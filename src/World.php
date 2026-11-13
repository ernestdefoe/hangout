<?php

namespace ErnestDefoe\Hangout;

use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Rooms and the people in them: connecting and disconnecting, walking,
 * going through doors, and what a browser needs to draw a room.
 *
 * Presence rows exist only while someone is connected. A browser that stops
 * polling (closed lid, lost signal) is swept out after STALE seconds with
 * TCZ's "lost connection" line; leaving the page says goodbye at once.
 */
final class World
{
    public const STALE = 150;
    public const LEAVE_GRACE = 15;
    private const BEAT = 25;
    private const SWEEP = 10;

    /** Body colours for members who haven't picked one. */
    private const COLOURS = ['#e8833a', '#7dd3fc', '#f9a8d4', '#a3e635', '#c4a7ff', '#fbbf24', '#5eead4', '#fb7185', '#93c5fd', '#fdba74'];

    /** @var array<int, object> */
    private array $rooms = [];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
        private readonly Events $events,
        private readonly Ranks $ranks,
        private readonly SlugManager $slugs,
    ) {
    }

    // ── rooms ───────────────────────────────────────────────────

    public function room(int $id): ?object
    {
        if (! array_key_exists($id, $this->rooms)) {
            $this->rooms[$id] = $this->db->table('hangout_rooms')->where('id', $id)->first();
        }

        return $this->rooms[$id];
    }

    public function defaultRoom(): object
    {
        $room = $this->db->table('hangout_rooms')->orderByDesc('is_default')->orderBy('position')->orderBy('id')->first();
        if (! $room) {
            throw new HangoutException('no_rooms', 503);
        }

        return $this->rooms[(int) $room->id] = $room;
    }

    /** @return array<int, object> every room, for the map and `go <name>` */
    public function rooms(): array
    {
        return $this->db->table('hangout_rooms')->orderBy('position')->orderBy('id')->get()->keyBy('id')->all();
    }

    /** @return array<int, object> */
    public function exits(int $roomId): array
    {
        return $this->db->table('hangout_exits')->where('room_id', $roomId)->orderBy('id')->get()->all();
    }

    /** @return array<int, object> */
    public function items(int $roomId): array
    {
        return $this->db->table('hangout_items')->where('room_id', $roomId)->orderBy('id')->get()->all();
    }

    /** The floor tile in front of a door. @return array{0: int, 1: int} */
    public static function doorTile(object $room, object $exit): array
    {
        $w = (int) $room->width;
        $h = (int) $room->height;
        $p = (int) $exit->pos;

        return match ($exit->side) {
            'e' => [$w - 1, min($p, $h - 1)],
            'w' => [0, min($p, $h - 1)],
            's' => [min($p, $w - 1), $h - 1],
            default => [min($p, $w - 1), 0],
        };
    }

    /** Everything a browser needs to draw a room and who is in it. */
    public function roomState(object $room, User $viewer): array
    {
        $roomId = (int) $room->id;
        $exits = $this->exits($roomId);
        $names = $exits ? $this->db->table('hangout_rooms')->whereIn('id', array_map(fn ($e) => $e->target_id, $exits))->pluck('name', 'id')->all() : [];

        return [
            'id' => $roomId,
            'slug' => $room->slug,
            'name' => $room->name,
            'description' => $room->description,
            'area' => $room->area,
            'w' => (int) $room->width,
            'h' => (int) $room->height,
            'look' => json_decode((string) $room->look, true) ?: [],
            'locked' => (bool) $room->locked,
            'exits' => array_map(fn ($e) => [
                'id' => (int) $e->id,
                'to' => (int) $e->target_id,
                'name' => $e->label ?: ($names[$e->target_id] ?? ''),
                'room' => $names[$e->target_id] ?? '',
                'side' => $e->side,
                'pos' => (int) $e->pos,
            ], $exits),
            'items' => array_map(fn ($i) => ['id' => (int) $i->id, 'k' => $i->kind, 'x' => (int) $i->x, 'y' => (int) $i->y, 'r' => (int) $i->rot], $this->items($roomId)),
            'people' => $this->people(['room_id' => $roomId], $viewer),
            'log' => $this->events->recent($roomId),
        ];
    }

    // ── people ──────────────────────────────────────────────────

    /**
     * Connected people as browsers draw them: four queries however many
     * (presence, users, their groups, profiles).
     *
     * @param  array<string, mixed>  $where
     * @return array<int, array<string, mixed>>
     */
    public function people(array $where, User $viewer): array
    {
        $q = $this->db->table('hangout_presence');
        foreach ($where as $col => $val) {
            is_array($val) ? $q->whereIn($col, $val) : $q->where($col, $val);
        }
        if (! Ranks::can($this->ranks->of($viewer), 'invisible')) {
            $q->where(fn ($q) => $q->where('invisible', false)->orWhere('user_id', $viewer->id));
        }
        $rows = $q->orderBy('connected_at')->get();

        return $this->snapshots($rows->all());
    }

    /**
     * @param  array<int, object>  $presence
     * @return array<int, array<string, mixed>>
     */
    public function snapshots(array $presence): array
    {
        if (! $presence) {
            return [];
        }
        $ids = array_map(fn ($p) => (int) $p->user_id, $presence);
        $users = User::query()->whereIn('id', $ids)->with('groups')->get()->keyBy('id');
        $profiles = $this->db->table('hangout_profiles')->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $out = [];
        foreach ($presence as $p) {
            $user = $users->get((int) $p->user_id);
            if ($user) {
                $out[] = $this->snapshot($user, $p, $profiles->get((int) $p->user_id));
            }
        }

        return $out;
    }

    /** One person: who they are, how they look and where they stand. */
    public function snapshot(User $user, ?object $presence, ?object $profile): array
    {
        return array_filter([
            'id' => (int) $user->id,
            'n' => $user->display_name,
            'u' => $user->username,
            's' => $this->slugs->forResource(User::class)->toSlug($user),
            'av' => $user->avatar_url,
            'rk' => $this->ranks->of($user),
            'col' => $profile->color ?? self::COLOURS[$user->id % count(self::COLOURS)],
            'ti' => $profile->title ?? null,
            'pf' => $profile->prefix ?? null,
            'sf' => $profile->suffix ?? null,
            'fe' => $profile->feeling ?? null,
            'x' => $presence ? (int) $presence->x : null,
            'y' => $presence ? (int) $presence->y : null,
            'r' => $presence ? (int) $presence->room_id : null,
            'afk' => $presence->afk ?? null,
            'act' => $presence ? strtotime((string) $presence->active_at) : null,
            'con' => $presence ? strtotime((string) $presence->connected_at) : null,
            'inv' => $presence && $presence->invisible ? true : null,
        ], fn ($v) => $v !== null);
    }

    public function presence(int $userId): ?object
    {
        return $this->db->table('hangout_presence')->where('user_id', $userId)->first();
    }

    public function profile(int $userId): ?object
    {
        return $this->db->table('hangout_profiles')->where('user_id', $userId)->first();
    }

    /** @param array<string, mixed> $values */
    public function saveProfile(int $userId, array $values): void
    {
        if ($this->db->table('hangout_profiles')->where('user_id', $userId)->exists()) {
            $this->db->table('hangout_profiles')->where('user_id', $userId)->update($values);
        } else {
            $this->db->table('hangout_profiles')->insert($values + ['user_id' => $userId]);
        }
    }

    // ── connecting ──────────────────────────────────────────────

    /**
     * Connect (or carry on, for a second tab or a reload): returns the
     * presence row. A new connection lands at home and is announced to all.
     */
    public function connect(User $user): object
    {
        $this->sweep();
        if ($presence = $this->presence((int) $user->id)) {
            // Back within the grace after leaving (a reload, the next page): no goodbye, no hello.
            $this->cache->forget('ernestdefoe-hangout.left.'.$user->id);
            $this->beat($user, true);

            return $presence;
        }
        $profile = $this->profile((int) $user->id);
        $room = ($profile?->home_id ? $this->room((int) $profile->home_id) : null) ?? $this->defaultRoom();
        [$x, $y] = $this->spawn($room, null);
        $now = date('Y-m-d H:i:s');
        $this->db->table('hangout_presence')->insertOrIgnore([
            'user_id' => $user->id, 'room_id' => $room->id, 'x' => $x, 'y' => $y, 'invisible' => false,
            'connected_at' => $now, 'seen_at' => $now, 'active_at' => $now,
        ]);
        $presence = $this->presence((int) $user->id);
        $this->saveProfile((int) $user->id, ['last_connected_at' => $now]);
        $this->events->write([
            'audience' => 'all', 'kind' => 'connect', 'room_id' => (int) $room->id, 'actor_id' => (int) $user->id,
            // A muted member's connect message stays unsaid.
            'body' => $profile && ! ($profile->muted_until && strtotime((string) $profile->muted_until) > time()) ? $profile->cmessage : null,
            'data' => ['p' => $this->snapshot($user, $presence, $profile), 'room' => $room->name],
        ]);
        $this->cache->put('ernestdefoe-hangout.beat.'.$user->id, 1, self::BEAT);

        return $presence;
    }

    /** Disconnect: TCZ's goodbye (or "lost connection" when swept). */
    public function disconnect(int $userId, bool $lost = false): void
    {
        $presence = $this->presence($userId);
        if (! $presence) {
            return;
        }
        $deleted = $this->db->table('hangout_presence')->where('user_id', $userId)->delete();
        if (! $deleted) {
            return;
        }
        $profile = $this->profile($userId);
        $seconds = max(0, min(86400, time() - strtotime((string) $presence->connected_at)));
        $this->db->table('hangout_profiles')->where('user_id', $userId)->increment('total_seconds', $seconds);
        $user = User::query()->find($userId);
        if ($presence->invisible) {
            return;
        }
        $this->events->write([
            'audience' => 'all', 'kind' => 'disconnect', 'room_id' => (int) $presence->room_id, 'actor_id' => $userId,
            'body' => $lost ? null : ($profile->dmessage ?? null),
            'data' => ['n' => $user ? $user->display_name : '', 'lost' => $lost ?: null],
        ]);
    }

    /**
     * Leaving the page: gone in LEAVE_GRACE seconds unless they come straight
     * back (a reload, or opening the Hangout again from another page), so that
     * doesn't fill everyone's log with goodbyes and hellos. The sweep then says
     * their own goodbye, not "lost their connection".
     */
    public function leave(int $userId): void
    {
        $this->cache->put('ernestdefoe-hangout.left.'.$userId, 1, self::STALE + 60);
        $this->db->table('hangout_presence')->where('user_id', $userId)->update(['seen_at' => date('Y-m-d H:i:s', time() - self::STALE + self::LEAVE_GRACE)]);
        $this->cache->forget('ernestdefoe-hangout.beat.'.$userId);
    }

    /** "Still here": the poll's heartbeat, written at most every BEAT seconds. */
    public function beat(User $user, bool $force = false): void
    {
        if ($force || $this->cache->add('ernestdefoe-hangout.beat.'.$user->id, 1, self::BEAT)) {
            $this->db->table('hangout_presence')->where('user_id', $user->id)->update(['seen_at' => date('Y-m-d H:i:s')]);
        }
        $this->sweep();
    }

    /** Disconnect everyone whose browser went quiet; one sweep every SWEEP seconds at most. */
    public function sweep(): void
    {
        if (! $this->cache->add('ernestdefoe-hangout.sweep', 1, self::SWEEP)) {
            return;
        }
        $stale = $this->db->table('hangout_presence')->where('seen_at', '<', date('Y-m-d H:i:s', time() - self::STALE))->pluck('user_id');
        foreach ($stale as $id) {
            $this->disconnect((int) $id, ! $this->cache->pull('ernestdefoe-hangout.left.'.$id));
        }
    }

    // ── moving ──────────────────────────────────────────────────

    /**
     * Walk to a floor tile in the same room. Two people never share a tile: a
     * tile someone else stands on resolves to the nearest free one. Returns
     * where they ended up, or null for a tile they can't stand on.
     *
     * @return array{0: int, 1: int}|null
     */
    public function walk(User $user, object $presence, int $x, int $y): ?array
    {
        $room = $this->room((int) $presence->room_id);
        if (! $room || $x < 0 || $y < 0 || $x >= $room->width || $y >= $room->height) {
            return null;
        }
        if (isset(Furniture::blocked($this->items((int) $room->id))[$y * 256 + $x])) {
            return null;
        }
        [$x, $y] = $this->nearestFree($room, [$x, $y], (int) $user->id);
        if ((int) $presence->x === $x && (int) $presence->y === $y) {
            return [$x, $y];
        }
        $this->db->table('hangout_presence')->where('user_id', $user->id)->update(['x' => $x, 'y' => $y]);
        $this->events->write([
            'audience' => 'room', 'kind' => 'move', 'room_id' => (int) $room->id, 'actor_id' => (int) $user->id,
            'data' => ['x' => $x, 'y' => $y],
        ]);

        return [$x, $y];
    }

    /** Go through a door (or be moved) into another room. */
    public function enter(User $user, object $presence, object $to, ?string $how = null): object
    {
        $from = $this->room((int) $presence->room_id);
        [$x, $y] = $this->spawn($to, $from);
        $this->db->table('hangout_presence')->where('user_id', $user->id)->update(['room_id' => $to->id, 'x' => $x, 'y' => $y]);
        $presence = $this->presence((int) $user->id);
        if (! $presence->invisible) {
            if ($from) {
                $this->events->write([
                    'audience' => 'room', 'kind' => 'leave', 'room_id' => (int) $from->id, 'actor_id' => (int) $user->id,
                    'data' => ['n' => $user->display_name, 'to' => $to->name, 'how' => $how],
                ]);
            }
            $this->events->write([
                'audience' => 'room', 'kind' => 'enter', 'room_id' => (int) $to->id, 'actor_id' => (int) $user->id,
                'data' => ['p' => $this->snapshot($user, $presence, $this->profile((int) $user->id)), 'from' => $from?->name, 'how' => $how],
            ]);
        }

        return $presence;
    }

    /** Where someone appears: by the door back to where they came from, else the middle. @return array{0: int, 1: int} */
    public function spawn(object $room, ?object $from): array
    {
        // Somewhere near the front middle, a little different each time, so arrivals don't stack up.
        $start = [intdiv((int) $room->width, 2) + random_int(-3, 3), max(0, (int) $room->height - 3 + random_int(-1, 1))];
        if ($from) {
            foreach ($this->exits((int) $room->id) as $exit) {
                if ((int) $exit->target_id === (int) $from->id) {
                    $start = self::doorTile($room, $exit);
                    break;
                }
            }
        }

        return $this->nearestFree($room, $start, null);
    }

    /**
     * The nearest floor tile to $start that no furniture and nobody else
     * (other than $mover) stands on, spiralling out. One person per tile.
     *
     * @param  array{0: int, 1: int}  $start
     * @return array{0: int, 1: int}
     */
    public function nearestFree(object $room, array $start, ?int $mover): array
    {
        $blocked = Furniture::blocked($this->items((int) $room->id));
        $q = $this->db->table('hangout_presence')->where('room_id', $room->id);
        if ($mover) {
            $q->where('user_id', '!=', $mover);
        }
        $taken = $q->get(['x', 'y'])->map(fn ($p) => (int) $p->y * 256 + (int) $p->x)->flip()->all();
        $w = (int) $room->width;
        $h = (int) $room->height;
        // A free start tile is honoured. Otherwise prefer tiles not straight in front of or
        // behind someone (a person is taller than a tile, so they'd hide each other), then any free tile.
        $free = fn (int $x, int $y) => $x >= 0 && $y >= 0 && $x < $w && $y < $h && ! isset($blocked[$y * 256 + $x]) && ! isset($taken[$y * 256 + $x]);
        $clear = fn (int $x, int $y) => $free($x, $y) && ! isset($taken[($y - 1) * 256 + $x]) && ! isset($taken[($y + 1) * 256 + $x]);
        if ($free($start[0], $start[1])) {
            return $start;
        }
        foreach ([$clear, $free] as $ok) {
            for ($r = 1; $r < max($w, $h); $r++) {
                for ($dy = -$r; $dy <= $r; $dy++) {
                    for ($dx = -$r; $dx <= $r; $dx++) {
                        if (max(abs($dx), abs($dy)) === $r && $ok($start[0] + $dx, $start[1] + $dy)) {
                            return [$start[0] + $dx, $start[1] + $dy];
                        }
                    }
                }
            }
        }

        return $start;
    }

    /** Someone did something: no longer idle, and back from AFK. */
    public function touch(User $user, object $presence): void
    {
        $values = ['active_at' => date('Y-m-d H:i:s')];
        if ($presence->afk !== null) {
            $values['afk'] = null;
        }
        $this->db->table('hangout_presence')->where('user_id', $user->id)->update($values);
        if ($presence->afk !== null && ! $presence->invisible) {
            $this->events->write(['audience' => 'room', 'kind' => 'back', 'room_id' => (int) $presence->room_id, 'actor_id' => (int) $user->id, 'data' => ['n' => $user->display_name]]);
        }
        $presence->afk = null;
    }
}
