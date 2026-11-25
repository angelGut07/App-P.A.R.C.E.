<?php

namespace App\Models\Payment;

use App\Core\DomainException;

/**
 * Cliente mínimo de la API de Mercado Pago (Checkout Pro) con cURL nativo.
 *
 * El Access Token (MP_ACCESS_TOKEN) vive solo en el servidor; el navegador
 * nunca lo ve. Esta clase no sabe nada de servicios ni de la base de datos:
 * solo habla con Mercado Pago. Las reglas de negocio están en PaymentService.
 */
class MercadoPagoClient
{
    private const API_URL = 'https://api.mercadopago.com';
    private const TIMEOUT = 15;

    /** Mensaje para el cliente cuando la pasarela no responde: el efectivo siempre funciona. */
    public const UNAVAILABLE = 'Los pagos en línea no están disponibles en este momento. Puedes pagar en efectivo.';

    /** Indica si hay un Access Token configurado. */
    public static function isConfigured(): bool
    {
        return trim((string)($_ENV['MP_ACCESS_TOKEN'] ?? '')) !== '';
    }

    /**
     * Crea una preferencia de pago (el "link" de Checkout Pro).
     *
     * @return array Respuesta de Mercado Pago (id, init_point, ...)
     */
    public function createPreference(array $preference): array
    {
        return $this->request('POST', '/checkout/preferences', $preference);
    }

    /**
     * Consulta un pago por su ID. Es la fuente de verdad: lo que diga la URL
     * de retorno o el webhook se confirma siempre aquí.
     *
     * @throws DomainException 404 si el pago no existe para esta cuenta
     */
    public function getPayment(string $paymentId): array
    {
        if (!preg_match('/^\d{1,30}$/', $paymentId)) {
            throw new DomainException('Identificador de pago inválido', 400);
        }

        return $this->request('GET', '/v1/payments/' . $paymentId);
    }

    /** Datos de la cuenta dueña del token (sirve para verificar la configuración). */
    public function whoAmI(): array
    {
        return $this->request('GET', '/users/me');
    }

    /**
     * @throws DomainException 503 sin token o sin conexión, 404 recurso inexistente, 502 error de Mercado Pago
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $token = trim((string)($_ENV['MP_ACCESS_TOKEN'] ?? ''));
        if ($token === '') {
            error_log('MercadoPagoClient: falta MP_ACCESS_TOKEN');
            throw new DomainException(self::UNAVAILABLE, 503);
        }

        $ch = curl_init(self::API_URL . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false || $curlError !== '') {
            error_log("MercadoPagoClient: {$method} {$path} sin respuesta ({$curlError})");
            throw new DomainException(self::UNAVAILABLE, 503);
        }

        $data = json_decode($response, true);

        if ($statusCode === 404) {
            throw new DomainException('No se encontró el pago en Mercado Pago', 404);
        }
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($data)) {
            // El detalle va al log (con el X-Request-Id); al cliente, un mensaje genérico.
            $detail = is_array($data) ? ($data['message'] ?? $data['error'] ?? '') : substr((string)$response, 0, 200);
            error_log("MercadoPagoClient: {$method} {$path} respondió HTTP {$statusCode} {$detail}");
            throw new DomainException(self::UNAVAILABLE, 502);
        }

        return $data;
    }
}
