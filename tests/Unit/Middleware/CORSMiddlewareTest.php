<?php

namespace Tests\Unit\Middleware;

use App\Core\Response;
use App\Middleware\CORSMiddleware;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de CORSMiddleware — sin base de datos, pura lógica de
 * cabeceras HTTP. Cubre en particular la regla de seguridad más importante
 * de este Middleware: cuando CORS_ALLOWED_ORIGINS incluye "*", se responde
 * con el literal "*" (nunca reflejando el Origin real), precisamente para
 * que el navegador rechace la combinación con Access-Control-Allow-Credentials
 * — de lo contrario cualquier sitio podría hacer peticiones autenticadas con
 * las cookies de sesión del usuario.
 */
class CORSMiddlewareTest extends TestCase
{
    use SimulatesHttp;

    private CORSMiddleware $middleware;
    private array $envBackup;

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        $this->backupSuperglobals();
        $this->middleware = new CORSMiddleware();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        $this->restoreSuperglobals();
    }

    public function testHandlePassesThroughWithoutCORSHeadersWhenThereIsNoOriginHeader(): void
    {
        $request = $this->makeRequest('GET', asJson: false);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $this->assertNull($this->responseHeader($response, 'Access-Control-Allow-Origin'));
    }

    public function testHandlePassesThroughWithoutCORSHeadersForADisallowedOrigin(): void
    {
        unset($_ENV['CORS_ALLOWED_ORIGINS']);
        $request = $this->makeRequest('GET', asJson: false, headers: ['Origin' => 'https://evil.test']);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $this->assertNull($this->responseHeader($response, 'Access-Control-Allow-Origin'));
    }

    public function testHandleReflectsTheExactOriginAndSetsCredentialsForAnAllowedOrigin(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'http://localhost:5173';
        $request = $this->makeRequest('GET', asJson: false, headers: ['Origin' => 'http://localhost:5173']);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $this->assertSame('http://localhost:5173', $this->responseHeader($response, 'Access-Control-Allow-Origin'));
        $this->assertSame('true', $this->responseHeader($response, 'Access-Control-Allow-Credentials'));
        $this->assertSame('Origin', $this->responseHeader($response, 'Vary'));
    }

    public function testHandleNeverCombinesAWildcardOriginWithCredentials(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*';
        $request = $this->makeRequest('GET', asJson: false, headers: ['Origin' => 'https://cualquier-sitio.test']);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $this->assertSame('*', $this->responseHeader($response, 'Access-Control-Allow-Origin'));
        $this->assertNull(
            $this->responseHeader($response, 'Access-Control-Allow-Credentials'),
            'Nunca debe combinarse "*" con Allow-Credentials: true — el navegador rechazaría la respuesta, pero no debemos ni intentarlo'
        );
    }

    public function testHandleAnswersAPreflightRequestWithoutCallingNext(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'http://localhost:5173';
        $request = $this->makeRequest('OPTIONS', asJson: false, headers: ['Origin' => 'http://localhost:5173']);

        $nextCalled = false;
        $response = $this->middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;
            return Response::success();
        });

        $this->assertFalse($nextCalled, 'Un preflight OPTIONS no debe llegar al Controller real');
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('GET, POST, PUT, DELETE, OPTIONS, PATCH', $this->responseHeader($response, 'Access-Control-Allow-Methods'));
    }
}
