<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\ChangeSet;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Http\Validate;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\Features;
use Velundo\ContaoConnect\Service\PlainText;
use Velundo\ContaoConnect\Service\Sorting;
use Velundo\ContaoConnect\Service\TextField;
use Velundo\ContaoConnect\Service\Writer;

/**
 * content – Inhaltselemente eines Artikels oder einer Nachricht lesen, anlegen (immer unsichtbar) und ändern.
 */
final class ContentHandler implements HandlerInterface
{
    public const WRITABLE = ['headline', 'headline_unit', 'text', 'invisible'];
    public const CREATABLE = ['article', 'news', 'type', 'headline', 'headline_unit', 'text', 'after'];
    public const TYPES = ['text', 'headline'];
    public const COLUMNS = ['id', 'pid', 'ptable', 'type', 'headline', 'text', 'invisible', 'start', 'stop', 'sorting', 'tstamp', 'addImage'];
    private const HEADLINE_MAX = 255;

    public function __construct(
        private readonly Db $db,
        private readonly Features $features,
        private readonly TextField $textField,
        private readonly Writer $writer,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
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
        [$ptable, $pid] = $this->parentFromQuery($request);
        $where = ' FROM tl_content WHERE pid = ? AND ' . self::ptableSql($ptable) . Format::visibilitySql('invisible', $request->visibility(), true);
        $total = (int)$this->db->value('SELECT COUNT(*)' . $where, [$pid]);
        $limit = $request->limit();
        $offset = $request->offset();
        $rows = $this->db->all('SELECT ' . implode(', ', self::COLUMNS) . $where . ' ORDER BY sorting, id' . Db::limit($limit, $offset), [$pid]);
        return Result::page(array_map([Format::class, 'content'], $rows), $total, $limit, $offset);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $row = $this->db->record('tl_content', $id, self::COLUMNS, 'Inhaltselement');
        $item = Format::content($row);
        $html = PlainText::html((string)($row['text'] ?? ''));
        $item['text'] = $html;
        $item['link_count'] = preg_match_all('/<a\s[^>]*href\s*=/i', $html);
        $item['image'] = Db::flag($row['addImage'] ?? 0);
        $item['parent'] = $this->parent($item['ptable'], (int)$row['pid']);
        return $item;
    }

    private function create(ApiRequest $request): Result
    {
        $body = $request->fields(self::CREATABLE);
        $article = Validate::int($body, 'article', 1);
        $news = Validate::int($body, 'news', 1);
        if (($article === null) === ($news === null)) {
            throw ApiException::validation('Genau eines der Felder „article“ oder „news“ angeben.', ['fields' => ['article', 'news']], 'validation_failed');
        }
        if ($article !== null) {
            $this->db->record('tl_article', $article, ['id'], 'Artikel');
            [$ptable, $pid, $module] = ['tl_article', $article, 'article'];
        } else {
            $this->features->requireNews();
            $this->db->record('tl_news', (int)$news, ['id'], 'Nachricht');
            [$ptable, $pid, $module] = ['tl_news', (int)$news, 'news'];
        }
        $type = Validate::string($body, 'type', 64) ?? 'text';
        if (!in_array($type, self::TYPES, true)) {
            throw ApiException::validation('Feld „type“ muss text oder headline sein.', ['field' => 'type', 'allowed' => self::TYPES], 'invalid_value');
        }
        $headline = PlainText::fromBody($body, 'headline', self::HEADLINE_MAX) ?? '';
        $unit = self::unit($body) ?? 'h2';
        if ($type === 'headline' && array_key_exists('text', $body)) {
            throw ApiException::validation('Elemente vom Typ headline haben keinen Text.', ['field' => 'text'], 'unsupported_type');
        }
        $text = $type === 'text' ? ($this->textField->fromBody($body, 'text') ?? '') : null;
        if ($type === 'text' && $text === '') {
            throw ApiException::validation('Feld „text“ fehlt (Elemente vom Typ text brauchen Text).', ['field' => 'text'], 'empty_value');
        }
        if ($type === 'headline' && $headline === '') {
            throw ApiException::validation('Feld „headline“ fehlt (Elemente vom Typ headline brauchen eine Überschrift).', ['field' => 'headline'], 'empty_value');
        }

        $siblings = [];
        foreach ($this->db->all('SELECT id, sorting FROM tl_content WHERE pid = ? AND ' . self::ptableSql($ptable) . ' ORDER BY sorting, id', [$pid]) as $s) {
            $siblings[(int)$s['id']] = (int)$s['sorting'];
        }
        $after = Validate::int($body, 'after', 1);
        if ($after !== null && !isset($siblings[$after])) {
            throw ApiException::validation('Das Element aus „after“ gehört nicht zu diesem Artikel bzw. dieser Nachricht.', ['field' => 'after', 'parent' => $ptable . '.' . $pid], 'after_mismatch');
        }
        $position = Sorting::position($siblings, $after);
        if ($position['renumber'] !== []) {
            $this->writer->resort('tl_content', $position['renumber']);
        }
        $data = [
            'pid' => $pid,
            'ptable' => $ptable,
            'sorting' => $position['sorting'],
            'type' => $type,
            'headline' => Format::headlineValue(PlainText::encode($headline), $unit),
            'invisible' => 1,
        ];
        if ($text !== null) {
            $data['text'] = $text;
        }
        $id = $this->writer->insert('tl_content', $data, $module, static fn(int $id): array => Writer::tags('tl_content', $id, $ptable, $pid),
            sprintf('Inhaltselement %%id%% angelegt (%s, unsichtbar) in %s.%d', $type, $ptable, $pid), $request->tokenName);
        return Result::created(['id' => $id, 'sorting' => $position['sorting'], 'before' => new \stdClass()]);
    }

    private function update(ApiRequest $request): Result
    {
        $id = (int)$request->id;
        $row = $this->db->record('tl_content', $id, self::COLUMNS, 'Inhaltselement');
        $body = $request->fields(self::WRITABLE);
        $type = (string)$row['type'];
        $touchesHeadline = array_key_exists('headline', $body) || array_key_exists('headline_unit', $body);
        if (($touchesHeadline && !in_array($type, self::TYPES, true)) || (array_key_exists('text', $body) && $type !== 'text')) {
            throw ApiException::validation(sprintf('Elemente vom Typ „%s“ ändert die Schnittstelle nur in der Sichtbarkeit (headline nur bei text und headline, text nur bei text).', $type),
                ['type' => $type], 'unsupported_type');
        }
        $item = Format::content($row);
        [$storedHeadline, $oldUnit] = Format::headlineParts($row['headline'] ?? '');
        $headline = PlainText::fromBody($body, 'headline', self::HEADLINE_MAX);
        $unit = self::unit($body);
        if ($type === 'headline' && $headline === '') {
            throw ApiException::validation('Elemente vom Typ headline brauchen eine Überschrift.', ['field' => 'headline'], 'empty_value');
        }
        $newHeadline = Format::headlineValue($headline !== null ? PlainText::encode($headline) : $storedHeadline, $unit ?? $oldUnit);
        $changes = new ChangeSet();
        $changes->set('headline', $headline, $item['headline'], $newHeadline);
        $changes->set('headline_unit', $unit, $oldUnit, $newHeadline, 'headline');
        $text = $this->textField->fromBody($body, 'text');
        if ($text === '') {
            throw ApiException::validation('Feld „text“ darf nicht leer sein.', ['field' => 'text'], 'empty_value');
        }
        $changes->set('text', $text, PlainText::html((string)($row['text'] ?? '')));
        $changes->flag('invisible', Validate::bool($body, 'invisible'), $item['invisible']);
        $changes->requireAny(self::WRITABLE);

        $ptable = $item['ptable'];
        $this->writer->update('tl_content', $id, $changes->data(), $ptable === 'tl_news' ? 'news' : 'article', Writer::tags('tl_content', $id, $ptable, (int)$row['pid']),
            sprintf('Inhaltselement %d geändert (%s)', $id, $changes->fieldList()), $request->tokenName);

        $result = $this->detail($id);
        $result['before'] = $changes->before();
        return Result::ok($result);
    }

    /**
     * Elternsatz aus ?article= bzw. ?news= (genau einer).
     *
     * @return array{0: string, 1: int}
     */
    private function parentFromQuery(ApiRequest $request): array
    {
        $article = $request->int('article', 0, 1);
        $news = $request->int('news', 0, 1);
        if (($article > 0) === ($news > 0)) {
            throw ApiException::validation('Genau einen der Parameter „article“ oder „news“ angeben.', ['parameters' => ['article', 'news']]);
        }
        if ($article > 0) {
            $this->db->record('tl_article', $article, ['id'], 'Artikel');
            return ['tl_article', $article];
        }
        $this->features->requireNews();
        $this->db->record('tl_news', $news, ['id'], 'Nachricht');
        return ['tl_news', $news];
    }

    /** @return array<string, mixed> */
    private function parent(string $ptable, int $pid): array
    {
        if ($ptable === 'tl_article') {
            $a = $this->db->one('SELECT id, pid, title FROM tl_article WHERE id = ?', [$pid]);
            return ['table' => $ptable, 'id' => $pid, 'title' => $a ? PlainText::decode((string)$a['title']) : null, 'page' => $a ? (int)$a['pid'] : null];
        }
        if ($ptable === 'tl_news' && $this->features->news()) {
            $n = $this->db->one('SELECT id, pid, headline FROM tl_news WHERE id = ?', [$pid]);
            return ['table' => $ptable, 'id' => $pid, 'title' => $n ? PlainText::decode((string)$n['headline']) : null, 'archive' => $n ? (int)$n['pid'] : null];
        }
        return ['table' => $ptable, 'id' => $pid, 'title' => null];
    }

    /** SQL-Bedingung für ptable (leer = tl_article aus Contao-4-Beständen). */
    public static function ptableSql(string $ptable): string
    {
        return $ptable === 'tl_article' ? "(ptable = 'tl_article' OR ptable = '')" : "ptable = 'tl_news'";
    }

    /** @param array<string, mixed> $body */
    private static function unit(array $body): ?string
    {
        $unit = Validate::string($body, 'headline_unit', 2);
        if ($unit !== null && !in_array($unit, Format::HEADLINE_UNITS, true)) {
            throw ApiException::validation('Feld „headline_unit“ muss h1 bis h6 sein.', ['field' => 'headline_unit', 'allowed' => Format::HEADLINE_UNITS], 'invalid_value');
        }
        return $unit;
    }
}
