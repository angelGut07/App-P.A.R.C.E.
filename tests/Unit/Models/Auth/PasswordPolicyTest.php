<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios de PasswordPolicy: composición, longitud, contraseñas
 * comunes (con sustituciones típicas) y palabras personales o del contexto.
 */
class PasswordPolicyTest extends TestCase
{
    public function testAcceptsAStrongPassword(): void
    {
        $this->assertSame([], PasswordPolicy::errors('Tr3n-Azul#Lluvia'));
        $this->assertSame([], PasswordPolicy::errors('Mi-Carro#Rojo7'));
    }

    public function testAcceptsAccentedLettersAsUpperAndLowerCase(): void
    {
        $this->assertSame([], PasswordPolicy::errors('Ñandú#2024x'));
    }

    public function testRejectsPasswordsOutsideTheLengthRange(): void
    {
        $this->assertSame(['La contraseña debe tener entre 8 y 128 caracteres'], PasswordPolicy::errors('Aa1!xyz'));
        $this->assertSame(
            ['La contraseña debe tener entre 8 y 128 caracteres'],
            PasswordPolicy::errors('Aa1!' . str_repeat('x', 125))
        );
    }

    public function testRejectsANullByte(): void
    {
        $this->assertSame(['La contraseña contiene caracteres no permitidos'], PasswordPolicy::errors("Tr3n-Azul\0#Lluvia"));
    }

    public static function missingCharacterClassProvider(): array
    {
        return [
            'sin mayúscula'        => ['tr3n-azul#lluvia', 'una letra mayúscula'],
            'sin minúscula'        => ['TR3N-AZUL#LLUVIA', 'una letra minúscula'],
            'sin número'           => ['Tren-Azul#Lluvia', 'un número'],
            'sin carácter especial' => ['Tr3nAzulLluvia', 'un carácter especial'],
        ];
    }

    #[DataProvider('missingCharacterClassProvider')]
    public function testRequiresEachCharacterClass(string $password, string $expected): void
    {
        $errors = PasswordPolicy::errors($password);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString($expected, $errors[0]);
    }

    public function testListsEveryMissingClassInOneSentence(): void
    {
        $this->assertSame(
            ['La contraseña debe incluir al menos una letra mayúscula, un número y un carácter especial (por ejemplo ! # * -)'],
            PasswordPolicy::errors('trenazullluvia')
        );
    }

    public static function commonPasswordProvider(): array
    {
        return [
            'palabra común con números'   => ['Password123!'],
            'sustituciones típicas'       => ['P@ssw0rd!'],
            'ciudad con año'              => ['Colombia2024*'],
            'símbolos al inicio'          => ['#Bogota2025'],
            'sustitución dentro'          => ['Te@mo2024!'],
        ];
    }

    #[DataProvider('commonPasswordProvider')]
    public function testRejectsCommonPasswordsEvenWithTypicalDecorations(string $password): void
    {
        $this->assertContains('Esa contraseña es muy común y fácil de adivinar; elige otra', PasswordPolicy::errors($password));
    }

    public function testRejectsPasswordsWithTooFewDistinctCharacters(): void
    {
        $this->assertContains('La contraseña repite demasiados caracteres; usa más variedad', PasswordPolicy::errors('Aa1!Aa1!'));
    }

    public function testRejectsTheNameOfThePlatform(): void
    {
        $this->assertContains(
            'La contraseña no debe contener tu nombre, tu correo ni el nombre de la plataforma',
            PasswordPolicy::errors('Parce2026*')
        );
        $this->assertContains(
            'La contraseña no debe contener tu nombre, tu correo ni el nombre de la plataforma',
            PasswordPolicy::errors('P4rce#Segura9')
        );
    }

    public function testRejectsPartsOfTheEmailAndTheName(): void
    {
        $message = 'La contraseña no debe contener tu nombre, tu correo ni el nombre de la plataforma';

        $this->assertContains($message, PasswordPolicy::errors('Cliente#2024x', ['email' => 'cliente@parce.test']));
        $this->assertContains($message, PasswordPolicy::errors('Carlos#2024x', ['first_name' => 'Carlos']));
        // El apellido se compara sin tilde
        $this->assertContains($message, PasswordPolicy::errors('Rodriguez#24x', ['last_name' => 'Rodríguez']));
    }

    public function testIgnoresVeryShortPartsOfTheName(): void
    {
        // "Ana" (3 letras) es demasiado corto para descartar contraseñas que lo contengan
        $this->assertSame([], PasswordPolicy::errors('Banana#Verde7', ['first_name' => 'Ana']));
    }
}
