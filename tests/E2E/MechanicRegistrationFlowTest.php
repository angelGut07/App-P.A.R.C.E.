<?php

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\TestDox;

/**
 * Registro de un mecánico desde el formulario público hasta su aprobación.
 *
 * Para aprobar a un mecánico (y para que acepte servicios) su licencia de
 * conducción debe tener número y fecha de vencimiento vigente. El registro
 * pedía el número pero no la fecha, así que ninguna solicitud creada desde el
 * formulario se podía aprobar (reporte "bug_aceptar-mecanic", 2026-11-11).
 */
final class MechanicRegistrationFlowTest extends E2ETestCase
{
    private const EMAIL = 'mecanico.e2e@parce.test';
    /** Cumple la política de contraseñas */
    private const PASSWORD = 'Rueda-Verde#2031';

    /** Datos del formulario de registro de un mecánico, con cambios opcionales */
    private static function registration(array $changes = []): array
    {
        return array_merge([
            'email'                          => self::EMAIL,
            'password'                       => self::PASSWORD,
            'password_confirmation'          => self::PASSWORD,
            'first_name'                     => 'Mateo',
            'last_name'                      => 'Taller',
            'phone'                          => '3125550188',
            'id_number'                      => '1088000222',
            'role'                           => 'mechanic',
            'driver_license_number'          => 'LICMEC2E01',
            'driver_license_expiration_date' => date('Y-m-d', strtotime('+3 years')),
            'certification_title'            => 'Técnico en Electricidad Automotriz',
            'services'                       => ['battery', 'engine'],
            'vehicle_plate'                  => 'MEC321',
            'vehicle_brand'                  => 'Renault',
            'vehicle_model'                  => 'Kangoo',
            'vehicle_year'                   => '2019',
            'vehicle_color'                  => 'Blanco',
            'soat_number'                    => 'SOATMEC01',
            'tecnomecanica_number'           => 'TMMEC01',
            'accept_terms'                   => true,
        ], $changes);
    }

    #[TestDox('El registro de mecánico exige la fecha de vencimiento de la licencia, y vigente')]
    public function testMechanicRegistrationRequiresAValidLicenseExpirationDate(): void
    {
        // Identidades propias: si el registro se aceptara, no deben chocar con la prueba siguiente
        $withoutDate = self::registration(['email' => 'sin.vencimiento.e2e@parce.test', 'id_number' => '1088000333', 'vehicle_plate' => 'MEC322']);
        unset($withoutDate['driver_license_expiration_date']);

        $missing = self::client('customer')->post('/auth/register', $withoutDate);
        self::assertStatus(400, $missing, 'Registrarse como mecánico sin fecha de vencimiento');
        $this->assertArrayHasKey('driverLicenseExpirationDate', $missing->body['fields'] ?? []);

        $expired = self::client('customer')->post('/auth/register', self::registration([
            'email'                          => 'licencia.vencida.e2e@parce.test',
            'id_number'                      => '1088000444',
            'vehicle_plate'                  => 'MEC323',
            'driver_license_expiration_date' => date('Y-m-d', strtotime('-1 day')),
        ]));
        self::assertStatus(400, $expired, 'Registrarse como mecánico con la licencia vencida');
        $this->assertArrayHasKey('driverLicenseExpirationDate', $expired->body['fields'] ?? []);
    }

    #[TestDox('Un mecánico registrado desde el formulario queda listo para que el administrador lo apruebe')]
    public function testAMechanicRegisteredFromTheFormCanBeApproved(): void
    {
        $expiration = date('Y-m-d', strtotime('+3 years'));
        $mechanic = self::client('customer');

        $user = self::assertStatus(201, $mechanic->post('/auth/register', self::registration([
            'driver_license_expiration_date' => $expiration,
        ])), 'Registrarse como mecánico')['user'] ?? [];
        $this->assertSame($expiration, $user['driverLicense']['expirationDate'] ?? null, 'El registro debe guardar el vencimiento de la licencia');
        $this->assertSame('pending', $user['mechanicApplication']['status'] ?? null);

        // Sin "approved_services" se aprueban todos los servicios pedidos
        $admin = self::loggedIn('administrator', 'admin@parce.test');
        self::assertStatus(200, $admin->post("/admin/mechanic-applications/{$user['mechanicApplication']['id']}/approve"), 'El administrador aprueba la solicitud');

        $me = self::assertStatus(200, $mechanic->get('/auth/me'), 'El mecánico consulta su perfil');
        $this->assertContains('mechanic', $me['roles'] ?? []);
        $services = array_column($me['mechanicProfile']['services'] ?? [], 'code');
        sort($services);
        $this->assertSame(['battery', 'engine'], $services);
    }
}
