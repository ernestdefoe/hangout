<?php

namespace ErnestDefoe\Hangout;

/**
 * Every piece of furniture and decor: its footprint in floor tiles, whether
 * it blocks walking, whether it hangs on the back wall, and the light it
 * gives off. The browser draws each kind from the art atlas by the same key.
 */
final class Furniture
{
    /** kind => [w, h, solid, wall, light radius in tiles (0 = none), light colour, seat] */
    public const CATALOG = [
        'rug' => [6, 4, false, false, 0, '', false],
        'rug_neon' => [5, 3, false, false, 0, '', false],
        'sofa' => [3, 1, true, false, 0, '', true],
        'armchair' => [1, 1, true, false, 0, '', true],
        'coffee_table' => [2, 1, true, false, 0, '', false],
        'table_round' => [1, 1, true, false, 0, '', false],
        'plant' => [1, 1, true, false, 0, '', false],
        'tall_plant' => [1, 1, true, false, 0, '', false],
        'lamp' => [1, 1, true, false, 4, '#ffc56b', false],
        'bookshelf' => [2, 1, true, false, 0, '', false],
        'cabinet' => [1, 1, true, false, 2, '#7dd3fc', false],
        'pinball' => [1, 2, true, false, 2, '#f472b6', false],
        'claw' => [1, 1, true, false, 2, '#fde047', false],
        'jukebox' => [1, 1, true, false, 3, '#c084fc', false],
        'stool' => [1, 1, false, false, 0, '', true],
        'beanbag' => [1, 1, false, false, 0, '', true],
        'bar' => [5, 1, true, false, 0, '', false],
        'bench' => [2, 1, true, false, 0, '', true],
        'planter' => [2, 1, true, false, 0, '', false],
        'telescope' => [1, 1, true, false, 0, '', false],
        'hammock' => [3, 1, true, false, 0, '', true],
        'deckchair' => [1, 1, false, false, 0, '', true],
        'fountain' => [2, 2, true, false, 2, '#a5f3fc', false],
        'board' => [4, 1, false, true, 0, '', false],
        'window' => [2, 1, false, true, 0, '', false],
        'painting' => [2, 1, false, true, 0, '', false],
        'neon' => [3, 1, false, true, 4, '#f472b6', false],
        'fireplace' => [3, 1, false, true, 5, '#ff9a3c', false],
        'clock' => [1, 1, false, true, 0, '', false],
        'string_lights' => [20, 1, false, true, 3, '#ffe08a', false],
    ];

    public static function has(string $kind): bool
    {
        return isset(self::CATALOG[$kind]);
    }

    /**
     * Which floor tiles are blocked in a room.
     *
     * @param  iterable<object{kind: string, x: int, y: int}>  $items
     * @return array<int, bool> keyed y * 256 + x
     */
    public static function blocked(iterable $items): array
    {
        $blocked = [];
        foreach ($items as $item) {
            [$w, $h, $solid, $wall] = self::CATALOG[$item->kind] ?? [1, 1, false, false];
            if (! $solid || $wall) {
                continue;
            }
            for ($dy = 0; $dy < $h; $dy++) {
                for ($dx = 0; $dx < $w; $dx++) {
                    $blocked[((int) $item->y + $dy) * 256 + (int) $item->x + $dx] = true;
                }
            }
        }

        return $blocked;
    }
}
