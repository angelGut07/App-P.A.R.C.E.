<?php

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Una persona nueva se registra con su vehículo, pide ser mecánica y el
 * administrador la aprueba solo para un servicio; además se prueban el
 * inventario y las PQR desde el lado del administrador.
 *
 * Cada paso depende del anterior (#[Depends]) y le pasa el estado.
 */
final class AccountAndAdminFlowTest extends E2ETestCase
{
    private const EMAIL = 'conductora.e2e@parce.test';
    /** Cumple la política de contraseñas (mayúscula, minúscula, número, símbolo, sin palabras comunes) */
    private const PASSWORD = 'Tr3n-Azul#Lluvia';

    #[TestDox('Una persona se registra con su vehículo y queda con la sesión iniciada')]
    public function testSomeoneRegistersWithTheirVehicle(): array
    {
        $user = self::client('customer');

        $data = self::assertStatus(201, $user->post('/auth/register', [
            'email'                 => self::EMAIL,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'first_name'            => 'Laura',
            'last_name'             => 'Pruebas',
            'phone'                 => '3105550123',
            'id_number'             => '1077000111',
            'driver_license_number' => 'LICE2E001',
            'role'                  => 'customer',
            'vehicle_plate'         => 'EZE123',
            'vehicle_brand'         => 'Mazda',
            'vehicle_model'         => '2',
            'vehicle_year'          => '2020',
            'vehicle_color'         => 'Azul',
            'soat_number'           => 'SOATE2E001',
            'tecnomecanica_number'  => 'TME2E001',
        ]), 'Registrarse con un vehículo');
        $this->assertSame(self::EMAIL, $data['user']['email'] ?? null);

        // El registro deja la sesión iniciada: ya puede consultar sus vehículos
        $vehicles = self::assertStatus(200, $user->get('/vehicles'), 'Consultar sus vehículos')['vehicles'] ?? [];
        $this->assertSame(['EZE123'], array_column($vehicles, 'licensePlate'));

        return ['user' => $user];
    }

    #[Depends('testSomeoneRegistersWithTheirVehicle')]
    #[TestDox('Agrega otro vehículo y actualiza su teléfono y su licencia')]
    public function testAddsAnotherVehicleAndUpdatesTheProfile(array $flow): array
    {
        self::assertStatus(201, $flow['user']->post('/vehicles', [
            'license_plate' => 'EZE456',
            'make'          => 'Kia',
            'model'         => 'Picanto',
            'year'          => 2018,
            'color'         => 'Rojo',
            'vehicle_type'  => 'sedan',
            'fuel_type'     => 'gasoline',
        ]), 'Agregar otro vehículo');

        $profile = self::assertStatus(200, $flow['user']->put('/auth/profile', [
            'phone'                           => '3007654321',
            'driver_license_expiration_date'  => date('Y-m-d', strtotime('+2 years')),
        ]), 'Actualizar el perfil');
        $this->assertSame('3007654321', $profile['phone'] ?? null);

        return $flow;
    }

    #[Depends('testAddsAnotherVehicleAndUpdatesTheProfile')]
    #[TestDox('Pide ser mecánica de batería y de grúa')]
    public function testAppliesToBeAMechanic(array $flow): array
    {
        $application = self::assertStatus(201, $flow['user']->post('/mechanic-applications', [
            'certification_title' => 'Técnica en Mecánica Automotriz',
            'services'            => ['battery', 'tow'],
        ]), 'Enviar la solicitud de mecánica')['application'] ?? [];

        $this->assertSame(['battery' => 'pending', 'tow' => 'pending'], self::statusByService($application['services'] ?? []));

        return $flow + ['applicationId' => (int)$application['id']];
    }

    #[Depends('testAppliesToBeAMechanic')]
    #[TestDox('El administrador aprueba solo batería y ella queda habilitada únicamente para ese servicio')]
    public function testTheAdminApprovesOnlyBattery(array $flow): array
    {
        $admin = self::loggedIn('administrator', 'admin@parce.test');

        self::assertStatus(200, $admin->post("/admin/mechanic-applications/{$flow['applicationId']}/approve", [
            'approved_services' => ['battery'],
            'review_notes'      => 'Falta la tarjeta de operación de la grúa',
        ]), 'Aprobar solo el servicio de batería');

        $me = self::assertStatus(200, $flow['user']->get('/auth/me'), 'Consultar su perfil después de la aprobación');
        $this->assertContains('mechanic', $me['roles'] ?? []);
        $this->assertSame(['battery' => 'approved', 'tow' => 'rejected'], self::statusByService($me['mechanicApplication']['services'] ?? []));
        $this->assertSame(['battery'], array_column($me['mechanicProfile']['services'] ?? [], 'code'));

        return $flow + ['admin' => $admin];
    }

    #[Depends('testTheAdminApprovesOnlyBattery')]
    #[TestDox('El administrador registra un producto nuevo y el ingreso de mercancía suma existencias')]
    public function testTheAdminReceivesStock(array $flow): array
    {
        $admin = $flow['admin'];

        $category = self::assertStatus([200, 201], $admin->post('/inventory/categories', ['name' => 'Accesorios E2E']), 'Crear una categoría')['category'] ?? [];
        $product = self::assertStatus([200, 201], $admin->post('/inventory/products', [
            'category_id'    => $category['id'] ?? null,
            'name'           => 'Cable de arranque E2E',
            'unit'           => 'unidad',
            'unit_price'     => 45000,
            'stock_quantity' => 0,
            'min_stock'      => 1,
        ]), 'Crear un producto')['product'] ?? [];
        $supplier = self::assertStatus([200, 201], $admin->post('/inventory/suppliers', [
            'name'  => 'Repuestos E2E',
            'phone' => '3001112233',
        ]), 'Crear un proveedor')['supplier'] ?? [];

        self::assertStatus([200, 201], $admin->post('/inventory/receipts', [
            'supplier_id'    => $supplier['id'] ?? null,
            'invoice_number' => 'FAC-E2E-001',
            'received_on'    => date('Y-m-d'),
            'items'          => [['product_id' => $product['id'] ?? null, 'quantity' => 3, 'unit_cost' => 30000]],
        ]), 'Registrar el ingreso de mercancía');

        $products = self::assertStatus(200, $admin->get('/inventory/products'), 'Consultar el inventario')['products'] ?? [];
        $stock = array_column($products, 'stockQuantity', 'id')[$product['id']] ?? null;
        $this->assertEquals(3, $stock, 'El ingreso de 3 unidades debe verse en las existencias del producto');

        return $flow;
    }

    #[Depends('testTheAdminReceivesStock')]
    #[TestDox('Ella radica una PQR, el administrador la responde y ella ve la respuesta')]
    public function testAPqrGetsAnsweredByTheAdmin(array $flow): void
    {
        $pqr = self::assertStatus(201, $flow['user']->post('/pqr', [
            'type'        => 'sugerencia',
            'subject'     => 'Aviso de llegada',
            'description' => 'Sería útil recibir un aviso cuando el mecánico esté cerca.',
        ]), 'Radicar la PQR')['pqr'] ?? [];
        $this->assertMatchesRegularExpression('/^PQR-\d{4}-\d{6}$/', (string)($pqr['ticketCode'] ?? ''));

        $tickets = self::assertStatus(200, $flow['admin']->get('/admin/pqr'), 'El administrador lista las PQR')['pqr'] ?? [];
        $this->assertContains((int)$pqr['id'], array_map('intval', array_column($tickets, 'id')));

        $answer = 'Gracias, lo tendremos en cuenta en la próxima versión.';
        self::assertStatus(200, $flow['admin']->post("/admin/pqr/{$pqr['id']}/respond", ['admin_response' => $answer]), 'El administrador responde');

        $seen = self::assertStatus(200, $flow['user']->get("/pqr/{$pqr['id']}"), 'Ella consulta su PQR')['pqr'] ?? [];
        $this->assertSame($answer, $seen['adminResponse'] ?? null);
    }

    /**
     * Estado de cada servicio de una solicitud de mecánico, ordenado por código
     *
     * @return array<string, string> código => estado
     */
    private static function statusByService(array $services): array
    {
        $statuses = array_column($services, 'status', 'serviceTypeCode');
        ksort($statuses);

        return $statuses;
    }
}
