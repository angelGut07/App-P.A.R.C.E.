<?php

use App\Core\Migration;

/**
 * Pagos de servicios y PQR (peticiones, quejas, reclamos y sugerencias).
 *
 * Normalización (3FN):
 * - Un pago pertenece a una solicitud (1:1). Cliente, mecánico y tipo de
 *   servicio se obtienen de la solicitud, no se copian al pago.
 * - `amount` es el monto efectivamente cobrado al momento de pagar (mano de
 *   obra + repuestos). Es un hecho del pago, no del servicio: después de que
 *   existe un pago no se pueden agregar repuestos al servicio.
 * - Nunca se guardan datos completos de tarjeta: solo los últimos 4 dígitos.
 */
class CreatePaymentsAndPqrTables extends Migration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE payments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
                receipt_number VARCHAR(20) NOT NULL UNIQUE COMMENT 'Ej. REC-2026-000123',
                method ENUM('cash', 'card', 'pse') NOT NULL,
                status ENUM('pending', 'confirmed', 'rejected') NOT NULL DEFAULT 'pending',
                amount DECIMAL(10,2) NOT NULL,
                card_last4 CHAR(4) NULL,
                pse_bank VARCHAR(60) NULL,
                reviewed_by BIGINT UNSIGNED NULL COMMENT 'Mecánico o administrador que confirmó/rechazó',
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_payments_status (status, created_at),
                CONSTRAINT fk_payments_request FOREIGN KEY (service_request_id) REFERENCES service_requests(id),
                CONSTRAINT fk_payments_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT chk_payments_amount CHECK (amount >= 0),
                CONSTRAINT chk_payments_card CHECK (method = 'card' OR card_last4 IS NULL),
                CONSTRAINT chk_payments_pse CHECK (method = 'pse' OR pse_bank IS NULL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE pqr (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                ticket_code VARCHAR(20) NOT NULL UNIQUE,
                type ENUM('peticion', 'queja', 'reclamo', 'sugerencia') NOT NULL,
                subject VARCHAR(150) NOT NULL,
                description TEXT NOT NULL,
                status ENUM('pending', 'in_review', 'resolved', 'rejected') NOT NULL DEFAULT 'pending',
                admin_response TEXT NULL,
                responded_by BIGINT UNSIGNED NULL,
                responded_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL,

                INDEX idx_pqr_user (user_id),
                INDEX idx_pqr_status (status, created_at),
                INDEX idx_pqr_deleted_at (deleted_at),
                CONSTRAINT fk_pqr_user FOREIGN KEY (user_id) REFERENCES users(id),
                CONSTRAINT fk_pqr_responded_by FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('pqr');
        $this->dropTable('payments');
    }
}
