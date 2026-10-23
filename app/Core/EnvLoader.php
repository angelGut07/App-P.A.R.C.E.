<?php

namespace App\Core;

/**
 * Cargador de variables de entorno
 *
 * Las variables reales del proceso (Docker, Render) tienen prioridad y se
 * copian a $_ENV; el archivo .env, si existe, solo completa las que falten.
 * Así el mismo código funciona en local (con .env) y en un servidor (sin .env).
 */
class EnvLoader
{
    /**
     * @param string $path Ruta del archivo .env
     * @return bool true si el archivo existía y se leyó
     */
    public static function load(string $path): bool
    {
        $processEnv = getenv();
        foreach ($processEnv as $key => $value) {
            $_ENV[$key] = $value;
        }

        $loaded = self::loadFile($path, $processEnv);

        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Bogota');

        return $loaded;
    }

    private static function loadFile(string $path, array $processEnv): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if (array_key_exists($key, $processEnv)) {
                continue;
            }

            $value = self::parseValue($value);
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        return true;
    }

    /**
     * Valor de una línea del .env: entre comillas se toma literal; sin
     * comillas se descarta un comentario final (" # ...").
     */
    private static function parseValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $end = strpos($raw, $raw[0], 1);
            return $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
        }
        return trim((string)preg_replace('/\s+#.*$/', '', $raw));
    }

    /**
     * Indica si la configuración mínima ya viene del entorno del proceso
     * (despliegue sin archivo .env).
     */
    public static function hasProcessConfig(): bool
    {
        return getenv('DB_HOST') !== false;
    }
}
