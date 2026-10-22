<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Velundo\ContaoConnect\Api\SystemHandler;
use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Service\RateLimiter;
use Velundo\ContaoConnect\Service\Settings;
use Velundo\ContaoConnect\Service\Store\ArrayRateStore;
use Velundo\ContaoConnect\Service\Store\ArrayTokenStore;
use Velundo\ContaoConnect\Service\Store\DbalTokenStore;
use Velundo\ContaoConnect\Service\TokenService;
use Velundo\ContaoConnect\Tests\Support\TestSite;

/**
 * Zusatzrechte der Tokens (1.1.0): Ablage, Kommando-Normalisierung, 403 scope_* an den Endpunkten, Bremse vor der
 * Token-Prüfung, knapperes GET system.
 */
final class ScopeTest extends TestCase
{
    private TestSite $site;
    private string $plain;

    protected function setUp(): void
    {
        $this->site = new TestSite();
        $this->plain = $this->site->tokens->create('Nur Entwürfe')['token'];
    }

    public function testScopesAreNormalizedStoredAndReturned(): void
    {
        $service = new TokenService(new ArrayTokenStore(), static fn(): int => 1_800_000_000);
        $created = $service->create('Agentur Test', ['LIVE_EDIT', 'publish', 'publish', '']);
        self::assertSame(['publish', 'live_edit'], $created['scopes']);
        self::assertSame(['publish', 'live_edit'], $service->authenticate('Bearer ' . $created['token'])['scopes']);
        self::assertSame(['publish', 'live_edit'], $service->list()[0]['scopes']);
        $plain = $service->create('Nur Entwürfe');
        self::assertSame([], $plain['scopes']);
        self::assertSame([], $service->authenticate('Bearer ' . $plain['token'])['scopes']);
        $this->expectException(\InvalidArgumentException::class);
        $service->create('Agentur Test', ['delete']);
    }

    public function testDbalStoreWithoutScopesColumnTreatsTokensAsPlain(): void
    {
        // Stand 1.0.x: Tabelle ohne Spalte scopes (contao:migrate noch nicht ausgeführt)
        $connection = new Connection();
        $connection->pdo->exec("CREATE TABLE tl_velundo_token (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL DEFAULT '', token_hash TEXT NOT NULL DEFAULT '' UNIQUE,
            created INTEGER NOT NULL DEFAULT 0, last_used INTEGER NOT NULL DEFAULT 0, revoked INTEGER NOT NULL DEFAULT 0)");
        $service = new TokenService(new DbalTokenStore($connection));
        $created = $service->create('Alt');
        self::assertSame([], $service->authenticate('Bearer ' . $created['token'])['scopes']);
        self::assertSame([], $service->list()[0]['scopes']);
        try {
            $service->create('Neu', ['publish']);
            self::fail('ohne Spalte scopes kein Token mit Rechten');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('contao:migrate', $e->getMessage());
        }
        self::assertCount(1, $service->list(), 'kein halber Eintrag');
    }

    public function testRequireScopeThrows403WithHint(): void
    {
        $request = new ApiRequest('PATCH', [], '{}', 7, 'Agentur Test', 'www.example.de', ['publish']);
        self::assertTrue($request->can('publish'));
        self::assertFalse($request->can('live_edit'));
        $request->require('publish', 'Das Veröffentlichen'); // kein Fehler
        try {
            $request->require('live_edit', 'Das Ändern einer veröffentlichten Seite');
            self::fail('403 erwartet');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('scope_live_edit', $e->errorCode);
            self::assertStringContainsString('--allow-live-edit', $e->getMessage());
            self::assertStringContainsString('Das Ändern einer veröffentlichten Seite', $e->getMessage());
        }
    }

    public function testPagesNeedPublishAndLiveEdit(): void
    {
        // Seite 3 ist veröffentlicht: Titel ändern braucht live_edit, Metadaten nicht
        $r = $this->site->call('PATCH', 'v1/pages/3', ['title' => 'Neu'], [], $this->plain);
        self::assertSame([403, 'scope_live_edit'], [$r['status'], $r['data']['error']['code']]);
        self::assertSame(200, $this->site->call('PATCH', 'v1/pages/3', ['description' => 'Meta', 'robots' => 'noindex,follow', 'hide' => true], [], $this->plain)['status']);
        // unveröffentlichen darf jedes Token – und im selben Aufruf den Titel ändern (wird zum Entwurf)
        self::assertSame(200, $this->site->call('PATCH', 'v1/pages/3', ['published' => false, 'title' => 'Entwurf'], [], $this->plain)['status']);
        self::assertSame(200, $this->site->call('PATCH', 'v1/pages/3', ['title' => 'Entwurf 2'], [], $this->plain)['status'], 'unveröffentlichte Seite frei');
        $r = $this->site->call('PATCH', 'v1/pages/3', ['published' => true], [], $this->plain);
        self::assertSame([403, 'scope_publish'], [$r['status'], $r['data']['error']['code']]);
        self::assertSame(0, (int)$this->site->row('SELECT published FROM tl_page WHERE id = 3')['published'], 'nichts geschrieben');
        self::assertStringContainsString('PATCH /v1/pages/3 → 403 – Token „Nur Entwürfe“', $this->site->logger->text());
        // mit Rechten (Standard-Token der Prüfungen)
        self::assertSame(200, $this->site->call('PATCH', 'v1/pages/3', ['published' => true, 'title' => 'Leistungen'])['status']);
        self::assertSame(200, $this->site->call('PATCH', 'v1/pages/3', ['title' => 'Leistungen (live)'])['status']);
    }

    public function testContentNeedsPublishAndLiveEdit(): void
    {
        // Element 21 ist unsichtbar, 20 ein sichtbares Textelement (Tests/Support/TestSite.php)
        self::assertSame(200, $this->site->call('PATCH', 'v1/content/21', ['headline' => 'Entwurf'], [], $this->plain)['status']);
        $r = $this->site->call('PATCH', 'v1/content/21', ['invisible' => false], [], $this->plain);
        self::assertSame([403, 'scope_publish'], [$r['status'], $r['data']['error']['code']]);
        $r = $this->site->call('PATCH', 'v1/content/20', ['text' => '<p>Live</p>'], [], $this->plain);
        self::assertSame([403, 'scope_live_edit'], [$r['status'], $r['data']['error']['code']]);
        self::assertSame(200, $this->site->call('PATCH', 'v1/content/22', ['invisible' => true], [], $this->plain)['status'], 'verstecken bleibt frei');
        self::assertSame(200, $this->site->call('PATCH', 'v1/content/20', ['invisible' => true, 'text' => '<p>Entwurf</p>'], [], $this->plain)['status'], 'verstecken + ändern = Entwurf');
        // Anlegen bleibt frei (immer unsichtbar)
        self::assertSame(201, $this->site->call('POST', 'v1/content', ['article' => 10, 'text' => '<p>Neu</p>'], [], $this->plain)['status']);
        self::assertSame(200, $this->site->call('PATCH', 'v1/content/21', ['invisible' => false])['status']);
        self::assertSame(200, $this->site->call('PATCH', 'v1/content/21', ['text' => '<p>Live</p>'])['status']);
    }

    public function testNewsNeedsPublishAndLiveEdit(): void
    {
        // Nachricht 30 ist veröffentlicht, 31 ein Entwurf
        self::assertSame(201, $this->site->call('POST', 'v1/news', ['archive' => 1, 'headline' => 'Entwurf'], [], $this->plain)['status']);
        self::assertSame(200, $this->site->call('PATCH', 'v1/news/31', ['headline' => 'Entwurf 2', 'text' => '<p>x</p>'], [], $this->plain)['status']);
        $r = $this->site->call('PATCH', 'v1/news/31', ['published' => true], [], $this->plain);
        self::assertSame([403, 'scope_publish'], [$r['status'], $r['data']['error']['code']]);
        $r = $this->site->call('PATCH', 'v1/news/30', ['teaser' => '<p>Live</p>'], [], $this->plain);
        self::assertSame([403, 'scope_live_edit'], [$r['status'], $r['data']['error']['code']]);
        self::assertSame(200, $this->site->call('PATCH', 'v1/news/30', ['date' => '2026-10-20', 'description' => 'Meta'], [], $this->plain)['status'], 'Metadaten frei');
        self::assertSame(200, $this->site->call('PATCH', 'v1/news/30', ['teaser' => '<p>Live</p>'])['status']);
        self::assertSame(200, $this->site->call('PATCH', 'v1/news/31', ['published' => true])['status']);
    }

    public function testAuthFailuresAreLimitedPerRemoteAddress(): void
    {
        $now = 1_800_000_000;
        $limiter = new RateLimiter(new ArrayRateStore(), new Settings([]), static function () use (&$now): int {
            return $now;
        });
        for ($i = 1; $i <= RateLimiter::AUTH_FAILURES_PER_MINUTE; $i++) {
            self::assertTrue($limiter->hitAuthFailure('203.0.113.5')['allowed'], "Versuch $i");
        }
        self::assertFalse($limiter->hitAuthFailure('203.0.113.5')['allowed']);
        self::assertTrue($limiter->hitAuthFailure('203.0.113.6')['allowed'], 'andere Adresse zählt getrennt');
        self::assertTrue($limiter->hit(1)['allowed'], 'Token-Zähler unabhängig');
        $now += 60;
        self::assertTrue($limiter->hitAuthFailure('203.0.113.5')['allowed'], 'neues Minutenfenster');

        // über den Kernel: 401 bis zur Grenze, danach 429 mit Retry-After; gültige Tokens bleiben unberührt
        for ($i = 1; $i <= RateLimiter::AUTH_FAILURES_PER_MINUTE; $i++) {
            self::assertSame(401, $this->site->call('GET', 'v1/info', null, [], 'falsches-token-1234567890')['status']);
        }
        $r = $this->site->call('GET', 'v1/info', null, [], 'falsches-token-1234567890');
        self::assertSame([429, 'rate_limited', '60'], [$r['status'], $r['data']['error']['code'], $r['headers']['Retry-After']]);
        self::assertSame(401, $this->site->call('GET', 'v1/info', null, [], 'falsches-token-1234567890', true, null, '203.0.113.99')['status']);
        self::assertSame(200, $this->site->call('GET', 'v1/info')['status']);
        self::assertStringNotContainsString('→ 429', $this->site->logger->text());
    }

    public function testSystemListsOnlyContaoAndVelundo(): void
    {
        self::assertTrue(SystemHandler::startsWithAny('ContaoNewsBundle', ['Contao', 'Velundo']));
        self::assertTrue(SystemHandler::startsWithAny('velundo/contao-connect', ['contao/', 'velundo/']));
        self::assertFalse(SystemHandler::startsWithAny('terminal42/notification_center', ['contao/', 'velundo/']));
        self::assertFalse(SystemHandler::startsWithAny('FrameworkBundle', ['Contao', 'Velundo']));
        $s = $this->site->call('GET', 'v1/system')['data'];
        self::assertSame(['ContaoCoreBundle', 'ContaoNewsBundle', 'VelundoContaoConnectBundle'], $s['bundles']);
        self::assertSame(['contao/core-bundle', 'contao/news-bundle'], array_column($s['packages'], 'name'));
    }
}
