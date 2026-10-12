<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

/** Alias aus einem Text (im Betrieb der Slug-Dienst contao.slug mit den Einstellungen einer Seite). */
interface SluggerInterface
{
    /** @param callable(string): bool $exists */
    public function generate(string $text, int $pageId, callable $exists): string;
}
