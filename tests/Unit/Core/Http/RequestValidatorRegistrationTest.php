<?php

namespace Tests\Unit\Core\Http;

use App\Core\Request;
use App\Core\Http\RequestValidator;
use PHPUnit\Framework\TestCase;

/**
 * Cubre la validación del registro público. 'role' solo acepta customer o
 * mechanic (mechanic abre una solicitud, no concede el rol); cualquier otro
 * valor —p. ej. 'administrator' o 'super_admin'— se rechaza con 400.
 */
class RequestValidatorRegistrationTest extends TestCase
{
    private array $postBackup;

    protected function setUp(): void
    {
        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
    }

    private function makeRequest(array $overrides = []): Request
    {
        $_POST = array_merge([
            'email'                 => 'nuevo@example.com',
            'password'              => 'Tr3n-Azul#Lluvia',
            'password_confirmation' => 'Tr3n-Azul#Lluvia',
            'first_name'            => 'Ana',
            'last_name'             => 'Gomez',
            'accept_terms'          => true,
        ], $overrides);

        return new Request();
    }

    public static function missingConsent(): array
    {
        return [
            'sin el campo' => [null],
            'en false'     => [false],
            'texto "no"'   => ['no'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingConsent')]
    public function testRegistrationRequiresAcceptingTheTermsAndDataPolicy(mixed $value): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest(['accept_terms' => $value]));

        $this->assertFalse($result['valid']);
        $this->assertSame(
            ['Debes aceptar los Términos y la Política de tratamiento de datos para crear tu cuenta'],
            $result['errors']['accept_terms']
        );
    }

    public function testConsentAcceptsTheValuesFormsSend(): void
    {
        foreach (['1', 'true', 'on'] as $value) {
            $result = RequestValidator::validateRegistrationRequest($this->makeRequest(['accept_terms' => $value]));
            $this->assertTrue($result['valid'], "accept_terms = '{$value}' debe aceptarse");
        }
    }

    public function testRegistrationSucceedsWithoutRoleField(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest());

        $this->assertTrue($result['valid']);
    }

    public function testRegistrationSucceedsWithExplicitCustomerRole(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest(['role' => 'customer']));

        $this->assertTrue($result['valid']);
    }

    public function testMechanicRegistrationRequiresLicenseWithExpirationAndCertification(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest(['role' => 'mechanic']));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('driver_license_number', $result['errors']);
        $this->assertArrayHasKey('driver_license_expiration_date', $result['errors']);
        $this->assertArrayHasKey('certification_title', $result['errors']);
    }

    /** Datos completos de un mecánico, con cambios opcionales */
    private function mechanicRequest(array $changes = []): Request
    {
        return $this->makeRequest(array_merge([
            'role'                           => 'mechanic',
            'driver_license_number'          => 'LIC-2024-0001',
            'driver_license_expiration_date' => date('Y-m-d', strtotime('+1 year')),
            'certification_title'            => 'Técnico en Mecánica Automotriz',
            'services'                       => ['battery', 'tire'],
        ], $changes));
    }

    public function testMechanicRegistrationSucceedsWithLicenseAndCertification(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->mechanicRequest());

        $this->assertTrue($result['valid']);
    }

    public function testMechanicRegistrationAcceptsALicenseThatExpiresToday(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->mechanicRequest([
            'driver_license_expiration_date' => date('Y-m-d'),
        ]));

        $this->assertTrue($result['valid']);
    }

    public function testMechanicRegistrationRejectsAnExpiredLicense(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->mechanicRequest([
            'driver_license_expiration_date' => date('Y-m-d', strtotime('-1 day')),
        ]));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('vencida', $result['errors']['driver_license_expiration_date'][0]);
    }

    public static function invalidDates(): array
    {
        return [
            'día que no existe' => ['2027-02-30'],
            'otro formato'      => ['12/05/2027'],
            'texto'             => ['mañana'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDates')]
    public function testLicenseExpirationMustBeARealIsoDate(string $value): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->mechanicRequest([
            'driver_license_expiration_date' => $value,
        ]));

        $this->assertFalse($result['valid']);
        $this->assertSame(
            ['La fecha de vencimiento debe tener el formato AAAA-MM-DD'],
            $result['errors']['driver_license_expiration_date']
        );
    }

    public function testCustomersDoNotNeedACurrentLicenseToRegister(): void
    {
        // Los clientes no necesitan licencia para pedir asistencia: la fecha es
        // opcional y, si la escriben, solo se valida su formato
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest([
            'role'                           => 'customer',
            'driver_license_expiration_date' => date('Y-m-d', strtotime('-1 month')),
        ]));

        $this->assertTrue($result['valid']);
    }

    public function testRegistrationRequiresVehicleDataWhenAPlateIsSent(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest(['vehicle_plate' => 'ABC123']));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('vehicle_brand', $result['errors']);
        $this->assertArrayHasKey('vehicle_year', $result['errors']);
    }

    public function testRegistrationFailsWhenRoleIsAdministrator(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest(['role' => 'administrator']));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('role', $result['errors']);
    }

    public function testRegistrationFailsWhenRoleIsSuperAdmin(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest(['role' => 'super_admin']));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('role', $result['errors']);
    }

    public function testRegistrationFailsWhenRoleIsAnArbitraryManipulatedString(): void
    {
        // Cubre manipulación arbitraria del campo, no solo los slugs de rol reales
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest(['role' => 'super_admin_hack']));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('role', $result['errors']);
    }
}
