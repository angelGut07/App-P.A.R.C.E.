<?php

namespace Tests\Unit\Controllers;

use App\Controllers\ServiceRequestController;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de ServiceRequestController — PDO mockeado, nunca MySQL real.
 *
 * Controller delgado: cada acción de negocio delega por completo en
 * ServiceRequestService (ya cubierto exhaustivamente por
 * ServiceRequestServiceTest — create/accept/start/complete/cancel/rate, con
 * todas sus ramas 400/403/404/409) o en MechanicRatingService (cubierto por
 * MechanicRatingServiceTest). Este archivo NO re-prueba esas ramas de negocio;
 * verifica solo que el Controller traduce correctamente a HTTP (un
 * éxito + una traducción de error representativa por acción) y cubre a fondo
 * la lógica que sí vive genuinamente aquí y en ningún otro lado:
 * - El gate Content-Type/parseJsonBody (idéntico en las 7 acciones POST/PUT,
 *   probado una sola vez de forma representativa, no 7 veces).
 * - availableForMechanic(): validación de lat/long/radio — 100% Controller,
 *   sin Service ni Validator detrás.
 * - complete(): validación de final_cost — igual, 100% Controller.
 *
 * ServiceRequestEvidenceService (usado por addEvidence()/getEvidences()) no
 * tiene test unitario propio a nivel de Service — hallazgo documentado en el
 * informe final de esta fase, no corregido aquí (fuera de alcance: esta tarea
 * es cobertura de Controllers/Middleware, no de Services).
 */
class ServiceRequestControllerTest extends TestCase
{
    use MocksDatabase;
    use SimulatesHttp;

    private ServiceRequestController $controller;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->backupSuperglobals();
        $this->controller = new ServiceRequestController();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
        $this->restoreSuperglobals();
    }

    private function requestRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 5, 'service_code' => 'SR-2026-000005', 'customer_id' => 1,
            'mechanic_id' => 42, 'vehicle_id' => 10, 'status' => 'pending',
            'emergency_type' => 'mechanical', 'description' => 'Se dañó el motor',
            'latitude' => 4.71, 'longitude' => -74.07, 'priority' => 'normal',
            'final_cost' => null, 'customer_rating' => null, 'created_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // =========================================================================
    // Gate Content-Type / parseJsonBody — representativo (idéntico en las
    // demás acciones POST/PUT de este Controller), verificado sobre store().
    // =========================================================================

    public function testStoreRejectsAMissingContentTypeWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('POST', body: ['vehicle_id' => 10], asJson: false);

        $response = $this->controller->store($request);

        $this->assertSame(415, $response->getStatusCode());
    }

    // =========================================================================
    // index()
    // =========================================================================

    public function testIndexReturnsTheCustomersServiceRequests(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([$this->requestRow(), $this->requestRow(['id' => 6])]),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 1]);

        $response = $this->controller->index($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $body['data']['count']);
    }

    // =========================================================================
    // store()
    // =========================================================================

    public function testStoreReturnsValidationErrorForMissingFields(): void
    {
        $request = $this->makeRequest('POST', body: []);

        $response = $this->controller->store($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStoreCreatesAndReturnsTheNewServiceRequest(): void
    {
        $this->allowTransactions();
        $this->expectLastInsertId('5');
        $this->expectQueries([
            $this->stepFetchOne(['id' => 10, 'user_id' => 1, 'status' => 'active']), // vehículo
            $this->stepFetchOne(['id' => 1]), // lock cliente
            $this->stepFetchOne(false), // sin solicitud activa del cliente
            $this->stepFetchOne(false), // sin solicitud activa del vehículo
            $this->stepWrite(1), // INSERT service_requests
            $this->stepWrite(1), // UPDATE service_code
            $this->stepFetchOne($this->requestRow()), // getById()
        ]);

        $request = $this->makeRequest('POST', body: [
            'vehicle_id' => 10, 'emergency_type' => 'engine',
            'description' => 'Se dañó el motor', 'latitude' => 4.71, 'longitude' => -74.07,
        ], attributes: ['userId' => 1, 'userRole' => 'customer']);

        $response = $this->controller->store($request);
        $body = $this->responseBody($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(5, $body['data']['serviceRequest']['id']);
    }

    // =========================================================================
    // show()
    // =========================================================================

    public function testShowReturnsTheServiceRequestWhenAccessible(): void
    {
        $this->expectQueries([
            $this->stepFetchOne($this->requestRow()),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 1, 'userRole' => 'customer']);

        $response = $this->controller->show($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('SR-2026-000005', $body['data']['serviceRequest']['serviceCode']);
    }

    public function testShowHidesFromTheRateeTheIndividualRatingTheyReceived(): void
    {
        $rated = ['status' => 'completed', 'customer_rating' => 2, 'customer_feedback' => 'Llegó tarde',
                  'mechanic_rating_score' => 4, 'mechanic_rating_comment' => 'Muy amable'];
        $this->expectQueries([
            $this->stepFetchOne($this->requestRow($rated)),
            $this->stepFetchOne($this->requestRow($rated)),
        ]);

        $asMechanic = $this->responseBody($this->controller->show(
            $this->makeRequest('GET', attributes: ['userId' => 42, 'userRole' => 'mechanic']), 5
        ))['data']['serviceRequest'];
        $asCustomer = $this->responseBody($this->controller->show(
            $this->makeRequest('GET', attributes: ['userId' => 1, 'userRole' => 'customer']), 5
        ))['data']['serviceRequest'];

        $this->assertArrayNotHasKey('customerRating', $asMechanic);
        $this->assertArrayNotHasKey('customerFeedback', $asMechanic);
        $this->assertSame(4, $asMechanic['mechanicRatingScore']);
        $this->assertArrayNotHasKey('mechanicRatingScore', $asCustomer);
        $this->assertSame(2, $asCustomer['customerRating']);
    }

    public function testShowReturns404WhenTheRequestDoesNotExistOrIsNotAccessible(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 1, 'userRole' => 'customer']);

        $response = $this->controller->show($request, 999999);

        $this->assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // update()
    // =========================================================================

    public function testUpdateReturns409WhenTheRequestStoppedBeingPendingConcurrently(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'status' => 'pending']),
            $this->stepWrite(0), // el UPDATE condicionado no afecta ninguna fila
        ]);

        $request = $this->makeRequest('PUT', body: ['description' => 'Nueva descripción'], attributes: [
            'userId' => 1, 'userRole' => 'customer',
        ]);

        $response = $this->controller->update($request, 5);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testUpdateSucceedsAndReturnsTheRefreshedServiceRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'status' => 'pending']),
            $this->stepWrite(1),
            $this->stepFetchOne($this->requestRow(['description' => 'Nueva descripción'])), // getById()
        ]);

        $request = $this->makeRequest('PUT', body: ['description' => 'Nueva descripción'], attributes: [
            'userId' => 1, 'userRole' => 'customer',
        ]);

        $response = $this->controller->update($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Nueva descripción', $body['data']['serviceRequest']['description']);
    }

    // =========================================================================
    // cancel()
    // =========================================================================

    public function testCancelReturns404ForANonexistentRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $request = $this->makeRequest('POST', body: ['cancellation_reason' => 'Ya no lo necesito'], attributes: [
            'userId' => 1, 'userRole' => 'customer',
        ]);

        $response = $this->controller->cancel($request, 999999);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCancelSucceedsAndReturnsTheCancelledServiceRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'status' => 'pending']),
            $this->stepWrite(1),
            $this->stepFetchOne($this->requestRow(['status' => 'cancelled'])), // getById()
        ]);

        $request = $this->makeRequest('POST', body: ['cancellation_reason' => 'Ya no lo necesito'], attributes: [
            'userId' => 1, 'userRole' => 'customer',
        ]);

        $response = $this->controller->cancel($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cancelled', $body['data']['serviceRequest']['status']);
    }

    // =========================================================================
    // rate() — negocio 100% cubierto por ServiceRequestServiceTest; solo se
    // verifica que el Controller traduce el éxito correctamente.
    // =========================================================================

    public function testRateSucceedsAndReturnsTheRatedServiceRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'mechanic_id' => 2, 'status' => 'completed']),
            $this->stepWrite(1),
            $this->stepFetchOne($this->requestRow(['status' => 'completed', 'customer_rating' => 5])), // getById()
        ]);

        $request = $this->makeRequest('POST', body: [
            'customer_rating' => 5, 'customer_feedback' => 'Excelente servicio',
        ], attributes: ['userId' => 1, 'userRole' => 'customer']);

        $response = $this->controller->rate($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(5, $body['data']['serviceRequest']['customerRating']);
    }

    // =========================================================================
    // rateCustomer() — negocio 100% cubierto por MechanicRatingServiceTest.
    // =========================================================================

    public function testRateCustomerSucceedsAndReturnsTheEnrichedServiceRequest(): void
    {
        $this->expectLastInsertId('7');
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'customer_id' => 1, 'mechanic_id' => 42, 'status' => 'completed']),
            $this->stepFetchOne(false), // sin calificación previa
            function (\PDOStatement $stmt): void {}, // INSERT ratings
            $this->stepFetchOne(['id' => 7, 'rating_type' => 'mechanic_to_customer', 'score' => 5]),
            $this->stepFetchOne($this->requestRow(['mechanic_rating_score' => 5])), // getById()
        ]);

        $request = $this->makeRequest('POST', body: ['score' => 5], attributes: [
            'userId' => 42, 'userRole' => 'mechanic',
        ]);

        $response = $this->controller->rateCustomer($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(5, $body['data']['serviceRequest']['mechanicRatingScore']);
    }

    // =========================================================================
    // availableForMechanic() — validación 100% propia del Controller
    // =========================================================================

    public function testAvailableForMechanicRequiresLatitudeAndLongitude(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('GET', query: []);

        $response = $this->controller->availableForMechanic($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAvailableForMechanicRejectsNonNumericCoordinates(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('GET', query: ['latitude' => 'norte', 'longitude' => '-74.07']);

        $response = $this->controller->availableForMechanic($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAvailableForMechanicRejectsANonNumericRadius(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('GET', query: [
            'latitude' => '4.71', 'longitude' => '-74.07', 'radius' => 'lejos',
        ]);

        $response = $this->controller->availableForMechanic($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAvailableForMechanicReturnsNearbyPendingRequests(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([$this->requestRow()]),
        ]);

        $request = $this->makeRequest('GET', query: ['latitude' => '4.71', 'longitude' => '-74.07']);

        $response = $this->controller->availableForMechanic($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['count']);
    }

    // =========================================================================
    // accept() / start() — sin body, negocio cubierto por ServiceRequestServiceTest
    // =========================================================================

    public function testAcceptSucceedsAndReturnsTheAssignedServiceRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'pending']),
            $this->stepFetchOne(['driver_license_expiration_date' => date('Y-m-d', strtotime('+1 year'))]),
            $this->stepWrite(1),
            $this->stepFetchOne($this->requestRow(['status' => 'assigned'])), // getById()
        ]);

        $request = $this->makeRequest('POST', attributes: ['userId' => 42, 'userRole' => 'mechanic']);

        $response = $this->controller->accept($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('assigned', $body['data']['serviceRequest']['status']);
    }

    public function testAcceptTranslatesAConcurrentAcceptanceIntoAConflictResponse(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'pending']),
            $this->stepFetchOne(['driver_license_expiration_date' => date('Y-m-d', strtotime('+1 year'))]),
            $this->stepWrite(0), // ya la tomó otro mecánico
        ]);

        $request = $this->makeRequest('POST', attributes: ['userId' => 42, 'userRole' => 'mechanic']);

        $response = $this->controller->accept($request, 5);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testStartSucceedsAndReturnsTheInProgressServiceRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'assigned', 'mechanic_id' => 42]),
            $this->stepWrite(1),
            $this->stepFetchOne($this->requestRow(['status' => 'in_progress'])), // getById()
        ]);

        $request = $this->makeRequest('PUT', attributes: ['userId' => 42, 'userRole' => 'mechanic']);

        $response = $this->controller->start($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('in_progress', $body['data']['serviceRequest']['status']);
    }

    // =========================================================================
    // complete() — validación de final_cost 100% propia del Controller
    // =========================================================================

    public function testCompleteRequiresAFinalCost(): void
    {
        $request = $this->makeRequest('PUT', body: [], attributes: ['userId' => 42, 'userRole' => 'mechanic']);

        $response = $this->controller->complete($request, 5);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCompleteRejectsANonNumericFinalCost(): void
    {
        $request = $this->makeRequest('PUT', body: ['final_cost' => 'gratis'], attributes: [
            'userId' => 42, 'userRole' => 'mechanic',
        ]);

        $response = $this->controller->complete($request, 5);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCompleteRejectsANegativeFinalCostWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $request = $this->makeRequest('PUT', body: ['final_cost' => -50], attributes: [
            'userId' => 42, 'userRole' => 'mechanic',
        ]);

        $response = $this->controller->complete($request, 5);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCompleteSucceedsAndReturnsTheCompletedServiceRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'status' => 'in_progress', 'mechanic_id' => 42]),
            $this->stepWrite(1),
            $this->stepFetchOne($this->requestRow(['status' => 'completed', 'final_cost' => 150000.0])), // getById()
        ]);

        $request = $this->makeRequest('PUT', body: ['final_cost' => 150000], attributes: [
            'userId' => 42, 'userRole' => 'mechanic',
        ]);

        $response = $this->controller->complete($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('completed', $body['data']['serviceRequest']['status']);
    }

    // =========================================================================
    // mechanicIndex() / mechanicStats()
    // =========================================================================

    public function testMechanicIndexReturnsTheMechanicsAssignedRequests(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([$this->requestRow()]),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 42, 'userRole' => 'mechanic']);

        $response = $this->controller->mechanicIndex($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['count']);
    }

    public function testMechanicStatsReturnsAggregatedStats(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['total' => 12, 'total_earnings' => 1500000.0]),
            $this->stepFetchOne(['avg_rating' => 4.5, 'avg_punctuality' => 4.2, 'avg_service_quality' => 4.8, 'total_rated' => 10]),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 42]);

        $response = $this->controller->mechanicStats($request);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(12, $body['data']['stats']['totalCompleted']);
        $this->assertSame(4.5, $body['data']['stats']['averageRating']);
    }

    // =========================================================================
    // addEvidence() / getEvidences()
    // =========================================================================

    public function testAddEvidenceReturns404ForANonexistentServiceRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(false),
        ]);

        $request = $this->makeRequest('POST', body: [
            'evidence_type' => 'before', 'image_url' => 'https://cdn.parce.test/foto.jpg',
        ], attributes: ['userId' => 42]);

        $response = $this->controller->addEvidence($request, 999999);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAddEvidenceSucceedsAndReturnsTheStoredEvidence(): void
    {
        $this->expectLastInsertId('3');
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 42, 'status' => 'in_progress']),
            $this->stepFetchOne(['status' => 'in_progress']), // revalidación con lock
            function (\PDOStatement $stmt): void {}, // INSERT service_request_evidences
            $this->stepFetchOne([
                'id' => 3, 'service_request_id' => 5, 'uploaded_by' => 42,
                'evidence_type' => 'before', 'image_url' => 'https://cdn.parce.test/foto.jpg',
            ]),
        ]);

        $request = $this->makeRequest('POST', body: [
            'evidence_type' => 'before', 'image_url' => 'https://cdn.parce.test/foto.jpg',
        ], attributes: ['userId' => 42]);

        $response = $this->controller->addEvidence($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('before', $body['data']['evidence']['evidenceType']);
    }

    public function testGetEvidencesReturns403WhenTheUserHasNoAccess(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['customer_id' => 1, 'mechanic_id' => 42]),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 999, 'userRole' => 'customer']);

        $response = $this->controller->getEvidences($request, 5);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testGetEvidencesReturnsTheListForTheOwningCustomer(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['customer_id' => 1, 'mechanic_id' => 42]),
            $this->stepFetchAll([
                ['id' => 3, 'evidence_type' => 'before', 'image_url' => 'https://cdn.parce.test/foto.jpg'],
            ]),
        ]);

        $request = $this->makeRequest('GET', attributes: ['userId' => 1, 'userRole' => 'customer']);

        $response = $this->controller->getEvidences($request, 5);
        $body = $this->responseBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['count']);
    }
}
