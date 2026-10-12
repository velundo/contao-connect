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
    /** Neues Token; Rückgabe: Kennung (id). */
    public function insert(string $name, string $hash, int $created): int;

    /**
     * Alle Tokens, auch widerrufene.
     *
     * @return list<array{id: int, name: string, token_hash: string, created: int, last_used: int, revoked: int}>
     */
    public function all(): array;

    public function markUsed(int $id, int $time): void;

    /** Widerruft ein aktives Token; false, wenn es keines mit dieser Kennung gibt oder es schon widerrufen ist. */
    public function revoke(int $id, int $time): bool;
}
