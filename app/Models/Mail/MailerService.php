<?php

namespace App\Models\Mail;

/**
 * Servicio de Envío de Correo (Resend)
 *
 * Envuelve la API HTTP de Resend (https://resend.com/docs/api-reference/emails/send-email)
 * sin dependencias externas — una sola llamada POST con cURL, ya disponible
 * en PHP sin necesidad de agregar un SDK vía Composer.
 *
 * Si RESEND_API_KEY no está configurada, el envío falla de forma controlada
 * (retorna false y registra el motivo en el log) en vez de lanzar una
 * excepción — un correo no enviado nunca debe tumbar el flujo que lo pidió
 * (ej. no debe filtrar al usuario si su email existe o no en el sistema).
 */
class MailerService
{
    private const API_URL = 'https://api.resend.com/emails';

    /**
     * Envía un correo HTML.
     *
     * @param string $to      Destinatario
     * @param string $subject Asunto
     * @param string $html    Cuerpo en HTML
     * @return bool           Verdadero si Resend aceptó el envío
     */
    public function send(string $to, string $subject, string $html): bool
    {
        $apiKey = $_ENV['RESEND_API_KEY'] ?? '';

        if ($apiKey === '') {
            error_log("MailerService: RESEND_API_KEY no configurada — correo a {$to} no enviado");
            return false;
        }

        $from = $_ENV['MAIL_FROM'] ?? 'P.A.R.C.E <onboarding@resend.dev>';

        $payload = json_encode([
            'from'    => $from,
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            error_log("MailerService: error de red enviando a {$to}: {$curlError}");
            return false;
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return true;
        }

        error_log("MailerService: Resend rechazó el envío a {$to} (HTTP {$statusCode}): {$response}");
        return false;
    }
}
