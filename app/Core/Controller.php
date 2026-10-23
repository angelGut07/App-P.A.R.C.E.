<?php

namespace App\Core;

/**
 * Clase base para los controladores
 *
 * Provee funcionalidad común a todos los controladores de la aplicación.
 * Gestiona la renderización de vistas, respuestas JSON y redirecciones.
 */
abstract class Controller
{
    protected Request $request;

    /**
     * Renderiza una plantilla de vista y retorna la respuesta
     */
    protected function view(string $view, array $data = []): Response
    {
        return (new Response())->view($view, $data);
    }

    /**
     * Retorna una respuesta en formato JSON
     */
    protected function json(array $data, int $statusCode = 200): Response
    {
        return (new Response())->json($data, $statusCode);
    }

    /**
     * Retorna una respuesta JSON de éxito estandarizada
     */
    protected function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): Response
    {
        return Response::success($data, $message, $statusCode);
    }

    /**
     * Retorna una respuesta JSON de error estandarizada
     */
    protected function error(string $message, mixed $errors = null, int $statusCode = 400): Response
    {
        return Response::error($message, $errors, $statusCode);
    }

    /**
     * Redirige al usuario a la URL indicada
     */
    protected function redirect(string $url, int $statusCode = 302): Response
    {
        return (new Response())->redirect($url, $statusCode);
    }

    /**
     * Redirige al usuario a la página anterior (referer)
     */
    protected function back(): Response
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        return $this->redirect($referer);
    }

    /**
     * Asigna la instancia de la petición (invocado por el enrutador)
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }
}
