<?php

namespace Tests\Unit\Controllers;

use App\Controllers\MechanicApplicationController;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de MechanicApplicationController — PDO mockeado, nunca
 * MySQL real.
 *
 * MechanicApplicationService tiene cobertura real, pero vía 32 tests de
 * integración contra una BD MySQL aislada (tests/Integration/, opt-in, no
 * conectados a `composer test` — ver roadmap ítem B.1 y
 * PARCE_AS_BUILT_ARCHITECTURE.md §1.16.2), no vía tests unitarios con mocks.
 * Este archivo NO re-verifica esas reglas de negocio (ya probadas contra BD
 * real); solo confirma que el Controller traduce correctamente a HTTP —
 * un éxito + una traducción de error representativa por acción, con las
 * secuencias reales de consultas leídas directamente del Service.
 */
class MechanicApplicationControllerTest extends TestCase
{
    use MocksDatabase;
    use SimulatesHttp;

    private MechanicApplicationController $controller;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->backupSuperglobals();
        $this->controller = new MechanicApplicationController();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
        $this->restoreSuperglobals();
    }

    private function testIp(): string
    {
        return '192.0.2.' . random_int(1, 254);
    }

    private function applicationRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 30, 'user_id' => 7, 'requested_role_id' => 3, 'status' => 'pending',
            'justification' => 'Tengo experiencia en mecánica automotriz',
            'created_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // =========================================================================
    // store()
    // =========================================================================

    public function testStoreRejectsAMissingContentTypeWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $request = $this->makeRequest('POST', body: ['justification' => 'x'], asJson: false);

        $response = $this->controller->store($request);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testStoreReturnsValidationErrorForAMissingJustification(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $request = $this->makeRequest('POST', body: []);

        $response = $this->controller->store($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStoreSucceedsAndReturnsTheNewApplication(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->allowTransactions();
        $this->expectLastInsertId('30');
        $this->expectQueries([
            $this->stepFetchOne([
                'id' => 7, 'account_status' => 'active', 'driver_license_number' => '123',
                'driver_license_expiration_date' => date('Y-m-d', strtotime('+1 year')),
                'driver_license_document_url' => 'https://cdn.parce.test/lic.jpg',
            ]), // usuario, FOR UPDATE
            $this->stepFetchAll([['slug' => 'customer']]), // RoleValidator (hasAnyRole + hasRole, cacheado)
            $this->stepFetchOne(false), // sin solicitud pendiente existente
            $this->stepWrite(1), // INSERT mechanic_applications
            $this->stepFetchOne($this->applicationRow()), // getByIdForUser()
        ]);

        $request = $this->makeRequest('POST', body: [
            'certification_title' => 'Técnico en Mecánica Automotriz',
            'justification'       => 'Tengo experiencia en mecánica automotriz',
        ], attributes: ['userId' => 7]);

        $response = $this->controller->store($request);
        $body = $this->responseBody($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('pending', $body['data']['application']['status']);
    }

    // =========================================================================
    // myApplications()
    // =========================================================================

    public function testMyApplicationsReturnsTheUsersApplicationHistory(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([$this->applicationRow(), $this->applicationRow(['id' => 31, 'status' => 'rejected'])]),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 7]);

        $response = $this->controller->myApplications($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $body['data']['count']);
    }

    // =========================================================================
    // cancel()
    // =========================================================================

    public function testCancelReturns404WhenTheApplicationIsNotOwnedByTheRequester(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $request = $this->makeRequest('POST', attributes: ['userId' => 7]);

        $response = $this->controller->cancel($request, 999999);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCancelSucceedsAndReturnsTheCancelledApplication(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->applicationRow()), // getByIdForUser()
            $this->stepWrite(1), // UPDATE status = cancelled
            $this->stepFetchOne($this->applicationRow(['status' => 'cancelled'])), // getByIdForUser() de nuevo
        ]);

        $request = $this->makeRequest('POST', attributes: ['userId' => 7]);

        $response = $this->controller->cancel($request, 30);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cancelled', $body['data']['application']['status']);
    }

    // =========================================================================
    // adminIndex()
    // =========================================================================

    public function testAdminIndexReturnsAPaginatedList(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['total' => 2]),
            $this->stepFetchAll([$this->applicationRow(), $this->applicationRow(['id' => 31])]),
        ]);

        $request = $this->makeRequest('GET', query: ['status' => 'pending']);

        $response = $this->controller->adminIndex($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $body['data']['total']);
        $this->assertSame(2, $body['data']['count']);
    }

    // =========================================================================
    // approve()
    // =========================================================================

    public function testApproveReturns403WhenTheAdminTriesToApproveTheirOwnApplication(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne($this->applicationRow(['user_id' => 9])), // FOR UPDATE
        ]);

        $request = $this->makeRequest('POST', attributes: ['userId' => 9]);

        $response = $this->controller->approve($request, 30);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testApproveSucceedsAndReturnsTheApprovedApplication(): void
    {
        $this->allowTransactions();
        $this->expectLastInsertId('99');
        $this->expectQueries([
            $this->stepFetchOne($this->applicationRow()), // FOR UPDATE
            $this->stepFetchOne([
                'id' => 7, 'account_status' => 'active', 'driver_license_number' => '123',
                'driver_license_expiration_date' => date('Y-m-d', strtotime('+1 year')),
                'driver_license_document_url' => 'https://cdn.parce.test/lic.jpg',
            ]), // solicitante
            $this->stepFetchOne(['id' => 3]), // getMechanicRoleId()
            $this->stepWrite(1), // INSERT user_roles
            $this->stepWrite(1), // UPDATE status = approved
            $this->stepFetchOne($this->applicationRow(['status' => 'approved'])), // fetch final
        ]);

        $request = $this->makeRequest('POST', attributes: ['userId' => 1]);

        $response = $this->controller->approve($request, 30);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('approved', $body['data']['application']['status']);
    }

    // =========================================================================
    // reject()
    // =========================================================================

    public function testRejectRejectsAMissingContentTypeWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('POST', body: ['rejection_reason' => 'x'], asJson: false, attributes: ['userId' => 1]);

        $response = $this->controller->reject($request, 30);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testRejectReturnsValidationErrorForAMissingReason(): void
    {
        $request = $this->makeRequest('POST', body: [], attributes: ['userId' => 1]);

        $response = $this->controller->reject($request, 30);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRejectSucceedsAndReturnsTheRejectedApplication(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne($this->applicationRow()), // FOR UPDATE
            $this->stepWrite(1), // UPDATE status = rejected
            $this->stepFetchOne($this->applicationRow(['status' => 'rejected', 'rejection_reason' => 'Licencia vencida'])),
        ]);

        $request = $this->makeRequest('POST', body: [
            'rejection_reason' => 'Licencia vencida',
        ], attributes: ['userId' => 1]);

        $response = $this->controller->reject($request, 30);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('rejected', $body['data']['application']['status']);
    }
}
