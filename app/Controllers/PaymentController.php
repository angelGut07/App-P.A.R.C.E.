<?php

namespace App\Controllers;

use App\Core\Controller;
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
     * POST /api/service-requests/{id}/payment (cliente)
     * Body: method (cash|card|pse), card_last4 (si es tarjeta), pse_bank (si es PSE)
     */
    public function store(Request $request, int $id): Response
    {
        try {
            $json = RequestValidator::parseJsonBody($request);
            if (!$json['valid']) {
                return ResponseFormatter::error($json['error'], null, $json['statusCode']);
            }

            $method = $request->input('method');
            $cardLast4 = $request->input('card_last4');
            $pseBank = $request->input('pse_bank');

            $errors = [];
            if (!in_array($method, PaymentService::METHODS, true)) {
                $errors['method'] = 'Método de pago inválido. Valores válidos: ' . implode(', ', PaymentService::METHODS);
            }
            if ($method === 'card' && !preg_match('/^\d{4}$/', (string)$cardLast4)) {
                $errors['card_last4'] = 'Indica los últimos 4 dígitos de la tarjeta';
            }
            if ($method === 'pse' && (trim((string)$pseBank) === '' || mb_strlen((string)$pseBank) > 60)) {
                $errors['pse_bank'] = 'Selecciona el banco para PSE';
            }
            if ($errors !== []) {
                return ResponseFormatter::validationError($errors);
            }

            $payment = $this->payments->pay(
                $id,
                (int)$request->getAttribute('userId'),
                $method,
                $method === 'card' ? (string)$cardLast4 : null,
                $method === 'pse' ? trim((string)$pseBank) : null
            );

            return ResponseFormatter::success(['payment' => $payment], 'Pago registrado correctamente', 201);
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
