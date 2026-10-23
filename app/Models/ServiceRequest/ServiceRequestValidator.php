<?php

namespace App\Models\ServiceRequest;

use App\Core\Request;

/**
 * Validador de Solicitudes de Servicio
 *
 * Valida los datos de solicitudes de servicio para creación y actualización.
 * Aplica reglas de negocio, integridad de datos y restricciones del ciclo de vida.
 */
class ServiceRequestValidator
{
    /**
     * Tipos de emergencia válidos aceptados por el sistema
     */
    private const VALID_EMERGENCY_TYPES = [
        'tire',
        'battery',
        'fuel',
        'lockout',
        'tow',
        'engine',
        'other'
    ];

    /**
     * Estados válidos de una solicitud de servicio
     */
    private const VALID_STATUSES = [
        'pending',
        'assigned',
        'in_progress',
        'completed',
        'cancelled',
        'expired'
    ];

    /**
     * Estados activos (no terminales) — la solicitud aún puede evolucionar
     */
    private const ACTIVE_STATUSES = [
        'pending',
        'assigned',
        'in_progress'
    ];

    /**
     * Estados terminales — no se puede realizar ninguna transición desde estos estados
     */
    private const TERMINAL_STATUSES = [
        'completed',
        'cancelled',
        'expired'
    ];

    /**
     * Prioridades válidas para una solicitud de servicio
     */
    private const VALID_PRIORITIES = [
        'normal',
        'urgent',
        'critical'
    ];

    /**
     * Valida los datos para la creación de una solicitud de servicio.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateCreateRequest(Request $request): array
    {
        $errors = [];

        // vehicle_id (requerido)
        $vehicleId = $request->input('vehicle_id');
        if ($vehicleId === null || $vehicleId === '') {
            $errors['vehicle_id'] = 'El ID del vehículo es requerido';
        } elseif (!is_numeric($vehicleId) || $vehicleId <= 0) {
            $errors['vehicle_id'] = 'El ID del vehículo debe ser un entero positivo';
        }

        // emergency_type (requerido)
        $emergencyType = $request->input('emergency_type');
        if (empty($emergencyType)) {
            $errors['emergency_type'] = 'El tipo de emergencia es requerido';
        } elseif (!in_array(strtolower($emergencyType), self::VALID_EMERGENCY_TYPES, true)) {
            $errors['emergency_type'] = 'Tipo de emergencia inválido. Tipos válidos: ' . implode(', ', self::VALID_EMERGENCY_TYPES);
        }

        // description (requerida, mínimo 10 caracteres)
        $description = $request->input('description');
        if (empty($description)) {
            $errors['description'] = 'La descripción es requerida';
        } elseif (strlen($description) < 10) {
            $errors['description'] = 'La descripción debe tener al menos 10 caracteres';
        } elseif (strlen($description) > 5000) {
            $errors['description'] = 'La descripción no debe superar los 5000 caracteres';
        }

        // latitude (requerida, rango -90 a 90)
        $latitude = $request->input('latitude');
        if ($latitude === null || $latitude === '') {
            $errors['latitude'] = 'La latitud es requerida';
        } elseif (!is_numeric($latitude)) {
            $errors['latitude'] = 'La latitud debe ser un número';
        } else {
            $lat = (float)$latitude;
            if ($lat < -90 || $lat > 90) {
                $errors['latitude'] = 'La latitud debe estar entre -90 y 90';
            }
        }

        // longitude (requerida, rango -180 a 180)
        $longitude = $request->input('longitude');
        if ($longitude === null || $longitude === '') {
            $errors['longitude'] = 'La longitud es requerida';
        } elseif (!is_numeric($longitude)) {
            $errors['longitude'] = 'La longitud debe ser un número';
        } else {
            $lon = (float)$longitude;
            if ($lon < -180 || $lon > 180) {
                $errors['longitude'] = 'La longitud debe estar entre -180 y 180';
            }
        }

        // priority (opcional, por defecto 'normal')
        $priority = $request->input('priority');
        if ($priority !== null && !in_array(strtolower($priority), self::VALID_PRIORITIES, true)) {
            $errors['priority'] = 'Prioridad inválida. Prioridades válidas: ' . implode(', ', self::VALID_PRIORITIES);
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Valida los datos para la actualización de una solicitud de servicio.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateUpdateRequest(Request $request): array
    {
        $errors = [];

        // En las actualizaciones los campos son opcionales, pero deben ser válidos si se envían

        // description
        $description = $request->input('description');
        if ($description !== null) {
            if (strlen($description) < 10) {
                $errors['description'] = 'La descripción debe tener al menos 10 caracteres';
            } elseif (strlen($description) > 5000) {
                $errors['description'] = 'La descripción no debe superar los 5000 caracteres';
            }
        }

        // latitude
        $latitude = $request->input('latitude');
        if ($latitude !== null) {
            if (!is_numeric($latitude)) {
                $errors['latitude'] = 'La latitud debe ser un número';
            } else {
                $lat = (float)$latitude;
                if ($lat < -90 || $lat > 90) {
                    $errors['latitude'] = 'La latitud debe estar entre -90 y 90';
                }
            }
        }

        // longitude
        $longitude = $request->input('longitude');
        if ($longitude !== null) {
            if (!is_numeric($longitude)) {
                $errors['longitude'] = 'La longitud debe ser un número';
            } else {
                $lon = (float)$longitude;
                if ($lon < -180 || $lon > 180) {
                    $errors['longitude'] = 'La longitud debe estar entre -180 y 180';
                }
            }
        }

        // priority
        $priority = $request->input('priority');
        if ($priority !== null && !in_array(strtolower($priority), self::VALID_PRIORITIES, true)) {
            $errors['priority'] = 'Prioridad inválida. Prioridades válidas: ' . implode(', ', self::VALID_PRIORITIES);
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Valida los datos de una solicitud de cancelación.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateCancellationRequest(Request $request): array
    {
        $errors = [];

        // cancellation_reason (requerido, mínimo 10 caracteres)
        $reason = $request->input('cancellation_reason');
        if (empty($reason)) {
            $errors['cancellation_reason'] = 'El motivo de cancelación es requerido';
        } elseif (strlen($reason) < 10) {
            $errors['cancellation_reason'] = 'El motivo de cancelación debe tener al menos 10 caracteres';
        } elseif (strlen($reason) > 1000) {
            $errors['cancellation_reason'] = 'El motivo de cancelación no debe superar los 1000 caracteres';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Valida los datos de una solicitud de calificación.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateRatingRequest(Request $request): array
    {
        $errors = [];

        // customer_rating (requerida, entre 1 y 5)
        $rating = $request->input('customer_rating');
        if ($rating === null || $rating === '') {
            $errors['customer_rating'] = 'La calificación es requerida';
        } elseif (!is_numeric($rating)) {
            $errors['customer_rating'] = 'La calificación debe ser un número';
        } else {
            $ratingInt = (int)$rating;
            if ($ratingInt < 1 || $ratingInt > 5) {
                $errors['customer_rating'] = 'La calificación debe estar entre 1 y 5';
            }
        }

        // customer_feedback (opcional, máximo 2000 caracteres)
        $feedback = $request->input('customer_feedback');
        if ($feedback !== null && strlen($feedback) > 2000) {
            $errors['customer_feedback'] = 'El comentario no debe superar los 2000 caracteres';
        }

        // Validar calificación de puntualidad (opcional)
        $punctualityRating = $request->input('punctuality_rating');
        if ($punctualityRating !== null) {
            if (!is_numeric($punctualityRating) || (int)$punctualityRating < 1 || (int)$punctualityRating > 5) {
                $errors['punctuality_rating'] = 'La calificación de puntualidad debe ser un entero entre 1 y 5';
            }
        }

        // Validar calificación de calidad del servicio (opcional)
        $serviceQualityRating = $request->input('service_quality_rating');
        if ($serviceQualityRating !== null) {
            if (!is_numeric($serviceQualityRating) || (int)$serviceQualityRating < 1 || (int)$serviceQualityRating > 5) {
                $errors['service_quality_rating'] = 'La calificación de calidad del servicio debe ser un entero entre 1 y 5';
            }
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Valida los datos para que un mecánico califique al cliente de una
     * solicitud completada (tabla `ratings`, rating_type='mechanic_to_customer').
     * Nombres de campo distintos a validateRatingRequest() a propósito: mapean
     * 1:1 a las columnas reales de `ratings` (score/comment/punctuality_score/
     * quality_score), no a las de `service_requests`.
     *
     * @param Request $request Solicitud HTTP
     * @return array           ['valid' => bool, 'errors' => array]
     */
    public static function validateMechanicRatingRequest(Request $request): array
    {
        $errors = [];

        $score = $request->input('score');
        if ($score === null || $score === '') {
            $errors['score'] = 'La calificación es requerida';
        } elseif (!is_numeric($score)) {
            $errors['score'] = 'La calificación debe ser un número';
        } else {
            $scoreInt = (int)$score;
            if ($scoreInt < 1 || $scoreInt > 5) {
                $errors['score'] = 'La calificación debe estar entre 1 y 5';
            }
        }

        $comment = $request->input('comment');
        if ($comment !== null && strlen($comment) > 2000) {
            $errors['comment'] = 'El comentario no debe superar los 2000 caracteres';
        }

        $punctualityScore = $request->input('punctuality_score');
        if ($punctualityScore !== null) {
            if (!is_numeric($punctualityScore) || (int)$punctualityScore < 1 || (int)$punctualityScore > 5) {
                $errors['punctuality_score'] = 'La calificación de puntualidad debe ser un entero entre 1 y 5';
            }
        }

        $qualityScore = $request->input('quality_score');
        if ($qualityScore !== null) {
            if (!is_numeric($qualityScore) || (int)$qualityScore < 1 || (int)$qualityScore > 5) {
                $errors['quality_score'] = 'La calificación de calidad debe ser un entero entre 1 y 5';
            }
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Valida si una transición de estado es permitida según las reglas del ciclo de vida.
     *
     * @param string $currentStatus Estado actual de la solicitud
     * @param string $newStatus     Nuevo estado deseado
     * @return array                Resultado ['valid' => bool, 'error' => string|null]
     */
    public static function validateStatusTransition(string $currentStatus, string $newStatus): array
    {
        // No se puede transicionar desde un estado terminal
        if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
            return [
                'valid' => false,
                'error' => "No se puede transicionar desde el estado terminal '{$currentStatus}'"
            ];
        }

        // Definir transiciones válidas por estado
        $validTransitions = [
            'pending'     => ['assigned', 'cancelled', 'expired'],
            'assigned'    => ['in_progress', 'cancelled'],
            'in_progress' => ['completed']
        ];

        if (!isset($validTransitions[$currentStatus])) {
            return [
                'valid' => false,
                'error' => "Estado actual inválido '{$currentStatus}'"
            ];
        }

        if (!in_array($newStatus, $validTransitions[$currentStatus], true)) {
            return [
                'valid' => false,
                'error' => "No se puede transicionar de '{$currentStatus}' a '{$newStatus}'"
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Verifica si un estado es activo (no terminal).
     *
     * @param string $status Estado a verificar
     * @return bool          Verdadero si el estado es activo
     */
    public static function isActiveStatus(string $status): bool
    {
        return in_array($status, self::ACTIVE_STATUSES, true);
    }

    /**
     * Verifica si un estado es terminal.
     *
     * @param string $status Estado a verificar
     * @return bool          Verdadero si el estado es terminal
     */
    public static function isTerminalStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Genera el código de servicio único para una solicitud.
     *
     * Formato: SR-YYYY-NNNNNN (ej. SR-2024-000123)
     *
     * @param int $requestId ID de la solicitud
     * @return string        Código de servicio generado
     */
    public static function generateServiceCode(int $requestId): string
    {
        $year      = date('Y');
        $paddedId  = str_pad($requestId, 6, '0', STR_PAD_LEFT);
        return "SR-{$year}-{$paddedId}";
    }

    /**
     * Retorna los tipos de emergencia válidos.
     *
     * @return array Lista de tipos de emergencia válidos
     */
    public static function getValidEmergencyTypes(): array
    {
        return self::VALID_EMERGENCY_TYPES;
    }

    /**
     * Retorna los estados válidos de una solicitud.
     *
     * @return array Lista de estados válidos
     */
    public static function getValidStatuses(): array
    {
        return self::VALID_STATUSES;
    }

    /**
     * Retorna los estados activos (no terminales).
     *
     * @return array Lista de estados activos
     */
    public static function getActiveStatuses(): array
    {
        return self::ACTIVE_STATUSES;
    }

    /**
     * Retorna los estados terminales.
     *
     * @return array Lista de estados terminales
     */
    public static function getTerminalStatuses(): array
    {
        return self::TERMINAL_STATUSES;
    }

    /**
     * Retorna las prioridades válidas.
     *
     * @return array Lista de prioridades válidas
     */
    public static function getValidPriorities(): array
    {
        return self::VALID_PRIORITIES;
    }
}
