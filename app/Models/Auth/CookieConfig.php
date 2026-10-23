<?php

namespace App\Models\Auth;

use InvalidArgumentException;

/**
 * DTO de Configuración de Cookie
 *
 * Objeto de transferencia de datos inmutable que define los parámetros seguros de las cookies para la gestión de sesiones.
 * Aplica las mejores prácticas de seguridad incluyendo los indicadores HttpOnly, Secure y SameSite.
 *
 * Requisitos: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6, 25.3
 */
readonly class CookieConfig
{
    /**
     * Crea una nueva configuración de cookie
     *
     * @param string $name Nombre de la cookie
     * @param int $lifetime Tiempo de vida de la cookie en segundos
     * @param string $path Ruta de la cookie
     * @param string $domain Dominio de la cookie
     * @param bool $secure Indicador Secure (solo HTTPS)
     * @param bool $httpOnly Indicador HttpOnly (impide el acceso desde JavaScript)
     * @param string $sameSite Política SameSite ('Strict', 'Lax' o 'None')
     *
     * @throws InvalidArgumentException Si la validación falla
     */
    public function __construct(
        public string $name,
        public int $lifetime,
        public string $path,
        public string $domain,
        public bool $secure,
        public bool $httpOnly,
        public string $sameSite
    ) {
        // Requisito 12.5: La ruta debe comenzar con '/'
        if (!str_starts_with($this->path, '/')) {
            throw new InvalidArgumentException(
                "Cookie path must start with '/'"
            );
        }

        // Requisito 12.3: SameSite debe ser 'Strict', 'Lax' o 'None'
        if (!in_array($this->sameSite, ['Strict', 'Lax', 'None'], true)) {
            throw new InvalidArgumentException(
                "SameSite must be 'Strict', 'Lax', or 'None'"
            );
        }

        // Validar que el tiempo de vida sea positivo
        if ($this->lifetime <= 0) {
            throw new InvalidArgumentException(
                'Cookie lifetime must be a positive integer'
            );
        }

        // Validar que el nombre no esté vacío
        if (empty($this->name)) {
            throw new InvalidArgumentException(
                'Cookie name cannot be empty'
            );
        }
    }

    /**
     * Crea una configuración de cookie segura con los valores predeterminados recomendados
     *
     * Requisitos: 12.1, 12.2, 12.3, 12.4, 12.6
     *
     * @return self
     */
    public static function secure(): self
    {
        return new self(
            name: 'parce_session',
            lifetime: 7200, // 2 horas (Requisito 12.4)
            path: '/',
            domain: '',
            secure: true, // Requisito 12.2
            httpOnly: true, // Requisito 12.1
            sameSite: 'Lax' // Requisito 12.3
        );
    }

    /**
     * Crea la configuración de cookie desde las variables de entorno
     *
     * Carga la configuración desde .env con valores predeterminados seguros para producción.
     * Detecta automáticamente HTTPS cuando SESSION_COOKIE_SECURE=auto.
     *
     * @return self
     */
    public static function fromEnv(): self
    {
        // Detectar automáticamente el indicador secure basándose en HTTPS
        $secureConfig = $_ENV['SESSION_COOKIE_SECURE'] ?? 'auto';
        $secure = match (strtolower($secureConfig)) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            'auto' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            default => false
        };

        // Analizar el indicador HttpOnly
        $httpOnlyConfig = $_ENV['SESSION_COOKIE_HTTPONLY'] ?? 'true';
        $httpOnly = in_array(strtolower($httpOnlyConfig), ['true', '1', 'yes'], true);

        return new self(
            name: $_ENV['SESSION_COOKIE_NAME'] ?? 'parce_session',
            lifetime: (int)($_ENV['SESSION_LIFETIME'] ?? 7200),
            path: $_ENV['SESSION_COOKIE_PATH'] ?? '/',
            domain: $_ENV['SESSION_COOKIE_DOMAIN'] ?? '',
            secure: $secure,
            httpOnly: $httpOnly,
            sameSite: $_ENV['SESSION_COOKIE_SAMESITE'] ?? 'Lax'
        );
    }
}
