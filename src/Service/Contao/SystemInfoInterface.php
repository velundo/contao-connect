<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

/** Versionen und installierte Bundles/Pakete (ohne Pfade). */
interface SystemInfoInterface
{
    public function contaoVersion(): string;

    /** @return list<string> Kurznamen der Bundles des Kernels */
    public function bundles(): array;

    /** @return list<array{name: string, type: string, version: string}> */
    public function packages(): array;
}
