<?php

use App\Core\Migration;

/**
 * Catálogo de servicios, solicitudes de asistencia, evidencias fotográficas,
 * calificaciones y encuestas de satisfacción.
 *
 * Normalización (3FN):
 * - El nombre, la descripción y el precio base de cada tipo de servicio viven
 *   en `service_types`; las solicitudes solo guardan su código (FK).
 * - Las calificaciones viven únicamente en `ratings` (cliente→mecánico y
 *   mecánico→cliente), no como columnas repetidas dentro de `service_requests`.
 * - El mecánico que resolvió el servicio es el asignado (`mechanic_id`); no se
 *   guarda una segunda columna con el mismo dato.
 * - La encuesta no repite el cliente: se obtiene de la solicitud.
 */
class CreateServiceTables extends Migration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE service_types (
                code VARCHAR(20) PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                base_price DECIMAL(10,2) NOT NULL DEFAULT 0,
                estimated_minutes_min SMALLINT UNSIGNED NULL,
                estimated_minutes_max SMALLINT UNSIGNED NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            INSERT INTO service_types (code, name, description, base_price, estimated_minutes_min, estimated_minutes_max) VALUES
            ('fuel', 'Suministro de Combustible a Domicilio', 'Llevamos gasolina corriente, extra o ACPM hasta donde estás.', 60000, 20, 35),
            ('tire', 'Reparación y Cambio de Neumáticos', 'Reparación de pinchazos o instalación del repuesto en sitio.', 50000, 25, 45),
            ('battery', 'Carga y Reemplazo de Batería', 'Arranque con cables o reemplazo de la batería en el lugar.', 60000, 15, 30),
            ('engine', 'Diagnóstico y Reparación Mecánica', 'Diagnóstico con escáner OBD y reparación de fallas menores.', 80000, 45, 90),
            ('lockout', 'Cerrajería Automotriz', 'Apertura del vehículo sin daños y programación de llaves.', 70000, 20, 40),
            ('tow', 'Grúa y Remolque de Vehículos', 'Traslado seguro del vehículo al taller o lugar indicado.', 150000, 30, 60),
            ('other', 'Otro', 'Cualquier otra emergencia vial.', 50000, NULL, NULL)
        ");

        $this->execute("
            CREATE TABLE service_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_code VARCHAR(20) NOT NULL UNIQUE COMMENT 'Código público, ej. SR-2026-000123',
                customer_id BIGINT UNSIGNED NOT NULL,
                vehicle_id BIGINT UNSIGNED NOT NULL,
                mechanic_id BIGINT UNSIGNED NULL,
                emergency_type VARCHAR(20) NOT NULL,
                description TEXT NOT NULL,
                priority ENUM('normal', 'urgent', 'critical') NOT NULL DEFAULT 'normal',
                latitude DECIMAL(10,8) NOT NULL,
                longitude DECIMAL(11,8) NOT NULL,
                status ENUM('pending', 'assigned', 'in_progress', 'completed', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
                requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                assigned_at TIMESTAMP NULL,
                started_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                cancelled_at TIMESTAMP NULL,
                expired_at TIMESTAMP NULL,
                cancellation_reason TEXT NULL,
                cancelled_by BIGINT UNSIGNED NULL,
                estimated_cost DECIMAL(10,2) NULL COMMENT 'Mano de obra estimada por el mecánico',
                final_cost DECIMAL(10,2) NULL COMMENT 'Mano de obra final (los repuestos van en service_request_parts)',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL,

                INDEX idx_service_requests_customer (customer_id, status),
                INDEX idx_service_requests_mechanic (mechanic_id, status),
                INDEX idx_service_requests_status (status, priority, requested_at),
                INDEX idx_service_requests_vehicle (vehicle_id),
                INDEX idx_service_requests_deleted_at (deleted_at),
                CONSTRAINT fk_service_requests_type FOREIGN KEY (emergency_type) REFERENCES service_types(code),
                CONSTRAINT fk_service_requests_customer FOREIGN KEY (customer_id) REFERENCES users(id),
                CONSTRAINT fk_service_requests_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
                CONSTRAINT fk_service_requests_mechanic FOREIGN KEY (mechanic_id) REFERENCES users(id),
                CONSTRAINT fk_service_requests_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE service_request_evidences (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_request_id BIGINT UNSIGNED NOT NULL,
                uploaded_by BIGINT UNSIGNED NOT NULL,
                evidence_type ENUM('before', 'during', 'after') NOT NULL,
                image_url VARCHAR(500) NOT NULL,
                original_filename VARCHAR(255) NULL,
                description VARCHAR(500) NULL,
                file_size INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_evidences_request (service_request_id),
                CONSTRAINT fk_evidences_request FOREIGN KEY (service_request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_evidences_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE ratings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_request_id BIGINT UNSIGNED NOT NULL,
                rating_type ENUM('customer_to_mechanic', 'mechanic_to_customer') NOT NULL,
                rater_id BIGINT UNSIGNED NOT NULL,
                ratee_id BIGINT UNSIGNED NOT NULL,
                score TINYINT UNSIGNED NOT NULL,
                punctuality_score TINYINT UNSIGNED NULL,
                quality_score TINYINT UNSIGNED NULL,
                comment TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_ratings_request_type (service_request_id, rating_type),
                INDEX idx_ratings_ratee (ratee_id, rating_type),
                INDEX idx_ratings_rater (rater_id),
                CONSTRAINT fk_ratings_request FOREIGN KEY (service_request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_ratings_rater FOREIGN KEY (rater_id) REFERENCES users(id),
                CONSTRAINT fk_ratings_ratee FOREIGN KEY (ratee_id) REFERENCES users(id),
                CONSTRAINT chk_ratings_score CHECK (score BETWEEN 1 AND 5),
                CONSTRAINT chk_ratings_punctuality CHECK (punctuality_score IS NULL OR punctuality_score BETWEEN 1 AND 5),
                CONSTRAINT chk_ratings_quality CHECK (quality_score IS NULL OR quality_score BETWEEN 1 AND 5)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE surveys (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
                overall_satisfaction TINYINT UNSIGNED NOT NULL,
                would_recommend BOOLEAN NOT NULL,
                comments TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL,

                INDEX idx_surveys_created_at (created_at),
                CONSTRAINT fk_surveys_request FOREIGN KEY (service_request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
                CONSTRAINT chk_surveys_satisfaction CHECK (overall_satisfaction BETWEEN 1 AND 5)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('surveys');
        $this->dropTable('ratings');
        $this->dropTable('service_request_evidences');
        $this->dropTable('service_requests');
        $this->dropTable('service_types');
    }
}
