<?php

use App\Core\Migration;

/**
 * Solicitudes para obtener el rol de mecánico (revisadas por un administrador).
 *
 * Normalización (3FN): la especialidad y la certificación del mecánico se
 * guardan una sola vez, en su solicitud. El perfil público del mecánico las
 * lee de su solicitud aprobada en vez de copiarlas a otra tabla. La revisión
 * usa una sola pareja revisor/fecha y una sola nota, válidas tanto para
 * aprobar como para rechazar (el `status` dice cuál de las dos fue).
 */
class CreateMechanicApplicationsTable extends Migration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE mechanic_applications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                specialty VARCHAR(100) NULL,
                years_experience TINYINT UNSIGNED NULL,
                certification_title VARCHAR(150) NULL,
                certification_document_url VARCHAR(500) NULL,
                justification TEXT NULL,
                status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at TIMESTAMP NULL,
                review_notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_mechanic_applications_user (user_id),
                INDEX idx_mechanic_applications_status (status, created_at),
                CONSTRAINT fk_mechanic_applications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_mechanic_applications_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT chk_mechanic_applications_review CHECK (
                    status IN ('pending', 'cancelled') OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
                ),
                CONSTRAINT chk_mechanic_applications_rejection CHECK (
                    status <> 'rejected' OR review_notes IS NOT NULL
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('mechanic_applications');
    }
}
