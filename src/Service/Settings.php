<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

/**
 * Einstellungen des Bundles (config/config.yaml, Schlüssel velundo_contao_connect) mit Standardwerten.
 */
class Settings
{
    public const MAX_TEXT_KB_DEFAULT = 200;
    public const RATE_LIMIT_DEFAULT = 120;

    /**
     * @param array<string, mixed> $values Werte aus der Bundle-Konfiguration (fehlende → Standard)
     */
    public function __construct(
        private readonly array $values = [],
        private readonly string $environment = 'prod',
    ) {}

    public function maxTextKb(): int
    {
        return max(1, (int)($this->values['max_text_kb'] ?? self::MAX_TEXT_KB_DEFAULT));
    }

    public function rateLimitPerMinute(): int
    {
        return max(1, (int)($this->values['rate_limit_per_minute'] ?? self::RATE_LIMIT_DEFAULT));
    }

    public function requireHttps(): bool
    {
        return (bool)($this->values['require_https'] ?? true);
    }

    /** Umgebung des Kernels (APP_ENV); in „dev“ ist http immer erlaubt. */
    public function environment(): string
    {
        return $this->environment;
    }

    public function isDevelopment(): bool
    {
        return $this->environment === 'dev';
    }
}
