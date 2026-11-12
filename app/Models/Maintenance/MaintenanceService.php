<?php

namespace App\Models\Maintenance;

use App\Core\Database;
use App\Core\Logger;
use App\Models\Auth\LoginThrottleService;
use App\Models\Auth\SessionManager;

/**
 * Tareas de mantenimiento periódico.
 *
 * Render, en el plan gratis, no tiene cron: un workflow de GitHub Actions
 * llama cada 15 minutos a POST /api/maintenance/run
 * (.github/workflows/maintenance.yml). También se pueden correr a mano con
 * `php bin/parce maintenance`.
 *
 * Todas las tareas son idempotentes. Correrlas de más no cambia nada, y
 * correrlas tarde solo atrasa la limpieza.
 */
class MaintenanceService
{
    /** Minutos que una solicitud puede esperar a que un mecánico la acepte */
    public const REQUEST_TIMEOUT_MINUTES = 30;

    /**
     * Días que se guardan los enlaces de recuperación ya vencidos: no sirven
     * para nada, pero guardan la IP de quien los pidió por si hay que revisar
     * un intento de robo de cuenta.
     */
    public const RESET_TOKEN_RETENTION_DAYS = 7;

    /** Días que se guardan los archivos de log (solo existen con LOG_CHANNEL=file) */
    public const LOG_RETENTION_DAYS = 30;

    private readonly SessionManager $sessions;
    private readonly LoginThrottleService $loginThrottles;
    private readonly string $logDirectory;

    public function __construct(
        ?SessionManager $sessions = null,
        ?LoginThrottleService $loginThrottles = null,
        ?string $logDirectory = null
    ) {
        $this->sessions = $sessions ?? new SessionManager();
        $this->loginThrottles = $loginThrottles ?? new LoginThrottleService();
        $this->logDirectory = $logDirectory ?? Logger::directory();
    }

    /**
     * Ejecuta todas las tareas.
     *
     * @return array{expired_requests: int, sessions: int, login_throttles: int, password_reset_tokens: int, log_files: int}
     *         Cuántos registros tocó cada una
     */
    public function runAll(): array
    {
        return ['expired_requests' => $this->expireUnattendedRequests()]
            + $this->cleanupAuthRecords()
            + ['log_files' => $this->deleteOldLogFiles()];
    }

    /**
     * Borra lo que la autenticación ya no necesita: sesiones vencidas por
     * inactividad, registros de intentos fallidos viejos y enlaces de
     * recuperación vencidos.
     *
     * @return array{sessions: int, login_throttles: int, password_reset_tokens: int}
     */
    public function cleanupAuthRecords(): array
    {
        return [
            'sessions'              => $this->sessions->cleanup(),
            'login_throttles'       => $this->loginThrottles->cleanup(),
            'password_reset_tokens' => $this->deleteOldPasswordResetTokens(),
        ];
    }

    /**
     * Expira las solicitudes que siguen pendientes (ningún mecánico las
     * aceptó) después de $minutes minutos. El cliente las ve como "Expirado"
     * y puede pedir el servicio otra vez.
     *
     * Si un mecánico acepta justo en ese momento, gana la primera escritura:
     * esta y ServiceRequestService::accept() exigen status = 'pending'.
     *
     * @return int Solicitudes expiradas
     */
    public function expireUnattendedRequests(int $minutes = self::REQUEST_TIMEOUT_MINUTES): int
    {
        return Database::update(
            'service_requests',
            ['status' => 'expired', 'expired_at' => date('Y-m-d H:i:s')],
            'status = ? AND requested_at < (NOW() - INTERVAL ? MINUTE)',
            ['pending', max(1, $minutes)]
        );
    }

    /**
     * Borra los enlaces de recuperación de contraseña que vencieron hace más
     * de $days días. Los usados también vencen (una hora después de pedirlos).
     *
     * @return int Enlaces borrados
     */
    public function deleteOldPasswordResetTokens(int $days = self::RESET_TOKEN_RETENTION_DAYS): int
    {
        return Database::delete(
            'password_reset_tokens',
            'expires_at < (NOW() - INTERVAL ? DAY)',
            [max(1, $days)]
        );
    }

    /**
     * Borra los archivos .log con más de $days días sin cambios. En Render
     * los logs van a stderr y esta carpeta queda vacía.
     *
     * @return int Archivos borrados
     */
    public function deleteOldLogFiles(int $days = self::LOG_RETENTION_DAYS): int
    {
        $cutoff = time() - max(1, $days) * 86400;
        $deleted = 0;

        foreach (glob($this->logDirectory . '/*.log') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
