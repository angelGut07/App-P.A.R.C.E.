<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\CaptchaService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios de CaptchaService. El POST a Cloudflare se reemplaza por
 * una función de prueba: nunca sale a la red.
 */
class CaptchaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['TURNSTILE_SITE_KEY'] = 'site-key-de-prueba';
        $_ENV['TURNSTILE_SECRET_KEY'] = 'secret-key-de-prueba';
    }

    protected function tearDown(): void
    {
        unset($_ENV['TURNSTILE_SITE_KEY'], $_ENV['TURNSTILE_SECRET_KEY']);
    }

    /** Servicio con un POST falso que devuelve $body y guarda lo que se envió */
    private function serviceAnswering(?string $body, ?array &$sent = null): CaptchaService
    {
        return new CaptchaService(function (string $url, array $fields) use ($body, &$sent): ?string {
            $sent = ['url' => $url, 'fields' => $fields];
            return $body;
        });
    }

    public function testIsDisabledAndLetsEverythingThroughWithoutKeys(): void
    {
        unset($_ENV['TURNSTILE_SITE_KEY'], $_ENV['TURNSTILE_SECRET_KEY']);
        $service = new CaptchaService(fn() => $this->fail('No debe llamar a Cloudflare sin claves'));

        $this->assertFalse($service->isEnabled());
        $this->assertNull($service->siteKey());
        $this->assertTrue($service->verify(null, '203.0.113.5', 'login'));
    }

    public function testRejectsAMissingTokenWithoutCallingCloudflare(): void
    {
        $service = new CaptchaService(fn() => $this->fail('No debe llamar a Cloudflare sin token'));

        $this->assertFalse($service->verify('', '203.0.113.5', 'login'));
    }

    public function testAcceptsATokenThatCloudflareValidates(): void
    {
        $service = $this->serviceAnswering('{"success":true,"action":"register"}', $sent);

        $this->assertTrue($service->verify('token-del-widget', '203.0.113.5', 'register'));
        $this->assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $sent['url']);
        $this->assertSame(
            ['secret' => 'secret-key-de-prueba', 'response' => 'token-del-widget', 'remoteip' => '203.0.113.5'],
            $sent['fields']
        );
    }

    public function testRejectsATokenThatCloudflareRejects(): void
    {
        $service = $this->serviceAnswering('{"success":false,"error-codes":["invalid-input-response"]}');

        $this->assertFalse($service->verify('token-vencido', '203.0.113.5', 'login'));
    }

    public function testRejectsATokenIssuedForAnotherForm(): void
    {
        $service = $this->serviceAnswering('{"success":true,"action":"login"}');

        $this->assertFalse($service->verify('token-del-login', '203.0.113.5', 'register'));
    }

    public function testRejectsWhenCloudflareCannotBeReached(): void
    {
        $this->assertFalse($this->serviceAnswering(null)->verify('token', '203.0.113.5', 'login'));
    }
}
