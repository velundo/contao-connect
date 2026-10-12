<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\PageTree;
use Velundo\ContaoConnect\Service\PlainText;

/**
 * articles – Artikel einer Seite (nur lesen).
 */
final class ArticlesHandler implements HandlerInterface
{
    public function __construct(
        private readonly Db $db,
        private readonly PageTree $tree,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        if ($action !== 'list') {
            throw ApiException::notFound();
        }
        $page = $request->int('page', 0, 1);
        if ($page <= 0) {
            throw ApiException::validation('Parameter „page“ (id der Seite) fehlt.', ['parameter' => 'page']);
        }
        $this->tree->get($page);
        $where = ' FROM tl_article a WHERE a.pid = ?' . Format::visibilitySql('a.published', $request->visibility());
        $total = (int)$this->db->value('SELECT COUNT(*)' . $where, [$page]);
        $limit = $request->limit();
        $offset = $request->offset();
        $rows = $this->db->all(
            'SELECT a.id, a.pid, a.title, a.alias, a.inColumn, a.published, a.start, a.stop, a.sorting, a.tstamp,'
            . " (SELECT COUNT(*) FROM tl_content c WHERE c.pid = a.id AND (c.ptable = 'tl_article' OR c.ptable = '')) AS elements"
            . $where . ' ORDER BY a.sorting, a.id' . Db::limit($limit, $offset),
            [$page]
        );
        $items = array_map(static fn(array $a): array => [
            'id' => (int)$a['id'],
            'pid' => (int)$a['pid'],
            'title' => PlainText::decode((string)$a['title']),
            'alias' => (string)$a['alias'],
            'inColumn' => (string)$a['inColumn'],
            'published' => Db::flag($a['published']),
            'start' => Db::iso($a['start']),
            'stop' => Db::iso($a['stop']),
            'sorting' => (int)$a['sorting'],
            'tstamp' => Db::iso($a['tstamp']),
            'elements' => (int)$a['elements'],
        ], $rows);
        return Result::page($items, $total, $limit, $offset);
    }
}
