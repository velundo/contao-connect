<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Service\Contao\UrlResolverInterface;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\Features;
use Velundo\ContaoConnect\Service\PageTree;
use Velundo\ContaoConnect\Service\PlainText;

/**
 * GET search?q= – Seiten (title, pageTitle), Inhaltselemente (headline, text) und Nachrichten (headline, teaser).
 * Mit ?root= nur Seiten dieser Website und Inhaltselemente in deren Artikeln.
 */
final class SearchHandler implements HandlerInterface
{
    public function __construct(
        private readonly Db $db,
        private readonly PageTree $tree,
        private readonly Features $features,
        private readonly UrlResolverInterface $urls,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $term = $request->string('q');
        if (mb_strlen($term) < 2 || mb_strlen($term) > 200) {
            throw ApiException::validation('Parameter „q“ muss 2 bis 200 Zeichen lang sein.', ['parameter' => 'q']);
        }
        $limit = $request->limit(20, 100);
        $mode = $request->visibility();
        $root = $request->int('root', 0, 1);
        if ($root > 0) {
            $this->tree->root($root);
        }
        $needle = mb_strtolower($term);
        $pageIds = [];
        $pages = [];
        foreach ($this->tree->all() as $row) {
            if ($root > 0 && (int)$row['root'] !== $root) {
                continue;
            }
            $pageIds[] = (int)$row['id'];
            if (count($pages) >= $limit || !Format::matches(Db::flag($row['published']), $mode)) {
                continue;
            }
            $hay = mb_strtolower(PlainText::decode((string)$row['title']) . "\n" . PlainText::decode((string)$row['pageTitle']));
            if (str_contains($hay, $needle)) {
                $pages[] = ['id' => (int)$row['id'], 'title' => PlainText::decode((string)$row['title']), 'url' => $this->urls->pageUrl((int)$row['id']), 'published' => Db::flag($row['published'])];
            }
        }

        $like = Db::contains($term);
        $sql = 'SELECT c.id, c.pid, c.ptable, c.type, c.headline, c.text, c.invisible, c.start, c.stop, c.sorting, c.tstamp FROM tl_content c'
            . " WHERE (c.headline LIKE ? ESCAPE '!' OR c.text LIKE ? ESCAPE '!')" . Format::visibilitySql('c.invisible', $mode, true);
        $params = [$like, $like];
        if ($root > 0) {
            $sql .= " AND (c.ptable = 'tl_article' OR c.ptable = '') AND c.pid IN (SELECT a.id FROM tl_article a WHERE a.pid IN (" . Db::placeholders(count($pageIds)) . '))';
            $params = array_merge($params, $pageIds ?: [0]);
        }
        $content = [];
        foreach ($this->db->all($sql . ' ORDER BY c.pid, c.sorting' . Db::limit($limit), $params) as $row) {
            $f = Format::content($row);
            $content[] = ['id' => $f['id'], 'pid' => $f['pid'], 'ptable' => $f['ptable'], 'type' => $f['type'], 'headline' => $f['headline'],
                'text_plain' => PlainText::fromHtml((string)($row['text'] ?? ''), 300), 'invisible' => $f['invisible']];
        }

        $news = [];
        if ($this->features->news() && $root === 0) {
            $rows = $this->db->all("SELECT id, headline, published FROM tl_news WHERE (headline LIKE ? ESCAPE '!' OR teaser LIKE ? ESCAPE '!')"
                . Format::visibilitySql('published', $mode) . ' ORDER BY date DESC, id DESC' . Db::limit($limit), [$like, $like]);
            foreach ($rows as $row) {
                $news[] = ['id' => (int)$row['id'], 'headline' => PlainText::decode((string)$row['headline']), 'url' => $this->urls->newsUrl((int)$row['id']), 'published' => Db::flag($row['published'])];
            }
        }
        return Result::ok(['q' => $term, 'pages' => $pages, 'content' => $content, 'news' => $news]);
    }
}
