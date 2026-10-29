<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;

/**
 * Controlador de Verificación de Salud del Sistema
 *
 * Provee endpoints para monitorear el estado del sistema.
 * Usado por balanceadores de carga, herramientas de monitoreo y DevOps.
 */
class HealthController extends Controller
{
    /**
     * Verificación básica de salud
     *
     * Retorna 200 OK si la aplicación está en ejecución.
     */
    public function index(Request $request): Response
    {
        return ResponseFormatter::success([
            'status'    => 'healthy',
            'message'   => 'Aplicación en ejecución',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Verificación de salud de la base de datos
     *
     * Prueba la conectividad con la base de datos y retorna el estado detallado.
     * CORRECCIÓN: cuando el estado no es 'healthy', se usa ResponseFormatter::error()
     * con código 503 en lugar de ResponseFormatter::success(), lo cual era contradictorio.
     */
    public function database(Request $request): Response
    {
        try {
            $health = Database::healthCheck();

            // Este endpoint no requiere autenticación (lo consumen balanceadores de
            // carga y monitoreo externo), así que nunca debe exponer detalles internos
            // (host, driver, versión del servidor, mensajes crudos del driver PDO) —
            // esa información es reconocimiento útil para un atacante. Solo se expone
            // el estado y el tiempo de respuesta; el detalle completo queda en el log.
            if ($health['status'] === 'healthy') {
                return ResponseFormatter::success([
                    'status'         => $health['status'],
                    'message'        => $health['message'],
                    'responseTimeMs' => $health['details']['response_time_ms'] ?? null,
                    'timestamp'      => date('Y-m-d H:i:s'),
                ], null, 200);
            }

            error_log('Health check: database unhealthy — ' . $health['message']);

            return ResponseFormatter::error(
                'La base de datos no está disponible',
                [
                    'status'    => $health['status'],
                    'timestamp' => date('Y-m-d H:i:s'),
                ],
                503
            );

        } catch (\Throwable $e) {
            ErrorHandler::logException($e);

            return ResponseFormatter::error(
                'La verificación de salud de la base de datos falló',
                [
                    'timestamp' => date('Y-m-d H:i:s'),
                ],
                503
            );
        }
    }

    /**
     * Verificación integral de salud del sistema
     *
     * Comprueba todos los componentes del sistema.
     */
    public function system(Request $request): Response
    {
        $checks        = [];
        $overallStatus = 'healthy';

        // Verificar aplicación
        $checks['application'] = [
            'status'  => 'healthy',
            'message' => 'Aplicación en ejecución',
        ];

        // Verificar base de datos — no exponer detalles internos del driver/host
        // en este endpoint público (mismo criterio que database(), arriba).
        try {
            $dbHealth           = Database::healthCheck();
            $checks['database'] = [
                'status'         => $dbHealth['status'],
                'responseTimeMs' => $dbHealth['details']['response_time_ms'] ?? null,
            ];

            if ($dbHealth['status'] !== 'healthy') {
                error_log('Health check: database unhealthy — ' . $dbHealth['message']);
                $overallStatus = 'degraded';
            }
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
            $checks['database'] = [
                'status' => 'unhealthy',
            ];
            $overallStatus = 'unhealthy';
        }

        // Verificar almacenamiento
        $storagePath        = BASE_PATH . '/storage/logs';
        $checks['storage']  = [
            'status'  => is_writable($storagePath) ? 'healthy' : 'unhealthy',
            'message' => is_writable($storagePath)
                ? 'El almacenamiento tiene permisos de escritura'
                : 'El almacenamiento no tiene permisos de escritura',
        ];

        if ($checks['storage']['status'] !== 'healthy') {
            $overallStatus = 'degraded';
        }

        // Integraciones externas: solo si tienen clave, nunca su valor. Sin
        // ellas la app funciona, pero el asistente y los correos no.
        $checks['integrations'] = [
            'chatbot' => trim((string)($_ENV['GROQ_API_KEY'] ?? '')) !== '' ? 'configured' : 'missing',
            'mail'    => trim((string)($_ENV['RESEND_API_KEY'] ?? '')) !== '' ? 'configured' : 'missing',
        ];

        // Verificar versión de PHP
        $checks['php'] = [
            'status'   => 'healthy',
            'version'  => PHP_VERSION,
            'required' => '>=8.2',
        ];

        $statusCode = match($overallStatus) {
            'healthy'   => 200,
            'degraded'  => 200,
            'unhealthy' => 503,
        };

        return ResponseFormatter::success([
            'status'    => $overallStatus,
            'checks'    => $checks,
            'timestamp' => date('Y-m-d H:i:s'),
        ], null, $statusCode);
    }
}
