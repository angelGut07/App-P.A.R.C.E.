<?php

namespace Tests\Unit\Support;

use App\Core\Database;
use PDO;
use PDOStatement;

/**
 * Helper compartido para tests unitarios de Services que llaman a
 * App\Core\Database directamente (fachada estática, sin capa Repository). Inyecta un PDO mockeado (PHPUnit createMock) vía
 * Database::setConnection(), nunca toca MySQL real.
 *
 * Uso típico: mockPdo() en setUp(), expectQueries() con una configuración por
 * cada llamada a Database::query()/fetchOne()/fetchAll()/insert()/update()/
 * delete() esperada, EN EL ORDEN EXACTO en que el código real las hace.
 */
trait MocksDatabase
{
    private PDO $mockPdo;

    protected function mockPdo(): PDO
    {
        $this->mockPdo = $this->createMock(PDO::class);
        Database::setConnection($this->mockPdo);

        return $this->mockPdo;
    }

    protected function unmockPdo(): void
    {
        Database::setConnection(null);
    }

    /**
     * Configura prepare() para devolver, en orden estricto, un PDOStatement
     * mockeado por cada paso. Cada elemento de $steps es un callable que
     * recibe el PDOStatement mock y lo configura (fetch/fetchAll/rowCount).
     * execute() siempre se stubea como exitoso salvo que el propio callable
     * lo sobreescriba.
     */
    protected function expectQueries(array $steps): void
    {
        $statements = array_map(function (callable $configure) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $configure($stmt);
            return $stmt;
        }, $steps);

        $this->mockPdo->expects($this->exactly(count($statements)))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls(...$statements);
    }

    /** Configura un único paso que devuelve una fila (o null) vía fetch(). */
    protected function stepFetchOne(array|false $row): callable
    {
        return function (PDOStatement $stmt) use ($row): void {
            $stmt->method('fetch')->willReturn($row);
        };
    }

    /** Configura un único paso que devuelve varias filas vía fetchAll(). */
    protected function stepFetchAll(array $rows): callable
    {
        return function (PDOStatement $stmt) use ($rows): void {
            $stmt->method('fetchAll')->willReturn($rows);
        };
    }

    /** Configura un paso de escritura (INSERT/UPDATE/DELETE) sin resultado de lectura. */
    protected function stepWrite(int $rowCount = 1): callable
    {
        return function (PDOStatement $stmt) use ($rowCount): void {
            $stmt->method('rowCount')->willReturn($rowCount);
        };
    }

    /** Configura un paso cuyo execute() falla con un PDOException (ej. clave duplicada). */
    protected function stepThrows(string $message): callable
    {
        return function (PDOStatement $stmt) use ($message): void {
            $stmt->method('execute')->willThrowException(new \PDOException($message));
        };
    }

    /** Configura beginTransaction/commit/rollBack como exitosos en el PDO mock. */
    protected function allowTransactions(): void
    {
        $this->mockPdo->method('beginTransaction')->willReturn(true);
        $this->mockPdo->method('commit')->willReturn(true);
        $this->mockPdo->method('rollBack')->willReturn(true);
    }

    protected function expectLastInsertId(string $id): void
    {
        $this->mockPdo->method('lastInsertId')->willReturn($id);
    }
}
