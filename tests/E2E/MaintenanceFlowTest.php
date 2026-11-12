<?php

namespace Tests\E2E;

use App\Core\Database;
use App\Models\Maintenance\MaintenanceService;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\E2E\Support\ApiClient;

/**
 * Mantenimiento programado (POST /api/maintenance/run), como lo llama el cron
 * de GitHub Actions: solo con el token, y expira las solicitudes que ningún
 * mecánico aceptó a tiempo.
 */
final class MaintenanceFlowTest extends E2ETestCase
{
    private const EMAIL = 'mantenimiento.e2e@parce.test';
    private const PASSWORD = 'Faro-Norte#2026';

    /** Cliente HTTP del cron: sin sesión, con el token del servidor de pruebas */
    private static function cron(string $token = E2E_MAINTENANCE_TOKEN): ApiClient
    {
        return self::client('customer')->withHeader('X-Maintenance-Token', $token);
    }

    /**
     * Simula que pasaron $minutes minutos desde que se pidió la solicitud. Es
     * lo único que esta prueba hace por fuera de la API: la base es la de
     * pruebas (parce_e2e).
     */
    private static function backdateRequest(int $requestId, int $minutes): void
    {
        Database::query(
            'UPDATE service_requests SET requested_at = (NOW() - INTERVAL ? MINUTE) WHERE id = ?',
            [$minutes, $requestId]
        );
    }

    #[TestDox('Sin el token correcto el mantenimiento no se ejecuta')]
    public function testMaintenanceRequiresTheToken(): void
    {
        self::assertStatus(401, self::client('customer')->post('/maintenance/run'), 'Ejecutar el mantenimiento sin token');
        self::assertStatus(401, self::cron(str_repeat('0', 64))->post('/maintenance/run'), 'Ejecutar el mantenimiento con otro token');
    }

    #[TestDox('Una solicitud que nadie aceptó a tiempo expira y el cliente puede pedir otra')]
    public function testARequestNobodyAcceptedExpires(): void
    {
        $customer = self::client('customer');
        self::assertStatus(201, $customer->post('/auth/register', [
            'email'                 => self::EMAIL,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'first_name'            => 'Lucía',
            'last_name'             => 'Espera',
            'phone'                 => '3145550177',
            'id_number'             => '1066000555',
            'vehicle_plate'         => 'MNT456',
            'vehicle_brand'         => 'Kia',
            'vehicle_model'         => 'Picanto',
            'vehicle_year'          => '2021',
            'vehicle_color'         => 'Blanco',
            'accept_terms'          => true,
        ]), 'Registrarse');

        $vehicleId = (self::assertStatus(200, $customer->get('/vehicles'), 'Consultar sus vehículos')['vehicles'][0]['id'] ?? null);
        $askForHelp = fn(string $step) => self::assertStatus(201, $customer->post('/service-requests', [
            'vehicle_id'     => $vehicleId,
            'emergency_type' => 'battery',
            'description'    => 'El carro no enciende',
            'latitude'       => 4.6486,
            'longitude'      => -74.0628,
        ]), $step)['serviceRequest'] ?? [];
        $status = fn(array $request, string $step) => self::assertStatus(200, $customer->get("/service-requests/{$request['id']}"), $step)['serviceRequest']['status'] ?? null;

        $first = $askForHelp('Pedir asistencia');
        self::backdateRequest((int) $first['id'], MaintenanceService::REQUEST_TIMEOUT_MINUTES + 1);

        $summary = self::assertStatus(200, self::cron()->post('/maintenance/run'), 'Ejecutar el mantenimiento');
        $this->assertGreaterThanOrEqual(1, $summary['expiredRequests'] ?? 0, 'El resumen cuenta la solicitud expirada');
        $this->assertSame('expired', $status($first, 'Ver la solicitud después del mantenimiento'));

        // Ya no tiene un servicio en curso: puede pedir otro, y ese no expira antes de tiempo
        $second = $askForHelp('Pedir asistencia otra vez');
        self::assertStatus(200, self::cron()->post('/maintenance/run'), 'Ejecutar el mantenimiento otra vez');
        $this->assertSame('pending', $status($second, 'Ver la solicitud nueva'));

        self::assertStatus(200, $customer->post("/service-requests/{$second['id']}/cancel", [
            'cancellation_reason' => 'Prueba terminada',
        ]), 'Cancelar la solicitud nueva');
    }
}
