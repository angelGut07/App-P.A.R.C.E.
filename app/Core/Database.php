<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * Clase de gestión de base de datos
 *
 * Administra las conexiones a la base de datos mediante PDO con soporte de pool de conexiones.
 * Provee métodos de construcción de consultas y soporte para transacciones.
 * Incluye validación de conexión, lógica de reintentos y verificación de salud.
 */
class Database
{
    private static ?PDO $connection = null;
    private static array $config = [];
    private static int $maxRetries = 3;
    private static int $retryDelay = 1000; // milisegundos
    private static ?string $lastError = null;
    private static bool $healthCheckPassed = false;

    /**
     * Establece la configuración de la base de datos
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
        self::$healthCheckPassed = false;
    }

    /**
     * Obtiene la conexión PDO activa (patrón singleton)
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::connect();
        }

        return self::$connection;
    }

    /**
     * Inyecta una conexión PDO ya construida (o la limpia con null), en vez de
     * dejar que getConnection() cree una real vía connect(). Único propósito:
     * permitir a los tests unitarios inyectar un PDO mockeado (PHPUnit
     * createMock) sin tocar MySQL — ver tests/Unit/Support/MocksDatabase.php.
     * No se usa en ningún flujo de producción.
     */
    public static function setConnection(?PDO $connection): void
    {
        self::$connection = $connection;
    }

    /**
     * Establece la conexión a la base de datos con lógica de reintentos
     */
    private static function connect(): void
    {
        $driver = self::$config['driver'] ?? 'mysql';
        $host = self::$config['host'] ?? '127.0.0.1';
        $port = self::$config['port'] ?? 3306;
        $database = self::$config['database'] ?? '';
        $username = self::$config['username'] ?? 'root';
        $password = self::$config['password'] ?? '';
        $charset = self::$config['charset'] ?? 'utf8mb4';

        $dsn = "{$driver}:host={$host};port={$port};dbname={$database};charset={$charset}";

        $attempt = 0;
        $lastException = null;

        while ($attempt < self::$maxRetries) {
            try {
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => true, // Pool de conexiones
                    PDO::ATTR_TIMEOUT => 5,       // Tiempo límite de conexión
                ];

                // Bases de datos gestionadas (Aiven, TiDB...) exigen TLS: ruta del certificado CA
                if (!empty(self::$config['ssl_ca'])) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = self::$config['ssl_ca'];
                }

                self::$connection = new PDO($dsn, $username, $password, $options);

                // Misma zona horaria en MySQL (CURRENT_TIMESTAMP) y en PHP (date())
                self::$connection->exec("SET time_zone = '" . (new \DateTime())->format('P') . "'");

                self::$lastError = null;
                self::$healthCheckPassed = true;
                self::logConnection('success', $host, $database);
                return;

            } catch (PDOException $e) {
                $attempt++;
                $lastException = $e;
                self::$lastError = $e->getMessage();

                self::logConnection('failed', $host, $database, $e->getMessage(), $attempt);

                if ($attempt < self::$maxRetries) {
                    // Retroceso exponencial: 1s, 2s, 4s
                    $delay = self::$retryDelay * pow(2, $attempt - 1);
                    usleep($delay * 1000);
                }
            }
        }

        // Todos los reintentos fallaron
        self::$healthCheckPassed = false;
        throw new DatabaseException(
            "Database connection failed after " . self::$maxRetries . " attempts: " . $lastException->getMessage(),
            (int) $lastException->getCode(),
            $lastException
        );
    }

    /**
     * Ejecuta una consulta SQL directa con parámetros preparados
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $connection = self::getConnection();
            $statement = $connection->prepare($sql);
            $statement->execute($params);
            return $statement;
        } catch (PDOException $e) {
            self::logQuery('failed', $sql, $params, $e->getMessage());
            throw new DatabaseException(
                "Query execution failed: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Obtiene todos los registros que coincidan con la consulta
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $statement = self::query($sql, $params);
        return $statement->fetchAll();
    }

    /**
     * Obtiene un único registro que coincida con la consulta
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = self::query($sql, $params);
        $result = $statement->fetch();
        return $result ?: null;
    }


    /**
     * Inserta un registro y retorna el ID del último insertado
     */
    public static function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

        self::query($sql, array_values($data));

        return (int) self::getConnection()->lastInsertId();
    }

    /**
     * Actualiza registros en la tabla indicada y retorna la cantidad de filas afectadas
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = "{$column} = ?";
        }
        $setClause = implode(', ', $set);

        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";

        $params = array_merge(array_values($data), $whereParams);
        $statement = self::query($sql, $params);

        return $statement->rowCount();
    }

    /**
     * Elimina registros de la tabla indicada y retorna la cantidad de filas afectadas
     */
    public static function delete(string $table, string $where, array $whereParams = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $statement = self::query($sql, $whereParams);
        return $statement->rowCount();
    }

    /**
     * Inicia una transacción de base de datos
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Confirma (commit) la transacción activa
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Revierte (rollback) la transacción activa
     */
    public static function rollback(): bool
    {
        return self::getConnection()->rollBack();
    }

    /**
     * Indica si hay una transacción activa en curso
     */
    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }

    /**
     * Cierra la conexión activa a la base de datos
     */
    public static function disconnect(): void
    {
        self::$connection = null;
        self::$healthCheckPassed = false;
    }

    /**
     * Realiza una verificación de salud de la conexión a la base de datos
     */
    public static function healthCheck(): array
    {
        $startTime = microtime(true);
        $status = 'healthy';
        $message = 'Database connection is operational';
        $details = [];

        try {
            // Probar la conexión
            $connection = self::getConnection();

            // Probar con una consulta simple
            $statement = $connection->query('SELECT 1 as test');
            $result = $statement->fetch();

            if ($result['test'] !== 1) {
                throw new \Exception('Health check query returned unexpected result');
            }

            // Obtener información del servidor
            $details['driver'] = self::$config['driver'] ?? 'unknown';
            $details['host'] = self::$config['host'] ?? 'unknown';
            $details['database'] = self::$config['database'] ?? 'unknown';
            $details['server_version'] = $connection->getAttribute(PDO::ATTR_SERVER_VERSION);
            $details['connection_status'] = $connection->getAttribute(PDO::ATTR_CONNECTION_STATUS);

        } catch (\Throwable $e) {
            $status = 'unhealthy';
            $message = 'Database health check failed: ' . $e->getMessage();
            $details['error'] = $e->getMessage();
            $details['last_error'] = self::$lastError;
        }

        $responseTime = round((microtime(true) - $startTime) * 1000, 2);
        $details['response_time_ms'] = $responseTime;

        return [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Verifica si la conexión a la base de datos está activa
     */
    public static function isConnected(): bool
    {
        if (self::$connection === null) {
            return false;
        }

        try {
            self::$connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Retorna el último mensaje de error registrado
     */
    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Registra en log el intento de conexión a la base de datos
     */
    private static function logConnection(string $status, string $host, string $database, ?string $error = null, int $attempt = 1): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/database-' . date('Y-m-d') . '.log';

        $message = sprintf(
            "[%s] Connection %s: host=%s, database=%s, attempt=%d",
            date('Y-m-d H:i:s'),
            $status,
            $host,
            $database,
            $attempt
        );

        if ($error) {
            $message .= ", error=" . $error;
        }

        $message .= "\n";

        @file_put_contents($logFile, $message, FILE_APPEND);
    }

    /**
     * Registra en log la ejecución de una consulta SQL
     */
    private static function logQuery(string $status, string $sql, array $params, ?string $error = null): void
    {
        $logDir = __DIR__ . '/../../storage/logs';

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/database-' . date('Y-m-d') . '.log';

        $message = sprintf(
            "[%s] Query %s: %s",
            date('Y-m-d H:i:s'),
            $status,
            $sql
        );

        if (!empty($params)) {
            $message .= ", params=" . json_encode($params);
        }

        if ($error) {
            $message .= ", error=" . $error;
        }

        $message .= "\n";

        @file_put_contents($logFile, $message, FILE_APPEND);
    }
}
