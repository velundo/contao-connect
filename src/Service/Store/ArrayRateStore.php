<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Store;

/**
 * Zähler im Arbeitsspeicher – nur für Prüfungen.
 */
final class ArrayRateStore implements RateStoreInterface
{
    /** @var array<string, int> */
    public array $counts = [];

    public function increment(int $tokenId, int $window): int
    {
        $key = $tokenId . ':' . $window;
        return $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    public function prune(int $beforeWindow): void
    {
        foreach (array_keys($this->counts) as $key) {
            if ((int)explode(':', $key)[1] < $beforeWindow) {
                unset($this->counts[$key]);
            }
        }
    }
}
