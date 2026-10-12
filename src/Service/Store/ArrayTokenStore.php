<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Store;

/**
 * Token-Ablage im Arbeitsspeicher – nur für Prüfungen.
 */
final class ArrayTokenStore implements TokenStoreInterface
{
    /** @var array<int, array{id: int, name: string, token_hash: string, created: int, last_used: int, revoked: int}> */
    public array $rows = [];

    public function insert(string $name, string $hash, int $created): int
    {
        $id = $this->rows === [] ? 1 : max(array_keys($this->rows)) + 1;
        $this->rows[$id] = ['id' => $id, 'name' => $name, 'token_hash' => $hash, 'created' => $created, 'last_used' => 0, 'revoked' => 0];
        return $id;
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function markUsed(int $id, int $time): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['last_used'] = $time;
        }
    }

    public function revoke(int $id, int $time): bool
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]['revoked'] > 0) {
            return false;
        }
        $this->rows[$id]['revoked'] = $time;
        return true;
    }
}
