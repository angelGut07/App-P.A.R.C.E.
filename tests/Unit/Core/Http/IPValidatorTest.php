<?php

namespace Tests\Unit\Core\Http;

use App\Core\Request;
use App\Core\Http\IPValidator;
use PHPUnit\Framework\TestCase;

class IPValidatorTest extends TestCase
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

    private function makeRequest(?string $forwardedFor, string $remoteAddr): Request
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        if ($forwardedFor !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;

        return new Request();
    }

    public function testIsValidIPAcceptsAnIPv4Address(): void
    {
        $this->assertTrue(IPValidator::isValidIP('192.168.1.1'));
    }

    public function testIsValidIPAcceptsAnIPv6Address(): void
    {
        $this->assertTrue(IPValidator::isValidIP('::1'));
    }

    public function testIsValidIPRejectsAnEmptyString(): void
    {
        $this->assertFalse(IPValidator::isValidIP(''));
    }

    public function testIsValidIPRejectsAMalformedAddress(): void
    {
        $this->assertFalse(IPValidator::isValidIP('not-an-ip'));
    }

    public function testGetClientIPIgnoresXForwardedForByDefault(): void
    {
        // Sin TRUSTED_PROXIES configurado, X-Forwarded-For (falsificable por el
        // cliente) nunca debe usarse — de lo contrario cualquiera podría rotar
        // su IP declarada para evadir el rate limiting de login.
        $request = $this->makeRequest('203.0.113.5', '10.0.0.1');

        $this->assertSame('10.0.0.1', IPValidator::getClientIP($request));
    }

    public function testGetClientIPIgnoresXForwardedForFromAnUntrustedRemoteAddr(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '192.0.2.10';
        $request = $this->makeRequest('203.0.113.5', '10.0.0.1');

        $this->assertSame('10.0.0.1', IPValidator::getClientIP($request));
    }

    public function testGetClientIPHonorsXForwardedForFromATrustedExactProxyIp(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1';
        $request = $this->makeRequest('203.0.113.5', '10.0.0.1');

        $this->assertSame('203.0.113.5', IPValidator::getClientIP($request));
    }

    public function testGetClientIPHonorsXForwardedForFromATrustedCidrRange(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '172.16.0.0/12';
        $request = $this->makeRequest('203.0.113.5', '172.20.5.9');

        $this->assertSame('203.0.113.5', IPValidator::getClientIP($request));
    }

    public function testGetClientIPUsesTheFirstIpInAForwardedChainFromATrustedProxy(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1';
        $request = $this->makeRequest('203.0.113.5, 10.0.0.2, 10.0.0.3', '10.0.0.1');

        $this->assertSame('203.0.113.5', IPValidator::getClientIP($request));
    }

    public function testGetClientIPFallsBackToRemoteAddrWhenForwardedForIsInvalid(): void
    {
        $request = $this->makeRequest('not-an-ip', '198.51.100.7');

        $this->assertSame('198.51.100.7', IPValidator::getClientIP($request));
    }

    public function testGetClientIPFallsBackToRemoteAddrWhenForwardedForIsAbsent(): void
    {
        $request = $this->makeRequest(null, '198.51.100.7');

        $this->assertSame('198.51.100.7', IPValidator::getClientIP($request));
    }

    public function testGetClientIPReturnsFallbackWhenBothAreInvalid(): void
    {
        $request = $this->makeRequest('not-an-ip', 'also-not-an-ip');

        $this->assertSame('0.0.0.0', IPValidator::getClientIP($request));
    }

    public function testGetAllForwardedIPsReturnsEveryValidIpInTheChain(): void
    {
        $request = $this->makeRequest('203.0.113.5, 10.0.0.2', '10.0.0.1');

        $this->assertSame(['203.0.113.5', '10.0.0.2'], array_values(IPValidator::getAllForwardedIPs($request)));
    }

    public function testGetAllForwardedIPsReturnsEmptyArrayWhenHeaderIsAbsent(): void
    {
        $request = $this->makeRequest(null, '10.0.0.1');

        $this->assertSame([], IPValidator::getAllForwardedIPs($request));
    }

    public function testHasIPChangedReturnsFalseForTheSameIP(): void
    {
        $this->assertFalse(IPValidator::hasIPChanged('192.168.1.1', '192.168.1.1'));
    }

    public function testHasIPChangedReturnsTrueForDifferentIPs(): void
    {
        $this->assertTrue(IPValidator::hasIPChanged('192.168.1.1', '192.168.1.2'));
    }
}
