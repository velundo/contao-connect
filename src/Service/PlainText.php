<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\Validate;

/**
 * Klartextfelder (Titel, Seitentitel, Überschriften, Beschreibungen) zwischen API und Datenbank.
 *
 * Contao 5 speichert Eingaben aus dem Backend kodiert (Input::encodeInput()): Felder ohne decodeEntities mit
 * `#<>()\="'` als numerische Entitäten (Modus encodeAll), Felder mit decodeEntities (z. B. description) nur `<`
 * (encodeLessThanSign); `&` bleibt. Basis-Entitäten ([nbsp], [-], [&] …) stehen für &nbsp;, &shy; usw.
 * Die API liest dekodiert und schreibt kodiert wie das Backend – so sieht die Ausgabe im Frontend gleich aus.
 *
 * Contao 6 speichert Klartextfelder dagegen roh und kodiert erst bei der Ausgabe (Widget::getPost() liest den Wert
 * unverändert, die Migration Version600\OutputEncodingMigration dekodiert ältere Bestände). Ab Contao 6 schreibt die API
 * deshalb roh (rawStorage) und löst beim Lesen nur die Basis-Entitäten auf. Umgeschaltet wird je Anfrage nach der
 * Hauptversion von Contao (ApiKernel).
 */
final class PlainText
{
    public const ENCODE_ALL = 'all';
    public const ENCODE_LT = 'lt';

    private const BASIC_ENTITIES = ['[&]' => '&amp;', '[&amp;]' => '&amp;', '[lt]' => '&lt;', '[gt]' => '&gt;', '[nbsp]' => '&nbsp;', '[-]' => '&shy;'];
    private const BASIC_CHARS = ['[&]' => '&', '[&amp;]' => '&', '[lt]' => '<', '[gt]' => '>', '[nbsp]' => "\u{00A0}", '[-]' => "\u{00AD}"];

    /** Speicherform: false = kodiert wie Contao 5, true = roh wie Contao 6. */
    private static bool $rawStorage = false;

    /** Speicherform nach der Hauptversion von Contao wählen („6.0.3“ → roh). */
    public static function useStorageOf(string $contaoVersion): void
    {
        self::$rawStorage = preg_match('/^(\d+)\./', $contaoVersion, $m) === 1 && (int)$m[1] >= 6;
    }

    public static function rawStorage(): bool
    {
        return self::$rawStorage;
    }

    /** Gespeicherter Wert → Klartext für die API. */
    public static function decode(?string $stored): string
    {
        if (self::$rawStorage) {
            return strtr((string)$stored, self::BASIC_CHARS);
        }
        $value = strtr((string)$stored, self::BASIC_ENTITIES);
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Klartext → Speicherform wie das Backend (Contao 5: Input::encodeInput(), Contao 6: roh). */
    public static function encode(string $text, string $mode = self::ENCODE_ALL): string
    {
        $text = (string)preg_replace('(\r\n?)', "\n", $text);
        if (self::$rawStorage) {
            return str_replace("\0", "\u{FFFD}", $text);
        }
        $text = $mode === self::ENCODE_LT
            ? str_replace('<', '&#60;', $text)
            : str_replace(['#', '<', '>', '(', ')', '\\', '=', '"', "'"], ['&#35;', '&#60;', '&#62;', '&#40;', '&#41;', '&#92;', '&#61;', '&#34;', '&#39;'], $text);
        return str_replace('\0', '&#92;0', $text);
    }

    /**
     * Klartext aus dem Rumpf: Typ, Länge, keine Steuerzeichen (außer Zeilenumbruch und Tab bei $multiline), keine
     * Insert-Tags. Rückgabe getrimmt oder null, wenn das Feld fehlt.
     *
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body, string $key, int $maxLength, bool $required = false, bool $multiline = false): ?string
    {
        $value = Validate::string($body, $key, $maxLength);
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        $control = $multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
        if (preg_match($control, $value) === 1 || preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $value) === 1) {
            throw ApiException::validation(sprintf('Feld „%s“ enthält Steuerzeichen.', $key), ['field' => $key], 'invalid_value');
        }
        if (str_contains($value, '{{')) {
            throw ApiException::validation(sprintf('Feld „%s“ darf keine Insert-Tags ({{…}}) enthalten.', $key), ['field' => $key], 'insert_tag_not_allowed');
        }
        if ($required && $value === '') {
            throw ApiException::validation(sprintf('Feld „%s“ darf nicht leer sein.', $key), ['field' => $key], 'empty_value');
        }
        return $value;
    }

    /** Klartext aus HTML: Tags entfernen, Entitäten auflösen, Leerraum zusammenfassen, auf Länge kürzen. */
    public static function fromHtml(?string $html, int $maxLength = 2000): string
    {
        $html = mb_convert_encoding(strtr((string)$html, self::BASIC_ENTITIES), 'UTF-8', 'UTF-8');
        $text = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $text = (string)preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th)\b[^>]*>#i', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)preg_replace('/[\s\x{00A0}\x{00AD}]+/u', ' ', $text));
        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
        }
        return $text;
    }

    /** Gespeichertes HTML für die API: Basis-Entitäten als echte Entitäten. */
    public static function html(?string $stored): string
    {
        return strtr((string)$stored, self::BASIC_ENTITIES);
    }
}
