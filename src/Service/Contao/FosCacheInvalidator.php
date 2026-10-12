<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

use Psr\Log\LoggerInterface;

/**
 * Cache-Tags invalidieren – derselbe Weg wie DataContainer::invalidateCacheTags() im Backend:
 * Contao 6 über den Cache-Tag-Manager (contao.cache.tag_manager: löst InvalidateCacheTagsEvent aus und gibt die Tags
 * an den Cache-Invalidator weiter), Contao 5 direkt über den Cache-Manager des FOSHttpCacheBundle
 * (fos_http_cache.cache_manager). „Verfügbar“ heißt: Es gibt einen Cache-Manager (HTTP-Cache eingerichtet); ohne ihn
 * passiert beim Schreiben nichts (wie im Kern) und POST cache/clear meldet feature_unavailable.
 */
final class FosCacheInvalidator implements CacheInvalidatorInterface
{
    /**
     * @param object|null $cacheManager FOS\HttpCacheBundle\CacheManager (Dienst fos_http_cache.cache_manager)
     * @param object|null $tagManager Contao\CoreBundle\Cache\CacheTagManager (Dienst contao.cache.tag_manager, Contao 6)
     */
    public function __construct(
        private readonly ?object $cacheManager,
        private readonly LoggerInterface $logger,
        private readonly ?object $tagManager = null,
    ) {}

    public function available(): bool
    {
        return $this->cacheManager !== null && method_exists($this->cacheManager, 'invalidateTags');
    }

    public function invalidate(array $tags): bool
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if ($tags === []) {
            return false;
        }
        try {
            if ($this->tagManager !== null && method_exists($this->tagManager, 'invalidateTags')) {
                $this->tagManager->invalidateTags($tags);
                return $this->available();
            }
            if (!$this->available()) {
                return false;
            }
            $this->cacheManager->invalidateTags($tags);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('velundo: Cache-Tags nicht invalidiert (' . $e->getMessage() . ')');
            return false;
        }
    }
}
