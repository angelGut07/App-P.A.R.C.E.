<?php

namespace App\Models\Survey;

use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\DomainException;

/**
 * Servicio de Encuestas
 *
 * Lógica de negocio para las encuestas de satisfacción posteriores al servicio:
 * creación por parte del cliente y consulta por parte del cliente y del administrador.
 */
class SurveyService
{
    /**
     * Crea una encuesta para una solicitud de servicio completada del cliente.
     *
     * @param int   $customerId ID del cliente autenticado
     * @param array $data       Datos de la encuesta
     * @return int              ID de la encuesta creada
     * @throws DomainException  Si la solicitud de servicio no existe, no pertenece al
     *                          cliente, no está completada, o ya tiene una encuesta
     */
    public function create(int $customerId, array $data): int
    {
        $serviceRequestId = (int)$data['service_request_id'];

        $serviceRequest = Database::fetchOne(
            'SELECT id, customer_id, status FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$serviceRequestId]
        );

        if ($serviceRequest === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        if ((int)$serviceRequest['customer_id'] !== $customerId) {
            throw new DomainException('No eres el propietario de esta solicitud de servicio', 403);
        }

        if ($serviceRequest['status'] !== 'completed') {
            throw new DomainException('Solo se puede encuestar un servicio completado', 400);
        }

        $existing = Database::fetchOne(
            'SELECT id FROM surveys WHERE service_request_id = ?',
            [$serviceRequestId]
        );

        if ($existing !== null) {
            throw new DomainException('Ya existe una encuesta para esta solicitud de servicio', 409);
        }

        // El chequeo anterior (SELECT existing) es "check then insert" — no es
        // atómico por sí solo, pero surveys.service_request_id tiene un UNIQUE
        // a nivel de BD que sí garantiza la invariante ante dos peticiones
        // concurrentes (ej. doble clic). Si la carrera ocurre, MySQL rechaza el
        // segundo INSERT — se traduce a la misma DomainException amigable en
        // vez de dejarla propagar como un error 500 genérico.
        try {
            return Database::insert('surveys', [
                'service_request_id'   => $serviceRequestId,
                'overall_satisfaction' => (int)$data['overall_satisfaction'],
                'would_recommend'      => !empty($data['would_recommend']) ? 1 : 0,
                'comments'             => $data['comments'] ?? null,
            ]);
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new DomainException('Ya existe una encuesta para esta solicitud de servicio', 409);
            }
            throw $e;
        }
    }

    /**
     * Obtiene las encuestas enviadas por el cliente autenticado.
     *
     * @param int $customerId ID del cliente
     * @return array          Lista de encuestas
     */
    public function getByCustomer(int $customerId): array
    {
        return Database::fetchAll(
            'SELECT s.*, sr.customer_id, sr.service_code, sr.emergency_type
             FROM surveys s
             JOIN service_requests sr ON sr.id = s.service_request_id
             WHERE sr.customer_id = ? AND s.deleted_at IS NULL
             ORDER BY s.created_at DESC',
            [$customerId]
        );
    }

    /**
     * Obtiene todas las encuestas para el administrador.
     *
     * Paginado (LIMIT/OFFSET) — este listado admin-wide (no acotado por
     * usuario) crece sin límite con el uso de la plataforma. $limit/$offset
     * ya vienen validados como enteros por
     * RequestValidator::parsePagination(), así que se interpolan
     * directamente: MySQL con PDO::ATTR_EMULATE_PREPARES=false no acepta
     * LIMIT/OFFSET como parámetros preparados nativos.
     *
     * @param int $limit  Máximo de filas a retornar
     * @param int $offset Filas a saltar
     * @return array       ['items' => array, 'total' => int]
     */
    public function adminList(int $limit = 50, int $offset = 0): array
    {
        $total = (int)(Database::fetchOne(
            'SELECT COUNT(*) AS total FROM surveys WHERE deleted_at IS NULL'
        )['total'] ?? 0);

        $items = Database::fetchAll(
            'SELECT s.*,
                    sr.customer_id, sr.service_code, sr.emergency_type, sr.mechanic_id,
                    u.first_name AS customer_first_name, u.last_name AS customer_last_name,
                    m.first_name AS mechanic_first_name, m.last_name AS mechanic_last_name
             FROM surveys s
             JOIN service_requests sr ON sr.id = s.service_request_id
             JOIN users u ON u.id = sr.customer_id
             LEFT JOIN users m ON m.id = sr.mechanic_id
             WHERE s.deleted_at IS NULL
             ORDER BY s.created_at DESC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return ['items' => $items, 'total' => $total];
    }
}
