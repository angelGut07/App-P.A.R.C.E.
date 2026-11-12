<?php

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Maintenance\MaintenanceService;

/**
 * Tareas de mantenimiento contra MySQL real: cada una toca solo lo vencido y
 * correrlas otra vez no cambia nada.
 */
class MaintenanceServiceTest extends IntegrationTestCase
{
    private MaintenanceService $service;
    private string $logDirectory;
    private int $customerId;
    private int $vehicleId;

    protected function setUp(): void
    {
        parent::setUp();

        // Carpeta de logs temporal y vacía: la prueba nunca borra logs reales
        $this->logDirectory = sys_get_temp_dir() . '/parce-maintenance-' . bin2hex(random_bytes(4));
        mkdir($this->logDirectory);
        $this->service = new MaintenanceService(logDirectory: $this->logDirectory);

        $this->customerId = $this->createUser();
        $this->vehicleId = Database::insert('vehicles', [
            'user_id'       => $this->customerId,
            'license_plate' => 'MNT123',
            'make'          => 'Renault',
            'model'         => 'Logan',
            'year'          => 2019,
            'vehicle_type'  => 'sedan',
            'fuel_type'     => 'gasoline',
            'is_primary'    => true,
            'status'        => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        @rmdir($this->logDirectory);
    }

    private function createRequest(string $status, int $minutesAgo): int
    {
        return Database::insert('service_requests', [
            'service_code'   => 'SR-' . random_int(100000, 999999),
            'customer_id'    => $this->customerId,
            'vehicle_id'     => $this->vehicleId,
            'emergency_type' => 'battery',
            'description'    => 'El carro no enciende',
            'priority'       => 'normal',
            'latitude'       => 4.7186,
            'longitude'      => -74.0712,
            'status'         => $status,
            'requested_at'   => date('Y-m-d H:i:s', time() - $minutesAgo * 60),
        ]);
    }

    private function requestRow(int $id): array
    {
        return Database::fetchOne('SELECT status, expired_at FROM service_requests WHERE id = ?', [$id]);
    }

    public function testExpiresOnlyPendingRequestsThatWaitedLongerThanTheTimeout(): void
    {
        $stale = $this->createRequest('pending', MaintenanceService::REQUEST_TIMEOUT_MINUTES + 1);
        $recent = $this->createRequest('pending', MaintenanceService::REQUEST_TIMEOUT_MINUTES - 1);
        $assigned = $this->createRequest('assigned', 120);
        $cancelled = $this->createRequest('cancelled', 120);

        $this->assertSame(1, $this->service->expireUnattendedRequests());

        $this->assertSame('expired', $this->requestRow($stale)['status']);
        $this->assertNotNull($this->requestRow($stale)['expired_at']);
        $this->assertSame('pending', $this->requestRow($recent)['status']);
        $this->assertNull($this->requestRow($recent)['expired_at']);
        $this->assertSame('assigned', $this->requestRow($assigned)['status']);
        $this->assertSame('cancelled', $this->requestRow($cancelled)['status']);

        // Otra vez: ya no hay nada vencido
        $this->assertSame(0, $this->service->expireUnattendedRequests());
    }

    public function testDeletesResetLinksThatExpiredMoreThanAWeekAgo(): void
    {
        $insert = fn(int $expiresInSeconds, bool $used) => Database::insert('password_reset_tokens', [
            'user_id'    => $this->customerId,
            'token_hash' => hash('sha256', random_bytes(16)),
            'ip_address' => '198.51.100.7',
            'expires_at' => date('Y-m-d H:i:s', time() + $expiresInSeconds),
            'used_at'    => $used ? date('Y-m-d H:i:s', time() + $expiresInSeconds - 1800) : null,
        ]);
        $day = 86400;
        $insert(-8 * $day, true);   // usado, vencido hace 8 días: se borra
        $insert(-8 * $day, false);  // sin usar, vencido hace 8 días: se borra
        $insert(-6 * $day, false);  // vencido hace 6 días: se guarda
        $insert(3600, false);       // vigente: se guarda

        $this->assertSame(2, $this->service->deleteOldPasswordResetTokens());
        $this->assertSame(2, (int) Database::fetchOne('SELECT COUNT(*) AS total FROM password_reset_tokens')['total']);
    }

    public function testRunAllCleansExpiredSessionsAndOldFailedLogins(): void
    {
        $session = fn(int $idleSeconds) => Database::insert('sessions', [
            'id'            => bin2hex(random_bytes(16)),
            'user_id'       => $this->customerId,
            'payload'       => '{}',
            'last_activity' => time() - $idleSeconds,
        ]);
        $session(3600); // una hora sin uso: la app ya la rechaza
        $session(60);

        $throttle = fn(int $lastFailureAgo) => Database::insert('login_throttles', [
            'identifier_hash' => hash('sha256', random_bytes(16)),
            'failed_attempts' => 2,
            'last_failed_at'  => time() - $lastFailureAgo,
        ]);
        $throttle(2 * 86400); // sin fallos en dos días
        $throttle(600);

        $stale = $this->createRequest('pending', 45);

        $this->assertSame([
            'expired_requests'      => 1,
            'sessions'              => 1,
            'login_throttles'       => 1,
            'password_reset_tokens' => 0,
            'log_files'             => 0,
        ], $this->service->runAll());

        $this->assertSame('expired', $this->requestRow($stale)['status']);
        $this->assertSame(1, (int) Database::fetchOne('SELECT COUNT(*) AS total FROM sessions')['total']);
        $this->assertSame(1, (int) Database::fetchOne('SELECT COUNT(*) AS total FROM login_throttles')['total']);
    }
}
