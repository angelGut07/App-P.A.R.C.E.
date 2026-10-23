<?php

namespace App\Models\Auth;

use InvalidArgumentException;

/**
 * DTO de Configuración de Límite de Tasa
 *
 * Objeto de transferencia de datos inmutable que define los parámetros de limitación de tasa para los intentos de autenticación.
 * Aplica límites sobre los intentos, el período de decaimiento y la duración del bloqueo.
 *
 * Requisitos: 13.1, 13.2, 13.3, 13.4, 13.5, 25.4
 */
readonly class RateLimitConfig
{
    /**
     * Crea una nueva configuración de límite de tasa
     *
     * @param int $maxAttempts Máximo de intentos permitidos (1-100)
     * @param int $decayMinutes Ventana de tiempo en minutos (1-1440)
     * @param int $lockoutMinutes Duración del bloqueo de cuenta en minutos (1-1440)
     *
     * @throws InvalidArgumentException Si la validación falla
     */
    public function __construct(
        public int $maxAttempts,
        public int $decayMinutes,
        public int $lockoutMinutes
    ) {
        // Requisito 13.1: maxAttempts debe estar entre 1 y 100
        if ($this->maxAttempts < 1 || $this->maxAttempts > 100) {
            throw new InvalidArgumentException(
                'maxAttempts must be between 1 and 100'
            );
        }

        // Requisito 13.2: decayMinutes debe estar entre 1 y 1440
        if ($this->decayMinutes < 1 || $this->decayMinutes > 1440) {
            throw new InvalidArgumentException(
                'decayMinutes must be between 1 and 1440'
            );
        }

        // Requisito 13.3: lockoutMinutes debe estar entre 1 y 1440
        if ($this->lockoutMinutes < 1 || $this->lockoutMinutes > 1440) {
            throw new InvalidArgumentException(
                'lockoutMinutes must be between 1 and 1440'
            );
        }
    }

    /**
     * Crea una configuración de límite de tasa predeterminada
     *
     * Requisito 13.4: maxAttempts=5, decayMinutes=15, lockoutMinutes=30
     *
     * @return self
     */
    public static function default(): self
    {
        return new self(
            maxAttempts: 5,
            decayMinutes: 15,
            lockoutMinutes: 30
        );
    }

    /**
     * Crea una configuración de límite de tasa estricta
     *
     * Requisito 13.5: maxAttempts=3, decayMinutes=10, lockoutMinutes=60
     *
     * @return self
     */
    public static function strict(): self
    {
        return new self(
            maxAttempts: 3,
            decayMinutes: 10,
            lockoutMinutes: 60
        );
    }
}
