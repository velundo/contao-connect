<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Store;

/**
 * Zähler der Ratenbegrenzung je Token und Minutenfenster. Im Betrieb die Tabelle tl_velundo_rate, in Prüfungen ein Feld.
 */
interface RateStoreInterface
{
    /** Erhöht den Zähler atomar um 1 und liefert den Stand danach. */
    public function increment(int $tokenId, int $window): int;

    /** Entfernt Zähler älterer Fenster. */
    public function prune(int $beforeWindow): void;
}
