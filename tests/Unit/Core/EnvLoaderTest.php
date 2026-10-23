<?php

namespace Tests\Unit\Core;

use App\Core\EnvLoader;
use PHPUnit\Framework\TestCase;

class EnvLoaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'env');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        foreach (['PARCE_T_PLAIN', 'PARCE_T_COMMENT', 'PARCE_T_QUOTED', 'PARCE_T_HASH'] as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    public function testParsesPlainQuotedAndCommentedValues(): void
    {
        file_put_contents($this->file, implode("\n", [
            '# comentario de línea',
            'PARCE_T_PLAIN=valor',
            'PARCE_T_COMMENT=local        # comentario al final',
            'PARCE_T_QUOTED="P.A.R.C.E"   # también con comillas',
            "PARCE_T_HASH='clave#con#numerales'",
        ]));

        $this->assertTrue(EnvLoader::load($this->file));
        $this->assertSame('valor', $_ENV['PARCE_T_PLAIN']);
        $this->assertSame('local', $_ENV['PARCE_T_COMMENT']);
        $this->assertSame('P.A.R.C.E', $_ENV['PARCE_T_QUOTED']);
        $this->assertSame('clave#con#numerales', $_ENV['PARCE_T_HASH']);
    }

    public function testProcessEnvironmentWinsOverFile(): void
    {
        putenv('PARCE_T_PLAIN=del-proceso');
        file_put_contents($this->file, "PARCE_T_PLAIN=del-archivo\n");

        EnvLoader::load($this->file);

        $this->assertSame('del-proceso', $_ENV['PARCE_T_PLAIN']);
    }

    public function testReturnsFalseWhenFileIsMissing(): void
    {
        $this->assertFalse(EnvLoader::load($this->file . '.no-existe'));
    }
}
