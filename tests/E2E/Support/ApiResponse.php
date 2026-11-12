<?php

namespace Tests\E2E\Support;

/**
 * Respuesta de la API: código HTTP y cuerpo JSON ya decodificado.
 */
final class ApiResponse
{
    /**
     * @param int    $status Código HTTP
     * @param array  $body   Cuerpo decodificado ({success, data, error, fields})
     * @param string $raw    Cuerpo tal como llegó, para los mensajes de error
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly string $raw
    ) {
    }

    /** Contenido de "data", o un arreglo vacío si no vino */
    public function data(): array
    {
        return is_array($this->body['data'] ?? null) ? $this->body['data'] : [];
    }

    /** Mensaje de error de la API, o cadena vacía */
    public function error(): string
    {
        return (string)($this->body['error'] ?? '');
    }
}
