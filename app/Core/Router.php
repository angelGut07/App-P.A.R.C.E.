<?php

namespace App\Core;

/**
 * Clase enrutadora de la aplicación
 *
 * Gestiona el registro y el despacho de rutas HTTP.
 * Soporta un pipeline de middleware para autenticación y autorización.
 */
class Router
{
    private array $routes = [];
    private array $middlewareStack = [];
    private array $groupMiddleware = [];
    private string $groupPrefix = '';
    private array $globalMiddleware = [];

    /**
     * Registra una ruta para el método GET
     */
    public function get(string $uri, callable|array $action): Route
    {
        return $this->addRoute('GET', $uri, $action);
    }

    /**
     * Registra una ruta para el método POST
     */
    public function post(string $uri, callable|array $action): Route
    {
        return $this->addRoute('POST', $uri, $action);
    }

    /**
     * Registra una ruta para el método PUT
     */
    public function put(string $uri, callable|array $action): Route
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    /**
     * Registra una ruta para el método DELETE
     */
    public function delete(string $uri, callable|array $action): Route
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    /**
     * Registra una ruta que responde a cualquier método HTTP
     */
    public function any(string $uri, callable|array $action): Route
    {
        return $this->addRoute('ANY', $uri, $action);
    }

    /**
     * Agrega una ruta al registro interno del enrutador
     */
    private function addRoute(string $method, string $uri, callable|array $action): Route
    {
        $uri = $this->groupPrefix . $uri;
        $uri = '/' . trim($uri, '/');

        $route = new Route($method, $uri, $action);

        // Aplicar el middleware del grupo activo a la ruta
        if (!empty($this->groupMiddleware)) {
            $route->middleware($this->groupMiddleware);
        }

        $this->routes[] = $route;

        return $route;
    }


    /**
     * Registra middleware global que se ejecuta en cada petición
     *
     * @param string|array $middleware Nombre(s) de clase del middleware
     * @return void
     */
    public function middleware(string|array $middleware): void
    {
        $middleware = is_array($middleware) ? $middleware : [$middleware];
        $this->globalMiddleware = array_merge($this->globalMiddleware, $middleware);
    }

    /**
     * Agrupa rutas bajo un prefijo y/o middleware común
     */
    public function group(array $attributes, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        // Establecer el prefijo del grupo
        if (isset($attributes['prefix'])) {
            $this->groupPrefix = $previousPrefix . '/' . trim($attributes['prefix'], '/');
        }

        // Establecer el middleware del grupo
        if (isset($attributes['middleware'])) {
            $middleware = is_array($attributes['middleware'])
                ? $attributes['middleware']
                : [$attributes['middleware']];
            $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        }

        // Ejecutar el callback para registrar las rutas del grupo
        $callback($this);

        // Restaurar los valores anteriores al grupo
        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /**
     * Despacha la petición entrante hacia la ruta que corresponda
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $uri = $request->uri();

        /* Manejar el preflight OPTIONS mediante el middleware global primero.
         * Esto permite que el middleware CORS intercepte sin requerir registro de ruta. */
        if ($method === 'OPTIONS' && !empty($this->globalMiddleware)) {
            // Crear una ruta ficticia para OPTIONS y pasarla por el middleware
            $dummyRoute = new Route('OPTIONS', $uri, function($request) {
                // Si ningún middleware la maneja, retornar 204 No Content
                return (new Response())->setStatusCode(204);
            });

            $response = $this->runMiddleware($dummyRoute, $request);
            if ($response !== null) {
                return $response;
            }
        }

        foreach ($this->routes as $route) {
            if ($route->matches($method, $uri)) {
                // Ejecutar el pipeline de middleware (que incluye la acción de la ruta)
                $response = $this->runMiddleware($route, $request);

                if ($response !== null) {
                    return $response;
                }

                // Si no hay middleware, ejecutar la acción directamente
                $params = $route->extractParams($uri);
                return $this->executeAction($route->getAction(), $request, $params);
            }
        }

        // Ninguna ruta coincidió — retornar 404
        return Response::error('Ruta no encontrada', null, 404);
    }

    /**
     * Ejecuta el pipeline de middleware para la ruta indicada
     */
    private function runMiddleware(Route $route, Request $request): ?Response
    {
        // Combinar el middleware global con el middleware específico de la ruta
        $routeMiddleware = $route->getMiddleware();
        $allMiddleware = array_merge($this->globalMiddleware, $routeMiddleware);

        if (empty($allMiddleware)) {
            return null;
        }

        // Construir el pipeline de middleware con la acción de la ruta como destino final
        $pipeline = function($request) use ($route) {
            // Manejador final: ejecutar la acción de la ruta
            $params = $route->extractParams($request->uri());
            return $this->executeAction($route->getAction(), $request, $params);
        };

        // Envolver cada middleware alrededor del pipeline (en orden inverso)
        foreach (array_reverse($allMiddleware) as $middlewareClass) {
            $pipeline = function($request) use ($middlewareClass, $pipeline) {
                // Middleware como cadena de texto (sin parámetros)
                if (is_string($middlewareClass)) {
                    $middlewareInstance = new $middlewareClass();
                }
                // Middleware como arreglo [NombreClase, parámetros]
                elseif (is_array($middlewareClass) && count($middlewareClass) === 2) {
                    $className = $middlewareClass[0];
                    $params = $middlewareClass[1];
                    // Pasar los parámetros tal cual (ya es un arreglo para RBACMiddleware)
                    $middlewareInstance = new $className($params);
                }
                // Middleware ya instanciado
                else {
                    $middlewareInstance = $middlewareClass;
                }

                // Ejecutar el middleware
                return $middlewareInstance->handle($request, $pipeline);
            };
        }

        // Ejecutar el pipeline completo
        return $pipeline($request);
    }

    /**
     * Ejecuta la acción de una ruta (closure o método de controlador)
     */
    private function executeAction(callable|array $action, Request $request, array $params): Response
    {
        if (is_callable($action)) {
            $result = $action($request, ...$params);
        } elseif (is_array($action) && count($action) === 2) {
            [$controller, $method] = $action;

            if (is_string($controller)) {
                $controller = new $controller();
            }

            $result = $controller->$method($request, ...$params);
        } else {
            throw new \Exception('Invalid route action');
        }

        // Convertir el resultado en una instancia de Response si es necesario
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return (new Response())->json($result);
        }

        if (is_string($result)) {
            return (new Response())->html($result);
        }

        return (new Response())->setContent($result);
    }

    /**
     * Retorna todas las rutas registradas en el enrutador
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
