<?php

namespace Tests\Unit\Models\Auth;

use App\Core\DomainException;
use App\Models\Auth\AuthResult;
use App\Models\Auth\AuthService;
use App\Models\Auth\PasswordHasher;
use App\Models\Auth\SessionManager;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;

/**
 * Tests unitarios de AuthService — PDO mockeado (nunca MySQL real), y sus dos
 * colaboradores (PasswordHasher, SessionManager) mockeados vía inyección de
 * constructor, ya que AuthService los recibe como dependencias explícitas.
 *
 * Nota de alcance: RBAC no vive en AuthService (vive en RoleValidator +
 * RBACMiddleware, ninguno de los 4 servicios en alcance de este paso) —
 * ver el informe final de esta fase para el detalle de por qué no hay un
 * test de "RBAC" aquí.
 */
class AuthServiceTest extends TestCase
{
    use MocksDatabase;

    private PasswordHasher $passwordHasher;
    private SessionManager $sessionManager;
    private AuthService $service;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->passwordHasher = $this->createMock(PasswordHasher::class);
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->service = new AuthService($this->passwordHasher, $this->sessionManager);
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
    }

    private function activeUserRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'email' => 'cliente@parce.local',
            'password_hash' => '$argon2id$fake',
            'account_status' => 'active',
        ], $overrides);
    }

    // =========================================================================
    // authenticate()
    // =========================================================================

    public function testAuthenticateFailsWithInvalidEmailFormatWithoutTouchingDatabase(): void
    {
        // Ni siquiera debe llegar a consultar la BD — se valida el formato primero.
        $this->mockPdo->expects($this->never())->method('prepare');

        $result = $this->service->authenticate('not-an-email', 'somepassword123');

        $this->assertFalse($result->success);
        $this->assertSame('Correo o contraseña incorrectos', $result->message);
    }

    public function testAuthenticateFailsForNonexistentUserWithGenericMessage(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false), // SELECT ... WHERE email = ? -> sin filas
        ]);
        // Requisito 24.1: hash ficticio para proteger contra ataques de tiempo,
        // incluso cuando el usuario no existe.
        $this->passwordHasher->expects($this->once())->method('hash');
        $this->sessionManager->expects($this->never())->method('create');

        $result = $this->service->authenticate('nadie@parce.local', 'somepassword123');

        $this->assertFalse($result->success);
        // Mismo mensaje genérico que credenciales inválidas — anti-enumeración de usuarios.
        $this->assertSame('Correo o contraseña incorrectos', $result->message);
    }

    public function testAuthenticateFailsWithWrongPasswordWithoutCreatingASession(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->activeUserRow()),
        ]);
        $this->passwordHasher->method('verify')->willReturn(false);
        $this->sessionManager->expects($this->never())->method('create');

        $result = $this->service->authenticate('cliente@parce.local', 'wrong-password');

        $this->assertFalse($result->success);
        $this->assertSame('Correo o contraseña incorrectos', $result->message);
    }

    public function testAuthenticateFailsForSuspendedAccountBeforeCheckingPassword(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->activeUserRow(['account_status' => 'suspended'])),
        ]);
        // No debe ni intentar verificar la contraseña de una cuenta inactiva.
        $this->passwordHasher->expects($this->never())->method('verify');

        $result = $this->service->authenticate('cliente@parce.local', 'correct-password-1');

        $this->assertFalse($result->success);
        $this->assertSame('Tu cuenta no está activa', $result->message);
    }

    public function testAuthenticateSucceedsAndCreatesASessionWithClientMetadata(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->activeUserRow()),
            $this->stepWrite(1), // UPDATE users SET last_login_at/last_login_ip
        ]);
        $this->passwordHasher->method('verify')->willReturn(true);
        $this->passwordHasher->method('needsRehash')->willReturn(false);
        $this->sessionManager->expects($this->once())
            ->method('create')
            ->with(7, $this->callback(function (array $metadata) {
                return $metadata['ip_address'] === '203.0.113.5'
                    && $metadata['user_agent'] === 'PHPUnit-Agent'
                    && $metadata['remember'] === false;
            }))
            ->willReturn('a'.str_repeat('f', 39));

        $result = $this->service->authenticate(
            'cliente@parce.local',
            'correct-password-1',
            false,
            '203.0.113.5',
            'PHPUnit-Agent'
        );

        $this->assertTrue($result->success);
        $this->assertSame(7, $result->userId);
        $this->assertSame('a'.str_repeat('f', 39), $result->sessionId);
    }

    public function testAuthenticateTransparentlyRehashesAnOutdatedHashOnSuccessfulLogin(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->activeUserRow()),
            $this->stepWrite(1), // UPDATE users SET password_hash (rehash)
            $this->stepWrite(1), // UPDATE users SET last_login_at/last_login_ip
        ]);
        $this->passwordHasher->method('verify')->willReturn(true);
        $this->passwordHasher->method('needsRehash')->willReturn(true);
        $this->passwordHasher->expects($this->once())->method('hash')->willReturn('$argon2id$newhash');
        $this->sessionManager->method('create')->willReturn('session-id');

        $result = $this->service->authenticate('cliente@parce.local', 'correct-password-1');

        $this->assertTrue($result->success);
    }

    // =========================================================================
    // logout()
    // =========================================================================

    public function testLogoutReturnsTrueWhenSessionManagerDestroysTheSession(): void
    {
        $this->sessionManager->method('destroy')->with('some-session-id')->willReturn(true);

        $this->assertTrue($this->service->logout('some-session-id'));
    }

    public function testLogoutReturnsFalseForANonexistentSession(): void
    {
        $this->sessionManager->method('destroy')->willReturn(false);

        $this->assertFalse($this->service->logout('does-not-exist'));
    }

    // =========================================================================
    // register() — el registro público SOLO asigna el rol 'customer'
    // =========================================================================

    public function testRegisterCreatesUserAssignsOnlyCustomerRoleAndCreatesSession(): void
    {
        $this->allowTransactions();
        $this->expectLastInsertId('55');
        $this->expectQueries([
            $this->stepWrite(1), // INSERT users
            $this->stepFetchOne(['id' => 3]), // SELECT roles WHERE slug = 'customer'
            $this->stepWrite(1), // INSERT user_roles
        ]);
        $this->sessionManager->method('create')->willReturn('new-session-id');

        $result = $this->service->register(
            'nuevo@parce.local',
            '$argon2id$hash',
            ['first_name' => 'Nuevo', 'last_name' => 'Usuario'],
            '127.0.0.1',
            'PHPUnit'
        );

        $this->assertTrue($result->success);
        $this->assertSame(55, $result->userId);
        $this->assertSame('new-session-id', $result->sessionId);
    }

    public function testRegisterThrowsDomainExceptionOnDuplicateEmailAndRollsBack(): void
    {
        $this->mockPdo->method('beginTransaction')->willReturn(true);
        $this->mockPdo->expects($this->once())->method('rollBack')->willReturn(true);
        $this->mockPdo->expects($this->never())->method('commit');

        // PDOException real (no DatabaseException) — es lo que Database::query()
        // realmente captura y reenvuelve en DatabaseException con el mensaje
        // "Query execution failed: ...", que es lo que register() inspecciona.
        $insertStmt = $this->createMock(\PDOStatement::class);
        $insertStmt->method('execute')->willThrowException(
            new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ya-existe@parce.local' for key 'email'")
        );
        $this->mockPdo->method('prepare')->willReturn($insertStmt);

        $this->expectException(DomainException::class);

        try {
            $this->service->register(
                'ya-existe@parce.local',
                '$argon2id$hash',
                ['first_name' => 'Alguien', 'last_name' => 'Más'],
                '127.0.0.1',
                'PHPUnit'
            );
        } catch (DomainException $e) {
            $this->assertSame(409, $e->getStatusCode());
            throw $e;
        }
    }

    // =========================================================================
    // emailExists()
    // =========================================================================

    public function testEmailExistsReturnsTrueWhenAUserRowIsFound(): void
    {
        $this->expectQueries([$this->stepFetchOne(['id' => 1])]);

        $this->assertTrue($this->service->emailExists('cliente@parce.local'));
    }

    public function testEmailExistsReturnsFalseWhenNoRowIsFound(): void
    {
        $this->expectQueries([$this->stepFetchOne(false)]);

        $this->assertFalse($this->service->emailExists('nadie@parce.local'));
    }
}
