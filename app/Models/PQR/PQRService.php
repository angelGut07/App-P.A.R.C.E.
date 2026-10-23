<?php

namespace App\Models\PQR;

use App\Core\Database;
use App\Core\DomainException;

/**
 * Servicio de PQR
 *
 * Lógica de negocio para peticiones, quejas, reclamos y sugerencias:
 * creación por parte del usuario y gestión (filtrar, responder, cambiar estado)
 * por parte del administrador.
 */
class PQRService
{
    /**
     * Crea un nuevo PQR para el usuario autenticado.
     *
     * @param int   $userId ID del usuario que crea el ticket
     * @param array $data   Datos del PQR (type, subject, description)
     * @return int          ID del PQR creado
     */
    public function create(int $userId, array $data): int
    {
        // El código de ticket depende del ID recién insertado, así que requiere
        // una segunda escritura — ambas deben ser atómicas: si la segunda falla,
        // el ticket quedaría con ticket_code='PENDING' permanentemente.
        Database::beginTransaction();
        try {
            $ticketId = Database::insert('pqr', [
                'user_id'     => $userId,
                'ticket_code' => 'PENDING',
                'type'        => $data['type'],
                'subject'     => $data['subject'],
                'description' => $data['description'],
                'status'      => 'pending',
            ]);

            $ticketCode = PQRValidator::generateTicketCode($ticketId);
            Database::update('pqr', ['ticket_code' => $ticketCode], 'id = ?', [$ticketId]);

            Database::commit();
            return $ticketId;
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Obtiene todos los tickets PQR de un usuario, opcionalmente filtrados por estado.
     *
     * @param int         $userId ID del usuario
     * @param string|null $status Filtro de estado opcional
     * @return array              Lista de tickets PQR
     */
    public function getUserPqrs(int $userId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM pqr WHERE user_id = ? AND deleted_at IS NULL';
        $params = [$userId];

        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY created_at DESC';

        return Database::fetchAll($sql, $params);
    }

    /**
     * Obtiene un ticket PQR perteneciente a un usuario específico.
     *
     * @param int $ticketId ID del PQR
     * @param int $userId   ID del usuario propietario
     * @return array        Ticket PQR
     * @throws DomainException Si el ticket no existe o no pertenece al usuario (404)
     */
    public function getByIdForUser(int $ticketId, int $userId): array
    {
        $ticket = Database::fetchOne(
            'SELECT * FROM pqr WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
            [$ticketId, $userId]
        );

        if ($ticket === null) {
            throw new DomainException('Ticket PQR no encontrado', 404);
        }

        return $ticket;
    }

    /**
     * Obtiene un ticket PQR por ID sin restricción de propietario (uso administrativo).
     *
     * @param int $ticketId ID del PQR
     * @return array        Ticket PQR con datos del usuario que lo creó
     * @throws DomainException Si el ticket no existe (404)
     */
    public function getByIdForAdmin(int $ticketId): array
    {
        $ticket = Database::fetchOne(
            'SELECT p.*,
                    u.first_name AS reporter_first_name,
                    u.last_name AS reporter_last_name,
                    u.email AS reporter_email
             FROM pqr p
             JOIN users u ON u.id = p.user_id
             WHERE p.id = ? AND p.deleted_at IS NULL',
            [$ticketId]
        );

        if ($ticket === null) {
            throw new DomainException('Ticket PQR no encontrado', 404);
        }

        return $ticket;
    }

    /**
     * Obtiene todos los tickets PQR con filtros para el administrador.
     *
     * Paginado (LIMIT/OFFSET) — este listado admin-wide (no acotado por
     * usuario) crece sin límite con el uso de la plataforma. $limit/$offset
     * ya vienen validados como enteros por
     * RequestValidator::parsePagination(), así que se interpolan
     * directamente: MySQL con PDO::ATTR_EMULATE_PREPARES=false no acepta
     * LIMIT/OFFSET como parámetros preparados nativos.
     *
     * @param array $filters Filtros opcionales: status, type, q (busca en subject/description),
     *                       reporter (customer | mechanic: quién la radicó)
     * @param int   $limit   Máximo de filas a retornar
     * @param int   $offset  Filas a saltar
     * @return array         ['items' => array, 'total' => int]
     */
    public function adminList(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $isMechanic = "EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                                WHERE ur.user_id = p.user_id AND r.slug = 'mechanic' AND ur.is_active = TRUE)";

        $sql = "SELECT p.*,
                       u.first_name AS reporter_first_name,
                       u.last_name AS reporter_last_name,
                       u.email AS reporter_email,
                       IF({$isMechanic}, 'mechanic', 'customer') AS reporter_role
                FROM pqr p
                JOIN users u ON u.id = p.user_id
                WHERE p.deleted_at IS NULL";
        $params = [];

        if (($filters['reporter'] ?? null) === 'mechanic') {
            $sql .= " AND {$isMechanic}";
        } elseif (($filters['reporter'] ?? null) === 'customer') {
            $sql .= " AND NOT {$isMechanic}";
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $sql .= ' AND p.type = ?';
            $params[] = $filters['type'];
        }

        if (!empty($filters['q'])) {
            $sql .= ' AND (p.subject LIKE ? OR p.description LIKE ? OR p.ticket_code LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS total FROM ({$sql}) AS filtered",
            $params
        )['total'] ?? 0);

        $sql .= ' ORDER BY p.created_at DESC LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);

        return [
            'items' => Database::fetchAll($sql, $params),
            'total' => $total,
        ];
    }

    /**
     * Actualiza el estado de un ticket PQR.
     *
     * Solo se permiten las transiciones definidas en
     * PQRValidator::validateStatusTransition() (derivadas del flujo ya
     * implementado en el panel de administrador): un ticket resuelto o
     * rechazado es terminal y no puede reabrirse por esta vía.
     *
     * @param int    $ticketId ID del PQR
     * @param string $status   Nuevo estado
     * @return bool            Verdadero si se actualizó correctamente
     * @throws DomainException Si el ticket no existe o la transición no es válida
     */
    public function updateStatus(int $ticketId, string $status): bool
    {
        $ticket = Database::fetchOne(
            'SELECT status FROM pqr WHERE id = ? AND deleted_at IS NULL',
            [$ticketId]
        );

        if ($ticket === null) {
            throw new DomainException('Ticket PQR no encontrado', 404);
        }

        $validation = PQRValidator::validateStatusTransition($ticket['status'], $status);
        if (!$validation['valid']) {
            throw new DomainException($validation['error'], 400);
        }

        // El WHERE repite el estado actual para que la actualización sea atómica
        // frente a una transición concurrente (ej. dos administradores actuando
        // sobre el mismo ticket a la vez) — mismo patrón usado en ServiceRequestService.
        $affected = Database::update(
            'pqr',
            ['status' => $status],
            'id = ? AND status = ? AND deleted_at IS NULL',
            [$ticketId, $ticket['status']]
        );

        if ($affected === 0) {
            throw new DomainException(
                'El estado del ticket cambió antes de poder actualizarlo. Actualiza la página e inténtalo de nuevo.',
                409
            );
        }

        return true;
    }

    /**
     * Registra la respuesta del administrador y marca el ticket como resuelto.
     *
     * @param int    $ticketId      ID del PQR
     * @param int    $adminUserId   ID del administrador que responde
     * @param string $responseText Texto de la respuesta
     * @return bool                Verdadero si se actualizó correctamente
     * @throws DomainException     Si el ticket ya fue respondido
     */
    public function respond(int $ticketId, int $adminUserId, string $responseText): bool
    {
        // El WHERE exige que aún no tenga respuesta, para que sea atómico frente a
        // un doble envío concurrente (ej. dos pestañas del mismo admin) — sin esto,
        // el segundo envío sobrescribiría silenciosamente la respuesta del primero.
        $affected = Database::update(
            'pqr',
            [
                'admin_response' => $responseText,
                'responded_by'   => $adminUserId,
                'responded_at'   => date('Y-m-d H:i:s'),
                'status'         => 'resolved',
            ],
            'id = ? AND deleted_at IS NULL AND admin_response IS NULL',
            [$ticketId]
        );

        if ($affected === 0) {
            throw new DomainException('Este ticket ya fue respondido', 409);
        }

        return true;
    }
}
