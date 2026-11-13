<?php

declare(strict_types=1);

/*
 * Prüft die Schnittstelle einer laufenden Contao-Installation mit dem Bundle über HTTP (CI, Job „Contao …“).
 * Erwartet die Testdaten aus run.sh (Startpunkt, Seite „Start“ mit Artikel, Nachrichtenarchiv) und drei Tokens:
 *
 *   VL_BASE           Endpunktbasis, z. B. http://127.0.0.1:8080/_velundo/v1/
 *   VL_TOKEN          Token ohne Zusatzrechte
 *   VL_TOKEN_PUBLISH  Token mit --allow-publish
 *   VL_TOKEN_FULL     Token mit --allow-publish --allow-live-edit
 *   VL_CONTAO         erwartete Version von Contao (z. B. 5.3 oder 6), optional
 *   VL_DB_DSN         PDO-DSN der Datenbank (mit VL_DB_USER, VL_DB_PASS): prüft tl_log, tl_version und die Speicherform
 *   VL_MODE           api (Standard), https (require_https aktiv: 403 https_required) oder revoked (Token widerrufen)
 *
 *   php Tests/Integration/api-check.php
 */

$base = rtrim((string)getenv('VL_BASE'), '/') . '/';
$token = (string)getenv('VL_TOKEN');
$mode = getenv('VL_MODE') ?: 'api';
if ($base === '/' || $token === '') {
    fwrite(STDERR, "VL_BASE und VL_TOKEN setzen.\n");
    exit(2);
}

$failed = 0;
$passed = 0;

/** @return array{status: int, body: mixed, headers: array<string, string>} */
function call(string $method, string $path, ?string $token, ?array $body = null, array $extraHeaders = []): array
{
    global $base;
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", array_merge($headers, $extraHeaders)),
        'content' => $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : '',
        'ignore_errors' => true,
        'timeout' => 60,
    ]]);
    $raw = @file_get_contents($base . $path, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = 0;
    $parsed = [];
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
            $parsed = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }
    }
    $decoded = $raw === false || $raw === '' ? null : json_decode($raw, true);
    return ['status' => $status, 'body' => $decoded ?? $raw, 'headers' => $parsed];
}

function check(string $name, bool $ok, mixed $detail = null): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        echo "ok      $name\n";
        return;
    }
    $failed++;
    $text = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo "FEHLER  $name: " . mb_substr((string)$text, 0, 1500) . "\n";
}

function errorCode(array $r): string
{
    return is_array($r['body']) ? (string)($r['body']['error']['code'] ?? '') : '';
}

function db(): ?PDO
{
    static $pdo = false;
    if ($pdo === false) {
        $dsn = (string)getenv('VL_DB_DSN');
        $pdo = $dsn === '' ? null : new PDO($dsn, getenv('VL_DB_USER') ?: null, getenv('VL_DB_PASS') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    return $pdo;
}

function scalar(string $sql, array $params = []): mixed
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

// Ohne gültiges Token antwortet jeder Pfad mit 401 – in jedem Modus
$r = call('GET', 'info', null);
check('ohne Token 401 unauthorized', $r['status'] === 401 && errorCode($r) === 'unauthorized', $r);
check('Kopf X-Velundo-Api: 1 und Cache-Control: no-store', ($r['headers']['x-velundo-api'] ?? '') === '1' && str_contains($r['headers']['cache-control'] ?? '', 'no-store'), $r['headers']);
$r = call('GET', 'info', 'falsch-' . bin2hex(random_bytes(8)));
check('falsches Token 401', $r['status'] === 401, $r);

if ($mode === 'revoked') {
    $r = call('GET', 'info', $token);
    check('widerrufenes Token 401', $r['status'] === 401 && errorCode($r) === 'unauthorized', $r);
} elseif ($mode === 'https') {
    $r = call('GET', 'info', $token);
    check('gültiges Token über http mit require_https: 403 https_required', $r['status'] === 403 && errorCode($r) === 'https_required', $r);
} else {
    runApiChecks($token);
}

echo sprintf("%d Prüfungen bestanden, %d fehlgeschlagen (%s)\n", $passed, $failed, $mode);
exit($failed === 0 ? 0 : 1);

function runApiChecks(string $token): void
{
    $publish = (string)getenv('VL_TOKEN_PUBLISH');
    $full = (string)getenv('VL_TOKEN_FULL');
    $composer = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
    $version = $composer['extra']['velundo-version'];
    $logsBefore = db() ? (int)scalar("SELECT COUNT(*) FROM tl_log WHERE text LIKE 'velundo:%'") : 0;

    // --- Lesen
    $r = call('GET', 'info', $token);
    $info = is_array($r['body']) ? $r['body'] : [];
    check('GET info 200', $r['status'] === 200, $r);
    check("GET info nennt das Bundle $version", ($info['bundle'] ?? null) === $version, $info['bundle'] ?? null);
    $expect = (string)getenv('VL_CONTAO');
    check('GET info nennt Contao ' . ($expect ?: '(beliebig)'), preg_match('/^\d+\.\d+/', (string)($info['contao'] ?? '')) === 1
        && ($expect === '' || str_starts_with((string)$info['contao'], $expect . '.')), $info['contao'] ?? null);
    check('GET info nennt PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, str_starts_with((string)($info['php'] ?? ''), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.'), $info['php'] ?? null);
    $rootId = (int)($info['roots'][0]['id'] ?? 0);
    check('GET info: ein Startpunkt', $rootId > 0 && count($info['roots']) === 1, $info['roots'] ?? null);
    check('GET info: Nachrichten erkannt', ($info['features']['news'] ?? false) === true, $info['features'] ?? null);
    check('GET info: Token ohne Zusatzrechte', ($info['token']['scopes'] ?? null) === [] && ($info['token']['name'] ?? '') === 'CI ohne Rechte', $info['token'] ?? null);
    $r = call('GET', 'info', $full);
    check('GET info: Token mit allen Zusatzrechten', is_array($r['body']) && ($r['body']['token']['scopes'] ?? null) === ['publish', 'live_edit'], $r['body']['token'] ?? $r);
    $r = call('GET', 'info', null, null, ['X-Velundo-Token: ' . $token]);
    check('Token ersatzweise im Kopf X-Velundo-Token', $r['status'] === 200, $r);

    $r = call('GET', 'system', $token);
    $packages = array_column(is_array($r['body']) ? ($r['body']['packages'] ?? []) : [], 'name');
    check('GET system: Umgebung prod, Pakete mit Bundle', $r['status'] === 200 && ($r['body']['environment'] ?? '') === 'prod'
        && in_array('velundo/contao-connect', $packages, true) && in_array('contao/news-bundle', $packages, true), $r);

    $r = call('GET', 'pages', $token);
    $items = is_array($r['body']) ? ($r['body']['items'] ?? []) : [];
    $start = [];
    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'regular') {
            $start = $item;
        }
    }
    $pageId = (int)($start['id'] ?? 0);
    check('GET pages: Startpunkt und Seite „Start“', $r['status'] === 200 && count($items) === 2 && (int)($items[0]['id'] ?? 0) === $rootId && $pageId > 0, $r);
    check('GET pages: absolute Adresse über den Content-URL-Generator', str_starts_with((string)($start['url'] ?? ''), 'http'), $start);
    $r = call('GET', "pages/$pageId", $token);
    $articleId = (int)($r['body']['articles'][0]['id'] ?? 0);
    check('GET pages/{id} mit Artikel', $r['status'] === 200 && $articleId > 0, $r);
    $r = call('GET', "articles?page=$pageId", $token);
    check('GET articles', $r['status'] === 200 && (int)($r['body']['items'][0]['id'] ?? 0) === $articleId, $r);
    $r = call('GET', 'pages/999999', $token);
    check('unbekannte Seite 404 not_found', $r['status'] === 404 && errorCode($r) === 'not_found', $r);
    $r = call('DELETE', "pages/$pageId", $token);
    check('DELETE 404 method_not_allowed', $r['status'] === 404 && errorCode($r) === 'method_not_allowed', $r);

    // --- Seiten schreiben
    $r = call('PATCH', "pages/$pageId", $token, ['description' => 'CI Beschreibung', 'robots' => 'noindex,follow']);
    check('PATCH pages: Beschreibung und robots ohne Zusatzrecht, mit before', $r['status'] === 200 && ($r['body']['description'] ?? '') === 'CI Beschreibung'
        && ($r['body']['robots'] ?? '') === 'noindex,follow' && is_array($r['body']['before'] ?? null) && array_key_exists('description', $r['body']['before']), $r);
    $r = call('PATCH', "pages/$pageId", $token, ['title' => 'CI Titel']);
    check('PATCH pages: Titel einer veröffentlichten Seite ohne live_edit 403 scope_live_edit', $r['status'] === 403 && errorCode($r) === 'scope_live_edit', $r);
    $title = 'CI (Titel) & „Co“ <b>';
    $r = call('PATCH', "pages/$pageId", $full, ['title' => $title]);
    check('PATCH pages: Titel mit Sonderzeichen mit live_edit, Klartext zurück', $r['status'] === 200 && ($r['body']['title'] ?? '') === $title, $r);
    $r = call('PATCH', "pages/$rootId", $full, ['description' => 'x']);
    check('PATCH pages: Startpunkt nur Titel 422 root_page', $r['status'] === 422 && errorCode($r) === 'root_page', $r);
    $r = call('PATCH', "pages/$pageId", $token, ['robots' => 'kaputt']);
    check('PATCH pages: robots unbekannt 422 invalid_value', $r['status'] === 422 && errorCode($r) === 'invalid_value', $r);
    $r = call('PATCH', "pages/$pageId", $token, ['published' => false]);
    check('PATCH pages: zurückziehen ohne Zusatzrecht', $r['status'] === 200 && ($r['body']['published'] ?? null) === false, $r);
    $r = call('PATCH', "pages/$pageId", $token, ['published' => true]);
    check('PATCH pages: veröffentlichen ohne publish 403 scope_publish', $r['status'] === 403 && errorCode($r) === 'scope_publish', $r);
    $r = call('PATCH', "pages/$pageId", $publish, ['published' => true]);
    check('PATCH pages: veröffentlichen mit publish', $r['status'] === 200 && ($r['body']['published'] ?? null) === true, $r);

    // --- Inhaltselemente
    $r = call('POST', 'content', $token, ['article' => $articleId, 'type' => 'text', 'headline' => 'CI Entwurf', 'text' => '<p>Hallo <strong>Welt</strong></p><script>alert(1)</script><p onclick="x()">Zwei</p>']);
    $id = (int)($r['body']['id'] ?? 0);
    check('POST content: Entwurf 201', $r['status'] === 201 && $id > 0, $r);
    $r = call('GET', "content/$id", $token);
    $text = (string)($r['body']['text'] ?? '');
    check('GET content/{id}: unsichtbar, HTML bereinigt, Elternsatz', $r['status'] === 200 && ($r['body']['invisible'] ?? null) === true
        && str_contains($text, '<strong>Welt</strong>') && !str_contains($text, '<script') && !str_contains($text, 'onclick')
        && ($r['body']['parent']['table'] ?? '') === 'tl_article', $r);
    $r = call('POST', 'content', $token, ['article' => $articleId, 'type' => 'headline', 'headline' => 'CI Zweites', 'after' => $id]);
    $second = (int)($r['body']['id'] ?? 0);
    check('POST content mit after 201', $r['status'] === 201 && $second > 0, $r);
    $r = call('GET', "content?article=$articleId", $token);
    $order = array_column(is_array($r['body']) ? ($r['body']['items'] ?? []) : [], 'id');
    check('GET content: Reihenfolge nach after', $r['status'] === 200 && array_search($second, $order, true) === array_search($id, $order, true) + 1, $order ?: $r);
    $r = call('POST', 'content', $token, ['article' => $articleId, 'type' => 'text', 'text' => '<p>x</p>', 'invisible' => false]);
    check('POST content: Feld invisible 422 unknown_field', $r['status'] === 422 && errorCode($r) === 'unknown_field', $r);
    $r = call('PATCH', "content/$id", $token, ['headline' => 'CI Entwurf geändert']);
    check('PATCH content: unsichtbares Element ohne Zusatzrecht', $r['status'] === 200 && ($r['body']['headline'] ?? '') === 'CI Entwurf geändert', $r);
    $r = call('PATCH', "content/$id", $token, ['invisible' => false]);
    check('PATCH content: sichtbar schalten ohne publish 403 scope_publish', $r['status'] === 403 && errorCode($r) === 'scope_publish', $r);
    $r = call('PATCH', "content/$id", $publish, ['invisible' => false]);
    check('PATCH content: sichtbar schalten mit publish', $r['status'] === 200 && ($r['body']['invisible'] ?? null) === false, $r);
    $r = call('PATCH', "content/$id", $publish, ['headline' => 'CI live']);
    check('PATCH content: sichtbares Element ohne live_edit 403 scope_live_edit', $r['status'] === 403 && errorCode($r) === 'scope_live_edit', $r);
    $r = call('PATCH', "content/$id", $full, ['headline' => 'CI live']);
    check('PATCH content: sichtbares Element mit live_edit', $r['status'] === 200 && ($r['body']['headline'] ?? '') === 'CI live', $r);
    $r = call('PATCH', "content/$id", $full, ['text' => '<p>{{env::path}}</p>']);
    check('PATCH content: Insert-Tag außerhalb der Verweise 422 insert_tag_not_allowed', $r['status'] === 422 && errorCode($r) === 'insert_tag_not_allowed', $r);

    // --- Suche und SEO
    $r = call('GET', 'search?q=CI+live', $token);
    check('GET search findet das Inhaltselement', $r['status'] === 200 && in_array($id, array_column($r['body']['content'] ?? [], 'id'), true), $r);
    $r = call('GET', 'seo/overview', $token);
    check('GET seo/overview', $r['status'] === 200 && ($r['body']['pages_total'] ?? 0) >= 1, $r);

    // --- Nachrichten
    $r = call('GET', 'news_archives', $token);
    $archive = (int)($r['body']['items'][0]['id'] ?? 0);
    check('GET news_archives', $r['status'] === 200 && $archive > 0, $r);
    $r = call('POST', 'news', $token, ['archive' => $archive, 'headline' => 'CI Nachricht', 'teaser' => '<p>Kurz</p>', 'text' => '<p>Text</p>', 'date' => '2026-11-01T10:00:00+01:00']);
    $news = (int)($r['body']['id'] ?? 0);
    check('POST news: unveröffentlicht 201 mit Alias', $r['status'] === 201 && $news > 0 && ($r['body']['alias'] ?? '') !== '', $r);
    $r = call('GET', "news/$news", $token);
    check('GET news/{id}: unveröffentlicht, Text als Inhaltselement', $r['status'] === 200 && ($r['body']['published'] ?? null) === false
        && is_int($r['body']['text_element'] ?? null) && $r['body']['text_element'] > 0 && str_contains((string)($r['body']['text'] ?? ''), 'Text'), $r);
    $r = call('PATCH', "news/$news", $token, ['published' => true]);
    check('PATCH news: veröffentlichen ohne publish 403 scope_publish', $r['status'] === 403 && errorCode($r) === 'scope_publish', $r);
    $r = call('PATCH', "news/$news", $publish, ['published' => true]);
    check('PATCH news: veröffentlichen mit publish', $r['status'] === 200 && ($r['body']['published'] ?? null) === true, $r);
    $r = call('POST', 'news', $token, ['archive' => $archive, 'headline' => 'CI', 'date' => '1.11.2026']);
    check('POST news: Datum nicht nach ISO 8601 422 invalid_type', $r['status'] === 422 && errorCode($r) === 'invalid_type', $r);
    $r = call('GET', "news?archive=$archive", $token);
    check('GET news listet die Nachricht', $r['status'] === 200 && in_array($news, array_column($r['body']['items'] ?? [], 'id'), true), $r);

    // --- Cache: je nach HTTP-Cache der Installation
    $r = call('POST', 'cache/clear', $token, ['pages' => [$pageId]]);
    $httpCache = ($info['features']['http_cache'] ?? null) === true;
    check('POST cache/clear passend zu features.http_cache (' . ($httpCache ? 'an' : 'aus') . ')', $httpCache
        ? $r['status'] === 200 && in_array("contao.db.tl_page.$pageId", $r['body']['tags'] ?? [], true)
        : $r['status'] === 404 && errorCode($r) === 'feature_unavailable', $r);

    // --- Datenbank: Protokoll, Versionen, Speicherform
    if (db()) {
        $logs = (int)scalar("SELECT COUNT(*) FROM tl_log WHERE text LIKE 'velundo:%'");
        $leak = (int)scalar("SELECT COUNT(*) FROM tl_log WHERE text LIKE 'velundo:%' AND (text LIKE '%Hallo%' OR text LIKE '%CI Beschreibung%')");
        check('tl_log: Änderungen protokolliert, ohne Inhalte', $logs - $logsBefore >= 10 && $leak === 0, "neu $logs - $logsBefore, mit Inhalt $leak");
        $before = $logs;
        call('GET', "pages/$pageId", $token);
        call('GET', 'search?q=CI', $token);
        check('tl_log: Lesezugriffe nicht protokolliert', (int)scalar("SELECT COUNT(*) FROM tl_log WHERE text LIKE 'velundo:%'") === $before, 'Lesezugriffe im Protokoll');
        $rejected = (int)scalar("SELECT COUNT(*) FROM tl_log WHERE text LIKE 'velundo:%' AND text LIKE '%→ 4%'");
        check('tl_log: abgelehnte Schreibversuche protokolliert', $rejected >= 1, "abgelehnt $rejected");
        $versions = (int)scalar("SELECT COUNT(*) FROM tl_version WHERE username = 'velundo' AND fromTable IN ('tl_page', 'tl_content', 'tl_news')");
        check('tl_version: Versionen mit Benutzer „velundo“', $versions >= 4, "Versionen $versions");
        $stored = (string)scalar('SELECT title FROM tl_page WHERE id = ?', [$pageId]);
        $contao6 = version_compare((string)($info['contao'] ?? '0'), '6.0', '>=');
        check('Speicherform des Titels wie im Backend (' . ($contao6 ? 'Contao 6 roh' : 'Contao 5 kodiert') . ')', $contao6
            ? $stored === $title
            : str_contains($stored, '&#40;') && !str_contains($stored, '<b>'), $stored);
    }
}
