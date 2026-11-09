<?php

use App\Core\Migration;

/**
 * Bloqueo por cuenta ante intentos fallidos de inicio de sesión
 * (LoginThrottleService). Complementa el límite por IP.
 *
 * Normalización (3FN): cada fila describe un solo correo y todos sus datos
 * dependen de él. Se identifica por el hash SHA-256 del correo normalizado y
 * no por el id del usuario: un correo que no está registrado se comporta
 * igual que uno real (el bloqueo no sirve para averiguar qué correos existen)
 * y no se guarda en claro lo que escribió un atacante. Los instantes son
 * marcas de tiempo Unix, como sessions.last_activity, porque toda la lógica
 * se calcula en PHP.
 */
class CreateLoginThrottlesTable extends Migration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE login_throttles (
                identifier_hash CHAR(64) PRIMARY KEY,
                failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                lockout_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
                locked_until INT UNSIGNED NULL,
                last_failed_at INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_login_throttles_last_failed (last_failed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('login_throttles');
    }
}
