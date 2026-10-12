<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

/**
 * Versionseinträge in tl_version (im Betrieb Contao\Versions). $module ist das Backend-Modul für die Bearbeiten-Adresse
 * der Version (page, article, news).
 */
interface VersionRecorderInterface
{
    /** Vor einer Änderung: legt die Ausgangsversion an, falls der Datensatz noch keine hat. */
    public function initialize(string $table, int $id, string $module): void;

    /** Nach einer Änderung bzw. nach dem Anlegen: neue Version. */
    public function create(string $table, int $id, string $module): void;
}
