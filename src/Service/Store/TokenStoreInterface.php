<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Store;

/**
 * Ablage der Zugangstokens (nur Hashes). Im Betrieb die Tabelle tl_velundo_token, in Prüfungen ein Feld.
 * Jede Methode ändert nur die Spalten, die sie betrifft – eine Anfrage, die „zuletzt verwendet“ vermerkt, kann einen
 * gleichzeitigen Widerruf deshalb nicht überschreiben.
 */
interface TokenStoreInterface
{
    /** Neues Token; $scopes als kommagetrennte Liste (ab 1.1.0, leer = keine Zusatzrechte). Rückgabe: Kennung (id). */
    public function insert(string $name, string $hash, int $created, string $scopes = ''): int;

    /**
     * Alle Tokens, auch widerrufene. `scopes` ist die kommagetrennte Liste der Zusatzrechte ('' bei Tokens aus 1.0.x).
     *
     * @return list<array{id: int, name: string, token_hash: string, created: int, last_used: int, revoked: int, scopes: string}>
     */
    public function all(): array;

    public function markUsed(int $id, int $time): void;

    /** Widerruft ein aktives Token; false, wenn es keines mit dieser Kennung gibt oder es schon widerrufen ist. */
    public function revoke(int $id, int $time): bool;
}
