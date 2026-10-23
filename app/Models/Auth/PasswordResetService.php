<?php

namespace App\Models\Auth;

use App\Core\Database;
use App\Core\DomainException;
use App\Models\Mail\MailerService;

/**
 * Servicio de Recuperación de Contraseña
 *
 * Orquesta el flujo de "olvidé mi contraseña": genera un token de un solo
 * uso, envía el enlace por correo, y valida/consume el token al restablecer
 * la contraseña. Reutiliza PasswordHasher, AuthService::updatePassword() y
 * SessionManager ya existentes en vez de duplicar esa lógica.
 */
class PasswordResetService
{
    /**
     * Vida del token: 1 hora, el estándar habitual para este tipo de enlace.
     */
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly PasswordHasher $passwordHasher,
        private readonly AuthService $authService,
        private readonly SessionManager $sessionManager,
        private readonly MailerService $mailer
    ) {
    }

    /**
     * Genera un token de reseteo y envía el correo si el email corresponde
     * a una cuenta activa. No revela si el email existe o no — mismo
     * criterio anti-enumeración ya usado en el login.
     *
     * @param string $email
     * @param string $ipAddress
     * @return void
     */
    public function requestReset(string $email, string $ipAddress): void
    {
        $user = Database::fetchOne(
            'SELECT id, first_name FROM users WHERE email = ? AND deleted_at IS NULL AND account_status = ?',
            [$email, 'active']
        );

        if ($user === null) {
            return;
        }

        // Invalidar tokens anteriores y emitir el nuevo deben ser atómicos: el
        // UPDATE de invalidación no adquiere ningún lock útil por sí solo
        // cuando no hay filas que afectar (ej. primera solicitud, o dos
        // solicitudes concurrentes que ambas ven "nada que invalidar"), así que
        // "envolver en una transacción" no basta para cerrar la carrera. Se
        // adquiere un lock pesimista sobre la fila del propio usuario (mismo
        // patrón que ServiceRequestService::create()) para serializar cualquier
        // solicitud de reseteo concurrente del mismo usuario — sin esto, dos
        // clics en "olvidé mi contraseña" casi simultáneos podrían dejar dos
        // tokens válidos coexistiendo, justo lo que este método intenta evitar.
        Database::beginTransaction();
        try {
            Database::fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user['id']]);

            Database::update(
                'password_reset_tokens',
                ['used_at' => date('Y-m-d H:i:s')],
                'user_id = ? AND used_at IS NULL AND expires_at > NOW()',
                [$user['id']]
            );

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);

            Database::insert('password_reset_tokens', [
                'user_id'    => $user['id'],
                'token_hash' => $tokenHash,
                'ip_address' => $ipAddress,
                'expires_at' => $expiresAt,
            ]);

            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }

        $frontendUrl = rtrim($_ENV['APP_FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:5173', '/');
        $resetUrl = "{$frontendUrl}/reset-password?token={$token}";

        $this->mailer->send(
            $email,
            'Recupera tu contraseña — P.A.R.C.E',
            $this->buildEmailHtml($user['first_name'], $resetUrl)
        );
    }

    /**
     * Valida un token y, si es válido, actualiza la contraseña, consume el
     * token, y cierra todas las sesiones activas del usuario (misma
     * práctica que changePassword() para un cambio de contraseña normal).
     *
     * @param string $token
     * @param string $newPassword
     * @return int ID del usuario cuya contraseña fue restablecida
     * @throws DomainException Si el token es inválido, expiró o ya se usó
     */
    public function resetPassword(string $token, string $newPassword): int
    {
        $tokenHash = hash('sha256', $token);

        $record = Database::fetchOne(
            'SELECT id, user_id FROM password_reset_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
            [$tokenHash]
        );

        if ($record === null) {
            throw new DomainException('El enlace de recuperación es inválido o ha expirado', 400);
        }

        $userId = (int) $record['user_id'];
        $newHash = $this->passwordHasher->hash($newPassword);

        Database::beginTransaction();
        try {
            $this->authService->updatePassword($userId, $newHash);

            // Invalida el token usado y cualquier otro token vigente del mismo
            // usuario (defensa en profundidad junto con requestReset(): cierra
            // la ventana si dos tokens llegaron a coexistir por cualquier motivo).
            Database::update(
                'password_reset_tokens',
                ['used_at' => date('Y-m-d H:i:s')],
                'user_id = ? AND used_at IS NULL',
                [$userId]
            );

            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }

        // Fuera de la transacción: cerrar sesiones activas es una limpieza de
        // seguridad, no parte de la integridad transaccional del cambio en sí.
        $this->sessionManager->destroyAllUserSessions($userId);

        return $userId;
    }

    private function buildEmailHtml(string $firstName, string $resetUrl): string
    {
        $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto; color: #1a1a1a;">
                <h2 style="color: #1d4ed8;">P.A.R.C.E</h2>
                <p>Hola {$safeName},</p>
                <p>Recibimos una solicitud para restablecer tu contraseña. Si no fuiste tú, puedes ignorar este correo.</p>
                <p>
                    <a href="{$safeUrl}" style="display:inline-block; padding:10px 20px; background:#1d4ed8; color:#ffffff; text-decoration:none; border-radius:6px;">
                        Restablecer contraseña
                    </a>
                </p>
                <p style="color:#666; font-size:13px;">Este enlace vence en 1 hora. Si el botón no funciona, copia y pega esta URL en tu navegador:<br>{$safeUrl}</p>
            </div>
            HTML;
    }
}
