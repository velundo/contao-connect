<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Service\Contao\CacheInvalidatorInterface;

/**
 * Welche optionalen Bereiche diese Installation bietet: Nachrichten (contao/news-bundle), Veranstaltungen
 * (contao/calendar-bundle, nur zur Anzeige) und der HTTP-Cache (Cache-Tags).
 */
final class Features
{
    /** @param array<string, string> $kernelBundles Parameter kernel.bundles (Kurzname → Klasse) */
    public function __construct(
        private readonly array $kernelBundles,
        private readonly CacheInvalidatorInterface $cache,
    ) {}

    public function news(): bool
    {
        return isset($this->kernelBundles['ContaoNewsBundle']);
    }

    public function calendar(): bool
    {
        return isset($this->kernelBundles['ContaoCalendarBundle']);
    }

    public function httpCache(): bool
    {
        return $this->cache->available();
    }

    /** @return array{news: bool, calendar: bool, http_cache: bool} */
    public function all(): array
    {
        return ['news' => $this->news(), 'calendar' => $this->calendar(), 'http_cache' => $this->httpCache()];
    }

    public function requireNews(): void
    {
        if (!$this->news()) {
            throw ApiException::featureUnavailable('news');
        }
    }
}
