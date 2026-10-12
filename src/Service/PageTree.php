<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Velundo\ContaoConnect\Http\ApiException;

/**
 * Seitenbaum (tl_page) in einer Abfrage: Tiefensuche nach sorting, je Seite Startpunkt (root) und Ebene (level, 0 =
 * Startpunkt). Seiten ohne erreichbaren Startpunkt (verwaiste pid) erscheinen nicht.
 */
final class PageTree
{
    public const COLUMNS = ['id', 'pid', 'sorting', 'type', 'title', 'alias', 'published', 'hide', 'robots', 'pageTitle', 'description', 'start', 'stop', 'tstamp', 'language'];
    public const MAX_LEVELS = 20;

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(private readonly Db $db) {}

    /**
     * Alle Seiten in Baumreihenfolge mit den Zusatzspalten root und level.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $byPid = [];
        foreach ($this->db->all('SELECT ' . implode(', ', self::COLUMNS) . ' FROM tl_page ORDER BY pid, sorting, id') as $row) {
            $byPid[(int)$row['pid']][] = $row;
        }
        $result = [];
        foreach ($byPid[0] ?? [] as $root) {
            $this->walk($root, (int)$root['id'], 0, $byPid, $result);
        }
        return $this->rows = $result;
    }

    /** Neu laden (nach Änderungen). */
    public function reset(): void
    {
        $this->rows = null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        foreach ($this->all() as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return $this->find($id) ?? throw ApiException::notFound(sprintf('Seite %d wurde nicht gefunden.', $id), 'not_found', ['id' => $id, 'table' => 'tl_page']);
    }

    /** @return list<array<string, mixed>> Startpunkte */
    public function roots(): array
    {
        return array_values(array_filter($this->all(), static fn(array $r): bool => (int)$r['level'] === 0));
    }

    /** Startpunkt aus ?root= (404 `root_not_found`, wenn es keiner ist). */
    public function root(int $id): array
    {
        $row = $this->find($id);
        if ($row === null || (int)$row['level'] !== 0) {
            throw ApiException::notFound(sprintf('Startpunkt %d wurde nicht gefunden.', $id), 'root_not_found', ['root' => $id]);
        }
        return $row;
    }

    /**
     * Teilbaum unter $parentId (ohne die Seite selbst), höchstens $depth Ebenen; $parentId 0 = alle Startpunkte.
     *
     * @return list<array<string, mixed>>
     */
    public function subtree(int $parentId, int $depth): array
    {
        $all = $this->all();
        if ($parentId === 0) {
            return array_values(array_filter($all, static fn(array $r): bool => (int)$r['level'] < $depth));
        }
        $result = [];
        $inside = false;
        $baseLevel = 0;
        foreach ($all as $row) {
            if (!$inside) {
                if ((int)$row['id'] === $parentId) {
                    $inside = true;
                    $baseLevel = (int)$row['level'];
                }
                continue;
            }
            if ((int)$row['level'] <= $baseLevel) {
                break;
            }
            if ((int)$row['level'] - $baseLevel <= $depth) {
                $result[] = $row;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, list<array<string, mixed>>> $byPid
     * @param list<array<string, mixed>> $result
     */
    private function walk(array $row, int $root, int $level, array $byPid, array &$result): void
    {
        if ($level > self::MAX_LEVELS) {
            return;
        }
        $row['root'] = $root;
        $row['level'] = $level;
        $result[] = $row;
        foreach ($byPid[(int)$row['id']] ?? [] as $child) {
            $this->walk($child, $root, $level + 1, $byPid, $result);
        }
    }
}
