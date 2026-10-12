<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\PlainText;

/**
 * Gemeinsame Umwandlungen zwischen Datenbankzeilen und API-Darstellung.
 */
final class Format
{
    public const HEADLINE_UNITS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * Überschrift eines Inhaltselements (inputUnit, serialisiert {value, unit}): [Speicherwert, Einheit].
     *
     * @return array{0: string, 1: string}
     */
    public static function headlineParts(mixed $raw): array
    {
        $raw = (string)$raw;
        if (str_starts_with($raw, 'a:')) {
            $data = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($data)) {
                $unit = (string)($data['unit'] ?? 'h2');
                return [(string)($data['value'] ?? ''), in_array($unit, self::HEADLINE_UNITS, true) ? $unit : 'h2'];
            }
        }
        return [$raw, 'h2'];
    }

    /** Speicherform einer Überschrift wie das Backend (Schlüsselreihenfolge value, unit). */
    public static function headlineValue(string $storedValue, string $unit): string
    {
        return serialize(['value' => $storedValue, 'unit' => $unit]);
    }

    /** Sichtbarkeitsfilter auf einen Schalter: $visible = veröffentlicht bzw. nicht unsichtbar. */
    public static function matches(bool $visible, string $mode): bool
    {
        return match ($mode) {
            ApiRequest::VISIBILITY_VISIBLE => $visible,
            ApiRequest::VISIBILITY_HIDDEN => !$visible,
            default => true,
        };
    }

    /** SQL-Bedingung für den Sichtbarkeitsfilter auf eine Spalte ('' bei all). */
    public static function visibilitySql(string $column, string $mode, bool $inverted = false): string
    {
        if ($mode === ApiRequest::VISIBILITY_ALL) {
            return '';
        }
        $wantSet = ($mode === ApiRequest::VISIBILITY_VISIBLE) !== $inverted;
        return $wantSet ? " AND $column = 1" : " AND ($column = 0 OR $column = '' OR $column IS NULL)";
    }

    /**
     * Inhaltselement in Listenform.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function content(array $row): array
    {
        [$headline, $unit] = self::headlineParts($row['headline'] ?? '');
        $text = (string)($row['text'] ?? '');
        return [
            'id' => (int)$row['id'],
            'pid' => (int)$row['pid'],
            'ptable' => (string)($row['ptable'] ?? '') !== '' ? (string)$row['ptable'] : 'tl_article',
            'type' => (string)$row['type'],
            'headline' => PlainText::decode($headline),
            'headline_unit' => $unit,
            'text_plain' => PlainText::fromHtml($text, 2000),
            'text_length' => strlen($text),
            'invisible' => Db::flag($row['invisible'] ?? 0),
            'start' => Db::iso($row['start'] ?? ''),
            'stop' => Db::iso($row['stop'] ?? ''),
            'sorting' => (int)$row['sorting'],
            'tstamp' => Db::iso($row['tstamp'] ?? 0),
        ];
    }
}
