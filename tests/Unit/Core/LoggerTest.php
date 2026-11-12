<?php

namespace Tests\Unit\Core;

use App\Core\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Destino de los logs: stderr en producción (Render solo muestra esa salida)
 * y archivos diarios en local.
 */
class LoggerTest extends TestCase
{
    private const CATEGORY = 'logger-test';

    private array $envBackup;
    private array $processEnvBackup;
    private string|false $errorLogBackup;
    private string $capturedStderr;

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        $this->processEnvBackup = [
            'LOG_CHANNEL' => getenv('LOG_CHANNEL'),
            'APP_ENV'     => getenv('APP_ENV'),
        ];
        $this->errorLogBackup = ini_get('error_log');
        $this->capturedStderr = tempnam(sys_get_temp_dir(), 'parce-log');
        @unlink(Logger::filePath(self::CATEGORY));
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        foreach ($this->processEnvBackup as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        ini_set('error_log', (string) $this->errorLogBackup);
        @unlink($this->capturedStderr);
        @unlink(Logger::filePath(self::CATEGORY));
    }

    private function setEnvironment(?string $logChannel, ?string $appEnv): void
    {
        foreach (['LOG_CHANNEL' => $logChannel, 'APP_ENV' => $appEnv] as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    public static function channels(): array
    {
        return [
            'producción sin LOG_CHANNEL'  => [null, 'production', Logger::CHANNEL_STDERR],
            'local sin LOG_CHANNEL'       => [null, 'local', Logger::CHANNEL_FILE],
            'sin APP_ENV ni LOG_CHANNEL'  => [null, null, Logger::CHANNEL_FILE],
            'LOG_CHANNEL=stderr en local' => ['stderr', 'local', Logger::CHANNEL_STDERR],
            'LOG_CHANNEL=file en producción' => ['file', 'production', Logger::CHANNEL_FILE],
            'mayúsculas y espacios'       => [' STDERR ', 'local', Logger::CHANNEL_STDERR],
            'valor desconocido: usa APP_ENV' => ['syslog', 'production', Logger::CHANNEL_STDERR],
        ];
    }

    #[DataProvider('channels')]
    public function testChannelFollowsLogChannelAndThenAppEnv(?string $logChannel, ?string $appEnv, string $expected): void
    {
        $this->setEnvironment($logChannel, $appEnv);

        $this->assertSame($expected, Logger::channel());
    }

    public function testFileChannelAppendsLinesToTheDailyFile(): void
    {
        $this->setEnvironment('file', 'local');

        Logger::write(self::CATEGORY, 'primera');
        Logger::write(self::CATEGORY, "segunda\n");

        $file = Logger::filePath(self::CATEGORY);
        $this->assertStringEndsWith('/storage/logs/' . self::CATEGORY . '-' . date('Y-m-d') . '.log', str_replace('\\', '/', $file));
        $this->assertSame(['primera', 'segunda'], file($file, FILE_IGNORE_NEW_LINES));
    }

    public function testStderrChannelWritesThroughErrorLogWithTheCategoryAndNoFile(): void
    {
        $this->setEnvironment('stderr', 'production');
        // error_log() escribe en este archivo en vez de la terminal durante la prueba
        ini_set('error_log', $this->capturedStderr);

        Logger::write(self::CATEGORY, 'conexión fallida');

        $this->assertStringContainsString('[' . self::CATEGORY . '] conexión fallida', (string) file_get_contents($this->capturedStderr));
        $this->assertFileDoesNotExist(Logger::filePath(self::CATEGORY));
    }
}
