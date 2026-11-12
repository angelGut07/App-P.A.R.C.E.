<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\ConsentService;
use PHPUnit\Framework\TestCase;

/**
 * Estado de la autorización de tratamiento de datos que ve la app: solo la
 * versión vigente cuenta como aceptada (una versión anterior obliga a aceptar
 * de nuevo).
 */
class ConsentServiceTest extends TestCase
{
    public function testCurrentVersionIsADate(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', ConsentService::CURRENT_VERSION);
    }

    public function testAcceptingTheCurrentVersionIsUpToDate(): void
    {
        $status = ConsentService::status(ConsentService::CURRENT_VERSION, '2026-11-12 08:30:00');

        $this->assertSame([
            'currentVersion' => ConsentService::CURRENT_VERSION,
            'version'        => ConsentService::CURRENT_VERSION,
            'acceptedAt'     => '2026-11-12 08:30:00',
            'upToDate'       => true,
        ], $status);
    }

    public function testAnOlderVersionMustBeAcceptedAgain(): void
    {
        $this->assertFalse(ConsentService::status('2020-01-01', '2020-01-01 10:00:00')['upToDate']);
    }

    public function testSomeoneWhoNeverAcceptedIsNotUpToDate(): void
    {
        $status = ConsentService::status(null, null);

        $this->assertFalse($status['upToDate']);
        $this->assertNull($status['version']);
    }
}
