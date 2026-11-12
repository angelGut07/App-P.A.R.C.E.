<?php

namespace Tests\Integration;

use App\Core\Database;
use App\Core\DomainException;
use App\Models\Auth\AccountDeletionService;
use App\Models\Auth\ConsentService;
use App\Models\MechanicApplication\MechanicApplicationService;

/**
 * Eliminación de cuenta contra MySQL real: anonimiza lo que identifica a la
 * persona, borra lo que es solo suyo y conserva, sin datos personales, el
 * historial de servicios y la prueba de la autorización.
 */
class AccountDeletionFlowTest extends IntegrationTestCase
{
    private AccountDeletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccountDeletionService();
    }

    private function createVehicle(int $userId, string $plate): int
    {
        return Database::insert('vehicles', [
            'user_id'      => $userId,
            'license_plate' => $plate,
            'make'         => 'Mazda',
            'model'        => '3',
            'year'         => 2020,
            'vin'          => '1HGBH41JXMN109186',
            'vehicle_type' => 'sedan',
            'fuel_type'    => 'gasoline',
            'nickname'     => 'El rojo',
            'is_primary'   => true,
            'status'       => 'active',
        ]);
    }

    private function createServiceRequest(int $customerId, int $vehicleId, string $status, ?int $mechanicId = null): string
    {
        $code = 'SR-' . random_int(100000, 999999);
        Database::insert('service_requests', [
            'service_code'   => $code,
            'customer_id'    => $customerId,
            'vehicle_id'     => $vehicleId,
            'mechanic_id'    => $mechanicId,
            'emergency_type' => 'battery',
            'description'    => 'El carro no enciende',
            'priority'       => 'normal',
            'latitude'       => 4.71861234,
            'longitude'      => -74.07123456,
            'status'         => $status,
            'requested_at'   => date('Y-m-d H:i:s'),
        ]);

        return $code;
    }

    private function rows(string $sql, array $params): int
    {
        return (int)Database::fetchOne($sql, $params)['total'];
    }

    public function testDeletionAnonymizesTheAccountAndKeepsTheServiceHistory(): void
    {
        $userId = $this->createUser(['phone' => '3001234567', 'id_number' => '1012345678']);
        $this->assignRole($userId, 'customer');
        $this->setLicense($userId);
        $usedVehicle = $this->createVehicle($userId, 'USE123');
        $unusedVehicle = $this->createVehicle($userId, 'NEW456');
        Database::insert('vehicle_documents', ['vehicle_id' => $usedVehicle, 'document_type' => 'soat', 'document_number' => 'SOAT1', 'expires_at' => '2027-01-01']);
        $this->createServiceRequest($userId, $usedVehicle, 'completed');
        Database::insert('sessions', ['id' => bin2hex(random_bytes(16)), 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        (new ConsentService())->record($userId, '127.0.0.1', 'PHPUnit');
        (new MechanicApplicationService())->create($userId, ['services' => ['battery'], 'certification_title' => 'Técnico automotriz']);
        $originalEmail = Database::fetchOne('SELECT email FROM users WHERE id = ?', [$userId])['email'];

        $result = $this->service->delete($userId);

        $this->assertSame($originalEmail, $result['email'], 'Devuelve el correo original para la confirmación');

        $user = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
        $this->assertSame("eliminada-{$userId}@cuentas.parce.invalid", $user['email']);
        $this->assertSame(['Cuenta', 'eliminada'], [$user['first_name'], $user['last_name']]);
        foreach (['phone', 'id_number', 'driver_license_number', 'driver_license_expiration_date', 'driver_license_document_url'] as $column) {
            $this->assertNull($user[$column], "{$column} debe quedar vacío");
        }
        $this->assertSame('deactivated', $user['account_status']);
        $this->assertNotNull($user['deleted_at']);
        $this->assertFalse(password_verify('Test12345!', $user['password_hash']), 'La contraseña anterior ya no sirve');

        // Accesos y datos propios: fuera
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS total FROM sessions WHERE user_id = ?', [$userId]));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS total FROM mechanic_applications WHERE user_id = ?', [$userId]));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS total FROM user_roles WHERE user_id = ? AND is_active = TRUE', [$userId]));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS total FROM vehicle_documents WHERE vehicle_id = ?', [$usedVehicle]));

        // Vehículos: el que no tiene historial se borra; el otro queda anónimo
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS total FROM vehicles WHERE id = ?', [$unusedVehicle]));
        $vehicle = Database::fetchOne('SELECT license_plate, vin, nickname, deleted_at FROM vehicles WHERE id = ?', [$usedVehicle]);
        $this->assertSame('ELIMINADO', $vehicle['license_plate']);
        $this->assertNull($vehicle['vin']);
        $this->assertNull($vehicle['nickname']);
        $this->assertNotNull($vehicle['deleted_at']);

        // Historial conservado, con la ubicación aproximada
        $request = Database::fetchOne('SELECT latitude, longitude, status FROM service_requests WHERE customer_id = ?', [$userId]);
        $this->assertSame('completed', $request['status']);
        $this->assertEquals(4.72, (float)$request['latitude']);
        $this->assertEquals(-74.07, (float)$request['longitude']);

        // La prueba de la autorización se conserva
        $this->assertSame(1, $this->rows('SELECT COUNT(*) AS total FROM user_consents WHERE user_id = ?', [$userId]));
    }

    public function testDeletionIsBlockedWhileTheCustomerHasAServiceInCourse(): void
    {
        $userId = $this->createUser();
        $code = $this->createServiceRequest($userId, $this->createVehicle($userId, 'ACT123'), 'pending');

        try {
            $this->service->delete($userId);
            $this->fail('No se debe eliminar la cuenta con un servicio en curso');
        } catch (DomainException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString($code, $e->getMessage());
        }

        $this->assertNull(Database::fetchOne('SELECT deleted_at FROM users WHERE id = ?', [$userId])['deleted_at'], 'Nada debe cambiar');
    }

    public function testDeletionIsBlockedWhileTheMechanicHasAServiceInProgress(): void
    {
        $customerId = $this->createUser();
        $mechanicId = $this->createUser();
        $this->assignRole($mechanicId, 'mechanic');
        $this->createServiceRequest($customerId, $this->createVehicle($customerId, 'MEC123'), 'in_progress', $mechanicId);

        $this->assertDomainException(409, fn() => $this->service->delete($mechanicId));
    }

    public function testAdministratorsCannotDeleteTheirAccountFromTheApp(): void
    {
        $adminId = $this->createUser();
        $this->assignRole($adminId, 'administrator');

        $this->assertDomainException(403, fn() => $this->service->delete($adminId));
    }

    public function testAnAlreadyDeletedAccountCannotBeDeletedAgain(): void
    {
        $userId = $this->createUser();
        $this->service->delete($userId);

        $this->assertDomainException(404, fn() => $this->service->delete($userId));
    }
}
