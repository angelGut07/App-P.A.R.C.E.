<?php

namespace Tests\Unit\Models\Auth;

use App\Core\DomainException;
use App\Models\Auth\AuthService;
use App\Models\Auth\PasswordHasher;
use App\Models\Auth\PasswordResetService;
use App\Models\Auth\SessionManager;
use App\Models\Mail\MailerService;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;

/**
 * Tests unitarios de PasswordResetService — PDO mockeado, nunca MySQL real.
 * AuthService/SessionManager/MailerService se inyectan mockeados (dependencias
 * explícitas del constructor) — solo se prueba la orquestación propia de este
 * Service, no la lógica interna de sus colaboradores.
 *
 * Nota de alcance sobre "token expirado vs inválido": la consulta real es
 * `WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()` — la
 * diferencia entre "no existe", "ya se usó" y "expiró" la resuelve el propio
 * SQL (todas colapsan a "0 filas"), no código de PasswordResetService. Con
 * PDO mockeado no hay forma honesta de distinguir esos tres casos — solo se
 * puede probar "hay fila" vs "no hay fila". Distinguirlos de verdad
 * requeriría una prueba contra MySQL real (como las de
 * tests/Integration/), fuera del alcance de tests unitarios con mocks.
 */
class PasswordResetServiceTest extends TestCase
{
    use MocksDatabase;

    private PasswordHasher $passwordHasher;
    private AuthService $authService;
    private SessionManager $sessionManager;
    private MailerService $mailer;
    private PasswordResetService $service;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->passwordHasher = $this->createMock(PasswordHasher::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->mailer = $this->createMock(MailerService::class);
        $this->service = new PasswordResetService(
            $this->passwordHasher,
            $this->authService,
            $this->sessionManager,
            $this->mailer
        );
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
    }

    // =========================================================================
    // requestReset() — generación del token, hash SHA-256, anti-enumeración
    // =========================================================================

    public function testRequestResetDoesNothingForANonexistentOrInactiveEmail(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(false), // sin usuario activo con ese email
        ]);
        $this->mockPdo->expects($this->never())->method('beginTransaction');
        $this->mailer->expects($this->never())->method('send');

        // No debe lanzar ninguna excepción — respuesta idéntica a la de un email real,
        // para no revelar si la cuenta existe.
        $this->service->requestReset('nadie@parce.local', '127.0.0.1');

        $this->addToAssertionCount(1);
    }

    public function testRequestResetStoresOnlyTheSha256HashOfTheTokenAndEmailsThePlainTokenInTheUrl(): void
    {
        $this->allowTransactions();

        $lookup = $this->createMock(PDOStatement::class);
        $lookup->method('execute')->willReturn(true);
        $lookup->method('fetch')->willReturn(['id' => 9, 'first_name' => 'Ana']);

        $lock = $this->createMock(PDOStatement::class);
        $lock->method('execute')->willReturn(true);
        $lock->method('fetch')->willReturn(['id' => 9]);

        $invalidate = $this->createMock(PDOStatement::class);
        $invalidate->method('execute')->willReturn(true);
        $invalidate->method('rowCount')->willReturn(0);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (array $params) {
                // El segundo parámetro posicional del INSERT es token_hash
                // (user_id, token_hash, ip_address, expires_at) — validamos el
                // formato: 64 caracteres hexadecimales (SHA-256).
                $tokenHash = $params[1];
                $this->tokenHashSeen = $tokenHash;
                return is_string($tokenHash) && preg_match('/^[a-f0-9]{64}$/', $tokenHash) === 1;
            }))
            ->willReturn(true);

        $this->mockPdo->expects($this->exactly(4))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($lookup, $lock, $invalidate, $insertStmt);

        $capturedHtml = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->with(
                'ana@parce.local',
                $this->stringContains('Recupera tu contraseña'),
                $this->callback(function (string $html) use (&$capturedHtml) {
                    $capturedHtml = $html;
                    return true;
                })
            )
            ->willReturn(true);

        $this->service->requestReset('ana@parce.local', '203.0.113.9');

        // Extraer el token en claro del enlace del correo y confirmar que su
        // SHA-256 coincide EXACTAMENTE con el hash que se guardó en la BD —
        // la prueba real de que "se guarda el hash, no el token".
        preg_match('/token=([a-f0-9]{64})/', $capturedHtml, $matches);
        $this->assertNotEmpty($matches, 'El correo debe incluir el token en claro en la URL');
        $plainToken = $matches[1];
        $this->assertSame(hash('sha256', $plainToken), $this->tokenHashSeen);
    }

    private ?string $tokenHashSeen = null;

    // =========================================================================
    // resetPassword() — token válido vs sin fila coincidente (inválido/expirado/usado)
    // =========================================================================

    public function testResetPasswordSucceedsForAValidTokenAndDestroysAllSessions(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(['id' => 1, 'user_id' => 9]), // token válido, no usado, no expirado
            $this->stepWrite(1), // invalidar token(s) del usuario
        ]);
        $this->passwordHasher->method('hash')->willReturn('$argon2id$newhash');
        $this->authService->expects($this->once())
            ->method('updatePassword')
            ->with(9, '$argon2id$newhash');
        $this->sessionManager->expects($this->once())
            ->method('destroyAllUserSessions')
            ->with(9)
            ->willReturn(2);

        $userId = $this->service->resetPassword('un-token-valido-cualquiera', 'NuevaContrasena123');

        $this->assertSame(9, $userId);
    }

    public function testResetPasswordFailsWithoutAMatchingTokenRow(): void
    {
        // Cubre, indistintamente, token inexistente, ya usado, o expirado —
        // los tres producen "0 filas" para esta consulta (ver nota de alcance
        // arriba). No se destruyen sesiones ni se toca la contraseña.
        $this->expectQueries([$this->stepFetchOne(false)]);
        $this->authService->expects($this->never())->method('updatePassword');
        $this->sessionManager->expects($this->never())->method('destroyAllUserSessions');

        $this->expectException(DomainException::class);

        try {
            $this->service->resetPassword('token-invalido-o-vencido-o-usado', 'NuevaContrasena123');
        } catch (DomainException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }
}
