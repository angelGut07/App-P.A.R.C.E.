<?php

namespace Tests\Unit\Middleware;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de AuthMiddleware — PDO mockeado, nunca MySQL real.
 *
 * El camino feliz completo encadena varias llamadas Database:: reales a
 * través de 3 clases que este middleware crea internamente sin inyección
 * (SessionManager, RoleValidator) además de su propia consulta de usuario —
 * cada test enumera esa secuencia exacta en el orden real del código:
 * SessionManager::shouldRegenerate() -> SessionManager::validate() (lectura +
 * UPDATE de last_activity) -> Database::fetchOne(users) del propio middleware
 * -> RoleValidator::getUserRoles().
 */
class AuthMiddlewareTest extends TestCase
{
    use MocksDatabase;
    use SimulatesHttp;

    private AuthMiddleware $middleware;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->backupSuperglobals();
        $this->middleware = new AuthMiddleware();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
        $this->restoreSuperglobals();
    }

    private function validSessionPayload(int $lastActivity, ?int $expiresAt = null): array
    {
        return [
            'id' => 'session-abc',
            'user_id' => 7,
            'ip_address' => '203.0.113.5',
            'user_agent' => 'PHPUnit',
            'payload' => json_encode([
                'expires_at' => $expiresAt ?? (time() + 7200),
                'max_idle_seconds' => 1800,
            ]),
            'last_activity' => $lastActivity,
            'created_at' => date('Y-m-d H:i:s', $lastActivity),
        ];
    }

    private function activeUserRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 7, 'email' => 'cliente@parce.local', 'first_name' => 'Test',
            'last_name' => 'User', 'account_status' => 'active',
            'last_login_at' => null, 'created_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    public function testHandleReturns401WithoutTouchingDatabaseWhenThereIsNoSessionCookie(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('GET', cookies: []);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Debes iniciar sesión', $this->responseBody($response)['error']);
    }

    public function testHandleReturns401ForAnInvalidOrExpiredSession(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false), // shouldRegenerate(): sin fila -> false
            $this->stepFetchOne(false), // validate(): sesión no existe -> null
        ]);

        $request = $this->makeRequest('GET', cookies: ['parce_session' => 'does-not-exist']);

        $nextCalled = false;
        $response = $this->middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;
            return Response::success();
        });

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Tu sesión expiró. Inicia sesión de nuevo', $this->responseBody($response)['error']);
        $this->assertFalse($nextCalled);
    }

    public function testHandleReturns401WhenTheSessionIsValidButTheUserNoLongerExists(): void
    {
        $now = time();
        $this->expectQueries([
            $this->stepFetchOne(['last_activity' => $now]), // shouldRegenerate: reciente -> false
            $this->stepFetchOne($this->validSessionPayload($now)), // validate(): sesión válida
            $this->stepWrite(1), // validate(): UPDATE last_activity
            $this->stepFetchOne(false), // el propio middleware: usuario no encontrado
        ]);

        $request = $this->makeRequest('GET', cookies: ['parce_session' => 'session-abc']);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Usuario no encontrado', $this->responseBody($response)['error']);
    }

    public function testHandleReturns403WhenTheAccountIsNotActive(): void
    {
        $now = time();
        $this->expectQueries([
            $this->stepFetchOne(['last_activity' => $now]),
            $this->stepFetchOne($this->validSessionPayload($now)),
            $this->stepWrite(1),
            $this->stepFetchOne($this->activeUserRow(['account_status' => 'suspended'])),
        ]);

        $request = $this->makeRequest('GET', cookies: ['parce_session' => 'session-abc']);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Tu cuenta no está activa', $this->responseBody($response)['error']);
    }

    public function testHandleAttachesUserAndRolesAndCallsNextOnSuccess(): void
    {
        $now = time();
        $this->expectQueries([
            $this->stepFetchOne(['last_activity' => $now]),
            $this->stepFetchOne($this->validSessionPayload($now)),
            $this->stepWrite(1),
            $this->stepFetchOne($this->activeUserRow()),
            $this->stepFetchAll([['slug' => 'mechanic'], ['slug' => 'customer']]),
        ]);

        $request = $this->makeRequest('GET', cookies: ['parce_session' => 'session-abc']);

        $capturedRequest = null;
        $sentinel = Response::success(['ok' => true]);
        $response = $this->middleware->handle($request, function ($req) use (&$capturedRequest, $sentinel) {
            $capturedRequest = $req;
            return $sentinel;
        });

        $this->assertSame($sentinel, $response);
        $this->assertSame(7, $capturedRequest->getAttribute('userId'));
        $this->assertSame(['mechanic', 'customer'], $capturedRequest->getAttribute('userRoles'));
        // super_admin/administrator/mechanic/customer/support en ese orden de prioridad
        $this->assertSame('mechanic', $capturedRequest->getAttribute('userRole'));
        $this->assertSame('cliente@parce.local', $capturedRequest->getAttribute('user')['email']);
    }

    public function testHandleRegeneratesTheSessionIdAfterTheConfiguredIntervalAndSetsTheNewCookie(): void
    {
        // last_activity de hace 700s (> 600s, el intervalo por defecto de
        // SESSION_REGENERATE_INTERVAL) — dispara la protección anti-fijación.
        // Sigue dentro de max_idle_seconds (1800s) para que la sesión no se
        // considere inactiva/expirada por eso.
        $longAgo = time() - 700;
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(['last_activity' => $longAgo]), // shouldRegenerate() -> true
            $this->stepFetchOne($this->validSessionPayload($longAgo)), // validate(): aún válida
            $this->stepWrite(1), // validate(): UPDATE last_activity
            $this->stepFetchOne(['user_id' => 7, 'ip_address' => '203.0.113.5', 'user_agent' => 'PHPUnit', 'payload' => '{}']), // regenerate(): lee la sesión vieja
            $this->stepWrite(1), // regenerate(): DELETE de la sesión vieja
            function (\PDOStatement $stmt): void {}, // regenerate(): INSERT de la nueva sesión
            $this->stepFetchOne($this->activeUserRow()),
            $this->stepFetchAll([['slug' => 'customer']]),
        ]);

        $request = $this->makeRequest('GET', cookies: ['parce_session' => 'session-abc']);

        $response = $this->middleware->handle($request, fn() => Response::success());

        $setCookieHeader = $this->responseHeader($response, 'Set-Cookie');
        $this->assertNotNull($setCookieHeader, 'Debe fijarse una nueva cookie de sesión tras la regeneración');
        $this->assertStringNotContainsString('session-abc', $setCookieHeader, 'La cookie nueva no debe reusar el ID de sesión viejo');
    }
}
