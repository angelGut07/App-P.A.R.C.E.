<?php

namespace App\Models\ServiceRequest;

use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\DomainException;

/**
 * Servicio de Calificación Mecánico → Cliente
 *
 * El mecánico califica al cliente de un servicio completado. Ambos sentidos
 * de calificación viven en la tabla `ratings` (rating_type
 * customer_to_mechanic | mechanic_to_customer); el cliente→mecánico lo
 * registra ServiceRequestService::rate().
 */
class MechanicRatingService
{
    private const RATING_TYPE = 'mechanic_to_customer';

    /**
     * El mecánico asignado califica al cliente de una solicitud completada.
     *
     * @param int   $requestId  ID de la solicitud de servicio
     * @param int   $mechanicId ID del mecánico autenticado (nunca del body)
     * @param array $data       ['score' => int, 'comment' => ?string,
     *                           'punctuality_score' => ?int, 'quality_score' => ?int]
     * @return array             Fila insertada en `ratings`
     * @throws DomainException   404 no existe; 403 no es el mecánico asignado;
     *                            400 no está completada; 409 ya fue calificada
     */
    public function rateCustomer(int $requestId, int $mechanicId, array $data): array
    {
        $request = Database::fetchOne(
            'SELECT id, customer_id, mechanic_id, status FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$requestId]
        );

        if ($request === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        if ((int)$request['mechanic_id'] !== $mechanicId) {
            throw new DomainException('No estás asignado a esta solicitud de servicio', 403);
        }

        if ($request['status'] !== 'completed') {
            throw new DomainException('Solo se pueden calificar solicitudes en estado completado', 400);
        }

        // SELECT-then-INSERT: da el 409 "esperado" en el caso común. La
        // protección real contra la carrera de dos envíos casi simultáneos es
        // el UNIQUE(service_request_id, rating_type) de la propia tabla,
        // capturado abajo como "Duplicate entry" — mismo patrón de doble
        // guardia que AuthService::register() (email duplicado) y
        // MechanicApplicationService::approve() (rol duplicado).
        $existing = Database::fetchOne(
            'SELECT id FROM ratings WHERE service_request_id = ? AND rating_type = ?',
            [$requestId, self::RATING_TYPE]
        );

        if ($existing !== null) {
            throw new DomainException('Ya calificaste a este cliente para esta solicitud', 409);
        }

        try {
            $ratingId = Database::insert('ratings', [
                'service_request_id' => $requestId,
                'rater_id'            => $mechanicId,
                'ratee_id'            => (int)$request['customer_id'],
                'rating_type'         => self::RATING_TYPE,
                'score'               => $data['score'],
                'punctuality_score'   => $data['punctuality_score'] ?? null,
                'quality_score'       => $data['quality_score'] ?? null,
                'comment'             => $data['comment'] ?? null,
            ]);
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new DomainException('Ya calificaste a este cliente para esta solicitud', 409);
            }
            throw $e;
        }

        return Database::fetchOne('SELECT * FROM ratings WHERE id = ?', [$ratingId]);
    }
}
