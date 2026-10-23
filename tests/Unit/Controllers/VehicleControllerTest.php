<?php

namespace Tests\Unit\Controllers;

use App\Controllers\VehicleController;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de VehicleController — PDO mockeado, nunca MySQL real.
 *
 * VehicleService ya está cubierto exhaustivamente por VehicleServiceTest
 * (incluyendo los locks nombrados de MySQL GET_LOCK/RELEASE_LOCK para
 * unicidad de placa/VIN) — este archivo no repite esas ramas, solo verifica
 * la traducción HTTP y la única pieza de lógica que vive exclusivamente en
 * este Controller: `formatVehicle()`, el *type casting* explícito
 * (`is_primary` TINYINT -> bool) que ResponseFormatter::success() no hace
 * por sí solo (documentado como convención deliberada en el propio código).
 */
class VehicleControllerTest extends TestCase
{
    use MocksDatabase;
    use SimulatesHttp;

    private VehicleController $controller;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->backupSuperglobals();
        $this->controller = new VehicleController();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
        $this->restoreSuperglobals();
    }

    private function vehicleRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 10, 'user_id' => 1, 'license_plate' => 'ABC-123',
            'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2022, 'color' => 'Rojo',
            'vin' => null, 'vehicle_type' => 'sedan', 'fuel_type' => 'gasoline',
            'nickname' => null, 'primary_photo_url' => null, 'is_primary' => 1,
            'status' => 'active', 'soat_expiration_date' => null,
            'tecnomecanica_expiration_date' => null,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // =========================================================================
    // formatVehicle() — type casting explícito, vía index()/show()
    // =========================================================================

    public function testIndexCastsIsPrimaryFromTinyintToARealBoolean(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([$this->vehicleRow(['is_primary' => 1]), $this->vehicleRow(['id' => 11, 'is_primary' => 0])]),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 1]);

        $response = $this->controller->index($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['data']['vehicles'][0]['isPrimary']);
        $this->assertFalse($body['data']['vehicles'][1]['isPrimary']);
        $this->assertIsInt($body['data']['vehicles'][0]['id']);
        $this->assertIsInt($body['data']['vehicles'][0]['year']);
    }

    // =========================================================================
    // store()
    // =========================================================================

    public function testStoreRejectsAMissingContentTypeWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('POST', body: ['make' => 'Toyota'], asJson: false);

        $response = $this->controller->store($request);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testStoreReturnsValidationErrorForMissingFields(): void
    {
        $request = $this->makeRequest('POST', body: []);

        $response = $this->controller->store($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStoreCreatesAndReturnsTheNewVehicle(): void
    {
        $this->expectLastInsertId('10');
        $this->expectQueries([
            $this->stepFetchOne(['acquired' => 1]), // GET_LOCK placa
            $this->stepFetchOne(false), // placa libre
            function (\PDOStatement $stmt): void {}, // INSERT vehicles
            function (\PDOStatement $stmt): void {}, // RELEASE_LOCK placa
            $this->stepFetchOne($this->vehicleRow()), // getById()
        ]);

        $request = $this->makeRequest('POST', body: [
            'license_plate' => 'ABC-123', 'make' => 'Toyota', 'model' => 'Corolla',
            'year' => 2022, 'vehicle_type' => 'sedan', 'fuel_type' => 'gasoline',
        ], attributes: ['userId' => 1]);

        $response = $this->controller->store($request);
        $body = $this->responseBody($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('ABC-123', $body['data']['vehicle']['licensePlate']);
        $this->assertTrue($body['data']['vehicle']['isPrimary']);
    }

    // =========================================================================
    // show()
    // =========================================================================

    public function testShowReturnsTheVehicleWhenOwnedByTheRequester(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->vehicleRow()),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 1]);

        $response = $this->controller->show($request, 10);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(10, $body['data']['vehicle']['id']);
    }

    public function testShowReturns404WhenTheVehicleDoesNotExistOrIsNotOwned(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 1]);

        $response = $this->controller->show($request, 999999);

        $this->assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // update()
    // =========================================================================

    public function testUpdateSucceedsAndReturnsTheRefreshedVehicle(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->vehicleRow(['vin' => null, 'is_primary' => 0])), // ownership + estado actual
            $this->stepWrite(1), // UPDATE vehicles
            $this->stepFetchOne($this->vehicleRow(['color' => 'Azul'])), // getById()
        ]);

        $request = $this->makeRequest('PUT', body: ['color' => 'Azul'], attributes: ['userId' => 1]);

        $response = $this->controller->update($request, 10);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Azul', $body['data']['vehicle']['color']);
    }

    public function testUpdateReturns404ForANonexistentVehicle(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $request = $this->makeRequest('PUT', body: ['color' => 'Azul'], attributes: ['userId' => 1]);

        $response = $this->controller->update($request, 999999);

        $this->assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // destroy()
    // =========================================================================

    public function testDestroySoftDeletesTheVehicle(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 1, 'is_primary' => 0]),
            $this->stepWrite(1),
        ]);

        $request = $this->makeRequest('DELETE', attributes: ['userId' => 1]);

        $response = $this->controller->destroy($request, 10);

        $this->assertSame(200, $response->getStatusCode());
    }

    // =========================================================================
    // setPrimary()
    // =========================================================================

    public function testSetPrimarySucceedsAndReturnsTheUpdatedVehicle(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 1]),
            $this->stepWrite(1), // unset previous primary
            $this->stepWrite(1), // set new primary
            $this->stepFetchOne($this->vehicleRow(['is_primary' => 1])), // getById()
        ]);

        $request = $this->makeRequest('PUT', attributes: ['userId' => 1]);

        $response = $this->controller->setPrimary($request, 10);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['data']['vehicle']['isPrimary']);
    }

    public function testSetPrimaryReturns403ForANonOwner(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 999]),
        ]);

        $request = $this->makeRequest('PUT', attributes: ['userId' => 1]);

        $response = $this->controller->setPrimary($request, 10);

        $this->assertSame(403, $response->getStatusCode());
    }
}
