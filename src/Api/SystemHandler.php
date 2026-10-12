<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Service\Contao\SystemInfoInterface;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\Settings;

/**
 * GET system – Versionen, Bundles des Kernels, Contao-/Symfony-Pakete und der letzte Cron-Lauf (keine Pfade, keine
 * Geheimnisse, keine Konfigurationswerte).
 */
final class SystemHandler implements HandlerInterface
{
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
            'bundles' => $this->system->bundles(),
            'packages' => $this->system->packages(),
            'cron_last_run' => $lastCron,
        ]);
    }
}
