<?php

namespace Tests\Unit\Models\ServiceRequest;

use App\Core\Request;
use App\Models\ServiceRequest\ServiceRequestValidator;
use PHPUnit\Framework\TestCase;

class ServiceRequestValidatorTest extends TestCase
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
            'vehicle_id' => '8',
            'emergency_type' => 'battery',
            'description' => 'El carro no enciende, batería descargada.',
            'latitude' => '4.6097',
            'longitude' => '-74.0817',
        ], $overrides);
    }

    // --- validateCreateRequest ---

    public function testValidCreateRequestPasses(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest($this->makeRequest($this->validCreatePayload()));

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testCreateRequestFailsWhenVehicleIdIsMissing(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['vehicle_id' => '']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('vehicle_id', $result['errors']);
    }

    public function testCreateRequestFailsForANonPositiveVehicleId(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['vehicle_id' => '-1']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('vehicle_id', $result['errors']);
    }

    public function testCreateRequestFailsForAnInvalidEmergencyType(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['emergency_type' => 'zombie-attack']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('emergency_type', $result['errors']);
    }

    public function testCreateRequestFailsWhenDescriptionIsShorterThanTenCharacters(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['description' => 'corto']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('description', $result['errors']);
    }

    public function testCreateRequestFailsWhenDescriptionExceedsFiveThousandCharacters(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['description' => str_repeat('a', 5001)]))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('description', $result['errors']);
    }

    public function testCreateRequestFailsForLatitudeOutOfRange(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['latitude' => '95']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('latitude', $result['errors']);
    }

    public function testCreateRequestFailsForLongitudeOutOfRange(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['longitude' => '-181']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('longitude', $result['errors']);
    }

    public function testCreateRequestFailsForAnInvalidPriority(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest(
            $this->makeRequest($this->validCreatePayload(['priority' => 'apocalyptic']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('priority', $result['errors']);
    }

    public function testCreateRequestPriorityIsOptional(): void
    {
        $result = ServiceRequestValidator::validateCreateRequest($this->makeRequest($this->validCreatePayload()));

        $this->assertTrue($result['valid']);
    }

    // --- validateUpdateRequest ---

    public function testUpdateRequestAllowsAllFieldsToBeOmitted(): void
    {
        $result = ServiceRequestValidator::validateUpdateRequest($this->makeRequest([]));

        $this->assertTrue($result['valid']);
    }

    public function testUpdateRequestValidatesDescriptionIfProvided(): void
    {
        $result = ServiceRequestValidator::validateUpdateRequest($this->makeRequest(['description' => 'x']));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('description', $result['errors']);
    }

    // --- validateCancellationRequest ---

    public function testCancellationRequestFailsWithoutAReason(): void
    {
        $result = ServiceRequestValidator::validateCancellationRequest($this->makeRequest([]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('cancellation_reason', $result['errors']);
    }

    public function testCancellationRequestFailsWithAReasonShorterThanTenCharacters(): void
    {
        $result = ServiceRequestValidator::validateCancellationRequest(
            $this->makeRequest(['cancellation_reason' => 'corto'])
        );

        $this->assertFalse($result['valid']);
    }

    public function testCancellationRequestPassesWithAValidReason(): void
    {
        $result = ServiceRequestValidator::validateCancellationRequest(
            $this->makeRequest(['cancellation_reason' => 'Ya no necesito el servicio, gracias.'])
        );

        $this->assertTrue($result['valid']);
    }

    // --- validateRatingRequest ---

    public function testRatingRequestFailsWithoutARating(): void
    {
        $result = ServiceRequestValidator::validateRatingRequest($this->makeRequest([]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('customer_rating', $result['errors']);
    }

    public function testRatingRequestFailsForARatingOutOfRange(): void
    {
        $result = ServiceRequestValidator::validateRatingRequest(
            $this->makeRequest(['customer_rating' => '6'])
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('customer_rating', $result['errors']);
    }

    public function testRatingRequestPassesWithJustTheRequiredRating(): void
    {
        $result = ServiceRequestValidator::validateRatingRequest(
            $this->makeRequest(['customer_rating' => '5'])
        );

        $this->assertTrue($result['valid']);
    }

    public function testRatingRequestValidatesOptionalPunctualityRating(): void
    {
        $result = ServiceRequestValidator::validateRatingRequest(
            $this->makeRequest(['customer_rating' => '5', 'punctuality_rating' => '9'])
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('punctuality_rating', $result['errors']);
    }

    public function testRatingRequestFailsWhenFeedbackExceedsMaxLength(): void
    {
        $result = ServiceRequestValidator::validateRatingRequest(
            $this->makeRequest(['customer_rating' => '5', 'customer_feedback' => str_repeat('a', 2001)])
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('customer_feedback', $result['errors']);
    }

    // --- validateStatusTransition (máquina de estados) ---

    public function testAllowsPendingToAssigned(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('pending', 'assigned');
        $this->assertTrue($result['valid']);
    }

    public function testAllowsPendingToCancelled(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('pending', 'cancelled');
        $this->assertTrue($result['valid']);
    }

    public function testAllowsPendingToExpired(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('pending', 'expired');
        $this->assertTrue($result['valid']);
    }

    public function testAllowsAssignedToInProgress(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('assigned', 'in_progress');
        $this->assertTrue($result['valid']);
    }

    public function testAllowsAssignedToCancelled(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('assigned', 'cancelled');
        $this->assertTrue($result['valid']);
    }

    public function testAllowsInProgressToCompleted(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('in_progress', 'completed');
        $this->assertTrue($result['valid']);
    }

    public function testRejectsPendingDirectlyToInProgress(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('pending', 'in_progress');

        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['error']);
    }

    public function testRejectsPendingDirectlyToCompleted(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('pending', 'completed');

        $this->assertFalse($result['valid']);
    }

    public function testRejectsAssignedBackToPending(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('assigned', 'pending');

        $this->assertFalse($result['valid']);
    }

    public function testRejectsInProgressToCancelled(): void
    {
        // Una vez en progreso, el ciclo de vida ya no permite cancelar — solo completar
        $result = ServiceRequestValidator::validateStatusTransition('in_progress', 'cancelled');

        $this->assertFalse($result['valid']);
    }

    /**
     * @dataProvider terminalStatusesProvider
     */
    public function testRejectsAnyTransitionFromATerminalStatus(string $terminalStatus): void
    {
        $result = ServiceRequestValidator::validateStatusTransition($terminalStatus, 'pending');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('terminal', $result['error']);
    }

    public static function terminalStatusesProvider(): array
    {
        return [
            ['completed'],
            ['cancelled'],
            ['expired'],
        ];
    }

    public function testRejectsAnUnknownCurrentStatus(): void
    {
        $result = ServiceRequestValidator::validateStatusTransition('teleported', 'pending');

        $this->assertFalse($result['valid']);
    }

    // --- helpers de estado ---

    public function testIsActiveStatusRecognizesActiveStates(): void
    {
        $this->assertTrue(ServiceRequestValidator::isActiveStatus('pending'));
        $this->assertTrue(ServiceRequestValidator::isActiveStatus('assigned'));
        $this->assertTrue(ServiceRequestValidator::isActiveStatus('in_progress'));
    }

    public function testIsActiveStatusRejectsTerminalStates(): void
    {
        $this->assertFalse(ServiceRequestValidator::isActiveStatus('completed'));
    }

    public function testIsTerminalStatusRecognizesTerminalStates(): void
    {
        $this->assertTrue(ServiceRequestValidator::isTerminalStatus('completed'));
        $this->assertTrue(ServiceRequestValidator::isTerminalStatus('cancelled'));
        $this->assertTrue(ServiceRequestValidator::isTerminalStatus('expired'));
    }

    public function testIsTerminalStatusRejectsActiveStates(): void
    {
        $this->assertFalse(ServiceRequestValidator::isTerminalStatus('pending'));
    }

    // --- generateServiceCode ---

    public function testGenerateServiceCodeFormatsWithCurrentYearAndPaddedId(): void
    {
        $code = ServiceRequestValidator::generateServiceCode(22);

        $this->assertSame('SR-' . date('Y') . '-000022', $code);
    }
}
