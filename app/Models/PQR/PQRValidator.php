<?php

namespace App\Models\PQR;

use App\Core\Request;

/**
 * Validador de PQR
 *
 * Valida los datos de peticiones, quejas, reclamos y sugerencias.
 */
class PQRValidator
{
    /**
     * Tipos de PQR válidos
     */
    private const VALID_TYPES = [
        'peticion',
        'queja',
        'reclamo',
        'sugerencia',
    ];

    /**
     * Estados válidos de un PQR
     */
    private const VALID_STATUSES = [
        'pending',
        'in_review',
        'resolved',
        'rejected',
    ];

    /**
     * Estados terminales: un ticket en uno de estos estados ya fue cerrado por
     * el administrador y no admite más transiciones de estado.
     */
    private const TERMINAL_STATUSES = ['resolved', 'rejected'];

    /**
     * Transiciones de estado válidas para updateStatus() (acción administrativa
     * de solo-estado). Derivado del flujo ya implementado en
     * AdminPQRPage.tsx: desde 'pending' el admin puede marcar 'in_review' o
     * 'rejected' (o resolver, vía respond()); desde 'in_review' solo puede
     * rechazar (o resolver, vía respond()) — la UI nunca ofrece "marcar en
     * revisión" para un ticket que ya está en revisión.
     */
    private const VALID_TRANSITIONS = [
        'pending'   => ['in_review', 'rejected', 'resolved'],
        'in_review' => ['rejected', 'resolved'],
    ];

    /**
     * Valida los datos para la creación de un PQR.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateCreateRequest(Request $request): array
    {
        $errors = [];

        $type = $request->input('type');
        if (empty($type)) {
            $errors['type'] = 'El tipo de PQR es requerido';
        } elseif (!in_array($type, self::VALID_TYPES, true)) {
            $errors['type'] = 'Tipo inválido. Tipos válidos: ' . implode(', ', self::VALID_TYPES);
        }

        $subject = $request->input('subject');
        if (empty($subject)) {
            $errors['subject'] = 'El asunto es requerido';
        } elseif (strlen($subject) > 150) {
            $errors['subject'] = 'El asunto no debe superar los 150 caracteres';
        }

        $description = $request->input('description');
        if (empty($description)) {
            $errors['description'] = 'La descripción es requerida';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Valida los datos para el cambio de estado por parte del administrador.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateStatusUpdateRequest(Request $request): array
    {
        $errors = [];

        $status = $request->input('status');
        if (empty($status)) {
            $errors['status'] = 'El estado es requerido';
        } elseif (!in_array($status, self::VALID_STATUSES, true)) {
            $errors['status'] = 'Estado inválido. Estados válidos: ' . implode(', ', self::VALID_STATUSES);
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Valida los datos para la respuesta del administrador.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateRespondRequest(Request $request): array
    {
        $errors = [];

        $response = $request->input('admin_response');
        if (empty($response)) {
            $errors['admin_response'] = 'La respuesta es requerida';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Valida si una transición de estado es permitida.
     *
     * @param string $currentStatus Estado actual del ticket
     * @param string $newStatus     Estado destino solicitado
     * @return array                ['valid' => bool, 'error' => string|null]
     */
    public static function validateStatusTransition(string $currentStatus, string $newStatus): array
    {
        if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
            return [
                'valid' => false,
                'error' => "No se puede transicionar desde el estado terminal '{$currentStatus}'"
            ];
        }

        if (!isset(self::VALID_TRANSITIONS[$currentStatus])) {
            return [
                'valid' => false,
                'error' => "Estado actual inválido '{$currentStatus}'"
            ];
        }

        if (!in_array($newStatus, self::VALID_TRANSITIONS[$currentStatus], true)) {
            return [
                'valid' => false,
                'error' => "No se puede transicionar de '{$currentStatus}' a '{$newStatus}'"
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Retorna los tipos de PQR válidos.
     *
     * @return array
     */
    public static function getValidTypes(): array
    {
        return self::VALID_TYPES;
    }

    /**
     * Retorna los estados válidos de un PQR.
     *
     * @return array
     */
    public static function getValidStatuses(): array
    {
        return self::VALID_STATUSES;
    }

    /**
     * Genera un código de ticket único a partir del ID insertado.
     *
     * @param int $ticketId ID del PQR recién insertado
     * @return string       Código de ticket con formato PQR-YYYY-NNNNNN
     */
    public static function generateTicketCode(int $ticketId): string
    {
        $year = date('Y');
        $paddedId = str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);
        return "PQR-{$year}-{$paddedId}";
    }
}
