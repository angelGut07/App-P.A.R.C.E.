<?php

namespace Tests\Unit\Middleware;

use App\Core\Response;
use App\Middleware\RequestLoggerMiddleware;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de RequestLoggerMiddleware — sin base de datos, pero SÍ
 * escribe a un archivo real (storage/logs/requests.log, sin seam inyectable
 * — mismo tipo de limitación ya documentada para RateLimiter en
 * AuthControllerTest). No se puede mockear sin tocar producción; se verifica
 * leyendo la última línea real escrita tras handle().
 */
class RequestLoggerMiddlewareTest extends TestCase
{
    use SimulatesHttp;

    private RequestLoggerMiddleware $middleware;

    protected function setUp(): void
    {
        $this->backupSuperglobals();
        $this->middleware = new RequestLoggerMiddleware();
    }

    protected function tearDown(): void
    {
        $this->restoreSuperglobals();
    }

    private function lastLoggedLine(): array
    {
        $lines = file(RequestLoggerMiddleware::getLogFilePath());
        return json_decode(trim(end($lines)), true);
    }

    public function testHandleLogsMethodPathStatusAndDurationAndReturnsNextsResponse(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/vehicles';
        $request = $this->makeRequest('GET', asJson: false, query: [], attributes: ['requestId' => 'req_test_123']);
        $sentinel = Response::success(['ok' => true], 'Creado', 201);

        $response = $this->middleware->handle($request, fn() => $sentinel);
        $logged = $this->lastLoggedLine();

        $this->assertSame($sentinel, $response);
        $this->assertSame('req_test_123', $logged['requestId']);
        $this->assertSame('GET', $logged['method']);
        $this->assertSame('/api/vehicles', $logged['path']);
        $this->assertSame(201, $logged['status']);
        // 0.0 se serializa como 0 en JSON: se valida que sea numérico y no negativo
        $this->assertIsNumeric($logged['durationMs']);
        $this->assertGreaterThanOrEqual(0, $logged['durationMs']);
    }

    public function testHandleUsesTheFirstIpFromXForwardedForWhenPresent(): void
    {
        $request = $this->makeRequest('GET', asJson: false, headers: [
            'X-Forwarded-For' => '203.0.113.5, 10.0.0.1',
        ]);

        $this->middleware->handle($request, fn() => Response::success());
        $logged = $this->lastLoggedLine();

        $this->assertSame('203.0.113.5', $logged['ip']);
    }
}
