<?php

namespace Tests\Unit\Controllers;

use App\Controllers\SurveyController;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de SurveyController — PDO mockeado, nunca MySQL real.
 *
 * Hallazgo (no corregido aquí, fuera de alcance — igual que PQRService, ver
 * PQRControllerTest): SurveyService no tiene ningún test unitario ni de
 * integración propio, solo SurveyValidator está cubierto. Por eso este
 * archivo también ejercita explícitamente las ramas de negocio de create()
 * (404, 403, 400, 409), no solo la traducción HTTP.
 */
class SurveyControllerTest extends TestCase
{
    use MocksDatabase;
    use SimulatesHttp;

    private SurveyController $controller;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->backupSuperglobals();
        $this->controller = new SurveyController();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
        $this->restoreSuperglobals();
    }

    private function validSurveyBody(array $overrides = []): array
    {
        return array_merge([
            'service_request_id' => 5, 'overall_satisfaction' => 5,
            'would_recommend' => true, 'comments' => 'Excelente atención',
        ], $overrides);
    }

    // =========================================================================
    // store()
    // =========================================================================

    public function testStoreRejectsAMissingContentTypeWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('POST', body: $this->validSurveyBody(), asJson: false);

        $response = $this->controller->store($request);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testStoreReturnsValidationErrorForMissingFields(): void
    {
        $request = $this->makeRequest('POST', body: []);

        $response = $this->controller->store($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStoreReturns404ForANonexistentServiceRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $request = $this->makeRequest('POST', body: $this->validSurveyBody(), attributes: ['userId' => 1]);

        $response = $this->controller->store($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStoreReturns403WhenTheRequesterDoesNotOwnTheServiceRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 999, 'status' => 'completed']),
        ]);

        $request = $this->makeRequest('POST', body: $this->validSurveyBody(), attributes: ['userId' => 1]);

        $response = $this->controller->store($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStoreReturns400WhenTheServiceRequestIsNotCompleted(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'status' => 'in_progress']),
        ]);

        $request = $this->makeRequest('POST', body: $this->validSurveyBody(), attributes: ['userId' => 1]);

        $response = $this->controller->store($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStoreReturns409WhenTheServiceRequestWasAlreadySurveyed(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'status' => 'completed']),
            $this->stepFetchOne(['id' => 40]), // ya existe una encuesta
        ]);

        $request = $this->makeRequest('POST', body: $this->validSurveyBody(), attributes: ['userId' => 1]);

        $response = $this->controller->store($request);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testStoreSucceeds(): void
    {
        $this->expectLastInsertId('1');
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'status' => 'completed']),
            $this->stepFetchOne(false), // sin encuesta previa
            $this->stepWrite(1), // INSERT surveys
        ]);

        $request = $this->makeRequest('POST', body: $this->validSurveyBody(), attributes: ['userId' => 1]);

        $response = $this->controller->store($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    // =========================================================================
    // index()
    // =========================================================================

    public function testIndexReturnsTheCustomersSurveys(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([
                ['id' => 1, 'service_request_id' => 5, 'overall_satisfaction' => 5],
            ]),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 1]);

        $response = $this->controller->index($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['count']);
    }

    // =========================================================================
    // adminIndex()
    // =========================================================================

    public function testAdminIndexReturnsAPaginatedList(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['total' => 3]),
            $this->stepFetchAll([
                ['id' => 1], ['id' => 2], ['id' => 3],
            ]),
        ]);

        $request = $this->makeRequest('GET');

        $response = $this->controller->adminIndex($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $body['data']['total']);
    }
}
