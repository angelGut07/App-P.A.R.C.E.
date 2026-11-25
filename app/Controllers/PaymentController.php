<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Payment\PaymentService;
use App\Core\Http\RequestValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;

/**
 * Controlador de Pagos
 *
 * Desglose de cobro y comprobante (RF 5.4), pago del cliente, confirmación
 * del mecánico o administrador y listados por rol.
 *
 * Efectivo: POST /payment. Tarjeta y PSE: POST /payment/checkout devuelve el
 * link de Mercado Pago; al volver, POST /payment/verify consulta el pago en
 * la API. Mercado Pago también avisa por POST /api/payments/webhook.
 */
class PaymentController extends Controller
{
    private PaymentService $payments;

    public function __construct()
    {
        $this->payments = new PaymentService();
    }

    /**
     * GET /api/service-requests/{id}/payment (cliente, mecánico o administrador)
     */
    public function show(Request $request, int $id): Response
    {
        try {
            return ResponseFormatter::success(
                ['payment' => $this->payments->getForRequest($id, (int)$request->getAttribute('userId'), $this->role($request))],
                'Detalle de pago obtenido',
                200
            );
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * POST /api/service-requests/{id}/payment (cliente): pago en efectivo.
     * Body: method = cash
     */
    public function store(Request $request, int $id): Response
    {
        try {
            $json = RequestValidator::parseJsonBody($request);
            if (!$json['valid']) {
                return ResponseFormatter::error($json['error'], null, $json['statusCode']);
            }

            $method = $request->input('method');
            if (in_array($method, PaymentService::ONLINE_METHODS, true)) {
                return ResponseFormatter::validationError([
                    'method' => 'Tarjeta y PSE se pagan en Mercado Pago: usa /payment/checkout',
                ]);
            }
            if ($method !== 'cash') {
                return ResponseFormatter::validationError([
                    'method' => 'Método de pago inválido. Valores válidos: ' . implode(', ', PaymentService::METHODS),
                ]);
            }

            $payment = $this->payments->payCash($id, (int)$request->getAttribute('userId'));

            return ResponseFormatter::success(['payment' => $payment], 'Pago en efectivo registrado', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * POST /api/service-requests/{id}/payment/checkout (cliente)
     * Body: method (card|pse). Responde checkout_url para redirigir a Mercado Pago.
     */
    public function checkout(Request $request, int $id): Response
    {
        try {
            $json = RequestValidator::parseJsonBody($request);
            if (!$json['valid']) {
                return ResponseFormatter::error($json['error'], null, $json['statusCode']);
            }

            $method = $request->input('method');
            if (!in_array($method, PaymentService::ONLINE_METHODS, true)) {
                return ResponseFormatter::validationError([
                    'method' => 'Método inválido. Valores válidos: ' . implode(', ', PaymentService::ONLINE_METHODS),
                ]);
            }

            $result = $this->payments->startCheckout($id, (int)$request->getAttribute('userId'), $method);

            return ResponseFormatter::success($result, 'Link de pago creado', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * POST /api/service-requests/{id}/payment/verify (cliente)
     * Body: payment_id (el que Mercado Pago agregó a la URL de retorno)
     */
    public function verify(Request $request, int $id): Response
    {
        try {
            $json = RequestValidator::parseJsonBody($request);
            if (!$json['valid']) {
                return ResponseFormatter::error($json['error'], null, $json['statusCode']);
            }

            $mpPaymentId = (string)$request->input('payment_id', '');
            if (!preg_match('/^\d{1,30}$/', $mpPaymentId)) {
                return ResponseFormatter::validationError(['payment_id' => 'Identificador de pago inválido']);
            }

            $payment = $this->payments->verifyReturn($id, (int)$request->getAttribute('userId'), $mpPaymentId);

            return ResponseFormatter::success(['payment' => $payment], 'Pago verificado', 200);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * POST /api/payments/webhook (público: lo llama Mercado Pago, sin sesión)
     *
     * Del aviso solo se toma el ID del pago; el estado se consulta en la API,
     * así que un aviso falso no puede marcar nada como pagado. Responde 200
     * a lo que no es de P.A.R.C.E (para que Mercado Pago no reintente) y 5xx
     * si Mercado Pago no respondió (para que sí reintente más tarde).
     */
    public function webhook(Request $request): Response
    {
        try {
            $data = $request->input('data');
            // Formato Webhooks: {"type":"payment","data":{"id":"123"}} (también llega en la URL como
            // ?type=payment&data.id=123, que PHP convierte en data_id). Formato IPN: ?topic=payment&id=123
            $type = (string)($request->input('type') ?? $request->query('type') ?? $request->query('topic') ?? '');
            $mpPaymentId = (string)((is_array($data) ? ($data['id'] ?? null) : null)
                ?? $request->query('data_id') ?? $request->query('id') ?? '');

            if ($type !== 'payment' || !preg_match('/^\d{1,30}$/', $mpPaymentId)) {
                return ResponseFormatter::success(null, 'Aviso ignorado', 200);
            }

            $this->payments->handleNotification($mpPaymentId);

            return ResponseFormatter::success(null, 'Aviso procesado', 200);
        } catch (DomainException $e) {
            // 404/409: el pago no existe o no es nuestro; reintentar no lo cambia
            if (in_array($e->getStatusCode(), [400, 404, 409], true)) {
                error_log('Webhook Mercado Pago ignorado: ' . $e->getMessage());
                return ResponseFormatter::success(null, 'Aviso ignorado', 200);
            }
            return ErrorHandler::handleException($e);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * POST /api/service-requests/{id}/payment/confirm y /reject (mecánico o administrador)
     */
    public function confirm(Request $request, int $id): Response
    {
        return $this->review($request, $id, 'confirmed', 'Pago confirmado');
    }

    public function reject(Request $request, int $id): Response
    {
        return $this->review($request, $id, 'rejected', 'Pago rechazado');
    }

    /**
     * GET /api/payments: pagos del usuario según su rol (todos para administradores)
     */
    public function index(Request $request): Response
    {
        try {
            $pagination = RequestValidator::parsePagination($request);
            $result = $this->payments->list(
                (int)$request->getAttribute('userId'),
                $this->role($request),
                ['status' => $request->query('status'), 'method' => $request->query('method')],
                $pagination['perPage'],
                $pagination['offset']
            );

            return ResponseFormatter::success([
                'payments'   => $result['items'],
                'total'      => $result['total'],
                'page'       => $pagination['page'],
                'perPage'    => $pagination['perPage'],
                'totalPages' => (int)ceil($result['total'] / $pagination['perPage']),
            ], 'Pagos obtenidos', 200);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    private function review(Request $request, int $id, string $decision, string $message): Response
    {
        try {
            $payment = $this->payments->review($id, (int)$request->getAttribute('userId'), $this->role($request), $decision);
            return ResponseFormatter::success(['payment' => $payment], $message, 200);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /** Rol efectivo para pagos: admin | mechanic | customer */
    private function role(Request $request): string
    {
        $role = (string)$request->getAttribute('userRole');
        return in_array($role, ['administrator', 'super_admin'], true) ? 'admin' : $role;
    }
}
