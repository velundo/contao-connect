<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service\Contao;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Versions;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Versionen über Contao\Versions – wie DC_Table: vor der Änderung initialize() (Ausgangsversion, falls noch keine),
 * danach create(). Benutzername „velundo“ (es gibt keinen Backend-Benutzer), Bearbeiten-Adresse ausdrücklich gesetzt
 * (sonst läse Versions sie aus der Anfrage). Schlägt das Anlegen fehl, bleibt die Änderung bestehen; der Fehler steht
 * im Protokoll (Warnung) – eine fehlende Version soll keine schon geschriebene Änderung verdecken.
 */
final class ContaoVersionRecorder implements VersionRecorderInterface
{
    public const USERNAME = 'velundo';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger,
    ) {}

    public function initialize(string $table, int $id, string $module): void
    {
        $this->versions($table, $id, $module)?->initialize();
    }

    public function create(string $table, int $id, string $module): void
    {
        $this->versions($table, $id, $module)?->create();
    }

    private function versions(string $table, int $id, string $module): ?Versions
    {
        try {
            $this->framework->initialize();
            /** @var Versions $versions */
            $versions = $this->framework->createInstance(Versions::class, [$table, $id]);
            $versions->setUsername(self::USERNAME);
            $versions->setUserId(0);
            $versions->setEditUrl($this->editUrl($table, $id, $module));
            return $versions;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('velundo: Version für %s.%d nicht angelegt (%s)', $table, $id, $e->getMessage()));
            return null;
        }
    }

    /** Bearbeiten-Adresse im Backend, z. B. /contao?do=article&table=tl_content&act=edit&id=12. */
    private function editUrl(string $table, int $id, string $module): string
    {
        $params = ['do' => $module] + ($table === 'tl_page' ? [] : ['table' => $table]) + ['act' => 'edit', 'id' => $id];
        try {
            return $this->router->generate('contao_backend', $params);
        } catch (\Throwable) {
            return '/contao?' . http_build_query($params);
        }
    }
}
