<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\AuthResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AuthResultTest extends TestCase
{
    public function testSuccessCreatesAResultWithUserIdAndSessionId(): void
    {
        $result = AuthResult::success(1, 'session123');

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->userId);
        $this->assertSame('session123', $result->sessionId);
    }

    public function testFailureCreatesAResultWithAMessage(): void
    {
        $result = AuthResult::failure('Correo o contraseña incorrectos');

        $this->assertFalse($result->success);
        $this->assertSame('Correo o contraseña incorrectos', $result->message);
        $this->assertNull($result->userId);
        $this->assertNull($result->sessionId);
    }

    public function testConstructorRejectsSuccessTrueWithoutUserIdOrSessionId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuthResult(true, null, null, null);
    }

    public function testConstructorRejectsFailureWithoutMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuthResult(false, null, null, null);
    }
}
