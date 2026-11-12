<?php

namespace App\Core;

/**
 * Destino único de los logs de la aplicación.
 *
 * En Render el disco del contenedor es temporal y no se puede consultar: un
 * archivo de log se pierde en cada deploy sin que nadie lo haya leído. Por eso
 * en producción todo va a la salida de errores (stderr), que Render muestra en
 * la pestaña "Logs" del servicio. En local se escribe en
 * storage/logs/{categoría}-AAAA-MM-DD.log.
 *
 * El destino se elige con LOG_CHANNEL (stderr | file). Si no está definido se
 * usa stderr cuando APP_ENV=production y archivos en cualquier otro entorno.
 *
 * Nunca registrar contraseñas, tokens, ids de sesión ni cuerpos de peticiones:
 * estos logs los lee cualquiera que administre el servidor.
 */
final class Logger
{
    public const CHANNEL_STDERR = 'stderr';
    public const CHANNEL_FILE = 'file';

    /**
     * Registra un mensaje en el destino activo.
     *
     * @param string $category Categoría (error, database, requests): nombre del
     *                         archivo en local y prefijo de la línea en stderr
     * @param string $message  Mensaje ya formateado; puede ocupar varias líneas
     */
    public static function write(string $category, string $message): void
    {
        $message = rtrim($message);

        if (self::channel() === self::CHANNEL_STDERR) {
            // Sin la directiva error_log en php.ini, error_log() escribe en el
            // log del servidor: con Apache (imagen Docker) es su ErrorLog, que
            // apunta a stderr del contenedor; en consola y en `php -S`, la terminal.
            error_log("[{$category}] {$message}");
            return;
        }

        $file = self::filePath($category);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        // @: no poder escribir un log nunca debe tumbar la petición
        @file_put_contents($file, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Destino activo: LOG_CHANNEL si tiene un valor válido; si no, según APP_ENV.
     */
    public static function channel(): string
    {
        $configured = strtolower(trim((string) self::env('LOG_CHANNEL')));
        if ($configured === self::CHANNEL_STDERR || $configured === self::CHANNEL_FILE) {
            return $configured;
        }

        return strtolower(trim((string) self::env('APP_ENV'))) === 'production'
            ? self::CHANNEL_STDERR
            : self::CHANNEL_FILE;
    }

    /**
     * Archivo del día para una categoría (solo se usa con el destino "file").
     * Un archivo por día permite que el mantenimiento borre los viejos
     * (MaintenanceService::deleteOldLogFiles()).
     */
    public static function filePath(string $category): string
    {
        return self::directory() . '/' . $category . '-' . date('Y-m-d') . '.log';
    }

    /** Carpeta de los archivos de log (storage/logs) */
    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs';
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return $value === false ? null : (string) $value;
    }
}
