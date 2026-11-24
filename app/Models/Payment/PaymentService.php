<?php

namespace App\Models\Payment;

use App\Core\Database;
use App\Core\DomainException;
use App\Models\Concerns\CastsColumns;
use App\Models\Inventory\ServicePartService;

/**
 * Servicio de Pagos
 *
 * Cobro de un servicio completado: mano de obra (costo final del mecánico o,
 * si no lo fijó, el precio base del tipo de servicio) más los repuestos
 * usados.
 *
 * - Efectivo: queda pendiente hasta que el mecánico (o un administrador)
 *   confirma que lo recibió. No depende de internet: es el respaldo cuando
 *   la señal falla.
 * - Tarjeta y PSE: Mercado Pago Checkout Pro. El cliente paga en la página
 *   de Mercado Pago y vuelve a /payment. El estado nunca se toma de la URL
 *   de retorno: siempre se consulta el pago en la API de Mercado Pago y se
 *   comprueban el recibo (external_reference) y el monto.
 *
 * Un servicio tiene una sola fila de pago (service_request_id es UNIQUE).
 * Si un intento falla, el cliente reintenta sobre la misma fila (con otro
 * método si quiere) y el número de recibo se conserva.
 */
class PaymentService
{
    use CastsColumns;

    public const METHODS = ['cash', 'card', 'pse'];
    /** Métodos que se pagan en Mercado Pago. */
    public const ONLINE_METHODS = ['card', 'pse'];

    private ServicePartService $parts;
    private MercadoPagoClient $mercadoPago;

    public function __construct(?MercadoPagoClient $mercadoPago = null)
    {
        $this->parts = new ServicePartService();
        $this->mercadoPago = $mercadoPago ?? new MercadoPagoClient();
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
     * El cliente elige pagar en efectivo: el pago queda pendiente hasta que
     * el mecánico confirma que recibió el dinero.
     *
     * @throws DomainException 403/404/409 según la regla que no se cumpla
     */
    public function payCash(int $requestId, int $customerId): array
    {
        $this->preparePendingPayment($requestId, $customerId, 'cash');

        return $this->getForRequest($requestId, $customerId, 'customer');
    }

    /**
     * El cliente elige tarjeta o PSE: se deja el pago pendiente y se crea la
     * preferencia de Checkout Pro. El frontend redirige a checkout_url.
     *
     * @return array{checkout_url: string, payment: array}
     * @throws DomainException 503/502 si Mercado Pago no responde (el frontend ofrece efectivo)
     */
    public function startCheckout(int $requestId, int $customerId, string $method): array
    {
        if (!in_array($method, self::ONLINE_METHODS, true)) {
            throw new DomainException('Método de pago en línea inválido', 400);
        }
        if (!MercadoPagoClient::isConfigured()) {
            throw new DomainException(MercadoPagoClient::UNAVAILABLE, 503);
        }

        $payment = $this->preparePendingPayment($requestId, $customerId, $method);
        $request = $this->fetchRequest($requestId);

        // La llamada a Mercado Pago va FUERA de la transacción: no se deja la
        // fila bloqueada mientras se espera una respuesta por internet.
        $preference = $this->mercadoPago->createPreference(
            $this->buildPreference($request, $payment, $method)
        );

        if (empty($preference['init_point'])) {
            error_log('PaymentService: Mercado Pago no devolvió init_point para ' . $payment['receipt_number']);
            throw new DomainException(MercadoPagoClient::UNAVAILABLE, 502);
        }

        Database::update('payments', ['mp_preference_id' => (string)$preference['id']], 'id = ?', [$payment['id']]);

        return [
            'checkout_url' => $preference['init_point'],
            'payment'      => $this->getForRequest($requestId, $customerId, 'customer'),
        ];
    }

    /**
     * El cliente volvió de Mercado Pago con ?payment_id=... en la URL. Se
     * consulta ese pago en la API (nunca se confía en el status de la URL)
     * y se aplica si corresponde a este servicio.
     */
    public function verifyReturn(int $requestId, int $customerId, string $mpPaymentId): array
    {
        $request = $this->fetchRequest($requestId);
        if ((int)$request['customer_id'] !== $customerId) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        $mpPayment = $this->mercadoPago->getPayment($mpPaymentId);
        $this->applyMercadoPagoPayment($mpPayment, $requestId);

        return $this->getForRequest($requestId, $customerId, 'customer');
    }

    /**
     * Webhook: Mercado Pago avisa que un pago cambió. Llega sin sesión, así
     * que solo se usa el ID para consultar la API; el contenido del aviso
     * no se cree.
     *
     * @return bool true si el pago correspondía a un servicio de P.A.R.C.E
     */
    public function handleNotification(string $mpPaymentId): bool
    {
        $mpPayment = $this->mercadoPago->getPayment($mpPaymentId);

        return $this->applyMercadoPagoPayment($mpPayment, null);
    }

    /**
     * El mecánico asignado (o un administrador) confirma o rechaza un pago
     * pendiente en efectivo. Los pagos de Mercado Pago los confirma la API,
     * no una persona.
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
        ], 'service_request_id = ? AND status = ? AND method = ?', [$requestId, 'pending', 'cash']);

        if ($affected === 0) {
            throw new DomainException('No hay un pago en efectivo pendiente para este servicio', 409);
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

    /**
     * Deja la fila de pago del servicio en "pendiente" con el método elegido.
     * Si no existe la crea (con su número de recibo); si existe y no está
     * confirmada la reutiliza (reintento o cambio de método).
     *
     * @return array Fila de payments
     */
    private function preparePendingPayment(int $requestId, int $customerId, string $method): array
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

            $amount = $this->totalAmount($request);
            $existing = Database::fetchOne(
                'SELECT * FROM payments WHERE service_request_id = ? FOR UPDATE',
                [$requestId]
            );

            if ($existing === null) {
                $paymentId = Database::insert('payments', [
                    'service_request_id' => $requestId,
                    'receipt_number'     => 'PENDIENTE-' . $requestId,
                    'method'             => $method,
                    'status'             => 'pending',
                    'amount'             => $amount,
                ]);
                // El número de recibo depende del ID: segunda escritura en la misma transacción.
                Database::update('payments', [
                    'receipt_number' => sprintf('REC-%s-%06d', date('Y'), $paymentId),
                ], 'id = ?', [$paymentId]);
            } else {
                if ($existing['status'] === 'confirmed') {
                    throw new DomainException('Este servicio ya está pagado', 409);
                }
                $paymentId = (int)$existing['id'];
                // Reintento: se limpian los datos del intento anterior. El
                // mp_payment_id viejo se borra para que un aviso tardío de ese
                // intento no se confunda con el nuevo (ver applyMercadoPagoPayment).
                Database::update('payments', [
                    'method'           => $method,
                    'status'           => 'pending',
                    'amount'           => $amount,
                    'card_last4'       => null,
                    'pse_bank'         => null,
                    'mp_preference_id' => null,
                    'mp_payment_id'    => null,
                    'mp_status_detail' => null,
                    'reviewed_by'      => null,
                    'reviewed_at'      => null,
                ], 'id = ?', [$paymentId]);
            }

            $payment = Database::fetchOne('SELECT * FROM payments WHERE id = ?', [$paymentId]);
            Database::commit();

            return $payment;
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /** Cuerpo de la preferencia de Checkout Pro. */
    private function buildPreference(array $request, array $payment, string $method): array
    {
        $appUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $returnUrl = $appUrl . '/payment?request=' . (int)$request['id'];

        // Efecty y similares (ticket/atm) se pagan en efectivo en un punto
        // físico: ya tenemos efectivo directo con el mecánico.
        $excluded = ['ticket', 'atm'];
        $excluded = array_merge($excluded, $method === 'card'
            ? ['bank_transfer']                               // tarjeta: sin PSE
            : ['credit_card', 'debit_card', 'prepaid_card']); // PSE: sin tarjetas

        $preference = [
            'items' => [[
                'id'          => (string)$request['service_code'],
                'title'       => 'P.A.R.C.E · ' . $request['service_type_name'] . ' (' . $request['service_code'] . ')',
                'quantity'    => 1,
                'currency_id' => 'COP',
                'unit_price'  => round((float)$payment['amount'], 2),
            ]],
            'external_reference'   => $payment['receipt_number'],
            'statement_descriptor' => 'PARCE',
            'back_urls'            => [
                'success' => $returnUrl,
                'failure' => $returnUrl,
                'pending' => $returnUrl,
            ],
            'payment_methods' => [
                'excluded_payment_types' => array_map(fn($type) => ['id' => $type], $excluded),
                'installments'           => 1,
            ],
        ];

        // Mercado Pago solo acepta retorno automático y webhook con una URL
        // pública HTTPS. En local (http://localhost) el cliente vuelve con el
        // botón "Volver al sitio" y no hay webhook.
        if (str_starts_with($appUrl, 'https://')) {
            $preference['auto_return'] = 'approved';
            $preference['notification_url'] = $appUrl . '/api/payments/webhook';
        }

        return $preference;
    }

    /**
     * Aplica a la fila de pago lo que dice Mercado Pago, con las tres
     * comprobaciones que impiden marcar como pagado algo que no lo está:
     * el recibo (external_reference) existe, coincide con el servicio
     * esperado y el monto es el mismo que se cobró.
     *
     * @param int|null $expectedRequestId Servicio que se está verificando (null en el webhook)
     * @return bool false si el pago no es de P.A.R.C.E (el webhook lo ignora)
     */
    private function applyMercadoPagoPayment(array $mp, ?int $expectedRequestId): bool
    {
        $mpPaymentId = (string)($mp['id'] ?? '');
        $receipt = (string)($mp['external_reference'] ?? '');

        $payment = $receipt !== ''
            ? Database::fetchOne('SELECT * FROM payments WHERE receipt_number = ?', [$receipt])
            : null;

        if ($payment === null) {
            if ($expectedRequestId !== null) {
                throw new DomainException('El pago no corresponde a este servicio', 409);
            }
            return false;
        }
        if ($expectedRequestId !== null && (int)$payment['service_request_id'] !== $expectedRequestId) {
            throw new DomainException('El pago no corresponde a este servicio', 409);
        }

        $paidAmount = (float)($mp['transaction_amount'] ?? 0);
        if (($mp['currency_id'] ?? '') !== 'COP' || abs($paidAmount - (float)$payment['amount']) > 0.009) {
            error_log("PaymentService: monto o moneda no coinciden en {$receipt} (MP {$mpPaymentId}: {$paidAmount} {$mp['currency_id']})");
            throw new DomainException('El monto pagado no coincide con el valor del servicio', 409);
        }

        $newStatus = match ($mp['status'] ?? '') {
            'approved'                                         => 'confirmed',
            'rejected', 'cancelled', 'refunded', 'charged_back' => 'rejected',
            default                                            => 'pending', // pending, in_process, authorized
        };

        // Un pago confirmado nunca baja de estado: si el cliente pagó bien en
        // un segundo intento, el aviso tardío del primer intento (rechazado)
        // no puede pisar la confirmación.
        if ($payment['status'] === 'confirmed' && $payment['mp_payment_id'] !== $mpPaymentId) {
            if ($newStatus === 'confirmed') {
                error_log("PaymentService: posible pago doble en {$receipt}: ya confirmado con {$payment['mp_payment_id']}, llegó {$mpPaymentId}");
            }
            return true;
        }
        // Mientras está pendiente con otro intento en curso, solo un pago aprobado puede reemplazarlo.
        if ($payment['mp_payment_id'] !== null && $payment['mp_payment_id'] !== $mpPaymentId && $newStatus !== 'confirmed') {
            return true;
        }

        $type = (string)($mp['payment_type_id'] ?? '');
        $method = $type === 'bank_transfer' ? 'pse' : ($type === '' || $type === 'account_money' ? $payment['method'] : 'card');

        Database::update('payments', [
            'method'           => $method,
            'status'           => $newStatus,
            'mp_payment_id'    => $mpPaymentId,
            'mp_status_detail' => isset($mp['status_detail']) ? mb_substr((string)$mp['status_detail'], 0, 100) : null,
            'card_last4'       => $method === 'card' ? ($mp['card']['last_four_digits'] ?? null) : null,
            'pse_bank'         => $method === 'pse'
                ? (mb_substr((string)($mp['transaction_details']['financial_institution'] ?? ''), 0, 60) ?: null)
                : null,
            'reviewed_by'      => null,
            'reviewed_at'      => $newStatus === 'pending' ? null : date('Y-m-d H:i:s'),
        ], 'id = ?', [$payment['id']]);

        return true;
    }

    /** Mano de obra + repuestos de una solicitud. */
    private function totalAmount(array $request): float
    {
        $partsTotal = (float)(Database::fetchOne(
            'SELECT COALESCE(SUM(quantity * unit_price), 0) AS total FROM service_request_parts WHERE service_request_id = ?',
            [(int)$request['id']]
        )['total'] ?? 0);

        return $this->laborAmount($request) + $partsTotal;
    }

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
