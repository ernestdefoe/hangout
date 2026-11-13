<?php

namespace ErnestDefoe\Hangout;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * TCZ's ranks, tied to Flarum groups.
 *
 * Each rank has a level (what it may do), a name and a colour (both editable
 * in the admin) and a group: a member holds the highest rank of any group they
 * are in, and a member in no ranked group is a Mortal. Moron is TCZ's
 * punishment rank and wins over everything else. Two ladders sit under the
 * Deity: Wizards (building) and Druids (helping and moderation), each with an
 * Apprentice and an Elder step. Colours follow TCZ's own ANSI colour map
 * (include/config.h).
 */
final class Ranks
{
    /** key => [level, default name, default colour, ladder] */
    public const CATALOG = [
        'deity' => [10, 'Deity', '#d946ef', 'staff'],
        'elder_wizard' => [9, 'Elder Wizard', '#4ade80', 'wizard'],
        'elder_druid' => [9, 'Elder Druid', '#16a34a', 'druid'],
        'wizard' => [8, 'Wizard', '#22d3ee', 'wizard'],
        'druid' => [8, 'Druid', '#0e9fb8', 'druid'],
        'apprentice_wizard' => [7, 'Apprentice Wizard', '#facc15', 'wizard'],
        'apprentice_druid' => [7, 'Apprentice Druid', '#ca8a04', 'druid'],
        'retired' => [5, 'Retired', '#f87171', 'honour'],
        'experienced' => [4, 'Experienced Builder', '#fb7185', 'builder'],
        'assistant' => [4, 'Assistant', '#dc2626', 'helper'],
        'builder' => [3, 'Builder', '#e2e8f0', 'builder'],
        'mortal' => [1, 'Mortal', '#cbd5e1', 'none'],
        'moron' => [0, 'Moron', '#60a5fa', 'none'],
    ];

    /** Out of the box: Deity = Admins, Druid = Mods. The rest wait for the admin to pick a group. */
    public const DEFAULT_GROUPS = ['deity' => 1, 'druid' => 4];

    /** @var array<string, array{name: string, color: string, group: int|null}>|null */
    private ?array $table = null;

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly ConnectionInterface $db,
    ) {
    }

    /** @return array<string, array{name: string, color: string, group: int|null, level: int, ladder: string}> */
    public function table(): array
    {
        if ($this->table === null) {
            $saved = json_decode((string) $this->settings->get(Settings::RANKS), true);
            $saved = is_array($saved) ? $saved : null;
            $this->table = [];
            foreach (self::CATALOG as $key => [$level, $name, $color, $ladder]) {
                $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];
                $group = array_key_exists('group', $row) ? $row['group'] : ($saved === null ? (self::DEFAULT_GROUPS[$key] ?? null) : null);
                $this->table[$key] = [
                    'name' => is_string($row['name'] ?? null) && trim($row['name']) !== '' ? mb_substr(trim($row['name']), 0, 40) : $name,
                    'color' => is_string($row['color'] ?? null) && preg_match('/^#[0-9a-f]{3,8}$/i', $row['color']) ? $row['color'] : $color,
                    'group' => is_numeric($group) && (int) $group > 0 ? (int) $group : null,
                    'level' => $level,
                    'ladder' => $ladder,
                ];
            }
        }

        /** @var array<string, array{name: string, color: string, group: int|null, level: int, ladder: string}> */
        return $this->table;
    }

    /** The rank for a set of group ids. */
    public function forGroups(array $groupIds): string
    {
        $groupIds = array_map('intval', $groupIds);
        $best = 'mortal';
        $bestLevel = self::CATALOG['mortal'][0];
        foreach ($this->table() as $key => $rank) {
            if ($rank['group'] === null || ! in_array($rank['group'], $groupIds, true)) {
                continue;
            }
            if ($key === 'moron') {
                return 'moron';
            }
            if ($rank['level'] > $bestLevel) {
                [$best, $bestLevel] = [$key, $rank['level']];
            }
        }

        return $best;
    }

    /** The actor's rank, from the groups Flarum already loaded for the permission check. */
    public function of(User $user): string
    {
        if ($user->isGuest()) {
            return 'mortal';
        }

        return $this->forGroups($user->groups->pluck('id')->all());
    }

    /**
     * Ranks for many members in one query.
     *
     * @param  int[]  $userIds
     * @return array<int, string>
     */
    public function ofMany(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (! $userIds) {
            return [];
        }
        $groups = [];
        foreach ($this->db->table('group_user')->whereIn('user_id', $userIds)->get(['user_id', 'group_id']) as $row) {
            $groups[(int) $row->user_id][] = (int) $row->group_id;
        }
        $ranks = [];
        foreach ($userIds as $id) {
            $ranks[$id] = $this->forGroups($groups[$id] ?? []);
        }

        return $ranks;
    }

    public static function level(string $rank): int
    {
        return self::CATALOG[$rank][0] ?? 1;
    }

    /**
     * What a rank may do. Phase 1 uses the talking powers; staff and building
     * powers are listed so later phases check them in one place.
     */
    public static function can(string $rank, string $power): bool
    {
        $level = self::level($rank);
        $ladder = self::CATALOG[$rank][3] ?? 'none';

        return match ($power) {
            // Apprentices and up shout without a wait (TCZ: Druids/Apprentice Wizards and above).
            'shout_free' => $level >= 7,
            'shout', 'yell', 'page_anyone', 'emote' => $level >= 1,
            'staff' => $level >= 7,
            // Druids and up: remove a message, mute someone (Apprentices learn first).
            'moderate' => $level >= 8,
            'build' => $level >= 3 && $ladder !== 'druid' || $level >= 7,
            'invisible' => $level >= 10,
            default => false,
        };
    }

    /** What the browser needs to draw name tags and the who list. */
    public function forBrowser(): array
    {
        $out = [];
        foreach ($this->table() as $key => $rank) {
            $out[$key] = ['name' => $rank['name'], 'color' => $rank['color'], 'level' => $rank['level']];
        }

        return $out;
    }
}
