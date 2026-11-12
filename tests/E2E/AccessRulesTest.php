<?php

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\TestDox;

/**
 * Reglas de acceso que valen para toda la API: sesión obligatoria, roles y
 * respuestas 404 bien formadas.
 */
final class AccessRulesTest extends E2ETestCase
{
    #[TestDox('Sin sesión, la API responde 401')]
    public function testWithoutASessionTheApiAnswers401(): void
    {
        self::assertStatus(401, self::client('customer')->get('/service-requests'), 'Listar solicitudes sin sesión');
    }

    #[TestDox('Un id que no es un número responde 404, no un error 500')]
    public function testANonNumericIdAnswers404(): void
    {
        $customer = self::loggedIn('customer', 'cliente@parce.test');

        foreach (['abc', '12abc', '-1'] as $id) {
            $response = $customer->get("/service-requests/{$id}");
            self::assertStatus(404, $response, "Consultar /service-requests/{$id}");
            $this->assertSame('Recurso no encontrado', $response->error());
        }
    }

    #[TestDox('Un cliente no puede usar las rutas del administrador')]
    public function testACustomerCannotUseAdminRoutes(): void
    {
        $customer = self::loggedIn('customer', 'cliente@parce.test');

        self::assertStatus(403, $customer->get('/admin/pqr'), 'Un cliente pide la lista de PQR del administrador');
    }

    #[TestDox('Una ruta que no existe responde 404 con el formato de error de la API')]
    public function testAnUnknownRouteAnswers404(): void
    {
        $response = self::client('customer')->get('/no-existe');

        self::assertStatus(404, $response, 'Pedir una ruta que no existe');
        $this->assertSame(['success' => false, 'error' => 'Ruta no encontrada'], $response->body);
    }
}
