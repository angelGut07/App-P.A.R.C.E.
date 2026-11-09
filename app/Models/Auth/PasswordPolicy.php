<?php

namespace App\Models\Auth;

/**
 * Política de contraseñas
 *
 * Reglas para crear o cambiar una contraseña: registro, recuperación y cambio
 * desde el perfil. No se aplica al iniciar sesión, para que las cuentas
 * creadas con reglas anteriores puedan seguir entrando.
 *
 * Combina la guía NIST SP 800-63B (longitud mínima, lista de contraseñas
 * comunes y palabras del contexto como el nombre del servicio o el correo
 * del usuario) con variedad de caracteres: mayúscula, minúscula, número y
 * carácter especial.
 *
 * Es la única fuente de verdad de la política: los validadores de las
 * peticiones y POST /api/auth/password-check la usan; el frontend solo
 * repite las reglas de composición para mostrarlas mientras se escribe.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;

    /** Mínimo de caracteres distintos (sin distinguir mayúsculas) */
    private const MIN_DISTINCT_CHARACTERS = 4;

    /** Fragmentos del correo o del nombre más cortos que esto no se revisan */
    private const MIN_PERSONAL_TOKEN_LENGTH = 4;

    /**
     * Palabras del contexto de la plataforma: una contraseña basada en el
     * nombre del servicio es de las primeras que prueba un atacante.
     */
    private const CONTEXT_WORDS = ['parce', 'asistencia', 'mecanico', 'conductor'];

    /**
     * Contraseñas completas muy comunes, en minúsculas (incluye patrones de
     * teclado que mezclan letras y números).
     */
    private const COMMON_PASSWORDS = [
        '12345678', '123456789', '1234567890', '11111111', '00000000', '87654321',
        'password', 'password1', 'password12', 'password123', 'passw0rd', 'p@ssw0rd', 'p@ssword',
        'qwerty12', 'qwerty123', 'qwertyuiop', '1qaz2wsx', 'zaq12wsx', '1q2w3e4r', '1q2w3e4r5t',
        'q1w2e3r4', 'asdf1234', 'abcd1234', 'abc12345', 'a1b2c3d4', 'qazwsxedc', 'zxcvbnm1',
        'iloveyou', 'iloveyou1', 'admin123', 'administrator', 'welcome1', 'welcome123',
        'letmein1', 'trustno1', 'changeme', 'contrasena', 'contrasena1', 'contrasena123',
        'clave123', 'teamo123',
    ];

    /**
     * Palabras base muy usadas, solo letras. Se comparan con la contraseña
     * sin los números y símbolos que la gente suele agregar al final
     * ("Colombia2024*" → "colombia") y sin sustituciones típicas como
     * @ por a o 0 por o ("P@ssw0rd!" → "password").
     */
    private const COMMON_WORDS = [
        'password', 'passwd', 'contrasena', 'clave', 'secreto', 'secret', 'qwerty', 'qwertyuiop',
        'asdf', 'asdfgh', 'zxcvbn', 'abc', 'abcd', 'abcde', 'abcdef', 'admin', 'administrador',
        'administrator', 'root', 'usuario', 'user', 'login', 'test', 'prueba', 'demo', 'welcome',
        'bienvenido', 'bienvenida', 'hola', 'hello', 'iloveyou', 'teamo', 'teamomucho', 'amor',
        'amorcito', 'princesa', 'princess', 'dragon', 'monkey', 'master', 'shadow', 'sunshine',
        'superman', 'batman', 'pokemon', 'naruto', 'starwars', 'letmein', 'freedom', 'whatever',
        'football', 'futbol', 'soccer', 'baseball', 'colombia', 'bogota', 'medellin', 'cali',
        'barranquilla', 'cartagena', 'millonarios', 'nacional', 'america', 'junior', 'santafe',
        'google', 'facebook', 'samsung', 'iphone', 'apple', 'familia', 'jesus', 'dios', 'diosesamor',
    ];

    /** Sustituciones típicas de letras por números o símbolos */
    private const LEET = ['@' => 'a', '4' => 'a', '3' => 'e', '1' => 'i', '!' => 'i', '0' => 'o', '$' => 's', '5' => 's', '7' => 't'];

    /** Vocales con tilde, diéresis y eñe en minúscula → letra simple */
    private const ACCENTS = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];

    /**
     * Revisa una contraseña nueva contra la política.
     *
     * @param string $password Contraseña en texto plano
     * @param array{email?: ?string, first_name?: ?string, last_name?: ?string} $context
     *        Datos de la persona: la contraseña no debe contenerlos
     * @return string[] Mensajes de las reglas que no cumple (vacío si es válida)
     */
    public static function errors(string $password, array $context = []): array
    {
        if (str_contains($password, "\0") || !mb_check_encoding($password, 'UTF-8')) {
            return ['La contraseña contiene caracteres no permitidos'];
        }

        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return ['La contraseña debe tener entre ' . self::MIN_LENGTH . ' y ' . self::MAX_LENGTH . ' caracteres'];
        }

        $errors = [];

        $missing = [];
        if (!preg_match('/\p{Lu}/u', $password)) {
            $missing[] = 'una letra mayúscula';
        }
        if (!preg_match('/\p{Ll}/u', $password)) {
            $missing[] = 'una letra minúscula';
        }
        if (!preg_match('/\d/', $password)) {
            $missing[] = 'un número';
        }
        if (!preg_match('/[^\p{L}\p{N}]/u', $password)) {
            $missing[] = 'un carácter especial (por ejemplo ! # * -)';
        }
        if ($missing !== []) {
            $errors[] = 'La contraseña debe incluir al menos ' . self::joinSpanish($missing);
        }

        $normalized = self::normalize($password);

        if (count(array_unique(mb_str_split($normalized))) < self::MIN_DISTINCT_CHARACTERS) {
            $errors[] = 'La contraseña repite demasiados caracteres; usa más variedad';
        }

        if (self::isCommon($normalized)) {
            $errors[] = 'Esa contraseña es muy común y fácil de adivinar; elige otra';
        }

        if (self::containsPersonalOrContextWord($normalized, $context)) {
            $errors[] = 'La contraseña no debe contener tu nombre, tu correo ni el nombre de la plataforma';
        }

        return $errors;
    }

    /**
     * Contraseña completa o palabra base dentro de las listas de comunes.
     */
    private static function isCommon(string $normalized): bool
    {
        if (in_array($normalized, self::COMMON_PASSWORDS, true)) {
            return true;
        }

        // Base: sin los números y símbolos de los extremos, sin sustituciones
        // típicas y sin lo que no sea letra
        $trimmed = preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', $normalized);
        $base = preg_replace('/[^a-z]/', '', strtr($trimmed, self::LEET));

        return $base !== '' && in_array($base, self::COMMON_WORDS, true);
    }

    /**
     * La contraseña contiene una parte del correo, del nombre o una palabra
     * del contexto de la plataforma.
     */
    private static function containsPersonalOrContextWord(string $normalized, array $context): bool
    {
        $comparable = strtr($normalized, self::LEET);

        $tokens = self::CONTEXT_WORDS;
        $email = (string)($context['email'] ?? '');
        $localPart = str_contains($email, '@') ? strstr($email, '@', true) : $email;
        foreach ([$localPart, $context['first_name'] ?? '', $context['last_name'] ?? ''] as $value) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', self::normalize((string)$value)) as $token) {
                if (mb_strlen($token) >= self::MIN_PERSONAL_TOKEN_LENGTH) {
                    $tokens[] = $token;
                }
            }
        }

        foreach ($tokens as $token) {
            if (str_contains($normalized, $token) || str_contains($comparable, $token)) {
                return true;
            }
        }

        return false;
    }

    /** Minúsculas y sin tildes, para comparar sin importar cómo se escribió */
    private static function normalize(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), self::ACCENTS);
    }

    /** ['a', 'b', 'c'] → "a, b y c" */
    private static function joinSpanish(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items) . ' y ' . $last;
    }
}
