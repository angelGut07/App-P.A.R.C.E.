<?php

namespace App\Models\Auth;

use App\Core\Database;
use App\Models\Mail\MailerService;

/**
 * Bloqueo por cuenta ante intentos fallidos de inicio de sesión
 *
 * Complementa el límite por IP (RateLimiter): quien reparte sus intentos
 * entre muchas IP igual se encuentra con la cuenta bloqueada.
 *
 * - Tras 3 fallos seguidos se exige el CAPTCHA (si está configurado).
 * - Tras 5 fallos la cuenta se bloquea 15 minutos. Cada bloqueo nuevo dura
 *   el doble (15 min, 30 min, 1 h… hasta 24 h) y, después de un bloqueo, el
 *   CAPTCHA se sigue exigiendo.
 * - 24 horas sin fallos reinician el conteo y el escalamiento.
 * - Al bloquear se avisa por correo al dueño de la cuenta, con el enlace para
 *   restablecer la contraseña. Restablecerla desbloquea la cuenta.
 *
 * Se identifica por el hash del correo normalizado, exista o no la cuenta:
 * un correo inexistente se comporta igual que uno real, así que el bloqueo no
 * sirve para averiguar qué correos están registrados.
 */
class LoginThrottleService
{
    /** Fallos seguidos a partir de los cuales se exige el CAPTCHA */
    public const CAPTCHA_AFTER_FAILURES = 3;

    /** Fallos seguidos que bloquean la cuenta */
    public const LOCK_AFTER_FAILURES = 5;

    /** Duración del primer bloqueo (15 minutos) */
    private const BASE_LOCK_SECONDS = 900;

    /** Tope de un bloqueo (24 horas) */
    private const MAX_LOCK_SECONDS = 86400;

    /** Tiempo sin fallos que reinicia el conteo y el escalamiento (24 horas) */
    private const RESET_AFTER_SECONDS = 86400;

    /** Nivel máximo guardado: 15 min × 2^7 ya supera el tope de 24 h */
    private const MAX_LOCKOUT_LEVEL = 7;

    public function __construct(private readonly MailerService $mailer = new MailerService())
    {
    }

    /**
     * Estado actual de un correo, sin modificarlo.
     *
     * @return array{locked: bool, retryAfter: int, failedAttempts: int, captchaRequired: bool}
     */
    public function status(string $email): array
    {
        $row = Database::fetchOne(
            'SELECT failed_attempts, lockout_level, locked_until, last_failed_at
             FROM login_throttles WHERE identifier_hash = ?',
            [self::identifier($email)]
        );

        return self::describe($row, time());
    }

    /**
     * Registra un inicio de sesión fallido. Al llegar al límite bloquea la
     * cuenta y avisa por correo al dueño, si la cuenta existe.
     *
     * @return array{locked: bool, retryAfter: int, failedAttempts: int, captchaRequired: bool}
     */
    public function recordFailure(string $email, string $ipAddress): array
    {
        $hash = self::identifier($email);
        $now = time();
        $lockSeconds = 0;

        Database::beginTransaction();
        try {
            // La fila debe existir para bloquearla con FOR UPDATE: así dos
            // fallos simultáneos del mismo correo se cuentan uno tras otro.
            Database::query('INSERT IGNORE INTO login_throttles (identifier_hash) VALUES (?)', [$hash]);
            $row = Database::fetchOne(
                'SELECT failed_attempts, lockout_level, locked_until, last_failed_at
                 FROM login_throttles WHERE identifier_hash = ? FOR UPDATE',
                [$hash]
            ) ?? [];

            $current = self::describe($row, $now);
            if ($current['locked']) {
                // Ya bloqueada: no se suma (el controlador no deja llegar hasta aquí)
                Database::commit();
                return $current;
            }

            $level = self::isStale($row, $now) ? 0 : (int)($row['lockout_level'] ?? 0);
            $failed = $current['failedAttempts'] + 1;
            $lockedUntil = null;

            if ($failed >= self::LOCK_AFTER_FAILURES) {
                $lockSeconds = min(self::BASE_LOCK_SECONDS * (2 ** $level), self::MAX_LOCK_SECONDS);
                $lockedUntil = $now + $lockSeconds;
                $level = min($level + 1, self::MAX_LOCKOUT_LEVEL);
            }

            $row = [
                'failed_attempts' => $failed,
                'lockout_level'   => $level,
                'locked_until'    => $lockedUntil,
                'last_failed_at'  => $now,
            ];
            Database::update('login_throttles', $row, 'identifier_hash = ?', [$hash]);

            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }

        if ($lockSeconds > 0) {
            $this->notifyOwner($email, $ipAddress, $lockSeconds);
        }

        return self::describe($row, $now);
    }

    /**
     * Olvida los fallos de un correo: inicio de sesión correcto o contraseña
     * restablecida (el dueño demostró tener acceso a su correo).
     */
    public function clear(string $email): void
    {
        Database::delete('login_throttles', 'identifier_hash = ?', [self::identifier($email)]);
    }

    /**
     * Borra los registros sin fallos en las últimas 24 horas y sin bloqueo
     * vigente (php bin/parce cleanup-sessions).
     *
     * @return int Registros borrados
     */
    public function cleanup(): int
    {
        $now = time();

        return Database::delete(
            'login_throttles',
            '(last_failed_at IS NULL OR last_failed_at < ?) AND (locked_until IS NULL OR locked_until < ?)',
            [$now - self::RESET_AFTER_SECONDS, $now]
        );
    }

    /**
     * Duración legible para los mensajes: "15 minutos", "1 hora", "8 horas".
     */
    public static function humanDuration(int $seconds): string
    {
        if ($seconds < 3600) {
            $minutes = max(1, (int)ceil($seconds / 60));
            return $minutes === 1 ? '1 minuto' : "{$minutes} minutos";
        }

        $hours = (int)ceil($seconds / 3600);
        return $hours === 1 ? '1 hora' : "{$hours} horas";
    }

    /**
     * Estado de una fila en un instante dado. Una fila sin fallos en las
     * últimas 24 horas cuenta como vacía; un bloqueo vencido deja el conteo en
     * cero, pero el CAPTCHA se sigue exigiendo.
     */
    private static function describe(?array $row, int $now): array
    {
        if ($row === null || $row === [] || self::isStale($row, $now)) {
            return ['locked' => false, 'retryAfter' => 0, 'failedAttempts' => 0, 'captchaRequired' => false];
        }

        $lockedUntil = isset($row['locked_until']) ? (int)$row['locked_until'] : null;
        $locked = $lockedUntil !== null && $lockedUntil > $now;
        $failed = $lockedUntil !== null && !$locked ? 0 : (int)($row['failed_attempts'] ?? 0);
        $previouslyLocked = (int)($row['lockout_level'] ?? 0) > 0;

        return [
            'locked'          => $locked,
            'retryAfter'      => $locked ? $lockedUntil - $now : 0,
            'failedAttempts'  => $failed,
            'captchaRequired' => $failed >= self::CAPTCHA_AFTER_FAILURES || $previouslyLocked,
        ];
    }

    /** Sin fallos en las últimas 24 horas (y sin bloqueo vigente) */
    private static function isStale(array $row, int $now): bool
    {
        $lastFailed = isset($row['last_failed_at']) ? (int)$row['last_failed_at'] : null;
        $lockedUntil = isset($row['locked_until']) ? (int)$row['locked_until'] : null;

        return $lastFailed !== null
            && $now - $lastFailed >= self::RESET_AFTER_SECONDS
            && ($lockedUntil === null || $lockedUntil <= $now);
    }

    /** Hash del correo sin espacios ni mayúsculas */
    private static function identifier(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email), 'UTF-8'));
    }

    /**
     * Avisa al dueño de la cuenta que se bloqueó el acceso. Un correo que no
     * está registrado no recibe nada; un fallo de envío no interrumpe el login.
     */
    private function notifyOwner(string $email, string $ipAddress, int $lockSeconds): void
    {
        $user = Database::fetchOne(
            "SELECT first_name FROM users WHERE email = ? AND deleted_at IS NULL AND account_status = 'active'",
            [$email]
        );

        if ($user === null) {
            return;
        }

        $this->mailer->send(
            $email,
            'Alerta de seguridad: bloqueamos el acceso a tu cuenta — P.A.R.C.E',
            $this->buildAlertHtml((string)$user['first_name'], $ipAddress, $lockSeconds)
        );
    }

    private function buildAlertHtml(string $firstName, string $ipAddress, int $lockSeconds): string
    {
        $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $safeIp = htmlspecialchars($ipAddress, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars(MailerService::frontendUrl('/forgot-password'), ENT_QUOTES, 'UTF-8');
        $attempts = self::LOCK_AFTER_FAILURES;
        $duration = self::humanDuration($lockSeconds);
        $when = date('d/m/Y h:i a');

        return <<<HTML
            <div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto; color: #1a1a1a;">
                <h2 style="color: #1d4ed8;">P.A.R.C.E</h2>
                <p>Hola {$safeName},</p>
                <p>Detectamos {$attempts} intentos fallidos seguidos para iniciar sesión en tu cuenta. Por seguridad bloqueamos el acceso durante <strong>{$duration}</strong>.</p>
                <p style="color:#444; font-size:14px;">Fecha: {$when}<br>Dirección IP del último intento: {$safeIp}</p>
                <p>Si fuiste tú, espera ese tiempo o restablece tu contraseña para entrar de inmediato. Si no fuiste tú, te recomendamos cambiarla.</p>
                <p>
                    <a href="{$safeUrl}" style="display:inline-block; padding:10px 20px; background:#1d4ed8; color:#ffffff; text-decoration:none; border-radius:6px;">
                        Restablecer contraseña
                    </a>
                </p>
                <p style="color:#666; font-size:13px;">Si el botón no funciona, copia y pega esta URL en tu navegador:<br>{$safeUrl}</p>
            </div>
            HTML;
    }
}
