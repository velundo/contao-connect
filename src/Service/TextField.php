<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Velundo\ContaoConnect\Http\ApiException;

/**
 * HTML-Feld aus einem JSON-Rumpf (text, teaser): Typ und Größe prüfen (max_text_kb), Insert-Tags prüfen, bereinigen.
 */
final class TextField
{
    public function __construct(
        private readonly Settings $settings,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * Bereinigtes HTML oder null, wenn das Feld fehlt.
     *
     * @param array<string, mixed> $body
     */
    public function fromBody(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_string($body[$key])) {
            throw ApiException::validation(sprintf('Feld „%s“ muss eine Zeichenkette (HTML) sein.', $key), ['field' => $key], 'invalid_type');
        }
        if (!mb_check_encoding($body[$key], 'UTF-8')) {
            throw ApiException::validation(sprintf('Feld „%s“ ist kein gültiges UTF-8.', $key), ['field' => $key], 'invalid_value');
        }
        $maxKb = $this->settings->maxTextKb();
        if (strlen($body[$key]) > $maxKb * 1024) {
            throw ApiException::validation(sprintf('Feld „%s“ ist größer als %d KB.', $key, $maxKb), ['field' => $key, 'max_kb' => $maxKb], 'text_too_large');
        }
        return trim($this->sanitizer->sanitize($body[$key], $key));
    }
}
