<?php

namespace Tests\Unit\Controllers;

use App\Controllers\PQRController;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de PQRController — PDO mockeado, nunca MySQL real.
 *
 * Hallazgo (no corregido aquí, fuera de alcance — esta tarea es Controllers/
 * Middleware, no Services): PQRService no tiene ningún test unitario ni de
 * integración propio; solo PQRValidator está cubierto (PQRValidatorTest).
 * Por eso, a diferencia de ServiceRequestController/VehicleController/
 * MechanicApplicationController, aquí SÍ se ejercitan explícitamente las
 * ramas de negocio del Service (404, 409 por transición de estado inválida o
 * por doble respuesta) además de la traducción HTTP — es la única cobertura
 * que ese Service tiene en todo el proyecto.
 */
class PQRControllerTest extends TestCase
{
    use MocksDatabase;
    use SimulatesHttp;

    private PQRController $controller;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->backupSuperglobals();
        $this->controller = new PQRController();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
        $this->restoreSuperglobals();
    }

    private function ticketRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 12, 'ticket_code' => 'PQR-2026-000012', 'user_id' => 7,
            'type' => 'queja', 'subject' => 'Demora en el servicio',
            'description' => 'El mecánico llegó dos horas tarde',
            'status' => 'pending', 'admin_response' => null,
            'created_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // =========================================================================
    // index()
    // =========================================================================

    public function testIndexReturnsTheUsersTickets(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([$this->ticketRow(), $this->ticketRow(['id' => 13])]),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 7]);

        $response = $this->controller->index($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $body['data']['count']);
    }

    // =========================================================================
    // store()
    // =========================================================================

    public function testStoreRejectsAMissingContentTypeWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('POST', body: ['type' => 'queja'], asJson: false);

        $response = $this->controller->store($request);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testStoreReturnsValidationErrorForAnInvalidType(): void
    {
        $request = $this->makeRequest('POST', body: [
            'type' => 'no-existe', 'subject' => 'Asunto', 'description' => 'Descripción de al menos 10 caracteres',
        ]);

        $response = $this->controller->store($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStoreSucceedsAndReturnsTheNewTicket(): void
    {
        $this->expectLastInsertId('12');
        $this->expectQueries([
            $this->stepWrite(1), // INSERT pqr
            $this->stepWrite(1), // UPDATE ticket_code
            $this->stepFetchOne($this->ticketRow()), // getByIdForUser()
        ]);

        $request = $this->makeRequest('POST', body: [
            'type' => 'queja', 'subject' => 'Demora en el servicio',
            'description' => 'El mecánico llegó dos horas tarde',
        ], attributes: ['userId' => 7]);

        $response = $this->controller->store($request);
        $body = $this->responseBody($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('PQR-2026-000012', $body['data']['pqr']['ticketCode']);
    }

    // =========================================================================
    // show()
    // =========================================================================

    public function testShowReturns404WhenTheTicketIsNotOwnedByTheRequester(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 7]);

        $response = $this->controller->show($request, 999999);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowReturnsTheTicketWhenOwnedByTheRequester(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->ticketRow()),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 7]);

        $response = $this->controller->show($request, 12);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pending', $body['data']['pqr']['status']);
    }

    // =========================================================================
    // adminIndex()
    // =========================================================================

    public function testAdminIndexReturnsAPaginatedList(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['total' => 1]),
            $this->stepFetchAll([$this->ticketRow()]),
        ]);

        $request = $this->makeRequest('GET', query: ['status' => 'pending']);

        $response = $this->controller->adminIndex($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['total']);
    }

    // =========================================================================
    // adminUpdateStatus() — ramas de negocio de PQRService ejercitadas aquí
    // porque no tiene ningún otro test en el proyecto (ver docblock).
    // =========================================================================

    public function testAdminUpdateStatusReturns404ForANonexistentTicket(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false), // getByIdForAdmin() inicial del Controller
        ]);

        $request = $this->makeRequest('PUT', body: ['status' => 'in_review']);

        $response = $this->controller->adminUpdateStatus($request, 999999);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAdminUpdateStatusRejectsAnInvalidTransition(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->ticketRow(['status' => 'resolved'])), // getByIdForAdmin() inicial
            $this->stepFetchOne(['status' => 'resolved']), // updateStatus(): re-lee el estado actual
        ]);

        $request = $this->makeRequest('PUT', body: ['status' => 'in_review']);

        $response = $this->controller->adminUpdateStatus($request, 12);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAdminUpdateStatusSucceedsAndReturnsTheUpdatedTicket(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->ticketRow(['status' => 'pending'])), // getByIdForAdmin() inicial
            $this->stepFetchOne(['status' => 'pending']), // updateStatus(): re-lee el estado actual
            $this->stepWrite(1), // UPDATE status
            $this->stepFetchOne($this->ticketRow(['status' => 'in_review'])), // getByIdForAdmin() final
        ]);

        $request = $this->makeRequest('PUT', body: ['status' => 'in_review']);

        $response = $this->controller->adminUpdateStatus($request, 12);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('in_review', $body['data']['pqr']['status']);
    }

    // =========================================================================
    // adminRespond()
    // =========================================================================

    public function testAdminRespondReturnsValidationErrorForAMissingResponse(): void
    {
        $request = $this->makeRequest('POST', body: []);

        $response = $this->controller->adminRespond($request, 12);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAdminRespondReturns409WhenTheTicketWasAlreadyRespondedTo(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->ticketRow()), // getByIdForAdmin() inicial
            $this->stepWrite(0), // respond(): el WHERE admin_response IS NULL ya no matchea
        ]);

        $request = $this->makeRequest('POST', body: [
            'admin_response' => 'Ya fue atendido anteriormente',
        ], attributes: ['userId' => 1]);

        $response = $this->controller->adminRespond($request, 12);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testAdminRespondSucceedsAndReturnsTheRespondedTicket(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->ticketRow()), // getByIdForAdmin() inicial
            $this->stepWrite(1), // UPDATE admin_response/status
            $this->stepFetchOne($this->ticketRow(['status' => 'resolved', 'admin_response' => 'Solucionado'])),
        ]);

        $request = $this->makeRequest('POST', body: [
            'admin_response' => 'Solucionado',
        ], attributes: ['userId' => 1]);

        $response = $this->controller->adminRespond($request, 12);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('resolved', $body['data']['pqr']['status']);
    }
}
