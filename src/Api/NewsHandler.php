<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\ChangeSet;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Http\Validate;
use Velundo\ContaoConnect\Service\Contao\SluggerInterface;
use Velundo\ContaoConnect\Service\Contao\UrlResolverInterface;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\Features;
use Velundo\ContaoConnect\Service\PlainText;
use Velundo\ContaoConnect\Service\Sorting;
use Velundo\ContaoConnect\Service\TextField;
use Velundo\ContaoConnect\Service\Writer;

/**
 * news – Nachrichten (contao/news-bundle) lesen, anlegen (immer unveröffentlicht) und ändern. Der Nachrichtentext
 * steht in Inhaltselementen mit ptable tl_news; `text` meint das einzige Element vom Typ text.
 */
final class NewsHandler implements HandlerInterface
{
    public const WRITABLE = ['headline', 'teaser', 'text', 'date', 'published', 'pageTitle', 'description'];
    public const CREATABLE = ['archive', 'headline', 'teaser', 'text', 'date', 'pageTitle', 'description'];
    private const COLUMNS = ['id', 'pid', 'headline', 'alias', 'date', 'teaser', 'published', 'featured', 'start', 'stop', 'pageTitle', 'description', 'robots', 'source', 'tstamp'];
    private const HEADLINE_MAX = 255;
    private const DESCRIPTION_MAX = 5000;

    public function __construct(
        private readonly Db $db,
        private readonly Features $features,
        private readonly UrlResolverInterface $urls,
        private readonly TextField $textField,
        private readonly SluggerInterface $slugger,
        private readonly Writer $writer,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $this->features->requireNews();
        return match ($action) {
            'list' => $this->list($request),
            'get' => Result::ok($this->detail((int)$request->id)),
            'create' => $this->create($request),
            'update' => $this->update($request),
            default => throw ApiException::notFound(),
        };
    }

    private function list(ApiRequest $request): Result
    {
        $where = ' FROM tl_news WHERE 1 = 1';
        $params = [];
        $archive = $request->int('archive', 0, 1);
        if ($archive > 0) {
            $this->db->record('tl_news_archive', $archive, ['id'], 'Nachrichtenarchiv');
            $where .= ' AND pid = ?';
            $params[] = $archive;
        }
        $search = $request->string('search');
        if ($search !== '') {
            $where .= " AND (headline LIKE ? ESCAPE '!' OR teaser LIKE ? ESCAPE '!')";
            $params[] = Db::contains($search);
            $params[] = Db::contains($search);
        }
        $where .= Format::visibilitySql('published', $request->visibility());
        $total = (int)$this->db->value('SELECT COUNT(*)' . $where, $params);
        $limit = $request->limit();
        $offset = $request->offset();
        $rows = $this->db->all('SELECT ' . implode(', ', self::COLUMNS) . $where . ' ORDER BY date DESC, id DESC' . Db::limit($limit, $offset), $params);
        return Result::page(array_map(fn(array $r): array => $this->format($r), $rows), $total, $limit, $offset);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $item = $this->format($this->db->record('tl_news', $id, self::COLUMNS, 'Nachricht'));
        $elements = $this->elements($id);
        $texts = array_values(array_filter($elements, static fn(array $e): bool => $e['type'] === 'text'));
        $html = implode("\n", array_map(static fn(array $e): string => PlainText::html((string)($e['text'] ?? '')), $texts));
        $item['content'] = array_map(static function (array $e): array {
            $f = Format::content($e);
            return ['id' => $f['id'], 'type' => $f['type'], 'headline' => $f['headline'], 'invisible' => $f['invisible'], 'sorting' => $f['sorting'], 'text_length' => $f['text_length']];
        }, $elements);
        $item['text'] = $html;
        $item['text_plain'] = PlainText::fromHtml($html, 20000);
        $item['text_element'] = match (count($texts)) {
            0 => 0,
            1 => (int)$texts[0]['id'],
            default => null,
        };
        return $item;
    }

    private function create(ApiRequest $request): Result
    {
        $body = $request->fields(self::CREATABLE);
        $archiveId = Validate::int($body, 'archive', 1);
        if ($archiveId === null) {
            throw ApiException::validation('Feld „archive“ (id des Nachrichtenarchivs) fehlt.', ['field' => 'archive'], 'empty_value');
        }
        $archive = $this->db->record('tl_news_archive', $archiveId, ['id', 'jumpTo'], 'Nachrichtenarchiv');
        $headline = (string)PlainText::fromBody($body, 'headline', self::HEADLINE_MAX, true);
        if (!array_key_exists('headline', $body)) {
            throw ApiException::validation('Feld „headline“ fehlt.', ['field' => 'headline'], 'empty_value');
        }
        $teaser = $this->textField->fromBody($body, 'teaser') ?? '';
        $text = $this->textField->fromBody($body, 'text') ?? '';
        $date = Validate::datetime($body, 'date') ?? $this->writer->now();
        $pageTitle = PlainText::fromBody($body, 'pageTitle', self::HEADLINE_MAX) ?? '';
        $description = PlainText::fromBody($body, 'description', self::DESCRIPTION_MAX, false, true) ?? '';
        $alias = $this->slugger->generate($headline, (int)$archive['jumpTo'], fn(string $alias): bool => $this->db->value('SELECT id FROM tl_news WHERE alias = ?', [$alias]) !== null);

        $id = $this->writer->insert('tl_news', [
            'pid' => $archiveId,
            'headline' => PlainText::encode($headline),
            'alias' => $alias,
            'author' => 0,
            'date' => $date,
            'time' => $date,
            'teaser' => $teaser,
            'published' => 0,
            'source' => 'default',
            'pageTitle' => PlainText::encode($pageTitle, PlainText::ENCODE_LT),
            'description' => PlainText::encode($description, PlainText::ENCODE_LT),
        ], 'news', static fn(int $id): array => Writer::tags('tl_news', $id, 'tl_news_archive', $archiveId),
            sprintf('Nachricht %%id%% angelegt (unveröffentlicht) in Archiv %d', $archiveId), $request->tokenName);

        $textElement = null;
        if ($text !== '') {
            $textElement = $this->insertTextElement($id, $text, $request->tokenName);
        }
        return Result::created(['id' => $id, 'alias' => $alias, 'text_element' => $textElement, 'before' => new \stdClass()]);
    }

    private function update(ApiRequest $request): Result
    {
        $id = (int)$request->id;
        $row = $this->db->record('tl_news', $id, self::COLUMNS, 'Nachricht');
        $body = $request->fields(self::WRITABLE);
        $current = $this->format($row, false);

        $changes = new ChangeSet();
        $headline = PlainText::fromBody($body, 'headline', self::HEADLINE_MAX, true);
        $changes->set('headline', $headline, $current['headline'], $headline === null ? null : PlainText::encode($headline));
        $teaser = $this->textField->fromBody($body, 'teaser');
        $changes->set('teaser', $teaser, $current['teaser']);
        $date = Validate::datetime($body, 'date');
        if ($date !== null) {
            $changes->set('date', $date, $current['date']);
            $changes->set('date', $date, $current['date'], $date, 'time');
        }
        $published = Validate::bool($body, 'published');
        $changes->flag('published', $published, $current['published']);
        $pageTitle = PlainText::fromBody($body, 'pageTitle', self::HEADLINE_MAX);
        $changes->set('pageTitle', $pageTitle, $current['pageTitle'], $pageTitle === null ? null : PlainText::encode($pageTitle, PlainText::ENCODE_LT));
        $description = PlainText::fromBody($body, 'description', self::DESCRIPTION_MAX, false, true);
        $changes->set('description', $description, $current['description'], $description === null ? null : PlainText::encode($description, PlainText::ENCODE_LT));

        // Text: das einzige Element vom Typ text der Nachricht – vor dem Schreiben prüfen (mehrere → 422)
        $text = $this->textField->fromBody($body, 'text');
        $textTarget = null;
        if ($text !== null) {
            $elements = $this->elements($id);
            $texts = array_values(array_filter($elements, static fn(array $e): bool => $e['type'] === 'text'));
            if (count($texts) > 1) {
                throw ApiException::validation('Die Nachricht hat mehrere Textelemente – bitte einzeln über PATCH content/{id} ändern.',
                    ['elements' => array_map(static fn(array $e): int => (int)$e['id'], $texts)], 'ambiguous_text');
            }
            if ($text === '') {
                throw ApiException::validation('Feld „text“ darf nicht leer sein (Textelemente brauchen Text).', ['field' => 'text'], 'empty_value');
            }
            $textTarget = $texts[0] ?? false;
            $changes->external('text', $texts ? PlainText::html((string)($texts[0]['text'] ?? '')) : '');
        }
        $changes->requireAny(self::WRITABLE);
        // Zusatzrechte des Tokens: Veröffentlichen und Live-Änderungen an veröffentlichten Nachrichten
        if ($published === true && !$current['published']) {
            $request->require('publish', 'Das Veröffentlichen einer Nachricht');
        }
        if (($headline !== null || $teaser !== null || $text !== null) && $current['published'] && $published !== false) {
            $request->require('live_edit', 'Das Ändern von headline, teaser oder text einer veröffentlichten Nachricht');
        }

        $archiveId = (int)$row['pid'];
        $this->writer->update('tl_news', $id, $changes->data(), 'news', Writer::tags('tl_news', $id, 'tl_news_archive', $archiveId),
            sprintf('Nachricht %d geändert (%s)', $id, $changes->fieldList()), $request->tokenName);
        if ($text !== null) {
            if ($textTarget === false) {
                $this->insertTextElement($id, $text, $request->tokenName);
            } else {
                $elementId = (int)$textTarget['id'];
                $this->writer->update('tl_content', $elementId, ['text' => $text], 'news', Writer::tags('tl_content', $elementId, 'tl_news', $id),
                    sprintf('Inhaltselement %d der Nachricht %d geändert (text)', $elementId, $id), $request->tokenName);
            }
        }

        $item = $this->detail($id);
        $item['before'] = $changes->before();
        return Result::ok($item);
    }

    /** Neues Textelement (sichtbar innerhalb der Nachricht) am Ende der Elemente; Rückgabe: id. */
    private function insertTextElement(int $newsId, string $text, string $tokenName): int
    {
        $siblings = [];
        foreach ($this->elements($newsId) as $e) {
            $siblings[(int)$e['id']] = (int)$e['sorting'];
        }
        $sorting = Sorting::position($siblings, null)['sorting'];
        return $this->writer->insert('tl_content', [
            'pid' => $newsId,
            'ptable' => 'tl_news',
            'sorting' => $sorting,
            'type' => 'text',
            'headline' => Format::headlineValue('', 'h2'),
            'text' => $text,
            'invisible' => 0,
        ], 'news', static fn(int $id): array => Writer::tags('tl_content', $id, 'tl_news', $newsId),
            sprintf('Inhaltselement %%id%% (text) in Nachricht %d angelegt', $newsId), $tokenName);
    }

    /** @return list<array<string, mixed>> Inhaltselemente der Nachricht in Reihenfolge */
    private function elements(int $newsId): array
    {
        return $this->db->all('SELECT ' . implode(', ', ContentHandler::COLUMNS) . " FROM tl_content WHERE ptable = 'tl_news' AND pid = ? ORDER BY sorting, id", [$newsId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function format(array $row, bool $withUrl = true): array
    {
        $teaser = PlainText::html((string)($row['teaser'] ?? ''));
        return [
            'id' => (int)$row['id'],
            'pid' => (int)$row['pid'],
            'headline' => PlainText::decode((string)$row['headline']),
            'alias' => (string)$row['alias'],
            'date' => Db::iso($row['date']),
            'teaser' => $teaser,
            'teaser_text' => PlainText::fromHtml($teaser, 2000),
            'published' => Db::flag($row['published']),
            'featured' => Db::flag($row['featured']),
            'start' => Db::iso($row['start']),
            'stop' => Db::iso($row['stop']),
            'pageTitle' => PlainText::decode((string)$row['pageTitle']),
            'description' => PlainText::decode((string)($row['description'] ?? '')),
            'robots' => (string)$row['robots'],
            'source' => (string)$row['source'],
            'url' => $withUrl ? $this->urls->newsUrl((int)$row['id']) : null,
            'tstamp' => Db::iso($row['tstamp']),
        ];
    }
}
