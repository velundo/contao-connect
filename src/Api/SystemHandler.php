<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Service\Contao\SystemInfoInterface;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\Settings;

/**
 * GET system – Versionen, die für die App relevanten Bundles und Pakete (Contao und velundo) und der letzte Cron-Lauf
 * (keine Pfade, keine Geheimnisse, keine Konfigurationswerte, keine Inventarliste aller Erweiterungen).
 */
final class SystemHandler implements HandlerInterface
{
    /** Präfixe der Bundles (Kurznamen) und Pakete, die GET system nennt (ab 1.1.0). */
    private const BUNDLE_PREFIXES = ['Contao', 'Velundo'];
    private const PACKAGE_PREFIXES = ['contao/', 'velundo/'];

    public function __construct(
        private readonly Db $db,
        private readonly SystemInfoInterface $system,
        private readonly Settings $settings,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $lastCron = null;
        try {
            $lastCron = Db::iso($this->db->value('SELECT MAX(lastRun) FROM tl_cron_job'));
        } catch (\Throwable) {
            // Tabelle fehlt (ältere Stände) – dann ohne Angabe
        }
        return Result::ok([
            'contao' => $this->system->contaoVersion(),
            'php' => PHP_VERSION,
            'environment' => $this->settings->environment(),
            'bundles' => array_values(array_filter($this->system->bundles(), static fn(string $name): bool => self::startsWithAny($name, self::BUNDLE_PREFIXES))),
            'packages' => array_values(array_filter($this->system->packages(), static fn(array $p): bool => self::startsWithAny((string)$p['name'], self::PACKAGE_PREFIXES))),
            'cron_last_run' => $lastCron,
        ]);
    }

    /** @param list<string> $prefixes */
    public static function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
