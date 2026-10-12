<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Log\LoggerInterface;

/**
 * Protokolliert in tl_log (Logger-Kanal contao.general mit ContaoContext, Kategorie GENERAL, Benutzer „velundo“):
 * Token-Bezeichnung, Aktion, Tabelle und ID – keine Inhalte.
 */
final class LogService
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function log(string $message, string $tokenName, string $table = '', int $id = 0): void
    {
        $text = 'velundo: ' . self::clean($message) . ($table !== '' ? sprintf(' [%s.%d]', $table, $id) : '') . ' – Token „' . self::clean($tokenName) . '“';
        $context = [];
        if (class_exists(ContaoContext::class)) {
            $context['contao'] = new ContaoContext(__METHOD__, ContaoContext::GENERAL, 'velundo');
        }
        try {
            $this->logger->info($text, $context);
        } catch (\Throwable) {
            // Das Protokoll darf keine Anfrage scheitern lassen
        }
    }

    public static function clean(string $text): string
    {
        $text = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));
        return mb_substr($text, 0, 200);
    }
}
