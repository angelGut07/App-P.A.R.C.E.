<?php

namespace App\Core;

use Exception;
use Throwable;

/**
 * Domain Exception
 *
 * Thrown by service classes for expected, user-facing business-rule violations
 * (e.g. "ya existe un vehículo con esta placa", "solicitud no encontrada").
 * Unlike a plain \Exception — which ErrorHandler deliberately masks behind a
 * generic "Internal server error" to avoid leaking internal details — a
 * DomainException's message IS meant to reach the client, along with an
 * appropriate HTTP status code instead of always defaulting to 500.
 */
class DomainException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the HTTP status code this exception should map to
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
