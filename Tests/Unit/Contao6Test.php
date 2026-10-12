<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\ContaoConnect\Service\Contao\FosCacheInvalidator;
use Velundo\ContaoConnect\Service\PlainText;
use Velundo\ContaoConnect\Tests\Support\MemoryLogger;
use Velundo\ContaoConnect\Tests\Support\TestSite;

/**
 * Unterschiede von Contao 6: Klartextfelder roh gespeichert (Ausgabe-Escaping), Cache-Tags über den Cache-Tag-Manager.
 */
final class Contao6Test extends TestCase
{
    protected function setUp(): void
    {
        PlainText::useStorageOf('5.3.12');
    }

    public function testStorageModeFollowsTheMajorVersion(): void
    {
        PlainText::useStorageOf('6.0.3');
        self::assertTrue(PlainText::rawStorage());
        self::assertSame('Über uns (Team) <b> & "x"', PlainText::encode('Über uns (Team) <b> & "x"'));
        self::assertSame('a &#40;b&#41;', PlainText::decode('a &#40;b&#41;'), 'Contao 6 dekodiert keine Entitäten mehr – roh bleibt roh');
        self::assertSame("A\u{00A0}B", PlainText::decode('A[nbsp]B'));
        PlainText::useStorageOf('5.7.1');
        self::assertFalse(PlainText::rawStorage());
        self::assertSame('Über uns &#40;Team&#41;', PlainText::encode('Über uns (Team)'));
        PlainText::useStorageOf('');
        self::assertFalse(PlainText::rawStorage());
    }

    public function testWritesRawTextOnContao6(): void
    {
        $site = new TestSite([], true, true, '6.0.3');
        $site->connection->executeStatement("UPDATE tl_page SET title = 'Leistungen (alle)' WHERE id = 3");
        $r = $site->call('PATCH', 'v1/pages/3', ['title' => 'Leistungen (neu) & mehr', 'description' => 'A <b> B']);
        self::assertSame(200, $r['status']);
        self::assertSame('Leistungen (alle)', $r['data']['before']['title']);
        $row = $site->row('SELECT title, description FROM tl_page WHERE id = 3');
        self::assertSame('Leistungen (neu) & mehr', $row['title'], 'roh gespeichert');
        self::assertSame('A <b> B', $row['description']);
        $c = $site->call('POST', 'v1/content', ['article' => 10, 'headline' => 'Neu (Entwurf)', 'text' => '<p>x</p>']);
        self::assertSame(serialize(['value' => 'Neu (Entwurf)', 'unit' => 'h2']), $site->row('SELECT headline FROM tl_content WHERE id = ?', [$c['data']['id']])['headline']);
        self::assertSame('5.3.12', (new TestSite())->call('GET', 'v1/info')['data']['contao']);
        self::assertFalse(PlainText::rawStorage(), 'die nächste Anfrage an Contao 5 schaltet zurück');
    }

    public function testCacheTagManagerIsPreferred(): void
    {
        $fos = new class {
            public array $tags = [];

            public function invalidateTags(array $tags): void
            {
                $this->tags = $tags;
            }
        };
        $tagManager = new class {
            public array $tags = [];

            public function invalidateTags(array $tags): self
            {
                $this->tags = $tags;
                return $this;
            }
        };
        $logger = new MemoryLogger();
        $both = new FosCacheInvalidator($fos, $logger, $tagManager);
        self::assertTrue($both->invalidate(['contao.db.tl_page.1', 'contao.db.tl_page.1', '']));
        self::assertSame(['contao.db.tl_page.1'], $tagManager->tags);
        self::assertSame([], $fos->tags, 'der Tag-Manager reicht an den Invalidator weiter');
        $only5 = new FosCacheInvalidator($fos, $logger);
        self::assertTrue($only5->invalidate(['contao.db.tl_news.2']));
        self::assertSame(['contao.db.tl_news.2'], $fos->tags);
        $none = new FosCacheInvalidator(null, $logger, $tagManager);
        self::assertFalse($none->available());
        self::assertFalse($none->invalidate(['contao.db.tl_page.2']), 'Ereignis ausgelöst, aber kein HTTP-Cache');
        self::assertSame(['contao.db.tl_page.2'], $tagManager->tags);
        self::assertFalse((new FosCacheInvalidator(null, $logger))->invalidate(['x']));
    }
}
