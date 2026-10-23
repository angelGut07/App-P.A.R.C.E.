<?php

namespace App\Models\Auth;

use InvalidArgumentException;

/**
 * DTO de Datos de Sesión
 *
 * Objeto de transferencia de datos inmutable que representa la información de sesión validada.
 * Incluye el ID de usuario, marcas de tiempo, metadatos y métodos de validación para expiración y tiempo de espera de inactividad.
 *
 * Requisitos: 11.1, 11.2, 11.3, 11.4, 11.5, 25.2
 */
readonly class SessionData
{
    /**
     * Crea un nuevo objeto de datos de sesión
     *
     * @param string $id ID de sesión
     * @param int $userId ID del usuario
     * @param string $ipAddress Dirección IP del cliente
     * @param string $userAgent Agente de usuario del cliente
     * @param int $lastActivity Marca de tiempo de última actividad (timestamp Unix)
     * @param int $createdAt Marca de tiempo de creación (timestamp Unix)
     * @param int|null $expiresAt Marca de tiempo de expiración (timestamp Unix, nulo si no expira)
     *
     * @throws InvalidArgumentException Si la validación falla
     */
    public function __construct(
        public string $id,
        public int $userId,
        public string $ipAddress,
        public string $userAgent,
        public int $lastActivity,
        public int $createdAt,
        public ?int $expiresAt = null
    ) {
        // Requisito 11.3: userId debe ser un entero positivo
        if ($this->userId <= 0) {
            throw new InvalidArgumentException(
                'userId must be a positive integer'
            );
        }

        // Requisito 11.4: ipAddress debe tener formato IPv4 o IPv6 válido
        if (!filter_var($this->ipAddress, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException(
                'ipAddress must be a valid IPv4 or IPv6 address'
            );
        }

        // Requisito 11.5: lastActivity debe ser un timestamp Unix válido
        if ($this->lastActivity <= 0) {
            throw new InvalidArgumentException(
                'lastActivity must be a valid Unix timestamp'
            );
        }

        // Requisito 11.5: createdAt debe ser un timestamp Unix válido
        if ($this->createdAt <= 0) {
            throw new InvalidArgumentException(
                'createdAt must be a valid Unix timestamp'
            );
        }

        // Validar que el ID de sesión no esté vacío
        if (empty($this->id)) {
            throw new InvalidArgumentException(
                'Session ID cannot be empty'
            );
        }
    }

    /**
     * Verifica si la sesión está expirada
     *
     * Requisito 11.1: Retorna verdadero si el tiempo actual supera expiresAt
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->expiresAt !== null && time() > $this->expiresAt;
    }

    /**
     * Verifica si la sesión está inactiva
     *
     * Requisito 11.2: Retorna verdadero si el tiempo transcurrido desde lastActivity supera el umbral
     *
     * @param int $maxIdleSeconds Tiempo máximo de inactividad en segundos
     * @return bool
     */
    public function isIdle(int $maxIdleSeconds): bool
    {
        return (time() - $this->lastActivity) > $maxIdleSeconds;
    }
}
