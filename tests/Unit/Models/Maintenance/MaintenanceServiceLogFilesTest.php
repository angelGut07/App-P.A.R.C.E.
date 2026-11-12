<?php

namespace Tests\Unit\Models\Maintenance;

use App\Models\Maintenance\MaintenanceService;
use PHPUnit\Framework\TestCase;

/**
 * Limpieza de logs: solo archivos .log viejos, en una carpeta temporal (nunca
 * los logs reales del proyecto).
 */
class MaintenanceServiceLogFilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/parce-logs-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function fileModifiedDaysAgo(string $name, int $days): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, "línea de log\n");
        touch($path, time() - $days * 86400);

        return $path;
    }

    public function testDeletesOnlyLogFilesOlderThanTheRetention(): void
    {
        $old = $this->fileModifiedDaysAgo('requests-2026-10-01.log', 31);
        $recent = $this->fileModifiedDaysAgo('requests-2026-10-20.log', 29);
        $other = $this->fileModifiedDaysAgo('notas.txt', 90);

        $deleted = (new MaintenanceService(logDirectory: $this->directory))->deleteOldLogFiles(30);

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertFileExists($other, 'Solo se borran archivos .log');
    }

    public function testAFolderWithoutLogsIsNotAnError(): void
    {
        $service = new MaintenanceService(logDirectory: $this->directory . '/no-existe');

        $this->assertSame(0, $service->deleteOldLogFiles());
    }
}
