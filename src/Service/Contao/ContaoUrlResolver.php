<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\NewsModel;
use Contao\PageModel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Adressen über den Content-URL-Generator von Contao 5.3+ (absolut). Seiten ohne eigene Adresse (Startpunkt,
 * Weiterleitung ohne Ziel, Fehlerseiten …) und Nachrichten ohne Detailseite liefern null – jede Ausnahme des Generators
 * (RouteNotFoundException, ForwardPageNotFoundException …) bedeutet „keine Adresse“.
 */
final class ContaoUrlResolver implements UrlResolverInterface
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ContentUrlGenerator $generator,
    ) {}

    public function pageUrl(int $id): ?string
    {
        try {
            $this->framework->initialize();
            $page = $this->framework->getAdapter(PageModel::class)->findById($id);
            return $page instanceof PageModel ? $this->generator->generate($page, [], UrlGeneratorInterface::ABSOLUTE_URL) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function newsUrl(int $id): ?string
    {
        try {
            if (!class_exists(NewsModel::class)) {
                return null;
            }
            $this->framework->initialize();
            $news = $this->framework->getAdapter(NewsModel::class)->findById($id);
            return $news instanceof NewsModel ? $this->generator->generate($news, [], UrlGeneratorInterface::ABSOLUTE_URL) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
