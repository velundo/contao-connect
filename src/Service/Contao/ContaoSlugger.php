<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Slug\Slug;

/**
 * Alias wie tl_news::generateAlias(): Slug-Dienst mit den Einstellungen der Detailseite des Archivs (jumpTo) und
 * Prüfung auf Doppelte.
 */
final class ContaoSlugger implements SluggerInterface
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Slug $slug,
    ) {}

    public function generate(string $text, int $pageId, callable $exists): string
    {
        $this->framework->initialize();
        return $this->slug->generate($text, $pageId > 0 ? $pageId : [], $exists);
    }
}
