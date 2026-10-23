<?php

/**
 * ATENCIÓN — este archivo declara funciones curl_init()/curl_setopt_array()/
 * curl_exec()/curl_getinfo()/curl_error()/curl_close() FALSAS dentro del
 * namespace App\Models\Mail (bloque de abajo). NO son las funciones
 * reales de la extensión cURL de PHP.
 *
 * Técnica usada: resolución de funciones por namespace de PHP — una llamada
 * sin calificar a una función dentro de un namespace (ej. `curl_exec(...)`
 * escrita dentro de MailerService::send(), que vive en el namespace
 * App\Models\Mail) primero busca una función con ese nombre EN ESE
 * MISMO namespace antes de caer a la función global real. Como este archivo
 * de test declara esas funciones directamente en App\Models\Mail,
 * PHP las usa en su lugar durante los tests — sin cambiar ni una línea de
 * app/Models/Mail/MailerService.php.
 *
 * Motivo: MailerService::send() llama a cURL de forma nativa e inline (sin
 * wrapper ni cliente HTTP inyectable) — no hay ningún seam de producción que
 * permita interceptar la llamada de otra forma sin tocar ese archivo. Esta
 * técnica es 100% nativa de PHP (ninguna librería nueva), autorizada
 * explícitamente para este caso.
 *
 * Las funciones falsas leen su comportamiento de $GLOBALS['__mailerCurlFake']
 * (arreglo mutable que cada test configura antes de instanciar MailerService)
 * y registran las opciones recibidas en $GLOBALS['__mailerCurlLastOptions']
 * para que los tests puedan verificar qué se le habría enviado realmente a
 * Resend (payload, cabecera Authorization) sin hacer ninguna petición de red
 * real. Los nombres de las constantes (CURLOPT_..., CURLINFO_...) siguen
 * siendo las reales de la extensión cURL (no se redefinen) — solo el
 * comportamiento de las funciones está falseado.
 */

namespace App\Models\Mail {

    function curl_init(string $url = '')
    {
        $GLOBALS['__mailerCurlLastUrl'] = $url;
        return 'fake-curl-handle';
    }

    function curl_setopt_array($ch, array $options): bool
    {
        $GLOBALS['__mailerCurlLastOptions'] = $options;
        return true;
    }

    function curl_exec($ch)
    {
        return $GLOBALS['__mailerCurlFake']['exec'] ?? false;
    }

    function curl_getinfo($ch, $option = 0)
    {
        if ($option === CURLINFO_HTTP_CODE) {
            return $GLOBALS['__mailerCurlFake']['httpCode'] ?? 0;
        }
        return null;
    }

    function curl_error($ch): string
    {
        return $GLOBALS['__mailerCurlFake']['error'] ?? '';
    }

    function curl_close($ch): void
    {
    }
}

namespace Tests\Unit\Models\Mail {

    use App\Models\Mail\MailerService;
    use PHPUnit\Framework\TestCase;

    /**
     * Tests unitarios de MailerService — sin base de datos. Las ramas que
     * dependen de cURL usan el override por namespace declarado arriba (ver
     * el comentario al inicio de este archivo); la rama sin API key no lo
     * necesita, se corta antes de construir la petición.
     */
    class MailerServiceTest extends TestCase
    {
        private ?string $apiKeyBackup;
        private ?string $mailFromBackup;

        protected function setUp(): void
        {
            $this->apiKeyBackup = $_ENV['RESEND_API_KEY'] ?? null;
            $this->mailFromBackup = $_ENV['MAIL_FROM'] ?? null;
            $_ENV['RESEND_API_KEY'] = 'test-key-123';
            unset($_ENV['MAIL_FROM']);

            $GLOBALS['__mailerCurlFake'] = [];
            $GLOBALS['__mailerCurlLastOptions'] = null;
            $GLOBALS['__mailerCurlLastUrl'] = null;
        }

        protected function tearDown(): void
        {
            if ($this->apiKeyBackup === null) {
                unset($_ENV['RESEND_API_KEY']);
            } else {
                $_ENV['RESEND_API_KEY'] = $this->apiKeyBackup;
            }
            if ($this->mailFromBackup === null) {
                unset($_ENV['MAIL_FROM']);
            } else {
                $_ENV['MAIL_FROM'] = $this->mailFromBackup;
            }
        }

        public function testSendFailsSafelyWithoutAttemptingAnyNetworkCallWhenTheApiKeyIsNotConfigured(): void
        {
            $_ENV['RESEND_API_KEY'] = '';

            $result = (new MailerService())->send('cliente@parce.test', 'Asunto', '<p>Cuerpo</p>');

            $this->assertFalse($result);
            $this->assertNull($GLOBALS['__mailerCurlLastOptions'], 'No debe siquiera intentar construir la petición cURL');
        }

        public function testSendFailsSafelyWhenTheApiKeyEnvVarIsEntirelyUnset(): void
        {
            unset($_ENV['RESEND_API_KEY']);

            $result = (new MailerService())->send('cliente@parce.test', 'Asunto', '<p>Cuerpo</p>');

            $this->assertFalse($result);
        }

        public function testSendSucceedsWhenResendAcceptsTheEmailAndSendsTheExpectedPayload(): void
        {
            $GLOBALS['__mailerCurlFake'] = ['exec' => '{"id":"abc123"}', 'httpCode' => 200];

            $result = (new MailerService())->send('cliente@parce.test', 'Recupera tu contraseña', '<p>Hola</p>');

            $this->assertTrue($result);

            $options = $GLOBALS['__mailerCurlLastOptions'];
            $payload = json_decode($options[CURLOPT_POSTFIELDS], true);

            $this->assertSame(['cliente@parce.test'], $payload['to']);
            $this->assertSame('Recupera tu contraseña', $payload['subject']);
            $this->assertSame('<p>Hola</p>', $payload['html']);
            $this->assertSame('P.A.R.C.E <onboarding@resend.dev>', $payload['from'], 'Debe usar el remitente por defecto cuando MAIL_FROM no está configurado');
            $this->assertContains('Authorization: Bearer test-key-123', $options[CURLOPT_HTTPHEADER]);
        }

        public function testSendUsesTheConfiguredMailFromWhenSet(): void
        {
            $_ENV['MAIL_FROM'] = 'P.A.R.C.E <no-reply@parce.test>';
            $GLOBALS['__mailerCurlFake'] = ['exec' => '{"id":"abc123"}', 'httpCode' => 200];

            (new MailerService())->send('cliente@parce.test', 'Asunto', '<p>Cuerpo</p>');

            $options = $GLOBALS['__mailerCurlLastOptions'];
            $payload = json_decode($options[CURLOPT_POSTFIELDS], true);

            $this->assertSame('P.A.R.C.E <no-reply@parce.test>', $payload['from']);
        }

        public function testSendReturnsFalseOnANetworkError(): void
        {
            $GLOBALS['__mailerCurlFake'] = ['exec' => false, 'error' => 'Connection refused'];

            $result = (new MailerService())->send('cliente@parce.test', 'Asunto', '<p>Cuerpo</p>');

            $this->assertFalse($result);
        }

        public function testSendReturnsFalseWhenResendRejectsTheRequest(): void
        {
            $GLOBALS['__mailerCurlFake'] = [
                'exec' => '{"message":"Invalid API key"}',
                'httpCode' => 401,
            ];

            $result = (new MailerService())->send('cliente@parce.test', 'Asunto', '<p>Cuerpo</p>');

            $this->assertFalse($result);
        }
    }
}
