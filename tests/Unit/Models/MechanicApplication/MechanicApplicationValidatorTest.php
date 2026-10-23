<?php

namespace Tests\Unit\Models\MechanicApplication;

use App\Core\Request;
use App\Models\MechanicApplication\MechanicApplicationValidator;
use PHPUnit\Framework\TestCase;

class MechanicApplicationValidatorTest extends TestCase
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

    public function testCreateRequestPassesWithCertificationAndOptionalFields(): void
    {
        $result = MechanicApplicationValidator::validateCreateRequest($this->makeRequest([
            'certification_title' => 'Técnico en Mecánica Automotriz',
            'specialty'           => 'Mecánica General',
            'years_experience'    => '5',
            'justification'       => 'Tengo 5 años de experiencia como mecánico automotriz certificado.',
        ]));

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testCreateRequestFailsWhenCertificationIsMissing(): void
    {
        $result = MechanicApplicationValidator::validateCreateRequest($this->makeRequest([]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('certification_title', $result['errors']);
    }

    public function testCreateRequestFailsWhenCertificationIsOnlyWhitespace(): void
    {
        $result = MechanicApplicationValidator::validateCreateRequest($this->makeRequest([
            'certification_title' => '                    ',
        ]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('certification_title', $result['errors']);
    }

    public function testCreateRequestFailsWhenYearsOfExperienceIsOutOfRange(): void
    {
        $result = MechanicApplicationValidator::validateCreateRequest($this->makeRequest([
            'certification_title' => 'Técnico',
            'years_experience'    => '99',
        ]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('years_experience', $result['errors']);
    }

    public function testCreateRequestFailsWhenJustificationExceedsMaxLength(): void
    {
        $result = MechanicApplicationValidator::validateCreateRequest($this->makeRequest([
            'certification_title' => 'Técnico',
            'justification' => str_repeat('a', 2001),
        ]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('justification', $result['errors']);
    }

    public function testRejectRequestPassesWithAValidReason(): void
    {
        $result = MechanicApplicationValidator::validateRejectRequest($this->makeRequest([
            'rejection_reason' => 'La licencia de conducción está vencida.',
        ]));

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testRejectRequestFailsWhenReasonIsMissing(): void
    {
        $result = MechanicApplicationValidator::validateRejectRequest($this->makeRequest([]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('rejection_reason', $result['errors']);
    }

    public function testRejectRequestFailsWhenReasonIsOnlyWhitespace(): void
    {
        $result = MechanicApplicationValidator::validateRejectRequest($this->makeRequest([
            'rejection_reason' => '   ',
        ]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('rejection_reason', $result['errors']);
    }

    public function testRejectRequestFailsWhenReasonExceedsMaxLength(): void
    {
        $result = MechanicApplicationValidator::validateRejectRequest($this->makeRequest([
            'rejection_reason' => str_repeat('a', 1001),
        ]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('rejection_reason', $result['errors']);
    }

    public function testGetValidStatusesReturnsTheFourExpectedStates(): void
    {
        $this->assertSame(
            ['pending', 'approved', 'rejected', 'cancelled'],
            MechanicApplicationValidator::getValidStatuses()
        );
    }
}
