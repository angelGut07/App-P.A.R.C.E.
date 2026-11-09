<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\LoginThrottleService;
use App\Models\Mail\MailerService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;

/**
 * Tests unitarios de LoginThrottleService — PDO mockeado, nunca MySQL real.
 * Cubren el umbral del CAPTCHA, el bloqueo, el escalamiento, el reinicio tras
 * 24 horas sin fallos y el aviso por correo.
 */
class LoginThrottleServiceTest extends TestCase
{
    use MocksDatabase;

    private MailerService $mailer;
    private LoginThrottleService $service;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->mailer = $this->createMock(MailerService::class);
        $this->service = new LoginThrottleService($this->mailer);
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
    }

    private function row(int $failed, int $level = 0, ?int $lockedUntil = null, ?int $lastFailed = null): array
    {
        return [
            'failed_attempts' => $failed,
            'lockout_level'   => $level,
            'locked_until'    => $lockedUntil,
            'last_failed_at'  => $lastFailed ?? time() - 60,
        ];
    }

    // =========================================================================
    // status()
    // =========================================================================

    public function testStatusOfAnEmailWithoutFailuresIsClean(): void
    {
        $this->expectQueries([$this->stepFetchOne(false)]);

        $this->assertSame(
            ['locked' => false, 'retryAfter' => 0, 'failedAttempts' => 0, 'captchaRequired' => false],
            $this->service->status('nadie@parce.local')
        );
    }

    public function testStatusRequiresTheCaptchaFromTheThirdFailure(): void
    {
        $this->expectQueries([$this->stepFetchOne($this->row(3))]);

        $status = $this->service->status('cliente@parce.local');

        $this->assertFalse($status['locked']);
        $this->assertTrue($status['captchaRequired']);
    }

    public function testStatusReportsAnActiveLockAndItsRemainingTime(): void
    {
        $this->expectQueries([$this->stepFetchOne($this->row(5, 1, time() + 600))]);

        $status = $this->service->status('cliente@parce.local');

        $this->assertTrue($status['locked']);
        $this->assertEqualsWithDelta(600, $status['retryAfter'], 2);
    }

    public function testStatusKeepsRequiringTheCaptchaAfterALockExpires(): void
    {
        $this->expectQueries([$this->stepFetchOne($this->row(5, 1, time() - 10, time() - 1000))]);

        $status = $this->service->status('cliente@parce.local');

        $this->assertFalse($status['locked']);
        $this->assertSame(0, $status['failedAttempts']);
        $this->assertTrue($status['captchaRequired']);
    }

    public function testStatusForgetsFailuresOlderThan24Hours(): void
    {
        $this->expectQueries([$this->stepFetchOne($this->row(4, 2, null, time() - 90000))]);

        $this->assertSame(
            ['locked' => false, 'retryAfter' => 0, 'failedAttempts' => 0, 'captchaRequired' => false],
            $this->service->status('cliente@parce.local')
        );
    }

    // =========================================================================
    // recordFailure()
    // =========================================================================

    public function testRecordFailureCountsAFailureWithoutLocking(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepWrite(1),                     // INSERT IGNORE
            $this->stepFetchOne($this->row(1)),      // SELECT … FOR UPDATE
            $this->stepWrite(1),                     // UPDATE
        ]);
        $this->mailer->expects($this->never())->method('send');

        $status = $this->service->recordFailure('cliente@parce.local', '203.0.113.5');

        $this->assertFalse($status['locked']);
        $this->assertSame(2, $status['failedAttempts']);
    }

    public function testRecordFailureDoublesTheLockOnEachNewLockAndAlertsTheOwner(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepWrite(1),
            $this->stepFetchOne($this->row(4, 1)),   // ya tuvo un bloqueo antes
            $this->stepWrite(1),
            $this->stepFetchOne(['first_name' => 'Carlos']), // la cuenta existe
        ]);
        $this->mailer->expects($this->once())
            ->method('send')
            ->with(
                'cliente@parce.local',
                $this->stringContains('Alerta de seguridad'),
                $this->logicalAnd($this->stringContains('Carlos'), $this->stringContains('30 minutos'), $this->stringContains('203.0.113.5'))
            )
            ->willReturn(true);

        $status = $this->service->recordFailure('cliente@parce.local', '203.0.113.5');

        $this->assertTrue($status['locked']);
        $this->assertEqualsWithDelta(1800, $status['retryAfter'], 2);
    }

    public function testRecordFailureStartsAgainAfterAnExpiredLockButKeepsTheCaptcha(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepWrite(1),
            $this->stepFetchOne($this->row(5, 1, time() - 10, time() - 1000)),
            $this->stepWrite(1),
        ]);

        $status = $this->service->recordFailure('cliente@parce.local', '203.0.113.5');

        $this->assertFalse($status['locked']);
        $this->assertSame(1, $status['failedAttempts']);
        $this->assertTrue($status['captchaRequired']);
    }

    public function testRecordFailureDoesNotCountWhileTheAccountIsLocked(): void
    {
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepWrite(1),
            $this->stepFetchOne($this->row(5, 1, time() + 300)),
        ]);
        $this->mailer->expects($this->never())->method('send');

        $this->assertTrue($this->service->recordFailure('cliente@parce.local', '203.0.113.5')['locked']);
    }

    // =========================================================================
    // humanDuration()
    // =========================================================================

    public function testHumanDurationUsesMinutesOrHours(): void
    {
        $this->assertSame('1 minuto', LoginThrottleService::humanDuration(30));
        $this->assertSame('15 minutos', LoginThrottleService::humanDuration(900));
        $this->assertSame('1 hora', LoginThrottleService::humanDuration(3600));
        $this->assertSame('24 horas', LoginThrottleService::humanDuration(86400));
    }
}
