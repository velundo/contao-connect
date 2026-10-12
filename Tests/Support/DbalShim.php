<?php

declare(strict_types=1);

/*
 * Ersatz für Doctrine\DBAL\Connection auf PDO/SQLite – nur die Methoden, die das Bundle nutzt (fetchAssociative,
 * fetchAllAssociative, fetchOne, executeStatement, insert, update, lastInsertId). Wird nur geladen, wenn DBAL fehlt;
 * so laufen die Endpunkte samt SQL im Prüfstand ohne Contao.
 */

namespace Doctrine\DBAL;

class Connection
{
    public readonly \PDO $pdo;

    /** @var list<string> ausgeführte Anweisungen (zur Kontrolle in Prüfungen) */
    public array $statements = [];

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        return $this->run($query, $params)->fetch();
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        return $this->run($query, $params)->fetchAll();
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        return $this->run($query, $params)->fetchColumn();
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function insert(string $table, array $data, array $types = []): int
    {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')';
        return $this->run($sql, array_values($data))->rowCount();
    }

    public function update(string $table, array $data, array $criteria, array $types = []): int
    {
        $set = implode(', ', array_map(static fn(string $c): string => "$c = ?", array_keys($data)));
        $where = implode(' AND ', array_map(static fn(string $c): string => "$c = ?", array_keys($criteria)));
        return $this->run("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), array_values($criteria)))->rowCount();
    }

    public function lastInsertId($name = null): string
    {
        return (string)$this->pdo->lastInsertId();
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        $this->statements[] = $sql;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt;
    }
}
