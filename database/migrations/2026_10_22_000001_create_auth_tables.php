<?php

use App\Core\Migration;

/**
 * Identidad y acceso: roles, usuarios, asignación de roles, sesiones y
 * tokens de restablecimiento de contraseña.
 *
 * Normalización (3FN):
 * - Los roles viven en su propia tabla y se asignan por `user_roles` (N:M).
 * - El estado de verificación de email/teléfono NO se guarda aparte: se
 *   deriva de `email_verified_at` / `phone_verified_at` (NULL = no verificado).
 * - El estado de la licencia de conducción (vigente / por vencer / vencida)
 *   NO se guarda: se calcula a partir de `driver_license_expiration_date`.
 */
class CreateAuthTables extends Migration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE roles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL UNIQUE,
                slug VARCHAR(50) NOT NULL UNIQUE,
                description VARCHAR(255) NULL,
                is_system_role BOOLEAN NOT NULL DEFAULT FALSE,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            INSERT INTO roles (name, slug, description, is_system_role) VALUES
            ('Cliente', 'customer', 'Conductor que solicita asistencia', TRUE),
            ('Mecánico', 'mechanic', 'Prestador que atiende solicitudes de asistencia', TRUE),
            ('Administrador', 'administrator', 'Gestiona usuarios, servicios, inventario y pagos', TRUE),
            ('Super administrador', 'super_admin', 'Acceso total a la plataforma', TRUE)
        ");

        $this->execute("
            CREATE TABLE users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                id_number VARCHAR(20) NULL UNIQUE COMMENT 'Cédula',
                phone VARCHAR(20) NULL,
                profile_picture_url VARCHAR(500) NULL,
                account_status ENUM('active', 'suspended', 'deactivated', 'pending_verification') NOT NULL DEFAULT 'active',
                email_verified_at TIMESTAMP NULL,
                phone_verified_at TIMESTAMP NULL,
                driver_license_number VARCHAR(50) NULL,
                driver_license_expiration_date DATE NULL,
                driver_license_document_url VARCHAR(500) NULL,
                driver_license_uploaded_at TIMESTAMP NULL,
                last_login_at TIMESTAMP NULL,
                last_login_ip VARCHAR(45) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL,

                INDEX idx_users_account_status (account_status),
                INDEX idx_users_deleted_at (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE user_roles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                role_id INT UNSIGNED NOT NULL,
                assigned_by BIGINT UNSIGNED NULL,
                assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_user_roles (user_id, role_id),
                INDEX idx_user_roles_role (role_id),
                CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_roles_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE sessions (
                id VARCHAR(255) PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                payload LONGTEXT NOT NULL,
                last_activity INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_sessions_user (user_id),
                INDEX idx_sessions_last_activity (last_activity),
                CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE password_reset_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                ip_address VARCHAR(45) NULL,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_password_reset_user (user_id),
                INDEX idx_password_reset_token (token_hash),
                CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('password_reset_tokens');
        $this->dropTable('sessions');
        $this->dropTable('user_roles');
        $this->dropTable('users');
        $this->dropTable('roles');
    }
}
