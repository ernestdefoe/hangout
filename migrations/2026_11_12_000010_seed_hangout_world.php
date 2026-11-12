<?php

use Illuminate\Database\Schema\Builder;

/*
 * The world a new install starts with: the Lobby, the Arcade, the Lounge and
 * the Rooftop. Admins rename and rearrange them later; this runs once.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        if ($db->table('hangout_rooms')->exists()) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $rooms = [
            'lobby' => ['The Lobby', 'A big, friendly entrance hall. The notice board on the wall carries the newest discussions, and doors lead off to the Arcade, the Lounge and the Rooftop.', ['wall' => 'dusk', 'floor' => 'oak', 'dark' => 0.25, 'windows' => true], true],
            'arcade' => ['The Arcade', 'Rows of humming cabinets and a pinball table, lit mostly by their screens.', ['wall' => 'arcade', 'floor' => 'carpet', 'dark' => 0.62, 'windows' => false], false],
            'lounge' => ['The Lounge', 'Deep sofas, a crackling fire and a bar at the back. A good place to settle in.', ['wall' => 'forest', 'floor' => 'walnut', 'dark' => 0.42, 'windows' => true], false],
            'rooftop' => ['The Rooftop', 'Open sky over the town. The sky follows the forum\'s own clock: sunny by day, stars at night.', ['wall' => 'sky', 'floor' => 'deck', 'dark' => 0, 'outdoor' => true], false],
        ];
        $ids = [];
        $p = 0;
        foreach ($rooms as $slug => [$name, $desc, $look, $default]) {
            $ids[$slug] = $db->table('hangout_rooms')->insertGetId([
                'slug' => $slug, 'name' => $name, 'description' => $desc, 'area' => 'Hangout',
                'width' => 20, 'height' => 11, 'look' => json_encode($look), 'is_default' => $default,
                'position' => $p++, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $exits = [
            ['lobby', 'arcade', 'Arcade', 'n', 2],
            ['lobby', 'rooftop', 'Rooftop stairs', 'n', 17],
            ['lobby', 'lounge', 'Lounge', 'e', 6],
            ['arcade', 'lobby', 'Lobby', 'n', 10],
            ['lounge', 'lobby', 'Lobby', 'w', 6],
            ['rooftop', 'lobby', 'Stairs down', 'w', 8],
        ];
        foreach ($exits as [$from, $to, $label, $side, $pos]) {
            $db->table('hangout_exits')->insert(['room_id' => $ids[$from], 'target_id' => $ids[$to], 'label' => $label, 'side' => $side, 'pos' => $pos]);
        }
        $items = [
            'lobby' => [
                ['board', 8, -1], ['window', 4, -1], ['window', 13, -1], ['clock', 15, -1],
                ['rug', 7, 4], ['fountain', 9, 5], ['sofa', 3, 7], ['coffee_table', 4, 8],
                ['armchair', 14, 7], ['armchair', 16, 7], ['table_round', 15, 8], ['plant', 0, 0], ['plant', 19, 0],
                ['tall_plant', 6, 0], ['tall_plant', 12, 0], ['lamp', 2, 7], ['lamp', 18, 9], ['bench', 1, 3],
            ],
            'arcade' => [
                ['neon', 4, -1], ['neon', 13, -1], ['cabinet', 1, 0], ['cabinet', 2, 0], ['cabinet', 3, 0], ['cabinet', 4, 0],
                ['cabinet', 6, 0], ['cabinet', 14, 0], ['cabinet', 15, 0], ['cabinet', 16, 0], ['cabinet', 17, 0],
                ['claw', 18, 0], ['pinball', 2, 4], ['pinball', 4, 4], ['jukebox', 19, 5], ['rug_neon', 8, 4],
                ['stool', 9, 7], ['stool', 11, 7], ['table_round', 10, 7], ['beanbag', 15, 8], ['beanbag', 17, 8],
            ],
            'lounge' => [
                ['fireplace', 8, -1], ['painting', 3, -1], ['painting', 14, -1], ['window', 17, -1],
                ['bookshelf', 0, 0], ['bookshelf', 2, 0], ['bar', 13, 1], ['stool', 13, 2], ['stool', 15, 2], ['stool', 17, 2],
                ['rug', 7, 3], ['sofa', 7, 6], ['armchair', 6, 4], ['armchair', 12, 4], ['coffee_table', 9, 4],
                ['lamp', 5, 6], ['lamp', 12, 7], ['plant', 19, 10], ['sofa', 2, 9], ['beanbag', 16, 8], ['tall_plant', 11, 0],
            ],
            'rooftop' => [
                ['string_lights', 0, -1], ['planter', 0, 0], ['planter', 18, 0], ['bench', 4, 2], ['bench', 14, 2],
                ['telescope', 10, 1], ['hammock', 15, 6], ['deckchair', 3, 6], ['deckchair', 5, 6], ['table_round', 4, 8],
                ['planter', 9, 10], ['lamp', 1, 5], ['lamp', 18, 9], ['tall_plant', 12, 0],
            ],
        ];
        foreach ($items as $slug => $list) {
            foreach ($list as [$kind, $x, $y]) {
                $db->table('hangout_items')->insert(['room_id' => $ids[$slug], 'kind' => $kind, 'x' => $x, 'y' => $y]);
            }
        }
    },
    'down' => function (Builder $schema) {
        // Dropping the tables removes the world.
    },
];
