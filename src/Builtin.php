<?php

namespace ErnestDefoe\Hangout;

use Flarum\Locale\TranslatorInterface;

/**
 * The world a new install starts with (the seed migration writes the same
 * English): its room names, descriptions and door labels are shown in the
 * forum's language while they're still the seeded text. Anything an admin
 * renames or adds is shown as typed.
 */
final class Builtin
{
    private const ROOMS = [
        'lobby' => ['The Lobby', 'A big, friendly entrance hall. The notice board on the wall carries the newest discussions, and doors lead off to the Arcade, the Lounge and the Rooftop.'],
        'arcade' => ['The Arcade', 'Rows of humming cabinets and a pinball table, lit mostly by their screens.'],
        'lounge' => ['The Lounge', 'Deep sofas, a crackling fire and a bar at the back. A good place to settle in.'],
        'rooftop' => ['The Rooftop', 'Open sky over the town. The sky follows the forum\'s own clock: sunny by day, stars at night.'],
    ];

    /** from slug => to slug => label */
    private const DOORS = [
        'lobby' => ['arcade' => 'Arcade', 'rooftop' => 'Rooftop stairs', 'lounge' => 'Lounge'],
        'arcade' => ['lobby' => 'Lobby'],
        'lounge' => ['lobby' => 'Lobby'],
        'rooftop' => ['lobby' => 'Stairs down'],
    ];

    private const KEY = 'ernestdefoe-hangout.forum.world.';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** A room row with its seeded name and description in the forum's language. */
    public function room(?object $room): ?object
    {
        $seed = $room ? (self::ROOMS[$room->slug] ?? null) : null;
        if ($seed) {
            $room = clone $room;
            if ($room->name === $seed[0]) {
                $room->name = $this->trans("rooms.{$room->slug}.name", $seed[0]);
            }
            if ($room->description === $seed[1]) {
                $room->description = $this->trans("rooms.{$room->slug}.description", $seed[1]);
            }
        }

        return $room;
    }

    public function door(?string $label, ?string $from, ?string $to): ?string
    {
        $seed = self::DOORS[$from][$to] ?? null;

        return $seed !== null && $label === $seed ? $this->trans("doors.{$from}_{$to}", $seed) : $label;
    }

    private function trans(string $key, string $english): string
    {
        $text = (string) $this->translator->trans(self::KEY.$key);

        return $text === self::KEY.$key || $text === '' ? $english : $text;
    }
}
