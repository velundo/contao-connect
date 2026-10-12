<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;

/**
 * Positivliste wie beim Speichern eines Rich-Text-Felds im Backend (InputEncodingMode::sanitizeHtml):
 * Input::stripTags() mit den Einstellungen allowedTags und allowedAttributes.
 */
final class ContaoHtmlWhitelist implements HtmlWhitelistInterface
{
    public function __construct(private readonly ContaoFramework $framework) {}

    public function clean(string $html): string
    {
        $this->framework->initialize();
        $config = $this->framework->getAdapter(Config::class);
        return (string)$this->framework->getAdapter(Input::class)->stripTags($html, (string)$config->get('allowedTags'), (string)$config->get('allowedAttributes'));
    }
}
