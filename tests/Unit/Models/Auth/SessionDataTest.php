<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\SessionData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SessionDataTest extends TestCase
{
    private function makeSession(array $overrides = []): SessionData
    {
        $defaults = [
            'id' => 'abc123',
            'userId' => 1,
            'ipAddress' => '192.168.1.1',
            'userAgent' => 'Mozilla/5.0',
            'lastActivity' => time(),
            'createdAt' => time() - 3600,
            'expiresAt' => time() + 3600,
        ];
        $args = array_merge($defaults, $overrides);

        return new SessionData(...$args);
    }

    public function testConstructsWithValidData(): void
    {
        $session = $this->makeSession();

        $this->assertSame('abc123', $session->id);
        $this->assertSame(1, $session->userId);
        $this->assertSame('192.168.1.1', $session->ipAddress);
    }

    public function testIsExpiredReturnsFalseBeforeExpiresAt(): void
    {
        $session = $this->makeSession(['expiresAt' => time() + 3600]);

        $this->assertFalse($session->isExpired());
    }

    public function testIsExpiredReturnsTrueAfterExpiresAt(): void
    {
        $session = $this->makeSession(['expiresAt' => time() - 1]);

        $this->assertTrue($session->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenExpiresAtIsNull(): void
    {
        $session = $this->makeSession(['expiresAt' => null]);

        $this->assertFalse($session->isExpired());
    }

    public function testIsIdleReturnsTrueWhenLastActivityExceedsThreshold(): void
    {
        $session = $this->makeSession(['lastActivity' => time() - 3600]);

        $this->assertTrue($session->isIdle(1800));
    }

    public function testIsIdleReturnsFalseWhenLastActivityIsRecent(): void
    {
        $session = $this->makeSession(['lastActivity' => time()]);

        $this->assertFalse($session->isIdle(1800));
    }

    public function testRejectsANonPositiveUserId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeSession(['userId' => 0]);
    }

    public function testRejectsAnInvalidIpAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeSession(['ipAddress' => 'not-an-ip']);
    }

    public function testRejectsAnEmptySessionId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeSession(['id' => '']);
    }
}
