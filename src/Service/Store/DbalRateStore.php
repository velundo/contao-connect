<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Store;

use Doctrine\DBAL\Connection;

/**
 * Zähler der Ratenbegrenzung in der Tabelle tl_velundo_rate (eindeutig je Token und Minutenfenster).
 * Erhöht wird atomar in der Datenbank (UPDATE … SET hits = hits + 1); fehlt die Zeile, wird sie angelegt – legt eine
 * gleichzeitige Anfrage sie zuerst an, scheitert das INSERT am eindeutigen Schlüssel und das UPDATE wird wiederholt.
 * Der danach gelesene Stand kann gleichzeitige Treffer enthalten: Die Grenze greift dann eher strenger, nie weicher.
 */
final class DbalRateStore implements RateStoreInterface
{
    public const TABLE = 'tl_velundo_rate';

    public function __construct(private readonly Connection $connection) {}

    public function increment(int $tokenId, int $window): int
    {
        $updated = (int)$this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET hits = hits + 1 WHERE token_id = ? AND window_start = ?', [$tokenId, $window]);
        if ($updated === 0) {
            try {
                $this->connection->insert(self::TABLE, ['token_id' => $tokenId, 'window_start' => $window, 'hits' => 1]);
                return 1;
            } catch (\Throwable) {
                // Zeitgleich angelegt – dann zählen
                $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET hits = hits + 1 WHERE token_id = ? AND window_start = ?', [$tokenId, $window]);
            }
        }
        return (int)$this->connection->fetchOne('SELECT hits FROM ' . self::TABLE . ' WHERE token_id = ? AND window_start = ?', [$tokenId, $window]);
    }

    public function prune(int $beforeWindow): void
    {
        $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE window_start < ?', [$beforeWindow]);
    }
}
