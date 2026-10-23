<?php

namespace Tests\Unit\Models\Vehicle;

use App\Core\DomainException;
use App\Models\Vehicle\VehicleService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;

/**
 * Tests unitarios de VehicleService — PDO mockeado, nunca MySQL real.
 *
 * license_plate/vin no tienen UNIQUE a nivel de BD (migración
 * 2026_07_16_000016) — la unicidad se protege solo con locks nombrados de
 * MySQL (GET_LOCK/RELEASE_LOCK), que en estos tests son, igual que el resto
 * de la BD, llamadas mockeadas de Database::fetchOne()/query().
 */
class VehicleServiceTest extends TestCase
{
    use MocksDatabase;

    private VehicleService $service;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->service = new VehicleService();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
    }

    private function validVehicleData(array $overrides = []): array
    {
        return array_merge([
            'license_plate' => 'ABC-123',
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2022,
            'vehicle_type' => 'sedan',
            'fuel_type' => 'gasoline',
        ], $overrides);
    }

    // =========================================================================
    // Unicidad de placa/VIN mediante lock nombrado de MySQL (GET_LOCK/RELEASE_LOCK)
    // =========================================================================

    public function testCreateAcquiresPlateLockChecksUniquenessAndReleasesTheLock(): void
    {
        $this->expectLastInsertId('77');
        $this->expectQueries([
            $this->stepFetchOne(['acquired' => 1]),  // GET_LOCK('vehicle_plate:ABC-123', ...)
            $this->stepFetchOne(false),               // placa libre
            function (\PDOStatement $stmt): void {},  // INSERT vehicles
            function (\PDOStatement $stmt): void {},  // RELEASE_LOCK('vehicle_plate:ABC-123')
        ]);

        $vehicleId = $this->service->create(1, $this->validVehicleData());

        $this->assertSame(77, $vehicleId);
    }

    public function testCreateFailsWithServiceUnavailableWhenTheNamedLockCannotBeAcquired(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['acquired' => 0]), // GET_LOCK no consiguió el lock a tiempo
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->create(1, $this->validVehicleData());
        } catch (DomainException $e) {
            $this->assertSame(503, $e->getStatusCode());
            throw $e;
        }
    }

    public function testCreateFailsWithConflictForADuplicatePlateAndStillReleasesTheLock(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['acquired' => 1]),   // GET_LOCK ok
            $this->stepFetchOne(['id' => 5]),          // ya existe un vehículo con esta placa
            function (\PDOStatement $stmt): void {},   // RELEASE_LOCK ejecutado en el finally
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->create(1, $this->validVehicleData());
        } catch (DomainException $e) {
            $this->assertSame(409, $e->getStatusCode());
            throw $e;
        }
    }

    public function testCreateFailsWithConflictForADuplicateVinAfterThePlateCheckPasses(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['acquired' => 1]),  // GET_LOCK placa
            $this->stepFetchOne(['acquired' => 1]),  // GET_LOCK vin
            $this->stepFetchOne(false),               // placa libre
            $this->stepFetchOne(['id' => 9]),          // VIN ya existe
            function (\PDOStatement $stmt): void {},   // RELEASE_LOCK placa
            function (\PDOStatement $stmt): void {},   // RELEASE_LOCK vin
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->create(1, $this->validVehicleData(['vin' => '1HGCM82633A004352']));
        } catch (DomainException $e) {
            $this->assertSame(409, $e->getStatusCode());
            throw $e;
        }
    }

    // =========================================================================
    // Reasignación transaccional de vehículo principal
    // =========================================================================

    public function testSetPrimaryAtomicallyUnsetsThePreviousPrimaryAndSetsTheNewOne(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 1]),
            $this->stepWrite(1), // UPDATE vehicles SET is_primary=false WHERE user_id=?
            $this->stepWrite(1), // UPDATE vehicles SET is_primary=true WHERE id=?
        ]);

        $this->assertTrue($this->service->setPrimary(10, 1));
    }

    public function testSetPrimaryFailsForANonOwner(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 999]),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->setPrimary(10, 1);
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function testSetPrimaryFailsForANonexistentVehicle(): void
    {
        $this->expectQueries([$this->stepFetchOne(false)]);

        $this->expectException(DomainException::class);

        try {
            $this->service->setPrimary(999999, 1);
        } catch (DomainException $e) {
            $this->assertSame(404, $e->getStatusCode());
            throw $e;
        }
    }

    // =========================================================================
    // SOAT/Tecnomecánica vencidos NO bloquean operaciones (comportamiento
    // actual por diseño — ver AS_DESIGNED_VS_AS_BUILT.md / roadmap A.5).
    // Este test confirma que efectivamente no bloquea, no que debería.
    // =========================================================================

    public function testUpdateAcceptsAnExpiredSoatDateWithoutAnyBlockingValidation(): void
    {
        $this->expectQueries([
            $this->stepFetchOne([
                'id' => 10, 'user_id' => 1, 'license_plate' => 'ABC-123',
                'vin' => null, 'is_primary' => 0,
            ]),
            $this->stepWrite(1), // UPDATE vehicles SET soat_expiration_date=...
        ]);

        $expiredDate = date('Y-m-d', strtotime('-30 days'));

        $result = $this->service->update(10, 1, ['soat_expiration_date' => $expiredDate]);

        $this->assertTrue($result, 'Un SOAT vencido no debe bloquear la actualización del vehículo');
    }
}
