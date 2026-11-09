<?php

use App\Core\Migration;

/**
 * Servicios que pide un mecánico en cada solicitud y la decisión del
 * administrador sobre cada uno (aprobación por tipo de servicio).
 *
 * Normalización (3FN): relación muchos a muchos entre `mechanic_applications`
 * y `service_types`. Una solicitud pide varios servicios y un servicio lo
 * piden muchas solicitudes; el estado y el soporte dependen de la pareja
 * completa (clave compuesta). El revisor, la fecha y la nota de la revisión
 * son únicos por solicitud y se quedan en `mechanic_applications`.
 *
 * Los servicios habilitados de un mecánico son los aprobados dentro de sus
 * solicitudes aprobadas: una solicitud posterior ("ampliación") suma
 * servicios sin tocar los anteriores.
 *
 * Los mecánicos aprobados antes de este cambio conservan todos los servicios
 * activos, y las solicitudes pendientes quedan con todos los servicios por
 * revisar, para que nadie pierda lo que ya tenía.
 */
class CreateMechanicApplicationServicesTable extends Migration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE mechanic_application_services (
                application_id BIGINT UNSIGNED NOT NULL,
                service_type_code VARCHAR(20) NOT NULL,
                status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                support_document_url VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (application_id, service_type_code),
                INDEX idx_mechanic_application_services_type (service_type_code, status),
                CONSTRAINT fk_mechanic_application_services_application FOREIGN KEY (application_id)
                    REFERENCES mechanic_applications(id) ON DELETE CASCADE,
                CONSTRAINT fk_mechanic_application_services_type FOREIGN KEY (service_type_code)
                    REFERENCES service_types(code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            INSERT INTO mechanic_application_services (application_id, service_type_code, status)
            SELECT ma.id, st.code, IF(ma.status = 'approved', 'approved', 'pending')
            FROM mechanic_applications ma
            CROSS JOIN service_types st
            WHERE ma.status IN ('approved', 'pending') AND st.is_active = TRUE
        ");
    }

    public function down(): void
    {
        $this->dropTable('mechanic_application_services');
    }
}
