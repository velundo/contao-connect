<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Velundo\ContaoConnect\Service\RateLimiter;
use Velundo\ContaoConnect\Service\Settings;
use Velundo\ContaoConnect\Service\Store\ArrayRateStore;
use Velundo\ContaoConnect\Service\Store\DbalRateStore;
use Velundo\ContaoConnect\Tests\Support\TestSite;

final class RateLimiterTest extends TestCase
{
    private int $now = 1_800_000_010;
    private RateLimiter $limiter;
    private ArrayRateStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayRateStore();
        $this->limiter = new RateLimiter($this->store, new Settings(['rate_limit_per_minute' => 3]), fn(): int => $this->now);
    }

    public function testLimitWithinWindow(): void
    {
        self::assertTrue($this->limiter->hit(1)['allowed']);
        self::assertTrue($this->limiter->hit(1)['allowed']);
        $third = $this->limiter->hit(1);
        self::assertTrue($third['allowed']);
        self::assertSame(0, $third['remaining']);
        $fourth = $this->limiter->hit(1);
        self::assertFalse($fourth['allowed']);
        self::assertSame(3, $fourth['limit']);
        self::assertGreaterThan(0, $fourth['retry_after']);
        self::assertLessThanOrEqual(60, $fourth['retry_after']);
        self::assertTrue($this->limiter->hit(2)['allowed'], 'Zweites Token hat ein eigenes Kontingent');
    }

    public function testWindowResetsAndOldWindowsArePruned(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->limiter->hit(1);
        }
        self::assertFalse($this->limiter->hit(1)['allowed']);
        $this->now += 60;
        self::assertTrue($this->limiter->hit(1)['allowed']);
        $this->now += 600;
        $this->limiter->hit(1);
        self::assertCount(1, $this->store->counts, 'alte Fenster entfernt');
    }

    public function testRetryAfterPointsToNextWindow(): void
    {
        self::assertSame(50, $this->limiter->hit(1)['retry_after']);
    }

    public function testDefaultLimitIs120(): void
    {
        $limiter = new RateLimiter(new ArrayRateStore(), new Settings([]), fn(): int => $this->now);
        self::assertSame(120, $limiter->hit(1)['limit']);
    }

    public function testDbalStoreCountsAtomicallyPerWindow(): void
    {
        $connection = new Connection();
        TestSite::schema($connection->pdo);
        $store = new DbalRateStore($connection);
        self::assertSame(1, $store->increment(7, 100));
        self::assertSame(2, $store->increment(7, 100));
        self::assertSame(1, $store->increment(7, 101));
        self::assertSame(1, $store->increment(8, 100));
        self::assertStringContainsString('hits = hits + 1', implode("\n", $connection->statements));
        $store->prune(101);
        self::assertSame('1', (string)$connection->fetchOne('SELECT COUNT(*) FROM tl_velundo_rate'));
        // Zeile zeitgleich angelegt: INSERT scheitert am eindeutigen Schlüssel, dann wird gezählt
        $connection->insert('tl_velundo_rate', ['token_id' => 9, 'window_start' => 5, 'hits' => 4]);
        self::assertSame(5, $store->increment(9, 5));
    }
}
