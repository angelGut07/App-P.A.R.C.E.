<?php

namespace App\Models\MechanicApplication;

use App\Core\Request;

/**
 * Validador de Solicitudes de Mecánico
 *
 * Valida los datos de entrada del flujo de solicitud + revisión administrativa
 * para obtener el rol `mechanic`.
 */
class MechanicApplicationValidator
{
    /**
     * Estados válidos de una solicitud (ENUM mechanic_applications.status).
     */
    private const VALID_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    /** Forma de un código de service_types (fuel, tire, battery…) */
    private const SERVICE_CODE_PATTERN = '/^[a-z_]{2,20}$/';

    /** Tope de servicios por solicitud (hay 7 tipos de servicio) */
    private const MAX_SERVICES = 20;

    /**
     * Valida los datos para crear una solicitud de mecánico.
     *
     * @param Request $request Solicitud HTTP
     * @return array           ['valid' => bool, 'errors' => array]
     */
    public static function validateCreateRequest(Request $request): array
    {
        $errors = self::validateData(self::extractData($request));

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Campos de la solicitud presentes en la petición (los mismos que acepta
     * el registro público con rol mecánico).
     */
    public static function extractData(Request $request): array
    {
        $data = [];
        foreach (['specialty', 'certification_title', 'certification_document_url', 'justification'] as $key) {
            $value = $request->input($key);
            if ($value !== null && trim((string)$value) !== '') {
                $data[$key] = trim((string)$value);
            }
        }
        if ($request->input('years_experience') !== null && $request->input('years_experience') !== '') {
            $data['years_experience'] = $request->input('years_experience');
        }
        $services = self::normalizeServiceCodes($request->input('services'));
        if ($services !== null) {
            $data['services'] = $services;
        }
        return $data;
    }

    /**
     * Códigos de servicio en minúsculas y sin repetir, o null si el valor no
     * es una lista. La existencia de cada código la revisa el servicio.
     */
    private static function normalizeServiceCodes(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $codes = [];
        foreach ($value as $item) {
            $codes[] = is_scalar($item) ? strtolower(trim((string)$item)) : '';
        }

        return array_values(array_unique($codes));
    }

    /** Lista de códigos con forma válida y dentro del tope */
    private static function areValidServiceCodes(array $codes): bool
    {
        if (count($codes) > self::MAX_SERVICES) {
            return false;
        }

        foreach ($codes as $code) {
            if (!preg_match(self::SERVICE_CODE_PATTERN, $code)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valida los datos de una solicitud de mecánico.
     *
     * @return array Errores por campo (vacío si todo es válido)
     */
    public static function validateData(array $data): array
    {
        $errors = [];

        if (empty($data['certification_title'])) {
            $errors['certification_title'] = 'El título o certificación del mecánico es requerido';
        } elseif (mb_strlen($data['certification_title']) > 150) {
            $errors['certification_title'] = 'El título no debe superar los 150 caracteres';
        }

        if (isset($data['specialty']) && mb_strlen($data['specialty']) > 100) {
            $errors['specialty'] = 'La especialidad no debe superar los 100 caracteres';
        }

        if (isset($data['years_experience'])) {
            $years = filter_var($data['years_experience'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 60]]);
            if ($years === false) {
                $errors['years_experience'] = 'Los años de experiencia deben ser un número entre 0 y 60';
            }
        }

        if (isset($data['certification_document_url'])
            && (mb_strlen($data['certification_document_url']) > 500 || !filter_var($data['certification_document_url'], FILTER_VALIDATE_URL))) {
            $errors['certification_document_url'] = 'La URL del documento debe ser válida y no superar los 500 caracteres';
        }

        if (isset($data['justification']) && mb_strlen($data['justification']) > 2000) {
            $errors['justification'] = 'La justificación no debe superar los 2000 caracteres';
        }

        if (empty($data['services'])) {
            $errors['services'] = 'Selecciona al menos un servicio que puedas prestar';
        } elseif (!self::areValidServiceCodes($data['services'])) {
            $errors['services'] = 'Hay servicios no válidos en la solicitud';
        }

        return $errors;
    }

    /**
     * Valida los datos para aprobar una solicitud (administrador):
     * approved_services es opcional (sin él se aprueban todos los pedidos) y
     * review_notes explica los servicios rechazados.
     *
     * @param Request $request Solicitud HTTP
     * @return array           ['valid' => bool, 'errors' => array, 'data' => array]
     */
    public static function validateApproveRequest(Request $request): array
    {
        $errors = [];
        $data = ['approved_services' => null, 'review_notes' => null];

        if ($request->input('approved_services') !== null) {
            $services = self::normalizeServiceCodes($request->input('approved_services'));
            if ($services === null || $services === [] || !self::areValidServiceCodes($services)) {
                $errors['approved_services'] = 'Selecciona al menos un servicio válido para aprobar';
            } else {
                $data['approved_services'] = $services;
            }
        }

        $notes = $request->input('review_notes');
        if ($notes !== null && !is_string($notes)) {
            $errors['review_notes'] = 'La nota de revisión no es válida';
        } elseif (is_string($notes) && mb_strlen($notes) > 1000) {
            $errors['review_notes'] = 'La nota de revisión no debe superar los 1000 caracteres';
        } elseif (is_string($notes) && trim($notes) !== '') {
            $data['review_notes'] = trim($notes);
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
            'data'   => $data,
        ];
    }

    /**
     * Valida los datos para rechazar una solicitud (administrador).
     *
     * @param Request $request Solicitud HTTP
     * @return array           ['valid' => bool, 'errors' => array]
     */
    public static function validateRejectRequest(Request $request): array
    {
        $errors = [];

        $reason = $request->input('rejection_reason');
        if (empty($reason) || trim((string)$reason) === '') {
            $errors['rejection_reason'] = 'El motivo de rechazo es requerido';
        } elseif (strlen($reason) > 1000) {
            $errors['rejection_reason'] = 'El motivo de rechazo no debe superar los 1000 caracteres';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Retorna los estados válidos de una solicitud.
     *
     * @return array
     */
    public static function getValidStatuses(): array
    {
        return self::VALID_STATUSES;
    }
}
