<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Middleware CORS
 *
 * Gestiona el intercambio de recursos de origen cruzado (CORS) para autenticación
 * basada en sesiones. Admite solicitudes con credenciales, orígenes configurables
 * y manejo de preflight.
 *
 * Configuración mediante .env:
 * - CORS_ALLOWED_ORIGINS: Lista de orígenes permitidos separados por coma
 * - CORS_ALLOW_CREDENTIALS: Habilitar soporte de credenciales (por defecto: true)
 * - CORS_MAX_AGE: Duración de caché del preflight en segundos (por defecto: 86400)
 */
class CORSMiddleware
{
    /**
     * Orígenes permitidos por defecto (desarrollo)
     */
    private const DEFAULT_ORIGINS = 'http://localhost:3000,http://localhost:5173,http://localhost:8080';

    /**
     * Métodos HTTP permitidos
     */
    private const ALLOWED_METHODS = 'GET, POST, PUT, DELETE, OPTIONS, PATCH';

    /**
     * Cabeceras permitidas
     */
    private const ALLOWED_HEADERS = 'Content-Type, Accept, Authorization, X-Requested-With, X-Request-ID';

    /**
     * Cabeceras expuestas (visibles para el frontend)
     */
    private const EXPOSED_HEADERS = 'X-Request-ID, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset';

    /**
     * Procesa la solicitud CORS entrante
     *
     * @param Request $request Solicitud HTTP
     * @param callable $next Siguiente middleware
     * @return Response Respuesta HTTP
     */
    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->header('Origin');

        // Si no hay cabecera Origin, continuar (solicitud del mismo origen)
        if ($origin === null) {
            return $next($request);
        }

        // Verificar si el origen está permitido
        if (!$this->isOriginAllowed($origin)) {
            // Por seguridad, se procesa la solicitud pero no se establecen cabeceras CORS
            // Esto evita errores CORS en solicitudes legítimas del mismo origen
            return $next($request);
        }

        // Manejar solicitud de preflight (OPTIONS)
        if ($request->method() === 'OPTIONS') {
            return $this->handlePreflight($origin);
        }

        // Procesar la solicitud real y agregar cabeceras CORS a la respuesta
        $response = $next($request);
        return $this->addCORSHeaders($response, $origin);
    }

    /**
     * Maneja la solicitud de preflight OPTIONS
     *
     * @param string $origin Origen de la solicitud
     * @return Response Respuesta de preflight
     */
    private function handlePreflight(string $origin): Response
    {
        $response = new Response();
        $response->setStatusCode(204); // Sin contenido

        // Agregar cabeceras CORS
        $response->setHeader('Access-Control-Allow-Origin', $this->resolveAllowOriginHeader($origin));
        $response->setHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
        $response->setHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        $response->setHeader('Access-Control-Max-Age', $this->getMaxAge());

        // Habilitar credenciales si está configurado — nunca junto con comodín (ver
        // resolveAllowOriginHeader())
        if ($this->allowCredentials() && !$this->isWildcardConfigured()) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Agrega cabeceras CORS a la respuesta
     *
     * @param Response $response Respuesta HTTP
     * @param string $origin Origen de la solicitud
     * @return Response Respuesta con cabeceras CORS
     */
    private function addCORSHeaders(Response $response, string $origin): Response
    {
        // Establecer el origen permitido (debe ser específico cuando se usan credenciales)
        $response->setHeader('Access-Control-Allow-Origin', $this->resolveAllowOriginHeader($origin));

        // Habilitar credenciales (necesario para cookies de sesión) — nunca junto con
        // comodín (ver resolveAllowOriginHeader())
        if ($this->allowCredentials() && !$this->isWildcardConfigured()) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        // Exponer cabeceras al frontend
        $response->setHeader('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);

        // Agregar cabecera Vary para caché correcta
        $response->setHeader('Vary', 'Origin');

        return $response;
    }

    /**
     * Verifica si el origen está permitido
     *
     * @param string $origin Origen de la solicitud
     * @return bool True si está permitido, false en caso contrario
     */
    private function isOriginAllowed(string $origin): bool
    {
        $allowedOrigins = $this->getAllowedOrigins();

        // Verificar comodín (no recomendado con credenciales)
        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }

        // Verificar si el origen está en la lista permitida
        return in_array($origin, $allowedOrigins, true);
    }

    /**
     * Verifica si CORS_ALLOWED_ORIGINS está configurado como comodín ("*").
     *
     * @return bool
     */
    private function isWildcardConfigured(): bool
    {
        return in_array('*', $this->getAllowedOrigins(), true);
    }

    /**
     * Resuelve el valor de Access-Control-Allow-Origin a enviar.
     *
     * Si CORS_ALLOWED_ORIGINS incluye "*", se responde con el literal "*" en vez
     * de reflejar el Origin de la solicitud. Reflejar el origen exacto junto con
     * Access-Control-Allow-Credentials: true (habilitado por defecto) permitiría
     * a cualquier sitio hacer solicitudes autenticadas con las cookies de sesión
     * del usuario — el navegador sí lo permite porque desde su perspectiva es un
     * origen específico, no un comodín real. Devolver el "*" literal hace que el
     * navegador rechace la combinación con credenciales, tal como especifica CORS.
     *
     * @param string $origin Origen de la solicitud
     * @return string
     */
    private function resolveAllowOriginHeader(string $origin): string
    {
        return $this->isWildcardConfigured() ? '*' : $origin;
    }

    /**
     * Obtiene los orígenes permitidos desde la configuración
     *
     * @return array Arreglo de orígenes permitidos
     */
    private function getAllowedOrigins(): array
    {
        $origins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? self::DEFAULT_ORIGINS;

        // Dividir por coma y eliminar espacios en blanco
        $originList = array_map('trim', explode(',', $origins));

        // Filtrar valores vacíos
        return array_filter($originList, fn($origin) => !empty($origin));
    }

    /**
     * Verifica si las credenciales están permitidas
     *
     * @return bool True si las credenciales están permitidas
     */
    private function allowCredentials(): bool
    {
        $allow = $_ENV['CORS_ALLOW_CREDENTIALS'] ?? 'true';
        return strtolower($allow) === 'true';
    }

    /**
     * Obtiene la edad máxima de caché del preflight
     *
     * @return string Edad máxima en segundos
     */
    private function getMaxAge(): string
    {
        return $_ENV['CORS_MAX_AGE'] ?? '86400'; // 24 horas por defecto
    }
}
