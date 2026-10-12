<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Service\Contao\SystemInfoInterface;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\Features;
use Velundo\ContaoConnect\Service\PlainText;
use Velundo\ContaoConnect\Service\Settings;

/**
 * GET info – Versionen, Websites (Startpunkte), verfügbare Bereiche und Grenzen.
 */
final class InfoHandler implements HandlerInterface
{
    // Muss composer.json (extra.velundo-version) und CHANGELOG.md entsprechen (Prüfung VersionTest)
    public const BUNDLE_VERSION = '1.0.1';

    public function __construct(
        private readonly Db $db,
        private readonly SystemInfoInterface $system,
        private readonly Features $features,
        private readonly Settings $settings,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $roots = [];
        foreach ($this->db->all("SELECT id, title, dns, language, fallback, published, maintenanceMode, useSSL, urlPrefix FROM tl_page WHERE type = 'root' ORDER BY sorting, id") as $r) {
            $host = (string)$r['dns'] !== '' ? (string)$r['dns'] : $request->host;
            $prefix = trim((string)$r['urlPrefix'], '/');
            $roots[] = [
                'id' => (int)$r['id'],
                'title' => PlainText::decode((string)$r['title']),
                'dns' => (string)$r['dns'],
                'language' => (string)$r['language'],
                'fallback' => Db::flag($r['fallback']),
                'published' => Db::flag($r['published']),
                'maintenance' => Db::flag($r['maintenanceMode']),
                'base' => $host === '' ? null : (Db::flag($r['useSSL']) ? 'https' : 'http') . '://' . $host . '/' . ($prefix !== '' ? $prefix . '/' : ''),
            ];
        }
        return Result::ok([
            'contao' => $this->system->contaoVersion(),
            'php' => PHP_VERSION,
            'bundle' => self::BUNDLE_VERSION,
            'roots' => $roots,
            'features' => $this->features->all(),
            'limits' => [
                'max_text_kb' => $this->settings->maxTextKb(),
                'rate_limit_per_minute' => $this->settings->rateLimitPerMinute(),
                'limit_max' => ApiRequest::LIMIT_MAX,
            ],
        ]);
    }
}
