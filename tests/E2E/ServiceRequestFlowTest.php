<?php

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Flujo completo de un servicio, con el cliente y el mecánico demo en sesiones
 * separadas: solicitud, aceptación, repuesto, evidencia, pago en efectivo y
 * calificaciones.
 *
 * Cada paso depende del anterior (#[Depends]) y le pasa el estado: si uno
 * falla, los siguientes se marcan como omitidos en vez de fallar en cadena.
 */
final class ServiceRequestFlowTest extends E2ETestCase
{
    /** Punto de la emergencia (Bogotá); el mecánico consulta desde el mismo lugar */
    private const LATITUDE = 4.7186;
    private const LONGITUDE = -74.0712;

    #[TestDox('El cliente pide asistencia por batería y recibe un código definitivo')]
    public function testTheCustomerRequestsBatteryAssistance(): array
    {
        $customer = self::loggedIn('customer', 'cliente@parce.test');
        $mechanic = self::loggedIn('mechanic', 'mecanico@parce.test');

        $vehicles = self::assertStatus(200, $customer->get('/vehicles'), 'Consultar los vehículos del cliente')['vehicles'] ?? [];
        $this->assertNotEmpty($vehicles, 'El cliente demo debe tener al menos un vehículo');

        $request = self::assertStatus(201, $customer->post('/service-requests', [
            'vehicle_id'     => $vehicles[0]['id'],
            'emergency_type' => 'battery',
            'description'    => 'El carro no enciende, parece la batería',
            'latitude'       => self::LATITUDE,
            'longitude'      => self::LONGITUDE,
        ]), 'Crear la solicitud')['serviceRequest'] ?? [];

        // El código provisional (PEND-...) se reemplaza en la misma transacción.
        // Con MySQL 8 estricto, insertar sin código daba error 500 (commit 3d43b7f).
        $this->assertMatchesRegularExpression('/^SR-\d{4}-\d{6}$/', (string)($request['serviceCode'] ?? ''));

        return ['customer' => $customer, 'mechanic' => $mechanic, 'requestId' => (int)$request['id']];
    }

    #[Depends('testTheCustomerRequestsBatteryAssistance')]
    #[TestDox('El mecánico la ve entre las solicitudes cercanas, sin la ubicación exacta')]
    public function testTheMechanicSeesItNearbyWithoutTheExactLocation(array $flow): array
    {
        $available = self::assertStatus(200, $flow['mechanic']->get(sprintf(
            '/mechanic/requests/available?latitude=%s&longitude=%s',
            self::LATITUDE,
            self::LONGITUDE
        )), 'Consultar las solicitudes cercanas')['serviceRequests'] ?? [];

        $found = array_values(array_filter($available, fn(array $item) => (int)$item['id'] === $flow['requestId']));
        $this->assertCount(1, $found, 'La solicitud nueva debe aparecer en la lista del mecánico');
        $this->assertArrayHasKey('latitudeApproximate', $found[0]);
        $this->assertArrayNotHasKey('latitude', $found[0], 'Antes de aceptar, el mecánico no debe ver la ubicación exacta');

        return $flow;
    }

    #[Depends('testTheMechanicSeesItNearbyWithoutTheExactLocation')]
    #[TestDox('El mecánico la acepta, el cliente lo ve asignado y el servicio inicia')]
    public function testTheMechanicAcceptsAndStartsTheService(array $flow): array
    {
        $id = $flow['requestId'];

        self::assertStatus(200, $flow['mechanic']->post("/mechanic/requests/{$id}/accept"), 'Aceptar la solicitud');
        $seen = self::assertStatus(200, $flow['customer']->get("/service-requests/{$id}"), 'El cliente consulta su solicitud')['serviceRequest'] ?? [];
        $this->assertSame('assigned', $seen['status'] ?? null);

        $started = self::assertStatus(200, $flow['mechanic']->put("/mechanic/requests/{$id}/start"), 'Iniciar el servicio')['serviceRequest'] ?? [];
        $this->assertSame('in_progress', $started['status'] ?? null);

        return $flow;
    }

    #[Depends('testTheMechanicAcceptsAndStartsTheService')]
    #[TestDox('El mecánico agrega un repuesto del inventario y sube una foto de evidencia')]
    public function testTheMechanicAddsAPartAndAPhoto(array $flow): array
    {
        $id = $flow['requestId'];

        $products = self::assertStatus(200, $flow['mechanic']->get('/inventory/products'), 'Consultar el inventario')['products'] ?? [];
        $inStock = array_values(array_filter($products, fn(array $product) => (float)($product['stockQuantity'] ?? 0) > 0));
        $this->assertNotEmpty($inStock, 'Los datos demo traen productos con existencias');
        self::assertStatus([200, 201], $flow['mechanic']->post("/mechanic/requests/{$id}/parts", [
            'product_id' => $inStock[0]['id'],
            'quantity'   => 1,
        ]), 'Agregar un repuesto');

        $upload = self::assertStatus([200, 201], $flow['mechanic']->upload(
            '/uploads',
            ['category' => 'evidence'],
            'file',
            'evidencia.png',
            self::png(),
            'image/png'
        ), 'Subir la foto')['upload'] ?? [];
        $this->assertNotEmpty($upload['url'] ?? '', 'La subida debe devolver la URL pública del archivo');

        self::assertStatus([200, 201], $flow['mechanic']->post("/mechanic/requests/{$id}/evidence", [
            'evidence_type'     => 'during',
            'image_url'         => $upload['url'],
            'original_filename' => 'evidencia.png',
            'file_size'         => strlen(self::png()),
        ]), 'Registrar la evidencia');

        return $flow + ['evidenceUrl' => $upload['url']];
    }

    #[Depends('testTheMechanicAddsAPartAndAPhoto')]
    #[TestDox('El mecánico termina, el cliente paga en efectivo y el mecánico confirma el pago')]
    public function testTheCustomerPaysInCashAndTheMechanicConfirms(array $flow): array
    {
        $id = $flow['requestId'];

        self::assertStatus(200, $flow['mechanic']->put("/mechanic/requests/{$id}/complete", ['final_cost' => 60000]), 'Completar el servicio');

        // El detalle del cobro: mano de obra (el costo final) más los repuestos
        $bill = self::assertStatus(200, $flow['customer']->get("/service-requests/{$id}/payment"), 'El cliente ve el detalle del cobro')['payment'] ?? [];
        $this->assertEquals(60000, $bill['laborAmount'] ?? null);
        $this->assertGreaterThan(0, $bill['partsTotal'] ?? 0, 'El repuesto agregado debe sumarse al cobro');
        $this->assertEquals($bill['laborAmount'] + $bill['partsTotal'], $bill['total'] ?? null);

        // Las respuestas de pago traen el mismo detalle; el registro del pago va en "payment"
        $pending = self::assertStatus(201, $flow['customer']->post("/service-requests/{$id}/payment", ['method' => 'cash']), 'Pagar en efectivo')['payment']['payment'] ?? [];
        $this->assertSame('pending', $pending['status'] ?? null, 'El efectivo queda pendiente hasta que el mecánico lo confirme');
        $this->assertEquals($bill['total'], $pending['amount'] ?? null, 'Se cobra el total del detalle');

        $confirmed = self::assertStatus(200, $flow['mechanic']->post("/service-requests/{$id}/payment/confirm"), 'Confirmar el pago')['payment']['payment'] ?? [];
        $this->assertSame('confirmed', $confirmed['status'] ?? null);
        $this->assertMatchesRegularExpression('/^REC-\d{4}-\d{6}$/', (string)($confirmed['receiptNumber'] ?? ''));

        return $flow;
    }

    #[Depends('testTheCustomerPaysInCashAndTheMechanicConfirms')]
    #[TestDox('Cliente y mecánico se califican, el cliente responde la encuesta y ve la evidencia')]
    public function testBothRateEachOtherAndTheCustomerSeesTheEvidence(array $flow): void
    {
        $id = $flow['requestId'];

        self::assertStatus(200, $flow['customer']->post("/service-requests/{$id}/rate", [
            'customer_rating'        => 5,
            'customer_feedback'      => 'Llegó rápido y explicó todo',
            'punctuality_rating'     => 5,
            'service_quality_rating' => 4,
        ]), 'El cliente califica al mecánico');

        self::assertStatus([200, 201], $flow['customer']->post('/surveys', [
            'service_request_id'   => $id,
            'overall_satisfaction' => 5,
            'would_recommend'      => true,
            'comments'             => 'Muy buen servicio',
        ]), 'El cliente responde la encuesta');

        self::assertStatus([200, 201], $flow['mechanic']->post("/mechanic/requests/{$id}/rate-customer", [
            'score'   => 5,
            'comment' => 'Cliente amable y puntual',
        ]), 'El mecánico califica al cliente');

        $evidences = self::assertStatus(200, $flow['customer']->get("/service-requests/{$id}/evidences"), 'El cliente consulta las evidencias')['evidences'] ?? [];
        $this->assertSame([$flow['evidenceUrl']], array_column($evidences, 'imageUrl'));
    }

    /** PNG válido de 1x1 píxel para la foto de evidencia */
    private static function png(): string
    {
        return (string)hex2bin(
            '89504e470d0a1a0a0000000d4948445200000001000000010806000000'
            . '1f15c4890000000d49444154789c6360000002000154a24f5d0000000049454e44ae426082'
        );
    }
}
