<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Psr\Log\LoggerInterface;
use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Service\Contao\CacheInvalidatorInterface;
use Velundo\ContaoConnect\Service\Contao\VersionRecorderInterface;

/**
 * Alle Schreibzugriffe: tstamp setzen, Version anlegen (vorher Ausgangsversion, nachher neue Version), Cache-Tags
 * invalidieren und ins Protokoll schreiben. Es wird nie gelöscht. Datenbankfehler werden zu 500 `write_failed`
 * (der Fehlertext steht nur im Log der Installation).
 */
final class Writer
{
    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly Db $db,
        private readonly VersionRecorderInterface $versions,
        private readonly CacheInvalidatorInterface $cache,
        private readonly LogService $log,
        private readonly LoggerInterface $logger,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /**
     * @param array<string, mixed> $data Spalten
     * @param list<string> $tags Cache-Tags
     */
    public function update(string $table, int $id, array $data, string $module, array $tags, string $message, string $tokenName): void
    {
        $this->versions->initialize($table, $id, $module);
        $data['tstamp'] = $this->now();
        try {
            $this->db->update($table, $id, $data);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('velundo: %s.%d nicht gespeichert (%s)', $table, $id, $e->getMessage()));
            throw ApiException::writeFailed();
        }
        $this->versions->create($table, $id, $module);
        $this->cache->invalidate($tags);
        $this->log->log($message, $tokenName, $table, $id);
    }

    /**
     * @param array<string, mixed> $data Spalten (tstamp wird gesetzt)
     * @param callable(int): list<string> $tags Cache-Tags zur neuen id
     */
    public function insert(string $table, array $data, string $module, callable $tags, string $message, string $tokenName): int
    {
        $data['tstamp'] = $this->now();
        try {
            $id = $this->db->insert($table, $data);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('velundo: Datensatz in %s nicht angelegt (%s)', $table, $e->getMessage()));
            throw ApiException::writeFailed();
        }
        if ($id <= 0) {
            throw ApiException::writeFailed();
        }
        $this->versions->create($table, $id, $module);
        $this->cache->invalidate($tags($id));
        $this->log->log(str_replace('%id%', (string)$id, $message), $tokenName, $table, $id);
        return $id;
    }

    /** Reihenfolge anderer Datensätze (Neunummerierung beim Einsortieren) – ohne tstamp, ohne Version, wie im Backend. */
    public function resort(string $table, array $sortings): void
    {
        try {
            foreach ($sortings as $id => $sorting) {
                $this->db->update($table, (int)$id, ['sorting' => (int)$sorting]);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('velundo: Reihenfolge in %s nicht gespeichert (%s)', $table, $e->getMessage()));
            throw ApiException::writeFailed();
        }
    }

    /** Cache-Tags eines Datensatzes und seines Elternsatzes (wie DataContainer::invalidateCacheTags()). @return list<string> */
    public static function tags(string $table, int $id, string $parentTable, int $parentId): array
    {
        $tags = ['contao.db.' . $table . '.' . $id];
        $tags[] = $parentTable !== '' && $parentId > 0 ? 'contao.db.' . $parentTable . '.' . $parentId : 'contao.db.' . $table;
        return $tags;
    }

    public function invalidate(array $tags): bool
    {
        return $this->cache->invalidate($tags);
    }

    public function cacheAvailable(): bool
    {
        return $this->cache->available();
    }

    public function log(string $message, string $tokenName): void
    {
        $this->log->log($message, $tokenName);
    }
}
