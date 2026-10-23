<?php

/**
 * Worker de un solo intento de aprobación, para lanzar como PROCESO
 * independiente (no hilo, no mock) contra la base de datos de pruebas
 * aislada "parce_test". Se usa para demostrar de forma determinista que dos
 * procesos concurrentes intentando aprobar la MISMA solicitud no pueden
 * producir dos asignaciones del rol ni dos aprobaciones exitosas.
 *
 * Uso: php concurrent_approve_probe.php <applicationId> <adminUserId>
 * Imprime en stdout exactamente una línea: "APPROVED" o "REJECTED:<code>:<mensaje>"
 */

define('BASE_PATH', dirname(__DIR__, 3));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\DomainException;
use App\Models\MechanicApplication\MechanicApplicationService;

Database::setConfig([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'parce_test',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
]);

$applicationId = (int)($argv[1] ?? 0);
$adminUserId   = (int)($argv[2] ?? 0);

$service = new MechanicApplicationService();

try {
    $service->approve($applicationId, $adminUserId);
    echo "APPROVED\n";
} catch (DomainException $e) {
    echo "REJECTED:{$e->getStatusCode()}:{$e->getMessage()}\n";
} catch (\Throwable $e) {
    echo "ERROR:" . get_class($e) . ":{$e->getMessage()}\n";
}
