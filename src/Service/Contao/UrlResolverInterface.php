<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

/** Absolute Adressen von Seiten und Nachrichten (im Betrieb der Content-URL-Generator von Contao); null ohne Adresse. */
interface UrlResolverInterface
{
    public function pageUrl(int $id): ?string;

    public function newsUrl(int $id): ?string;
}
