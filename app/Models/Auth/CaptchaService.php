<?php

namespace App\Models\Auth;

/**
 * Verificación CAPTCHA con Cloudflare Turnstile
 *
 * Distingue a las personas de los programas automáticos en el registro, la
 * recuperación de contraseña y el inicio de sesión (este último solo tras
 * varios intentos fallidos). Turnstile es gratuito y casi siempre se resuelve
 * solo, sin acertijos.
 *
 * Se activa al configurar TURNSTILE_SITE_KEY y TURNSTILE_SECRET_KEY; sin ellas
 * la verificación se omite, igual que el correo sin RESEND_API_KEY. La clave
 * secreta solo vive en el servidor; la del sitio es pública y la usa el widget.
 *
 * https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
class CaptchaService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Turnstile emite tokens de hasta 2048 caracteres */
    private const MAX_TOKEN_LENGTH = 2048;

    /** POST de formulario: (url, campos) → cuerpo de la respuesta, o null si falla */
    private \Closure $httpPost;

    /**
     * @param \Closure|null $httpPost Reemplazo del POST real (pruebas)
     */
    public function __construct(?\Closure $httpPost = null)
    {
        $this->httpPost = $httpPost ?? fn(string $url, array $fields): ?string => self::curlPost($url, $fields);
    }

    /** Verdadero si están configuradas las dos claves */
    public function isEnabled(): bool
    {
        return $this->siteKey() !== null && self::env('TURNSTILE_SECRET_KEY') !== null;
    }

    /** Clave pública que necesita el widget del navegador */
    public function siteKey(): ?string
    {
        return self::env('TURNSTILE_SITE_KEY');
    }

    /**
     * Verifica el token que generó el widget en el navegador. Cada token sirve
     * una sola vez y solo para el formulario que lo pidió.
     *
     * @param string|null $token          Token del widget (campo captcha_token)
     * @param string      $ipAddress      IP del cliente
     * @param string      $expectedAction Formulario: login, register o forgot-password
     * @return bool Verdadero si es una persona (o si el CAPTCHA no está configurado)
     */
    public function verify(?string $token, string $ipAddress, string $expectedAction): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $token = trim((string)$token);
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        $body = ($this->httpPost)(self::VERIFY_URL, [
            'secret'   => self::env('TURNSTILE_SECRET_KEY'),
            'response' => $token,
            'remoteip' => $ipAddress,
        ]);

        // Sin respuesta de Cloudflare se rechaza: dejar pasar sin verificar
        // abriría la puerta justo cuando el servicio no está disponible.
        if ($body === null) {
            error_log('CaptchaService: no se pudo contactar a Turnstile');
            return false;
        }

        $result = json_decode($body, true);
        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            $codes = is_array($result['error-codes'] ?? null) ? implode(', ', $result['error-codes']) : 'respuesta inválida';
            error_log("CaptchaService: token rechazado ({$codes})");
            return false;
        }

        $action = (string)($result['action'] ?? '');
        if ($action !== '' && $action !== $expectedAction) {
            error_log("CaptchaService: token de '{$action}' usado en '{$expectedAction}'");
            return false;
        }

        return true;
    }

    private static function env(string $key): ?string
    {
        $value = trim((string)($_ENV[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    private static function curlPost(string $url, array $fields): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => 8,
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $response === false || $statusCode < 200 || $statusCode >= 300 ? null : (string)$response;
    }
}
