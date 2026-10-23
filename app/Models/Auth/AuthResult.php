<?php

namespace App\Models\Auth;

use InvalidArgumentException;

/**
 * DTO de Resultado de Autenticación
 *
 * Objeto de transferencia de datos inmutable que representa el resultado de un intento de autenticación.
 * Contiene el estado de éxito, identificadores de usuario/sesión e información de errores.
 *
 * Requisitos: 10.1, 10.2, 10.3, 10.4, 10.5, 25.1
 */
readonly class AuthResult
{
    /**
     * Crea un nuevo resultado de autenticación
     *
     * @param bool $success Indica si la autenticación fue exitosa
     * @param int|null $userId ID del usuario (requerido si success=true)
     * @param string|null $sessionId ID de sesión (requerido si success=true)
     * @param string|null $message Mensaje del resultado (requerido si success=false)
     * @param array|null $errors Errores de validación opcionales
     *
     * @throws InvalidArgumentException Si la validación falla
     */
    public function __construct(
        public bool $success,
        public ?int $userId,
        public ?string $sessionId,
        public ?string $message,
        public ?array $errors = null
    ) {
        // Requisito 10.3: Si success es verdadero, userId y sessionId no deben ser nulos
        if ($this->success && ($this->userId === null || $this->sessionId === null)) {
            throw new InvalidArgumentException(
                'userId and sessionId are required when success is true'
            );
        }

        // Requisito 10.4: Si success es falso, se debe proporcionar un mensaje
        if (!$this->success && empty($this->message)) {
            throw new InvalidArgumentException(
                'message is required when success is false'
            );
        }
    }

    /**
     * Crea un resultado de autenticación exitoso
     *
     * @param int $userId ID del usuario
     * @param string $sessionId ID de sesión
     * @return self
     */
    public static function success(int $userId, string $sessionId): self
    {
        return new self(
            success: true,
            userId: $userId,
            sessionId: $sessionId,
            message: 'Authentication successful'
        );
    }

    /**
     * Crea un resultado de autenticación fallido
     *
     * @param string $message Mensaje de error
     * @param array|null $errors Errores de validación opcionales
     * @return self
     */
    public static function failure(string $message, ?array $errors = null): self
    {
        return new self(
            success: false,
            userId: null,
            sessionId: null,
            message: $message,
            errors: $errors
        );
    }
}
