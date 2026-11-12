<?php

namespace App\Models\Auth;

use App\Core\Database;
use App\Core\DomainException;
use App\Models\Upload\UploadService;

/**
 * Eliminación de la cuenta a pedido de su titular (derecho de supresión,
 * Ley 1581 de 2012).
 *
 * La fila del usuario no se borra: los servicios, pagos, calificaciones y PQR
 * la referencian (llaves RESTRICT) y son registros que se deben conservar por
 * obligaciones legales y contables. Lo que identifica a la persona se borra o
 * se anonimiza en una sola transacción:
 *
 *  - Cuenta: correo, nombre, cédula, teléfono, licencia y foto; queda
 *    desactivada y con deleted_at, así que ya no puede iniciar sesión.
 *  - Sesiones y enlaces de recuperación de contraseña: se borran.
 *  - Roles: se desactivan.
 *  - Solicitudes para ser mecánico, con sus certificados y soportes: se borran.
 *  - Vehículos: los que nunca se usaron en un servicio se borran; los demás
 *    quedan sin placa, VIN, apodo ni foto (el historial los referencia), y sus
 *    documentos (SOAT, tecnomecánica) se borran.
 *  - Ubicación de sus solicitudes: se redondea a unos 1,1 km; el historial
 *    contable no necesita el punto exacto.
 *
 * Se conservan, ya sin datos que identifiquen a la persona, el historial de
 * servicios, pagos, calificaciones y PQR, y la prueba de la autorización de
 * tratamiento de datos (user_consents). Los archivos se borran del disco
 * después de confirmar la transacción.
 */
class AccountDeletionService
{
    /** Personal de la plataforma: sus cuentas no se eliminan desde la app */
    private const STAFF_ROLES = ['administrator', 'super_admin'];

    /** Estados de un servicio que aún no termina, según el papel de la persona */
    private const ACTIVE_AS_CUSTOMER = ['pending', 'assigned', 'in_progress'];
    private const ACTIVE_AS_MECHANIC = ['assigned', 'in_progress'];

    private RoleValidator $roles;
    private LoginThrottleService $loginThrottle;

    public function __construct(?RoleValidator $roles = null, ?LoginThrottleService $loginThrottle = null)
    {
        $this->roles = $roles ?? new RoleValidator();
        $this->loginThrottle = $loginThrottle ?? new LoginThrottleService();
    }

    /**
     * Elimina la cuenta: anonimiza y borra los datos personales.
     *
     * @return array{email: string, firstName: string} Datos originales, para el correo de confirmación
     * @throws DomainException 403 si es personal de la plataforma, 404 si la cuenta no existe,
     *                         409 si tiene un servicio en curso
     */
    public function delete(int $userId): array
    {
        if ($this->roles->hasAnyRole($userId, self::STAFF_ROLES)) {
            throw new DomainException('Las cuentas de administrador no se eliminan desde la app', 403);
        }

        Database::beginTransaction();
        try {
            $user = Database::fetchOne(
                'SELECT id, email, first_name, profile_picture_url, driver_license_document_url
                 FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$userId]
            );
            if ($user === null) {
                throw new DomainException('La cuenta no existe', 404);
            }

            $this->assertNoActiveService($userId);
            $files = $this->collectFiles($userId, $user);

            // Solicitudes para ser mecánico (sus servicios se borran en cascada)
            Database::query('DELETE FROM mechanic_applications WHERE user_id = ?', [$userId]);

            // Vehículos: documentos fuera; sin historial se borran; con historial se anonimizan
            Database::query(
                'DELETE FROM vehicle_documents WHERE vehicle_id IN (SELECT id FROM vehicles WHERE user_id = ?)',
                [$userId]
            );
            Database::query(
                'DELETE FROM vehicles WHERE user_id = ?
                   AND id NOT IN (SELECT vehicle_id FROM service_requests WHERE vehicle_id IS NOT NULL)',
                [$userId]
            );
            Database::query(
                "UPDATE vehicles SET license_plate = 'ELIMINADO', vin = NULL, nickname = NULL,
                        primary_photo_url = NULL, is_primary = FALSE, status = 'inactive',
                        deleted_at = COALESCE(deleted_at, NOW())
                 WHERE user_id = ?",
                [$userId]
            );

            // Ubicación de sus solicitudes: aproximada (2 decimales, unos 1,1 km)
            Database::query(
                'UPDATE service_requests SET latitude = ROUND(latitude, 2), longitude = ROUND(longitude, 2)
                 WHERE customer_id = ?',
                [$userId]
            );

            // Accesos: sesiones, recuperación de contraseña y roles
            Database::query('DELETE FROM sessions WHERE user_id = ?', [$userId]);
            Database::query('DELETE FROM password_reset_tokens WHERE user_id = ?', [$userId]);
            Database::query('UPDATE user_roles SET is_active = FALSE WHERE user_id = ?', [$userId]);

            // La cuenta: sin datos personales ni forma de iniciar sesión
            Database::update('users', [
                'email'                          => "eliminada-{$userId}@cuentas.parce.invalid",
                'password_hash'                  => '!cuenta-eliminada',
                'first_name'                     => 'Cuenta',
                'last_name'                      => 'eliminada',
                'id_number'                      => null,
                'phone'                          => null,
                'profile_picture_url'            => null,
                'driver_license_number'          => null,
                'driver_license_expiration_date' => null,
                'driver_license_document_url'    => null,
                'driver_license_uploaded_at'     => null,
                'email_verified_at'              => null,
                'phone_verified_at'              => null,
                'last_login_ip'                  => null,
                'account_status'                 => 'deactivated',
                'deleted_at'                     => date('Y-m-d H:i:s'),
            ], 'id = ?', [$userId]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            throw $e;
        }

        // Fuera de la transacción: no se pueden revertir junto con la base
        foreach ($files as $url) {
            UploadService::deleteByUrl($url);
        }
        $this->loginThrottle->clear($user['email']);

        return ['email' => $user['email'], 'firstName' => $user['first_name']];
    }

    /**
     * Un servicio en curso necesita a ambas partes: primero se termina o cancela
     */
    private function assertNoActiveService(int $userId): void
    {
        $customer = implode("', '", self::ACTIVE_AS_CUSTOMER);
        $mechanic = implode("', '", self::ACTIVE_AS_MECHANIC);

        $active = Database::fetchOne(
            "SELECT service_code FROM service_requests
             WHERE deleted_at IS NULL
               AND ((customer_id = ? AND status IN ('{$customer}'))
                 OR (mechanic_id = ? AND status IN ('{$mechanic}')))
             LIMIT 1",
            [$userId, $userId]
        );

        if ($active !== null) {
            throw new DomainException(
                "Tienes un servicio en curso ({$active['service_code']}). Termínalo o cancélalo antes de eliminar tu cuenta.",
                409
            );
        }
    }

    /**
     * URL de los archivos subidos por la persona que se van a borrar del disco
     *
     * @return string[]
     */
    private function collectFiles(int $userId, array $user): array
    {
        $files = [$user['profile_picture_url'], $user['driver_license_document_url']];

        $queries = [
            'SELECT certification_document_url AS url FROM mechanic_applications WHERE user_id = ?',
            'SELECT mas.support_document_url AS url FROM mechanic_application_services mas
               JOIN mechanic_applications ma ON ma.id = mas.application_id WHERE ma.user_id = ?',
            'SELECT d.document_url AS url FROM vehicle_documents d
               JOIN vehicles v ON v.id = d.vehicle_id WHERE v.user_id = ?',
            'SELECT primary_photo_url AS url FROM vehicles WHERE user_id = ?',
        ];
        foreach ($queries as $sql) {
            foreach (Database::fetchAll($sql, [$userId]) as $row) {
                $files[] = $row['url'];
            }
        }

        return array_values(array_unique(array_filter($files, fn($url) => is_string($url) && $url !== '')));
    }
}
