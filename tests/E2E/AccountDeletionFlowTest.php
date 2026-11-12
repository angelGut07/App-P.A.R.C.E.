<?php

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Eliminación de la cuenta desde el perfil (derecho de supresión, Ley 1581):
 * se confirma con la contraseña, no procede con un servicio en curso y al
 * terminar la sesión se cierra y el correo queda libre.
 */
final class AccountDeletionFlowTest extends E2ETestCase
{
    private const EMAIL = 'eliminar.e2e@parce.test';
    private const PASSWORD = 'Tr3n-Azul#Lluvia';

    #[TestDox('Para eliminar la cuenta hay que confirmar con la contraseña')]
    public function testDeletionRequiresThePassword(): array
    {
        $user = self::client('customer');
        self::assertStatus(201, $user->post('/auth/register', [
            'email'                 => self::EMAIL,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'first_name'            => 'Tomás',
            'last_name'             => 'Borrado',
            'phone'                 => '3145550199',
            'id_number'             => '1066000777',
            'vehicle_plate'         => 'DEL123',
            'vehicle_brand'         => 'Chevrolet',
            'vehicle_model'         => 'Onix',
            'vehicle_year'          => '2022',
            'vehicle_color'         => 'Negro',
            'accept_terms'          => true,
        ]), 'Registrarse');

        $wrong = $user->delete('/auth/account', ['password' => 'Otra-Clave#123']);
        self::assertStatus(400, $wrong, 'Eliminar con una contraseña equivocada');
        $this->assertArrayHasKey('password', $wrong->body['fields'] ?? []);

        return ['user' => $user];
    }

    #[Depends('testDeletionRequiresThePassword')]
    #[TestDox('Con un servicio en curso no se puede eliminar; al cancelarlo, sí')]
    public function testAServiceInCourseBlocksTheDeletion(array $flow): array
    {
        $vehicles = self::assertStatus(200, $flow['user']->get('/vehicles'), 'Consultar sus vehículos')['vehicles'] ?? [];
        $request = self::assertStatus(201, $flow['user']->post('/service-requests', [
            'vehicle_id'     => $vehicles[0]['id'] ?? null,
            'emergency_type' => 'tire',
            'description'    => 'Llanta pinchada',
            'latitude'       => 4.6097,
            'longitude'      => -74.0817,
        ]), 'Pedir asistencia')['serviceRequest'] ?? [];

        $blocked = $flow['user']->delete('/auth/account', ['password' => self::PASSWORD]);
        self::assertStatus(409, $blocked, 'Eliminar la cuenta con el servicio en curso');
        $this->assertStringContainsString((string)($request['serviceCode'] ?? ''), $blocked->error());

        self::assertStatus(200, $flow['user']->post("/service-requests/{$request['id']}/cancel", [
            'cancellation_reason' => 'Ya lo resolví',
        ]), 'Cancelar el servicio');

        return $flow;
    }

    #[Depends('testAServiceInCourseBlocksTheDeletion')]
    #[TestDox('Al eliminarla se cierra la sesión, no se puede volver a entrar y el correo queda libre')]
    public function testDeletingClosesTheSessionAndFreesTheEmail(array $flow): void
    {
        $deleted = self::assertStatus(200, $flow['user']->delete('/auth/account', ['password' => self::PASSWORD]), 'Eliminar la cuenta');
        // La suite no envía correos: la app no debe decir que mandó la confirmación
        $this->assertSame(false, $deleted['emailSent'] ?? null);

        self::assertStatus(401, $flow['user']->get('/auth/me'), 'Usar la sesión que tenía');
        self::assertStatus(401, self::client('customer')->post('/auth/login', [
            'email'    => self::EMAIL,
            'password' => self::PASSWORD,
        ]), 'Volver a iniciar sesión con la cuenta eliminada');

        // El correo y la cédula se anonimizaron: se puede crear una cuenta nueva con ellos
        self::assertStatus(201, self::client('customer')->post('/auth/register', [
            'email'                 => self::EMAIL,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'first_name'            => 'Tomás',
            'last_name'             => 'Nuevo',
            'id_number'             => '1066000777',
            'accept_terms'          => true,
        ]), 'Registrarse otra vez con el mismo correo');
    }
}
