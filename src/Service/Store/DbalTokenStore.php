<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Store;

use Doctrine\DBAL\Connection;

/**
 * Tokens in der Tabelle tl_velundo_token (DCA contao/dca/tl_velundo_token.php, angelegt mit contao:migrate).
 * Gespeichert wird nur der SHA-256-Hash; jede Methode schreibt nur ihre eigenen Spalten.
 */
final class DbalTokenStore implements TokenStoreInterface
{
    public const TABLE = 'tl_velundo_token';

    public function __construct(private readonly Connection $connection) {}

    public function insert(string $name, string $hash, int $created, string $scopes = ''): int
    {
        $data = [
            'tstamp' => $created,
            'name' => $name,
            'token_hash' => $hash,
            'created' => $created,
            'last_used' => 0,
            'revoked' => 0,
        ];
        if ($scopes !== '') {
            // Spalte scopes gibt es erst ab 1.1.0 (contao:migrate); ohne Zusatzrechte bleibt der Eintrag davon unabhängig
            $data['scopes'] = $scopes;
            try {
                $this->connection->insert(self::TABLE, $data);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Das Token konnte nicht mit Zusatzrechten gespeichert werden – fehlt die Spalte scopes? Bitte „contao:migrate“ (bzw. „Datenbank aktualisieren“ im Contao Manager) ausführen.', 0, $e);
            }
        } else {
            $this->connection->insert(self::TABLE, $data);
        }
        return (int)$this->connection->lastInsertId();
    }

    public function all(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative('SELECT id, name, token_hash, created, last_used, revoked, scopes FROM ' . self::TABLE . ' ORDER BY id');
        } catch (\Throwable) {
            // Tabelle noch ohne Spalte scopes (Stand 1.0.x, contao:migrate nicht ausgeführt): Tokens ohne Zusatzrechte
            $rows = $this->connection->fetchAllAssociative('SELECT id, name, token_hash, created, last_used, revoked FROM ' . self::TABLE . ' ORDER BY id');
        }
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'token_hash' => (string)$r['token_hash'],
            'created' => (int)$r['created'],
            'last_used' => (int)$r['last_used'],
            'revoked' => (int)$r['revoked'],
            'scopes' => (string)($r['scopes'] ?? ''),
        ], $rows);
    }

    public function markUsed(int $id, int $time): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET last_used = ? WHERE id = ?', [$time, $id]);
    }

    public function revoke(int $id, int $time): bool
    {
        return (int)$this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET revoked = ?, tstamp = ? WHERE id = ? AND revoked = 0', [$time, $time, $id]) === 1;
    }
}
