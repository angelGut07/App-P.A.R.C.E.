<?php

namespace Tests\Unit\Middleware;

use App\Core\Response;
use App\Middleware\RBACMiddleware;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de RBACMiddleware — PDO mockeado, nunca MySQL real.
 *
 * RoleValidator::getUserRoles() cachea por instancia; RBACMiddleware crea su
 * propia instancia interna (no inyectada) y la llama dos veces por handle()
 * (getUserRoles() directo + de nuevo dentro de hasAnyRole()) — la segunda
 * pega la caché, así que solo hay UNA consulta real (Database::fetchAll) por
 * invocación, verificado leyendo RoleValidator.php antes de escribir esto.
 */
class RBACMiddlewareTest extends TestCase
{
    use MocksDatabase;
    use SimulatesHttp;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->backupSuperglobals();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
        $this->restoreSuperglobals();
    }

    private function nextThatReturns(Response $response): callable
    {
        return fn() => $response;
    }

    public function testHandleReturns401WhenNoAuthenticatedUserIsAttached(): void
    {
        // AuthMiddleware no corrió antes (o no adjuntó 'user') — no debe ni
        // intentar consultar roles.
        $this->mockPdo->expects($this->never())->method('prepare');

        $middleware = new RBACMiddleware(['administrator', 'super_admin']);
        $request = $this->makeRequest('GET');

        $nextCalled = false;
        $response = $middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;
            return Response::success();
        });

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($nextCalled, '$next no debe ejecutarse si no hay usuario autenticado');
        $this->assertSame('Debes iniciar sesión', $this->responseBody($response)['error']);
    }

    public function testHandleReturns403WhenUserLacksAnyAllowedRole(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([]), // el usuario no tiene ningún rol activo
        ]);

        $middleware = new RBACMiddleware(['administrator', 'super_admin']);
        $request = $this->makeRequest('GET', attributes: ['user' => ['id' => 7]]);

        $nextCalled = false;
        $response = $middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;
            return Response::success();
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($nextCalled);
        $this->assertSame('Permisos insuficientes para acceder a este recurso', $this->responseBody($response)['error']);
    }

    public function testHandleCallsNextAndReturnsItsResponseWhenUserHasAnAllowedRole(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'mechanic']]),
        ]);

        $middleware = new RBACMiddleware(['mechanic']);
        $request = $this->makeRequest('GET', attributes: ['user' => ['id' => 42]]);

        $nextCalled = false;
        $sentinel = Response::success(['ok' => true], 'paso', 200);
        $response = $middleware->handle($request, function ($req) use (&$nextCalled, $sentinel, $request) {
            $nextCalled = true;
            $this->assertSame($request, $req, '$next debe recibir el mismo Request');
            return $sentinel;
        });

        $this->assertTrue($nextCalled);
        $this->assertSame($sentinel, $response);
    }

    public function testGetAllowedRolesReturnsTheRolesPassedToTheConstructor(): void
    {
        $middleware = new RBACMiddleware(['customer', 'mechanic']);

        $this->assertSame(['customer', 'mechanic'], $middleware->getAllowedRoles());
    }
}
