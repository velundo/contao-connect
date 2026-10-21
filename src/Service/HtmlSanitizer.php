<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Service\Contao\HtmlWhitelistInterface;

/**
 * Bereinigt HTML von außen (text, teaser): erst eine eigene Grobreinigung (gefährliche Elemente samt Inhalt,
 * Ereignis-Attribute, javascript:-/vbscript:-/data:text/html-Adressen), dann – falls vorhanden – die Positivliste von
 * Contao (Input::stripTags mit den erlaubten Tags und Attributen der Einstellungen, wie beim Speichern im Backend).
 * Insert-Tags sind nur als Verweise erlaubt (INSERT_TAGS), alle anderen → 422.
 */
final class HtmlSanitizer
{
    private const FORBIDDEN_ELEMENTS = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'meta', 'link', 'base', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'template', 'noscript'];

    /** Erlaubte Insert-Tags: Verweise auf Seiten, Artikel, Nachrichten, Termine, FAQ und E-Mail-Adressen. */
    public const INSERT_TAGS = ['link', 'link_open', 'link_url', 'link_title', 'link_close', 'news', 'news_open', 'news_url', 'news_title', 'article', 'article_open',
        'article_url', 'article_title', 'event', 'event_open', 'event_url', 'event_title', 'faq', 'faq_open', 'faq_url', 'faq_title', 'email', 'email_open', 'email_url', 'br'];

    public function __construct(private readonly ?HtmlWhitelistInterface $whitelist = null) {}

    public function sanitize(string $html, string $field = 'text'): string
    {
        self::assertInsertTags($html, $field);
        $html = $this->preClean($html);
        // Erneut nach jeder Bereinigung: Das Entfernen eines Kommentars oder eines nicht erlaubten Tags kann erst das
        // Insert-Tag zusammensetzen („{<!-- -->{insert_module::3}}“ → „{{insert_module::3}}“) – geprüft wird deshalb
        // immer das, was gespeichert würde
        self::assertInsertTags($html, $field);
        if ($this->whitelist !== null) {
            $html = $this->whitelist->clean($html);
            self::assertInsertTags($html, $field);
        }
        return $html;
    }

    /** 422 `insert_tag_not_allowed`, wenn ein Insert-Tag nicht in der Positivliste steht. */
    public static function assertInsertTags(string $html, string $field): void
    {
        if (!str_contains($html, '{{')) {
            return;
        }
        $names = implode('|', self::INSERT_TAGS);
        // erlaubte Tags herausnehmen; bleibt danach „{{“ übrig, ist etwas anderes enthalten
        $rest = (string)preg_replace('/\{\{(?:' . $names . ')(?:::[A-Za-z0-9._@+\-]{1,128})?(?:\|[a-z_]{1,32})*\}\}/', '', $html);
        if (str_contains($rest, '{{')) {
            throw ApiException::validation(
                sprintf('Feld „%s“ enthält ein nicht erlaubtes Insert-Tag – erlaubt sind nur Verweise ({{link_url::<id>}} usw.).', $field),
                ['field' => $field, 'allowed' => self::INSERT_TAGS],
                'insert_tag_not_allowed'
            );
        }
    }

    /** Grobreinigung ohne Abhängigkeiten – auch in Prüfungen ohne Contao lauffähig. */
    public function preClean(string $html): string
    {
        $html = str_replace("\0", '', $html);
        // HTML-Kommentare entfernen (bedingte Kommentare können Markup verstecken)
        $html = (string)preg_replace('#<!--.*?-->#s', '', $html);
        foreach (self::FORBIDDEN_ELEMENTS as $element) {
            $html = (string)preg_replace('#<' . $element . '\b[^>]*>.*?</' . $element . '\s*>#is', '', $html);
            $html = (string)preg_replace('#<' . $element . '\b[^>]*/?>#i', '', $html);
            $html = (string)preg_replace('#</' . $element . '\s*>#i', '', $html);
        }
        // Ein angefangenes Tag ohne schließendes „>“ („<img src=x onerror=…“ am Textende) wäre für die Attributbereinigung
        // unsichtbar – als Text entwerten
        $html = (string)preg_replace('#<(?=[a-z/!?])(?![^<>]*>)#i', '&lt;', $html);
        // Attribute nur innerhalb von Tags bereinigen – Fließtext wie „online=Version“ bleibt unangetastet
        return (string)preg_replace_callback('#<[a-z][^>]*>#i', static fn(array $m): string => self::cleanTag($m[0]), $html);
    }

    /**
     * Entfernt aus einem einzelnen Start-Tag Ereignis-Attribute, style mit Ausdrücken und Skript-Adressen. Als Trenner
     * vor einem Attribut gilt auch „/“ („<img/onerror=…>“ liest der Browser wie „<img onerror=…>“).
     */
    private static function cleanTag(string $tag): string
    {
        $tag = (string)preg_replace('#[\s/]+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $tag);
        $tag = (string)preg_replace('#[\s/]+style\s*=\s*("[^"]*(expression|javascript|url\s*\()[^"]*"|\'[^\']*(expression|javascript|url\s*\()[^\']*\')#i', '', $tag);
        return (string)preg_replace_callback(
            '#[\s/](href|src|action|formaction|xlink:href|data|poster|background)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i',
            static function (array $m): string {
                $value = $m[3] !== '' ? $m[3] : ($m[4] !== '' ? $m[4] : ($m[5] ?? ''));
                $probe = strtolower((string)preg_replace('/[\s\x00-\x1f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));
                if (str_starts_with($probe, 'javascript:') || str_starts_with($probe, 'vbscript:') || str_starts_with($probe, 'data:text/html') || str_starts_with($probe, 'data:image/svg')) {
                    return '';
                }
                return $m[0];
            },
            $tag
        );
    }
}
