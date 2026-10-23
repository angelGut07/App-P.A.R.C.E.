<?php

namespace App\Core;

/**
 * Clase de respuesta HTTP
 *
 * Gestiona las respuestas HTTP tanto para vistas web como para respuestas JSON de la API.
 * Soporta códigos de estado, encabezados, cookies y tipos de contenido.
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private array $cookies = [];
    private mixed $content = null;

    /**
     * Establece el código de estado HTTP de la respuesta
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Retorna el código de estado HTTP actual de la respuesta
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Establece un encabezado HTTP en la respuesta
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Establece múltiples encabezados HTTP de una sola vez
     */
    public function setHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
        return $this;
    }

    /**
     * Agrega una cookie a la respuesta
     */
    public function setCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true
    ): self {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly
        ];
        return $this;
    }

    /**
     * Establece el contenido del cuerpo de la respuesta
     */
    public function setContent(mixed $content): self
    {
        $this->content = $content;
        return $this;
    }


    /**
     * Configura la respuesta como JSON con el código de estado indicado
     */
    public function json(array $data, int $statusCode = 200): self
    {
        $this->statusCode = $statusCode;
        $this->setHeader('Content-Type', 'application/json');
        $this->content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this;
    }

    /**
     * Configura la respuesta como HTML con el código de estado indicado
     */
    public function html(string $html, int $statusCode = 200): self
    {
        $this->statusCode = $statusCode;
        $this->setHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->content = $html;
        return $this;
    }

    /**
     * Renderiza una plantilla de vista y la establece como contenido HTML de la respuesta
     */
    public function view(string $view, array $data = [], int $statusCode = 200): self
    {
        $viewPath = __DIR__ . '/../../app/Views/' . str_replace('.', '/', $view) . '.php';

        if (!file_exists($viewPath)) {
            throw new \Exception("View not found: {$view}");
        }

        ob_start();
        extract($data);
        require $viewPath;
        $html = ob_get_clean();

        return $this->html($html, $statusCode);
    }

    /**
     * Configura la respuesta como una redirección a la URL indicada
     */
    public function redirect(string $url, int $statusCode = 302): self
    {
        $this->statusCode = $statusCode;
        $this->setHeader('Location', $url);
        return $this;
    }

    /**
     * Envía la respuesta al cliente: código de estado, encabezados, cookies y contenido
     */
    public function send(): void
    {
        // Establecer el código de estado HTTP
        http_response_code($this->statusCode);

        // Enviar encabezados HTTP
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Enviar cookies
        foreach ($this->cookies as $cookie) {
            setcookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expires'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httpOnly']
            );
        }

        // Enviar el contenido de la respuesta
        if ($this->content !== null) {
            echo $this->content;
        }
    }

    /**
     * Crea y retorna una respuesta JSON de éxito estandarizada
     */
    public static function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): self
    {
        $response = new self();
        return $response->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    /**
     * Crea y retorna una respuesta JSON de error estandarizada
     */
    public static function error(string $message, mixed $errors = null, int $statusCode = 400): self
    {
        $response = new self();
        return $response->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], $statusCode);
    }
}
