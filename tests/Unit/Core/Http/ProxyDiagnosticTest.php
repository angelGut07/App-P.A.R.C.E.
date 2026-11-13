<?php

namespace Tests\Unit\Core\Http;

use App\Core\Http\ProxyDiagnostic;
use App\Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Diagnóstico temporal de la IP detrás del proxy: describe el camino de la
 * petición sin mostrar nunca una dirección.
 */
class ProxyDiagnosticTest extends TestCase
{
    private array $serverBackup;
    private array $envBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->envBackup = $_ENV;
        unset($_ENV['TRUSTED_PROXIES']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_ENV = $this->envBackup;
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeRequest(string $remoteAddr, array $headers = []): Request
    {
        foreach (['X_FORWARDED_FOR', 'CF_CONNECTING_IP', 'TRUE_CLIENT_IP', 'X_REAL_IP', 'FORWARDED'] as $name) {
            unset($_SERVER['HTTP_' . $name]);
        }
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;

        return new Request();
    }

    /** Parte "tipo" de un valor "tipo#huella" */
    private static function type(string $described): string
    {
        return explode('#', $described)[0];
    }

    /** Parte "huella" de un valor "tipo#huella" */
    private static function fingerprint(string $described): string
    {
        return explode('#', $described)[1] ?? '';
    }

    public static function addresses(): array
    {
        return [
            'proxy interno de Render'  => ['10.21.157.68', 'privada:10.0.0.0/8'],
            'red privada 172.16/12'    => ['172.20.1.1', 'privada:172.16.0.0/12'],
            'red privada 192.168/16'   => ['192.168.1.10', 'privada:192.168.0.0/16'],
            'IPv6 privada'             => ['fd12:3456::1', 'privada:fc00::/7'],
            'CGNAT'                    => ['100.70.1.1', 'cgnat:100.64.0.0/10'],
            'loopback IPv4'            => ['127.0.0.1', 'loopback'],
            'loopback IPv6'            => ['::1', 'loopback'],
            'enlace local'             => ['169.254.10.20', 'enlace-local'],
            'documentación IPv4'       => ['203.0.113.77', 'documentacion'],
            'documentación IPv6'       => ['2001:db8::5', 'documentacion'],
            'Cloudflare IPv4'          => ['172.70.1.1', 'cloudflare'],
            'Cloudflare IPv4 (104.16)' => ['104.16.1.1', 'cloudflare'],
            'Cloudflare IPv6'          => ['2606:4700::1', 'cloudflare'],
            'pública IPv4'             => ['8.8.8.8', 'publica'],
            'pública IPv6'             => ['2800:4b0::1', 'publica'],
            'IPv4 escrita como IPv6'   => ['::ffff:10.1.2.3', 'privada:10.0.0.0/8'],
            'texto'                    => ['unknown', 'invalida'],
            'con puerto'               => ['8.8.8.8:443', 'invalida'],
        ];
    }

    #[DataProvider('addresses')]
    public function testClassifiesEachKindOfAddress(string $ip, string $expected): void
    {
        $this->assertSame($expected, ProxyDiagnostic::classify($ip));
    }

    public function testDescribesTheWholeChainWithoutShowingAnyAddress(): void
    {
        // Lo que se espera ver en Render: proxy interno en REMOTE_ADDR, una
        // entrada falsa enviada por el cliente, el cliente real y el borde de
        // Cloudflare al final
        $request = $this->makeRequest('10.21.157.68', [
            'X-Forwarded-For'  => '203.0.113.77, 8.8.8.8, 172.70.1.1',
            'CF-Connecting-IP' => '8.8.8.8',
            'True-Client-IP'   => '8.8.8.8',
        ]);

        $description = ProxyDiagnostic::describe($request);
        $json = json_encode($description);

        foreach (['10.21.157.68', '203.0.113.77', '8.8.8.8', '172.70.1.1', '10.21', '203.0', '8.8.', '172.70'] as $address) {
            $this->assertStringNotContainsString($address, $json);
        }

        $this->assertSame('privada:10.0.0.0/8', self::type($description['remoteAddr']));
        $this->assertSame(['documentacion', 'publica', 'cloudflare'], array_map([self::class, 'type'], $description['xForwardedFor']));
        $this->assertSame(3, $description['xForwardedForTotal']);
        $this->assertSame('publica', self::type($description['cfConnectingIp']));
        $this->assertSame('ausente', $description['xRealIp']);
        $this->assertSame('ausente', $description['forwarded']);

        // La huella permite ver qué entrada es la que Cloudflare identificó
        // como el cliente, sin mostrarla
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', self::fingerprint($description['cfConnectingIp']));
        $this->assertSame(self::fingerprint($description['xForwardedFor'][1]), self::fingerprint($description['cfConnectingIp']));
        $this->assertSame($description['cfConnectingIp'], $description['trueClientIp']);

        // Sin TRUSTED_PROXIES, el límite de intentos usa hoy REMOTE_ADDR
        $this->assertSame($description['remoteAddr'], $description['claveActual']);
    }

    public function testTheSameClientGetsTheSameFingerprintAndAnotherClientADifferentOne(): void
    {
        $first = ProxyDiagnostic::describe($this->makeRequest('10.0.0.5', ['CF-Connecting-IP' => '8.8.8.8']));
        $again = ProxyDiagnostic::describe($this->makeRequest('10.0.0.5', ['CF-Connecting-IP' => '8.8.8.8']));
        $other = ProxyDiagnostic::describe($this->makeRequest('10.0.0.5', ['CF-Connecting-IP' => '9.9.9.9']));

        $this->assertSame($first['cfConnectingIp'], $again['cfConnectingIp']);
        $this->assertNotSame($first['cfConnectingIp'], $other['cfConnectingIp']);
        // Mismo proxy para los dos clientes: hoy comparten la clave del límite
        $this->assertSame($first['claveActual'], $other['claveActual']);
    }

    public function testReportsMissingHeadersAsAbsent(): void
    {
        $description = ProxyDiagnostic::describe($this->makeRequest('8.8.8.8'));

        $this->assertSame([], $description['xForwardedFor']);
        $this->assertSame(0, $description['xForwardedForTotal']);
        $this->assertSame('ausente', $description['cfConnectingIp']);
        $this->assertSame('ausente', $description['trueClientIp']);
    }

    public function testDescribesOnlyTheLastTenEntriesOfAVeryLongChain(): void
    {
        $chain = implode(', ', array_fill(0, 50, '203.0.113.1')) . ', 8.8.8.8';
        $description = ProxyDiagnostic::describe($this->makeRequest('10.0.0.5', ['X-Forwarded-For' => $chain]));

        $this->assertCount(10, $description['xForwardedFor']);
        $this->assertSame(51, $description['xForwardedForTotal']);
        $this->assertSame('publica', self::type(end($description['xForwardedFor'])));
    }
}
