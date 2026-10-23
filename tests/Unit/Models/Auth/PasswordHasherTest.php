<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\PasswordHasher;
use App\Models\Auth\AuthenticationException;
use PHPUnit\Framework\TestCase;

class PasswordHasherTest extends TestCase
{
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasher();
    }

    public function testHashProducesArgon2idHash(): void
    {
        $hash = $this->hasher->hash('correct-horse-battery-staple');

        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testHashProducesADifferentHashEachTime(): void
    {
        $hash1 = $this->hasher->hash('same-password-123');
        $hash2 = $this->hasher->hash('same-password-123');

        $this->assertNotSame($hash1, $hash2, 'Cada hash debe tener una sal distinta');
    }

    public function testHashRejectsPasswordsShorterThanEightCharacters(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->hasher->hash('short1');
    }

    public function testHashAcceptsExactlyEightCharacters(): void
    {
        $hash = $this->hasher->hash('exactly8');

        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testVerifyReturnsTrueForCorrectPassword(): void
    {
        $hash = $this->hasher->hash('my-secret-password');

        $this->assertTrue($this->hasher->verify('my-secret-password', $hash));
    }

    public function testVerifyReturnsFalseForIncorrectPassword(): void
    {
        $hash = $this->hasher->hash('my-secret-password');

        $this->assertFalse($this->hasher->verify('wrong-password', $hash));
    }

    public function testNeedsRehashReturnsFalseForACurrentArgon2idHash(): void
    {
        $hash = $this->hasher->hash('my-secret-password');

        $this->assertFalse($this->hasher->needsRehash($hash));
    }

    public function testNeedsRehashReturnsTrueForALegacyBcryptHash(): void
    {
        $bcryptHash = password_hash('my-secret-password', PASSWORD_BCRYPT);

        $this->assertTrue($this->hasher->needsRehash($bcryptHash));
    }
}
