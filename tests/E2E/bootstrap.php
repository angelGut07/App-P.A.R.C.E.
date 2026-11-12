<?php

/**
 * Bootstrap de la suite de punta a punta (E2E).
 *
 * La suite prueba la API como la usa el frontend: peticiones HTTP reales a un
 * servidor `php -S`, con sesiones, middleware, controladores y MySQL. Para no
 * tocar datos reales:
 *
 *   1. Recrea desde cero la base "parce_e2e" (migraciones + datos demo). El
 *      nombre es fijo a propósito: la suite nunca puede apuntar a otra base.
 *   2. Levanta su propio servidor en un puerto libre, con esa base y sin
 *      correo, CAPTCHA ni asistente, y lo apaga al terminar.
 *
 * Requiere MySQL/MariaDB encendido. En local usa el de XAMPP (root sin
 * contraseña); con otras credenciales se definen DB_HOST, DB_PORT,
 * DB_USERNAME y DB_PASSWORD como variables de entorno (así lo hace el CI).
 *
 * Todo lo que registre el servidor de pruebas (errores con su traza, consultas
 * fallidas, cada petición) queda en storage/logs/e2e-server.log.
 */

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\MigrationRunner;

const E2E_DATABASE = 'parce_e2e';
const E2E_SERVER_LOG = BASE_PATH . '/storage/logs/e2e-server.log';

// Las pruebas arman fechas ("hoy" en un ingreso de mercancía) en la misma zona
// horaria de la app. Sin esto, en el CI (UTC) "hoy" ya puede ser "mañana" para
// la API, que lo rechaza como fecha futura.
const E2E_TIMEZONE = 'America/Bogota';
date_default_timezone_set(E2E_TIMEZONE);

/** Detiene la suite con un mensaje claro (antes de correr cualquier prueba). */
function e2e_abort(string $message): never
{
    fwrite(STDERR, PHP_EOL . "E2E: {$message}" . PHP_EOL);
    exit(1);
}

$db = [
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => (int)(getenv('DB_PORT') ?: 3306),
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];

// 1. Base de datos limpia: migraciones y datos demo, igual que un despliegue nuevo
try {
    $server = new PDO(
        "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4",
        $db['username'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec('DROP DATABASE IF EXISTS `' . E2E_DATABASE . '`');
    $server->exec('CREATE DATABASE `' . E2E_DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server = null;
} catch (PDOException $e) {
    e2e_abort('no se pudo crear la base ' . E2E_DATABASE . " ({$e->getMessage()}). ¿Está encendido MySQL?");
}

Database::setConfig($db + ['driver' => 'mysql', 'database' => E2E_DATABASE, 'charset' => 'utf8mb4']);
ob_start();
try {
    (new MigrationRunner(BASE_PATH . '/database/migrations'))->migrate();
    require_once BASE_PATH . '/database/seeders/DatabaseSeeder.php';
    (new DatabaseSeeder())->run();
} catch (Throwable $e) {
    ob_end_clean();
    e2e_abort('falló la migración o los datos demo: ' . $e->getMessage());
}
ob_end_clean();
Database::disconnect();

// Los contadores del límite de intentos por IP son locales (no van a la base):
// se reinician para que correr la suite varias veces seguidas no termine en 429.
@unlink(BASE_PATH . '/storage/rate_limit.json');

// 2. Servidor de pruebas en un puerto libre
$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($probe === false) {
    e2e_abort("no hay un puerto libre para el servidor de pruebas ({$errorMessage})");
}
$port = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$uploads = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'parce-e2e-uploads';
if (!is_dir($uploads)) {
    mkdir($uploads, 0777, true);
}

$settings = [
    'APP_ENV'              => 'local',
    'APP_DEBUG'            => 'false',
    'APP_URL'              => "http://127.0.0.1:{$port}",
    'APP_TIMEZONE'         => E2E_TIMEZONE,
    'LOG_CHANNEL'          => 'stderr',
    'DB_CONNECTION'        => 'mysql',
    'DB_HOST'              => $db['host'],
    'DB_PORT'              => (string)$db['port'],
    'DB_DATABASE'          => E2E_DATABASE,
    'DB_USERNAME'          => $db['username'],
    'DB_PASSWORD'          => $db['password'],
    'DB_SSL_CA'            => '',
    'UPLOADS_PATH'         => $uploads,
    // Apagados: la suite nunca envía correos ni llama servicios externos
    'RESEND_API_KEY'       => '',
    'TURNSTILE_SITE_KEY'   => '',
    'TURNSTILE_SECRET_KEY' => '',
    'GROQ_API_KEY'         => '',
];

// El servidor lee un archivo de entorno propio (ENV_FILE) en vez del .env
// local: en Windows una variable vacía no llega al proceso hijo, y sin esto
// heredaría claves reales del .env (correo, CAPTCHA).
$envFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "parce-e2e-{$port}.env";
file_put_contents($envFile, "# Generado por tests/E2E/bootstrap.php; se borra al terminar\n" . implode("\n", array_map(
    fn(string $key, string $value) => "{$key}=\"{$value}\"",
    array_keys($settings),
    $settings
)) . "\n");

// Las variables del proceso ganan sobre el archivo: se quitan las heredadas
// de la terminal y se pasan solo las que tienen valor.
$environment = array_diff_key(getenv(), $settings, ['ENV_FILE' => true]);
$environment += array_filter($settings, fn(string $value) => $value !== '') + ['ENV_FILE' => $envFile];

if (!is_dir(dirname(E2E_SERVER_LOG))) {
    mkdir(dirname(E2E_SERVER_LOG), 0755, true);
}
file_put_contents(E2E_SERVER_LOG, '');

$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', BASE_PATH . '/public', BASE_PATH . '/public/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', E2E_SERVER_LOG, 'a'], 2 => ['file', E2E_SERVER_LOG, 'a']],
    $pipes,
    BASE_PATH,
    $environment
);
if (!is_resource($process)) {
    e2e_abort('no se pudo iniciar el servidor de pruebas');
}

register_shutdown_function(function () use ($process, $envFile): void {
    proc_terminate($process);
    proc_close($process);
    @unlink($envFile);
});

// Espera a que el servidor responda (normalmente tarda menos de un segundo)
$ready = false;
for ($attempt = 0; $attempt < 60 && !$ready; $attempt++) {
    if (!proc_get_status($process)['running']) {
        break;
    }
    $curl = curl_init("http://127.0.0.1:{$port}/api/health");
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $ready = curl_exec($curl) !== false && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200;
    if (!$ready) {
        usleep(250_000);
    }
}
if (!$ready) {
    e2e_abort("el servidor de pruebas no respondió. Últimas líneas de su log:\n"
        . implode("\n", array_slice(file(E2E_SERVER_LOG, FILE_IGNORE_NEW_LINES) ?: [], -15)));
}

define('E2E_BASE_URL', "http://127.0.0.1:{$port}/api");
