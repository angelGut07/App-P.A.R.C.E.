<?php

namespace App\Models\Admin;

use App\Core\Database;
use App\Core\DomainException;
use App\Models\Concerns\CastsColumns;

/**
 * Servicio de Administración
 *
 * Consultas del panel de administración: resumen del dashboard,
 * calificaciones, rendimiento de mecánicos, usuarios, vehículos y
 * solicitudes de servicio. Las únicas escrituras son el cambio de estado de
 * la cuenta de un usuario.
 */
class AdminService
{
    use CastsColumns;

    /** Estados de cuenta que un administrador puede asignar. */
    public const USER_STATUSES = ['active', 'suspended', 'deactivated'];

    /**
     * Obtiene los contadores de resumen para el dashboard de administración.
     *
     * @return array Contadores de usuarios, solicitudes, PQR, encuestas, pagos e inventario
     */
    public function dashboard(): array
    {
        $usersByRole = Database::fetchAll(
            'SELECT r.slug AS role, COUNT(DISTINCT u.id) AS total
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id AND ur.is_active = TRUE
             JOIN roles r ON r.id = ur.role_id
             WHERE u.deleted_at IS NULL
             GROUP BY r.slug'
        );

        $requestsByStatus = Database::fetchAll(
            'SELECT status, COUNT(*) AS total
             FROM service_requests
             WHERE deleted_at IS NULL
             GROUP BY status'
        );

        $pqr = Database::fetchOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'pending'), 0) AS pending
             FROM pqr WHERE deleted_at IS NULL"
        );

        $totalSurveys = Database::fetchOne(
            'SELECT COUNT(*) AS total FROM surveys WHERE deleted_at IS NULL'
        )['total'] ?? 0;

        $avgRating = Database::fetchOne(
            "SELECT AVG(score) AS average FROM ratings WHERE rating_type = 'customer_to_mechanic'"
        )['average'] ?? null;

        $payments = Database::fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount END), 0) AS confirmed_amount,
                    COALESCE(SUM(status = 'pending'), 0) AS pending
             FROM payments"
        );

        $lowStock = Database::fetchOne(
            'SELECT COUNT(*) AS total FROM products WHERE is_active = TRUE AND stock_quantity < min_stock'
        )['total'] ?? 0;

        $pendingApplications = Database::fetchOne(
            "SELECT COUNT(*) AS total FROM mechanic_applications WHERE status = 'pending'"
        )['total'] ?? 0;

        $roleCounts = array_column($usersByRole, 'total', 'role');

        return [
            'total_users'                  => (int)(Database::fetchOne('SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL')['total'] ?? 0),
            'total_customers'              => (int)($roleCounts['customer'] ?? 0),
            'total_mechanics'              => (int)($roleCounts['mechanic'] ?? 0),
            'pending_mechanic_applications' => (int)$pendingApplications,
            'total_pqr'                    => (int)($pqr['total'] ?? 0),
            'pending_pqr'                  => (int)($pqr['pending'] ?? 0),
            'total_surveys'                => (int)$totalSurveys,
            'average_rating'               => $avgRating !== null ? round((float)$avgRating, 2) : null,
            'confirmed_payments_amount'    => (float)($payments['confirmed_amount'] ?? 0),
            'pending_payments'             => (int)($payments['pending'] ?? 0),
            'low_stock_products'           => (int)$lowStock,
            'requests_by_status'           => array_map(
                fn($row) => ['status' => $row['status'], 'total' => (int)$row['total']],
                $requestsByStatus
            ),
        ];
    }

    /**
     * Lista las calificaciones que los clientes dieron a los mecánicos, con
     * filtros opcionales y paginación.
     *
     * @param array $filters Filtros opcionales: mechanicId, customerId, minRating, dateFrom, dateTo
     * @param int   $limit   Máximo de filas a retornar
     * @param int   $offset  Filas a saltar
     * @return array          ['items' => array, 'total' => int]
     */
    public function ratings(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT sr.id, sr.service_code, sr.emergency_type, st.name AS service_type_name, sr.status,
                       r.score AS customer_rating, r.punctuality_score AS punctuality_rating,
                       r.quality_score AS service_quality_rating, r.comment AS customer_feedback,
                       r.created_at AS rated_at, sr.completed_at,
                       sr.customer_id, sr.mechanic_id,
                       c.first_name AS customer_first_name, c.last_name AS customer_last_name,
                       m.first_name AS mechanic_first_name, m.last_name AS mechanic_last_name,
                       (SELECT ma.specialty FROM mechanic_applications ma
                         WHERE ma.user_id = sr.mechanic_id AND ma.status = 'approved'
                         ORDER BY ma.reviewed_at DESC LIMIT 1) AS mechanic_specialty
                FROM ratings r
                JOIN service_requests sr ON sr.id = r.service_request_id AND sr.deleted_at IS NULL
                JOIN service_types st ON st.code = sr.emergency_type
                JOIN users c ON c.id = sr.customer_id
                LEFT JOIN users m ON m.id = sr.mechanic_id
                WHERE r.rating_type = 'customer_to_mechanic'";
        $params = [];

        if (!empty($filters['mechanicId'])) {
            $sql .= ' AND sr.mechanic_id = ?';
            $params[] = (int)$filters['mechanicId'];
        }

        if (!empty($filters['customerId'])) {
            $sql .= ' AND sr.customer_id = ?';
            $params[] = (int)$filters['customerId'];
        }

        if (!empty($filters['minRating'])) {
            $sql .= ' AND r.score >= ?';
            $params[] = (int)$filters['minRating'];
        }

        if (!empty($filters['dateFrom'])) {
            $sql .= ' AND r.created_at >= ?';
            $params[] = $filters['dateFrom'] . ' 00:00:00';
        }

        if (!empty($filters['dateTo'])) {
            $sql .= ' AND r.created_at <= ?';
            $params[] = $filters['dateTo'] . ' 23:59:59';
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS total FROM ({$sql}) AS filtered",
            $params
        )['total'] ?? 0);

        $sql .= ' ORDER BY r.created_at DESC LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);

        return [
            'items' => Database::fetchAll($sql, $params),
            'total' => $total,
        ];
    }

    /**
     * Rendimiento por mecánico (RF 6.4): promedio, cantidad y distribución
     * de las calificaciones recibidas de los clientes.
     *
     * @return array Un registro por mecánico con al menos una calificación
     */
    public function mechanicPerformance(): array
    {
        $rows = Database::fetchAll(
            "SELECT m.id AS mechanic_id, m.first_name, m.last_name,
                    (SELECT ma.specialty FROM mechanic_applications ma
                      WHERE ma.user_id = m.id AND ma.status = 'approved'
                      ORDER BY ma.reviewed_at DESC LIMIT 1) AS specialty,
                    COUNT(*) AS total_ratings,
                    AVG(r.score) AS average_rating,
                    AVG(CASE WHEN r.created_at >= (NOW() - INTERVAL 30 DAY) THEN r.score END) AS average_last_30_days,
                    SUM(r.score = 1) AS stars_1, SUM(r.score = 2) AS stars_2, SUM(r.score = 3) AS stars_3,
                    SUM(r.score = 4) AS stars_4, SUM(r.score = 5) AS stars_5
             FROM ratings r
             JOIN users m ON m.id = r.ratee_id
             WHERE r.rating_type = 'customer_to_mechanic'
             GROUP BY m.id, m.first_name, m.last_name
             ORDER BY average_rating DESC"
        );

        return array_map(fn($row) => [
            'mechanic_id'          => (int)$row['mechanic_id'],
            'first_name'           => $row['first_name'],
            'last_name'            => $row['last_name'],
            'specialty'            => $row['specialty'],
            'total_ratings'        => (int)$row['total_ratings'],
            'average_rating'       => round((float)$row['average_rating'], 2),
            'average_last_30_days' => $row['average_last_30_days'] !== null ? round((float)$row['average_last_30_days'], 2) : null,
            'distribution'         => [
                1 => (int)$row['stars_1'], 2 => (int)$row['stars_2'], 3 => (int)$row['stars_3'],
                4 => (int)$row['stars_4'], 5 => (int)$row['stars_5'],
            ],
        ], $rows);
    }

    /**
     * Lista usuarios con sus roles y cantidad de vehículos.
     *
     * @param array $filters Filtros opcionales: role (slug), status, search
     * @param int   $limit   Máximo de filas a retornar
     * @param int   $offset  Filas a saltar
     * @return array          ['items' => array, 'total' => int]
     */
    public function users(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT u.id, u.email, u.first_name, u.last_name, u.id_number, u.phone,
                       u.profile_picture_url, u.account_status, u.created_at, u.last_login_at,
                       GROUP_CONCAT(DISTINCT r.slug ORDER BY r.id SEPARATOR ',') AS roles,
                       (SELECT COUNT(*) FROM vehicles v WHERE v.user_id = u.id AND v.deleted_at IS NULL) AS vehicle_count
                FROM users u
                LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_active = TRUE
                LEFT JOIN roles r ON r.id = ur.role_id
                WHERE u.deleted_at IS NULL";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= ' AND u.account_status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.id_number LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $sql .= ' GROUP BY u.id';

        if (!empty($filters['role'])) {
            $sql .= ' HAVING FIND_IN_SET(?, roles) > 0';
            $params[] = $filters['role'];
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS total FROM ({$sql}) AS filtered",
            $params
        )['total'] ?? 0);

        $sql .= ' ORDER BY u.created_at DESC LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);

        $items = array_map(function ($row) {
            $row['roles'] = $row['roles'] !== null ? explode(',', $row['roles']) : [];
            $row['vehicle_count'] = (int)$row['vehicle_count'];
            return $row;
        }, Database::fetchAll($sql, $params));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Cambia el estado de la cuenta de un usuario. Un administrador no puede
     * cambiar el estado de su propia cuenta.
     *
     * @throws DomainException 400 estado inválido, 403 cuenta propia, 404 usuario inexistente
     */
    public function updateUserStatus(int $userId, string $status, int $adminUserId): void
    {
        if (!in_array($status, self::USER_STATUSES, true)) {
            throw new DomainException('Estado de cuenta inválido', 400);
        }

        if ($userId === $adminUserId) {
            throw new DomainException('No puedes cambiar el estado de tu propia cuenta', 403);
        }

        $user = Database::fetchOne('SELECT id FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
        if ($user === null) {
            throw new DomainException('Usuario no encontrado', 404);
        }

        Database::update('users', ['account_status' => $status], 'id = ?', [$userId]);

        // Una cuenta suspendida o desactivada pierde sus sesiones abiertas.
        if ($status !== 'active') {
            Database::query('DELETE FROM sessions WHERE user_id = ?', [$userId]);
        }
    }

    /**
     * Lista todos los vehículos registrados, con su propietario y documentos.
     *
     * @param string|null $search Texto a buscar en placa, marca, modelo o propietario
     * @return array               ['items' => array, 'total' => int]
     */
    public function vehicles(?string $search, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT v.id, v.license_plate, v.make, v.model, v.year, v.color, v.vehicle_type,
                       v.fuel_type, v.status, v.is_primary, v.created_at,
                       u.id AS owner_id, u.first_name AS owner_first_name, u.last_name AS owner_last_name,
                       u.email AS owner_email,
                       soat.document_number AS soat_number,
                       soat.expires_at AS soat_expiration_date,
                       tm.document_number AS tecnomecanica_number,
                       tm.expires_at AS tecnomecanica_expiration_date
                FROM vehicles v
                JOIN users u ON u.id = v.user_id
                LEFT JOIN vehicle_documents soat ON soat.vehicle_id = v.id AND soat.document_type = 'soat'
                LEFT JOIN vehicle_documents tm ON tm.vehicle_id = v.id AND tm.document_type = 'tecnomecanica'
                WHERE v.deleted_at IS NULL";
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (v.license_plate LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS total FROM ({$sql}) AS filtered",
            $params
        )['total'] ?? 0);

        $sql .= ' ORDER BY v.created_at DESC LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);

        return ['items' => $this->castAll(Database::fetchAll($sql, $params), bools: ['is_primary']), 'total' => $total];
    }

    /**
     * Lista todas las solicitudes de servicio de la plataforma.
     *
     * @param array $filters Filtros opcionales: status, search (código, cliente o mecánico)
     * @return array          ['items' => array, 'total' => int]
     */
    public function serviceRequests(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT sr.id, sr.service_code, sr.emergency_type, st.name AS service_type_name,
                       sr.priority, sr.status, sr.requested_at, sr.completed_at, sr.final_cost,
                       sr.customer_id, c.first_name AS customer_first_name, c.last_name AS customer_last_name,
                       sr.mechanic_id, m.first_name AS mechanic_first_name, m.last_name AS mechanic_last_name,
                       v.license_plate AS vehicle_license_plate,
                       p.status AS payment_status, p.amount AS payment_amount
                FROM service_requests sr
                JOIN service_types st ON st.code = sr.emergency_type
                JOIN users c ON c.id = sr.customer_id
                LEFT JOIN users m ON m.id = sr.mechanic_id
                LEFT JOIN vehicles v ON v.id = sr.vehicle_id
                LEFT JOIN payments p ON p.service_request_id = sr.id
                WHERE sr.deleted_at IS NULL";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= ' AND sr.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (sr.service_code LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS total FROM ({$sql}) AS filtered",
            $params
        )['total'] ?? 0);

        $sql .= ' ORDER BY sr.requested_at DESC LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);

        return ['items' => $this->castAll(Database::fetchAll($sql, $params), ['final_cost', 'payment_amount']), 'total' => $total];
    }
}
