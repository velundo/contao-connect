<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\ChangeSet;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Http\Validate;
use Velundo\ContaoConnect\Service\Contao\UrlResolverInterface;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\PageTree;
use Velundo\ContaoConnect\Service\PlainText;
use Velundo\ContaoConnect\Service\Writer;

/**
 * pages – Seitenbaum lesen, einzelne Seite mit Artikeln lesen und Felder ändern.
 */
final class PagesHandler implements HandlerInterface
{
    public const WRITABLE = ['title', 'pageTitle', 'description', 'robots', 'published', 'hide'];
    public const ROOT_WRITABLE = ['title', 'pageTitle'];
    public const ROBOTS = ['', 'index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'];
    private const TITLE_MAX = 255;
    private const DESCRIPTION_MAX = 5000;

    public function __construct(
        private readonly Db $db,
        private readonly PageTree $tree,
        private readonly UrlResolverInterface $urls,
        private readonly Writer $writer,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        return match ($action) {
            'list' => $this->list($request),
            'get' => Result::ok($this->detail((int)$request->id)),
            'update' => $this->update($request),
            default => throw ApiException::notFound(),
        };
    }

    private function list(ApiRequest $request): Result
    {
        $mode = $request->visibility();
        $depth = $request->int('depth', PageTree::MAX_LEVELS, 1, PageTree::MAX_LEVELS);
        $parent = $request->int('parent', 0, 1);
        $root = $request->int('root', 0, 1);
        if ($parent > 0) {
            $this->tree->get($parent);
            $rows = $this->tree->subtree($parent, $depth);
        } else {
            // ohne parent zählen die Startpunkte als erste Ebene
            $rows = $this->tree->subtree(0, $depth);
        }
        if ($root > 0) {
            $this->tree->root($root);
            $rows = array_values(array_filter($rows, static fn(array $r): bool => (int)$r['root'] === $root));
        }
        $search = mb_strtolower($request->string('search'));
        $rows = array_values(array_filter($rows, static function (array $r) use ($mode, $search): bool {
            if (!Format::matches(Db::flag($r['published']), $mode)) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            foreach (['title', 'pageTitle', 'alias'] as $field) {
                if (str_contains(mb_strtolower(PlainText::decode((string)$r[$field])), $search)) {
                    return true;
                }
            }
            return false;
        }));
        $limit = $request->limit();
        $offset = $request->offset();
        $items = array_map(fn(array $r): array => $this->format($r), array_slice($rows, $offset, $limit));
        return Result::page($items, count($rows), $limit, $offset);
    }

    /**
     * Seite wie GET pages/{id}: Felder plus Artikel.
     *
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        $page = $this->format($this->tree->get($id));
        $articles = $this->db->all(
            'SELECT a.id, a.title, a.alias, a.inColumn, a.published, a.start, a.stop, a.sorting,'
            . " (SELECT COUNT(*) FROM tl_content c WHERE c.pid = a.id AND (c.ptable = 'tl_article' OR c.ptable = '')) AS elements"
            . ' FROM tl_article a WHERE a.pid = ? ORDER BY a.sorting, a.id',
            [$id]
        );
        $page['articles'] = array_map(static fn(array $a): array => [
            'id' => (int)$a['id'],
            'title' => PlainText::decode((string)$a['title']),
            'alias' => (string)$a['alias'],
            'inColumn' => (string)$a['inColumn'],
            'published' => Db::flag($a['published']),
            'start' => Db::iso($a['start']),
            'stop' => Db::iso($a['stop']),
            'sorting' => (int)$a['sorting'],
            'elements' => (int)$a['elements'],
        ], $articles);
        return $page;
    }

    private function update(ApiRequest $request): Result
    {
        $id = (int)$request->id;
        $row = $this->tree->get($id);
        $body = $request->fields(self::WRITABLE);
        if ((string)$row['type'] === 'root') {
            $other = array_values(array_diff(array_keys($body), self::ROOT_WRITABLE));
            if ($other !== []) {
                throw ApiException::validation('Startpunkte einer Website: nur title und pageTitle – veröffentlichen, verbergen und Metadaten nur im Backend.', ['fields' => $other, 'allowed' => self::ROOT_WRITABLE], 'root_page');
            }
        }
        $current = $this->format($row, false);
        $changes = new ChangeSet();
        $title = PlainText::fromBody($body, 'title', self::TITLE_MAX, true);
        $changes->set('title', $title, $current['title'], $title === null ? null : PlainText::encode($title));
        $pageTitle = PlainText::fromBody($body, 'pageTitle', self::TITLE_MAX);
        $changes->set('pageTitle', $pageTitle, $current['pageTitle'], $pageTitle === null ? null : PlainText::encode($pageTitle));
        $description = PlainText::fromBody($body, 'description', self::DESCRIPTION_MAX, false, true);
        $changes->set('description', $description, $current['description'], $description === null ? null : PlainText::encode($description, PlainText::ENCODE_LT));
        $robots = Validate::string($body, 'robots', 32);
        if ($robots !== null && !in_array($robots, self::ROBOTS, true)) {
            throw ApiException::validation('Feld „robots“ muss leer oder index,follow, index,nofollow, noindex,follow bzw. noindex,nofollow sein.', ['field' => 'robots', 'allowed' => self::ROBOTS], 'invalid_value');
        }
        $changes->set('robots', $robots, $current['robots']);
        $published = Validate::bool($body, 'published');
        $changes->flag('published', $published, $current['published']);
        $changes->flag('hide', Validate::bool($body, 'hide'), $current['hide']);
        $changes->requireAny(self::WRITABLE);
        // Zusatzrechte des Tokens: Veröffentlichen und Live-Änderungen an sichtbaren Seiten (SEO-Felder bleiben frei)
        if ($published === true && !$current['published']) {
            $request->require('publish', 'Das Veröffentlichen einer Seite');
        }
        if (($title !== null || $pageTitle !== null) && $current['published'] && $published !== false) {
            $request->require('live_edit', 'Das Ändern von title oder pageTitle einer veröffentlichten Seite');
        }

        $pid = (int)$row['pid'];
        $this->writer->update('tl_page', $id, $changes->data(), 'page', Writer::tags('tl_page', $id, $pid > 0 ? 'tl_page' : '', $pid),
            sprintf('Seite %d geändert (%s)', $id, $changes->fieldList()), $request->tokenName);
        $this->tree->reset();

        $page = $this->detail($id);
        $page['before'] = $changes->before();
        return Result::ok($page);
    }

    /**
     * Felder einer Seite nach Vertrag (Listenform).
     *
     * @param array<string, mixed> $row Zeile aus PageTree (mit root und level)
     * @return array<string, mixed>
     */
    public function format(array $row, bool $withUrl = true): array
    {
        $root = $this->tree->find((int)$row['root']);
        return [
            'id' => (int)$row['id'],
            'pid' => (int)$row['pid'],
            'root' => (int)$row['root'],
            'level' => (int)$row['level'],
            'type' => (string)$row['type'],
            'title' => PlainText::decode((string)$row['title']),
            'alias' => (string)$row['alias'],
            'url' => $withUrl ? $this->urls->pageUrl((int)$row['id']) : null,
            'published' => Db::flag($row['published']),
            'hide' => Db::flag($row['hide']),
            'robots' => (string)$row['robots'],
            'pageTitle' => PlainText::decode((string)$row['pageTitle']),
            'description' => PlainText::decode((string)($row['description'] ?? '')),
            'start' => Db::iso($row['start']),
            'stop' => Db::iso($row['stop']),
            'tstamp' => Db::iso($row['tstamp']),
            'language' => (string)($root['language'] ?? ''),
            'sorting' => (int)$row['sorting'],
        ];
    }
}
