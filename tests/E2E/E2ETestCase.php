<?php

namespace Tests\E2E;

use PHPUnit\Framework\TestCase;
use Tests\E2E\Support\ApiClient;
use Tests\E2E\Support\ApiResponse;

/**
 * Base de las pruebas E2E: crea clientes HTTP (uno por persona) y verifica
 * cada paso con un mensaje que dice qué se intentaba y qué respondió la API.
 */
abstract class E2ETestCase extends TestCase
{
    /** Contraseña de las cuentas demo del seeder (pública en el README; solo existe en bases de prueba) */
    protected const DEMO_PASSWORD = 'Parce2026*';

    /** Cliente HTTP sin sesión, con la vista activa indicada */
    protected static function client(string $activeRole): ApiClient
    {
        return new ApiClient(E2E_BASE_URL, $activeRole);
    }

    /** Cliente HTTP con la sesión ya iniciada */
    protected static function loggedIn(string $activeRole, string $email, string $password = self::DEMO_PASSWORD): ApiClient
    {
        $client = self::client($activeRole);
        self::assertStatus(200, $client->post('/auth/login', ['email' => $email, 'password' => $password]), "Iniciar sesión como {$email}");

        return $client;
    }

    /**
     * Verifica el código HTTP de un paso y devuelve su "data"
     *
     * @param int|int[] $expected Código (o códigos) aceptados
     * @param string    $step     Qué se estaba haciendo, para el mensaje de error
     */
    protected static function assertStatus(int|array $expected, ApiResponse $response, string $step): array
    {
        $expected = (array)$expected;

        self::assertContains($response->status, $expected, sprintf(
            "%s: se esperaba HTTP %s y llegó %d. Respuesta: %s\nDetalle del servidor en storage/logs/e2e-server.log",
            $step,
            implode(' o ', $expected),
            $response->status,
            $response->error() !== '' ? $response->error() : mb_substr($response->raw, 0, 300)
        ));

        return $response->data();
    }
}
