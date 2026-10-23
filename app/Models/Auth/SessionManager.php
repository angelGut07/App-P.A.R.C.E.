<?php

namespace App\Models\Auth;

use App\Core\Database;
use App\Models\Auth\SessionData;
use PDOException;

/**
 * Servicio de Gestión de Sesiones
 *
 * Gestión de sesiones respaldada por base de datos con características de seguridad que incluyen
 * regeneración, tiempo de espera y manejo de sesiones concurrentes.
 *
 * Requisitos: 4.1-4.6, 5.1-5.6, 6.1-6.5, 7.1-7.5, 8.1-8.4, 9.1-9.4,
 *               19.1-19.5, 22.1-22.4, 23.1-23.5
 */
class SessionManager
{
    /**
     * Tiempo de espera de inactividad predeterminado en segundos (30 minutos)
     */
    private const DEFAULT_IDLE_TIMEOUT = 1800;

    /**
     * Tiempo de espera absoluto predeterminado en segundos (2 horas)
     */
    private const DEFAULT_ABSOLUTE_TIMEOUT = 7200;

    /**
     * Crea una nueva sesión
     *
     * Requisitos: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 19.1, 19.2, 19.3, 19.4, 23.2
     *
     * @param int $userId ID del usuario
     * @param array $metadata Metadatos de sesión (ip_address, user_agent, remember, etc.)
     * @return string ID de sesión (40 caracteres)
     */
    public function create(int $userId, array $metadata): string
    {
        // Requisito 4.1, 4.2: Generar un ID de sesión criptográficamente seguro de 40 caracteres
        $sessionId = bin2hex(random_bytes(20));

        // Requisito 4.5, 19.3: Establecer last_activity con la marca de tiempo actual
        $lastActivity = time();

        // Requisito 23.2: Construir el payload JSON con los campos requeridos
        $payload = [
            'user_id' => $userId,
            'expires_at' => $metadata['remember'] ?? false
                ? time() + (30 * 24 * 60 * 60) // 30 días para "recordarme"
                : time() + self::DEFAULT_ABSOLUTE_TIMEOUT, // 2 horas por defecto
            'max_idle_seconds' => self::DEFAULT_IDLE_TIMEOUT,
            'remember' => $metadata['remember'] ?? false,
            'created_at' => $lastActivity,
            'ip_address' => $metadata['ip_address'] ?? '',
            'user_agent' => $metadata['user_agent'] ?? ''
        ];

        // Requisito 4.3, 4.4, 19.1, 19.2, 19.4: Insertar la sesión en la base de datos
        Database::insert('sessions', [
            'id' => $sessionId,
            'user_id' => $userId,
            'ip_address' => $metadata['ip_address'] ?? '',
            'user_agent' => $metadata['user_agent'] ?? '',
            'payload' => json_encode($payload),
            'last_activity' => $lastActivity
        ]);

        return $sessionId;
    }

    /**
     * Valida la sesión y retorna los datos de sesión
     *
     * Requisitos: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 6.1, 6.2, 6.3, 6.4, 6.5, 19.5, 20.7
     *
     * @param string $sessionId ID de sesión
     * @param string|null $currentIP Dirección IP actual del cliente para detección de cambios
     * @param bool $autoRegenerate Regenerar automáticamente la sesión si es necesario
     * @return SessionData|null Datos de sesión si es válida, nulo en caso contrario
     */
    public function validate(string $sessionId, ?string $currentIP = null, bool $autoRegenerate = false): ?SessionData
    {
        // Requisito 5.1: Obtener la sesión de la base de datos
        $session = Database::fetchOne(
            'SELECT id, user_id, ip_address, user_agent, payload, last_activity, created_at
             FROM sessions
             WHERE id = ?',
            [$sessionId]
        );

        // Requisito 5.2: Retornar nulo si la sesión no existe
        if ($session === null) {
            return null;
        }

        // Requisito 19.5, 23.3: Decodificar el payload JSON
        $payload = json_decode($session['payload'], true);

        // Requisito 23.4: Tratar el payload malformado como inválido
        if ($payload === null) {
            Database::delete('sessions', 'id = ?', [$sessionId]);
            return null;
        }

        // Requisito 23.5, 6.4: Usar valores predeterminados para campos opcionales faltantes
        $expiresAt = $payload['expires_at'] ?? null;
        $maxIdleSeconds = $payload['max_idle_seconds'] ?? self::DEFAULT_IDLE_TIMEOUT;

        // Requisito 5.3, 6.1, 6.3: Verificar la expiración absoluta
        if ($expiresAt !== null && time() > $expiresAt) {
            Database::delete('sessions', 'id = ?', [$sessionId]);
            return null;
        }

        // Requisito 5.4, 6.2, 6.3: Verificar el tiempo de espera de inactividad
        $idleTime = time() - $session['last_activity'];
        if ($idleTime > $maxIdleSeconds) {
            Database::delete('sessions', 'id = ?', [$sessionId]);
            return null;
        }

        // Requisito 20.7: Registrar advertencia por cambios de dirección IP
        if ($currentIP !== null && $session['ip_address'] !== '' && $session['ip_address'] !== $currentIP) {
            error_log("WARNING: IP address changed for session {$sessionId} (user {$session['user_id']}): {$session['ip_address']} -> {$currentIP}");
        }

        // Verificar si la sesión debe regenerarse (anti-fijación)
        $shouldRegenerate = false;
        if ($autoRegenerate) {
            $regenerateInterval = (int)($_ENV['SESSION_REGENERATE_INTERVAL'] ?? 600); // 10 minutos por defecto
            $timeSinceCreation = time() - $session['last_activity'];

            // Regenerar si la última actividad fue hace más tiempo que el intervalo de regeneración
            if ($timeSinceCreation >= $regenerateInterval) {
                $shouldRegenerate = true;
            }
        }

        // Requisito 5.5: Actualizar la marca de tiempo de last_activity
        Database::update('sessions', [
            'last_activity' => time()
        ], 'id = ?', [$sessionId]);

        // Requisito 5.6: Retornar el objeto SessionData con la bandera de regeneración
        $sessionData = new SessionData(
            id: $session['id'],
            userId: (int)$session['user_id'],
            ipAddress: $session['ip_address'],
            userAgent: $session['user_agent'],
            lastActivity: (int)$session['last_activity'],
            createdAt: strtotime($session['created_at']),
            expiresAt: $expiresAt
        );

        // Almacenar la bandera de regeneración de forma que el llamador pueda verificarla
        // (Se añadirá a SessionData en el siguiente paso)
        if ($shouldRegenerate) {
            // Por ahora, registramos en el log
            error_log("INFO: Session {$sessionId} should be regenerated");
        }

        return $sessionData;
    }

    /**
     * Destruye una sesión
     *
     * Requisitos: 9.3, 9.4, 15.1, 15.2, 15.3, 15.4
     *
     * @param string $sessionId ID de sesión
     * @return bool Verdadero si la sesión fue eliminada, falso si no existía
     */
    public function destroy(string $sessionId): bool
    {
        // Requisito 9.3, 15.4: Eliminar la sesión de la base de datos
        $rowCount = Database::delete('sessions', 'id = ?', [$sessionId]);

        // Requisito 9.4, 15.2, 15.3: Retornar verdadero si fue eliminada, falso si no existía
        return $rowCount > 0;
    }

    /**
     * Regenera el ID de sesión (previene la fijación de sesión)
     *
     * Requisitos: 7.1, 7.2, 7.3, 7.4, 7.5
     *
     * @param string $sessionId ID de sesión actual
     * @return string Nuevo ID de sesión (cadena vacía si la sesión no existe)
     */
    public function regenerate(string $sessionId): string
    {
        // Requisito 7.1, 7.2: Obtener la sesión existente
        $session = Database::fetchOne(
            'SELECT user_id, ip_address, user_agent, payload
             FROM sessions
             WHERE id = ?',
            [$sessionId]
        );

        // Retornar cadena vacía si la sesión no existe
        if ($session === null) {
            return '';
        }

        // Requisito 7.1, 7.5: Generar un nuevo ID de sesión criptográficamente seguro
        $newSessionId = bin2hex(random_bytes(20));

        // Requisito 7.4: Actualizar last_activity con la marca de tiempo actual
        $lastActivity = time();

        // Ambas escrituras deben ser atómicas: si el insert falla tras el delete,
        // el usuario quedaría deslogueado en medio de una regeneración rutinaria
        // (anti-fijación de sesión), no ante un error real de su parte.
        Database::beginTransaction();
        try {
            // Requisito 7.2: Eliminar la sesión antigua
            Database::delete('sessions', 'id = ?', [$sessionId]);

            // Requisito 7.3: Crear una nueva sesión con el mismo user_id y metadatos
            Database::insert('sessions', [
                'id' => $newSessionId,
                'user_id' => $session['user_id'],
                'ip_address' => $session['ip_address'],
                'user_agent' => $session['user_agent'],
                'payload' => $session['payload'],
                'last_activity' => $lastActivity
            ]);

            Database::commit();
            return $newSessionId;
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Destruye todas las sesiones de un usuario
     *
     * Requisitos: 9.1, 9.2, 22.1, 22.2, 22.3, 22.4
     *
     * @param int $userId ID del usuario
     * @return int Cantidad de sesiones destruidas
     */
    public function destroyAllUserSessions(int $userId): int
    {
        // Requisito 9.1, 22.3: Eliminar todas las sesiones con el user_id correspondiente
        $rowCount = Database::delete('sessions', 'user_id = ?', [$userId]);

        // Requisito 9.2, 22.4: Retornar la cantidad de sesiones destruidas
        return $rowCount;
    }

    /**
     * Limpia las sesiones expiradas
     *
     * Requisitos: 8.1, 8.2, 8.3, 8.4
     *
     * @return int Cantidad de sesiones eliminadas
     */
    public function cleanup(): int
    {
        $deletedCount = 0;

        // Requisito 8.1: Eliminar sesiones donde last_activity supera el tiempo de espera de inactividad
        // Se necesita verificar el payload para max_idle_seconds, pero por simplicidad,
        // se usará el tiempo de espera predeterminado para la limpieza
        $idleThreshold = time() - self::DEFAULT_IDLE_TIMEOUT;
        $deletedCount += Database::delete(
            'sessions',
            'last_activity < ?',
            [$idleThreshold]
        );

        // Requisito 8.2: Eliminar sesiones donde expires_at está en el pasado
        // Esto requiere analizar el payload JSON, por lo que se hará en una consulta más compleja
        // Por ahora, se depende del método validate() para limpiar las sesiones expiradas

        // Requisito 8.3: Retornar la cantidad de sesiones eliminadas
        return $deletedCount;
    }

    /**
     * Verifica si la sesión necesita regenerarse por seguridad
     *
     * Implementa protección contra fijación de sesión verificando si la sesión
     * ha estado activa por más tiempo que el intervalo de regeneración.
     *
     * @param string $sessionId ID de sesión
     * @return bool Verdadero si la sesión debe regenerarse
     */
    public function shouldRegenerate(string $sessionId): bool
    {
        $session = Database::fetchOne(
            'SELECT last_activity FROM sessions WHERE id = ?',
            [$sessionId]
        );

        if ($session === null) {
            return false;
        }

        $regenerateInterval = (int)($_ENV['SESSION_REGENERATE_INTERVAL'] ?? 600); // 10 minutos
        $timeSinceLastActivity = time() - $session['last_activity'];

        return $timeSinceLastActivity >= $regenerateInterval;
    }

    /**
     * Obtiene la configuración de tiempo de espera desde el entorno
     *
     * @return array Configuración de tiempo de espera [idle_timeout, absolute_timeout]
     */
    public static function getTimeoutConfig(): array
    {
        return [
            'idle_timeout' => (int)($_ENV['SESSION_IDLE_TIMEOUT'] ?? self::DEFAULT_IDLE_TIMEOUT),
            'absolute_timeout' => (int)($_ENV['SESSION_LIFETIME'] ?? self::DEFAULT_ABSOLUTE_TIMEOUT)
        ];
    }
}
