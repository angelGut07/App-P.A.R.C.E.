<?php

namespace Tests\Unit\Models\Survey;

use App\Core\Request;
use App\Models\Survey\SurveyValidator;
use PHPUnit\Framework\TestCase;

class SurveyValidatorTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'service_request_id' => '7',
            'overall_satisfaction' => '5',
            'would_recommend' => '1',
        ], $overrides);
    }

    public function testValidCreateRequestPasses(): void
    {
        $result = SurveyValidator::validateCreateRequest($this->makeRequest($this->validPayload()));

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testFailsWhenServiceRequestIdIsMissing(): void
    {
        $result = SurveyValidator::validateCreateRequest(
            $this->makeRequest($this->validPayload(['service_request_id' => '']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('service_request_id', $result['errors']);
    }

    public function testFailsWhenServiceRequestIdIsNotNumeric(): void
    {
        $result = SurveyValidator::validateCreateRequest(
            $this->makeRequest($this->validPayload(['service_request_id' => 'abc']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('service_request_id', $result['errors']);
    }

    public function testFailsWhenOverallSatisfactionIsMissing(): void
    {
        $result = SurveyValidator::validateCreateRequest(
            $this->makeRequest($this->validPayload(['overall_satisfaction' => '']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('overall_satisfaction', $result['errors']);
    }

    public function testFailsWhenOverallSatisfactionIsBelowOne(): void
    {
        $result = SurveyValidator::validateCreateRequest(
            $this->makeRequest($this->validPayload(['overall_satisfaction' => '0']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('overall_satisfaction', $result['errors']);
    }

    public function testFailsWhenOverallSatisfactionExceedsFive(): void
    {
        $result = SurveyValidator::validateCreateRequest(
            $this->makeRequest($this->validPayload(['overall_satisfaction' => '6']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('overall_satisfaction', $result['errors']);
    }

    public function testAcceptsBoundaryValuesOneAndFive(): void
    {
        $low = SurveyValidator::validateCreateRequest(
            $this->makeRequest($this->validPayload(['overall_satisfaction' => '1']))
        );
        $high = SurveyValidator::validateCreateRequest(
            $this->makeRequest($this->validPayload(['overall_satisfaction' => '5']))
        );

        $this->assertTrue($low['valid']);
        $this->assertTrue($high['valid']);
    }

    public function testFailsWhenWouldRecommendIsMissing(): void
    {
        $result = SurveyValidator::validateCreateRequest(
            $this->makeRequest($this->validPayload(['would_recommend' => '']))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('would_recommend', $result['errors']);
    }

    public function testFailsWhenCommentsExceedMaxLength(): void
    {
        $result = SurveyValidator::validateCreateRequest(
            $this->makeRequest($this->validPayload(['comments' => str_repeat('a', 2001)]))
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('comments', $result['errors']);
    }

    public function testCommentsAreOptional(): void
    {
        $result = SurveyValidator::validateCreateRequest($this->makeRequest($this->validPayload()));

        $this->assertTrue($result['valid']);
    }
}
