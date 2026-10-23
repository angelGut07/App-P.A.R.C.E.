<?php

namespace Tests\Unit\Controllers;

use App\Controllers\AdminController;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de AdminController — PDO mockeado, nunca MySQL real.
 *
 * Hallazgo (no corregido aquí, fuera de alcance): AdminService no tiene
 * ningún test propio en el proyecto — es puramente de solo lectura
 * (agregaciones sobre tablas ya existentes), así que se ejercita aquí
 * directamente vía el Controller, que es delgado en ambas acciones.
 */
class AdminControllerTest extends TestCase
{
    use MocksDatabase;
    use SimulatesHttp;

    private AdminController $controller;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->backupSuperglobals();
        $this->controller = new AdminController();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
        $this->restoreSuperglobals();
    }

    public function testDashboardReturnsTheAggregatedSummary(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['role' => 'customer', 'total' => 30], ['role' => 'mechanic', 'total' => 6]]),
            $this->stepFetchAll([
                ['status' => 'pending', 'total' => 3],
                ['status' => 'completed', 'total' => 20],
            ]),
            $this->stepFetchOne(['total' => 5, 'pending' => 2]), // pqr
            $this->stepFetchOne(['total' => 10]), // total_surveys
            $this->stepFetchOne(['average' => 4.567]), // average_rating
            $this->stepFetchOne(['confirmed_amount' => 230000, 'pending' => 1]), // payments
            $this->stepFetchOne(['total' => 2]), // low_stock_products
            $this->stepFetchOne(['total' => 1]), // pending_mechanic_applications
            $this->stepFetchOne(['total' => 38]), // total_users
        ]);

        $request = $this->makeRequest('GET');

        $response = $this->controller->dashboard($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(38, $body['data']['summary']['totalUsers']);
        $this->assertSame(4.57, $body['data']['summary']['averageRating']);
        $this->assertCount(2, $body['data']['summary']['requestsByStatus']);
    }

    public function testRatingsReturnsAPaginatedFilteredList(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['total' => 1]),
            $this->stepFetchAll([
                ['id' => 5, 'customer_rating' => 5, 'mechanic_id' => 42],
            ]),
        ]);

        $request = $this->makeRequest('GET', query: ['mechanic_id' => '42', 'min_rating' => '4']);

        $response = $this->controller->ratings($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['total']);
        $this->assertSame(1, $body['data']['count']);
    }
}
