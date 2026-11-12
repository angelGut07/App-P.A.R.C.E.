<?php

namespace Tests\Unit\Models\Upload;

use App\Models\Upload\UploadService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Borrado de archivos subidos por URL: solo nombres generados por la app y
 * dentro de la carpeta de archivos (una URL manipulada no borra nada más).
 */
class UploadServiceDeleteByUrlTest extends TestCase
{
    private const FILE = '7_0123456789abcdef01234567.pdf';

    private array $envBackup;
    private string $directory;

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        $this->directory = sys_get_temp_dir() . '/parce-uploads-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/document', 0777, true);
        file_put_contents($this->directory . '/document/' . self::FILE, '%PDF-1.4');
        file_put_contents($this->directory . '/secreto.txt', 'no se debe borrar');

        $_ENV['UPLOADS_PATH'] = $this->directory;
        $_ENV['APP_URL'] = 'https://parce.test';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        @unlink($this->directory . '/document/' . self::FILE);
        @unlink($this->directory . '/secreto.txt');
        @rmdir($this->directory . '/document');
        @rmdir($this->directory);
    }

    public function testDeletesAFileStoredByTheUploader(): void
    {
        $this->assertTrue(UploadService::deleteByUrl('https://parce.test/uploads/document/' . self::FILE));
        $this->assertFileDoesNotExist($this->directory . '/document/' . self::FILE);
    }

    public static function foreignUrls(): array
    {
        return [
            'otro dominio'               => ['https://otro.com/uploads/document/' . self::FILE],
            'sale de la carpeta'         => ['https://parce.test/uploads/../secreto.txt'],
            'sale desde una categoría'   => ['https://parce.test/uploads/document/../secreto.txt'],
            'nombre que no generó la app' => ['https://parce.test/uploads/document/mi-archivo.pdf'],
            'categoría que no existe'    => ['https://parce.test/uploads/otra/' . self::FILE],
        ];
    }

    #[DataProvider('foreignUrls')]
    public function testIgnoresUrlsThatAreNotUploadsOfTheApp(string $url): void
    {
        $this->assertFalse(UploadService::deleteByUrl($url));
        $this->assertFileExists($this->directory . '/secreto.txt');
        $this->assertFileExists($this->directory . '/document/' . self::FILE);
    }

    public function testAMissingFileIsNotAnError(): void
    {
        $this->assertFalse(UploadService::deleteByUrl('https://parce.test/uploads/document/7_ffffffffffffffffffffffff.pdf'));
    }
}
