<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\CookieConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CookieConfigTest extends TestCase
{
    public function testSecureReturnsHardenedDefaults(): void
    {
        $config = CookieConfig::secure();

        $this->assertSame('parce_session', $config->name);
        $this->assertTrue($config->httpOnly);
        $this->assertTrue($config->secure);
        $this->assertSame('Lax', $config->sameSite);
    }

    public function testRejectsAPathNotStartingWithSlash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CookieConfig('test', 3600, 'invalid', '', true, true, 'Lax');
    }

    public function testRejectsAnInvalidSameSiteValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CookieConfig('test', 3600, '/', '', true, true, 'Invalid');
    }

    public function testRejectsANonPositiveLifetime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CookieConfig('test', 0, '/', '', true, true, 'Lax');
    }

    public function testRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CookieConfig('', 3600, '/', '', true, true, 'Lax');
    }
}
