<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Velundo\ContaoConnect\Api\ApiKernel;
use Velundo\ContaoConnect\Service\Store\ArrayTokenStore;
use Velundo\ContaoConnect\Service\Store\DbalTokenStore;
use Velundo\ContaoConnect\Service\TokenService;
use Velundo\ContaoConnect\Tests\Support\TestSite;

final class TokenServiceTest extends TestCase
{
    private ArrayTokenStore $store;
    private TokenService $service;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->store = new ArrayTokenStore();
        $this->service = new TokenService($this->store, fn(): int => $this->now);
    }

    public function testCreateReturnsBase64UrlTokenAndStoresOnlyHash(): void
    {
        $created = $this->service->create('Agentur Test');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $created['token']);
        self::assertSame(1, $created['id']);
        self::assertSame(hash('sha256', $created['token']), $this->store->rows[1]['token_hash']);
        self::assertStringNotContainsString($created['token'], (string)json_encode($this->store->rows));
    }

    public function testAuthenticateAcceptsValidBearerAndRejectsOthers(): void
    {
        $created = $this->service->create('Agentur Test');
        self::assertSame(['id' => 1, 'name' => 'Agentur Test'], $this->service->authenticate('Bearer ' . $created['token']));
        self::assertNull($this->service->authenticate(null));
        self::assertNull($this->service->authenticate(''));
        self::assertNull($this->service->authenticate('Bearer ' . TokenService::encode(random_bytes(32))));
        self::assertNull($this->service->authenticate('Basic ' . $created['token']));
        self::assertNull($this->service->authenticate($created['token']));
    }

    public function testRevokeDisablesToken(): void
    {
        $created = $this->service->create('Agentur Test');
        self::assertTrue($this->service->revoke($created['id']));
        self::assertFalse($this->service->revoke($created['id']), 'Zweiter Widerruf schlägt fehl');
        self::assertFalse($this->service->revoke(99));
        self::assertFalse($this->service->revoke(0));
        self::assertNull($this->service->authenticate('Bearer ' . $created['token']));
        self::assertSame($this->now, $this->service->list()[0]['revoked']);
    }

    public function testListHidesHashes(): void
    {
        $this->service->create('Eins');
        $this->service->create('Zwei');
        $list = $this->service->list();
        self::assertCount(2, $list);
        foreach ($list as $entry) {
            self::assertFalse(array_key_exists('token_hash', $entry));
            self::assertNull($entry['last_used']);
        }
    }

    public function testLastUsedIsUpdatedAtMostOncePerMinute(): void
    {
        $created = $this->service->create('Agentur Test');
        $this->service->authenticate('Bearer ' . $created['token']);
        self::assertSame($this->now, $this->service->list()[0]['last_used']);
        $this->now += 30;
        $this->service->authenticate('Bearer ' . $created['token']);
        self::assertSame($this->now - 30, $this->service->list()[0]['last_used']);
        $this->now += 31;
        $this->service->authenticate('Bearer ' . $created['token']);
        self::assertSame($this->now, $this->service->list()[0]['last_used']);
    }

    public function testNameIsCheckedAndCleaned(): void
    {
        self::assertSame('Agentur Test', $this->service->create("Agentur\nTest")['name'], 'Steuerzeichen werden zu Leerzeichen');
        try {
            $this->service->create('   ');
            self::fail('leere Bezeichnung');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create(str_repeat('x', 101));
    }

    public function testExtractBearerIsStrict(): void
    {
        self::assertSame('abcdefghijklmnopqrstuvwxyz', TokenService::extractBearer('Bearer abcdefghijklmnopqrstuvwxyz'));
        self::assertSame('abcdefghijklmnopqrstuvwxyz', TokenService::extractBearer('  Bearer   abcdefghijklmnopqrstuvwxyz  '));
        self::assertNull(TokenService::extractBearer('Bearer kurz'));
        self::assertNull(TokenService::extractBearer('Bearer abc def ghijklmnopqrstuvwxyz'));
        self::assertNull(TokenService::extractBearer('Bearer abcdefghijklmnopqrstuvwxyz=='));
    }

    public function testAuthorizationHeaderFallsBackToVelundoToken(): void
    {
        self::assertSame('Bearer abc', ApiKernel::authorizationHeader('Bearer abc', 'xyz'));
        self::assertSame('Bearer xyz', ApiKernel::authorizationHeader(null, ' xyz '));
        self::assertSame('Bearer xyz', ApiKernel::authorizationHeader('', 'xyz'));
        self::assertNull(ApiKernel::authorizationHeader(null, ''));
    }

    public function testDbalStoreWritesOnlyItsColumns(): void
    {
        $connection = new Connection();
        TestSite::schema($connection->pdo);
        $store = new DbalTokenStore($connection);
        $cli = new TokenService($store, fn(): int => $this->now);
        $a = $cli->create('Agentur Test');
        $b = $cli->create('Zweites Token');
        self::assertTrue($cli->revoke($a['id']));
        $this->now += 120;
        // Eine Anfrage vermerkt „zuletzt verwendet“ – der Widerruf bleibt, das Token wird abgelehnt
        self::assertNull($cli->authenticate('Bearer ' . $a['token']));
        self::assertSame(['id' => $b['id'], 'name' => 'Zweites Token'], $cli->authenticate('Bearer ' . $b['token']));
        $rows = $cli->list();
        self::assertSame($this->now - 120, $rows[0]['revoked']);
        self::assertSame($this->now, $rows[1]['last_used']);
        $store->markUsed($a['id'], $this->now);
        self::assertSame($this->now - 120, $cli->list()[0]['revoked'], 'markUsed überschreibt keinen Widerruf');
    }
}
