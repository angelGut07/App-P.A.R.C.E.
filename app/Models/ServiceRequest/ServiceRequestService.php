<?php

namespace App\Models\ServiceRequest;

use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\DomainException;
use App\Models\ServiceRequest\ServiceRequestValidator;

/**
 * Servicio de Solicitudes de Servicio
 *
 * Lógica de negocio para la gestión de solicitudes de servicio, incluyendo
 * creación, actualizaciones, transiciones de estado, asignación de mecánicos
 * y calificación por parte del cliente.
 *
 * Reglas de negocio:
 * - Una solicitud activa por cliente
 * - Una solicitud activa por vehículo
 * - Estados activos: pending, assigned, in_progress
 * - Estados terminales: completed, cancelled, expired
 * - Privacidad de ubicación: coordenadas exactas ocultas durante pending, visibles tras asignación
 */
class ServiceRequestService
{
    /**
     * Crea una nueva solicitud de servicio.
     *
     * @param int   $customerId ID del usuario cliente
     * @param array $data       Datos de la solicitud de servicio
     * @return int              ID de la solicitud creada
     * @throws \Exception       Por error de base de datos o lógica de negocio
     */
    public function create(int $customerId, array $data): int
    {
        // Verificar que el vehículo exista y pertenezca al cliente
        $vehicle = Database::fetchOne(
            'SELECT id, user_id, status FROM vehicles WHERE id = ? AND deleted_at IS NULL',
            [(int)$data['vehicle_id']]
        );

        if ($vehicle === null) {
            throw new DomainException('Vehículo no encontrado', 404);
        }

        if ((int)$vehicle['user_id'] !== $customerId) {
            throw new DomainException('No eres el propietario de este vehículo', 403);
        }

        if ($vehicle['status'] !== 'active') {
            throw new DomainException('El vehículo no está activo', 400);
        }

        // "Una solicitud activa por cliente/vehículo" es un SELECT-then-INSERT:
        // sin protección adicional, dos peticiones concurrentes del mismo
        // cliente (doble clic, reintento de red) podrían pasar ambas el chequeo
        // antes de que cualquiera inserte, dejando dos solicitudes activas a la
        // vez — a diferencia de PQR/encuestas, aquí no hay un UNIQUE de BD que
        // lo evite (el estado "activo" son 3 valores posibles, no admite un
        // índice único simple). Se cierra adquiriendo un lock pesimista sobre
        // la fila del propio cliente en `users` (SELECT ... FOR UPDATE) antes
        // de los chequeos: como esa fila siempre existe, cualquier otra
        // create() concurrente del MISMO cliente queda bloqueada hasta que esta
        // transacción termine. Clientes distintos no se bloquean entre sí.
        Database::beginTransaction();
        try {
            Database::fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$customerId]);

            $activeCustomerRequest = Database::fetchOne(
                'SELECT id, service_code FROM service_requests
                 WHERE customer_id = ?
                 AND status IN (?, ?, ?)
                 AND deleted_at IS NULL',
                [$customerId, 'pending', 'assigned', 'in_progress']
            );

            if ($activeCustomerRequest !== null) {
                throw new DomainException(
                    'Ya tienes una solicitud de servicio activa (' .
                    $activeCustomerRequest['service_code'] .
                    '). Por favor, complétala o cancélala antes de crear una nueva.',
                    409
                );
            }

            // Verificar si el vehículo ya tiene una solicitud activa (el
            // vehículo ya se confirmó de propiedad del mismo cliente arriba,
            // así que el lock sobre el cliente también protege este chequeo)
            $activeVehicleRequest = Database::fetchOne(
                'SELECT id, service_code FROM service_requests
                 WHERE vehicle_id = ?
                 AND status IN (?, ?, ?)
                 AND deleted_at IS NULL',
                [(int)$data['vehicle_id'], 'pending', 'assigned', 'in_progress']
            );

            if ($activeVehicleRequest !== null) {
                throw new DomainException(
                    'Este vehículo ya tiene una solicitud de servicio activa (' .
                    $activeVehicleRequest['service_code'] .
                    '). Por favor, complétala o cancélala antes de crear una nueva.',
                    409
                );
            }

            $insertData = [
                'customer_id'    => $customerId,
                'vehicle_id'     => (int)$data['vehicle_id'],
                'emergency_type' => strtolower($data['emergency_type']),
                'description'    => $data['description'],
                'latitude'       => (float)$data['latitude'],
                'longitude'      => (float)$data['longitude'],
                'priority'       => strtolower($data['priority'] ?? 'normal'),
                'status'         => 'pending',
                'requested_at'   => date('Y-m-d H:i:s')
            ];

            $requestId = Database::insert('service_requests', $insertData);

            // El código de servicio depende del ID recién insertado, así que
            // requiere una segunda escritura — misma transacción: si esta
            // falla, la solicitud no debe quedar sin service_code.
            $serviceCode = ServiceRequestValidator::generateServiceCode($requestId);
            Database::update('service_requests', [
                'service_code' => $serviceCode
            ], 'id = ?', [$requestId]);

            Database::commit();
            return $requestId;
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Actualiza una solicitud de servicio pendiente (solo el cliente puede hacerlo).
     *
     * @param int   $requestId  ID de la solicitud de servicio
     * @param int   $customerId ID del usuario cliente
     * @param array $data       Datos a actualizar
     * @return bool             Verdadero si la actualización fue exitosa
     * @throws \Exception       Por error de base de datos o lógica de negocio
     */
    public function update(int $requestId, int $customerId, array $data): bool
    {
        // Verificar que la solicitud exista y pertenezca al cliente
        $request = Database::fetchOne(
            'SELECT id, customer_id, status FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$requestId]
        );

        if ($request === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        if ((int)$request['customer_id'] !== $customerId) {
            throw new DomainException('No eres el propietario de esta solicitud de servicio', 403);
        }

        // Solo se pueden actualizar solicitudes en estado pendiente
        if ($request['status'] !== 'pending') {
            throw new DomainException('Solo se pueden actualizar solicitudes en estado pendiente', 400);
        }

        // Preparar datos de actualización
        $updateData = [];

        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }

        if (isset($data['latitude'])) {
            $updateData['latitude'] = (float)$data['latitude'];
        }

        if (isset($data['longitude'])) {
            $updateData['longitude'] = (float)$data['longitude'];
        }

        if (isset($data['priority'])) {
            $updateData['priority'] = strtolower($data['priority']);
        }

        // Si no hay nada que actualizar, retornar verdadero directamente
        if (empty($updateData)) {
            return true;
        }

        // El WHERE repite la condición de estado (igual que cancel()) para que la
        // actualización sea atómica frente a una transición concurrente — sin esto,
        // el mecánico podría aceptar la solicitud justo entre el chequeo de arriba
        // y este UPDATE, y la edición del cliente se aplicaría igual sobre una
        // solicitud que ya no está pendiente.
        $rowCount = Database::update(
            'service_requests',
            $updateData,
            'id = ? AND status = ?',
            [$requestId, 'pending']
        );

        if ($rowCount === 0) {
            throw new DomainException(
                'El estado de la solicitud cambió antes de poder actualizarla. Actualiza la página e inténtalo de nuevo.',
                409
            );
        }

        return true;
    }

    /**
     * Cancela una solicitud de servicio.
     *
     * @param int    $requestId ID de la solicitud de servicio
     * @param int    $userId    ID del usuario (cliente o administrador)
     * @param string $reason    Motivo de la cancelación
     * @return bool             Verdadero si la cancelación fue exitosa
     * @throws \Exception       Por error de base de datos o lógica de negocio
     */
    public function cancel(int $requestId, int $userId, string $reason): bool
    {
        // Verificar que la solicitud exista
        $request = Database::fetchOne(
            'SELECT id, customer_id, status FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$requestId]
        );

        if ($request === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        // Verificar propiedad de la solicitud
        if ((int)$request['customer_id'] !== $userId) {
            throw new DomainException('No eres el propietario de esta solicitud de servicio', 403);
        }

        // Solo se pueden cancelar solicitudes en estado pending o assigned
        if (!in_array($request['status'], ['pending', 'assigned'], true)) {
            throw new DomainException('Solo se pueden cancelar solicitudes en estado pendiente o asignado', 400);
        }

        // Actualizar al estado cancelado — el WHERE repite la condición de estado para que
        // la operación sea atómica frente a una transición concurrente (ej. el mecánico
        // asignado inicia el trabajo justo cuando el cliente cancela).
        $rowCount = Database::update('service_requests', [
            'status'              => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_by'        => $userId,
            'cancelled_at'        => date('Y-m-d H:i:s')
        ], 'id = ? AND status IN (?, ?)', [$requestId, 'pending', 'assigned']);

        if ($rowCount === 0) {
            throw new DomainException(
                'El estado de la solicitud cambió antes de poder cancelarla. Actualiza la página e inténtalo de nuevo.',
                409
            );
        }

        return true;
    }

    /**
     * El mecánico acepta una solicitud pendiente (auto-asignación).
     *
     * @param int $requestId  ID de la solicitud de servicio
     * @param int $mechanicId ID del mecánico
     * @return bool           Verdadero si la aceptación fue exitosa
     * @throws \Exception     Por error de base de datos o lógica de negocio
     */
    public function accept(int $requestId, int $mechanicId): bool
    {
        // Verificar que la solicitud exista y esté en estado pendiente
        $request = Database::fetchOne(
            'SELECT id, status FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$requestId]
        );

        if ($request === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        if ($request['status'] !== 'pending') {
            throw new DomainException('Solo se pueden aceptar solicitudes en estado pendiente', 400);
        }

        // Verificar que el mecánico tenga una licencia de conducción registrada y vigente
        // (Requisito 3.5, 3.6, 3.7) — una licencia es obligatoria para aceptar solicitudes,
        // no solo para quienes ya registraron una y la dejaron vencer.
        $mechanic = Database::fetchOne(
            'SELECT driver_license_expiration_date FROM users WHERE id = ?',
            [$mechanicId]
        );

        if ($mechanic !== null && empty($mechanic['driver_license_expiration_date'])) {
            throw new DomainException(
                'Debes registrar tu licencia de conducción antes de aceptar solicitudes.',
                403
            );
        }

        if ($mechanic !== null
            && !empty($mechanic['driver_license_expiration_date'])
            && $mechanic['driver_license_expiration_date'] < date('Y-m-d')) {
            throw new DomainException(
                'La licencia de conducción del mecánico está vencida. No puede aceptar solicitudes.',
                403
            );
        }

        // Validar la transición de estado
        $validation = ServiceRequestValidator::validateStatusTransition($request['status'], 'assigned');
        if (!$validation['valid']) {
            throw new DomainException($validation['error'], 400);
        }

        // Actualizar al estado asignado — la condición status = 'pending' en el WHERE
        // hace la operación atómica: si dos mecánicos intentan aceptar la misma
        // solicitud casi simultáneamente, solo la primera UPDATE afecta una fila.
        $rowCount = Database::update('service_requests', [
            'status'      => 'assigned',
            'mechanic_id' => $mechanicId,
            'assigned_at' => date('Y-m-d H:i:s')
        ], 'id = ? AND status = ?', [$requestId, 'pending']);

        if ($rowCount === 0) {
            throw new DomainException(
                'Esta solicitud ya fue aceptada por otro mecánico',
                409
            );
        }

        return true;
    }

    /**
     * El mecánico inicia el trabajo en una solicitud asignada.
     *
     * @param int $requestId  ID de la solicitud de servicio
     * @param int $mechanicId ID del mecánico
     * @return bool           Verdadero si el inicio fue exitoso
     * @throws \Exception     Por error de base de datos o lógica de negocio
     */
    public function start(int $requestId, int $mechanicId): bool
    {
        // Verificar que la solicitud exista y el mecánico esté asignado
        $request = Database::fetchOne(
            'SELECT id, status, mechanic_id FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$requestId]
        );

        if ($request === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        if ((int)$request['mechanic_id'] !== $mechanicId) {
            throw new DomainException('No estás asignado a esta solicitud de servicio', 403);
        }

        if ($request['status'] !== 'assigned') {
            throw new DomainException('Solo se pueden iniciar solicitudes en estado asignado', 400);
        }

        // Validar la transición de estado
        $validation = ServiceRequestValidator::validateStatusTransition($request['status'], 'in_progress');
        if (!$validation['valid']) {
            throw new DomainException($validation['error'], 400);
        }

        // Actualizar al estado en progreso (WHERE atómico frente a transiciones concurrentes,
        // ej. el cliente cancela justo cuando el mecánico inicia el trabajo)
        $rowCount = Database::update('service_requests', [
            'status'     => 'in_progress',
            'started_at' => date('Y-m-d H:i:s')
        ], 'id = ? AND status = ?', [$requestId, 'assigned']);

        if ($rowCount === 0) {
            throw new DomainException(
                'El estado de la solicitud cambió antes de poder iniciarla. Actualiza la página e inténtalo de nuevo.',
                409
            );
        }

        return true;
    }

    /**
     * El mecánico completa una solicitud de servicio.
     *
     * @param int   $requestId  ID de la solicitud de servicio
     * @param int   $mechanicId ID del mecánico
     * @param float $finalCost  Costo final del servicio
     * @return bool             Verdadero si la finalización fue exitosa
     * @throws \Exception       Por error de base de datos o lógica de negocio
     */
    public function complete(int $requestId, int $mechanicId, float $finalCost): bool
    {
        // Verificar que la solicitud exista y el mecánico esté asignado
        $request = Database::fetchOne(
            'SELECT id, status, mechanic_id FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$requestId]
        );

        if ($request === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        if ((int)$request['mechanic_id'] !== $mechanicId) {
            throw new DomainException('No estás asignado a esta solicitud de servicio', 403);
        }

        if ($request['status'] !== 'in_progress') {
            throw new DomainException('Solo se pueden completar solicitudes en estado en progreso', 400);
        }

        // Validar la transición de estado
        $validation = ServiceRequestValidator::validateStatusTransition($request['status'], 'completed');
        if (!$validation['valid']) {
            throw new DomainException($validation['error'], 400);
        }

        // Validar que el costo final sea un número positivo
        if ($finalCost < 0) {
            throw new DomainException('El costo final debe ser un número positivo', 400);
        }

        // Actualizar al estado completado (WHERE atómico frente a transiciones concurrentes)
        $rowCount = Database::update('service_requests', [
            'status'       => 'completed',
            'final_cost'   => $finalCost,
            'completed_at' => date('Y-m-d H:i:s')
        ], 'id = ? AND status = ?', [$requestId, 'in_progress']);

        if ($rowCount === 0) {
            throw new DomainException(
                'El estado de la solicitud cambió antes de poder completarla. Actualiza la página e inténtalo de nuevo.',
                409
            );
        }

        return true;
    }

    /**
     * El cliente califica una solicitud de servicio completada.
     *
     * @param int         $requestId             ID de la solicitud de servicio
     * @param int         $customerId            ID del usuario cliente
     * @param int         $rating                Calificación general (1-5)
     * @param string|null $feedback              Comentario opcional del cliente
     * @param int|null    $punctualityRating     Calificación de puntualidad (1-5), opcional
     * @param int|null    $serviceQualityRating  Calificación de calidad del servicio (1-5), opcional
     * @return bool                              Verdadero si la calificación fue registrada
     * @throws \Exception                        Por error de base de datos o lógica de negocio
     */
    public function rate(
        int $requestId,
        int $customerId,
        int $rating,
        ?string $feedback = null,
        ?int $punctualityRating = null,
        ?int $serviceQualityRating = null
    ): bool {
        // Verificar que la solicitud exista y pertenezca al cliente
        $request = Database::fetchOne(
            'SELECT id, customer_id, mechanic_id, status FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$requestId]
        );

        if ($request === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        if ((int)$request['customer_id'] !== $customerId) {
            throw new DomainException('No eres el propietario de esta solicitud de servicio', 403);
        }

        // Solo se pueden calificar solicitudes completadas
        if ($request['status'] !== 'completed') {
            throw new DomainException('Solo se pueden calificar solicitudes en estado completado', 400);
        }

        // La calificación vive en `ratings` (rating_type customer_to_mechanic).
        // El UNIQUE (service_request_id, rating_type) hace atómico el "solo una
        // vez": un doble envío concurrente choca con la clave y se traduce al
        // mismo 409.
        try {
            Database::insert('ratings', [
                'service_request_id' => $requestId,
                'rating_type'        => 'customer_to_mechanic',
                'rater_id'           => $customerId,
                'ratee_id'           => (int)$request['mechanic_id'],
                'score'              => $rating,
                'punctuality_score'  => $punctualityRating,
                'quality_score'      => $serviceQualityRating,
                'comment'            => $feedback,
            ]);
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new DomainException('Esta solicitud de servicio ya fue calificada', 409);
            }
            throw $e;
        }
        return true;
    }

    /**
     * Obtiene una solicitud de servicio por su ID, aplicando filtros de privacidad según el rol.
     *
     * @param int    $requestId ID de la solicitud de servicio
     * @param int    $userId    ID del usuario (para verificación de acceso)
     * @param string $userRole  Rol del usuario (customer, mechanic, admin)
     * @return array|null       Datos de la solicitud con filtros de privacidad aplicados, o null si no tiene acceso
     */
    public function getById(int $requestId, int $userId, string $userRole): ?array
    {
        // Consultar la solicitud con datos relacionados de cliente, mecánico,
        // vehículo, y la calificación mecánico→cliente si existe (tabla
        // `ratings`, rating_type='mechanic_to_customer' — ver
        // MechanicRatingService). Único lugar donde se construye esta
        // entidad; reutilizado por show/create/update/cancel/rate/accept/
        // start/complete — cualquier campo nuevo se agrega aquí, no en una
        // consulta aparte.
        $request = Database::fetchOne(
            'SELECT sr.*,
                    c.first_name as customer_first_name,
                    c.last_name as customer_last_name,
                    c.email as customer_email,
                    c.phone as customer_phone,
                    m.first_name as mechanic_first_name,
                    m.last_name as mechanic_last_name,
                    m.phone as mechanic_phone,
                    m.profile_picture_url as mechanic_picture_url,
                    m.created_at as mechanic_joined_at,
                    (SELECT ma.specialty FROM mechanic_applications ma
                      WHERE ma.user_id = sr.mechanic_id AND ma.status = \'approved\'
                      ORDER BY ma.reviewed_at DESC LIMIT 1) as mechanic_specialty,
                    (SELECT ROUND(AVG(r.score), 1) FROM ratings r
                      WHERE r.ratee_id = sr.mechanic_id AND r.rating_type = \'customer_to_mechanic\') as mechanic_average_rating,
                    (SELECT COUNT(*) FROM service_requests done
                      WHERE done.mechanic_id = sr.mechanic_id AND done.status = \'completed\') as mechanic_completed_services,
                    v.make as vehicle_make,
                    v.model as vehicle_model,
                    v.year as vehicle_year,
                    v.color as vehicle_color,
                    v.license_plate as vehicle_license_plate,
                    st.name as service_type_name,
                    cr.score as customer_rating,
                    cr.punctuality_score as punctuality_rating,
                    cr.quality_score as service_quality_rating,
                    cr.comment as customer_feedback,
                    mr.score as mechanic_rating_score,
                    mr.punctuality_score as mechanic_rating_punctuality_score,
                    mr.quality_score as mechanic_rating_quality_score,
                    mr.comment as mechanic_rating_comment,
                    mr.created_at as mechanic_rating_created_at
             FROM service_requests sr
             LEFT JOIN users c ON sr.customer_id = c.id
             LEFT JOIN users m ON sr.mechanic_id = m.id
             LEFT JOIN vehicles v ON sr.vehicle_id = v.id
             LEFT JOIN service_types st ON st.code = sr.emergency_type
             LEFT JOIN ratings cr ON cr.service_request_id = sr.id AND cr.rating_type = \'customer_to_mechanic\'
             LEFT JOIN ratings mr ON mr.service_request_id = sr.id AND mr.rating_type = \'mechanic_to_customer\'
             WHERE sr.id = ? AND sr.deleted_at IS NULL',
            [$requestId]
        );

        if ($request === null) {
            return null;
        }

        // Aplicar controles de acceso según el rol del usuario
        $customerId = (int)$request['customer_id'];
        $mechanicId = $request['mechanic_id'] ? (int)$request['mechanic_id'] : null;

        // Los clientes solo pueden ver sus propias solicitudes
        if ($userRole === 'customer' && $customerId !== $userId) {
            return null;
        }

        // Los mecánicos solo pueden ver las solicitudes que tienen asignadas
        if ($userRole === 'mechanic' && $mechanicId !== $userId) {
            return null;
        }

        // Una calificación individual solo la ve quien la dio (y el
        // administrador): el calificado conoce únicamente su promedio.
        $hidden = match ($userRole) {
            'mechanic' => ['customer_rating', 'punctuality_rating', 'service_quality_rating', 'customer_feedback'],
            'customer' => ['mechanic_rating_score', 'mechanic_rating_punctuality_score',
                           'mechanic_rating_quality_score', 'mechanic_rating_comment', 'mechanic_rating_created_at'],
            default    => [],
        };
        foreach ($hidden as $field) {
            unset($request[$field]);
        }

        // Aplicar privacidad de ubicación: ocultar coordenadas exactas cuando la solicitud está pendiente
        if ($request['status'] === 'pending' && $userRole === 'mechanic') {
            $request['latitude_approximate']  = round((float)$request['latitude'], 2);
            $request['longitude_approximate'] = round((float)$request['longitude'], 2);
            unset($request['latitude']);
            unset($request['longitude']);
        }

        return $request;
    }

    /**
     * Obtiene todas las solicitudes de servicio de un cliente.
     *
     * @param int         $customerId ID del usuario cliente
     * @param string|null $status     Filtro opcional por estado
     * @return array                  Lista de solicitudes de servicio
     */
    public function getCustomerRequests(int $customerId, ?string $status = null): array
    {
        // Consulta base con datos del vehículo y mecánico asignado
        $sql = 'SELECT sr.*,
                       v.make as vehicle_make,
                       v.model as vehicle_model,
                       v.license_plate as vehicle_license_plate,
                       m.first_name as mechanic_first_name,
                       m.last_name as mechanic_last_name,
                       m.phone as mechanic_phone,
                       st.name as service_type_name,
                       cr.score as customer_rating,
                       cr.punctuality_score as punctuality_rating,
                       cr.quality_score as service_quality_rating,
                       cr.comment as customer_feedback
                FROM service_requests sr
                LEFT JOIN vehicles v ON sr.vehicle_id = v.id
                LEFT JOIN users m ON sr.mechanic_id = m.id
                LEFT JOIN service_types st ON st.code = sr.emergency_type
                LEFT JOIN ratings cr ON cr.service_request_id = sr.id AND cr.rating_type = \'customer_to_mechanic\'
                WHERE sr.customer_id = ? AND sr.deleted_at IS NULL';

        $params = [$customerId];

        // Aplicar filtro de estado si se proporcionó
        if ($status !== null) {
            $sql .= ' AND sr.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY sr.requested_at DESC';

        return Database::fetchAll($sql, $params);
    }

    /**
     * Obtiene todas las solicitudes de servicio asignadas a un mecánico.
     *
     * @param int         $mechanicId ID del mecánico
     * @param string|null $status     Filtro opcional por estado
     * @return array                  Lista de solicitudes de servicio
     */
    public function getMechanicRequests(int $mechanicId, ?string $status = null): array
    {
        // Consulta base con datos del cliente y el vehículo. Sin la
        // calificación que dejó cada cliente: el mecánico solo ve su promedio
        // (getMechanicStats).
        $sql = 'SELECT sr.*,
                       c.first_name as customer_first_name,
                       c.last_name as customer_last_name,
                       c.phone as customer_phone,
                       v.make as vehicle_make,
                       v.model as vehicle_model,
                       v.license_plate as vehicle_license_plate,
                       st.name as service_type_name
                FROM service_requests sr
                LEFT JOIN users c ON sr.customer_id = c.id
                LEFT JOIN vehicles v ON sr.vehicle_id = v.id
                LEFT JOIN service_types st ON st.code = sr.emergency_type
                WHERE sr.mechanic_id = ? AND sr.deleted_at IS NULL';

        $params = [$mechanicId];

        // Aplicar filtro de estado si se proporcionó
        if ($status !== null) {
            $sql .= ' AND sr.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY sr.requested_at DESC';

        return Database::fetchAll($sql, $params);
    }

    /**
     * Obtiene estadísticas agregadas del mecánico: trabajos completados, calificación
     * promedio (general, puntualidad, calidad) e ingresos totales.
     *
     * @param int $mechanicId ID del mecánico
     * @return array Estadísticas agregadas
     */
    public function getMechanicStats(int $mechanicId): array
    {
        $completed = Database::fetchOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(final_cost), 0) AS total_earnings
             FROM service_requests
             WHERE mechanic_id = ? AND status = 'completed' AND deleted_at IS NULL",
            [$mechanicId]
        );

        $ratings = Database::fetchOne(
            "SELECT AVG(score) AS avg_rating,
                    AVG(punctuality_score) AS avg_punctuality,
                    AVG(quality_score) AS avg_service_quality,
                    COUNT(*) AS total_rated
             FROM ratings
             WHERE ratee_id = ? AND rating_type = 'customer_to_mechanic'",
            [$mechanicId]
        );

        return [
            'total_completed'      => (int)($completed['total'] ?? 0),
            'total_earnings'       => (float)($completed['total_earnings'] ?? 0),
            'total_rated'          => (int)($ratings['total_rated'] ?? 0),
            'average_rating'       => $ratings['avg_rating'] !== null ? round((float)$ratings['avg_rating'], 2) : null,
            'average_punctuality'  => $ratings['avg_punctuality'] !== null ? round((float)$ratings['avg_punctuality'], 2) : null,
            'average_service_quality' => $ratings['avg_service_quality'] !== null ? round((float)$ratings['avg_service_quality'], 2) : null,
        ];
    }

    /**
     * Obtiene solicitudes pendientes cercanas para mecánicos (solo ubicación aproximada).
     *
     * @param float $latitude   Latitud actual del mecánico
     * @param float $longitude  Longitud actual del mecánico
     * @param int   $radiusKm   Radio de búsqueda en kilómetros (por defecto 50 km)
     * @return array            Lista de solicitudes pendientes con ubicación aproximada
     */
    public function getNearbyPendingRequests(float $latitude, float $longitude, int $radiusKm = 50): array
    {
        // Usar la fórmula de Haversine para el cálculo de distancia
        // Nota: es un cálculo aproximado válido para distancias cortas
        $sql = "SELECT sr.id,
                       sr.service_code,
                       sr.emergency_type,
                       sr.priority,
                       sr.requested_at,
                       sr.description,
                       st.name as service_type_name,
                       ROUND(sr.latitude, 2) as latitude_approximate,
                       ROUND(sr.longitude, 2) as longitude_approximate,
                       v.make as vehicle_make,
                       v.model as vehicle_model,
                       v.year as vehicle_year,
                       (6371 * acos(
                           cos(radians(?)) *
                           cos(radians(sr.latitude)) *
                           cos(radians(sr.longitude) - radians(?)) +
                           sin(radians(?)) *
                           sin(radians(sr.latitude))
                       )) AS distance_km
                FROM service_requests sr
                LEFT JOIN vehicles v ON sr.vehicle_id = v.id
                LEFT JOIN service_types st ON st.code = sr.emergency_type
                WHERE sr.status = 'pending'
                  AND sr.deleted_at IS NULL
                HAVING distance_km <= ?
                ORDER BY
                    CASE sr.priority
                        WHEN 'critical' THEN 3
                        WHEN 'urgent'   THEN 2
                        WHEN 'normal'   THEN 1
                        ELSE 0
                    END DESC,
                    distance_km ASC,
                    sr.requested_at ASC
                LIMIT 50";

        return Database::fetchAll($sql, [$latitude, $longitude, $latitude, $radiusKm]);
    }
}
