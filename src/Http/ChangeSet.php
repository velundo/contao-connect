<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Http;

/**
 * Sammelt bei PATCH die zu schreibenden Spalten und die vorherigen Werte (`before`, API-Namen und -Darstellung).
 * Felder ohne Wert (null) werden übergangen.
 */
final class ChangeSet
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $before = [];

    /** @var list<string> */
    private array $fields = [];

    /**
     * Nimmt das Feld auf, wenn $value nicht null ist. $dbValue: Wert für die Spalte (Standard $value),
     * $dbField: Spaltenname, wenn er vom API-Feld abweicht.
     */
    public function set(string $apiField, mixed $value, mixed $before, mixed $dbValue = null, ?string $dbField = null): void
    {
        if ($value === null) {
            return;
        }
        $this->data[$dbField ?? $apiField] = $dbValue ?? $value;
        $this->before[$apiField] = $before;
        $this->fields[] = $apiField;
    }

    /** Wie set() für Schalter: true/false wird als 1/0 geschrieben. */
    public function flag(string $apiField, ?bool $value, bool $before, ?string $dbField = null): void
    {
        if ($value === null) {
            return;
        }
        $this->set($apiField, $value, $before, $value ? 1 : 0, $dbField);
    }

    /** Vermerkt ein Feld, das nicht in dieser Tabelle steht (z. B. Text einer Nachricht im Inhaltselement). */
    public function external(string $apiField, mixed $before): void
    {
        $this->before[$apiField] = $before;
        $this->fields[] = $apiField;
    }

    /** 422 `empty_body`, wenn kein Feld einen Wert enthält. @param list<string> $allowed */
    public function requireAny(array $allowed): void
    {
        if ($this->fields === []) {
            throw ApiException::validation('Keines der Felder enthält einen Wert.', ['allowed' => $allowed], 'empty_body');
        }
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function before(): array
    {
        return $this->before;
    }

    /** Geänderte API-Felder für das Protokoll, z. B. „title, published“. */
    public function fieldList(): string
    {
        return implode(', ', array_values(array_unique($this->fields)));
    }
}
