<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Middleware de Cabeceras de Seguridad
 *
 * Agrega cabeceras HTTP de seguridad estándar a toda respuesta. La API es
 * exclusivamente JSON (salvo la página de bienvenida en '/', que no usa
 * scripts ni recursos externos), por lo que se aplica una CSP estricta.
 */
class SecurityHeadersMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Evita que el navegador adivine el Content-Type (protección contra ataques MIME-sniffing)
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        // Evita que la aplicación se cargue dentro de un iframe (protección contra clickjacking)
        $response->setHeader('X-Frame-Options', 'DENY');

        // No enviar la URL completa como referrer a otros orígenes
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // API JSON sin scripts/estilos/recursos externos: política restrictiva por defecto
        $response->setHeader(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'"
        );

        // Forzar HTTPS en el navegador durante 1 año una vez servido por HTTPS
        if ($request->isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
