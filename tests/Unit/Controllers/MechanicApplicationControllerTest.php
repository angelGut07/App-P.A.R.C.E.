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

    /** Fila de mechanic_application_services con el nombre del servicio */
    private function serviceRow(string $code = 'battery', string $status = 'pending', int $applicationId = 30): array
    {
        $names = ['battery' => 'Carga y Reemplazo de Batería', 'tow' => 'Grúa y Remolque de Vehículos', 'tire' => 'Reparación y Cambio de Neumáticos'];

        return [
            'application_id' => $applicationId, 'service_type_code' => $code,
            'service_type_name' => $names[$code], 'status' => $status, 'support_document_url' => null,
        ];
    }

    private function activeApplicant(): array
    {
        return [
            'id' => 7, 'account_status' => 'active', 'driver_license_number' => '123',
            'driver_license_expiration_date' => date('Y-m-d', strtotime('+1 year')),
            'driver_license_document_url' => 'https://cdn.parce.test/lic.jpg',
        ];
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
            $this->stepFetchOne($this->activeApplicant()), // usuario, FOR UPDATE
            $this->stepFetchAll([['slug' => 'customer']]), // RoleValidator (hasAnyRole)
            $this->stepFetchOne(false), // sin solicitud pendiente existente
            $this->stepFetchAll([['code' => 'battery', 'name' => 'Carga y Reemplazo de Batería']]), // servicios activos
            $this->stepFetchAll([]), // servicios ya aprobados: ninguno
            $this->stepWrite(1), // INSERT mechanic_applications
            $this->stepWrite(1), // INSERT mechanic_application_services
            $this->stepFetchOne($this->applicationRow()), // getByIdForUser()
            $this->stepFetchAll([$this->serviceRow()]), // servicios de la solicitud
        ]);

        $request = $this->makeRequest('POST', body: [
            'certification_title' => 'Técnico en Mecánica Automotriz',
            'justification'       => 'Tengo experiencia en mecánica automotriz',
            'services'            => ['battery'],
        ], attributes: ['userId' => 7]);

        $response = $this->controller->store($request);
        $body = $this->responseBody($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('pending', $body['data']['application']['status']);
        $this->assertSame('battery', $body['data']['application']['services'][0]['serviceTypeCode']);
    }

    public function testStoreRequiresAtLeastOneService(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('POST', body: [
            'certification_title' => 'Técnico en Mecánica Automotriz',
        ], attributes: ['userId' => 7]);

        $response = $this->controller->store($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Selecciona al menos un servicio que puedas prestar', $this->responseBody($response)['fields']['services']);
    }

    public function testStoreRejectsAServiceTheMechanicAlreadyHasApproved(): void
    {
        // Ampliación: un mecánico pide más servicios, pero no uno que ya tiene
        $_SERVER['REMOTE_ADDR'] = $this->testIp();
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne($this->activeApplicant()),
            $this->stepFetchAll([['slug' => 'customer'], ['slug' => 'mechanic']]),
            $this->stepFetchOne(false),
            $this->stepFetchAll([
                ['code' => 'battery', 'name' => 'Carga y Reemplazo de Batería'],
                ['code' => 'tow', 'name' => 'Grúa y Remolque de Vehículos'],
            ]),
            $this->stepFetchAll([['service_type_code' => 'battery']]), // ya aprobado
        ]);

        $request = $this->makeRequest('POST', body: [
            'certification_title' => 'Técnico en Mecánica Automotriz',
            'services'            => ['battery', 'tow'],
        ], attributes: ['userId' => 7]);

        $response = $this->controller->store($request);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('Carga y Reemplazo de Batería', $this->responseBody($response)['error']);
    }

    // =========================================================================
    // myApplications()
    // =========================================================================

    public function testMyApplicationsReturnsTheUsersApplicationHistory(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([$this->applicationRow(), $this->applicationRow(['id' => 31, 'status' => 'rejected'])]),
            $this->stepFetchAll([$this->serviceRow(), $this->serviceRow('tow', 'rejected', 31)]),
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
            $this->stepFetchOne($this->applicationRow()), // solicitud propia
            $this->stepWrite(1), // UPDATE status = cancelled
            $this->stepFetchOne($this->applicationRow(['status' => 'cancelled'])), // getByIdForUser()
            $this->stepFetchAll([$this->serviceRow()]),
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
            $this->stepFetchAll([$this->serviceRow(), $this->serviceRow('tow', 'pending', 31)]),
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
            $this->stepFetchOne($this->activeApplicant()), // solicitante
            $this->stepFetchAll([['service_type_code' => 'battery']]), // servicios pedidos
            $this->stepWrite(1), // UPDATE servicios aprobados
            $this->stepFetchAll([['slug' => 'customer']]), // aún no es mecánico
            $this->stepFetchOne(['id' => 3]), // getMechanicRoleId()
            $this->stepWrite(1), // INSERT user_roles
            $this->stepWrite(1), // UPDATE status = approved
            $this->stepFetchOne($this->applicationRow(['status' => 'approved'])), // fetch final
            $this->stepFetchAll([$this->serviceRow('battery', 'approved')]),
        ]);

        // Sin approved_services: se aprueban todos los servicios pedidos
        $request = $this->makeRequest('POST', attributes: ['userId' => 1]);

        $response = $this->controller->approve($request, 30);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('approved', $body['data']['application']['status']);
        $this->assertSame('approved', $body['data']['application']['services'][0]['status']);
    }

    public function testApproveRequiresANoteWhenSomeServicesAreLeftOut(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne($this->applicationRow()),
            $this->stepFetchOne($this->activeApplicant()),
            $this->stepFetchAll([['service_type_code' => 'battery'], ['service_type_code' => 'tow']]),
        ]);

        $request = $this->makeRequest('POST', body: ['approved_services' => ['battery']], attributes: ['userId' => 1]);

        $response = $this->controller->approve($request, 30);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Explica en la nota por qué no apruebas todos los servicios', $this->responseBody($response)['error']);
    }

    public function testApproveOfAServiceExtensionKeepsTheExistingMechanicRole(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne($this->applicationRow()),
            $this->stepFetchOne($this->activeApplicant()),
            $this->stepFetchAll([['service_type_code' => 'battery'], ['service_type_code' => 'tow']]),
            $this->stepWrite(1), // UPDATE aprobados (batería)
            $this->stepWrite(1), // UPDATE rechazados (grúa)
            $this->stepFetchAll([['slug' => 'customer'], ['slug' => 'mechanic']]), // ya es mecánico: sin INSERT de rol
            $this->stepWrite(1), // UPDATE status = approved
            $this->stepFetchOne($this->applicationRow(['status' => 'approved', 'review_notes' => 'Falta la tarjeta de la grúa'])),
            $this->stepFetchAll([$this->serviceRow('battery', 'approved'), $this->serviceRow('tow', 'rejected')]),
        ]);

        $request = $this->makeRequest('POST', body: [
            'approved_services' => ['battery'],
            'review_notes'      => 'Falta la tarjeta de la grúa',
        ], attributes: ['userId' => 1]);

        $response = $this->controller->approve($request, 30);
        $services = $this->responseBody($response)['data']['application']['services'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['approved', 'rejected'], array_column($services, 'status'));
    }

    public function testApproveRejectsAServiceThatWasNotRequested(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne($this->applicationRow()),
            $this->stepFetchOne($this->activeApplicant()),
            $this->stepFetchAll([['service_type_code' => 'battery']]),
        ]);

        $request = $this->makeRequest('POST', body: ['approved_services' => ['tow']], attributes: ['userId' => 1]);

        $this->assertSame(400, $this->controller->approve($request, 30)->getStatusCode());
    }

    // =========================================================================
    // attachServiceDocument()
    // =========================================================================

    public function testAttachServiceDocumentRequiresAnUploadedFile(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('POST', body: ['support_document_url' => 'https://otro-sitio.com/x.pdf'], attributes: ['userId' => 7]);

        $this->assertSame(400, $this->controller->attachServiceDocument($request, 30, 'tow')->getStatusCode());
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
            $this->stepWrite(1), // UPDATE servicios = rejected
            $this->stepFetchOne($this->applicationRow(['status' => 'rejected', 'rejection_reason' => 'Licencia vencida'])),
            $this->stepFetchAll([$this->serviceRow('battery', 'rejected')]),
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
