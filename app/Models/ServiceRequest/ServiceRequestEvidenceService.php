<?php

namespace App\Models\ServiceRequest;

use App\Core\Database;
use App\Core\DomainException;

/**
 * Servicio de Evidencias de Solicitudes de Servicio
 *
 * Gestiona las evidencias fotográficas (antes/durante/después) asociadas
 * a solicitudes de servicio. Solo el mecánico asignado puede agregar evidencias.
 * Las evidencias se almacenan como URLs con metadatos opcionales.
 *
 * Requisitos: 5.1-5.11
 */
class ServiceRequestEvidenceService
{
    /** Tamaño máximo de archivo permitido: 5 MB en bytes */
    private const MAX_FILE_SIZE = 5242880;

    /** Extensiones de imagen válidas aceptadas */
    private const VALID_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Tipos de evidencia permitidos según el ciclo de vida del servicio */
    private const VALID_EVIDENCE_TYPES = ['before', 'during', 'after'];

    /** Estados de la solicitud que permiten agregar evidencias */
    private const VALID_STATUSES = ['assigned', 'in_progress', 'completed'];

    /**
     * Agrega un registro de evidencia fotográfica a una solicitud de servicio.
     *
     * @param int   $serviceRequestId  ID de la solicitud de servicio
     * @param int   $mechanicId        ID del mecánico autenticado
     * @param array $data              Claves: evidence_type, image_url, original_filename (opcional), file_size (opcional)
     * @return array                   Registro de evidencia insertado
     * @throws \RuntimeException       Si no se puede recuperar la evidencia tras insertarla
     * @throws \Exception              Por violación de acceso o regla de negocio
     * @throws \InvalidArgumentException Por fallo de validación de datos
     */
    public function addEvidence(int $serviceRequestId, int $mechanicId, array $data): array
    {
        // 1. Obtener la solicitud y verificar que exista
        $serviceRequest = Database::fetchOne(
            'SELECT id, mechanic_id, status FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$serviceRequestId]
        );

        if ($serviceRequest === null) {
            throw new DomainException('Solicitud de servicio no encontrada.', 404);
        }

        // 2. Verificar que el mecánico esté asignado a esta solicitud
        if ((int)$serviceRequest['mechanic_id'] !== $mechanicId) {
            throw new DomainException('No está asignado a esta solicitud de servicio.', 403);
        }

        // 3. Verificar que la solicitud esté en un estado válido para agregar evidencias
        if (!in_array($serviceRequest['status'], self::VALID_STATUSES, true)) {
            throw new DomainException(
                'Solo se pueden agregar evidencias a solicitudes en estado assigned, in_progress o completed.',
                400
            );
        }

        // 4. Validar el tipo de evidencia
        $evidenceType = $data['evidence_type'] ?? '';
        if (!in_array($evidenceType, self::VALID_EVIDENCE_TYPES, true)) {
            throw new \InvalidArgumentException(
                'evidence_type debe ser uno de: before, during, after.'
            );
        }

        // 5. Validar image_url — requerida, máximo 500 caracteres, debe ser URL http/https
        $imageUrl = $data['image_url'] ?? '';
        if (empty($imageUrl)
            || strlen($imageUrl) > 500
            || !filter_var($imageUrl, FILTER_VALIDATE_URL)
            || !preg_match('/^https?:\/\//i', $imageUrl)) {
            throw new \InvalidArgumentException(
                'image_url debe ser una URL http/https válida de no más de 500 caracteres.'
            );
        }

        // 6. Validar la extensión del archivo desde la ruta de la URL
        $urlPath = strtolower(parse_url($imageUrl, PHP_URL_PATH) ?? '');
        $ext = pathinfo($urlPath, PATHINFO_EXTENSION);
        if (!in_array($ext, self::VALID_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'La URL de la imagen debe apuntar a un archivo con extensión: jpg, jpeg, png o webp.'
            );
        }

        // 7. Validar file_size si se proporciona
        if (isset($data['file_size']) && $data['file_size'] !== null) {
            if ((int)$data['file_size'] > self::MAX_FILE_SIZE) {
                throw new \InvalidArgumentException(
                    'file_size no debe superar 5242880 bytes (5 MB).'
                );
            }
        }

        // 8. Construir los datos para la inserción
        $insertData = [
            'service_request_id' => $serviceRequestId,
            'uploaded_by'        => $mechanicId,
            'evidence_type'      => $evidenceType,
            'image_url'          => $imageUrl,
        ];

        // Agregar nombre de archivo original si se proporcionó
        if (!empty($data['original_filename'])) {
            $insertData['original_filename'] = $data['original_filename'];
        }

        // Agregar descripción si se proporcionó
        if (!empty($data['description'])) {
            $insertData['description'] = $data['description'];
        }

        // Agregar tamaño de archivo si se proporcionó
        if (isset($data['file_size']) && $data['file_size'] !== null) {
            $insertData['file_size'] = (int)$data['file_size'];
        }

        // 9. Insertar el registro en la base de datos
        // El chequeo de estado (paso 3) y esta inserción no son atómicos por sí
        // solos: si la solicitud se cancela justo en este intervalo (ej. el
        // cliente cancela mientras el mecánico sube una foto), la evidencia se
        // adjuntaría igual a una solicitud que ya no lo permite. Se re-verifica
        // el estado con un lock de fila dentro de la misma transacción que
        // hace la inserción.
        Database::beginTransaction();
        try {
            $revalidated = Database::fetchOne(
                'SELECT status FROM service_requests WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$serviceRequestId]
            );

            if ($revalidated === null || !in_array($revalidated['status'], self::VALID_STATUSES, true)) {
                throw new DomainException(
                    'Solo se pueden agregar evidencias a solicitudes en estado assigned, in_progress o completed.',
                    400
                );
            }

            $evidenceId = Database::insert('service_request_evidences', $insertData);
            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }

        // 10. Recuperar y retornar el registro insertado
        $evidence = Database::fetchOne(
            'SELECT id, service_request_id, uploaded_by, evidence_type,
                    image_url, original_filename, description, file_size, created_at
             FROM service_request_evidences
             WHERE id = ?',
            [$evidenceId]
        );

        // Verificar que la evidencia fue recuperada correctamente tras la inserción
        if ($evidence === null) {
            throw new \RuntimeException('No se pudo recuperar la evidencia después de insertarla');
        }

        return $evidence;
    }

    /**
     * Obtiene todas las evidencias de una solicitud de servicio, ordenadas por fecha de creación.
     *
     * Solo el cliente propietario o el mecánico asignado a la solicitud pueden
     * consultar sus evidencias (evita IDOR: adivinar IDs de otras solicitudes).
     *
     * @param int    $serviceRequestId ID de la solicitud de servicio
     * @param int    $userId           ID del usuario autenticado
     * @param string $userRole         Rol del usuario autenticado ('customer' | 'mechanic')
     * @return array                   Lista de evidencias ordenadas ascendentemente
     * @throws DomainException         Si la solicitud no existe o el usuario no tiene acceso
     */
    public function getEvidences(int $serviceRequestId, int $userId, string $userRole): array
    {
        $serviceRequest = Database::fetchOne(
            'SELECT customer_id, mechanic_id FROM service_requests WHERE id = ? AND deleted_at IS NULL',
            [$serviceRequestId]
        );

        if ($serviceRequest === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }

        $isOwningCustomer   = $userRole === 'customer' && (int)$serviceRequest['customer_id'] === $userId;
        $isAssignedMechanic = $userRole === 'mechanic' && (int)$serviceRequest['mechanic_id'] === $userId;

        if (!$isOwningCustomer && !$isAssignedMechanic) {
            throw new DomainException('No tienes acceso a las evidencias de esta solicitud', 403);
        }

        return Database::fetchAll(
            'SELECT id, uploaded_by, evidence_type, image_url,
                    original_filename, description, file_size, created_at
             FROM service_request_evidences
             WHERE service_request_id = ?
             ORDER BY created_at ASC',
            [$serviceRequestId]
        );
    }
}
