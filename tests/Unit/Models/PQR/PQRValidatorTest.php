<?php

namespace Tests\Unit\Models\PQR;

use App\Core\Request;
use App\Models\PQR\PQRValidator;
use PHPUnit\Framework\TestCase;

class PQRValidatorTest extends TestCase
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

    public function testCreateRequestPassesWithValidData(): void
    {
        $result = PQRValidator::validateCreateRequest($this->makeRequest([
            'type' => 'queja',
            'subject' => 'Retraso en la atención',
            'description' => 'El mecánico llegó una hora tarde.',
        ]));

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testCreateRequestFailsForAnInvalidType(): void
    {
        $result = PQRValidator::validateCreateRequest($this->makeRequest([
            'type' => 'not-a-real-type',
            'subject' => 'Asunto',
            'description' => 'Descripción',
        ]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('type', $result['errors']);
    }

    public function testCreateRequestFailsWhenSubjectExceedsMaxLength(): void
    {
        $result = PQRValidator::validateCreateRequest($this->makeRequest([
            'type' => 'peticion',
            'subject' => str_repeat('a', 151),
            'description' => 'Descripción',
        ]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('subject', $result['errors']);
    }

    public function testCreateRequestFailsWhenDescriptionIsMissing(): void
    {
        $result = PQRValidator::validateCreateRequest($this->makeRequest([
            'type' => 'sugerencia',
            'subject' => 'Asunto',
        ]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('description', $result['errors']);
    }

    public function testStatusUpdateRequestAcceptsAValidStatus(): void
    {
        $result = PQRValidator::validateStatusUpdateRequest($this->makeRequest(['status' => 'in_review']));

        $this->assertTrue($result['valid']);
    }

    public function testStatusUpdateRequestRejectsAnInvalidStatus(): void
    {
        $result = PQRValidator::validateStatusUpdateRequest($this->makeRequest(['status' => 'closed']));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('status', $result['errors']);
    }

    public function testRespondRequestFailsWithoutAResponse(): void
    {
        $result = PQRValidator::validateRespondRequest($this->makeRequest([]));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('admin_response', $result['errors']);
    }

    public function testRespondRequestPassesWithAResponse(): void
    {
        $result = PQRValidator::validateRespondRequest($this->makeRequest([
            'admin_response' => 'Ya fue resuelto.',
        ]));

        $this->assertTrue($result['valid']);
    }

    public function testStatusTransitionAllowsPendingToInReview(): void
    {
        $result = PQRValidator::validateStatusTransition('pending', 'in_review');
        $this->assertTrue($result['valid']);
    }

    public function testStatusTransitionAllowsPendingToRejected(): void
    {
        $result = PQRValidator::validateStatusTransition('pending', 'rejected');
        $this->assertTrue($result['valid']);
    }

    public function testStatusTransitionAllowsPendingToResolved(): void
    {
        $result = PQRValidator::validateStatusTransition('pending', 'resolved');
        $this->assertTrue($result['valid']);
    }

    public function testStatusTransitionAllowsInReviewToRejected(): void
    {
        $result = PQRValidator::validateStatusTransition('in_review', 'rejected');
        $this->assertTrue($result['valid']);
    }

    public function testStatusTransitionRejectsInReviewBackToInReview(): void
    {
        // La UI nunca ofrece "marcar en revisión" para un ticket ya en revisión
        $result = PQRValidator::validateStatusTransition('in_review', 'in_review');
        $this->assertFalse($result['valid']);
    }

    public function testStatusTransitionRejectsFromResolvedTerminalState(): void
    {
        $result = PQRValidator::validateStatusTransition('resolved', 'in_review');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('terminal', $result['error']);
    }

    public function testStatusTransitionRejectsFromRejectedTerminalState(): void
    {
        $result = PQRValidator::validateStatusTransition('rejected', 'pending');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('terminal', $result['error']);
    }

    public function testGenerateTicketCodeFormatsWithCurrentYearAndPaddedId(): void
    {
        $code = PQRValidator::generateTicketCode(5);

        $this->assertSame('PQR-' . date('Y') . '-000005', $code);
    }

    public function testGenerateTicketCodeDoesNotTruncateIdsLongerThanSixDigits(): void
    {
        $code = PQRValidator::generateTicketCode(1234567);

        $this->assertSame('PQR-' . date('Y') . '-1234567', $code);
    }
}
