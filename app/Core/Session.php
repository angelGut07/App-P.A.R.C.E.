<?php

namespace App\Core;

/**
 * Clase de gestión de sesiones
 *
 * Administra las sesiones PHP aplicando buenas prácticas de seguridad.
 * Soporta mensajes flash y regeneración de ID de sesión.
 */
class Session
{
    private static bool $started = false;

    /**
     * Inicia la sesión con configuración segura
     */
    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        // Configuración segura de la cookie de sesión
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');

        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');
        }

        session_start();
        self::$started = true;
    }

    /**
     * Almacena un valor en la sesión bajo la clave indicada
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Obtiene el valor asociado a una clave de la sesión
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Indica si una clave existe en la sesión activa
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Elimina una clave de la sesión
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * Limpia todos los datos de la sesión activa
     */
    public static function clear(): void
    {
        self::start();
        $_SESSION = [];
    }

    /**
     * Destruye completamente la sesión activa y su cookie
     */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        self::$started = false;
    }


    /**
     * Regenera el ID de sesión como medida de seguridad (previene fijación de sesión)
     */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    /**
     * Almacena un mensaje flash disponible únicamente durante la siguiente petición
     */
    public static function flash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Obtiene y elimina un mensaje flash de la sesión
     */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        self::start();
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * Indica si existe un mensaje flash para la clave indicada
     */
    public static function hasFlash(string $key): bool
    {
        self::start();
        return isset($_SESSION['_flash'][$key]);
    }

    /**
     * Retorna todos los datos almacenados en la sesión activa
     */
    public static function all(): array
    {
        self::start();
        return $_SESSION;
    }

    /**
     * Retorna el identificador único de la sesión activa
     */
    public static function id(): string
    {
        self::start();
        return session_id();
    }
}
