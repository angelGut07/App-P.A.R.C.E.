<?php

namespace Tests\Unit\Models\ServiceRequest;

use App\Core\DomainException;
use App\Models\ServiceRequest\ServiceRequestService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;

/**
 * Tests unitarios de ServiceRequestService — PDO mockeado, nunca MySQL real.
 *
 * Nota de alcance: la expiración automática de solicitudes pendientes (30 min)
 * NO vive en este Service — es un script standalone
 * (scripts/maintenance/expire_pending_requests.php) sin ningún método
 * equivalente en ServiceRequestService. No hay test de "expiración por cron"
 * aquí porque no hay código de ServiceRequestService que probar — ver el
 * informe final de esta fase.
 */
class ServiceRequestServiceTest extends TestCase
{
    use MocksDatabase;

    private ServiceRequestService $service;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->service = new ServiceRequestService();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
    }

    // =========================================================================
    // create() — una solicitud activa por cliente/vehículo
    // =========================================================================

    public function testCreateFailsWhenCustomerAlreadyHasAnActiveRequest(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 1, 'status' => 'active']), // vehículo
            $this->stepFetchOne(['id' => 1]), // lock FOR UPDATE sobre el cliente
            $this->stepFetchOne(['id' => 99, 'service_code' => 'SR-2026-000099']), // solicitud activa existente
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->create(1, [
                'vehicle_id' => 10, 'emergency_type' => 'mechanical',
                'description' => 'x', 'latitude' => 4.71, 'longitude' => -74.07,
            ]);
        } catch (DomainException $e) {
            $this->assertSame(409, $e->getStatusCode());
            throw $e;
        }
    }

    public function testCreateFailsWhenVehicleIsNotOwnedByCustomer(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 999, 'status' => 'active']),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->create(1, ['vehicle_id' => 10, 'emergency_type' => 'mechanical', 'description' => 'x', 'latitude' => 0, 'longitude' => 0]);
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function testCreateFailsWhenVehicleIsNotActive(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 1, 'status' => 'inactive']),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->create(1, ['vehicle_id' => 10, 'emergency_type' => 'mechanical', 'description' => 'x', 'latitude' => 0, 'longitude' => 0]);
        } catch (DomainException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    public function testCreateSucceedsAndGeneratesAServiceCode(): void
    {
        $this->allowTransactions();
        $this->expectLastInsertId('123');
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 1, 'status' => 'active']), // vehículo
            $this->stepFetchOne(['id' => 1]), // lock
            $this->stepFetchOne(false), // sin solicitud activa del cliente
            $this->stepFetchOne(false), // sin solicitud activa del vehículo
            $this->stepWrite(1), // INSERT service_requests
            $this->stepWrite(1), // UPDATE service_code
        ]);

        $requestId = $this->service->create(1, [
            'vehicle_id' => 10, 'emergency_type' => 'mechanical',
            'description' => 'Se dañó el motor', 'latitude' => 4.71, 'longitude' => -74.07,
        ]);

        $this->assertSame(123, $requestId);
    }

    // =========================================================================
    // accept() — pending -> assigned, incl. doble asignación de mecánico
    // =========================================================================

    public function testAcceptSucceedsTransitioningPendingToAssigned(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'pending']),
            $this->stepFetchOne(['driver_license_expiration_date' => date('Y-m-d', strtotime('+1 year'))]),
            $this->stepWrite(1), // UPDATE ... WHERE status = 'pending' afecta 1 fila
        ]);

        $this->assertTrue($this->service->accept(5, 42));
    }

    public function testAcceptFailsWithConflictWhenAnotherMechanicAlreadyAcceptedConcurrently(): void
    {
        // La lectura inicial todavía ve 'pending' (leída antes de que el otro
        // mecánico confirmara su UPDATE), pero el UPDATE condicionado
        // "WHERE status = 'pending'" ya no afecta ninguna fila porque el otro
        // mecánico ganó la carrera — exactamente el escenario que el guard
        // atómico de accept() existe para detectar.
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'pending']),
            $this->stepFetchOne(['driver_license_expiration_date' => date('Y-m-d', strtotime('+1 year'))]),
            $this->stepWrite(0), // 0 filas afectadas -> ya la tomó otro mecánico
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->accept(5, 42);
        } catch (DomainException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('Esta solicitud ya fue aceptada por otro mecánico', $e->getMessage());
            throw $e;
        }
    }

    public function testAcceptRejectsATransitionFromANonPendingState(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'in_progress']),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->accept(5, 42);
        } catch (DomainException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    public function testAcceptRejectsAMechanicWithoutARegisteredLicense(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'pending']),
            $this->stepFetchOne(['driver_license_expiration_date' => null]),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->accept(5, 42);
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function testAcceptRejectsAMechanicWithAnExpiredLicense(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'pending']),
            $this->stepFetchOne(['driver_license_expiration_date' => date('Y-m-d', strtotime('-1 day'))]),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->accept(5, 42);
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    // =========================================================================
    // start() — assigned -> in_progress
    // =========================================================================

    public function testStartSucceedsForTheAssignedMechanic(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'assigned', 'mechanic_id' => 42]),
            $this->stepWrite(1),
        ]);

        $this->assertTrue($this->service->start(5, 42));
    }

    public function testStartFailsForAMechanicNotAssignedToTheRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'assigned', 'mechanic_id' => 999]),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->start(5, 42);
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function testStartRejectsAnInvalidTransitionFromPending(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'pending', 'mechanic_id' => 42]),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->start(5, 42);
        } catch (DomainException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    // =========================================================================
    // complete() — in_progress -> completed
    // =========================================================================

    public function testCompleteSucceedsForTheAssignedMechanicWithAValidCost(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'in_progress', 'mechanic_id' => 42]),
            $this->stepWrite(1),
        ]);

        $this->assertTrue($this->service->complete(5, 42, 150000.0));
    }

    public function testCompleteRejectsANegativeFinalCost(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'in_progress', 'mechanic_id' => 42]),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->complete(5, 42, -1.0);
        } catch (DomainException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    // =========================================================================
    // cancel() — permitida desde pending/assigned, rechazada en otros estados
    // =========================================================================

    public function testCancelSucceedsFromPending(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'status' => 'pending']),
            $this->stepWrite(1),
        ]);

        $this->assertTrue($this->service->cancel(5, 1, 'Ya no lo necesito'));
    }

    public function testCancelSucceedsFromAssigned(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'status' => 'assigned']),
            $this->stepWrite(1),
        ]);

        $this->assertTrue($this->service->cancel(5, 1, 'Cambié de planes'));
    }

    public function testCancelIsRejectedFromCompletedState(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'status' => 'completed']),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->cancel(5, 1, 'Motivo');
        } catch (DomainException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    public function testCancelIsRejectedForANonOwner(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 999, 'status' => 'pending']),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->cancel(5, 1, 'Motivo');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function testCancelFailsWithNotFoundForANonexistentRequest(): void
    {
        $this->expectQueries([$this->stepFetchOne(false)]);

        $this->expectException(DomainException::class);

        try {
            $this->service->cancel(999999, 1, 'Motivo');
        } catch (DomainException $e) {
            $this->assertSame(404, $e->getStatusCode());
            throw $e;
        }
    }

    // =========================================================================
    // rate() — una sola calificación por solicitud
    // =========================================================================

    public function testRateSucceedsOnceForACompletedRequest(): void
    {
        $this->expectLastInsertId('1');
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'mechanic_id' => 2, 'status' => 'completed']),
            $this->stepWrite(1), // INSERT ratings
        ]);

        $this->assertTrue($this->service->rate(5, 1, 5, 'Excelente servicio', 5, 4));
    }

    public function testRateIsRejectedWhenTheRequestWasAlreadyRated(): void
    {
        // El UNIQUE (service_request_id, rating_type) de `ratings` rechaza la segunda calificación
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'mechanic_id' => 2, 'status' => 'completed']),
            $this->stepThrows("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '5-customer_to_mechanic'"),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->rate(5, 1, 5);
        } catch (DomainException $e) {
            $this->assertSame(409, $e->getStatusCode());
            throw $e;
        }
    }
}
