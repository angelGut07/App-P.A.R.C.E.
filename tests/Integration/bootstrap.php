<?php

/**
 * Bootstrap de la suite de integración.
 *
 * Conecta EXCLUSIVAMENTE a una base de datos de pruebas aislada
 * ("parce_test"), nunca a la base de datos real de desarrollo/producción
 * ("parce"). Las credenciales son las mismas por defecto de XAMPP en local
 * (root, sin contraseña) ya documentadas en .env.example — no son secretas.
 *
 * Esta suite es OPT-IN: no está registrada en phpunit.xml (la que usa
 * `composer test` / CI), solo en phpunit.integration.xml. No se ejecuta
 * nunca por accidente junto a la suite Unit.
 *
 * Requiere la base de datos "parce_test" migrada; ver la sección
 * "Pruebas" del README.
 */

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/vendor/autoload.php';

use App\Core\Database;

const INTEGRATION_TEST_DATABASE = 'parce_test';

Database::setConfig([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => INTEGRATION_TEST_DATABASE,
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
]);

// Salvaguarda: si alguna vez esta constante se cambia por error a "parce",
// abortar inmediatamente en vez de arriesgarse a truncar datos reales.
if (INTEGRATION_TEST_DATABASE === 'parce') {
    fwrite(STDERR, "REFUSING TO RUN: la suite de integración no puede apuntar a la base de datos 'parce'.\n");
    exit(1);
}
