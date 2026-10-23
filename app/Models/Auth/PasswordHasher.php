<?php

namespace App\Models\Auth;

use App\Models\Auth\AuthenticationException;

/**
 * Servicio de Hash de Contraseñas
 *
 * Proporciona hash seguro y verificación de contraseñas usando el algoritmo Argon2id.
 * Implementa protección contra ataques de tiempo y actualización automática de hashes.
 *
 * Requisitos: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4, 2.5, 16.1
 */
class PasswordHasher
{
    /**
     * Longitud mínima de contraseña
     */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Genera el hash de una contraseña usando el algoritmo Argon2id
     *
     * Requisitos: 1.1, 1.2, 1.3, 1.4, 1.5
     *
     * @param string $password Contraseña en texto plano
     * @return string Hash Argon2id
     * @throws AuthenticationException Si la contraseña es inválida
     */
    public function hash(string $password): string
    {
        // Requisito 1.4: Rechazar contraseñas más cortas que 8 caracteres
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new AuthenticationException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters long'
            );
        }

        // Requisito 1.1: Producir un hash Argon2id
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        if ($hash === false) {
            throw new AuthenticationException(
                'Failed to hash password'
            );
        }

        // Requisito 1.2: Asegurar que el hash comience con el prefijo "$argon2id$"
        if (!str_starts_with($hash, '$argon2id$')) {
            throw new AuthenticationException(
                'Hash algorithm verification failed'
            );
        }

        // Requisito 1.3: Cada hash es diferente debido a la sal única
        // Requisito 1.5: La contraseña original no puede ser recuperada

        return $hash;
    }

    /**
     * Verifica una contraseña contra un hash usando comparación segura contra ataques de tiempo
     *
     * Requisitos: 2.1, 2.2, 2.3, 2.4
     *
     * @param string $password Contraseña en texto plano a verificar
     * @param string $hash Hash de contraseña contra el cual verificar
     * @return bool Verdadero si la contraseña coincide, falso en caso contrario
     */
    public function verify(string $password, string $hash): bool
    {
        // Requisito 2.1, 2.4: Usar comparación segura contra ataques de tiempo mediante password_verify()
        // password_verify() usa comparación en tiempo constante internamente
        $result = password_verify($password, $hash);

        // Requisito 2.2: Retornar verdadero para contraseña correcta
        // Requisito 2.3: Retornar falso para contraseña incorrecta
        return $result;
    }

    /**
     * Verifica si un hash necesita ser regenerado con los parámetros del algoritmo actual
     *
     * Requisitos: 2.5, 16.1
     *
     * @param string $hash Hash de contraseña a verificar
     * @return bool Verdadero si el hash necesita regenerarse, falso en caso contrario
     */
    public function needsRehash(string $hash): bool
    {
        // Requisito 2.5: Detectar si el hash usa un algoritmo desactualizado
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }
}
