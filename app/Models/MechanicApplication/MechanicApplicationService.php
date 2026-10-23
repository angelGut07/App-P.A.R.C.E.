<?php

namespace App\Models\MechanicApplication;

use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\DomainException;
use App\Models\Auth\RoleValidator;

/**
 * Servicio de Solicitudes de Mecánico
 *
 * Gestiona el flujo de solicitud + revisión administrativa para obtener el
 * rol `mechanic`. El registro público (AuthService::register()) ya no acepta
 * ningún parámetro de rol — la única vía para obtener `mechanic` es una
 * solicitud aprobada por un administrador a través de este servicio.
 *
 * El registro público con rol "mecánico" (AuthService::register()) crea la
 * cuenta como cliente y abre automáticamente una solicitud aquí; el rol
 * `mechanic` solo se concede cuando un administrador la aprueba.
 *
 * La especialidad, experiencia y certificación se guardan en la solicitud
 * (tabla `mechanic_applications`); el perfil del mecánico las lee de su
 * solicitud aprobada.
 */
class MechanicApplicationService
{
    /**
     * Slug del único rol que este flujo puede conceder. El ID real del rol
     * siempre se resuelve en base de datos (getMechanicRoleId()) — nunca se
     * acepta un role_id/slug desde una petición HTTP.
     */
    private const MECHANIC_ROLE_SLUG = 'mechanic';

    /**
     * Roles que no pueden solicitar convertirse en mecánico. Un administrador
     * ya tiene acceso administrativo a la plataforma; permitirle acumular
     * además el rol operativo de mecánico mezcla dos responsabilidades que
     * el sistema mantiene deliberadamente separadas.
     */
    private const EXCLUDED_ROLE_SLUGS = ['administrator', 'super_admin'];

    private RoleValidator $roleValidator;

    public function __construct()
    {
        $this->roleValidator = new RoleValidator();
    }

    /**
     * Crea una solicitud para convertirse en mecánico (usa su propia transacción).
     *
     * @param int   $userId ID del usuario autenticado (nunca del body)
     * @param array $data   specialty, years_experience, certification_title,
     *                      certification_document_url, justification (todos opcionales)
     * @return int          ID de la solicitud creada
     * @throws DomainException 404 si el usuario no existe; 403 si la cuenta no
     *                          está activa; 409 si ya tiene el rol o ya tiene una
     *                          solicitud pendiente; 400 si no registró licencia
     *                          o la tiene vencida
     */
    public function create(int $userId, array $data): int
    {
        Database::beginTransaction();
        try {
            $applicationId = $this->createWithinTransaction($userId, $data);
            Database::commit();
            return $applicationId;
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Igual que create(), pero dentro de una transacción ya abierta por quien
     * llama (AuthService::register() crea la cuenta y la solicitud juntas).
     */
    public function createWithinTransaction(int $userId, array $data): int
    {
        // Lock pesimista sobre la propia fila del usuario: serializa
        // cualquier create() concurrente del MISMO usuario (doble clic,
        // reintento de red).
        $user = Database::fetchOne(
            'SELECT id, account_status, driver_license_number,
                    driver_license_expiration_date, driver_license_document_url
             FROM users WHERE id = ? FOR UPDATE',
            [$userId]
        );

        if ($user === null) {
            throw new DomainException('Usuario no encontrado', 404);
        }

        // Ya garantizado estructuralmente por AuthMiddleware (rechaza toda
        // petición de una cuenta no activa antes de llegar a este Service) —
        // se re-verifica aquí explícitamente por defensa en profundidad, sin
        // depender silenciosamente de que otro componente lo siga haciendo.
        if ($user['account_status'] !== 'active') {
            throw new DomainException('Tu cuenta no está activa', 403);
        }

        // Verificado con los roles actuales del usuario dentro de esta misma
        // transacción (no antes) — un administrador/super_admin no puede
        // solicitar el rol de mecánico. Se comprueba antes que el rol
        // mechanic en sí para dar el mensaje correcto: un admin que además
        // ya fuera mecánico (caso ya cubierto por la siguiente validación)
        // sigue estando excluido por esta regla igualmente.
        if ($this->roleValidator->hasAnyRole($userId, self::EXCLUDED_ROLE_SLUGS)) {
            throw new DomainException(
                'Los administradores no pueden solicitar el rol de mecánico',
                403
            );
        }

        if ($this->roleValidator->hasRole($userId, self::MECHANIC_ROLE_SLUG)) {
            throw new DomainException('Ya tienes el rol de mecánico', 409);
        }

        // La licencia completa (vencimiento y documento) se exige al
        // aprobar; para abrir la solicitud basta con haberla registrado.
        if (empty($user['driver_license_number'])) {
            throw new DomainException(
                'Debes registrar tu licencia de conducción en tu perfil antes de solicitar ser mecánico',
                400
            );
        }

        if (
            !empty($user['driver_license_expiration_date'])
            && $user['driver_license_expiration_date'] < date('Y-m-d')
        ) {
            throw new DomainException('Tu licencia de conducción está vencida', 400);
        }

        $existingPending = Database::fetchOne(
            'SELECT id FROM mechanic_applications WHERE user_id = ? AND status = ?',
            [$userId, 'pending']
        );

        if ($existingPending !== null) {
            throw new DomainException('Ya tienes una solicitud pendiente de revisión', 409);
        }

        return Database::insert('mechanic_applications', [
            'user_id'                    => $userId,
            'specialty'                  => $data['specialty'] ?? null,
            'years_experience'           => isset($data['years_experience']) ? (int)$data['years_experience'] : null,
            'certification_title'        => $data['certification_title'] ?? null,
            'certification_document_url' => $data['certification_document_url'] ?? null,
            'justification'              => $data['justification'] ?? null,
            'status'                     => 'pending',
        ]);
    }

    /**
     * Adjunta (o reemplaza) el documento de certificación de una solicitud
     * propia pendiente. La URL debe venir de POST /api/uploads.
     *
     * @throws DomainException 404 si no existe/no es propia; 409 si ya fue revisada
     */
    public function attachDocument(int $applicationId, int $userId, string $documentUrl): array
    {
        $application = $this->getByIdForUser($applicationId, $userId);

        if ($application['status'] !== 'pending') {
            throw new DomainException('Solo se puede adjuntar el documento a una solicitud pendiente', 409);
        }

        Database::update(
            'mechanic_applications',
            ['certification_document_url' => $documentUrl],
            'id = ? AND user_id = ? AND status = ?',
            [$applicationId, $userId, 'pending']
        );

        return $this->getByIdForUser($applicationId, $userId);
    }

    /**
     * Especialidad y certificación vigentes de un mecánico (su solicitud
     * aprobada más reciente), o null si no tiene ninguna aprobada.
     */
    public function getApprovedProfile(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT specialty, years_experience, certification_title, certification_document_url, reviewed_at AS approved_at
             FROM mechanic_applications
             WHERE user_id = ? AND status = 'approved'
             ORDER BY reviewed_at DESC LIMIT 1",
            [$userId]
        );
    }

    /**
     * Obtiene el historial completo de solicitudes de un usuario (más
     * reciente primero), para que pueda ver el estado tras un rechazo y
     * volver a solicitar si corresponde.
     *
     * @param int $userId ID del usuario autenticado
     * @return array       Lista de solicitudes
     */
    public function getByUser(int $userId): array
    {
        return Database::fetchAll(
            'SELECT * FROM mechanic_applications WHERE user_id = ? ORDER BY created_at DESC',
            [$userId]
        );
    }

    /**
     * Obtiene una solicitud propia por ID.
     *
     * La consulta combina id+user_id en el mismo WHERE — mismo patrón anti-IDOR
     * que PQRService::getByIdForUser(): una solicitud ajena responde 404, igual
     * que una inexistente, nunca se distingue con un 403 separado (evita que un
     * atacante pueda usar la diferencia de código de estado para enumerar IDs
     * ajenos válidos).
     *
     * @param int $applicationId ID de la solicitud
     * @param int $userId        ID del usuario autenticado (dueño esperado)
     * @return array              Solicitud
     * @throws DomainException    404 si no existe o no pertenece al usuario
     */
    public function getByIdForUser(int $applicationId, int $userId): array
    {
        $application = Database::fetchOne(
            'SELECT * FROM mechanic_applications WHERE id = ? AND user_id = ?',
            [$applicationId, $userId]
        );

        if ($application === null) {
            throw new DomainException('Solicitud no encontrada', 404);
        }

        return $application;
    }

    /**
     * Cancela una solicitud propia pendiente.
     *
     * @param int $applicationId ID de la solicitud
     * @param int $userId        ID del usuario autenticado (dueño esperado)
     * @return array              Solicitud actualizada
     * @throws DomainException    404 si no existe/no es propia; 409 si no está
     *                             pendiente o si su estado cambió concurrentemente
     */
    public function cancel(int $applicationId, int $userId): array
    {
        $application = $this->getByIdForUser($applicationId, $userId);

        if ($application['status'] !== 'pending') {
            throw new DomainException('Solo se pueden cancelar solicitudes pendientes', 409);
        }

        // El WHERE repite el estado actual para que la actualización sea atómica
        // frente a un cambio de estado concurrente (ej. el admin aprueba justo
        // mientras el usuario cancela) — mismo patrón que PQRService::updateStatus().
        $affected = Database::update(
            'mechanic_applications',
            ['status' => 'cancelled'],
            'id = ? AND user_id = ? AND status = ?',
            [$applicationId, $userId, 'pending']
        );

        if ($affected === 0) {
            throw new DomainException(
                'El estado de la solicitud cambió antes de poder cancelarla. Actualiza la página e inténtalo de nuevo.',
                409
            );
        }

        return $this->getByIdForUser($applicationId, $userId);
    }

    /**
     * Lista todas las solicitudes de mecánico para el administrador, con
     * filtro opcional de estado.
     *
     * Paginado (LIMIT/OFFSET) — mismo patrón que PQRService::adminList() y
     * SurveyService::adminList(): $limit/$offset ya vienen validados como
     * enteros por RequestValidator::parsePagination(), así que se interpolan
     * directamente (MySQL con PDO::ATTR_EMULATE_PREPARES=false no acepta
     * LIMIT/OFFSET como parámetros preparados nativos).
     *
     * @param array $filters Filtros opcionales: status
     * @param int   $limit   Máximo de filas a retornar
     * @param int   $offset  Filas a saltar
     * @return array          ['items' => array, 'total' => int]
     */
    public function adminList(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT ma.*,
                       u.first_name AS applicant_first_name,
                       u.last_name AS applicant_last_name,
                       u.email AS applicant_email,
                       u.driver_license_number,
                       u.driver_license_expiration_date,
                       u.driver_license_document_url,
                       reviewer.first_name AS reviewer_first_name,
                       reviewer.last_name AS reviewer_last_name,
                       u.phone AS applicant_phone,
                       u.id_number AS applicant_id_number
                FROM mechanic_applications ma
                JOIN users u ON u.id = ma.user_id
                LEFT JOIN users reviewer ON reviewer.id = ma.reviewed_by
                WHERE 1 = 1';
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= ' AND ma.status = ?';
            $params[] = $filters['status'];
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS total FROM ({$sql}) AS filtered",
            $params
        )['total'] ?? 0);

        $sql .= ' ORDER BY ma.created_at DESC LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);

        return [
            'items' => Database::fetchAll($sql, $params),
            'total' => $total,
        ];
    }

    /**
     * Aprueba una solicitud pendiente: asigna el rol `mechanic` al
     * solicitante.
     *
     * Este método es el ÚNICO lugar de todo el sistema donde se hace
     * `INSERT INTO user_roles` con el rol `mechanic` para un usuario que no
     * lo tenía ya. El rol se resuelve siempre por su slug en base de datos
     * (getMechanicRoleId()) — nunca se acepta un role_id desde la petición.
     * `assigned_by` y `reviewed_by` se toman exclusivamente de
     * $adminUserId (el usuario autenticado que hace la llamada), nunca del
     * body de la petición.
     *
     * @param int $applicationId ID de la solicitud
     * @param int $adminUserId   ID del administrador autenticado
     * @return array              Solicitud actualizada
     * @throws DomainException    404 si no existe; 403 si el admin intenta
     *                             aprobar su propia solicitud; 409 si ya no está
     *                             pendiente, si el estado cambió concurrentemente,
     *                             o si el usuario ya tiene el rol; 400 si el
     *                             usuario objetivo ya no está activo o su licencia
     *                             ya no es válida
     */
    public function approve(int $applicationId, int $adminUserId): array
    {
        Database::beginTransaction();
        try {
            // Lock pesimista sobre la fila de la solicitud — cierra la carrera de
            // dos administradores aprobando/rechazando la misma solicitud a la
            // vez, mismo patrón que ServiceRequestEvidenceService::addEvidence().
            $application = Database::fetchOne(
                'SELECT * FROM mechanic_applications WHERE id = ? FOR UPDATE',
                [$applicationId]
            );

            if ($application === null) {
                throw new DomainException('Solicitud no encontrada', 404);
            }

            if ($application['status'] !== 'pending') {
                throw new DomainException('La solicitud ya no está pendiente de revisión', 409);
            }

            // Bloqueo de autoaprobación, antes de escribir nada.
            if ((int)$application['user_id'] === $adminUserId) {
                throw new DomainException('No puedes aprobar tu propia solicitud', 403);
            }

            // Re-verificación de cuenta activa y licencia vigente: pudieron
            // cambiar entre el momento en que se creó la solicitud y el momento
            // de la aprobación.
            $applicant = Database::fetchOne(
                'SELECT id, account_status, driver_license_number,
                        driver_license_expiration_date, driver_license_document_url
                 FROM users WHERE id = ?',
                [$application['user_id']]
            );

            if ($applicant === null || $applicant['account_status'] !== 'active') {
                throw new DomainException('El usuario ya no está activo', 400);
            }

            if (
                empty($applicant['driver_license_number'])
                || empty($applicant['driver_license_expiration_date'])
                || $applicant['driver_license_expiration_date'] < date('Y-m-d')
            ) {
                throw new DomainException('Para aprobar, la licencia del solicitante debe tener número y una fecha de vencimiento vigente', 400);
            }

            $mechanicRoleId = $this->getMechanicRoleId();

            try {
                Database::insert('user_roles', [
                    'user_id'     => $application['user_id'],
                    'role_id'     => $mechanicRoleId,
                    'assigned_by' => $adminUserId,
                    'assigned_at' => date('Y-m-d H:i:s'),
                    'is_active'   => true,
                ]);
            } catch (DatabaseException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw new DomainException('El usuario ya tiene este rol', 409);
                }
                throw $e;
            }

            // El WHERE repite status='pending' para que la aprobación sea
            // atómica frente a la misma carrera que el lock de arriba ya
            // debería impedir — doble guardia, mismo hábito que el resto del
            // codebase (ver PQRService::updateStatus()).
            $affected = Database::update(
                'mechanic_applications',
                [
                    'status'      => 'approved',
                    'reviewed_by' => $adminUserId,
                    'reviewed_at' => date('Y-m-d H:i:s'),
                ],
                'id = ? AND status = ?',
                [$applicationId, 'pending']
            );

            if ($affected === 0) {
                throw new DomainException(
                    'El estado de la solicitud cambió antes de poder aprobarla. Actualiza la página e inténtalo de nuevo.',
                    409
                );
            }

            Database::commit();

            return Database::fetchOne('SELECT * FROM mechanic_applications WHERE id = ?', [$applicationId]);
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Rechaza una solicitud pendiente.
     *
     * `reviewed_by` se toma exclusivamente de $adminUserId; el motivo queda en
     * `review_notes` (obligatorio para un rechazo, ver CHECK de la tabla).
     *
     * @param int    $applicationId ID de la solicitud
     * @param int    $adminUserId   ID del administrador autenticado
     * @param string $reason        Motivo de rechazo (obligatorio, ya validado)
     * @return array                 Solicitud actualizada
     * @throws DomainException       404 si no existe; 409 si ya no está pendiente
     *                                o si el estado cambió concurrentemente
     */
    public function reject(int $applicationId, int $adminUserId, string $reason): array
    {
        Database::beginTransaction();
        try {
            $application = Database::fetchOne(
                'SELECT * FROM mechanic_applications WHERE id = ? FOR UPDATE',
                [$applicationId]
            );

            if ($application === null) {
                throw new DomainException('Solicitud no encontrada', 404);
            }

            if ($application['status'] !== 'pending') {
                throw new DomainException('La solicitud ya no está pendiente de revisión', 409);
            }

            $affected = Database::update(
                'mechanic_applications',
                [
                    'status'       => 'rejected',
                    'reviewed_by'  => $adminUserId,
                    'review_notes' => $reason,
                    'reviewed_at'  => date('Y-m-d H:i:s'),
                ],
                'id = ? AND status = ?',
                [$applicationId, 'pending']
            );

            if ($affected === 0) {
                throw new DomainException(
                    'El estado de la solicitud cambió antes de poder rechazarla. Actualiza la página e inténtalo de nuevo.',
                    409
                );
            }

            Database::commit();

            return Database::fetchOne('SELECT * FROM mechanic_applications WHERE id = ?', [$applicationId]);
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Resuelve el ID del rol `mechanic` desde la base de datos. Este es el
     * único lugar de todo el flujo donde se determina qué rol se va a
     * conceder — nunca se acepta este valor desde una petición HTTP.
     *
     * @return int
     * @throws \Exception Si el rol no existe o está desactivado (no debería
     *                     ocurrir nunca — es uno de los 5 roles sembrados desde
     *                     la migración inicial del proyecto; error interno
     *                     genérico, sin detalle expuesto al cliente, mismo
     *                     tratamiento que el "Role not found" ya existente en
     *                     AuthService::register())
     */
    private function getMechanicRoleId(): int
    {
        $role = Database::fetchOne(
            'SELECT id FROM roles WHERE slug = ? AND is_active = TRUE',
            [self::MECHANIC_ROLE_SLUG]
        );

        if ($role === null) {
            throw new \Exception("Role '" . self::MECHANIC_ROLE_SLUG . "' not found");
        }

        return (int)$role['id'];
    }
}
