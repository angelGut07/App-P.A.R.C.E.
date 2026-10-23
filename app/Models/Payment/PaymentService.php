<?php

namespace App\Models\Payment;

use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\DomainException;
use App\Models\Concerns\CastsColumns;
use App\Models\Inventory\ServicePartService;

/**
 * Servicio de Pagos
 *
 * Cobro de un servicio completado: mano de obra (costo final del mecánico o,
 * si no lo fijó, el precio base del tipo de servicio) más los repuestos
 * usados. La pasarela es simulada: tarjeta y PSE se confirman al instante;
 * el efectivo queda pendiente hasta que el mecánico (o un administrador)
 * confirma que lo recibió.
 */
class PaymentService
{
    use CastsColumns;

    public const METHODS = ['cash', 'card', 'pse'];

    private ServicePartService $parts;

    public function __construct()
    {
        $this->parts = new ServicePartService();
    }

    /**
     * Desglose del cobro de una solicitud y su pago (si existe), validando
     * que el usuario pueda verlo.
     *
     * @param string $role customer | mechanic | admin
     */
    public function getForRequest(int $requestId, int $userId, string $role): array
    {
        $request = $this->fetchRequest($requestId);
        $this->assertCanView($request, $userId, $role);

        $parts      = $this->parts->listForRequest($requestId);
        $partsTotal = array_sum(array_map(fn($p) => (float)$p['subtotal'], $parts));
        $labor      = $this->laborAmount($request);

        $payment = Database::fetchOne(
            'SELECT p.*, r.first_name AS reviewer_first_name, r.last_name AS reviewer_last_name
             FROM payments p
             LEFT JOIN users r ON r.id = p.reviewed_by
             WHERE p.service_request_id = ?',
            [$requestId]
        );

        return [
            'service_request' => [
                'id'                    => (int)$request['id'],
                'service_code'          => $request['service_code'],
                'status'                => $request['status'],
                'service_type_name'     => $request['service_type_name'],
                'description'           => $request['description'],
                'completed_at'          => $request['completed_at'],
                'customer_name'         => trim($request['customer_first_name'] . ' ' . $request['customer_last_name']),
                'customer_email'        => $request['customer_email'],
                'customer_id_number'    => $request['customer_id_number'],
                'mechanic_name'         => $request['mechanic_id'] !== null
                    ? trim($request['mechanic_first_name'] . ' ' . $request['mechanic_last_name'])
                    : null,
                'vehicle_license_plate' => $request['vehicle_license_plate'],
            ],
            'labor_amount' => $labor,
            'parts'        => $parts,
            'parts_total'  => $partsTotal,
            'total'        => $labor + $partsTotal,
            'payment'      => $this->cast($payment, ['amount']),
        ];
    }

    /**
     * El cliente paga un servicio completado.
     *
     * @throws DomainException 403/404/409 según la regla que no se cumpla
     */
    public function pay(int $requestId, int $customerId, string $method, ?string $cardLast4, ?string $pseBank): array
    {
        Database::beginTransaction();
        try {
            $request = $this->fetchRequest($requestId, true);

            if ((int)$request['customer_id'] !== $customerId) {
                throw new DomainException('No eres el propietario de esta solicitud de servicio', 403);
            }
            if ($request['status'] !== 'completed') {
                throw new DomainException('Solo se pueden pagar servicios completados', 409);
            }

            $partsTotal = (float)(Database::fetchOne(
                'SELECT COALESCE(SUM(quantity * unit_price), 0) AS total FROM service_request_parts WHERE service_request_id = ?',
                [$requestId]
            )['total'] ?? 0);

            $confirmedNow = $method !== 'cash';

            try {
                $paymentId = Database::insert('payments', [
                    'service_request_id' => $requestId,
                    'receipt_number'     => 'PENDIENTE-' . $requestId,
                    'method'             => $method,
                    'status'             => $confirmedNow ? 'confirmed' : 'pending',
                    'amount'             => $this->laborAmount($request) + $partsTotal,
                    'card_last4'         => $method === 'card' ? $cardLast4 : null,
                    'pse_bank'           => $method === 'pse' ? $pseBank : null,
                    'reviewed_at'        => $confirmedNow ? date('Y-m-d H:i:s') : null,
                ]);
            } catch (DatabaseException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw new DomainException('Este servicio ya tiene un pago registrado', 409);
                }
                throw $e;
            }

            // El número de recibo depende del ID: segunda escritura en la misma transacción.
            Database::update('payments', [
                'receipt_number' => sprintf('REC-%s-%06d', date('Y'), $paymentId),
            ], 'id = ?', [$paymentId]);

            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }

        return $this->getForRequest($requestId, $customerId, 'customer');
    }

    /**
     * El mecánico asignado (o un administrador) confirma o rechaza un pago
     * pendiente (efectivo).
     *
     * @param string $decision confirmed | rejected
     */
    public function review(int $requestId, int $userId, string $role, string $decision): array
    {
        if (!in_array($decision, ['confirmed', 'rejected'], true)) {
            throw new DomainException('Decisión inválida', 400);
        }

        $request = $this->fetchRequest($requestId);
        if ($role === 'mechanic' && (int)$request['mechanic_id'] !== $userId) {
            throw new DomainException('No estás asignado a esta solicitud de servicio', 403);
        }

        $affected = Database::update('payments', [
            'status'      => $decision,
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ], 'service_request_id = ? AND status = ?', [$requestId, 'pending']);

        if ($affected === 0) {
            throw new DomainException('No hay un pago pendiente para este servicio', 409);
        }

        return $this->getForRequest($requestId, $userId, $role);
    }

    /**
     * Lista pagos. Para un mecánico, solo los de sus servicios; para un
     * cliente, solo los suyos; para un administrador, todos.
     *
     * @param array $filters status, method
     */
    public function list(int $userId, string $role, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT p.id, p.receipt_number, p.method, p.status, p.amount, p.card_last4, p.pse_bank,
                       p.created_at, p.reviewed_at,
                       sr.id AS service_request_id, sr.service_code, st.name AS service_type_name,
                       (SELECT COALESCE(SUM(sp.quantity * sp.unit_price), 0)
                          FROM service_request_parts sp WHERE sp.service_request_id = sr.id) AS parts_total,
                       c.first_name AS customer_first_name, c.last_name AS customer_last_name,
                       m.first_name AS mechanic_first_name, m.last_name AS mechanic_last_name
                FROM payments p
                JOIN service_requests sr ON sr.id = p.service_request_id
                JOIN service_types st ON st.code = sr.emergency_type
                JOIN users c ON c.id = sr.customer_id
                LEFT JOIN users m ON m.id = sr.mechanic_id
                WHERE 1 = 1";
        $params = [];

        if ($role === 'mechanic') {
            $sql .= ' AND sr.mechanic_id = ?';
            $params[] = $userId;
        } elseif ($role === 'customer') {
            $sql .= ' AND sr.customer_id = ?';
            $params[] = $userId;
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['method'])) {
            $sql .= ' AND p.method = ?';
            $params[] = $filters['method'];
        }

        $total = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM ({$sql}) AS filtered", $params)['total'] ?? 0);
        $sql .= ' ORDER BY p.created_at DESC LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);

        return ['items' => $this->castAll(Database::fetchAll($sql, $params), ['amount', 'parts_total']), 'total' => $total];
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────

    private function fetchRequest(int $requestId, bool $forUpdate = false): array
    {
        $request = Database::fetchOne(
            'SELECT sr.id, sr.service_code, sr.status, sr.description, sr.completed_at,
                    sr.customer_id, sr.mechanic_id, sr.final_cost, sr.estimated_cost,
                    st.name AS service_type_name, st.base_price,
                    c.first_name AS customer_first_name, c.last_name AS customer_last_name,
                    c.email AS customer_email, c.id_number AS customer_id_number,
                    m.first_name AS mechanic_first_name, m.last_name AS mechanic_last_name,
                    v.license_plate AS vehicle_license_plate
             FROM service_requests sr
             JOIN service_types st ON st.code = sr.emergency_type
             JOIN users c ON c.id = sr.customer_id
             LEFT JOIN users m ON m.id = sr.mechanic_id
             LEFT JOIN vehicles v ON v.id = sr.vehicle_id
             WHERE sr.id = ? AND sr.deleted_at IS NULL' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$requestId]
        );

        if ($request === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        return $request;
    }

    private function assertCanView(array $request, int $userId, string $role): void
    {
        $allowed = match ($role) {
            'admin'    => true,
            'customer' => (int)$request['customer_id'] === $userId,
            'mechanic' => (int)$request['mechanic_id'] === $userId,
            default    => false,
        };

        if (!$allowed) {
            // 404 en vez de 403: no revela si existe una solicitud ajena con ese ID
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }
    }

    private function laborAmount(array $request): float
    {
        return (float)($request['final_cost'] ?? $request['estimated_cost'] ?? $request['base_price']);
    }
}
