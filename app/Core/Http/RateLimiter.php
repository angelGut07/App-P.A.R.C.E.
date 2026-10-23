<?php

namespace App\Core\Http;

/**
 * Utilidad de Limitación de Tasa
 *
 * Implementa la limitación de tasa basada en IP usando el algoritmo de ventana deslizante.
 * Rastrea los intentos de inicio de sesión por dirección IP con expiración automática.
 *
 * Requisitos: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 17.1, 17.2, 17.3, 17.4, 17.5, 17.6, 17.7
 */
class RateLimiter
{
    /**
     * Almacenamiento en memoria para el seguimiento de intentos
     *
     * Estructura: [
     *   'endpoint:ip' => [
     *     'attempts' => int,
     *     'window_start' => int (timestamp),
     *     'locked_until' => int|null (timestamp)
     *   ]
     * ]
     *
     * @var array
     */
    private static array $storage = [];

    /**
     * Ruta del archivo de almacenamiento para persistencia
     */
    private const STORAGE_FILE = __DIR__ . '/../../../storage/rate_limit.json';

    /**
     * Ejecuta $operation() dentro de una sección crítica exclusiva sobre el
     * archivo de almacenamiento: adquiere un lock, carga self::$storage desde
     * disco, ejecuta la operación (que lee/muta self::$storage libremente),
     * persiste el resultado y libera el lock — todo antes de que cualquier
     * otro proceso pueda leer o escribir el archivo.
     *
     * Sin esto, dos peticiones concurrentes pueden leer el mismo estado,
     * mutar cada una su copia en memoria, y la segunda escritura descarta el
     * incremento de la primera — permitiendo sobrepasar el límite de
     * intentos bajo carga concurrente, justo el escenario (fuerza bruta /
     * credential stuffing) para el que este mecanismo existe.
     *
     * @param callable $operation Lógica que lee/muta self::$storage
     * @return mixed El valor de retorno de $operation()
     */
    private static function withLock(callable $operation): mixed
    {
        $dir = dirname(self::STORAGE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen(self::STORAGE_FILE, 'c+');
        if ($handle === false) {
            // No se pudo abrir el archivo (permisos, disco, etc.) — degradar
            // sin lock antes que romper el flujo de autenticación por completo.
            return $operation();
        }

        try {
            flock($handle, LOCK_EX);

            $contents = stream_get_contents($handle);
            self::$storage = ($contents !== false && $contents !== '')
                ? (json_decode($contents, true) ?: [])
                : [];

            $result = $operation();

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(self::$storage));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Duración de la ventana deslizante en segundos (15 minutos)
     *
     * Requisito 6.2, 17.2
     */
    private const WINDOW_DURATION = 900; // 15 minutos

    /**
     * Máximo de intentos permitidos por ventana
     *
     * Requisito 6.3, 17.3
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Verifica si la solicitud está limitada por tasa
     *
     * Requisitos: 6.1, 6.2, 6.3, 6.4, 17.1, 17.2, 17.3, 17.4
     *
     * @param string $endpoint Identificador del endpoint (ej. 'login')
     * @param string $ipAddress Dirección IP del cliente
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int|null]
     */
    public static function check(string $endpoint, string $ipAddress): array
    {
        return self::withLock(function () use ($endpoint, $ipAddress) {
            $key = self::getKey($endpoint, $ipAddress);
            $now = time();

            // Requisito 6.4, 17.4: Limpiar entradas expiradas
            self::cleanupExpired();

            // Obtener o inicializar los datos de seguimiento
            if (!isset(self::$storage[$key])) {
                self::$storage[$key] = [
                    'attempts' => 0,
                    'window_start' => $now,
                    'locked_until' => null
                ];
            }

            $data = &self::$storage[$key];

            // Verificar si está bloqueado actualmente
            if ($data['locked_until'] !== null && $now < $data['locked_until']) {
                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'reset_at' => $data['locked_until']
                ];
            }

            // Requisito 6.2, 17.2: Verificar si la ventana ha expirado (ventana deslizante)
            if ($now - $data['window_start'] >= self::WINDOW_DURATION) {
                // Reiniciar la ventana
                $data['attempts'] = 0;
                $data['window_start'] = $now;
                $data['locked_until'] = null;
            }

            // Requisito 6.3, 17.3: Verificar si se alcanzó el máximo de intentos
            if ($data['attempts'] >= self::MAX_ATTEMPTS) {
                // Bloquear hasta que expire la ventana
                $data['locked_until'] = $data['window_start'] + self::WINDOW_DURATION;

                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'reset_at' => $data['locked_until']
                ];
            }

            // Requisito 6.1, 17.1: Permitir la solicitud
            return [
                'allowed' => true,
                'remaining' => self::MAX_ATTEMPTS - $data['attempts'],
                'reset_at' => $data['window_start'] + self::WINDOW_DURATION
            ];
        });
    }

    /**
     * Registra un intento fallido
     *
     * Requisitos: 6.1, 6.3, 17.1, 17.3
     *
     * @param string $endpoint Identificador del endpoint
     * @param string $ipAddress Dirección IP del cliente
     * @return void
     */
    public static function recordAttempt(string $endpoint, string $ipAddress): void
    {
        self::withLock(function () use ($endpoint, $ipAddress) {
            $key = self::getKey($endpoint, $ipAddress);
            $now = time();

            // Inicializar si no existe
            if (!isset(self::$storage[$key])) {
                self::$storage[$key] = [
                    'attempts' => 0,
                    'window_start' => $now,
                    'locked_until' => null
                ];
            }

            // Requisito 6.1, 17.1: Incrementar el contador de intentos
            self::$storage[$key]['attempts']++;
        });
    }

    /**
     * Reinicia los intentos tras un inicio de sesión exitoso
     *
     * Requisitos: 6.5, 17.5
     *
     * @param string $endpoint Identificador del endpoint
     * @param string $ipAddress Dirección IP del cliente
     * @return void
     */
    public static function reset(string $endpoint, string $ipAddress): void
    {
        self::withLock(function () use ($endpoint, $ipAddress) {
            $key = self::getKey($endpoint, $ipAddress);

            // Requisito 6.5, 17.5: Limpiar los datos de seguimiento al tener éxito
            unset(self::$storage[$key]);
        });
    }

    /**
     * Obtiene los intentos restantes para una IP
     *
     * @param string $endpoint Identificador del endpoint
     * @param string $ipAddress Dirección IP del cliente
     * @return int Intentos restantes
     */
    public static function getRemaining(string $endpoint, string $ipAddress): int
    {
        $key = self::getKey($endpoint, $ipAddress);

        if (!isset(self::$storage[$key])) {
            return self::MAX_ATTEMPTS;
        }

        $attempts = self::$storage[$key]['attempts'];
        return max(0, self::MAX_ATTEMPTS - $attempts);
    }

    /**
     * Obtiene el tiempo hasta que se reinicia el límite de tasa
     *
     * @param string $endpoint Identificador del endpoint
     * @param string $ipAddress Dirección IP del cliente
     * @return int|null Timestamp cuando se reinicia el límite, o nulo si no está limitado
     */
    public static function getResetTime(string $endpoint, string $ipAddress): ?int
    {
        $key = self::getKey($endpoint, $ipAddress);

        if (!isset(self::$storage[$key])) {
            return null;
        }

        $data = self::$storage[$key];

        if ($data['locked_until'] !== null) {
            return $data['locked_until'];
        }

        return $data['window_start'] + self::WINDOW_DURATION;
    }

    /**
     * Limpia las entradas de seguimiento expiradas
     *
     * Requisitos: 6.4, 17.4
     *
     * @return void
     */
    private static function cleanupExpired(): void
    {
        $now = time();

        foreach (self::$storage as $key => $data) {
            // Eliminar si la ventana expiró y no está bloqueada
            if ($data['locked_until'] === null &&
                $now - $data['window_start'] >= self::WINDOW_DURATION) {
                unset(self::$storage[$key]);
            }

            // Eliminar si el bloqueo expiró
            if ($data['locked_until'] !== null && $now >= $data['locked_until']) {
                unset(self::$storage[$key]);
            }
        }
    }

    /**
     * Genera la clave de almacenamiento para el endpoint y la IP
     *
     * Requisitos: 6.7, 17.7
     *
     * @param string $endpoint Identificador del endpoint
     * @param string $ipAddress Dirección IP del cliente
     * @return string Clave de almacenamiento
     */
    private static function getKey(string $endpoint, string $ipAddress): string
    {
        // Requisito 6.7, 17.7: Seguimiento por endpoint
        return $endpoint . ':' . $ipAddress;
    }

    /**
     * Limpia todos los datos de límite de tasa (para pruebas)
     *
     * @return void
     */
    public static function clearAll(): void
    {
        self::$storage = [];
    }

    /**
     * Obtiene el conteo de intentos actual (para pruebas y depuración)
     *
     * @param string $endpoint Identificador del endpoint
     * @param string $ipAddress Dirección IP del cliente
     * @return int Conteo de intentos actual
     */
    public static function getAttempts(string $endpoint, string $ipAddress): int
    {
        $key = self::getKey($endpoint, $ipAddress);

        if (!isset(self::$storage[$key])) {
            return 0;
        }

        return self::$storage[$key]['attempts'];
    }
}
