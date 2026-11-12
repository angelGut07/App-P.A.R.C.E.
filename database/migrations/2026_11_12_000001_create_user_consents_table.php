<?php

use App\Core\Migration;

/**
 * Autorizaciones de tratamiento de datos personales (Ley 1581 de 2012).
 *
 * El responsable debe conservar prueba de la autorización de cada titular
 * (Decreto 1377 de 2013, compilado en el Decreto 1074 de 2015). Cada fila es
 * una aceptación de los Términos y la Política de tratamiento de datos en una
 * versión concreta, con la fecha, la IP y el navegador desde donde se dio.
 *
 * Normalización (3FN): una aceptación es un hecho propio, no un atributo del
 * usuario; por eso va en su propia tabla y guarda el historial: cuando la
 * política cambia de versión se pide aceptarla otra vez y queda otra fila.
 */
class CreateUserConsentsTable extends Migration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE user_consents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                policy_version VARCHAR(20) NOT NULL,
                accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,

                UNIQUE KEY uq_user_consents_user_version (user_id, policy_version),
                CONSTRAINT fk_user_consents_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('user_consents');
    }
}
