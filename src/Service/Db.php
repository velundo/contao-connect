<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Doctrine\DBAL\Connection;
use Velundo\ContaoConnect\Http\ApiException;

/**
 * Datenbankzugriffe über Doctrine DBAL (Verbindung von Contao). Nur vorbereitete Abfragen mit Platzhaltern; LIMIT und
 * OFFSET werden als geprüfte Ganzzahlen eingesetzt (emulierte Platzhalter würden sie sonst als Text binden).
 * Verwendet nur fetchAssociative, fetchAllAssociative, fetchOne, executeStatement, insert, update und lastInsertId –
 * dieselben Methoden in DBAL 3 und 4.
 */
final class Db
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $params);
        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return array_values($this->connection->fetchAllAssociative($sql, $params));
    }

    /** @param list<mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->connection->fetchOne($sql, $params);
        return $value === false ? null : $value;
    }

    /** @param list<mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        return (int)$this->connection->executeStatement($sql, $params);
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $this->connection->insert($table, $data);
        return (int)$this->connection->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(string $table, int $id, array $data): void
    {
        if ($data !== []) {
            $this->connection->update($table, $data, ['id' => $id]);
        }
    }

    /**
     * Ein Datensatz oder 404 `not_found`.
     *
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    public function record(string $table, int $id, array $columns, string $label): array
    {
        $row = $this->one('SELECT ' . implode(', ', $columns) . ' FROM ' . $table . ' WHERE id = ?', [$id]);
        if ($row === null) {
            throw ApiException::notFound(sprintf('%s %d wurde nicht gefunden.', $label, $id), 'not_found', ['id' => $id, 'table' => $table]);
        }
        return $row;
    }

    /** „?, ?, ?“ für IN (…) mit $n Platzhaltern (mindestens einer). */
    public static function placeholders(int $n): string
    {
        return implode(', ', array_fill(0, max(1, $n), '?'));
    }

    /** LIKE-Muster „enthält“; Platzhalter des Suchtexts mit ! maskiert (Abfrage: LIKE ? ESCAPE '!'). */
    public static function contains(string $term): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    }

    /** LIMIT/OFFSET als geprüfte Ganzzahlen. */
    public static function limit(int $limit, int $offset = 0): string
    {
        return ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);
    }

    /** Zeitstempel als ISO 8601 mit Versatz (Zeitzone der Installation); 0/leer ergibt null. */
    public static function iso(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '' || !is_numeric($timestamp) || (int)$timestamp <= 0) {
            return null;
        }
        return (new \DateTimeImmutable('@' . (int)$timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format(DATE_ATOM);
    }

    /** Schalter aus der Datenbank (boolean, char(1) '' / '1'). */
    public static function flag(mixed $value): bool
    {
        return (bool)(int)$value;
    }
}
