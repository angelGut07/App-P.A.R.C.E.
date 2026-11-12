<?php

namespace Tests\Unit\Controllers;

use App\Controllers\HealthController;
use App\Core\Database;
use App\Core\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\SimulatesHttp;
use PDO;
use PDOStatement;
use PDOException;

/**
 * Tests unitarios de HealthController — PDO mockeado, nunca MySQL real.
 *
 * Sin Service detrás — llama a Database::healthCheck() directamente, que a
 * su vez usa PDO::query() (no prepare/execute), a diferencia de todo el
 * resto del proyecto que pasa por Database::fetchOne()/fetchAll(). Por eso
 * este archivo no puede reutilizar MocksDatabase::expectQueries() (que solo
 * configura prepare()) y en su lugar configura query()/getAttribute()
 * directamente sobre el mismo mock de PDO que ese trait ya crea — no es un
 * mecanismo de mock paralelo, es el mismo objeto, solo con un método
 * adicional configurado que el trait no cubre.
 */
class HealthControllerTest extends TestCase
{
    use SimulatesHttp;

    private HealthController $controller;
    private PDO $mockPdo;

    protected function setUp(): void
    {
        // system() usa BASE_PATH para el chequeo de almacenamiento — la suite
        // Unit solo arranca vendor/autoload.php (ver phpunit.xml), sin este
        // bootstrap la constante no existe fuera del proceso real de la app
        // (public/index.php). Mismo patrón ya usado en tests/Integration/bootstrap.php.
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 3));
        }

        $this->mockPdo = $this->createMock(PDO::class);
        Database::setConnection($this->mockPdo);
        $this->backupSuperglobals();
        $this->controller = new HealthController();
    }

    protected function tearDown(): void
    {
        Database::setConnection(null);
        $this->restoreSuperglobals();
    }

    private function mockHealthyDatabase(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(['test' => 1]);
        $this->mockPdo->method('query')->willReturn($stmt);
        $this->mockPdo->method('getAttribute')->willReturn('mocked');
    }

    private function mockUnhealthyDatabase(): void
    {
        $this->mockPdo->method('query')->willThrowException(new PDOException('Connection refused'));
    }

    // =========================================================================
    // index()
    // =========================================================================

    public function testIndexAlwaysReturnsHealthyWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('query');

        $request = $this->makeRequest('GET');

        $response = $this->controller->index($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('healthy', $body['data']['status']);
    }

    // =========================================================================
    // database()
    // =========================================================================

    public function testDatabaseReturns200AndTheResponseTimeWhenHealthy(): void
    {
        $this->mockHealthyDatabase();

        $request = $this->makeRequest('GET');

        $response = $this->controller->database($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('healthy', $body['data']['status']);
        $this->assertArrayHasKey('responseTimeMs', $body['data']);
    }

    public function testDatabaseReturns503AndNeverExposesInternalDriverDetailsWhenUnhealthy(): void
    {
        $this->mockUnhealthyDatabase();

        $request = $this->makeRequest('GET');

        $response = $this->controller->database($request);
        $body = $this->responseBody($response);

        $this->assertSame(503, $response->getStatusCode());
        $bodyAsString = json_encode($body);
        $this->assertStringNotContainsString('Connection refused', $bodyAsString);
        $this->assertStringNotContainsString('mocked', $bodyAsString);
    }

    // =========================================================================
    // system()
    // =========================================================================

    public function testSystemReturns200WithHealthyOverallStatusWhenEverythingIsFine(): void
    {
        $this->mockHealthyDatabase();

        $request = $this->makeRequest('GET');

        $response = $this->controller->system($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('healthy', $body['data']['status']);
        $this->assertSame('healthy', $body['data']['checks']['database']['status']);
        $this->assertSame('healthy', $body['data']['checks']['storage']['status']);
        // Informa el destino activo de los logs (la regla se prueba en LoggerTest)
        $this->assertSame(Logger::channel(), $body['data']['checks']['logs']['channel']);
    }

    public function testSystemSaysWhetherScheduledMaintenanceIsConfiguredWithoutShowingTheToken(): void
    {
        $this->mockHealthyDatabase();
        $envBackup = $_ENV;
        $token = str_repeat('7f', 32);

        try {
            unset($_ENV['MAINTENANCE_TOKEN']);
            $body = $this->responseBody($this->controller->system($this->makeRequest('GET')));
            $this->assertSame('missing', $body['data']['checks']['integrations']['maintenance']);

            $_ENV['MAINTENANCE_TOKEN'] = $token;
            $body = $this->responseBody($this->controller->system($this->makeRequest('GET')));
            $this->assertSame('configured', $body['data']['checks']['integrations']['maintenance']);
            $this->assertStringNotContainsString($token, json_encode($body));
        } finally {
            $_ENV = $envBackup;
        }
    }

    public function testSystemReturns503WithUnhealthyOverallStatusWhenTheDatabaseIsUnhealthy(): void
    {
        $this->mockUnhealthyDatabase();

        $request = $this->makeRequest('GET');

        $response = $this->controller->system($request);
        $body = $this->responseBody($response);

        // healthCheck() atrapa la excepción internamente y devuelve
        // status=unhealthy. Sin base de datos la app no atiende a nadie, así
        // que system() responde 503: un monitor externo debe verlo como caída
        // (antes devolvía 200 con 'degraded' y pasaba por sano).
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('unhealthy', $body['data']['status']);
        $this->assertSame('unhealthy', $body['data']['checks']['database']['status']);
    }
}
