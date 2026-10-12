<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

/** Cache-Tags des HTTP-Caches invalidieren (im Betrieb FOS CacheManager, falls eingerichtet). */
interface CacheInvalidatorInterface
{
    public function available(): bool;

    /** @param list<string> $tags */
    public function invalidate(array $tags): bool;
}
