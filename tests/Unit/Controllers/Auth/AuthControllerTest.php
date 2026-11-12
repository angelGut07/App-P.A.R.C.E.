<?php

namespace Tests\Unit\Controllers\Auth;

use App\Controllers\Auth\AuthController;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de AuthController — PDO mockeado, nunca MySQL real.
 *
 * AuthController construye sus propios Services internamente
 * (`new AuthService(...)` en el constructor, sin inyección) — no hay forma de
 * sustituir un AuthService mockeado. Probar el Controller significa manejar la
 * misma cadena real de llamadas Database:: que ya prueba AuthServiceTest, un
 * nivel más abajo. Alcance deliberado: cobertura completa para register()/
 * login() (el "corazón" del flujo); para el resto de acciones, un test
 * representativo por acción — no se re-prueba cada rama de negocio ya cubierta
 * por AuthServiceTest/PasswordResetServiceTest, solo que el Controller
 * traduzca correctamente a HTTP.
 */
class AuthControllerTest extends TestCase
{
    use MocksDatabase;
    use SimulatesHttp;

    private AuthController $controller;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->backupSuperglobals();
        $this->controller = new AuthController();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
        $this->restoreSuperglobals();
    }

    /**
     * IP única por test (rango de documentación RFC 5737) para que
     * RateLimiter::recordAttempt() —que persiste en storage/rate_limit.json
     * real, sin seam para mockearlo— nunca acumule intentos entre corridas
     * repetidas de `composer test` a lo largo de esta sesión.
     */
    private function testIp(): string
    {
        return '192.0.2.' . random_int(1, 254);
    }

    private function activeUserRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 7, 'email' => 'cliente@parce.local', 'password_hash' => '$argon2id$fake',
            'account_status' => 'active', 'first_name' => 'Test', 'last_name' => 'User',
            'phone' => null, 'last_login_at' => null, 'created_at' => '2026-01-01 00:00:00',
            'driver_license_number' => null, 'driver_license_expiration_date' => null,
            'driver_license_document_url' => null, 'id_number' => null, 'profile_picture_url' => null,
            'driver_license_uploaded_at' => null,
        ], $overrides);
    }

    // =========================================================================
    // register()
    // =========================================================================

    public function testRegisterReturnsValidationErrorForMissingFields(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $request = $this->makeRequest('POST', body: []);

        $response = $this->controller->register($request);
        $body = $this->responseBody($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('fields', $body);
        $this->assertSame(
            ['email', 'password', 'password_confirmation', 'first_name', 'last_name'],
            $body['fields']['fields']
        );
    }

    public function testRegisterReturnsConflictWhenEmailAlreadyExists(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->expectQueries([
            $this->stepFetchOne(['id' => 1]), // emailExists() -> ya existe
        ]);

        $request = $this->makeRequest('POST', body: [
            'email' => 'ya-existe@parce.local', 'password' => 'Tr3n-Azul#Lluvia',
            'password_confirmation' => 'Tr3n-Azul#Lluvia', 'first_name' => 'Ana', 'last_name' => 'Gomez',
            'accept_terms' => true,
        ]);

        $response = $this->controller->register($request);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testRegisterSucceedsAndReturnsAFullUserProfileWithSessionCookie(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->allowTransactions();
        $this->expectLastInsertId('55');
        $this->expectQueries([
            $this->stepFetchOne(false), // emailExists() -> libre
            $this->stepWrite(1), // INSERT users
            $this->stepFetchOne(['id' => 3]), // SELECT roles WHERE slug='customer'
            $this->stepWrite(1), // INSERT user_roles
            $this->stepWrite(1), // INSERT user_consents (prueba de la autorización, Ley 1581)
            $this->stepWrite(1), // INSERT sessions (SessionManager::create)
            $this->stepFetchOne($this->activeUserRow(['id' => 55, 'email' => 'nueva@parce.local'])), // buildUserProfile: getUserProfileData
            $this->stepFetchAll([['slug' => 'customer']]), // buildUserProfile: roles
            $this->stepFetchAll([]), // buildUserProfile: solicitudes de mecánico
        ]);

        $request = $this->makeRequest('POST', body: [
            'email' => 'nueva@parce.local', 'password' => 'Tr3n-Azul#Lluvia',
            'password_confirmation' => 'Tr3n-Azul#Lluvia', 'first_name' => 'Nueva', 'last_name' => 'Cuenta',
            'accept_terms' => true,
        ]);

        $response = $this->controller->register($request);
        $body = $this->responseBody($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('nueva@parce.local', $body['data']['user']['email']);
        $this->assertSame(['customer'], $body['data']['user']['roles']);
        $this->assertNotNull($this->responseHeader($response, 'Set-Cookie'));
    }

    // =========================================================================
    // login()
    // =========================================================================

    public function testLoginReturnsValidationErrorForMissingFields(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $request = $this->makeRequest('POST', body: []);

        $response = $this->controller->login($request);
        $body = $this->responseBody($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('fields', $body);
        $this->assertSame(['email', 'password'], $body['fields']['fields']);
    }

    public function testLoginReturns401ForInvalidCredentialsWithoutRevealingWhichFieldWasWrong(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(false), // bloqueo por cuenta: sin fallos previos
            $this->stepFetchOne(false), // usuario no existe
            $this->stepWrite(1),        // recordFailure(): INSERT IGNORE
            $this->stepFetchOne(['failed_attempts' => 0, 'lockout_level' => 0, 'locked_until' => null, 'last_failed_at' => null]),
            $this->stepWrite(1),        // recordFailure(): UPDATE
        ]);

        $request = $this->makeRequest('POST', body: [
            'email' => 'nadie@parce.local', 'password' => 'wrong-password-1',
        ]);

        $response = $this->controller->login($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Correo o contraseña incorrectos', $this->responseBody($response)['error']);
    }

    public function testLoginSucceedsAndReturnsAFullUserProfileWithSessionCookie(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->expectQueries([
            $this->stepFetchOne(false), // bloqueo por cuenta: sin fallos previos
            $this->stepFetchOne($this->activeUserRow(['password_hash' => password_hash('Correct123!', PASSWORD_ARGON2ID)])),
            $this->stepWrite(1), // INSERT sessions
            $this->stepWrite(1), // UPDATE users last_login_at/ip
            $this->stepWrite(0), // clear(): DELETE login_throttles
            $this->stepFetchOne($this->activeUserRow()), // buildUserProfile: getUserProfileData
            $this->stepFetchAll([['slug' => 'customer']]),
            $this->stepFetchAll([]), // buildUserProfile: solicitudes de mecánico
        ]);

        $request = $this->makeRequest('POST', body: [
            'email' => 'cliente@parce.local', 'password' => 'Correct123!',
        ]);

        $response = $this->controller->login($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cliente@parce.local', $body['data']['user']['email']);
        $this->assertNotNull($this->responseHeader($response, 'Set-Cookie'));
        // El id de la sesión viaja solo en la cookie HttpOnly
        $this->assertArrayNotHasKey('id', $body['data']['session']);
    }

    public function testLoginRejectsALockedAccountWithoutCheckingThePassword(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->expectQueries([
            $this->stepFetchOne(['failed_attempts' => 5, 'lockout_level' => 1, 'locked_until' => time() + 600, 'last_failed_at' => time()]),
        ]);

        $request = $this->makeRequest('POST', body: ['email' => 'cliente@parce.local', 'password' => 'Correct123!']);

        $response = $this->controller->login($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('bloqueamos temporalmente el acceso a esta cuenta', $this->responseBody($response)['error']);
        $this->assertNotNull($this->responseHeader($response, 'Retry-After'));
    }

    public function testLoginLocksTheAccountOnTheFifthConsecutiveFailure(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->allowTransactions();
        $previous = ['failed_attempts' => 4, 'lockout_level' => 0, 'locked_until' => null, 'last_failed_at' => time() - 60];
        $this->expectQueries([
            $this->stepFetchOne($previous), // estado: 4 fallos, aún sin bloqueo
            $this->stepFetchOne(false),     // usuario no existe (mismo trato que uno real)
            $this->stepWrite(1),            // INSERT IGNORE
            $this->stepFetchOne($previous), // SELECT … FOR UPDATE
            $this->stepWrite(1),            // UPDATE: queda bloqueada 15 minutos
            $this->stepFetchOne(false),     // aviso por correo: el correo no tiene cuenta
        ]);

        $request = $this->makeRequest('POST', body: ['email' => 'nadie@parce.local', 'password' => 'wrong-password-1']);

        $response = $this->controller->login($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('15 minutos', $this->responseBody($response)['error']);
    }

    public function testLoginRequiresTheCaptchaAfterSeveralFailuresWhenItIsConfigured(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $_ENV['TURNSTILE_SITE_KEY'] = 'site-key-de-prueba';
        $_ENV['TURNSTILE_SECRET_KEY'] = 'secret-key-de-prueba';
        $this->expectQueries([
            $this->stepFetchOne(['failed_attempts' => 3, 'lockout_level' => 0, 'locked_until' => null, 'last_failed_at' => time()]),
        ]);

        try {
            // Sin captcha_token: se rechaza antes de verificar la contraseña (y sin red)
            $request = $this->makeRequest('POST', body: ['email' => 'cliente@parce.local', 'password' => 'Correct123!']);
            $response = $this->controller->login($request);
        } finally {
            unset($_ENV['TURNSTILE_SITE_KEY'], $_ENV['TURNSTILE_SECRET_KEY']);
        }

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['Confirma que no eres un robot para continuar'], $this->responseBody($response)['fields']['captcha']);
    }

    // =========================================================================
    // logout()
    // =========================================================================

    public function testLogoutIsIdempotentWhenThereIsNoSessionCookie(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('POST', cookies: []);

        $response = $this->controller->logout($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testLogoutDestroysTheRealSessionAndClearsTheCookie(): void
    {
        $this->expectQueries([
            $this->stepWrite(1), // DELETE sessions
        ]);

        $request = $this->makeRequest('POST', cookies: ['parce_session' => 'session-abc']);

        $response = $this->controller->logout($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Max-Age=0', $this->responseHeader($response, 'Set-Cookie'));
    }

    // =========================================================================
    // me()
    // =========================================================================

    public function testMeReturns401WhenAuthMiddlewareDidNotAttachAUser(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('GET');

        $response = $this->controller->me($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testMeReturnsTheFullProfileForTheAuthenticatedUser(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->activeUserRow()),
            $this->stepFetchAll([['slug' => 'customer']]),
            $this->stepFetchAll([]), // buildUserProfile: solicitudes de mecánico
        ]);

        $request = $this->makeRequest('GET', attributes: ['user' => ['id' => 7]]);

        $response = $this->controller->me($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cliente@parce.local', $body['data']['email']);
    }

    // =========================================================================
    // profile()
    // =========================================================================

    public function testProfileReturns401WhenAuthMiddlewareDidNotAttachAUser(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('PUT');

        $response = $this->controller->profile($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testProfileRejectsAMalformedExpirationDateWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('PUT', body: [
            'driver_license_expiration_date' => 'not-a-date',
        ], attributes: ['user' => ['id' => 7]]);

        $response = $this->controller->profile($request);
        $body = $this->responseBody($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('YYYY-MM-DD', $body['fields']['driverLicenseExpirationDate']);
    }

    public function testProfileUpdatesTheLicenseAndReturnsTheRefreshedProfile(): void
    {
        $this->expectQueries([
            $this->stepWrite(1), // UPDATE users (updateProfile)
            $this->stepFetchOne($this->activeUserRow(['driver_license_number' => '999'])), // me(): getUserProfileData
            $this->stepFetchAll([['slug' => 'customer']]), // me(): roles
            $this->stepFetchAll([]), // buildUserProfile: solicitudes de mecánico
        ]);

        $request = $this->makeRequest('PUT', body: [
            'driver_license_number' => '999',
        ], attributes: ['user' => ['id' => 7]]);

        $response = $this->controller->profile($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('999', $body['data']['driverLicense']['number']);
    }

    // =========================================================================
    // changePassword()
    // =========================================================================

    public function testChangePasswordReturns401WhenAuthMiddlewareDidNotAttachAUser(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('PUT');

        $response = $this->controller->changePassword($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testChangePasswordRejectsAnIncorrectCurrentPassword(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->expectQueries([
            $this->stepFetchOne(['password_hash' => password_hash('TheRealOne123!', PASSWORD_ARGON2ID)]),
        ]);

        $request = $this->makeRequest('PUT', body: [
            'current_password' => 'wrong-one-123', 'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
        ], attributes: ['user' => ['id' => 7]]);

        $response = $this->controller->changePassword($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('La contraseña actual es incorrecta', $this->responseBody($response)['error']);
    }

    // =========================================================================
    // forgotPassword() / resetPassword()
    // =========================================================================

    public function testForgotPasswordAlwaysRespondsGenericallyEvenForANonexistentEmail(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->expectQueries([
            $this->stepFetchOne(false), // requestReset(): usuario no existe -> no-op
        ]);

        $request = $this->makeRequest('POST', body: ['email' => 'nadie@parce.local']);

        $response = $this->controller->forgotPassword($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'Si el correo corresponde',
            $this->responseBody($response)['data']['message'] ?? $this->responseBody($response)['message']
        );
    }

    public function testResetPasswordRejectsAnInvalidOrExpiredToken(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->expectQueries([
            $this->stepFetchOne(false), // resetPassword(): sin fila de token coincidente
        ]);

        $request = $this->makeRequest('POST', body: [
            'token' => 'no-such-token', 'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
        ]);

        $response = $this->controller->resetPassword($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    // =========================================================================
    // passwordCheck()
    // =========================================================================

    public function testPasswordCheckReportsEveryRuleThePasswordBreaks(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('POST', body: ['password' => 'carlos2026', 'first_name' => 'Carlos']);

        $response = $this->controller->passwordCheck($request);
        $data = $this->responseBody($response)['data'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($data['valid']);
        $this->assertCount(2, $data['errors']); // composición + contiene el nombre
    }

    public function testPasswordCheckAcceptsAStrongPassword(): void
    {
        $request = $this->makeRequest('POST', body: ['password' => 'Tr3n-Azul#Lluvia', 'email' => 'ana@parce.local']);

        $data = $this->responseBody($this->controller->passwordCheck($request))['data'];

        $this->assertTrue($data['valid']);
        $this->assertSame([], $data['errors']);
    }

    // =========================================================================
    // health()
    // =========================================================================

    public function testHealthReturnsHealthyWhenTheDatabaseResponds(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['test' => 1]), // AuthService::checkDatabaseHealth()
        ]);

        $request = $this->makeRequest('GET');

        $response = $this->controller->health($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('healthy', $this->responseBody($response)['data']['status']);
    }
}
