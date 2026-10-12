<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

/** Positivliste für HTML (im Betrieb Input::stripTags mit den erlaubten Tags/Attributen der Contao-Einstellungen). */
interface HtmlWhitelistInterface
{
    public function clean(string $html): string;
}
