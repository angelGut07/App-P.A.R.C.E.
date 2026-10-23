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
        return $data;
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

        return $errors;
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
