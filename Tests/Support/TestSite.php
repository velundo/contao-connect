<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Support;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Velundo\ContaoConnect\Api\ApiKernel;
use Velundo\ContaoConnect\Api\ArticlesHandler;
use Velundo\ContaoConnect\Api\CacheHandler;
use Velundo\ContaoConnect\Api\ContentHandler;
use Velundo\ContaoConnect\Api\InfoHandler;
use Velundo\ContaoConnect\Api\NewsArchivesHandler;
use Velundo\ContaoConnect\Api\NewsHandler;
use Velundo\ContaoConnect\Api\PagesHandler;
use Velundo\ContaoConnect\Api\SearchHandler;
use Velundo\ContaoConnect\Api\SeoHandler;
use Velundo\ContaoConnect\Api\SystemHandler;
use Velundo\ContaoConnect\Service\Contao\CacheInvalidatorInterface;
use Velundo\ContaoConnect\Service\Contao\SluggerInterface;
use Velundo\ContaoConnect\Service\Contao\SystemInfoInterface;
use Velundo\ContaoConnect\Service\Contao\UrlResolverInterface;
use Velundo\ContaoConnect\Service\Contao\VersionRecorderInterface;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\Features;
use Velundo\ContaoConnect\Service\HtmlSanitizer;
use Velundo\ContaoConnect\Service\LogService;
use Velundo\ContaoConnect\Service\PageTree;
use Velundo\ContaoConnect\Service\RateLimiter;
use Velundo\ContaoConnect\Service\Router;
use Velundo\ContaoConnect\Service\Settings;
use Velundo\ContaoConnect\Service\Store\DbalRateStore;
use Velundo\ContaoConnect\Service\Store\DbalTokenStore;
use Velundo\ContaoConnect\Service\TextField;
use Velundo\ContaoConnect\Service\TokenService;
use Velundo\ContaoConnect\Service\Writer;

/** Protokoll im Arbeitsspeicher. */
final class MemoryLogger implements LoggerInterface
{
    /** @var list<array{0: string, 1: string}> */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message, $context); }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string)$level, (string)$message];
    }

    public function text(): string
    {
        return implode("\n", array_map(static fn(array $r): string => $r[1], $this->records));
    }
}

final class FakeUrls implements UrlResolverInterface
{
    public function pageUrl(int $id): ?string
    {
        return $id === 1 ? null : 'https://www.example.de/seite-' . $id;
    }

    public function newsUrl(int $id): ?string
    {
        return 'https://www.example.de/aktuelles/' . $id;
    }
}

final class FakeVersions implements VersionRecorderInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function initialize(string $table, int $id, string $module): void
    {
        $this->calls[] = "init $table.$id $module";
    }

    public function create(string $table, int $id, string $module): void
    {
        $this->calls[] = "create $table.$id $module";
    }
}

final class FakeCache implements CacheInvalidatorInterface
{
    /** @var list<string> */
    public array $tags = [];

    public function __construct(public bool $enabled = true) {}

    public function available(): bool
    {
        return $this->enabled;
    }

    public function invalidate(array $tags): bool
    {
        if (!$this->enabled) {
            return false;
        }
        array_push($this->tags, ...$tags);
        return true;
    }
}

final class FakeSlugger implements SluggerInterface
{
    public function generate(string $text, int $pageId, callable $exists): string
    {
        $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']))), '-');
        $slug = $base;
        for ($i = 2; $exists($slug); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }
}

final class FakeSystemInfo implements SystemInfoInterface
{
    public function __construct(private readonly string $version = '5.3.12') {}

    public function contaoVersion(): string
    {
        return $this->version;
    }

    public function bundles(): array
    {
        return ['ContaoCoreBundle', 'ContaoNewsBundle', 'VelundoContaoConnectBundle'];
    }

    public function packages(): array
    {
        return [['name' => 'contao/core-bundle', 'type' => 'contao-bundle', 'version' => '5.3.12'], ['name' => 'contao/news-bundle', 'type' => 'contao-bundle', 'version' => '5.3.12']];
    }
}

/**
 * Prüf-Installation: SQLite-Datenbank mit den Contao-Tabellen (nur benutzte Spalten), Beispieldaten und der ApiKernel
 * mit Ersatzdiensten für Contao (Adressen, Versionen, Cache, Slug, Systemangaben).
 */
final class TestSite
{
    public Connection $connection;
    public MemoryLogger $logger;
    public FakeVersions $versions;
    public FakeCache $cache;
    public ApiKernel $kernel;
    public TokenService $tokens;
    public string $token;
    public int $now = 1_791_626_400; // 2026-10-10 10:00 UTC

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = [], bool $news = true, bool $httpCache = true, string $contaoVersion = '5.3.12')
    {
        $this->connection = new Connection();
        self::schema($this->connection->pdo);
        self::data($this->connection->pdo);
        $db = new Db($this->connection);
        $this->logger = new MemoryLogger();
        $this->versions = new FakeVersions();
        $this->cache = new FakeCache($httpCache);
        $clock = fn(): int => $this->now;
        $settingsObj = new Settings($settings + ['rate_limit_per_minute' => 1000]);
        $this->tokens = new TokenService(new DbalTokenStore($this->connection), $clock);
        $this->token = $this->tokens->create('Agentur Test')['token'];
        $log = new LogService($this->logger);
        $writer = new Writer($db, $this->versions, $this->cache, $log, $this->logger, $clock);
        $features = new Features($news ? ['ContaoCoreBundle' => 'x', 'ContaoNewsBundle' => 'y'] : ['ContaoCoreBundle' => 'x'], $this->cache);
        $tree = new PageTree($db);
        $urls = new FakeUrls();
        $text = new TextField($settingsObj, new HtmlSanitizer());
        $system = new FakeSystemInfo($contaoVersion);
        $this->kernel = new ApiKernel(new Router(), $this->tokens, new RateLimiter(new DbalRateStore($this->connection), $settingsObj, $clock), $settingsObj, $log, $this->logger, [
            'info' => new InfoHandler($db, $system, $features, $settingsObj),
            'system' => new SystemHandler($db, $system, $settingsObj),
            'pages' => new PagesHandler($db, $tree, $urls, $writer),
            'articles' => new ArticlesHandler($db, $tree),
            'content' => new ContentHandler($db, $features, $text, $writer),
            'news_archives' => new NewsArchivesHandler($db, $features),
            'news' => new NewsHandler($db, $features, $urls, $text, new FakeSlugger(), $writer),
            'seo' => new SeoHandler($tree, $urls),
            'search' => new SearchHandler($db, $tree, $features, $urls),
            'cache' => new CacheHandler($writer),
        ], $system);
    }

    /**
     * Aufruf wie über HTTP; $path ohne /_velundo/, z. B. 'v1/pages'.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @return array{status: int, data: array<string, mixed>, headers: array<string, string>}
     */
    public function call(string $method, string $path, ?array $body = null, array $query = [], ?string $token = null, bool $secure = true, ?string $velundoToken = null): array
    {
        $r = $this->kernel->handle($method, $path, $query, $body === null ? '' : (string)json_encode($body), 'Bearer ' . ($token ?? $this->token), $velundoToken, $secure, 'www.example.de');
        // Rundreise durch JSON wie im Controller (stdClass → {}, Typen wie beim Client)
        $r['data'] = json_decode((string)json_encode($r['data'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
        return $r;
    }

    /** @return array<string, mixed>|null */
    public function row(string $sql, array $params = []): ?array
    {
        $r = $this->connection->fetchAssociative($sql, $params);
        return is_array($r) ? $r : null;
    }

    public static function schema(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, sorting INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0,
            title TEXT NOT NULL DEFAULT '', alias TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'regular', pageTitle TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '',
            robots TEXT NOT NULL DEFAULT '', description TEXT NULL, dns TEXT NOT NULL DEFAULT '', fallback INTEGER NOT NULL DEFAULT 0, useSSL INTEGER NOT NULL DEFAULT 1,
            urlPrefix TEXT NOT NULL DEFAULT '', maintenanceMode INTEGER NOT NULL DEFAULT 0, hide INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 0,
            start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')");
        $pdo->exec("CREATE TABLE tl_article (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, sorting INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0,
            title TEXT NOT NULL DEFAULT '', alias TEXT NOT NULL DEFAULT '', inColumn TEXT NOT NULL DEFAULT 'main', published INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')");
        $pdo->exec("CREATE TABLE tl_content (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, ptable TEXT NOT NULL DEFAULT 'tl_article', sorting INTEGER NOT NULL DEFAULT 0,
            tstamp INTEGER NOT NULL DEFAULT 0, type TEXT NOT NULL DEFAULT 'text', headline TEXT NOT NULL DEFAULT 'a:2:{s:5:\"value\";s:0:\"\";s:4:\"unit\";s:2:\"h2\";}', text TEXT NULL,
            addImage INTEGER NOT NULL DEFAULT 0, invisible INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')");
        $pdo->exec("CREATE TABLE tl_news_archive (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT '', jumpTo INTEGER NOT NULL DEFAULT 0, protected INTEGER NOT NULL DEFAULT 0)");
        $pdo->exec("CREATE TABLE tl_news (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0, headline TEXT NOT NULL DEFAULT '', featured INTEGER NOT NULL DEFAULT 0,
            alias TEXT NOT NULL DEFAULT '', author INTEGER NOT NULL DEFAULT 0, date INTEGER NOT NULL DEFAULT 0, time INTEGER NOT NULL DEFAULT 0, pageTitle TEXT NOT NULL DEFAULT '', robots TEXT NOT NULL DEFAULT '',
            description TEXT NULL, teaser TEXT NULL, source TEXT NOT NULL DEFAULT 'default', published INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')");
        $pdo->exec('CREATE TABLE tl_cron_job (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, lastRun INTEGER NOT NULL DEFAULT 0)');
        // wie die DCA-Dateien contao/dca/tl_velundo_token.php und tl_velundo_rate.php
        $pdo->exec("CREATE TABLE tl_velundo_token (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL DEFAULT '', token_hash TEXT NOT NULL DEFAULT '' UNIQUE,
            created INTEGER NOT NULL DEFAULT 0, last_used INTEGER NOT NULL DEFAULT 0, revoked INTEGER NOT NULL DEFAULT 0)");
        $pdo->exec('CREATE TABLE tl_velundo_rate (id INTEGER PRIMARY KEY AUTOINCREMENT, token_id INTEGER NOT NULL DEFAULT 0, window_start INTEGER NOT NULL DEFAULT 0, hits INTEGER NOT NULL DEFAULT 0, UNIQUE (token_id, window_start))');
    }

    public static function data(\PDO $pdo): void
    {
        $h = static fn(string $v, string $u = 'h2'): string => serialize(['value' => $v, 'unit' => $u]);
        $ins = static function (string $table, array $row) use ($pdo): void {
            $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        };
        // Website 1 (deutsch) und Website 8 (englisch)
        $ins('tl_page', ['id' => 1, 'pid' => 0, 'sorting' => 128, 'tstamp' => 1_780_000_000, 'title' => 'Agentur Test', 'type' => 'root', 'language' => 'de', 'dns' => 'www.example.de', 'fallback' => 1, 'published' => 1, 'pageTitle' => 'Agentur Test']);
        $ins('tl_page', ['id' => 2, 'pid' => 1, 'sorting' => 128, 'tstamp' => 1_780_000_000, 'title' => 'Start', 'alias' => 'index', 'published' => 1, 'pageTitle' => 'Willkommen bei Agentur Test', 'description' => 'Startseite der Agentur Test']);
        $ins('tl_page', ['id' => 3, 'pid' => 1, 'sorting' => 256, 'tstamp' => 1_780_000_000, 'title' => 'Leistungen &#40;alle&#41;', 'alias' => 'leistungen', 'published' => 1, 'description' => '']);
        $ins('tl_page', ['id' => 4, 'pid' => 3, 'sorting' => 128, 'tstamp' => 1_780_000_000, 'title' => 'Beratung', 'alias' => 'beratung', 'published' => 0, 'hide' => 1]);
        $ins('tl_page', ['id' => 5, 'pid' => 1, 'sorting' => 384, 'tstamp' => 1_780_000_000, 'title' => 'Impressum', 'alias' => 'impressum', 'published' => 1, 'robots' => 'noindex,follow', 'description' => str_repeat('Lang ', 40)]);
        $ins('tl_page', ['id' => 6, 'pid' => 1, 'sorting' => 512, 'tstamp' => 1_780_000_000, 'title' => 'Start', 'alias' => 'start2', 'published' => 1, 'start' => '1780000000']);
        $ins('tl_page', ['id' => 8, 'pid' => 0, 'sorting' => 256, 'tstamp' => 1_780_000_000, 'title' => 'Agency Test', 'type' => 'root', 'language' => 'en', 'dns' => 'en.example.de', 'published' => 1, 'urlPrefix' => 'en']);
        $ins('tl_page', ['id' => 9, 'pid' => 8, 'sorting' => 128, 'tstamp' => 1_780_000_000, 'title' => 'Services', 'alias' => 'services', 'published' => 1, 'pageTitle' => str_repeat('Very long title ', 5)]);
        // Artikel und Inhaltselemente
        $ins('tl_article', ['id' => 10, 'pid' => 3, 'sorting' => 128, 'tstamp' => 1_780_000_000, 'title' => 'Leistungen', 'alias' => 'leistungen', 'published' => 1]);
        $ins('tl_article', ['id' => 11, 'pid' => 3, 'sorting' => 256, 'tstamp' => 1_780_000_000, 'title' => 'Seitenleiste', 'alias' => 'seitenleiste', 'inColumn' => 'right', 'published' => 0]);
        $ins('tl_content', ['id' => 20, 'pid' => 10, 'ptable' => 'tl_article', 'sorting' => 128, 'tstamp' => 1_780_000_000, 'type' => 'text', 'headline' => $h('Was wir tun'), 'text' => '<p>Wir beraten &amp; bauen <a href="/kontakt">Websites</a>.</p>', 'invisible' => 0]);
        $ins('tl_content', ['id' => 21, 'pid' => 10, 'ptable' => 'tl_article', 'sorting' => 129, 'tstamp' => 1_780_000_000, 'type' => 'text', 'headline' => $h('Entwurf &#40;neu&#41;', 'h3'), 'text' => '<p>Noch geheim[nbsp]und neu.</p>', 'invisible' => 1]);
        $ins('tl_content', ['id' => 22, 'pid' => 10, 'ptable' => '', 'sorting' => 512, 'tstamp' => 1_780_000_000, 'type' => 'image', 'headline' => $h(''), 'invisible' => 0, 'addImage' => 1]);
        $ins('tl_content', ['id' => 23, 'pid' => 11, 'ptable' => 'tl_article', 'sorting' => 128, 'tstamp' => 1_780_000_000, 'type' => 'headline', 'headline' => $h('Kontakt', 'h4'), 'invisible' => 0]);
        // Nachrichten
        $ins('tl_news_archive', ['id' => 1, 'tstamp' => 1_780_000_000, 'title' => 'Aktuelles', 'jumpTo' => 6]);
        $ins('tl_news_archive', ['id' => 2, 'tstamp' => 1_780_000_000, 'title' => 'Presse', 'jumpTo' => 0, 'protected' => 1]);
        $ins('tl_news', ['id' => 30, 'pid' => 1, 'tstamp' => 1_780_000_000, 'headline' => 'Neue Website online', 'alias' => 'neue-website-online', 'date' => 1_788_000_000, 'time' => 1_788_000_000, 'teaser' => '<p>Wir sind live.</p>', 'published' => 1, 'pageTitle' => 'Neu &#60;online>']);
        $ins('tl_news', ['id' => 31, 'pid' => 1, 'tstamp' => 1_780_000_000, 'headline' => 'Entwurf Herbst', 'alias' => 'entwurf-herbst', 'date' => 1_789_000_000, 'time' => 1_789_000_000, 'teaser' => '', 'published' => 0]);
        $ins('tl_news', ['id' => 32, 'pid' => 2, 'tstamp' => 1_780_000_000, 'headline' => 'Pressemitteilung', 'alias' => 'pressemitteilung', 'date' => 1_787_000_000, 'time' => 1_787_000_000, 'published' => 0]);
        $ins('tl_content', ['id' => 40, 'pid' => 30, 'ptable' => 'tl_news', 'sorting' => 128, 'tstamp' => 1_780_000_000, 'type' => 'text', 'headline' => $h(''), 'text' => '<p>Heute ging die Seite online.</p>', 'invisible' => 0]);
        $ins('tl_content', ['id' => 41, 'pid' => 32, 'ptable' => 'tl_news', 'sorting' => 128, 'tstamp' => 1_780_000_000, 'type' => 'text', 'headline' => $h(''), 'text' => '<p>Teil eins</p>', 'invisible' => 0]);
        $ins('tl_content', ['id' => 42, 'pid' => 32, 'ptable' => 'tl_news', 'sorting' => 256, 'tstamp' => 1_780_000_000, 'type' => 'text', 'headline' => $h(''), 'text' => '<p>Teil zwei</p>', 'invisible' => 0]);
        $ins('tl_cron_job', ['name' => 'x', 'lastRun' => 1_791_620_000]);
    }
}
