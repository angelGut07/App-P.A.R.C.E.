<?php

namespace App\Models\Auth;

use Exception;

/**
 * Excepción de Autenticación
 *
 * Se lanza cuando las operaciones de autenticación fallan debido a credenciales inválidas,
 * errores de validación o violaciones de seguridad.
 */
class AuthenticationException extends Exception
{
    /**
     * Crea una nueva excepción de autenticación
     *
     * @param string $message Mensaje de error
     * @param int $code Código de error (predeterminado: 0)
     * @param Exception|null $previous Excepción anterior para encadenamiento
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
