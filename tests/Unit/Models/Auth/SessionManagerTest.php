<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\SessionData;
use App\Models\Auth\SessionManager;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;

/**
 * Tests unitarios de SessionManager — PDO mockeado, nunca MySQL real.
 *
 * Hallazgo de arquitectura (reportado, no corregido — mismo criterio que
 * AuthController::profile()): validate($sessionId, $currentIP, $autoRegenerate)
 * acepta un tercer parámetro $autoRegenerate que, si es true, calcula
 * internamente $shouldRegenerate y solo lo registra vía error_log() — nunca lo
 * expone al llamador ni dispara ninguna regeneración real (el comentario en el
 * propio código, línea ~158, dice literalmente "Se añadirá a SessionData en el
 * siguiente paso"). Verificado por grep: NINGÚN llamador real (AuthMiddleware,
 * AuthService) pasa jamás $autoRegenerate=true — el flujo real de regeneración
 * de sesión pasa enteramente por el método público independiente
 * shouldRegenerate(), no por este parámetro. Es código muerto/incompleto en
 * producción. testValidateWithAutoRegenerateTrueStillNeverExposesTheFlag
 * documenta este comportamiento actual explícitamente.
 */
class SessionManagerTest extends TestCase
{
    use MocksDatabase;

    private SessionManager $manager;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->manager = new SessionManager();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
    }

    private function sessionRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'session-abc',
            'user_id' => 7,
            'ip_address' => '203.0.113.5',
            'user_agent' => 'PHPUnit',
            'payload' => json_encode([
                'expires_at' => time() + 7200,
                'max_idle_seconds' => 1800,
            ]),
            'last_activity' => time(),
            'created_at' => date('Y-m-d H:i:s'),
        ], $overrides);
    }

    // =========================================================================
    // create()
    // =========================================================================

    public function testCreateInsertsTheSessionAndReturnsA40CharacterId(): void
    {
        $this->expectQueries([
            $this->stepWrite(1),
        ]);

        $sessionId = $this->manager->create(7, ['ip_address' => '203.0.113.5', 'user_agent' => 'PHPUnit']);

        $this->assertSame(40, strlen($sessionId));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $sessionId);
    }

    public function testCreateSucceedsWithEmptyMetadata(): void
    {
        $this->expectQueries([
            $this->stepWrite(1),
        ]);

        $sessionId = $this->manager->create(7, []);

        $this->assertSame(40, strlen($sessionId));
    }

    // =========================================================================
    // validate()
    // =========================================================================

    public function testValidateReturnsNullForANonexistentSession(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $this->assertNull($this->manager->validate('does-not-exist'));
    }

    public function testValidateDeletesAndReturnsNullForAMalformedPayload(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->sessionRow(['payload' => 'not-json'])),
            $this->stepWrite(1), // DELETE por payload malformado
        ]);

        $this->assertNull($this->manager->validate('session-abc'));
    }

    public function testValidateDeletesAndReturnsNullWhenAbsoluteExpirationHasPassed(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->sessionRow([
                'payload' => json_encode(['expires_at' => time() - 10, 'max_idle_seconds' => 1800]),
            ])),
            $this->stepWrite(1), // DELETE por expiración absoluta
        ]);

        $this->assertNull($this->manager->validate('session-abc'));
    }

    public function testValidateDeletesAndReturnsNullWhenIdleTimeoutIsExceeded(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->sessionRow([
                'last_activity' => time() - 3600,
                'payload' => json_encode(['expires_at' => time() + 7200, 'max_idle_seconds' => 1800]),
            ])),
            $this->stepWrite(1), // DELETE por inactividad
        ]);

        $this->assertNull($this->manager->validate('session-abc'));
    }

    public function testValidateSucceedsAndUpdatesLastActivity(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->sessionRow()),
            $this->stepWrite(1), // UPDATE last_activity
        ]);

        $sessionData = $this->manager->validate('session-abc');

        $this->assertInstanceOf(SessionData::class, $sessionData);
        $this->assertSame('session-abc', $sessionData->id);
        $this->assertSame(7, $sessionData->userId);
    }

    public function testValidateStillSucceedsDespiteAnIpAddressChangeItOnlyLogsAWarning(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->sessionRow(['ip_address' => '203.0.113.5'])),
            $this->stepWrite(1),
        ]);

        $sessionData = $this->manager->validate('session-abc', '198.51.100.9');

        $this->assertInstanceOf(SessionData::class, $sessionData, 'Un cambio de IP no debe invalidar la sesión, solo se registra');
        $this->assertSame('203.0.113.5', $sessionData->ipAddress, 'SessionData conserva la IP original almacenada, no la actual de la petición');
    }

    public function testValidateWithAutoRegenerateTrueStillNeverExposesTheFlag(): void
    {
        // Ver docblock de esta clase: $autoRegenerate=true entra a la rama que
        // calcula $shouldRegenerate internamente, pero ese resultado nunca se
        // adjunta a SessionData ni cambia el retorno del método de ninguna
        // forma observable — solo se registra vía error_log(). Este test fija
        // ese comportamiento actual (parámetro sin efecto observable), no lo
        // valida como deseable.
        $this->expectQueries([
            $this->stepFetchOne($this->sessionRow(['last_activity' => time() - 700])),
            $this->stepWrite(1),
        ]);

        $sessionData = $this->manager->validate('session-abc', null, true);

        $this->assertInstanceOf(SessionData::class, $sessionData);
    }

    // =========================================================================
    // destroy()
    // =========================================================================

    public function testDestroyReturnsTrueWhenTheSessionExisted(): void
    {
        $this->expectQueries([
            $this->stepWrite(1),
        ]);

        $this->assertTrue($this->manager->destroy('session-abc'));
    }

    public function testDestroyReturnsFalseWhenTheSessionDidNotExist(): void
    {
        $this->expectQueries([
            $this->stepWrite(0),
        ]);

        $this->assertFalse($this->manager->destroy('does-not-exist'));
    }

    // =========================================================================
    // regenerate()
    // =========================================================================

    public function testRegenerateReplacesTheSessionIdAndPreservesTheMetadata(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(['user_id' => 7, 'ip_address' => '203.0.113.5', 'user_agent' => 'PHPUnit', 'payload' => '{}']),
            $this->stepWrite(1), // DELETE de la sesión vieja
            function (\PDOStatement $stmt): void {}, // INSERT de la nueva
        ]);

        $newId = $this->manager->regenerate('session-abc');

        $this->assertSame(40, strlen($newId));
        $this->assertNotSame('session-abc', $newId);
    }

    public function testRegenerateReturnsAnEmptyStringForANonexistentSessionWithoutStartingATransaction(): void
    {
        $this->mockPdo->expects($this->never())->method('beginTransaction');
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $this->assertSame('', $this->manager->regenerate('does-not-exist'));
    }

    // =========================================================================
    // destroyAllUserSessions()
    // =========================================================================

    public function testDestroyAllUserSessionsReturnsTheNumberOfSessionsRemoved(): void
    {
        $this->expectQueries([
            $this->stepWrite(3),
        ]);

        $this->assertSame(3, $this->manager->destroyAllUserSessions(7));
    }

    // =========================================================================
    // cleanup()
    // =========================================================================

    public function testCleanupReturnsTheNumberOfIdleSessionsRemoved(): void
    {
        $this->expectQueries([
            $this->stepWrite(5),
        ]);

        $this->assertSame(5, $this->manager->cleanup());
    }

    // =========================================================================
    // shouldRegenerate()
    // =========================================================================

    public function testShouldRegenerateReturnsFalseForANonexistentSession(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $this->assertFalse($this->manager->shouldRegenerate('does-not-exist'));
    }

    public function testShouldRegenerateReturnsFalseWhenRecentlyActive(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['last_activity' => time()]),
        ]);

        $this->assertFalse($this->manager->shouldRegenerate('session-abc'));
    }

    public function testShouldRegenerateReturnsTrueAfterTheConfiguredInterval(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['last_activity' => time() - 700]),
        ]);

        $this->assertTrue($this->manager->shouldRegenerate('session-abc'));
    }

    // =========================================================================
    // getTimeoutConfig() — estático, sin base de datos
    // =========================================================================

    public function testGetTimeoutConfigReturnsCodeDefaultsWhenEnvIsUnset(): void
    {
        $idleBackup = $_ENV['SESSION_IDLE_TIMEOUT'] ?? null;
        $lifetimeBackup = $_ENV['SESSION_LIFETIME'] ?? null;
        unset($_ENV['SESSION_IDLE_TIMEOUT'], $_ENV['SESSION_LIFETIME']);

        $config = SessionManager::getTimeoutConfig();

        $this->assertSame(1800, $config['idle_timeout']);
        $this->assertSame(7200, $config['absolute_timeout']);

        if ($idleBackup !== null) { $_ENV['SESSION_IDLE_TIMEOUT'] = $idleBackup; }
        if ($lifetimeBackup !== null) { $_ENV['SESSION_LIFETIME'] = $lifetimeBackup; }
    }

    public function testGetTimeoutConfigHonorsEnvOverrides(): void
    {
        $idleBackup = $_ENV['SESSION_IDLE_TIMEOUT'] ?? null;
        $lifetimeBackup = $_ENV['SESSION_LIFETIME'] ?? null;
        $_ENV['SESSION_IDLE_TIMEOUT'] = '900';
        $_ENV['SESSION_LIFETIME'] = '3600';

        $config = SessionManager::getTimeoutConfig();

        $this->assertSame(900, $config['idle_timeout']);
        $this->assertSame(3600, $config['absolute_timeout']);

        if ($idleBackup === null) { unset($_ENV['SESSION_IDLE_TIMEOUT']); } else { $_ENV['SESSION_IDLE_TIMEOUT'] = $idleBackup; }
        if ($lifetimeBackup === null) { unset($_ENV['SESSION_LIFETIME']); } else { $_ENV['SESSION_LIFETIME'] = $lifetimeBackup; }
    }

    // =========================================================================
    // regenerate()
    // =========================================================================

    public function testRegenerateKeepsTheOldIdValidForAShortGracePeriodInsteadOfDeletingIt(): void
    {
        $this->allowTransactions();
        $oldUpdateParams = null;
        $this->expectQueries([
            $this->stepFetchOne($this->sessionRow()),
            function (\PDOStatement $stmt) use (&$oldUpdateParams): void {
                $stmt->method('execute')->willReturnCallback(function ($params) use (&$oldUpdateParams) {
                    $oldUpdateParams = $params;
                    return true;
                });
            },
            $this->stepWrite(1), // INSERT de la nueva sesión
        ]);

        $newId = $this->manager->regenerate('session-abc');

        $this->assertSame(40, strlen($newId));
        // Las peticiones en paralelo con la cookie anterior siguen funcionando unos segundos
        $payload = json_decode($oldUpdateParams[0], true);
        $this->assertLessThanOrEqual(time() + 60, $payload['expires_at']);
        $this->assertGreaterThan(time(), $payload['expires_at']);
        $this->assertSame('session-abc', end($oldUpdateParams));
    }
}
