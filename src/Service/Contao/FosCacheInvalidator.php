<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

use Psr\Log\LoggerInterface;

/**
 * Cache-Tags über den Cache-Manager des FOSHttpCacheBundle (Dienst fos_http_cache.cache_manager) – derselbe Weg wie
 * DataContainer::invalidateCacheTags() im Backend. Ohne eingerichteten HTTP-Cache gibt es den Dienst nicht; dann
 * passiert nichts (wie im Kern).
 */
final class FosCacheInvalidator implements CacheInvalidatorInterface
{
    /** @param object|null $cacheManager FOS\HttpCacheBundle\CacheManager */
    public function __construct(
        private readonly ?object $cacheManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function available(): bool
    {
        return $this->cacheManager !== null && method_exists($this->cacheManager, 'invalidateTags');
    }

    public function invalidate(array $tags): bool
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if ($tags === [] || !$this->available()) {
            return false;
        }
        try {
            $this->cacheManager->invalidateTags($tags);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('velundo: Cache-Tags nicht invalidiert (' . $e->getMessage() . ')');
            return false;
        }
    }
}
