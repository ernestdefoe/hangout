<?php

namespace ErnestDefoe\Hangout;

use ErnestDefoe\Hangout\Job\Push;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;

/**
 * The event log: everything said and done, read by every browser as "what
 * happened after event N that I may see".
 *
 * Writes take the hangout_meta row lock, so event ids commit in id order and
 * a reader's cursor can never skip one (a clock would drop same-second
 * events). The newest id is also cached: a browser that is up to date is
 * answered from the cache without touching the table.
 */
final class Events
{
    public const HEAD = 'ernestdefoe-hangout.head';

    /** @var array<int, array{0: int[], 1: array<string, mixed>}> pushes waiting for the response to finish */
    private array $pushes = [];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
        private readonly Container $container,
    ) {
    }

    /**
     * Save one event and push it to whoever is connected and may see it.
     *
     * @param  array{audience: string, kind: string, room_id?: int|null, channel_id?: int|null, area?: string|null, actor_id?: int|null, body?: string|null, data?: array<string, mixed>}  $event
     * @param  int[]  $inbox  members a private event reaches (page, tell)
     * @return array<string, mixed> the event as browsers get it
     */
    public function write(array $event, array $inbox = []): array
    {
        $now = time();
        $row = [
            'audience' => $event['audience'],
            'kind' => $event['kind'],
            'room_id' => $event['room_id'] ?? null,
            'channel_id' => $event['channel_id'] ?? null,
            'area' => $event['area'] ?? null,
            'actor_id' => $event['actor_id'] ?? null,
            'body' => $event['body'] ?? null,
            'data' => isset($event['data']) ? json_encode($event['data'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => date('Y-m-d H:i:s', $now),
        ];
        $inbox = array_values(array_unique(array_map('intval', $inbox)));

        $id = $this->db->transaction(function () use ($row, $inbox) {
            $this->db->table('hangout_meta')->where('id', 1)->lockForUpdate()->first();
            $id = (int) $this->db->table('hangout_events')->insertGetId($row);
            if ($inbox) {
                $this->db->table('hangout_inbox')->insert(array_map(fn (int $u) => ['user_id' => $u, 'event_id' => $id], $inbox));
            }
            $this->db->table('hangout_meta')->where('id', 1)->update(['head' => $id]);

            return $id;
        });
        $this->cache->forever(self::HEAD, $id);

        $out = self::present((object) ($row + ['id' => $id]));
        $this->push($out, $row, $inbox);

        return $out;
    }

    /** The newest event id, from the cache when it has it. */
    public function head(): int
    {
        $head = $this->cache->get(self::HEAD);
        if ($head === null) {
            $head = (int) $this->db->table('hangout_meta')->where('id', 1)->value('head');
            $this->cache->forever(self::HEAD, $head);
        }

        return (int) $head;
    }

    /**
     * Events after $since one reader may see: their room, the whole Hangout,
     * their area, their channels and what was sent to them. The id range is
     * read through the primary key, so a reader that keeps up scans a handful
     * of rows however long the log is.
     *
     * @param  int[]  $channels
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    public function after(int $since, ?int $roomId, ?string $area, ?int $userId, array $channels = [], int $limit = 200): array
    {
        $rows = $this->db->table('hangout_events')
            ->where('id', '>', $since)
            ->where(function ($q) use ($roomId, $area, $userId, $since, $channels) {
                $q->where('audience', 'all');
                if ($roomId) {
                    $q->orWhere(fn ($q) => $q->where('audience', 'room')->where('room_id', $roomId));
                }
                if ($area !== null) {
                    $q->orWhere(fn ($q) => $q->where('audience', 'area')->where('area', $area));
                }
                if ($channels) {
                    $q->orWhere(fn ($q) => $q->where('audience', 'chan')->whereIn('channel_id', $channels));
                }
                if ($userId) {
                    $q->orWhereIn('id', fn ($q) => $q->select('event_id')->from('hangout_inbox')->where('user_id', $userId)->where('event_id', '>', $since));
                }
            })
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $more = $rows->count() > $limit;

        return [$rows->take($limit)->map(fn ($row) => self::present($row))->values()->all(), $more];
    }

    /**
     * The room's recent log, oldest first, for someone walking in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $roomId, int $limit = 40): array
    {
        return $this->db->table('hangout_events')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('audience', 'room')->where('room_id', $roomId))->orWhere('audience', 'all'))
            ->whereNotIn('kind', ['move', 'upd'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn ($row) => self::present($row))
            ->values()
            ->all();
    }

    /**
     * Recent talk on some channels, oldest first, for someone opening the Hangout.
     *
     * @param  int[]  $channels
     * @return array<int, array<string, mixed>>
     */
    public function recentChannels(array $channels, int $limit = 60): array
    {
        if (! $channels) {
            return [];
        }

        return $this->db->table('hangout_events')->where('audience', 'chan')->whereIn('channel_id', $channels)
            ->orderByDesc('id')->limit($limit)->get()->reverse()->map(fn ($row) => self::present($row))->values()->all();
    }

    /**
     * Private messages one member received or sent, newest first (recall, the Tells tab).
     *
     * @param  string[]  $kinds
     * @return array<int, array<string, mixed>>
     */
    public function inbox(int $userId, array $kinds, int $limit): array
    {
        return $this->db->table('hangout_events')
            ->whereIn('id', fn ($q) => $q->select('event_id')->from('hangout_inbox')->where('user_id', $userId))
            ->whereIn('kind', $kinds)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => self::present($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function present(object $row): array
    {
        $data = is_string($row->data ?? null) ? json_decode($row->data, true) : ($row->data ?? null);

        return array_filter([
            'i' => (int) $row->id,
            'k' => $row->kind,
            'a' => isset($row->actor_id) ? (int) $row->actor_id : null,
            'r' => isset($row->room_id) ? (int) $row->room_id : null,
            'h' => isset($row->channel_id) ? (int) $row->channel_id : null,
            't' => $row->body ?? null,
            'd' => $data ?: null,
            'at' => strtotime((string) $row->created_at),
        ], fn ($v) => $v !== null);
    }

    /** Push through flarum/realtime when it is installed; without it, browsers poll. */
    private function push(array $event, array $row, array $inbox): void
    {
        if (! class_exists(\Pusher\Pusher::class) || ! $this->container->bound(\Pusher\Pusher::class)) {
            return;
        }
        $q = $this->db->table('hangout_presence');
        $ids = match ($row['audience']) {
            'room' => $q->where('room_id', $row['room_id'])->pluck('user_id')->all(),
            'all' => $q->pluck('user_id')->all(),
            'area' => $q->whereIn('room_id', fn ($s) => $s->select('id')->from('hangout_rooms')->where('area', $row['area']))->pluck('user_id')->all(),
            'chan' => $q->whereIn('user_id', fn ($s) => $s->select('user_id')->from('hangout_channel_members')->where('channel_id', $row['channel_id']))->pluck('user_id')->all(),
            default => [],
        };
        $ids = array_values(array_unique(array_merge(array_map('intval', $ids), $inbox)));
        if (! $ids) {
            return;
        }
        // Under php-fpm: after the response has gone out, so nobody waits on the
        // daemon and nobody waits on the queue either (a queued push took ~3 s).
        if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
            if (! $this->pushes) {
                register_shutdown_function(function () {
                    fastcgi_finish_request();
                    foreach ($this->pushes as [$userIds, $payload]) {
                        (new Push($userIds, $payload))->handle($this->container);
                    }
                });
            }
            $this->pushes[] = [$ids, $event];

            return;
        }
        $this->container->make(Dispatcher::class)->dispatch(new Push($ids, $event));
    }
}
