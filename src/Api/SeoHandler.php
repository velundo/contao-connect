<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Service\Contao\UrlResolverInterface;
use Velundo\ContaoConnect\Service\Db;
use Velundo\ContaoConnect\Service\PageTree;
use Velundo\ContaoConnect\Service\PlainText;

/**
 * GET seo/overview – Befunde über alle veröffentlichten Seiten vom Typ regular (höchstens 100 je Liste).
 * Wirksamer Titel: pageTitle, sonst title.
 */
final class SeoHandler implements HandlerInterface
{
    private const LIST_MAX = 100;
    private const TITLE_MAX = 60;
    private const DESCRIPTION_MAX = 160;

    public function __construct(
        private readonly PageTree $tree,
        private readonly UrlResolverInterface $urls,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        if ($action !== 'overview') {
            throw ApiException::notFound();
        }
        $root = $request->int('root', 0, 1);
        if ($root > 0) {
            $this->tree->root($root);
        }
        $rows = array_values(array_filter($this->tree->all(), static fn(array $r): bool => (string)$r['type'] === 'regular' && Db::flag($r['published'])
            && ($root === 0 || (int)$r['root'] === $root)));

        $lists = ['missing_title' => [], 'missing_description' => [], 'noindex' => [], 'long_title' => [], 'long_description' => []];
        $byTitle = [];
        foreach ($rows as $row) {
            $title = trim(PlainText::decode((string)$row['title']));
            $pageTitle = trim(PlainText::decode((string)$row['pageTitle']));
            $effective = $pageTitle !== '' ? $pageTitle : $title;
            $description = trim(PlainText::decode((string)($row['description'] ?? '')));
            $hits = [];
            if ($pageTitle === '') {
                $hits[] = 'missing_title';
            }
            if (mb_strlen($effective) > self::TITLE_MAX) {
                $hits[] = 'long_title';
            }
            if ($description === '') {
                $hits[] = 'missing_description';
            } elseif (mb_strlen($description) > self::DESCRIPTION_MAX) {
                $hits[] = 'long_description';
            }
            if (str_starts_with((string)$row['robots'], 'noindex')) {
                $hits[] = 'noindex';
            }
            foreach ($hits as $list) {
                if (count($lists[$list]) < self::LIST_MAX) {
                    $lists[$list][] = ['id' => (int)$row['id'], 'title' => $title, 'url' => $this->urls->pageUrl((int)$row['id'])];
                }
            }
            if ($effective !== '') {
                $byTitle[(int)$row['root'] . '|' . mb_strtolower($effective)][] = (int)$row['id'];
            }
        }
        $duplicates = array_values(array_filter($byTitle, static fn(array $ids): bool => count($ids) > 1));
        return Result::ok(['pages_total' => count($rows)] + $lists + ['duplicate_titles' => array_slice($duplicates, 0, self::LIST_MAX)]);
    }
}
