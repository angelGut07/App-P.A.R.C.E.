<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Http\ErrorHandler;
use App\Core\Http\IPValidator;
use App\Core\Http\RateLimiter;
use App\Core\Http\ResponseFormatter;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Models\Maintenance\MaintenanceService;

/**
 * Mantenimiento programado: POST /api/maintenance/run
 *
 * Lo llama un workflow de GitHub Actions cada 15 minutos
 * (.github/workflows/maintenance.yml), porque Render no tiene cron en el plan
 * gratis. No usa sesión: se autoriza con el header X-Maintenance-Token, que
 * debe ser igual a la variable MAINTENANCE_TOKEN del servidor.
 *
 * Sin MAINTENANCE_TOKEN, o si tiene menos de 32 caracteres, el endpoint está
 * apagado y responde 404.
 */
class MaintenanceController extends Controller
{
    public const TOKEN_HEADER = 'X-Maintenance-Token';
    public const MIN_TOKEN_LENGTH = 32;
    private const RATE_LIMIT_KEY = 'maintenance';

    private ?MaintenanceService $service;

    public function __construct(?MaintenanceService $service = null)
    {
        $this->service = $service;
    }

    public function run(Request $request): Response
    {
        try {
            $expected = self::configuredToken();
            if ($expected === null) {
                return ResponseFormatter::notFound('El mantenimiento programado no está activado en este servidor (falta MAINTENANCE_TOKEN)');
            }

            // El token correcto siempre pasa: el límite de intentos solo frena
            // a quien prueba tokens, y no puede bloquear al cron legítimo.
            $provided = $request->header(self::TOKEN_HEADER);
            if (!is_string($provided) || !hash_equals($expected, $provided)) {
                return $this->rejectInvalidToken(IPValidator::getClientIP($request));
            }

            $database = Database::healthCheck();
            if ($database['status'] !== 'healthy') {
                Logger::write('maintenance', 'No se ejecutó: ' . $database['message']);
                return ResponseFormatter::serviceUnavailable('La base de datos no está disponible');
            }

            $startedAt = microtime(true);
            $summary = ($this->service ?? new MaintenanceService())->runAll();
            $summary['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

            Logger::write('maintenance', (string) json_encode($summary));

            return ResponseFormatter::success($summary, 'Mantenimiento completado');

        } catch (\Throwable $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * MAINTENANCE_TOKEN, o null si falta o es demasiado corto para ser seguro.
     */
    public static function configuredToken(): ?string
    {
        $token = trim((string) ($_ENV['MAINTENANCE_TOKEN'] ?? ''));

        return strlen($token) >= self::MIN_TOKEN_LENGTH ? $token : null;
    }

    /**
     * 401 por token inválido, o 429 tras 5 intentos fallidos en 15 minutos
     * desde la misma IP.
     */
    private function rejectInvalidToken(string $ipAddress): Response
    {
        $limit = RateLimiter::check(self::RATE_LIMIT_KEY, $ipAddress);
        if (!$limit['allowed']) {
            return ResponseFormatter::rateLimitExceeded($limit['reset_at'] - time());
        }

        RateLimiter::recordAttempt(self::RATE_LIMIT_KEY, $ipAddress);
        Logger::write('maintenance', "Token inválido desde {$ipAddress}");

        return ResponseFormatter::unauthorized('Token de mantenimiento inválido');
    }
}
