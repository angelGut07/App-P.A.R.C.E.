<?php

namespace App\Models\Vehicle;

use App\Core\Database;
use App\Core\DomainException;
use App\Models\Vehicle\VehicleValidator;

/**
 * Servicio de Vehículos
 *
 * Lógica de negocio para gestión de vehículos: creación, actualización,
 * eliminación, y designación de vehículo principal.
 */
class VehicleService
{
    /**
     * Vehículo con sus documentos (SOAT y tecnomecánica) aplanados como
     * columnas soat_* / tecnomecanica_*. Los documentos viven en
     * `vehicle_documents`; este SELECT es el único lugar que los une al
     * vehículo, para que todas las lecturas devuelvan la misma forma.
     */
    public const VEHICLE_SELECT = "SELECT v.*,
            soat.document_number AS soat_number,
            soat.expires_at AS soat_expiration_date,
            soat.document_url AS soat_document_url,
            tm.document_number AS tecnomecanica_number,
            tm.expires_at AS tecnomecanica_expiration_date,
            tm.document_url AS tecnomecanica_document_url
        FROM vehicles v
        LEFT JOIN vehicle_documents soat ON soat.vehicle_id = v.id AND soat.document_type = 'soat'
        LEFT JOIN vehicle_documents tm ON tm.vehicle_id = v.id AND tm.document_type = 'tecnomecanica'";

    /** Tipos de documento; sus campos llegan como {tipo}_number, {tipo}_expiration_date, {tipo}_document_url. */
    private const DOCUMENT_TYPES = ['soat', 'tecnomecanica'];

    /** Tiempo máximo de espera (segundos) para adquirir un lock de placa/VIN */
    private const UNIQUENESS_LOCK_TIMEOUT = 5;

    /**
     * Crear un nuevo vehículo
     *
     * @param int $userId ID del propietario
     * @param array $data Datos del vehículo
     * @return int ID del vehículo creado
     * @throws \Exception Si hay error de base de datos o de lógica de negocio
     */
    public function create(int $userId, array $data): int
    {
        // Normalizar placa
        $data['license_plate'] = VehicleValidator::normalizeLicensePlate($data['license_plate']);

        // Normalizar VIN si se proporcionó
        if (!empty($data['vin'])) {
            $data['vin'] = VehicleValidator::normalizeVIN($data['vin']);
        }

        // license_plate/vin no tienen UNIQUE a nivel de BD (se quitó intencionalmente
        // para permitir reutilizar una placa liberada por un vehículo eliminado —
        // ver migración 2026_07_16_000016), así que el chequeo de unicidad de abajo
        // es un SELECT-then-write sin respaldo de la BD: dos solicitudes concurrentes
        // con la misma placa/VIN (de cualquier usuario) podrían pasar ambas el
        // chequeo. Se cierra con un lock nombrado de MySQL por placa/VIN — no hay
        // una fila propia que bloquear como en otros casos ya corregidos, porque el
        // registro aún no existe.
        $lockKeys = $this->acquireUniquenessLocks($data['license_plate'], $data['vin'] ?? null);

        try {
            // Verificar que la placa no exista (entre vehículos activos; una placa liberada
            // por un vehículo eliminado debe poder reutilizarse)
            $existing = Database::fetchOne(
                'SELECT id FROM vehicles WHERE license_plate = ? AND deleted_at IS NULL',
                [$data['license_plate']]
            );

            if ($existing !== null) {
                throw new DomainException('Ya existe un vehículo con esta placa', 409);
            }

            // Verificar que el VIN no exista (si se proporcionó)
            if (!empty($data['vin'])) {
                $existingVin = Database::fetchOne(
                    'SELECT id FROM vehicles WHERE vin = ? AND deleted_at IS NULL',
                    [$data['vin']]
                );

                if ($existingVin !== null) {
                    throw new DomainException('Ya existe un vehículo con este VIN', 409);
                }
            }

            // Preparar datos de inserción
            $insertData = [
                'user_id'      => $userId,
                'license_plate'=> $data['license_plate'],
                'make'         => $data['make'],
                'model'        => $data['model'],
                'year'         => (int)$data['year'],
                'vehicle_type' => $data['vehicle_type'],
                'fuel_type'    => $data['fuel_type'],
                'is_primary'   => !empty($data['is_primary']) ? 1 : 0,
                'status'       => 'active',
            ];

            // Campos opcionales
            if (!empty($data['color']))             $insertData['color']             = $data['color'];
            if (!empty($data['vin']))               $insertData['vin']               = $data['vin'];
            if (!empty($data['nickname']))          $insertData['nickname']          = $data['nickname'];
            if (!empty($data['primary_photo_url'])) $insertData['primary_photo_url'] = $data['primary_photo_url'];

            // Vehículo + documentos (y, si es principal, quitar el principal
            // anterior) deben ser atómicos: si algo falla a mitad de camino el
            // usuario no debe quedar sin vehículo principal ni con un vehículo
            // sin sus documentos.
            Database::beginTransaction();
            try {
                if (!empty($data['is_primary'])) {
                    Database::update('vehicles', ['is_primary' => false], 'user_id = ?', [$userId]);
                }
                $vehicleId = Database::insert('vehicles', $insertData);
                $this->saveDocuments($vehicleId, $data);
                Database::commit();
                return $vehicleId;
            } catch (\Exception $e) {
                Database::rollback();
                throw $e;
            }
        } finally {
            $this->releaseLocks($lockKeys);
        }
    }

    /**
     * Actualizar un vehículo existente
     *
     * @param int $vehicleId ID del vehículo
     * @param int $userId ID del propietario (para verificar ownership)
     * @param array $data Datos a actualizar
     * @return bool Éxito
     * @throws \Exception Si hay error o el usuario no es propietario
     */
    public function update(int $vehicleId, int $userId, array $data): bool
    {
        // Verificar que el vehículo existe y pertenece al usuario
        $vehicle = Database::fetchOne(
            'SELECT id, user_id, license_plate, vin, is_primary FROM vehicles WHERE id = ? AND deleted_at IS NULL',
            [$vehicleId]
        );

        if ($vehicle === null) {
            throw new DomainException('Vehículo no encontrado', 404);
        }

        if ((int)$vehicle['user_id'] !== $userId) {
            throw new DomainException('No eres propietario de este vehículo', 403);
        }

        $updateData = [];

        // Igual que en create(): sin UNIQUE de BD para placa/VIN, el chequeo de
        // unicidad necesita un lock nombrado propio cuando el valor realmente
        // cambia (si no cambia, no hay nada que proteger).
        $lockKeys = [];
        $normalizedPlate = null;
        $normalizedVin = null;

        if (isset($data['license_plate'])) {
            $normalizedPlate = VehicleValidator::normalizeLicensePlate($data['license_plate']);
            if ($normalizedPlate !== $vehicle['license_plate']) {
                $lockKeys[] = 'vehicle_plate:' . $normalizedPlate;
            }
        }

        if (isset($data['vin'])) {
            $normalizedVin = VehicleValidator::normalizeVIN($data['vin']);
            if ($normalizedVin !== null && $normalizedVin !== $vehicle['vin']) {
                $lockKeys[] = 'vehicle_vin:' . $normalizedVin;
            }
        }

        $lockKeys = $this->acquireLocks($lockKeys);

        try {
            // Placa
            if ($normalizedPlate !== null) {
                if ($normalizedPlate !== $vehicle['license_plate']) {
                    $existing = Database::fetchOne(
                        'SELECT id FROM vehicles WHERE license_plate = ? AND id != ? AND deleted_at IS NULL',
                        [$normalizedPlate, $vehicleId]
                    );

                    if ($existing !== null) {
                        throw new DomainException('Ya existe un vehículo con esta placa', 409);
                    }
                }

                $updateData['license_plate'] = $normalizedPlate;
            }

            // VIN
            if (isset($data['vin'])) {
                if ($normalizedVin !== null && $normalizedVin !== $vehicle['vin']) {
                    $existingVin = Database::fetchOne(
                        'SELECT id FROM vehicles WHERE vin = ? AND id != ? AND deleted_at IS NULL',
                        [$normalizedVin, $vehicleId]
                    );

                    if ($existingVin !== null) {
                        throw new DomainException('Ya existe un vehículo con este VIN', 409);
                    }
                }

                $updateData['vin'] = $normalizedVin;
            }

            return $this->applyUpdate($vehicleId, $userId, $updateData, $data, (bool)$vehicle['is_primary']);
        } finally {
            $this->releaseLocks($lockKeys);
        }
    }

    /**
     * Aplica los campos de actualización de un vehículo (fuera del bloque de
     * unicidad de placa/VIN, que ya liberó sus locks para este punto).
     */
    private function applyUpdate(int $vehicleId, int $userId, array $updateData, array $data, bool $wasPrimary): bool
    {
        // Campos simples
        if (isset($data['make']))             $updateData['make']              = $data['make'];
        if (isset($data['model']))            $updateData['model']             = $data['model'];
        if (isset($data['year']))             $updateData['year']              = (int)$data['year'];
        if (isset($data['color']))            $updateData['color']             = $data['color'];
        if (isset($data['vehicle_type']))     $updateData['vehicle_type']      = $data['vehicle_type'];
        if (isset($data['fuel_type']))        $updateData['fuel_type']         = $data['fuel_type'];
        if (isset($data['nickname']))         $updateData['nickname']          = $data['nickname'];
        if (isset($data['primary_photo_url'])) $updateData['primary_photo_url'] = $data['primary_photo_url'];
        if (isset($data['status']))           $updateData['status']            = $data['status'];

        $documentsChanged = $this->saveDocuments($vehicleId, $data);

        if (empty($updateData) && empty($data['is_primary'])) {
            return true;
        }

        // Si esta actualización desactiva (status=inactive) un vehículo que era
        // el principal, hay que reasignar el principal a otro vehículo activo
        // del usuario — mismo invariante que delete() ya aplica cuando el
        // vehículo eliminado era el principal: un usuario con vehículos activos
        // no debería quedar sin ninguno marcado como principal. update() es la
        // otra vía por la que un vehículo puede dejar de estar activo (además
        // de delete()), así que necesita el mismo fallback.
        $deactivatesPrimary = $wasPrimary
            && isset($updateData['status'])
            && $updateData['status'] === 'inactive';

        if (empty($data['is_primary']) && !$deactivatesPrimary) {
            $rowCount = Database::update('vehicles', $updateData, 'id = ?', [$vehicleId]);
            return $rowCount > 0 || $documentsChanged;
        }

        // Todas las escrituras relacionadas con is_primary deben ser atómicas —
        // misma razón que en create()/setPrimary()/delete(): una falla a mitad
        // de camino dejaría al usuario sin ningún vehículo principal.
        Database::beginTransaction();
        try {
            if (!empty($data['is_primary'])) {
                Database::update('vehicles', ['is_primary' => false], 'user_id = ? AND id != ?', [$userId, $vehicleId]);
                $updateData['is_primary'] = true;
            }

            $rowCount = Database::update('vehicles', $updateData, 'id = ?', [$vehicleId]);

            if ($deactivatesPrimary) {
                $anotherVehicle = Database::fetchOne(
                    'SELECT id FROM vehicles
                     WHERE user_id = ? AND deleted_at IS NULL AND status = ? AND id != ?
                     ORDER BY created_at ASC LIMIT 1',
                    [$userId, 'active', $vehicleId]
                );

                if ($anotherVehicle !== null) {
                    Database::update('vehicles', ['is_primary' => true], 'id = ?', [$anotherVehicle['id']]);
                }
            }

            Database::commit();
            return $rowCount > 0;
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Eliminar vehículo (soft delete)
     *
     * @param int $vehicleId ID del vehículo
     * @param int $userId ID del propietario
     * @return bool Éxito
     * @throws \Exception Si el vehículo no existe o no pertenece al usuario
     */
    public function delete(int $vehicleId, int $userId): bool
    {
        $vehicle = Database::fetchOne(
            'SELECT id, user_id, is_primary FROM vehicles WHERE id = ? AND deleted_at IS NULL',
            [$vehicleId]
        );

        if ($vehicle === null) {
            throw new DomainException('Vehículo no encontrado', 404);
        }

        if ((int)$vehicle['user_id'] !== $userId) {
            throw new DomainException('No eres propietario de este vehículo', 403);
        }

        // El soft-delete y la reasignación del vehículo principal deben ser
        // atómicos — una falla entre ambas escrituras dejaría al usuario sin
        // vehículo principal pese a tener otros vehículos activos disponibles.
        Database::beginTransaction();
        try {
            $rowCount = Database::update('vehicles', [
                'status'     => 'inactive',
                'deleted_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$vehicleId]);

            // Si era el principal, designar otro vehículo como principal
            if ($vehicle['is_primary']) {
                $anotherVehicle = Database::fetchOne(
                    'SELECT id FROM vehicles WHERE user_id = ? AND deleted_at IS NULL AND id != ? ORDER BY created_at ASC LIMIT 1',
                    [$userId, $vehicleId]
                );

                if ($anotherVehicle !== null) {
                    Database::update('vehicles', ['is_primary' => true], 'id = ?', [$anotherVehicle['id']]);
                }
            }

            Database::commit();
            return $rowCount > 0;
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Establecer un vehículo como principal
     *
     * @param int $vehicleId ID del vehículo
     * @param int $userId ID del propietario
     * @return bool Éxito
     * @throws \Exception Si el vehículo no existe o no pertenece al usuario
     */
    public function setPrimary(int $vehicleId, int $userId): bool
    {
        $vehicle = Database::fetchOne(
            'SELECT id, user_id FROM vehicles WHERE id = ? AND deleted_at IS NULL',
            [$vehicleId]
        );

        if ($vehicle === null) {
            throw new DomainException('Vehículo no encontrado', 404);
        }

        if ((int)$vehicle['user_id'] !== $userId) {
            throw new DomainException('No eres propietario de este vehículo', 403);
        }

        // Ambas escrituras deben ser atómicas: si la segunda falla tras la
        // primera, el usuario quedaría sin ningún vehículo principal.
        Database::beginTransaction();
        try {
            Database::update('vehicles', ['is_primary' => false], 'user_id = ?', [$userId]);
            $rowCount = Database::update('vehicles', ['is_primary' => true], 'id = ?', [$vehicleId]);
            Database::commit();
            return $rowCount > 0;
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Obtener vehículo por ID
     *
     * @param int $vehicleId ID del vehículo
     * @param int $userId ID del propietario (para verificar ownership)
     * @return array|null Datos del vehículo o null si no existe
     */
    public function getById(int $vehicleId, int $userId): ?array
    {
        return Database::fetchOne(
            self::VEHICLE_SELECT . ' WHERE v.id = ? AND v.user_id = ? AND v.deleted_at IS NULL',
            [$vehicleId, $userId]
        );
    }

    /**
     * Obtener todos los vehículos de un usuario
     *
     * @param int $userId ID del usuario
     * @param bool $activeOnly Solo vehículos activos
     * @return array Lista de vehículos
     */
    public function getUserVehicles(int $userId, bool $activeOnly = true): array
    {
        $sql    = self::VEHICLE_SELECT . ' WHERE v.user_id = ? AND v.deleted_at IS NULL';
        $params = [$userId];

        if ($activeOnly) {
            $sql      .= ' AND v.status = ?';
            $params[]  = 'active';
        }

        $sql .= ' ORDER BY v.is_primary DESC, v.created_at DESC';

        return Database::fetchAll($sql, $params);
    }

    /**
     * Obtener el vehículo principal del usuario
     *
     * @param int $userId ID del usuario
     * @return array|null Vehículo principal o null si no existe
     */
    public function getPrimaryVehicle(int $userId): ?array
    {
        return Database::fetchOne(
            self::VEHICLE_SELECT . ' WHERE v.user_id = ? AND v.is_primary = TRUE AND v.deleted_at IS NULL AND v.status = ?',
            [$userId, 'active']
        );
    }

    /**
     * Crea o actualiza los documentos del vehículo presentes en $data.
     *
     * @return bool true si se escribió al menos un documento
     */
    public function saveDocuments(int $vehicleId, array $data): bool
    {
        $changed = false;

        foreach (self::DOCUMENT_TYPES as $type) {
            $fields = [];
            if (array_key_exists("{$type}_number", $data))          $fields['document_number'] = $data["{$type}_number"] ?: null;
            if (array_key_exists("{$type}_expiration_date", $data)) $fields['expires_at']      = $data["{$type}_expiration_date"] ?: null;
            if (array_key_exists("{$type}_document_url", $data))    $fields['document_url']    = $data["{$type}_document_url"] ?: null;

            if (empty($fields)) {
                continue;
            }
            if (!empty($fields['document_url'])) {
                $fields['uploaded_at'] = date('Y-m-d H:i:s');
            }

            $columns = array_keys($fields);
            $updates = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $columns));
            Database::query(
                'INSERT INTO vehicle_documents (vehicle_id, document_type, ' . implode(', ', $columns) . ')
                 VALUES (?, ?' . str_repeat(', ?', count($columns)) . ")
                 ON DUPLICATE KEY UPDATE {$updates}",
                array_merge([$vehicleId, $type], array_values($fields))
            );
            $changed = true;
        }

        return $changed;
    }

    /**
     * Arma y adquiere los locks nombrados de MySQL para la placa (y VIN, si se
     * proporciona) que create() está a punto de verificar/insertar.
     *
     * @return string[] Claves de lock efectivamente adquiridas (para liberar después)
     */
    private function acquireUniquenessLocks(string $licensePlate, ?string $vin): array
    {
        $keys = ['vehicle_plate:' . $licensePlate];
        if (!empty($vin)) {
            $keys[] = 'vehicle_vin:' . $vin;
        }

        return $this->acquireLocks($keys);
    }

    /**
     * Adquiere una serie de locks nombrados de MySQL (GET_LOCK), en orden, para
     * serializar el chequeo de unicidad de placa/VIN entre solicitudes
     * concurrentes — license_plate/vin no tienen UNIQUE a nivel de BD (ver
     * comentario en create()), así que no hay una fila propia que bloquear.
     *
     * @param string[] $keys
     * @return string[] Claves efectivamente adquiridas
     * @throws DomainException Si no se pudo adquirir algún lock a tiempo
     */
    private function acquireLocks(array $keys): array
    {
        $acquired = [];

        foreach ($keys as $key) {
            $result = Database::fetchOne('SELECT GET_LOCK(?, ?) AS acquired', [$key, self::UNIQUENESS_LOCK_TIMEOUT]);

            if ($result === null || (int)$result['acquired'] !== 1) {
                $this->releaseLocks($acquired);
                throw new DomainException(
                    'No se pudo procesar la solicitud en este momento. Inténtalo de nuevo.',
                    503
                );
            }

            $acquired[] = $key;
        }

        return $acquired;
    }

    /**
     * Libera los locks nombrados adquiridos por acquireLocks()/acquireUniquenessLocks().
     *
     * @param string[] $keys
     */
    private function releaseLocks(array $keys): void
    {
        foreach ($keys as $key) {
            Database::query('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }
}
