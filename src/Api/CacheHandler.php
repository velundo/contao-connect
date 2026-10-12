<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Http\Validate;
use Velundo\ContaoConnect\Service\Writer;

/**
 * POST cache/clear – Cache-Tags einzelner Seiten ({"pages":[id,…]}) und/oder Nachrichten ({"news":[id,…]}) invalidieren.
 * Den gesamten Cache leert die Schnittstelle nicht.
 */
final class CacheHandler implements HandlerInterface
{
    private const MAX_IDS = 200;

    public function __construct(private readonly Writer $writer) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        if ($action !== 'clear') {
            throw ApiException::notFound();
        }
        if (!$this->writer->cacheAvailable()) {
            throw ApiException::featureUnavailable('http_cache');
        }
        $body = $request->fields(['pages', 'news']);
        $pages = Validate::idList($body, 'pages', self::MAX_IDS) ?? [];
        $news = Validate::idList($body, 'news', self::MAX_IDS) ?? [];
        if ($pages === [] && $news === []) {
            throw ApiException::validation('Mindestens eine Seite (pages) oder Nachricht (news) angeben.', ['fields' => ['pages', 'news']], 'empty_body');
        }
        if (count($pages) + count($news) > self::MAX_IDS) {
            throw ApiException::validation(sprintf('Höchstens %d IDs je Aufruf.', self::MAX_IDS), ['max' => self::MAX_IDS], 'too_long');
        }
        $tags = array_merge(
            array_map(static fn(int $id): string => 'contao.db.tl_page.' . $id, $pages),
            array_map(static fn(int $id): string => 'contao.db.tl_news.' . $id, $news)
        );
        $done = $this->writer->invalidate($tags);
        $this->writer->log(sprintf('Cache-Tags invalidiert (%d Seiten, %d Nachrichten)', count($pages), count($news)), $request->tokenName);
        return Result::ok(['tags' => $tags, 'invalidated' => $done, 'before' => new \stdClass()]);
    }
}
