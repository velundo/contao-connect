<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

/**
 * Einsortieren eines neuen Datensatzes wie DC_Table: ans Ende (größtes sorting + 128) oder direkt hinter ein Element
 * (Mitte der Lücke zum nächsten); ohne Lücke werden alle Elemente in 128er-Schritten neu nummeriert.
 */
final class Sorting
{
    public const STEP = 128;

    /**
     * @param array<int, int> $siblings id → sorting der vorhandenen Elemente desselben Elternsatzes
     * @return array{sorting: int, renumber: array<int, int>} sorting des neuen Elements und ggf. neue Werte der anderen
     */
    public static function position(array $siblings, ?int $afterId): array
    {
        asort($siblings);
        if ($afterId === null) {
            return ['sorting' => ($siblings === [] ? 0 : max($siblings)) + self::STEP, 'renumber' => []];
        }
        $after = $siblings[$afterId];
        $next = null;
        foreach ($siblings as $sorting) {
            if ($sorting > $after) {
                $next = $sorting;
                break;
            }
        }
        if ($next === null) {
            return ['sorting' => $after + self::STEP, 'renumber' => []];
        }
        if ($next - $after > 1) {
            return ['sorting' => intdiv($after + $next, 2), 'renumber' => []];
        }
        // Keine Lücke: neu nummerieren, das neue Element bekommt den Platz direkt hinter $afterId
        $renumber = [];
        $value = 0;
        $new = 0;
        foreach (array_keys($siblings) as $id) {
            $value += self::STEP;
            $renumber[$id] = $value;
            if ($id === $afterId) {
                $value += self::STEP;
                $new = $value;
            }
        }
        foreach ($renumber as $id => $v) {
            if ($siblings[$id] === $v) {
                unset($renumber[$id]);
            }
        }
        return ['sorting' => $new, 'renumber' => $renumber];
    }
}
