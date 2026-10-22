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

    /** Fehlversuche ohne gültiges Token je Minute und Absenderadresse (ab 1.1.0) – Bremse gegen Durchprobieren. */
    public const AUTH_FAILURES_PER_MINUTE = 30;

    /** Zähler der Fehlversuche liegen in tl_velundo_rate oberhalb der Token-Kennungen (token_id ist unsigned int). */
    private const AUTH_KEY_BASE = 1_000_000_000;

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
        return $this->hitKey($tokenId, max(1, $this->settings->rateLimitPerMinute()));
    }

    /**
     * Zählt einen Fehlversuch (kein gültiges Token) je Absenderadresse; über der Grenze antwortet die Schnittstelle 429
     * statt 401. Die Adresse wird nicht gespeichert, nur ein Schlüssel aus ihrem Hash.
     *
     * @return array{allowed: bool, limit: int, remaining: int, retry_after: int}
     */
    public function hitAuthFailure(string $remoteAddress): array
    {
        $key = self::AUTH_KEY_BASE + (int)(hexdec(substr(hash('sha256', 'auth:' . $remoteAddress), 0, 7)) % 1_000_000_000);
        return $this->hitKey($key, self::AUTH_FAILURES_PER_MINUTE);
    }

    /** @return array{allowed: bool, limit: int, remaining: int, retry_after: int} */
    private function hitKey(int $key, int $limit): array
    {
        $now = ($this->clock)();
        $window = intdiv($now, self::WINDOW);
        $count = $this->store->increment($key, $window);
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
