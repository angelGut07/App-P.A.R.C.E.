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
            'password'              => 'ContrasenaSegura123',
            'password_confirmation' => 'ContrasenaSegura123',
            'first_name'            => 'Ana',
            'last_name'             => 'Gomez',
        ], $overrides);

        return new Request();
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

    public function testMechanicRegistrationRequiresLicenseAndCertification(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest(['role' => 'mechanic']));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('driver_license_number', $result['errors']);
        $this->assertArrayHasKey('certification_title', $result['errors']);
    }

    public function testMechanicRegistrationSucceedsWithLicenseAndCertification(): void
    {
        $result = RequestValidator::validateRegistrationRequest($this->makeRequest([
            'role'                  => 'mechanic',
            'driver_license_number' => 'LIC-2024-0001',
            'certification_title'   => 'Técnico en Mecánica Automotriz',
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
