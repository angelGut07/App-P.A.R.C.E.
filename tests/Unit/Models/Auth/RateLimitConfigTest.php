<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\RateLimitConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RateLimitConfigTest extends TestCase
{
    public function testDefaultMatchesTheDocumentedValues(): void
    {
        $config = RateLimitConfig::default();

        $this->assertSame(5, $config->maxAttempts);
        $this->assertSame(15, $config->decayMinutes);
        $this->assertSame(30, $config->lockoutMinutes);
    }

    public function testStrictMatchesTheDocumentedValues(): void
    {
        $config = RateLimitConfig::strict();

        $this->assertSame(3, $config->maxAttempts);
        $this->assertSame(10, $config->decayMinutes);
        $this->assertSame(60, $config->lockoutMinutes);
    }

    public function testRejectsMaxAttemptsBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimitConfig(0, 15, 30);
    }

    public function testRejectsMaxAttemptsAboveOneHundred(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimitConfig(101, 15, 30);
    }

    public function testRejectsDecayMinutesAboveFourteenForty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimitConfig(5, 2000, 30);
    }

    public function testRejectsLockoutMinutesAboveFourteenForty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimitConfig(5, 15, 2000);
    }
}
