<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\ContaoConnect\Api\InfoHandler;
use Velundo\ContaoConnect\Tests\Support\TestSite;

/**
 * Endpunkte nach Vertrag gegen eine SQLite-Datenbank mit Contao-Tabellen (Tests/Support/TestSite.php).
 */
final class ApiTest extends TestCase
{
    private TestSite $site;

    protected function setUp(): void
    {
        $this->site = new TestSite();
    }

    public function testAuthOrderAndErrors(): void
    {
        $r = $this->site->call('GET', 'v1/info', null, [], 'falsches-token-1234567890');
        self::assertSame(401, $r['status']);
        self::assertSame('unauthorized', $r['data']['error']['code']);
        self::assertSame('Bearer realm="velundo"', $r['headers']['WWW-Authenticate']);
        self::assertStringNotContainsString('5.3', (string)json_encode($r['data']));
        // ohne Token auch über http 401, mit Token über http 403
        self::assertSame(401, $this->site->call('GET', 'v1/info', null, [], 'falsches-token-1234567890', false)['status']);
        $r = $this->site->call('GET', 'v1/info', null, [], null, false);
        self::assertSame(403, $r['status']);
        self::assertSame('https_required', $r['data']['error']['code']);
        // X-Velundo-Token, auch wenn Authorization etwas anderes trägt (Basic-Schutz)
        $r = $this->site->kernel->handle('GET', 'v1/info', [], '', null, $this->site->token, true);
        self::assertSame(200, $r['status']);
        $r = $this->site->kernel->handle('GET', 'v1/info', [], '', 'Basic dXNlcjpwdw==', $this->site->token, true);
        self::assertSame(200, $r['status']);
        self::assertSame(404, $this->site->call('GET', 'v1/gibtsnicht')['status']);
        self::assertSame('method_not_allowed', $this->site->call('DELETE', 'v1/pages/2')['data']['error']['code']);
        $r = $this->site->kernel->handle('PATCH', 'v1/pages/2', [], '{"title": ', 'Bearer ' . $this->site->token, null, true);
        self::assertSame(400, $r['status']);
        self::assertSame('invalid_json', $r['data']['error']['code']);
        $log = $this->site->logger->text();
        // Lesezugriffe nicht im Protokoll, abgelehnte Schreibversuche schon (Livetest: sonst flutet jede Abfrage tl_log)
        self::assertStringNotContainsString('GET /v1/info', $log);
        self::assertStringContainsString('velundo: PATCH /v1/pages/2 → 400 – Token „Agentur Test“', $log);
        self::assertStringContainsString('velundo: DELETE /v1/pages/2', $log);
        self::assertStringNotContainsString('falsches-token', $log);
    }

    public function testRateLimit(): void
    {
        $site = new TestSite(['rate_limit_per_minute' => 2]);
        self::assertSame(200, $site->call('GET', 'v1/info')['status']);
        self::assertSame(200, $site->call('GET', 'v1/info')['status']);
        $r = $site->call('GET', 'v1/info');
        self::assertSame(429, $r['status']);
        self::assertSame('rate_limited', $r['data']['error']['code']);
        self::assertSame('60', $r['headers']['Retry-After']);
        self::assertStringNotContainsString('→ 429', $site->logger->text());
    }

    public function testInfoAndSystem(): void
    {
        $r = $this->site->call('GET', 'v1/info');
        self::assertSame(200, $r['status']);
        self::assertSame('5.3.12', $r['data']['contao']);
        self::assertSame(InfoHandler::BUNDLE_VERSION, $r['data']['bundle']);
        self::assertSame(['news' => true, 'calendar' => false, 'http_cache' => true], $r['data']['features']);
        self::assertSame(['max_text_kb' => 200, 'rate_limit_per_minute' => 1000, 'limit_max' => 200], $r['data']['limits']);
        self::assertSame(['name' => 'Agentur Test', 'scopes' => ['publish', 'live_edit']], $r['data']['token']);
        $plain = $this->site->tokens->create('Nur Entwürfe')['token'];
        self::assertSame(['name' => 'Nur Entwürfe', 'scopes' => []], $this->site->call('GET', 'v1/info', null, [], $plain)['data']['token']);
        self::assertCount(2, $r['data']['roots']);
        self::assertSame(['id' => 1, 'title' => 'Agentur Test', 'dns' => 'www.example.de', 'language' => 'de', 'fallback' => true, 'published' => true, 'maintenance' => false, 'base' => 'https://www.example.de/'], $r['data']['roots'][0]);
        self::assertSame('https://en.example.de/en/', $r['data']['roots'][1]['base']);
        $s = $this->site->call('GET', 'v1/system')['data'];
        self::assertSame('prod', $s['environment']);
        self::assertSame('contao/core-bundle', $s['packages'][0]['name']);
        self::assertNotNull($s['cron_last_run']);
        self::assertStringNotContainsString('/var/', (string)json_encode($s));
    }

    public function testPagesTree(): void
    {
        $r = $this->site->call('GET', 'v1/pages')['data'];
        self::assertSame(8, $r['total']);
        self::assertSame([1, 2, 3, 4, 5, 6, 8, 9], array_column($r['items'], 'id'));
        $p3 = $r['items'][2];
        self::assertSame('Leistungen (alle)', $p3['title'], 'Entitäten dekodiert');
        self::assertSame(1, $p3['root']);
        self::assertSame(1, $p3['level']);
        self::assertSame('de', $p3['language']);
        self::assertSame('https://www.example.de/seite-3', $p3['url']);
        self::assertNull($r['items'][0]['url']);
        self::assertSame(2, $r['items'][3]['level']);
        self::assertTrue($r['items'][3]['hide']);
        self::assertSame('2026-05-28T22:26:40+02:00', $r['items'][5]['start']);
        self::assertNull($r['items'][0]['start']);
        self::assertSame([8, 9], array_column($this->site->call('GET', 'v1/pages', null, ['root' => '8'])['data']['items'], 'id'));
        self::assertSame([4], array_column($this->site->call('GET', 'v1/pages', null, ['parent' => '3'])['data']['items'], 'id'));
        self::assertSame([2, 3, 5, 6], array_column($this->site->call('GET', 'v1/pages', null, ['parent' => '1', 'depth' => '1'])['data']['items'], 'id'));
        self::assertSame([1, 8], array_column($this->site->call('GET', 'v1/pages', null, ['depth' => '1'])['data']['items'], 'id'));
        self::assertSame([4], array_column($this->site->call('GET', 'v1/pages', null, ['visibility' => 'hidden'])['data']['items'], 'id'));
        self::assertSame([3], array_column($this->site->call('GET', 'v1/pages', null, ['search' => '(alle)'])['data']['items'], 'id'));
        $page = $this->site->call('GET', 'v1/pages', null, ['limit' => '2', 'offset' => '2'])['data'];
        self::assertSame([3, 4], array_column($page['items'], 'id'));
        self::assertSame(['total' => 8, 'limit' => 2, 'offset' => 2], array_intersect_key($page, ['total' => 1, 'limit' => 1, 'offset' => 1]));
        self::assertSame('root_not_found', $this->site->call('GET', 'v1/pages', null, ['root' => '3'])['data']['error']['code']);
        self::assertSame(404, $this->site->call('GET', 'v1/pages', null, ['parent' => '77'])['status']);
    }

    public function testPageDetailAndArticles(): void
    {
        $p = $this->site->call('GET', 'v1/pages/3')['data'];
        self::assertSame(3, $p['id']);
        self::assertCount(2, $p['articles']);
        self::assertSame(['id' => 10, 'title' => 'Leistungen', 'alias' => 'leistungen', 'inColumn' => 'main', 'published' => true, 'start' => null, 'stop' => null, 'sorting' => 128, 'elements' => 3], $p['articles'][0]);
        self::assertSame(404, $this->site->call('GET', 'v1/pages/77')['status']);
        $a = $this->site->call('GET', 'v1/articles', null, ['page' => '3'])['data'];
        self::assertSame(2, $a['total']);
        self::assertSame('right', $a['items'][1]['inColumn']);
        self::assertSame([11], array_column($this->site->call('GET', 'v1/articles', null, ['page' => '3', 'visibility' => 'hidden'])['data']['items'], 'id'));
        self::assertSame(422, $this->site->call('GET', 'v1/articles')['status']);
    }

    public function testPatchPage(): void
    {
        $r = $this->site->call('PATCH', 'v1/pages/3', ['title' => 'Leistungen (neu)', 'pageTitle' => 'Leistungen – Agentur Test', 'description' => 'Alles <b>wichtige</b>', 'robots' => 'noindex,follow', 'published' => false, 'hide' => true]);
        self::assertSame(200, $r['status']);
        self::assertSame('Leistungen (neu)', $r['data']['title']);
        self::assertSame(['title' => 'Leistungen (alle)', 'pageTitle' => '', 'description' => '', 'robots' => '', 'published' => true, 'hide' => false], $r['data']['before']);
        self::assertCount(2, $r['data']['articles']);
        $row = $this->site->row('SELECT * FROM tl_page WHERE id = 3');
        self::assertSame('Leistungen &#40;neu&#41;', $row['title'], 'gespeichert wie im Backend kodiert');
        self::assertSame('Alles &#60;b>wichtige&#60;/b>', $row['description']);
        self::assertSame(0, (int)$row['published']);
        self::assertSame($this->site->now, (int)$row['tstamp']);
        self::assertSame(['init tl_page.3 page', 'create tl_page.3 page'], $this->site->versions->calls);
        self::assertSame(['contao.db.tl_page.3', 'contao.db.tl_page.1'], $this->site->cache->tags);
        self::assertStringContainsString('velundo: Seite 3 geändert (title, pageTitle, description, robots, published, hide) [tl_page.3] – Token „Agentur Test“', $this->site->logger->text());
        self::assertStringNotContainsString('wichtige', $this->site->logger->text(), 'keine Inhalte im Protokoll');
        // Fehlerfälle
        foreach ([[['robots' => 'nofollow'], 'invalid_value'], [['title' => ''], 'empty_value'], [['title' => str_repeat('x', 256)], 'too_long'], [['published' => 'ja'], 'invalid_type'],
            [['deleted' => 1], 'unknown_field'], [[], 'empty_body'], [['pageTitle' => 'a {{env::host}}'], 'insert_tag_not_allowed']] as [$body, $code]) {
            $e = $this->site->call('PATCH', 'v1/pages/2', $body);
            self::assertSame(422, $e['status'], $code);
            self::assertSame($code, $e['data']['error']['code']);
        }
        $root = $this->site->call('PATCH', 'v1/pages/1', ['published' => false]);
        self::assertSame('root_page', $root['data']['error']['code']);
        self::assertSame(200, $this->site->call('PATCH', 'v1/pages/1', ['title' => 'Agentur Test GmbH & Co'])['status']);
        self::assertSame(['contao.db.tl_page.1', 'contao.db.tl_page'], array_slice($this->site->cache->tags, -2));
    }

    public function testContentRead(): void
    {
        $r = $this->site->call('GET', 'v1/content', null, ['article' => '10'])['data'];
        self::assertSame([20, 21, 22], array_column($r['items'], 'id'));
        self::assertSame('Entwurf (neu)', $r['items'][1]['headline']);
        self::assertSame('h3', $r['items'][1]['headline_unit']);
        self::assertSame('Noch geheim und neu.', $r['items'][1]['text_plain'], 'Basis-Entität [nbsp] als Leerzeichen im Klartext');
        self::assertSame('tl_article', $r['items'][2]['ptable'], 'leeres ptable gilt als tl_article');
        self::assertSame([21], array_column($this->site->call('GET', 'v1/content', null, ['article' => '10', 'visibility' => 'hidden'])['data']['items'], 'id'));
        self::assertSame([40], array_column($this->site->call('GET', 'v1/content', null, ['news' => '30'])['data']['items'], 'id'));
        self::assertSame(422, $this->site->call('GET', 'v1/content', null, ['article' => '10', 'news' => '30'])['status']);
        self::assertSame(422, $this->site->call('GET', 'v1/content')['status']);
        $c = $this->site->call('GET', 'v1/content/20')['data'];
        self::assertSame('<p>Wir beraten &amp; bauen <a href="/kontakt">Websites</a>.</p>', $c['text']);
        self::assertSame(1, $c['link_count']);
        self::assertFalse($c['image']);
        self::assertSame(['table' => 'tl_article', 'id' => 10, 'title' => 'Leistungen', 'page' => 3], $c['parent']);
        self::assertSame(['table' => 'tl_news', 'id' => 30, 'title' => 'Neue Website online', 'archive' => 1], $this->site->call('GET', 'v1/content/40')['data']['parent']);
        self::assertTrue($this->site->call('GET', 'v1/content/22')['data']['image']);
    }

    public function testContentCreate(): void
    {
        $r = $this->site->call('POST', 'v1/content', ['article' => 10, 'headline' => 'Neu (Entwurf)', 'headline_unit' => 'h3', 'text' => '<p>Text<script>x</script> mit <a href="{{link_url::3}}">Link</a></p>']);
        self::assertSame(201, $r['status']);
        $id = $r['data']['id'];
        self::assertSame(640, $r['data']['sorting'], 'ans Ende: 512 + 128');
        self::assertSame([], $r['data']['before']);
        $row = $this->site->row('SELECT * FROM tl_content WHERE id = ?', [$id]);
        self::assertSame(1, (int)$row['invisible'], 'immer unsichtbar');
        self::assertSame('tl_article', $row['ptable']);
        self::assertSame('text', $row['type']);
        self::assertSame(serialize(['value' => 'Neu &#40;Entwurf&#41;', 'unit' => 'h3']), $row['headline']);
        self::assertSame('<p>Text mit <a href="{{link_url::3}}">Link</a></p>', $row['text']);
        self::assertSame(["create tl_content.$id article"], $this->site->versions->calls);
        self::assertSame(["contao.db.tl_content.$id", 'contao.db.tl_article.10'], $this->site->cache->tags);
        // hinter Element 20: keine Lücke zu 21 (128/129) → neu nummeriert
        $r = $this->site->call('POST', 'v1/content', ['article' => 10, 'type' => 'headline', 'headline' => 'Zwischentitel', 'after' => 20]);
        self::assertSame(201, $r['status']);
        $sortings = array_column($this->site->connection->fetchAllAssociative("SELECT id, sorting FROM tl_content WHERE pid = 10 AND ptable IN ('tl_article', '') ORDER BY sorting"), 'id');
        self::assertSame([20, $r['data']['id'], 21, 22, $id], array_map('intval', $sortings));
        self::assertNull($this->site->row('SELECT text FROM tl_content WHERE id = ?', [$r['data']['id']])['text']);
        // in eine Nachricht
        $n = $this->site->call('POST', 'v1/content', ['news' => 31, 'text' => '<p>Nachrichtentext</p>']);
        self::assertSame(201, $n['status']);
        self::assertSame('tl_news', $this->site->row('SELECT ptable FROM tl_content WHERE id = ?', [$n['data']['id']])['ptable']);
        // Fehlerfälle
        foreach ([[['article' => 10, 'invisible' => false, 'text' => 'x'], 422, 'unknown_field'], [['article' => 10], 422, 'empty_value'], [['article' => 10, 'type' => 'html', 'text' => 'x'], 422, 'invalid_value'],
            [['article' => 10, 'news' => 30, 'text' => 'x'], 422, 'validation_failed'], [['article' => 99, 'text' => 'x'], 404, 'not_found'], [['article' => 10, 'text' => 'x', 'after' => 23], 422, 'after_mismatch'],
            [['article' => 10, 'type' => 'headline'], 422, 'empty_value'], [['article' => 10, 'type' => 'headline', 'headline' => 'x', 'text' => 'y'], 422, 'unsupported_type'],
            [['article' => 10, 'text' => '{{user::password}}'], 422, 'insert_tag_not_allowed'], [['article' => 10, 'text' => 'x', 'headline_unit' => 'h7'], 422, 'invalid_value']] as [$body, $status, $code]) {
            $e = $this->site->call('POST', 'v1/content', $body);
            self::assertSame($status, $e['status'], $code . ' ' . json_encode($body));
            self::assertSame($code, $e['data']['error']['code']);
        }
    }

    public function testContentUpdate(): void
    {
        $r = $this->site->call('PATCH', 'v1/content/21', ['headline' => 'Fertig', 'text' => '<p>Jetzt öffentlich?</p>']);
        self::assertSame(200, $r['status']);
        self::assertSame(['headline' => 'Entwurf (neu)', 'text' => '<p>Noch geheim&nbsp;und neu.</p>'], $r['data']['before']);
        self::assertSame('Fertig', $r['data']['headline']);
        self::assertSame('h3', $r['data']['headline_unit'], 'Einheit bleibt');
        self::assertTrue($r['data']['invisible']);
        $r = $this->site->call('PATCH', 'v1/content/21', ['headline_unit' => 'h2', 'invisible' => false]);
        self::assertSame(['headline_unit' => 'h3', 'invisible' => true], $r['data']['before']);
        self::assertSame(serialize(['value' => 'Fertig', 'unit' => 'h2']), $this->site->row('SELECT headline FROM tl_content WHERE id = 21')['headline']);
        self::assertSame(['init tl_content.21 article', 'create tl_content.21 article', 'init tl_content.21 article', 'create tl_content.21 article'], $this->site->versions->calls);
        // Bild-Element: nur Sichtbarkeit; headline-Element ohne Text
        self::assertSame('unsupported_type', $this->site->call('PATCH', 'v1/content/22', ['headline' => 'x'])['data']['error']['code']);
        self::assertSame(200, $this->site->call('PATCH', 'v1/content/22', ['invisible' => true])['status']);
        self::assertSame('unsupported_type', $this->site->call('PATCH', 'v1/content/23', ['text' => '<p>x</p>'])['data']['error']['code']);
        self::assertSame('empty_value', $this->site->call('PATCH', 'v1/content/23', ['headline' => ''])['data']['error']['code']);
        self::assertSame('empty_value', $this->site->call('PATCH', 'v1/content/20', ['text' => ' '])['data']['error']['code']);
        self::assertSame(404, $this->site->call('PATCH', 'v1/content/999', ['invisible' => true])['status']);
        $news = $this->site->call('PATCH', 'v1/content/40', ['invisible' => true]);
        self::assertSame(['contao.db.tl_content.40', 'contao.db.tl_news.30'], array_slice($this->site->cache->tags, -2));
        self::assertSame(200, $news['status']);
    }

    public function testNewsRead(): void
    {
        $a = $this->site->call('GET', 'v1/news_archives')['data'];
        self::assertSame(2, $a['total']);
        self::assertSame(['id' => 1, 'title' => 'Aktuelles', 'jumpTo' => 6, 'protected' => false, 'news_count' => 2, 'tstamp' => '2026-05-28T22:26:40+02:00'], $a['items'][0]);
        $r = $this->site->call('GET', 'v1/news')['data'];
        self::assertSame([31, 30, 32], array_column($r['items'], 'id'), 'neueste zuerst');
        $n30 = $r['items'][1];
        self::assertSame('Neu <online>', $n30['pageTitle']);
        self::assertSame('Wir sind live.', $n30['teaser_text']);
        self::assertSame('https://www.example.de/aktuelles/30', $n30['url']);
        self::assertTrue($n30['published']);
        self::assertSame([31, 30], array_column($this->site->call('GET', 'v1/news', null, ['archive' => '1'])['data']['items'], 'id'));
        self::assertSame([30], array_column($this->site->call('GET', 'v1/news', null, ['search' => 'live'])['data']['items'], 'id'));
        self::assertSame([31, 32], array_column($this->site->call('GET', 'v1/news', null, ['visibility' => 'hidden'])['data']['items'], 'id'));
        self::assertSame([], $this->site->call('GET', 'v1/news', null, ['search' => '%'])['data']['items'], 'Platzhalter maskiert');
        self::assertSame(404, $this->site->call('GET', 'v1/news', null, ['archive' => '9'])['status']);
        $d = $this->site->call('GET', 'v1/news/30')['data'];
        self::assertSame(40, $d['text_element']);
        self::assertSame('<p>Heute ging die Seite online.</p>', $d['text']);
        self::assertSame('Heute ging die Seite online.', $d['text_plain']);
        self::assertCount(1, $d['content']);
        self::assertSame(0, $this->site->call('GET', 'v1/news/31')['data']['text_element']);
        self::assertNull($this->site->call('GET', 'v1/news/32')['data']['text_element']);
        $plain = new TestSite([], false);
        self::assertSame('feature_unavailable', $plain->call('GET', 'v1/news')['data']['error']['code']);
        self::assertSame('feature_unavailable', $plain->call('GET', 'v1/news_archives')['data']['error']['code']);
        self::assertFalse($plain->call('GET', 'v1/info')['data']['features']['news']);
    }

    public function testNewsCreate(): void
    {
        $r = $this->site->call('POST', 'v1/news', ['archive' => 1, 'headline' => 'Neue Website online', 'teaser' => '<p>Kurz</p>', 'text' => '<p>Langer Text</p>', 'date' => '2026-11-01T09:00:00+01:00', 'pageTitle' => 'SEO (Titel)', 'description' => 'Meta']);
        self::assertSame(201, $r['status']);
        $id = $r['data']['id'];
        self::assertSame('neue-website-online-2', $r['data']['alias'], 'eindeutig');
        $row = $this->site->row('SELECT * FROM tl_news WHERE id = ?', [$id]);
        self::assertSame(0, (int)$row['published'], 'immer unveröffentlicht');
        self::assertSame(1_793_520_000, (int)$row['date']);
        self::assertSame((int)$row['date'], (int)$row['time']);
        self::assertSame(0, (int)$row['author']);
        self::assertSame('SEO (Titel)', $row['pageTitle'], 'pageTitle der Nachricht: nur < kodiert (decodeEntities)');
        $el = $this->site->row('SELECT * FROM tl_content WHERE id = ?', [$r['data']['text_element']]);
        self::assertSame(['tl_news', $id, 'text', '<p>Langer Text</p>', 0], [$el['ptable'], (int)$el['pid'], $el['type'], $el['text'], (int)$el['invisible']]);
        self::assertSame(["create tl_news.$id news", 'create tl_content.' . $el['id'] . ' news'], $this->site->versions->calls);
        $d = $this->site->call('GET', "v1/news/$id")['data'];
        self::assertSame((int)$el['id'], $d['text_element']);
        self::assertNull($this->site->call('POST', 'v1/news', ['archive' => 1, 'headline' => 'Ohne Text'])['data']['text_element']);
        foreach ([[['archive' => 1, 'headline' => 'x', 'published' => true], 422, 'unknown_field'], [['headline' => 'x'], 422, 'empty_value'], [['archive' => 9, 'headline' => 'x'], 404, 'not_found'],
            [['archive' => 1], 422, 'empty_value'], [['archive' => 1, 'headline' => 'x', 'date' => 'morgen'], 422, 'invalid_type']] as [$body, $status, $code]) {
            $e = $this->site->call('POST', 'v1/news', $body);
            self::assertSame($status, $e['status'], $code);
            self::assertSame($code, $e['data']['error']['code']);
        }
    }

    public function testNewsUpdate(): void
    {
        $r = $this->site->call('PATCH', 'v1/news/31', ['headline' => 'Herbst 2026', 'text' => '<p>Neuer Text</p>', 'date' => '2026-10-01', 'published' => true]);
        self::assertSame(200, $r['status']);
        self::assertSame(['headline' => 'Entwurf Herbst', 'date' => '2026-09-10T02:26:40+02:00', 'published' => false, 'text' => ''], $r['data']['before']);
        self::assertSame('<p>Neuer Text</p>', $r['data']['text']);
        self::assertGreaterThan(0, $r['data']['text_element']);
        $r = $this->site->call('PATCH', 'v1/news/30', ['text' => '<p>Geändert</p>']);
        self::assertSame(['text' => '<p>Heute ging die Seite online.</p>'], $r['data']['before']);
        self::assertSame('<p>Geändert</p>', $this->site->row('SELECT text FROM tl_content WHERE id = 40')['text']);
        self::assertContainsVersion('create tl_content.40 news');
        $e = $this->site->call('PATCH', 'v1/news/32', ['text' => '<p>x</p>']);
        self::assertSame('ambiguous_text', $e['data']['error']['code']);
        self::assertSame([41, 42], $e['data']['error']['details']['elements']);
        self::assertSame('<p>Teil eins</p>', $this->site->row('SELECT text FROM tl_content WHERE id = 41')['text'], 'nichts geschrieben');
        self::assertSame('empty_value', $this->site->call('PATCH', 'v1/news/30', ['text' => ''])['data']['error']['code']);
        self::assertSame('empty_body', $this->site->call('PATCH', 'v1/news/30', [])['data']['error']['code']);
    }

    public function testSeoSearchAndCache(): void
    {
        $s = $this->site->call('GET', 'v1/seo/overview')['data'];
        self::assertSame(5, $s['pages_total']);
        self::assertSame([3, 5, 6], array_column($s['missing_title'], 'id'));
        self::assertSame([3, 6, 9], array_column($s['missing_description'], 'id'));
        self::assertSame([5], array_column($s['noindex'], 'id'));
        self::assertSame([5], array_column($s['long_description'], 'id'));
        self::assertSame([9], array_column($s['long_title'], 'id'));
        self::assertSame([], $s['duplicate_titles'], 'Start/Willkommen sind verschieden');
        self::assertSame(4, $this->site->call('GET', 'v1/seo/overview', null, ['root' => '1'])['data']['pages_total']);
        $q = $this->site->call('GET', 'v1/search', null, ['q' => 'start'])['data'];
        self::assertSame([2, 6], array_column($q['pages'], 'id'));
        $q = $this->site->call('GET', 'v1/search', null, ['q' => 'online'])['data'];
        self::assertSame([40], array_column($q['content'], 'id'));
        self::assertSame([30], array_column($q['news'], 'id'));
        self::assertSame([], $this->site->call('GET', 'v1/search', null, ['q' => 'online', 'root' => '8'])['data']['content']);
        self::assertSame(422, $this->site->call('GET', 'v1/search', null, ['q' => 'x'])['status']);
        $c = $this->site->call('POST', 'v1/cache/clear', ['pages' => [2, 3], 'news' => [30]]);
        self::assertSame(['contao.db.tl_page.2', 'contao.db.tl_page.3', 'contao.db.tl_news.30'], $c['data']['tags']);
        self::assertTrue($c['data']['invalidated']);
        self::assertSame('empty_body', $this->site->call('POST', 'v1/cache/clear', ['pages' => []])['data']['error']['code']);
        self::assertSame('invalid_type', $this->site->call('POST', 'v1/cache/clear', ['pages' => ['a']])['data']['error']['code']);
        $noCache = new TestSite([], true, false);
        self::assertSame('feature_unavailable', $noCache->call('POST', 'v1/cache/clear', ['pages' => [2]])['data']['error']['code']);
        self::assertFalse($noCache->call('GET', 'v1/info')['data']['features']['http_cache']);
        // Schreiben ohne HTTP-Cache geht trotzdem
        self::assertSame(200, $noCache->call('PATCH', 'v1/pages/2', ['hide' => true])['status']);
    }

    private function assertContainsVersion(string $call): void
    {
        self::assertTrue(in_array($call, $this->site->versions->calls, true), $call . ' fehlt: ' . implode(', ', $this->site->versions->calls));
    }
}
