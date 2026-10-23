<?php

namespace App\Models\Survey;

use App\Core\Request;

/**
 * Validador de Encuestas
 *
 * Valida los datos de las encuestas de satisfacción posteriores al servicio.
 */
class SurveyValidator
{
    /**
     * Valida los datos para la creación de una encuesta.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateCreateRequest(Request $request): array
    {
        $errors = [];

        $serviceRequestId = $request->input('service_request_id');
        if (empty($serviceRequestId) || !is_numeric($serviceRequestId)) {
            $errors['service_request_id'] = 'El ID de la solicitud de servicio es requerido';
        }

        $overallSatisfaction = $request->input('overall_satisfaction');
        if ($overallSatisfaction === null || $overallSatisfaction === '') {
            $errors['overall_satisfaction'] = 'La calificación general es requerida';
        } elseif (!is_numeric($overallSatisfaction) || (int)$overallSatisfaction < 1 || (int)$overallSatisfaction > 5) {
            $errors['overall_satisfaction'] = 'La calificación general debe estar entre 1 y 5';
        }

        $wouldRecommend = $request->input('would_recommend');
        if ($wouldRecommend === null || $wouldRecommend === '') {
            $errors['would_recommend'] = 'Indique si recomendaría el servicio';
        }

        $comments = $request->input('comments');
        if ($comments !== null && strlen($comments) > 2000) {
            $errors['comments'] = 'Los comentarios no deben superar los 2000 caracteres';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }
}
