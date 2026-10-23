<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Middleware de Registro de Solicitudes
 *
 * Registra todas las solicitudes HTTP en formato JSON estructurado, incluyendo:
 * - Metadatos de la solicitud (marca de tiempo, ID, método, ruta)
 * - Metadatos de la respuesta (código de estado, tiempo de ejecución)
 * - Información del cliente (IP, agente de usuario)
 *
 * Requisitos: 13.1, 13.2, 13.3, 13.4, 13.5, 13.6, 13.7
 */
class RequestLoggerMiddleware
{
    /**
     * Ruta del archivo de registro
     */
    private const LOG_FILE = __DIR__ . '/../../storage/logs/requests.log';

    /**
     * Procesa la solicitud entrante
     *
     * Requisitos: 13.1, 13.2, 13.3, 13.4, 13.5
     *
     * @param Request $request Objeto de solicitud HTTP
     * @param callable $next Siguiente middleware/controlador en la cadena
     * @return Response Respuesta HTTP
     */
    public function handle(Request $request, callable $next): Response
    {
        // Requisito 13.1: reutilizar el ID de solicitud ya generado por
        // App::run() (compartido con el log de errores y el header
        // X-Request-Id de la respuesta) en vez de generar uno propio aquí
        // — antes cada uno tenía un ID distinto, imposible de correlacionar.
        $requestId = $request->getAttribute('requestId') ?? $this->generateRequestId();

        // Requisito 13.2: Registrar el tiempo de inicio para calcular la duración
        $startTime = microtime(true);

        // Requisito 13.1: Capturar la marca de tiempo de la solicitud
        $timestamp = date('Y-m-d H:i:s');

        // Ejecutar la solicitud a través de la cadena de middlewares
        $response = $next($request);

        // Requisito 13.2: Calcular el tiempo de ejecución en milisegundos
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        // Requisitos 13.3, 13.4, 13.5: Construir la entrada de registro
        $logEntry = [
            'timestamp' => $timestamp,
            'requestId' => $requestId,
            'method' => $request->method(),
            'path' => $request->uri(),
            'status' => $response->getStatusCode(),
            'durationMs' => $durationMs,
            'ip' => $this->getClientIp($request),
            'userAgent' => $request->userAgent()
        ];

        // Requisito 13.6: Filtrar datos sensibles (contraseñas, tokens, IDs de sesión)
        // Nota: No se registra el cuerpo ni las cabeceras de la solicitud para evitar datos sensibles

        // Requisito 13.7: Escribir la entrada de registro
        $this->writeLog($logEntry);

        return $response;
    }

    /**
     * Genera un ID único de solicitud
     *
     * Requisito 13.1
     *
     * @return string Identificador único de solicitud
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * Obtiene la dirección IP del cliente
     *
     * Gestiona la cabecera X-Forwarded-For para solicitudes mediante proxy
     *
     * Requisito 13.5
     *
     * @param Request $request Solicitud HTTP
     * @return string Dirección IP del cliente
     */
    private function getClientIp(Request $request): string
    {
        // Verificar la cabecera X-Forwarded-For (para solicitudes mediante proxy)
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor !== null) {
            // Usar la primera IP de la cadena
            $ips = explode(',', $forwardedFor);
            return trim($ips[0]);
        }

        // Alternativa: IP directa
        return $request->ip();
    }

    /**
     * Escribe la entrada de registro en el archivo
     *
     * Usa el formato JSON Lines (un objeto JSON por línea)
     *
     * Requisitos: 13.7
     *
     * @param array $logEntry Datos de la entrada de registro
     * @return void
     */
    private function writeLog(array $logEntry): void
    {
        try {
            // Asegurar que el directorio de registros exista
            $logDir = dirname(self::LOG_FILE);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            // Convertir a JSON y agregar nueva línea (formato JSON Lines)
            $jsonLine = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

            // Agregar al archivo de registro (bajo costo, sin bloqueo para mejor rendimiento)
            file_put_contents(self::LOG_FILE, $jsonLine, FILE_APPEND);

        } catch (\Exception $e) {
            // Fallar silenciosamente para no interrumpir el flujo de la solicitud
            // Registrar en error_log como alternativa
            error_log("RequestLogger error: " . $e->getMessage());
        }
    }

    /**
     * Obtiene la ruta del archivo de registro (para pruebas)
     *
     * @return string Ruta del archivo de registro
     */
    public static function getLogFilePath(): string
    {
        return self::LOG_FILE;
    }
}
