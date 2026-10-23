<?php

namespace Tests\Unit\Middleware;

use App\Core\Response;
use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de SecurityHeadersMiddleware — sin base de datos, pura
 * lógica de cabeceras HTTP.
 */
class SecurityHeadersMiddlewareTest extends TestCase
{
    use SimulatesHttp;

    private SecurityHeadersMiddleware $middleware;

    protected function setUp(): void
    {
        $this->backupSuperglobals();
        $this->middleware = new SecurityHeadersMiddleware();
    }

    protected function tearDown(): void
    {
        $this->restoreSuperglobals();
    }

    public function testHandleSetsTheStandardSecurityHeadersAndReturnsNextsResponse(): void
    {
        $request = $this->makeRequest('GET', asJson: false);
        $sentinel = Response::success(['ok' => true]);

        $response = $this->middleware->handle($request, fn() => $sentinel);

        $this->assertSame($sentinel, $response);
        $this->assertSame('nosniff', $this->responseHeader($response, 'X-Content-Type-Options'));
        $this->assertSame('DENY', $this->responseHeader($response, 'X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $this->responseHeader($response, 'Referrer-Policy'));
        $this->assertSame(
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            $this->responseHeader($response, 'Content-Security-Policy')
        );
    }

    public function testHandleDoesNotSetHstsOverPlainHttp(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $request = $this->makeRequest('GET', asJson: false);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $this->assertNull($this->responseHeader($response, 'Strict-Transport-Security'));
    }

    public function testHandleSetsHstsOverHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $request = $this->makeRequest('GET', asJson: false);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $this->assertSame('max-age=31536000; includeSubDomains', $this->responseHeader($response, 'Strict-Transport-Security'));
    }
}
