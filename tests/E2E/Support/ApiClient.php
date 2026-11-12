<?php

namespace Tests\E2E\Support;

/**
 * Cliente HTTP de la suite E2E: se comporta como una pestaña del navegador.
 *
 * Guarda las cookies que envía el servidor (la sesión es una cookie HttpOnly)
 * y las devuelve en cada petición, y manda el header X-Active-Role con la vista
 * activa, igual que el frontend. Cada instancia es una sesión independiente:
 * cliente, mecánico y administrador usan una cada uno.
 */
final class ApiClient
{
    /** @var array<string, string> Cookies vigentes, nombre => valor */
    private array $cookies = [];

    /** @var array<string, string> Headers adicionales, nombre => valor */
    private array $headers = [];

    /**
     * @param string $baseUrl    URL base de la API (termina en /api)
     * @param string $activeRole Vista activa: customer, mechanic o administrator
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $activeRole
    ) {
    }

    /**
     * Copia de este cliente que además envía un header en cada petición
     * (p. ej. el token del mantenimiento programado)
     */
    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->headers[$name] = $value;

        return $copy;
    }

    public function get(string $path): ApiResponse
    {
        return $this->send('GET', $path);
    }

    public function post(string $path, array $body = []): ApiResponse
    {
        return $this->send('POST', $path, $body);
    }

    public function put(string $path, array $body = []): ApiResponse
    {
        return $this->send('PUT', $path, $body);
    }

    public function delete(string $path, array $body = []): ApiResponse
    {
        return $this->send('DELETE', $path, $body);
    }

    /**
     * Sube un archivo como multipart/form-data (el mismo formato de un <input type="file">)
     *
     * @param array  $fields   Campos de texto del formulario
     * @param string $field    Nombre del campo del archivo
     * @param string $contents Bytes del archivo
     */
    public function upload(string $path, array $fields, string $field, string $filename, string $contents, string $mime): ApiResponse
    {
        $fields[$field] = new \CURLStringFile($contents, $filename, $mime);

        return $this->send('POST', $path, null, $fields);
    }

    private function send(string $method, string $path, ?array $json = null, ?array $multipart = null): ApiResponse
    {
        $headers = ['Accept: application/json', 'X-Active-Role: ' . $this->activeRole];
        foreach ($this->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }
        if ($this->cookies !== []) {
            $pairs = array_map(fn(string $name, string $value) => "{$name}={$value}", array_keys($this->cookies), $this->cookies);
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line): int {
                $this->rememberCookie($line);
                return strlen($line);
            },
        ];

        if ($multipart !== null) {
            $options[CURLOPT_POSTFIELDS] = $multipart; // curl arma el multipart y su Content-Type
        } elseif ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($json === [] ? new \stdClass() : $json);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        $curl = curl_init($this->baseUrl . $path);
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);

        if ($raw === false) {
            throw new \RuntimeException("{$method} {$path} no obtuvo respuesta: " . curl_error($curl));
        }

        $decoded = json_decode((string)$raw, true);

        return new ApiResponse(
            (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            is_array($decoded) ? $decoded : [],
            (string)$raw
        );
    }

    /**
     * Guarda o borra una cookie a partir de un header Set-Cookie
     */
    private function rememberCookie(string $headerLine): void
    {
        if (!preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)(.*)$/i', trim($headerLine), $match)) {
            return;
        }
        [, $name, $value, $attributes] = $match;

        $expires = preg_match('/expires=([^;]+)/i', $attributes, $date) ? strtotime($date[1]) : false;
        $deleted = $value === '' || $value === 'deleted'
            || preg_match('/max-age=(0|-)/i', $attributes)
            || ($expires !== false && $expires < time());

        if ($deleted) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }
}
