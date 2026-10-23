<?php

namespace App\Core;

/**
 * Clase principal de la aplicación
 *
 * Gestiona el arranque y el ciclo de vida de la aplicación PARCE.
 * Se encarga de cargar la configuración, manejar errores y despachar peticiones.
 */
class App
{
    private Router $router;
    private array $config = [];

    public function __construct()
    {
        $this->router = new Router();
        // El manejo de errores se configura antes que todo lo demás: si
        // loadEnvironment()/loadConfiguration() fallan (ej. falta el .env en
        // producción), deben quedar cubiertos por el exception handler
        // controlado de la app en vez de dejar pasar un error fatal crudo de
        // PHP con ruta de archivo y traza completa.
        $this->setupErrorHandling();
        $this->loadEnvironment();
        $this->loadConfiguration();
        $this->setupDatabase();
        $this->startSession();
    }

    /**
     * Carga las variables de entorno desde el archivo .env
     */
    private function loadEnvironment(): void
    {
        $envFile = __DIR__ . '/../../.env';

        if (!EnvLoader::load($envFile) && !EnvLoader::hasProcessConfig()) {
            // Verificar si estamos en producción — el .env es obligatorio
            if (getenv('APP_ENV') === 'production') {
                throw new \RuntimeException('.env file not found. This is required in production.');
            }
            // En desarrollo, se advierte pero se continúa con los valores por defecto
            error_log('ADVERTENCIA: Archivo .env no encontrado. Se usará la configuración por defecto.');
        }
    }

    /**
     * Carga los archivos de configuración de la aplicación
     */
    private function loadConfiguration(): void
    {
        $this->config = [
            'app' => [
                'name' => $this->env('APP_NAME', 'P.A.R.C.E'),
                'env' => $this->env('APP_ENV', 'local'),
                // Fail-safe: si APP_DEBUG no está definida, se asume producción
                // (debug=false) — un .env de producción que omita esta clave por
                // error nunca debe filtrar trazas/mensajes internos a usuarios reales.
                'debug' => $this->env('APP_DEBUG', 'false') === 'true',
                'url' => $this->env('APP_URL', 'http://localhost'),
            ],
            'database' => [
                'driver' => $this->env('DB_CONNECTION', 'mysql'),
                'host' => $this->env('DB_HOST', '127.0.0.1'),
                'port' => (int) $this->env('DB_PORT', '3306'),
                'database' => $this->env('DB_DATABASE', 'parce'),
                'username' => $this->env('DB_USERNAME', 'root'),
                'password' => $this->env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'ssl_ca' => $this->env('DB_SSL_CA', null),
            ],
            'session' => [
                'lifetime' => (int) $this->env('SESSION_LIFETIME', '120'),
                'driver' => $this->env('SESSION_DRIVER', 'file'),
            ],
        ];

        // Validar la configuración cargada
        $validator = new ConfigValidator();
        if (!$validator->validate($this->config)) {
            $errorMessage = $validator->getErrorMessage();

            if ($this->config['app']['debug'] ?? false) {
                throw new \RuntimeException($errorMessage);
            } else {
                // Registrar errores y continuar con advertencias en producción
                error_log($errorMessage);
            }
        }

        // Registrar advertencias aunque la validación haya pasado
        $warnings = $validator->getWarnings();
        if (!empty($warnings)) {
            foreach ($warnings as $warning) {
                error_log("ADVERTENCIA DE CONFIG: {$warning}");
            }
        }
    }


    /**
     * Configura el manejo global de errores y excepciones
     */
    private function setupErrorHandling(): void
    {
        error_reporting(E_ALL);

        // $this->config puede estar vacío en este punto (se ejecuta antes de
        // loadConfiguration()) — por defecto se asume debug=false, la opción segura.
        if ($this->config['app']['debug'] ?? false) {
            ini_set('display_errors', '1');
        } else {
            ini_set('display_errors', '0');
        }

        // Manejador de excepciones no capturadas
        set_exception_handler(function (\Throwable $e) {
            $this->handleException($e);
        });

        // Manejador de errores — convierte errores en excepciones
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        // Manejador de cierre para errores fatales
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $this->handleException(new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                ));
            }
        });
    }

    /**
     * Maneja las excepciones no capturadas de la aplicación.
     *
     * Si la petición es de tipo API (Content-Type: application/json o URI bajo /api/),
     * responde con JSON. De lo contrario, responde con HTML.
     */
    private function handleException(\Throwable $e): void
    {
        // Registrar el error en el log
        $this->logError($e);

        // Establecer código de respuesta HTTP 500
        http_response_code(500);

        $requestId = RequestContext::getRequestId();
        if ($requestId !== null) {
            header('X-Request-Id: ' . $requestId);
        }

        // Determinar si la petición es de tipo API
        $contentType = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestContentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        $esApiRequest = str_contains($requestContentType, 'application/json')
            || str_contains($contentType, 'application/json')
            || str_starts_with($requestUri, '/api/');

        if ($esApiRequest) {
            // Responder con JSON para peticiones de API
            header('Content-Type: application/json');

            $respuesta = [
                'success' => false,
                'error'   => 'Error interno del servidor',
            ];

            if ($this->config['app']['debug'] ?? false) {
                // Incluir información de depuración solo en modo debug
                $respuesta['debug'] = [
                    'mensaje'      => $e->getMessage(),
                    'archivo'      => $e->getFile(),
                    'linea'        => $e->getLine(),
                    'traza'        => $e->getTraceAsString(),
                ];
            }

            echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            // Responder con HTML para peticiones web
            if ($this->config['app']['debug'] ?? false) {
                // Mostrar error detallado en modo debug
                echo "<h1>Error</h1>";
                echo "<p><strong>Mensaje:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
                echo "<p><strong>Archivo:</strong> " . htmlspecialchars($e->getFile()) . "</p>";
                echo "<p><strong>Línea:</strong> " . $e->getLine() . "</p>";
                echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            } else {
                // Mostrar mensaje genérico en producción
                echo "<h1>500 Error Interno del Servidor</h1>";
                echo "<p>Algo salió mal. Por favor, inténtelo de nuevo más tarde.</p>";
            }
        }

        exit(1);
    }

    /**
     * Registra el error en el archivo de log diario
     */
    private function logError(\Throwable $e): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/error-' . date('Y-m-d') . '.log';
        $requestId = RequestContext::getRequestId();
        $message = sprintf(
            "[%s]%s %s: %s in %s:%d\nStack trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            $requestId !== null ? " [{$requestId}]" : '',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $this->formatTraceWithoutArguments($e)
        );

        file_put_contents($logFile, $message, FILE_APPEND);
    }

    /**
     * Formatea la traza de la excepción sin los valores de los argumentos.
     *
     * `Throwable::getTraceAsString()` incluye los valores de los argumentos de
     * cada llamada en la pila — si el error ocurre mientras una contraseña,
     * token de sesión u otro dato sensible está en el alcance de alguna llamada
     * de la pila, ese valor en texto plano quedaría escrito permanentemente en
     * el archivo de log, sin importar el modo debug (este log se escribe
     * siempre). Se construye la traza manualmente usando solo
     * clase/función/archivo/línea, sin argumentos.
     *
     * @param \Throwable $e Excepción o error
     * @return string Traza de la pila sin argumentos
     */
    private function formatTraceWithoutArguments(\Throwable $e): string
    {
        $lines = [];

        foreach ($e->getTrace() as $index => $frame) {
            $function = $frame['function'] ?? '';
            $class    = $frame['class'] ?? '';
            $type     = $frame['type'] ?? '';
            $file     = $frame['file'] ?? '[internal function]';
            $line     = $frame['line'] ?? '';

            $location = $file !== '[internal function]' ? "{$file}({$line})" : $file;

            $lines[] = "#{$index} {$location}: {$class}{$type}{$function}()";
        }

        $lines[] = '#' . count($lines) . ' {main}';

        return implode("\n", $lines);
    }

    /**
     * Inicializa la conexión a la base de datos con la configuración cargada
     */
    private function setupDatabase(): void
    {
        Database::setConfig($this->config['database']);
    }

    /**
     * Inicia la sesión de usuario
     */
    private function startSession(): void
    {
        Session::start();
    }

    /**
     * Obtiene el valor de una variable de entorno con valor por defecto
     */
    private function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Retorna la instancia del enrutador
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Obtiene un valor de configuración usando notación de punto (ej: 'app.debug')
     */
    public function config(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Ejecuta la aplicación: despacha la petición y envía la respuesta
     */
    public function run(): void
    {
        // ID único de esta petición — se propaga al log de errores y a la
        // respuesta (header X-Request-Id) para poder correlacionar un
        // reporte de un usuario con la línea exacta del log que lo originó.
        $requestId = uniqid('req_', true);
        RequestContext::setRequestId($requestId);

        $request = new Request();
        $request->setAttribute('requestId', $requestId);

        $response = $this->router->dispatch($request);
        $response->setHeader('X-Request-Id', $requestId);
        $response->send();
    }
}
