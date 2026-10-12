<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Velundo\ContaoConnect\Service\Store\RateStoreInterface;

/**
 * Ratenbegrenzung je Token: festes Minutenfenster, Zähler in tl_velundo_rate (atomar erhöht).
 * Zähler älterer Fenster werden beim ersten Treffer eines neuen Fensters entfernt.
 */
final class RateLimiter
{
    private const WINDOW = 60;
    private const KEEP_WINDOWS = 5;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly RateStoreInterface $store,
        private readonly Settings $settings,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Zählt eine Anfrage und sagt, ob sie noch erlaubt ist.
     *
     * @return array{allowed: bool, limit: int, remaining: int, retry_after: int}
     */
    public function hit(int $tokenId): array
    {
        $limit = max(1, $this->settings->rateLimitPerMinute());
        $now = ($this->clock)();
        $window = intdiv($now, self::WINDOW);
        $count = $this->store->increment($tokenId, $window);
        if ($count === 1) {
            $this->store->prune($window - self::KEEP_WINDOWS);
        }
        return [
            'allowed' => $count <= $limit,
            'limit' => $limit,
            'remaining' => max(0, $limit - $count),
            'retry_after' => max(1, ($window + 1) * self::WINDOW - $now),
        ];
    }
}
