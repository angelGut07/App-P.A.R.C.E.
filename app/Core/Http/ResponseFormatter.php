<?php

namespace App\Core\Http;

use App\Core\Response;
use App\Models\Auth\CookieConfig;

/**
 * Formateador de Respuestas
 *
 * Estandariza las respuestas de la API con formato JSON consistente, manejo seguro de cookies
 * y códigos de estado HTTP apropiados. Implementa conversión a camelCase y JSON disperso.
 *
 * Requisitos: 8.1-8.7, 11.1-11.7, 12.1-12.7
 */
class ResponseFormatter
{
    /**
     * Nombre de la cookie de sesión
     */
    private const SESSION_COOKIE_NAME = 'parce_session';

    /**
     * Expiración predeterminada de la cookie (2 horas en segundos)
     */
    private const DEFAULT_COOKIE_EXPIRATION = 7200;

    /**
     * Expiración de la cookie "recordarme" (30 días en segundos)
     */
    private const REMEMBER_COOKIE_EXPIRATION = 2592000;

    /**
     * Crea una respuesta de éxito estandarizada
     *
     * Requisitos: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6, 12.7
     *
     * @param mixed $data Datos de la respuesta (opcional)
     * @param string $message Mensaje de éxito (opcional)
     * @param int $statusCode Código de estado HTTP (predeterminado: 200)
     * @return Response Objeto de respuesta
     */
    public static function success($data = null, string $message = null, int $statusCode = 200): Response
    {
        $response = new Response();

        // Construir el arreglo de respuesta
        $responseData = ['success' => true];

        // Agregar datos si se proporcionaron
        if ($data !== null) {
            $responseData['data'] = self::convertToCamelCase($data);
        }

        // Agregar mensaje si se proporcionó
        if ($message !== null) {
            $responseData['message'] = $message;
        }

        // Eliminar campos nulos (JSON disperso)
        $responseData = self::removeNullFields($responseData);

        // Establecer encabezados
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('X-API-Version', '1.0.0');

        return $response->json($responseData, $statusCode);
    }

    /**
     * Crea una respuesta de error estandarizada
     *
     * Requisitos: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6, 11.7
     *
     * @param string $error Mensaje de error
     * @param array|null $fields Errores específicos por campo (opcional)
     * @param int $statusCode Código de estado HTTP (predeterminado: 400)
     * @return Response Objeto de respuesta
     */
    public static function error(string $error, ?array $fields = null, int $statusCode = 400): Response
    {
        $response = new Response();

        // Construir el arreglo de respuesta
        $responseData = [
            'success' => false,
            'error' => $error
        ];

        // Agregar errores específicos por campo si se proporcionaron
        if ($fields !== null && !empty($fields)) {
            $responseData['fields'] = self::convertToCamelCase($fields);
        }

        // Eliminar campos nulos (JSON disperso)
        $responseData = self::removeNullFields($responseData);

        // Establecer encabezados
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('X-API-Version', '1.0.0');

        return $response->json($responseData, $statusCode);
    }

    /**
     * Establece la cookie de sesión con atributos de seguridad
     *
     * Usa la configuración de cookies adaptada al entorno para un comportamiento seguro en producción.
     * Detecta automáticamente HTTPS y aplica los indicadores de seguridad correspondientes.
     *
     * Requisitos: 8.1, 8.2, 8.3, 8.4, 8.5, 8.7
     *
     * @param Response $response Objeto de respuesta
     * @param string $sessionId ID de sesión
     * @param bool $remember Habilitar "recordarme" (30 días vs 2 horas)
     * @return Response Objeto de respuesta con la cookie establecida
     */
    public static function setSessionCookie(Response $response, string $sessionId, bool $remember = false): Response
    {
        // Cargar la configuración de cookies desde el entorno
        $cookieConfig = CookieConfig::fromEnv();

        // Calcular la expiración según el indicador "recordarme"
        $expiration = $remember
            ? time() + self::REMEMBER_COOKIE_EXPIRATION
            : time() + $cookieConfig->lifetime;

        // Establecer la cookie usando la configuración del entorno
        $response->setCookie(
            name: $cookieConfig->name,
            value: $sessionId,
            expires: $expiration,
            path: $cookieConfig->path,
            domain: $cookieConfig->domain,
            secure: $cookieConfig->secure,
            httpOnly: $cookieConfig->httpOnly
        );

        // Establecer el encabezado SameSite manualmente para mayor compatibilidad
        $cookieString = $cookieConfig->name . '=' . $sessionId .
            '; Path=' . $cookieConfig->path .
            ($cookieConfig->httpOnly ? '; HttpOnly' : '') .
            ($cookieConfig->secure ? '; Secure' : '') .
            '; SameSite=' . $cookieConfig->sameSite .
            '; Max-Age=' . ($remember ? self::REMEMBER_COOKIE_EXPIRATION : $cookieConfig->lifetime);

        $response->setHeader('Set-Cookie', $cookieString);

        return $response;
    }

    /**
     * Determina si estamos en un contexto seguro (HTTPS)
     *
     * @return bool Verdadero si es HTTPS o localhost, falso en caso contrario
     */
    private static function isSecureContext(): bool
    {
        // Verificar si es HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Verificar si es localhost (permitir HTTP en desarrollo)
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false; // Permitir HTTP para localhost
        }

        // Verificar el protocolo reenviado (detrás de un proxy inverso)
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        // Por defecto, usar modo seguro en producción
        return true;
    }

    /**
     * Limpia la cookie de sesión
     *
     * Usa la configuración de cookies adaptada al entorno para limpiar correctamente las cookies.
     *
     * Requisito 8.6: Establecer maxAge en 0 para limpiar la cookie
     *
     * @param Response $response Objeto de respuesta
     * @return Response Objeto de respuesta con la cookie limpiada
     */
    public static function clearSessionCookie(Response $response): Response
    {
        // Cargar la configuración de cookies desde el entorno
        $cookieConfig = CookieConfig::fromEnv();

        $response->setCookie(
            name: $cookieConfig->name,
            value: '',
            expires: time() - 3600, // Expirar en el pasado
            path: $cookieConfig->path,
            domain: $cookieConfig->domain,
            secure: $cookieConfig->secure,
            httpOnly: $cookieConfig->httpOnly
        );

        // Establecer el encabezado SameSite manualmente para limpiar la cookie
        $cookieString = $cookieConfig->name . '=; Path=' . $cookieConfig->path .
            ($cookieConfig->httpOnly ? '; HttpOnly' : '') .
            ($cookieConfig->secure ? '; Secure' : '') .
            '; SameSite=' . $cookieConfig->sameSite .
            '; Max-Age=0';

        $response->setHeader('Set-Cookie', $cookieString);

        return $response;
    }

    /**
     * Convierte las claves de un arreglo a camelCase
     *
     * Requisito 12.6: Usar camelCase para todos los nombres de campos JSON
     *
     * @param mixed $data Datos a convertir
     * @return mixed Datos convertidos
     */
    private static function convertToCamelCase($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $result = [];

        foreach ($data as $key => $value) {
            // Convertir snake_case a camelCase
            $camelKey = lcfirst(str_replace('_', '', ucwords($key, '_')));

            // Convertir recursivamente los arreglos anidados
            if (is_array($value)) {
                $result[$camelKey] = self::convertToCamelCase($value);
            } else {
                $result[$camelKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Elimina los campos nulos de un arreglo (JSON disperso)
     *
     * Requisito 12.7: Omitir los campos nulos de la respuesta
     *
     * @param array $data Arreglo de datos
     * @return array Arreglo con los campos nulos eliminados
     */
    private static function removeNullFields(array $data): array
    {
        return array_filter($data, function ($value) {
            // Mantener false y 0, pero eliminar null
            return $value !== null;
        });
    }

    /**
     * Crea una respuesta de error de validación
     *
     * Requisito 11.4: Incluir los campos con mensajes de error específicos por campo
     *
     * @param array $errors Errores específicos por campo
     * @param int $statusCode Código de estado HTTP (predeterminado: 400)
     * @return Response Objeto de respuesta
     */
    public static function validationError(array $errors, int $statusCode = 400): Response
    {
        return self::error(
            'Revisa los datos del formulario',
            $errors,
            $statusCode
        );
    }

    /**
     * Crea una respuesta de no autorizado
     *
     * Requisito 11.7: Mapear AuthenticationException a HTTP 401
     *
     * @param string $message Mensaje de error (predeterminado: 'No autorizado')
     * @return Response Objeto de respuesta
     */
    public static function unauthorized(string $message = 'No autorizado'): Response
    {
        return self::error($message, null, 401);
    }

    /**
     * Crea una respuesta de acceso prohibido
     *
     * @param string $message Mensaje de error (predeterminado: 'Acceso denegado')
     * @return Response Objeto de respuesta
     */
    public static function forbidden(string $message = 'Acceso denegado'): Response
    {
        return self::error($message, null, 403);
    }

    /**
     * Crea una respuesta de recurso no encontrado
     *
     * @param string $message Mensaje de error (predeterminado: 'Recurso no encontrado')
     * @return Response Objeto de respuesta
     */
    public static function notFound(string $message = 'Recurso no encontrado'): Response
    {
        return self::error($message, null, 404);
    }

    /**
     * Crea una respuesta de conflicto
     *
     * @param string $message Mensaje de error
     * @return Response Objeto de respuesta
     */
    public static function conflict(string $message): Response
    {
        return self::error($message, null, 409);
    }

    /**
     * Crea una respuesta de límite de tasa excedido
     *
     * Requisito 6.3, 6.4: Retornar HTTP 429 con el encabezado Retry-After
     *
     * @param int $retryAfter Segundos hasta que se reinicie el límite de tasa
     * @return Response Objeto de respuesta
     */
    public static function rateLimitExceeded(int $retryAfter): Response
    {
        $response = self::error(
            'Demasiados intentos. Intenta de nuevo más tarde',
            ['retryAfter' => $retryAfter],
            429
        );

        $response->setHeader('Retry-After', (string)$retryAfter);

        return $response;
    }

    /**
     * Crea una respuesta de error interno del servidor
     *
     * Requisito 11.6: Retornar mensaje de error genérico (sin trazas de pila)
     *
     * @param string $message Mensaje de error (predeterminado: 'Error interno del servidor')
     * @return Response Objeto de respuesta
     */
    public static function serverError(string $message = 'Error interno del servidor'): Response
    {
        return self::error($message, null, 500);
    }

    /**
     * Crea una respuesta de servicio no disponible
     *
     * @param string $message Mensaje de error (predeterminado: 'Servicio no disponible')
     * @return Response Objeto de respuesta
     */
    public static function serviceUnavailable(string $message = 'Servicio no disponible'): Response
    {
        return self::error($message, null, 503);
    }

    /**
     * Obtiene el nombre de la cookie de sesión desde la configuración del entorno
     *
     * @return string Nombre de la cookie
     */
    public static function getSessionCookieName(): string
    {
        return $_ENV['SESSION_COOKIE_NAME'] ?? self::SESSION_COOKIE_NAME;
    }
}
