<?php

declare(strict_types=1);

namespace Kone\Database;

use PDO;
use Throwable;

/**
 * PDO wrapper mirroring v2 DbService (pool.query / pool.transaction).
 *
 * Parameterized statements only — no string interpolation of user input.
 * Reuses the v1 singleton connection from config/database.php by default,
 * but accepts any PDO (e.g. an isolated test DB) for testability.
 */
final class Db
{
    private static ?Db $instance = null;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? self::defaultConnection();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private static function defaultConnection(): PDO
    {
        require_once dirname(__DIR__, 2) . '/config/database.php';
        return \Database::getInstance()->getConnection();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Run a statement and return ALL rows as assoc arrays. */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Run a statement and return the affected row count. */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Run a statement and return the FIRST row, or null. */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Run $fn inside a transaction; $fn receives $this (same Db instance).
     * Commits on success, rolls back on any Throwable, then rethrows.
     * Mirrors v2 DbService.transaction(fn).
     *
     * @template T
     * @param callable(Db): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}