<?php

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\TestDox;

/**
 * Autorización de tratamiento de datos personales (Ley 1581 de 2012): se pide
 * al registrarse, queda guardada como prueba y quien ya tenía cuenta debe
 * aceptar la versión vigente antes de seguir.
 */
final class ConsentFlowTest extends E2ETestCase
{
    /** Datos mínimos de un registro de cliente, con cambios opcionales */
    private static function registration(array $changes = []): array
    {
        return array_merge([
            'email'                 => 'datos.e2e@parce.test',
            'password'              => 'Tr3n-Azul#Lluvia',
            'password_confirmation' => 'Tr3n-Azul#Lluvia',
            'first_name'            => 'Elena',
            'last_name'             => 'Datos',
        ], $changes);
    }

    #[TestDox('Sin aceptar los Términos y la Política de tratamiento de datos no se crea la cuenta')]
    public function testRegistrationRequiresTheConsent(): void
    {
        $response = self::client('customer')->post('/auth/register', self::registration(['email' => 'sin.autorizacion.e2e@parce.test']));

        self::assertStatus(400, $response, 'Registrarse sin aceptar');
        $this->assertArrayHasKey('acceptTerms', $response->body['fields'] ?? []);
    }

    #[TestDox('Al registrarse queda guardada la aceptación de la versión vigente')]
    public function testRegistrationStoresTheConsent(): void
    {
        $user = self::assertStatus(201, self::client('customer')->post('/auth/register', self::registration([
            'accept_terms' => true,
        ])), 'Registrarse aceptando')['user'] ?? [];

        $this->assertTrue($user['consent']['upToDate'] ?? false);
        $this->assertSame($user['consent']['currentVersion'] ?? null, $user['consent']['version'] ?? 'sin versión');
        $this->assertNotEmpty($user['consent']['acceptedAt'] ?? null, 'Debe quedar la fecha como prueba');
    }

    #[TestDox('Quien ya tenía cuenta acepta la política vigente y su primera aceptación se conserva')]
    public function testExistingUsersAcceptTheCurrentPolicy(): void
    {
        $customer = self::loggedIn('customer', 'cliente@parce.test');

        $before = self::assertStatus(200, $customer->get('/auth/me'), 'Perfil antes de aceptar');
        $this->assertFalse($before['consent']['upToDate'] ?? true, 'Las cuentas demo existen desde antes de la política');

        $after = self::assertStatus(200, $customer->post('/auth/consent'), 'Aceptar la política vigente');
        $this->assertTrue($after['consent']['upToDate'] ?? false);

        // Aceptar otra vez no cambia la fecha: la primera aceptación es la prueba
        $again = self::assertStatus(200, $customer->post('/auth/consent'), 'Aceptar otra vez');
        $this->assertSame($after['consent']['acceptedAt'] ?? null, $again['consent']['acceptedAt'] ?? 'sin fecha');
    }

    #[TestDox('Aceptar la política exige haber iniciado sesión')]
    public function testAcceptingRequiresASession(): void
    {
        self::assertStatus(401, self::client('customer')->post('/auth/consent'), 'Aceptar sin sesión');
    }
}
