<?php

namespace App\Models\Auth;

use App\Core\Database;

/**
 * Autorización de tratamiento de datos personales (Ley 1581 de 2012).
 *
 * Registra la aceptación de los Términos y la Política de tratamiento de
 * datos con su prueba (fecha, IP y navegador) en la tabla user_consents. El
 * perfil del usuario dice si aceptó la versión vigente (AuthService::
 * getUserProfileData y AuthController::buildUserProfile); si no, la app le
 * pide aceptarla antes de seguir.
 */
class ConsentService
{
    /**
     * Versión vigente de los Términos y la Política de tratamiento de datos.
     *
     * Al cambiar el texto de las páginas legales (frontend: views/legal.ts,
     * POLICY_VERSION) se sube esta versión en ambos lados y cada usuario debe
     * aceptarla de nuevo al entrar.
     */
    public const CURRENT_VERSION = '2026-11-12';

    /**
     * Registra que el usuario aceptó la versión vigente. Si ya la había
     * aceptado no cambia nada: se conserva la primera aceptación como prueba.
     */
    public function record(int $userId, ?string $ipAddress, ?string $userAgent): void
    {
        Database::query(
            'INSERT INTO user_consents (user_id, policy_version, ip_address, user_agent)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE accepted_at = accepted_at',
            [
                $userId,
                self::CURRENT_VERSION,
                $ipAddress !== null ? substr($ipAddress, 0, 45) : null,
                $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            ]
        );
    }

    /**
     * Estado de la autorización para el perfil, a partir de la última versión
     * aceptada (null si nunca aceptó)
     *
     * @return array{currentVersion: string, version: ?string, acceptedAt: ?string, upToDate: bool}
     */
    public static function status(?string $acceptedVersion, ?string $acceptedAt): array
    {
        return [
            'currentVersion' => self::CURRENT_VERSION,
            'version'        => $acceptedVersion,
            'acceptedAt'     => $acceptedAt,
            'upToDate'       => $acceptedVersion === self::CURRENT_VERSION,
        ];
    }
}
