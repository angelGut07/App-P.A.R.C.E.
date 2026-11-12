<?php

namespace Tests\Unit\Controllers;

use App\Controllers\MaintenanceController;
use App\Core\Database;
use App\Core\Http\RateLimiter;
use App\Models\Maintenance\MaintenanceService;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * POST /api/maintenance/run (PDO mockeado, nunca MySQL real): las tareas solo
 * corren con el token correcto, y sin base de datos responde 503 para que el
 * cron lo tome como una caída pasajera.
 */
class MaintenanceControllerTest extends TestCase
{
    use SimulatesHttp;

    private const TOKEN = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const IP = '203.0.113.77';

    private array $envBackup;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        $_ENV['MAINTENANCE_TOKEN'] = self::TOKEN;

        $this->pdo = $this->createMock(PDO::class);
        Database::setConnection($this->pdo);
        $this->backupSuperglobals();

        // El límite de intentos se guarda en storage/rate_limit.json: cada
        // prueba empieza sin intentos fallidos registrados para su IP
        RateLimiter::reset('maintenance', self::IP);
    }

    protected function tearDown(): void
    {
        RateLimiter::reset('maintenance', self::IP);
        Database::setConnection(null);
        $this->restoreSuperglobals();
        $_ENV = $this->envBackup;
    }

    private function healthyDatabase(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn(['test' => 1]);
        $this->pdo->method('query')->willReturn($statement);
        $this->pdo->method('getAttribute')->willReturn('mocked');
    }

    private function serviceThatMustNotRun(): MaintenanceService
    {
        $service = $this->createMock(MaintenanceService::class);
        $service->expects($this->never())->method('runAll');

        return $service;
    }

    /**
     * Llama al endpoint como lo hace el cron
     *
     * @return array{0: int, 1: array} Código HTTP y cuerpo de la respuesta
     */
    private function call(?string $token, ?MaintenanceService $service = null): array
    {
        unset($_SERVER['HTTP_X_MAINTENANCE_TOKEN']);
        $_SERVER['REMOTE_ADDR'] = self::IP;
        $request = $this->makeRequest('POST', headers: $token === null ? [] : ['X-Maintenance-Token' => $token]);

        $response = (new MaintenanceController($service ?? $this->serviceThatMustNotRun()))->run($request);

        return [$response->getStatusCode(), $this->responseBody($response)];
    }

    public function testIsOffWhileTheServerHasNoToken(): void
    {
        unset($_ENV['MAINTENANCE_TOKEN']);

        [$status, $body] = $this->call(self::TOKEN);

        $this->assertSame(404, $status);
        $this->assertStringContainsString('MAINTENANCE_TOKEN', $body['error']);
    }

    public function testATokenShorterThan32CharactersCountsAsNotConfigured(): void
    {
        $_ENV['MAINTENANCE_TOKEN'] = str_repeat('a', 31);

        [$status] = $this->call(str_repeat('a', 31));

        $this->assertSame(404, $status);
        $this->assertNull(MaintenanceController::configuredToken());
    }

    public static function wrongTokens(): array
    {
        return [
            'sin header'          => [null],
            'vacío'               => [''],
            'otro token'          => [str_repeat('b', 64)],
            'el token sin el final' => [substr(self::TOKEN, 0, -1)],
            'el token con algo más' => [self::TOKEN . 'x'],
        ];
    }

    #[DataProvider('wrongTokens')]
    public function testRejectsCallsWithoutTheRightToken(?string $token): void
    {
        [$status, $body] = $this->call($token);

        $this->assertSame(401, $status);
        $this->assertSame('Token de mantenimiento inválido', $body['error']);
    }

    public function testBlocksAnIpAfterFiveWrongTokensButTheRightOneStillWorks(): void
    {
        for ($attempt = 1; $attempt <= RateLimiter::MAX_ATTEMPTS; $attempt++) {
            $this->assertSame(401, $this->call('token-equivocado-' . $attempt)[0], "Intento {$attempt}");
        }
        $this->assertSame(429, $this->call('token-equivocado-6')[0]);

        // Quien prueba tokens no puede dejar sin mantenimiento al cron legítimo
        $this->healthyDatabase();
        $service = $this->createMock(MaintenanceService::class);
        $service->expects($this->once())->method('runAll')->willReturn([]);

        $this->assertSame(200, $this->call(self::TOKEN, $service)[0]);
    }

    public function testRunsTheTasksAndReportsWhatEachOneDid(): void
    {
        $this->healthyDatabase();
        $service = $this->createMock(MaintenanceService::class);
        $service->expects($this->once())->method('runAll')->willReturn([
            'expired_requests'      => 2,
            'sessions'              => 5,
            'login_throttles'       => 1,
            'password_reset_tokens' => 0,
            'log_files'             => 0,
        ]);

        [$status, $body] = $this->call(self::TOKEN, $service);

        $this->assertSame(200, $status);
        $this->assertSame('Mantenimiento completado', $body['message']);
        $this->assertSame(2, $body['data']['expiredRequests']);
        $this->assertSame(5, $body['data']['sessions']);
        $this->assertSame(1, $body['data']['loginThrottles']);
        $this->assertSame(0, $body['data']['passwordResetTokens']);
        $this->assertSame(0, $body['data']['logFiles']);
        $this->assertIsInt($body['data']['durationMs']);
    }

    public function testAnswers503WithoutRunningWhenTheDatabaseIsDown(): void
    {
        $this->pdo->method('query')->willThrowException(new PDOException('Connection refused'));

        [$status, $body] = $this->call(self::TOKEN);

        $this->assertSame(503, $status);
        $this->assertStringContainsString('base de datos', $body['error']);
        // Nunca expone el detalle del driver
        $this->assertStringNotContainsString('Connection refused', json_encode($body));
    }
}
