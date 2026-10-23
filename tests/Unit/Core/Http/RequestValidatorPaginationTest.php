<?php

namespace Tests\Unit\Core\Http;

use App\Core\Request;
use App\Core\Http\RequestValidator;
use PHPUnit\Framework\TestCase;

class RequestValidatorPaginationTest extends TestCase
{
    private array $getBackup;

    protected function setUp(): void
    {
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
    }

    private function makeRequest(array $query): Request
    {
        $_GET = $query;
        return new Request();
    }

    public function testDefaultsToPageOneAndTheGivenDefaultPerPage(): void
    {
        $result = RequestValidator::parsePagination($this->makeRequest([]));

        $this->assertSame(1, $result['page']);
        $this->assertSame(50, $result['perPage']);
        $this->assertSame(0, $result['offset']);
    }

    public function testComputesOffsetFromPageAndPerPage(): void
    {
        $result = RequestValidator::parsePagination($this->makeRequest(['page' => '3', 'per_page' => '20']));

        $this->assertSame(3, $result['page']);
        $this->assertSame(20, $result['perPage']);
        $this->assertSame(40, $result['offset']);
    }

    public function testClampsPerPageToTheConfiguredMaximum(): void
    {
        $result = RequestValidator::parsePagination($this->makeRequest(['per_page' => '9999']), 50, 200);

        $this->assertSame(200, $result['perPage']);
    }

    public function testFallsBackToDefaultPerPageWhenNonPositive(): void
    {
        $result = RequestValidator::parsePagination($this->makeRequest(['per_page' => '0']));

        $this->assertSame(50, $result['perPage']);
    }

    public function testFallsBackToPageOneWhenNonPositive(): void
    {
        $result = RequestValidator::parsePagination($this->makeRequest(['page' => '-3']));

        $this->assertSame(1, $result['page']);
    }

    public function testIgnoresNonNumericValuesAndUsesDefaults(): void
    {
        $result = RequestValidator::parsePagination($this->makeRequest(['page' => 'abc', 'per_page' => 'xyz']));

        $this->assertSame(1, $result['page']);
        $this->assertSame(50, $result['perPage']);
    }
}
