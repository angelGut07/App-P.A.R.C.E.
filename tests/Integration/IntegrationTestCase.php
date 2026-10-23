<?php

namespace Tests\Integration;

use App\Core\Database;
use App\Core\DomainException;
use PHPUnit\Framework\TestCase;

/**
 * Clase base para tests de integración contra la base de datos de pruebas
 * aislada "parce_test" (nunca "parce"). Provee helpers para crear datos de
 * prueba reales y limpia las tablas mutables antes de cada test para que los
 * tests sean independientes entre sí.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static int $mechanicRoleId;
    protected static int $administratorRoleId;
    protected static int $customerRoleId;

    public static function setUpBeforeClass(): void
    {
        self::$mechanicRoleId = (int)Database::fetchOne(
            "SELECT id FROM roles WHERE slug = 'mechanic'"
        )['id'];
        self::$administratorRoleId = (int)Database::fetchOne(
            "SELECT id FROM roles WHERE slug = 'administrator'"
        )['id'];
        self::$customerRoleId = (int)Database::fetchOne(
            "SELECT id FROM roles WHERE slug = 'customer'"
        )['id'];
    }

    /**
     * Limpia las tablas mutables antes de cada test (no toca `roles`, que es
     * catálogo sembrado una sola vez por la migración inicial).
     */
    protected function setUp(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['mechanic_applications', 'user_roles', 'sessions', 'password_reset_tokens', 'users'] as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Crea un usuario real en la base de datos de pruebas.
     */
    protected function createUser(array $overrides = []): int
    {
        $data = array_merge([
            'email'          => 'user_' . bin2hex(random_bytes(6)) . '@test.local',
            'password_hash'  => password_hash('Test12345!', PASSWORD_ARGON2ID),
            'first_name'     => 'Test',
            'last_name'      => 'User',
            'account_status' => 'active',
        ], $overrides);

        return Database::insert('users', $data);
    }

    /**
     * Asigna un rol directamente (para preparar fixtures, ej. un
     * administrador o un mecánico ya existente) — bypass deliberado del
     * flujo bajo prueba, usado SOLO para dejar el estado inicial listo.
     */
    protected function assignRole(int $userId, string $roleSlug): void
    {
        $role = Database::fetchOne('SELECT id FROM roles WHERE slug = ?', [$roleSlug]);
        Database::insert('user_roles', [
            'user_id'     => $userId,
            'role_id'     => $role['id'],
            'assigned_at' => date('Y-m-d H:i:s'),
            'is_active'   => true,
        ]);
    }

    /**
     * Fija (o borra) los campos de licencia de conducción de un usuario.
     */
    protected function setLicense(int $userId, ?string $expirationOffset = '+1 year', bool $complete = true): void
    {
        if (!$complete) {
            Database::update('users', [
                'driver_license_number'          => null,
                'driver_license_expiration_date' => null,
                'driver_license_document_url'    => null,
            ], 'id = ?', [$userId]);
            return;
        }

        Database::update('users', [
            'driver_license_number'          => 'LIC-' . random_int(100000, 999999),
            'driver_license_expiration_date' => date('Y-m-d', strtotime($expirationOffset)),
            'driver_license_document_url'    => 'https://example.com/license.jpg',
        ], 'id = ?', [$userId]);
    }

    protected function hasRole(int $userId, string $roleSlug): bool
    {
        return Database::fetchOne(
            'SELECT ur.id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.slug = ? AND ur.is_active = TRUE',
            [$userId, $roleSlug]
        ) !== null;
    }

    protected function countRoleAssignments(int $userId, string $roleSlug): int
    {
        return (int)Database::fetchOne(
            'SELECT COUNT(*) AS c FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.slug = ?',
            [$userId, $roleSlug]
        )['c'];
    }

    protected function createPendingApplication(int $userId, string $justification = 'Justificacion de prueba con longitud suficiente.'): int
    {
        return Database::insert('mechanic_applications', [
            'user_id'             => $userId,
            'certification_title' => 'Técnico automotriz',
            'justification'       => $justification,
            'status'              => 'pending',
        ]);
    }

    /**
     * Ejecuta $fn esperando que lance DomainException con exactamente
     * $expectedStatusCode. No se usa PHPUnit::expectExceptionCode() porque
     * verifica Exception::getCode() (siempre 0 en este proyecto — nadie pasa
     * el tercer argumento del constructor), no el getStatusCode() propio de
     * DomainException que el resto del código realmente usa para decidir el
     * código HTTP de respuesta.
     */
    protected function assertDomainException(int $expectedStatusCode, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Se esperaba una DomainException con statusCode ' . $expectedStatusCode . ', no se lanzó ninguna excepción.');
        } catch (DomainException $e) {
            $this->assertSame($expectedStatusCode, $e->getStatusCode(), 'Mensaje de la excepción: ' . $e->getMessage());
        }
    }
}
