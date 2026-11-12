<?php

namespace ErnestDefoe\Hangout;

use ErnestDefoe\Hangout\Notification\MailBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * TCZ's social side: chat channels, friends and enemies, mail (which arrives
 * as a Flarum notification), the BBS, aliases, shout and yell, and @partner.
 * Each is a command word registered on Commands, so typed commands and the
 * browser's buttons go the same way.
 */
final class Social
{
    public const ALIASES_MORTAL = 100;
    public const ALIASES_ADMIN = 200;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
        private readonly Events $events,
        private readonly World $world,
        private readonly Settings $settings,
        private readonly NotificationSyncer $notifications,
    ) {
    }

    public function register(Commands $commands): void
    {
        $commands->register('shout', fn (Ctx $c, string $a) => $this->shout($c, $a));
        $commands->register('yell', fn (Ctx $c, string $a) => $this->yell($c, $a));
        $commands->register('chat', fn (Ctx $c, string $a) => $this->chat($c, $a));
        $commands->register('channel', fn (Ctx $c, string $a) => $this->channel($c, $a));
        $commands->register('channels', fn (Ctx $c, string $a) => $this->channel($c, 'list'));
        $commands->register('friend', fn (Ctx $c, string $a) => $this->relation($c, 'friend', $a));
        $commands->register('friends', fn (Ctx $c, string $a) => $this->relation($c, 'friend', $a ?: 'list'));
        $commands->register('enemy', fn (Ctx $c, string $a) => $this->relation($c, 'enemy', $a));
        $commands->register('enemies', fn (Ctx $c, string $a) => $this->relation($c, 'enemy', $a ?: 'list'));
        $commands->register('mail', fn (Ctx $c, string $a) => $this->mail($c, $a, $commands));
        $commands->register('bbs', function (Ctx $c): void {
            $c->ui['board'] = true;
        });
        $commands->register('alias', fn (Ctx $c, string $a) => $this->alias($c, $a));
        $commands->register('aliases', fn (Ctx $c, string $a) => $this->alias($c, ''));
        $commands->register('partner', fn (Ctx $c, string $a) => $this->partner($c, $a, $commands));
        $commands->expandWith(fn (User $u, string $word) => $this->aliases((int) $u->id)[$word] ?? null);
        $commands->blockWith(fn (int $from, array $to) => $this->blockedBy($from, $to));
        $commands->groupWith(fn (int $userId, string $group) => $group === 'friends' ? $this->ids($userId, 'friend') : null);
    }

    // ── shout and yell ──────────────────────────────────────────

    /** TCZ's shout: one line in every room. Apprentices and up any time; others wait the admin's minutes. */
    private function shout(Ctx $ctx, string $args): void
    {
        $text = Commands::clean($args);
        if ($text === '') {
            throw new HangoutException('usage', 422, ['usage' => 'shout <message>']);
        }
        if (! $ctx->actor->can(Settings::SHOUT) || ! Ranks::can($ctx->rank, 'shout')) {
            throw new HangoutException('rank_cannot', 403);
        }
        if (! Ranks::can($ctx->rank, 'shout_free') && ($minutes = $this->settings->shoutMinutes()) > 0) {
            $last = $this->world->profile((int) $ctx->actor->id)?->shouted_at;
            $wait = $last ? strtotime((string) $last) + $minutes * 60 - time() : 0;
            if ($wait > 0) {
                throw new HangoutException('shout_wait', 429, ['minutes' => (int) ceil($wait / 60)]);
            }
        }
        $this->world->saveProfile((int) $ctx->actor->id, ['shouted_at' => date('Y-m-d H:i:s')]);
        $ctx->events[] = $this->events->write([
            'audience' => 'all', 'kind' => 'shout', 'room_id' => (int) $ctx->where->id, 'actor_id' => (int) $ctx->actor->id,
            'body' => $text, 'data' => ['n' => $ctx->name(), 'rk' => $ctx->rank, 'room' => $ctx->where->name],
        ]);
    }

    /** TCZ's yell: heard in every room of the same area, at the top of your voice. Once a minute for Mortals. */
    private function yell(Ctx $ctx, string $args): void
    {
        $text = Commands::clean($args);
        if ($text === '') {
            throw new HangoutException('usage', 422, ['usage' => 'yell <message>']);
        }
        if (! Ranks::can($ctx->rank, 'yell')) {
            throw new HangoutException('rank_cannot', 403);
        }
        if (! Ranks::can($ctx->rank, 'shout_free') && ! $this->cache->add('ernestdefoe-hangout.yell.'.$ctx->actor->id, 1, 60)) {
            throw new HangoutException('yell_wait', 429);
        }
        $ctx->events[] = $this->events->write([
            'audience' => 'area', 'kind' => 'yell', 'room_id' => (int) $ctx->where->id, 'area' => $ctx->where->area, 'actor_id' => (int) $ctx->actor->id,
            'body' => $text, 'data' => ['n' => $ctx->name(), 'rk' => $ctx->rank, 'area' => $ctx->where->area],
        ]);
    }

    // ── channels ────────────────────────────────────────────────

    /** @return int[] the channels someone is in (cached until they join or leave one) */
    public function channelIds(int $userId): array
    {
        return $this->cache->remember('ernestdefoe-hangout.chans.'.$userId, 3600, fn () => array_map('intval', $this->db->table('hangout_channel_members')->where('user_id', $userId)->pluck('channel_id')->all()));
    }

    /** @return array<int, array<string, mixed>> */
    public function channels(int $userId): array
    {
        $mine = array_flip($this->channelIds($userId));
        $counts = $this->db->table('hangout_channel_members')->selectRaw('channel_id, count(*) as c')->groupBy('channel_id')->pluck('c', 'channel_id')->all();

        return $this->db->table('hangout_channels')->orderByDesc('permanent')->orderBy('name')->get()->map(fn ($c) => [
            'id' => (int) $c->id, 'name' => $c->name, 'description' => $c->description,
            'n' => (int) ($counts[$c->id] ?? 0), 'in' => isset($mine[(int) $c->id]),
        ])->all();
    }

    private function findChannel(string $name): ?object
    {
        $slug = self::slug($name);

        return $slug === '' ? null : $this->db->table('hangout_channels')->where('slug', $slug)->first();
    }

    private static function slug(string $name): string
    {
        return substr(preg_replace('/[^a-z0-9_-]/', '', strtolower(ltrim(trim($name), '#'))) ?? '', 0, 30);
    }

    /** `-gaming hello` or `chat gaming hello`: talk on a channel; with only one channel, the name can be left out. */
    private function chat(Ctx $ctx, string $args): void
    {
        $mine = $this->channelIds((int) $ctx->actor->id);
        $parts = preg_split('/\s+/', trim($args), 2) ?: [];
        $channel = $parts ? $this->findChannel($parts[0]) : null;
        if ($channel && in_array((int) $channel->id, $mine, true)) {
            $text = $parts[1] ?? '';
        } elseif (count($mine) === 1) {
            $channel = $this->db->table('hangout_channels')->where('id', $mine[0])->first();
            $text = $args;
        } else {
            throw new HangoutException($mine ? 'which_channel' : 'no_channels', 422);
        }
        $text = Commands::clean($text);
        if ($text === '') {
            throw new HangoutException('usage', 422, ['usage' => '-<channel> <message>']);
        }
        $emote = str_starts_with($text, ':');
        $ctx->events[] = $this->events->write([
            'audience' => 'chan', 'kind' => 'chan', 'channel_id' => (int) $channel->id, 'actor_id' => (int) $ctx->actor->id,
            'body' => $emote ? ltrim(substr($text, 1)) : $text, 'data' => ['n' => $ctx->name(), 'rk' => $ctx->rank, 'ch' => $channel->name, 'em' => $emote ?: null],
        ]);
    }

    /** channel list | join <name> | leave <name> | create <name> [description] | who <name> */
    private function channel(Ctx $ctx, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args), 3) ?: [];
        $verb = strtolower($parts[0] ?? 'list');
        $uid = (int) $ctx->actor->id;
        if ($verb === '' || $verb === 'list') {
            $ctx->say('channels', ['channels' => $this->channels($uid)]);

            return;
        }
        $name = $parts[1] ?? '';
        $channel = $this->findChannel($name);
        switch ($verb) {
            case 'join':
                if (! $channel) {
                    throw new HangoutException('no_such_channel', 404, ['name' => $name]);
                }
                $this->db->table('hangout_channel_members')->insertOrIgnore(['channel_id' => $channel->id, 'user_id' => $uid, 'joined_at' => date('Y-m-d H:i:s')]);
                break;
            case 'leave':
                if (! $channel) {
                    throw new HangoutException('no_such_channel', 404, ['name' => $name]);
                }
                $this->db->table('hangout_channel_members')->where('channel_id', $channel->id)->where('user_id', $uid)->delete();
                break;
            case 'create':
                $slug = self::slug($name);
                if ($slug === '' || ! ($ctx->actor->can('ernestdefoe-hangout.createChannels') || Ranks::can($ctx->rank, 'staff'))) {
                    throw new HangoutException($slug === '' ? 'usage' : 'rank_cannot', 403, ['usage' => 'channel create <name> [description]']);
                }
                if ($channel) {
                    throw new HangoutException('channel_exists', 422, ['name' => $slug]);
                }
                $id = $this->db->table('hangout_channels')->insertGetId(['slug' => $slug, 'name' => $slug, 'description' => Commands::clean($parts[2] ?? '', 160) ?: null, 'owner_id' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
                $this->db->table('hangout_channel_members')->insert(['channel_id' => $id, 'user_id' => $uid, 'joined_at' => date('Y-m-d H:i:s')]);
                break;
            case 'who':
                if (! $channel) {
                    throw new HangoutException('no_such_channel', 404, ['name' => $name]);
                }
                $ids = $this->db->table('hangout_channel_members')->where('channel_id', $channel->id)->pluck('user_id')->all();
                $ctx->say('channel_who', ['name' => $channel->name, 'people' => User::query()->whereIn('id', $ids)->get()->map(fn (User $u) => $u->display_name)->all()]);

                return;
            default:
                throw new HangoutException('usage', 422, ['usage' => 'channel list | join <name> | leave <name> | create <name>']);
        }
        $this->cache->forget('ernestdefoe-hangout.chans.'.$uid);
        $ctx->say('channel_'.$verb, ['name' => $channel->name ?? $slug ?? $name]);
        $ctx->ui['channels'] = $this->channels($uid);
    }

    // ── friends and enemies ─────────────────────────────────────

    /** @return int[] */
    public function ids(int $userId, string $kind): array
    {
        return array_map('intval', $this->db->table('hangout_relations')->where('user_id', $userId)->where('kind', $kind)->pluck('other_id')->all());
    }

    /** @param int[] $to @return int[] the recipients who have $from on their enemies list */
    public function blockedBy(int $from, array $to): array
    {
        return $to ? array_map('intval', $this->db->table('hangout_relations')->whereIn('user_id', $to)->where('other_id', $from)->where('kind', 'enemy')->pluck('user_id')->all()) : [];
    }

    /** friend add|remove <name>, friends; enemy add|remove <name>, enemies */
    private function relation(Ctx $ctx, string $kind, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args), 2) ?: [];
        $verb = strtolower($parts[0] ?? 'list');
        $uid = (int) $ctx->actor->id;
        if (! in_array($verb, ['add', 'remove', 'list', ''], true)) {
            [$verb, $parts[1]] = ['add', trim($args)];
        }
        if ($verb === 'list' || $verb === '') {
            $ids = $this->ids($uid, $kind);
            $online = $ids ? $this->db->table('hangout_presence')->whereIn('user_id', $ids)->where('invisible', false)->pluck('room_id', 'user_id')->all() : [];
            $rooms = $this->db->table('hangout_rooms')->pluck('name', 'id')->all();
            $people = User::query()->whereIn('id', $ids ?: [0])->get()->map(fn (User $u) => ['id' => (int) $u->id, 'n' => $u->display_name, 'room' => isset($online[$u->id]) ? ($rooms[$online[$u->id]] ?? '') : null])->all();
            $ctx->say($kind === 'friend' ? 'friends' : 'enemies', ['people' => $people]);

            return;
        }
        $user = $this->findAnyone($parts[1] ?? '');
        if (! $user || (int) $user->id === $uid) {
            throw new HangoutException('no_such_person', 404, ['name' => $parts[1] ?? '']);
        }
        if ($verb === 'add') {
            $max = Ranks::level($ctx->rank) >= 7 ? 500 : 300; // TCZ's MAX_FRIENDS_ADMIN / _MORTAL
            if ($this->db->table('hangout_relations')->where('user_id', $uid)->count() >= $max) {
                throw new HangoutException('too_many', 422, ['max' => $max]);
            }
            $this->db->table('hangout_relations')->where('user_id', $uid)->where('other_id', $user->id)->delete();
            $this->db->table('hangout_relations')->insert(['user_id' => $uid, 'other_id' => $user->id, 'kind' => $kind, 'created_at' => date('Y-m-d H:i:s')]);
        } else {
            $this->db->table('hangout_relations')->where('user_id', $uid)->where('other_id', $user->id)->where('kind', $kind)->delete();
        }
        $ctx->say($kind.'_'.$verb, ['name' => $user->display_name]);
        $ctx->ui['relations'] = ['friends' => $this->ids($uid, 'friend'), 'enemies' => $this->ids($uid, 'enemy')];
    }

    private function findAnyone(string $name): ?User
    {
        $name = trim(ltrim($name, '@'));
        if ($name === '') {
            return null;
        }

        return User::query()->where('username', $name)->first()
            ?? (ctype_digit($name) ? User::query()->find((int) $name) : null);
    }

    // ── mail ────────────────────────────────────────────────────

    /** mail <name> = <message> | mail (list) | mail read <n> | mail delete <n> */
    private function mail(Ctx $ctx, string $args, Commands $commands): void
    {
        $args = trim($args);
        $uid = (int) $ctx->actor->id;
        if ($args === '' || $args === 'list') {
            $ctx->ui['tab'] = 'mail';

            return;
        }
        if (preg_match('/^(read|delete)\s+#?(\d+)$/i', $args, $m)) {
            $q = $this->db->table('hangout_mail')->where('to_id', $uid)->where('id', (int) $m[2]);
            if (! $q->exists()) {
                throw new HangoutException('no_such_mail', 404);
            }
            strtolower($m[1]) === 'read' ? $q->update(['read_at' => date('Y-m-d H:i:s')]) : $q->delete();
            $ctx->ui['tab'] = 'mail';

            return;
        }
        if (! str_contains($args, '=')) {
            throw new HangoutException('usage', 422, ['usage' => 'mail <name> = <message>']);
        }
        [$who, $body] = array_map('trim', explode('=', $args, 2));
        $body = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $body) ?? '');
        $body = mb_substr($body, 0, 4000);
        $to = $commands->find($who) ?? $this->findAnyone($who);
        if (! $to || $body === '') {
            throw new HangoutException($to ? 'usage' : 'no_such_person', 404, ['name' => $who, 'usage' => 'mail <name> = <message>']);
        }
        if ($this->blockedBy($uid, [(int) $to->id])) {
            throw new HangoutException('blocked', 403, ['name' => $to->display_name]);
        }
        // TCZ's MAIL_LIMIT_MORTAL: 50 waiting at once is plenty; and 20 a day from one sender.
        if ($this->db->table('hangout_mail')->where('to_id', $to->id)->whereNull('read_at')->count() >= 50) {
            throw new HangoutException('mailbox_full', 422, ['name' => $to->display_name]);
        }
        if (! Ranks::can($ctx->rank, 'staff')) {
            $key = 'ernestdefoe-hangout.mail.'.$uid.'.'.date('Ymd');
            $this->cache->add($key, 0, 90000);
            if ((int) $this->cache->increment($key) > 20) {
                throw new HangoutException('slow_down', 429);
            }
        }
        $subject = mb_substr(strtok($body, "\n") ?: $body, 0, 80);
        $id = $this->db->table('hangout_mail')->insertGetId(['from_id' => $uid, 'to_id' => $to->id, 'subject' => $subject, 'body' => $body, 'created_at' => date('Y-m-d H:i:s')]);
        $this->notifications->sync(new MailBlueprint($ctx->actor, (int) $id), [$to]);
        $ctx->say('mail_sent', ['name' => $to->display_name]);
    }

    /** @return array<int, array<string, mixed>> */
    public function inbox(int $userId): array
    {
        $rows = $this->db->table('hangout_mail')->where('to_id', $userId)->orderByDesc('id')->limit(100)->get();
        $users = User::query()->whereIn('id', $rows->pluck('from_id')->filter()->unique()->all() ?: [0])->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'from' => $r->from_id ? ($users->get($r->from_id)->display_name ?? '') : '', 'fromU' => $r->from_id ? ($users->get($r->from_id)->username ?? '') : '',
            'subject' => $r->subject, 'body' => $r->body, 'read' => $r->read_at !== null, 'at' => strtotime((string) $r->created_at),
        ])->all();
    }

    public function unread(int $userId): int
    {
        return $this->db->table('hangout_mail')->where('to_id', $userId)->whereNull('read_at')->count();
    }

    // ── aliases ─────────────────────────────────────────────────

    /** @return array<string, string> */
    public function aliases(int $userId): array
    {
        $raw = $this->world->profile($userId)?->aliases;
        $list = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($list) ? $list : [];
    }

    /** alias (list) | alias <name> = <command> | alias <name> = (remove) */
    private function alias(Ctx $ctx, string $args): void
    {
        $uid = (int) $ctx->actor->id;
        $list = $this->aliases($uid);
        if (trim($args) === '') {
            $ctx->say('aliases', ['aliases' => $list]);

            return;
        }
        if (! str_contains($args, '=')) {
            throw new HangoutException('usage', 422, ['usage' => 'alias <name> = <command>']);
        }
        [$name, $command] = array_map('trim', explode('=', $args, 2));
        $name = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $name) ?? '');
        if ($name === '' || $name === 'alias') {
            throw new HangoutException('usage', 422, ['usage' => 'alias <name> = <command>']);
        }
        if ($command === '') {
            unset($list[$name]);
        } else {
            $max = Ranks::level($ctx->rank) >= 7 ? self::ALIASES_ADMIN : self::ALIASES_MORTAL;
            if (! isset($list[$name]) && count($list) >= $max) {
                throw new HangoutException('too_many', 422, ['max' => $max]);
            }
            $list[$name] = Commands::clean($command, 300);
        }
        ksort($list);
        $this->world->saveProfile($uid, ['aliases' => $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null]);
        $ctx->say($command === '' ? 'alias_removed' : 'alias_set', ['name' => $name, 'command' => $command]);
    }

    // ── @partner ────────────────────────────────────────────────

    /** TCZ's @partner, the 'net' marriage: partner <name> proposes, partner accept, partner decline, partner end. */
    private function partner(Ctx $ctx, string $args, Commands $commands): void
    {
        $uid = (int) $ctx->actor->id;
        $me = $this->world->profile($uid);
        $verb = strtolower(trim($args));
        $theirs = fn () => $this->db->table('hangout_profiles')->where('partner_id', $uid)->where('partner_state', 'proposed')->first();

        if ($verb === '') {
            $partner = $me?->partner_id ? User::query()->find((int) $me->partner_id) : null;
            $ctx->say('partner_status', ['name' => $partner?->display_name, 'state' => $me->partner_state ?? null]);

            return;
        }
        if ($verb === 'accept' || $verb === 'decline') {
            $proposal = $theirs();
            if (! $proposal) {
                throw new HangoutException('no_proposal', 404);
            }
            $them = User::query()->find((int) $proposal->user_id);
            if ($verb === 'decline') {
                $this->world->saveProfile((int) $proposal->user_id, ['partner_id' => null, 'partner_state' => null]);
                $ctx->say('partner_declined', ['name' => $them?->display_name]);

                return;
            }
            $this->world->saveProfile((int) $proposal->user_id, ['partner_state' => 'married']);
            $this->world->saveProfile($uid, ['partner_id' => (int) $proposal->user_id, 'partner_state' => 'married']);
            $ctx->events[] = $this->events->write([
                'audience' => 'all', 'kind' => 'partner', 'room_id' => (int) $ctx->where->id, 'actor_id' => $uid,
                'data' => ['n' => $ctx->name(), 'rk' => $ctx->rank, 'with' => $them?->display_name],
            ]);

            return;
        }
        if ($verb === 'end' || $verb === 'divorce' || $verb === 'reset') {
            if ($me?->partner_id) {
                $this->db->table('hangout_profiles')->where('user_id', $me->partner_id)->where('partner_id', $uid)->update(['partner_id' => null, 'partner_state' => null]);
            }
            $this->world->saveProfile($uid, ['partner_id' => null, 'partner_state' => null]);
            $ctx->say('partner_ended');

            return;
        }
        $them = $commands->find($args);
        if (! $them || (int) $them->id === $uid) {
            throw new HangoutException('not_connected', 404, ['name' => trim($args)]);
        }
        if ($this->blockedBy($uid, [(int) $them->id])) {
            throw new HangoutException('blocked', 403, ['name' => $them->display_name]);
        }
        if ($me?->partner_state === 'married') {
            throw new HangoutException('already_partnered', 422);
        }
        $this->world->saveProfile($uid, ['partner_id' => (int) $them->id, 'partner_state' => 'proposed']);
        $ctx->events[] = $this->events->write([
            'audience' => 'user', 'kind' => 'propose', 'actor_id' => $uid,
            'data' => ['n' => $ctx->name(), 'rk' => $ctx->rank, 'to' => [['id' => (int) $them->id, 'n' => $them->display_name]]],
        ], [(int) $them->id, $uid]);
    }
}
