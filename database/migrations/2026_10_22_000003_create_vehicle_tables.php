<?php

use App\Core\Migration;

/**
 * Vehículos y sus documentos obligatorios (SOAT y revisión técnico-mecánica).
 *
 * Normalización (3FN): los datos de cada documento (número, vencimiento,
 * archivo) dependen del par (vehículo, tipo de documento), no del vehículo
 * solo. Por eso viven en `vehicle_documents` y no como columnas repetidas
 * soat_* / tecnomecanica_* dentro de `vehicles`.
 *
 * La placa y el VIN no llevan UNIQUE a nivel de BD porque un vehículo
 * eliminado (soft delete) debe poder volver a registrarse. La unicidad entre
 * vehículos activos la garantiza VehicleService con locks con nombre.
 */
class CreateVehicleTables extends Migration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE vehicles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                license_plate VARCHAR(20) NOT NULL,
                make VARCHAR(50) NOT NULL,
                model VARCHAR(50) NOT NULL,
                year SMALLINT UNSIGNED NOT NULL,
                color VARCHAR(30) NULL,
                vin VARCHAR(17) NULL,
                vehicle_type ENUM('sedan', 'suv', 'truck', 'motorcycle', 'van', 'other') NOT NULL DEFAULT 'sedan',
                fuel_type ENUM('gasoline', 'diesel', 'electric', 'hybrid', 'other') NOT NULL DEFAULT 'gasoline',
                nickname VARCHAR(50) NULL,
                primary_photo_url VARCHAR(500) NULL,
                is_primary BOOLEAN NOT NULL DEFAULT FALSE,
                status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL,

                INDEX idx_vehicles_user (user_id, deleted_at),
                INDEX idx_vehicles_plate (license_plate),
                INDEX idx_vehicles_vin (vin),
                CONSTRAINT fk_vehicles_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE vehicle_documents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id BIGINT UNSIGNED NOT NULL,
                document_type ENUM('soat', 'tecnomecanica') NOT NULL,
                document_number VARCHAR(50) NULL,
                expires_at DATE NULL,
                document_url VARCHAR(500) NULL,
                uploaded_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_vehicle_documents (vehicle_id, document_type),
                INDEX idx_vehicle_documents_expires (document_type, expires_at),
                CONSTRAINT fk_vehicle_documents_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('vehicle_documents');
        $this->dropTable('vehicles');
    }
}
