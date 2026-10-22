<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\ContaoConnect\Api\InfoHandler;
use Velundo\ContaoConnect\Service\Sorting;

final class VersionTest extends TestCase
{
    /** GET info meldet die Konstante – sie muss zu composer.json und CHANGELOG passen. */
    public function testInfoVersionMatchesComposerAndChangelog(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true);
        self::assertSame($composer['extra']['velundo-version'], InfoHandler::BUNDLE_VERSION);
        self::assertSame('velundo/contao-connect', $composer['name']);
        self::assertSame('contao-bundle', $composer['type']);
        self::assertStringContainsString('## ' . InfoHandler::BUNDLE_VERSION . ' ', (string)file_get_contents($root . '/CHANGELOG.md'));
    }

    public function testDcaFilesDefineTheTables(): void
    {
        $root = dirname(__DIR__, 2);
        if (!class_exists('Contao\\DC_Table')) {
            eval('namespace Contao; class DC_Table {}');
        }
        foreach (['tl_velundo_token' => ['token_hash', 'revoked', 'last_used', 'scopes'], 'tl_velundo_rate' => ['token_id', 'window_start', 'hits']] as $table => $fields) {
            require $root . '/contao/dca/' . $table . '.php';
            $dca = $GLOBALS['TL_DCA'][$table];
            self::assertSame('primary', $dca['config']['sql']['keys']['id']);
            foreach ($fields as $field) {
                self::assertArrayHasKey($field, $dca['fields']);
                self::assertArrayHasKey('sql', $dca['fields'][$field]);
            }
            self::assertTrue($dca['config']['closed']);
        }
        self::assertSame('unique', $GLOBALS['TL_DCA']['tl_velundo_token']['config']['sql']['keys']['token_hash']);
        self::assertSame('unique', $GLOBALS['TL_DCA']['tl_velundo_rate']['config']['sql']['keys']['token_id,window_start']);
    }

    public function testSortingLikeTheBackend(): void
    {
        self::assertSame(['sorting' => 128, 'renumber' => []], Sorting::position([], null));
        self::assertSame(['sorting' => 640, 'renumber' => []], Sorting::position([1 => 128, 2 => 512], null));
        self::assertSame(['sorting' => 320, 'renumber' => []], Sorting::position([1 => 128, 2 => 512], 1));
        self::assertSame(['sorting' => 640, 'renumber' => []], Sorting::position([1 => 128, 2 => 512], 2));
        // keine Lücke zwischen 128 und 129: neu nummerieren, neues Element direkt hinter 1
        self::assertSame(['sorting' => 256, 'renumber' => [2 => 384]], Sorting::position([1 => 128, 2 => 129, 3 => 512], 1));
    }
}
