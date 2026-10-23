<?php

namespace Tests\Unit\Models\Vehicle;

use App\Core\Request;
use App\Models\Vehicle\VehicleValidator;
use PHPUnit\Framework\TestCase;

class VehicleValidatorTest extends TestCase
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

    private function makeRequest(array $data): Request
    {
        $_POST = $data;
        return new Request();
    }

    private function validCreatePayload(array $overrides = []): array
    {
        return array_merge([
            'license_plate' => 'ABC-123',
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => '2022',
            'vehicle_type' => 'sedan',
            'fuel_type' => 'gasoline',
        ], $overrides);
    }

    public function testValidCreateRequestPassesWithAllRequiredFields(): void
    {
        $result = VehicleValidator::validateCreateRequest($this->makeRequest($this->validCreatePayload()));

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testCreateRequestFailsWhenLicensePlateIsMissing(): void
    {
        $result = VehicleValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['license_plate' => '']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('license_plate', $result['errors']);
    }

    public function testCreateRequestFailsForAYearBeforeNineteenHundred(): void
    {
        $result = VehicleValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['year' => '1899']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('year', $result['errors']);
    }

    public function testCreateRequestFailsForANonNumericYear(): void
    {
        $result = VehicleValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['year' => 'not-a-year']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('year', $result['errors']);
    }

    public function testCreateRequestFailsForAnInvalidVehicleType(): void
    {
        $result = VehicleValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['vehicle_type' => 'spaceship']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('vehicle_type', $result['errors']);
    }

    public function testCreateRequestAcceptsVanAsAValidVehicleType(): void
    {
        $result = VehicleValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['vehicle_type' => 'van']))
        );

        $this->assertTrue($result['valid']);
    }

    public function testCreateRequestFailsForAVinShorterThanSeventeenCharacters(): void
    {
        $result = VehicleValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['vin' => 'SHORT123']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('vin', $result['errors']);
    }

    public function testCreateRequestFailsForAVinWithInvalidCharacters(): void
    {
        // Un VIN válido nunca contiene las letras I, O, Q (fácilmente confundibles con 1/0)
        $result = VehicleValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['vin' => 'IIIIIIIIIIIIIIIII']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('vin', $result['errors']);
    }

    public function testCreateRequestAcceptsAValidSeventeenCharacterVin(): void
    {
        $result = VehicleValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['vin' => '1HGCM82633A004352']))
        );

        $this->assertTrue($result['valid']);
    }

    public function testUpdateRequestAllowsAllFieldsToBeOmitted(): void
    {
        $result = VehicleValidator::validateUpdateRequest($this->makeRequest([]));

        $this->assertTrue($result['valid']);
    }

    public function testUpdateRequestRejectsAnInvalidStatus(): void
    {
        $result = VehicleValidator::validateUpdateRequest($this->makeRequest(['status' => 'exploded']));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('status', $result['errors']);
    }

    public function testNormalizeLicensePlateUppercasesAndTrims(): void
    {
        $this->assertSame('ABC 123', VehicleValidator::normalizeLicensePlate('  abc   123  '));
    }

    public function testNormalizeVINUppercasesAndTrims(): void
    {
        $this->assertSame('1HGCM82633A004352', VehicleValidator::normalizeVIN('  1hgcm82633a004352  '));
    }

    public function testNormalizeVINReturnsNullForEmptyInput(): void
    {
        $this->assertNull(VehicleValidator::normalizeVIN(''));
        $this->assertNull(VehicleValidator::normalizeVIN(null));
    }
}
