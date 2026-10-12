<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\Features;
use Velundo\ContaoConnect\Service\PlainText;

/**
 * news_archives – Nachrichtenarchive (nur lesen, nur mit contao/news-bundle).
 */
final class NewsArchivesHandler implements HandlerInterface
{
    public function __construct(
        private readonly Db $db,
        private readonly Features $features,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $this->features->requireNews();
        if ($action !== 'list') {
            throw ApiException::notFound();
        }
        $total = (int)$this->db->value('SELECT COUNT(*) FROM tl_news_archive');
        $limit = $request->limit();
        $offset = $request->offset();
        $rows = $this->db->all(
            'SELECT a.id, a.title, a.jumpTo, a.protected, a.tstamp, (SELECT COUNT(*) FROM tl_news n WHERE n.pid = a.id) AS news_count'
            . ' FROM tl_news_archive a ORDER BY a.title, a.id' . Db::limit($limit, $offset)
        );
        $items = array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'title' => PlainText::decode((string)$r['title']),
            'jumpTo' => (int)$r['jumpTo'],
            'protected' => Db::flag($r['protected']),
            'news_count' => (int)$r['news_count'],
            'tstamp' => Db::iso($r['tstamp']),
        ], $rows);
        return Result::page($items, $total, $limit, $offset);
    }
}
