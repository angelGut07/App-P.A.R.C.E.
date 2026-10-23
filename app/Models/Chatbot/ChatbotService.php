<?php

namespace App\Models\Chatbot;

use App\Core\DomainException;

/**
 * Asistente de diagnóstico (Groq, API compatible con OpenAI).
 *
 * La clave GROQ_API_KEY vive solo en el servidor; el navegador nunca la ve.
 * El prompt del sistema también se define aquí para que el cliente no pueda
 * cambiar el rol del asistente.
 */
class ChatbotService
{
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const MODEL = 'llama-3.1-8b-instant';
    private const MAX_MESSAGES = 20;
    private const MAX_CONTENT_LENGTH = 1000;

    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres el asistente virtual de P.A.R.C.E (Plataforma de Asistencia Rápida Para Conductores en Emergencia).
Tu rol es diagnosticar problemas vehiculares y recomendar el servicio adecuado.

Los servicios disponibles en PARCE son EXACTAMENTE estos 6:
1. Suministro de Combustible a Domicilio
2. Reparación y Cambio de Neumáticos
3. Carga y Reemplazo de Batería
4. Diagnóstico y Reparación Mecánica
5. Cerrajería Automotriz
6. Grúa y Remolque de Vehículos

Cuando el usuario describa un problema, responde SIEMPRE así:

Si el problema corresponde a uno de los 6 servicios:
- Explica brevemente qué causó el problema
- Termina con exactamente: "SERVICIO_SUGERIDO: [nombre exacto del servicio de la lista]"

Si el problema NO corresponde a ninguno de los 6 servicios:
- Explica qué causó el problema
- Di qué tipo de servicio especializado necesita (ej: taller de transmisión, servicio de AC, etc.)
- Termina con exactamente: "SERVICIO_SUGERIDO: Diagnóstico y Reparación Mecánica"

Responde siempre en español, de forma clara y breve. Usa emojis relevantes.
PROMPT;

    /**
     * @param array $messages Historial [{role: user|assistant, content: string}]
     * @return string         Respuesta del asistente
     * @throws DomainException 400 historial inválido, 503 servicio no configurado o caído
     */
    public function reply(array $messages): string
    {
        $apiKey = $_ENV['GROQ_API_KEY'] ?? '';
        if ($apiKey === '') {
            throw new DomainException('El asistente no está disponible en este momento', 503);
        }

        $history = [];
        foreach (array_slice($messages, -self::MAX_MESSAGES) as $message) {
            $role = $message['role'] ?? '';
            $content = trim((string)($message['content'] ?? ''));
            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                throw new DomainException('Historial de mensajes inválido', 400);
            }
            $history[] = ['role' => $role, 'content' => mb_substr($content, 0, self::MAX_CONTENT_LENGTH)];
        }

        if ($history === [] || end($history)['role'] !== 'user') {
            throw new DomainException('El último mensaje debe ser del usuario', 400);
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => self::MODEL,
                'messages'    => array_merge([['role' => 'system', 'content' => self::SYSTEM_PROMPT]], $history),
                'temperature' => 0.4,
                'max_tokens'  => 400,
            ]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $reply = $response !== false ? (json_decode($response, true)['choices'][0]['message']['content'] ?? null) : null;

        if ($curlError !== '' || $statusCode < 200 || $statusCode >= 300 || !is_string($reply)) {
            error_log("ChatbotService: Groq respondió HTTP {$statusCode} {$curlError}");
            throw new DomainException('El asistente no está disponible en este momento', 503);
        }

        return trim($reply);
    }
}
