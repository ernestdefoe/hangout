<?php

namespace ErnestDefoe\Hangout;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Keeping the Hangout civil: Druids and up remove a message and mute someone
 * for a while (TCZ's staff powers), and anyone may report a message, which
 * raises a Flarum flag so it lands in the moderators' usual queue.
 *
 * Flags sit on posts, so each report is a post in one hidden "Hangout
 * reports" discussion. Hidden discussions are seen only by staff who may hide
 * discussions (and by their author, which is why an admin starts it, never
 * the reporter).
 */
final class Moderation
{
    public const DISCUSSION = 'ernestdefoe-hangout.reports_discussion';
    public const MUTE_DEFAULT = 10;
    public const MUTE_MAX = 10080;
    public const REPORTS_PER_HOUR = 10;

    /** Handlers a muted member can't use: everything other people would read. */
    public const TALK = ['say', 'ask', 'emote', 'think', 'feel', 'page', 'tell', 'remote', 'reply', 'afk', 'title', 'prefix', 'suffix',
        'describe', 'cmessage', 'dmessage', 'shout', 'yell', 'chat', 'partner', 'channel'];

    /** Kinds of event a message can be removed or reported from: the ones that carry someone's words. */
    private const SPOKEN = ['say', 'emote', 'think', 'feel', 'shout', 'yell', 'chan', 'page', 'tell', 'remote', 'connect', 'disconnect', 'afk'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
        private readonly Events $events,
        private readonly World $world,
        private readonly Ranks $ranks,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ExtensionManager $extensions,
    ) {
    }

    public function register(Commands $commands): void
    {
        $commands->register('remove', fn (Ctx $c, string $a) => $this->remove($c, $a));
        $commands->register('mute', fn (Ctx $c, string $a) => $this->mute($c, $a, $commands));
        $commands->register('unmute', fn (Ctx $c, string $a) => $this->unmute($c, $a, $commands));
        $commands->register('report', fn (Ctx $c, string $a) => $this->report($c, $a));
        $commands->guardWith(function (Ctx $ctx, string $handler, string $args) {
            if (in_array($handler, self::TALK, true) || ($handler === 'mail' && str_contains($args, '='))) {
                $this->assertNotMuted($ctx->actor);
            }
        });
    }

    public function canReport(): bool
    {
        return $this->extensions->isEnabled('flarum-flags');
    }

    /** Seconds left on someone's mute, 0 when they may talk. */
    public function mutedFor(int $userId): int
    {
        $until = $this->world->profile($userId)?->muted_until;

        return $until ? max(0, strtotime((string) $until) - time()) : 0;
    }

    public function assertNotMuted(User $actor): void
    {
        if ($left = $this->mutedFor((int) $actor->id)) {
            throw new HangoutException('muted', 403, ['minutes' => (int) ceil($left / 60)]);
        }
    }

    /** Staff only act on people below them: a Druid can't silence an Elder or a Deity. */
    private function assertOutranks(Ctx $ctx, int $userId): void
    {
        if (! Ranks::can($ctx->rank, 'moderate')) {
            throw new HangoutException('rank_cannot', 403);
        }
        if ($userId === (int) $ctx->actor->id || Ranks::level($this->ranks->ofMany([$userId])[$userId]) >= Ranks::level($ctx->rank)) {
            throw new HangoutException('outranked', 403);
        }
    }

    // ── remove ──────────────────────────────────────────────────

    /** remove <message id>: the line goes from every log and bubble, for good. */
    private function remove(Ctx $ctx, string $args): void
    {
        if (! Ranks::can($ctx->rank, 'moderate')) {
            throw new HangoutException('rank_cannot', 403);
        }
        $event = $this->spoken((int) trim(ltrim(trim($args), '#')));
        if (! $event || $event->audience === 'user') {
            throw new HangoutException('no_such_message', 404);
        }
        $this->assertOutranks($ctx, (int) $event->actor_id);
        $this->db->table('hangout_events')->where('id', $event->id)->update(['kind' => 'removed', 'body' => null]);
        // The same people who saw it are told to drop it.
        $ctx->events[] = $this->events->write([
            'audience' => $event->audience, 'kind' => 'rm', 'room_id' => $event->room_id, 'channel_id' => $event->channel_id,
            'area' => $event->area, 'actor_id' => (int) $ctx->actor->id, 'data' => ['id' => (int) $event->id, 'who' => (int) $event->actor_id],
        ]);
        $ctx->say('removed');
    }

    private function spoken(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }
        $event = $this->db->table('hangout_events')->where('id', $id)->first();

        return $event && $event->actor_id && in_array($event->kind, self::SPOKEN, true) ? $event : null;
    }

    // ── mute ────────────────────────────────────────────────────

    /** mute <name> [minutes]: they can't talk, page, shout or post until it runs out. */
    private function mute(Ctx $ctx, string $args, Commands $commands): void
    {
        $parts = preg_split('/\s+/', trim($args)) ?: [];
        $minutes = count($parts) > 1 && ctype_digit((string) end($parts)) ? (int) array_pop($parts) : self::MUTE_DEFAULT;
        $name = implode(' ', $parts);
        $user = $name !== '' ? $commands->find(ltrim($name, '#'), true) : null;
        if (! $user) {
            throw new HangoutException($name === '' ? 'usage' : 'no_such_person', $name === '' ? 422 : 404, ['name' => $name, 'usage' => 'mute <name> [minutes]']);
        }
        $this->assertOutranks($ctx, (int) $user->id);
        $minutes = max(1, min(self::MUTE_MAX, $minutes));
        $this->world->saveProfile((int) $user->id, ['muted_until' => date('Y-m-d H:i:s', time() + $minutes * 60)]);
        $this->notice($ctx, $user, 'mute', ['m' => $minutes]);
    }

    private function unmute(Ctx $ctx, string $args, Commands $commands): void
    {
        $user = trim($args) !== '' ? $commands->find(ltrim(trim($args), '#'), true) : null;
        if (! $user) {
            throw new HangoutException('no_such_person', 404, ['name' => trim($args)]);
        }
        $this->assertOutranks($ctx, (int) $user->id);
        $this->world->saveProfile((int) $user->id, ['muted_until' => null]);
        $this->notice($ctx, $user, 'unmute');
    }

    /** Only the two of them hear about it. */
    private function notice(Ctx $ctx, User $user, string $kind, array $data = []): void
    {
        $ctx->events[] = $this->events->write([
            'audience' => 'user', 'kind' => $kind, 'actor_id' => (int) $ctx->actor->id,
            'data' => ['n' => $ctx->name(), 'rk' => $ctx->rank, 'to' => [['id' => (int) $user->id, 'n' => $user->display_name]]] + $data,
        ], [(int) $user->id, (int) $ctx->actor->id]);
    }

    // ── report ──────────────────────────────────────────────────

    /** report <message id> [reason]: a Flarum flag for the moderators. */
    private function report(Ctx $ctx, string $args): void
    {
        if (! $this->canReport()) {
            throw new HangoutException('reports_off', 403);
        }
        $parts = preg_split('/\s+/', trim($args), 2) ?: [];
        $event = $this->spoken((int) ltrim($parts[0] ?? '', '#'));
        $uid = (int) $ctx->actor->id;
        if (! $event || ! $this->couldSee($event, $uid) || (int) $event->actor_id === $uid) {
            throw new HangoutException('no_such_message', 404);
        }
        $reason = Commands::clean($parts[1] ?? '', 200);
        if ($this->db->table('hangout_reports')->where('event_id', $event->id)->where('user_id', $uid)->exists()) {
            $ctx->say('reported');

            return;
        }
        $key = 'ernestdefoe-hangout.report.'.$uid.'.'.intdiv(time(), 3600);
        $this->cache->add($key, 0, 3700);
        if ((int) $this->cache->increment($key) > self::REPORTS_PER_HOUR) {
            throw new HangoutException('slow_down', 429);
        }

        $post = $this->db->transaction(function () use ($event, $ctx, $reason, $uid) {
            $this->db->table('hangout_reports')->insert(['event_id' => $event->id, 'user_id' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
            $post = $this->post($event, $ctx->actor, $reason);
            $this->db->table('hangout_reports')->where('event_id', $event->id)->where('user_id', $uid)->update(['post_id' => $post->id]);
            $this->db->table('flags')->insert([
                'post_id' => $post->id, 'type' => 'user', 'user_id' => $uid, 'reason' => 'hangout',
                'reason_detail' => $reason !== '' ? $reason : null, 'created_at' => Carbon::now(),
            ]);

            return $post;
        });
        $ctx->say('reported', ['post' => (int) $post->id]);
    }

    /** Only what the reporter was able to see can be reported: their tells, their channels, any room. */
    private function couldSee(object $event, int $userId): bool
    {
        return match ($event->audience) {
            'user' => $this->db->table('hangout_inbox')->where('event_id', $event->id)->where('user_id', $userId)->exists(),
            'chan' => $this->db->table('hangout_channel_members')->where('channel_id', $event->channel_id)->where('user_id', $userId)->exists(),
            default => true,
        };
    }

    /** The report as a post the flag can sit on. Written by the discussion's admin, quoting the message as code (no mentions, no links). */
    private function post(object $event, User $reporter, string $reason): CommentPost
    {
        $discussion = $this->discussion();
        $owner = $discussion->user ?? User::query()->findOrFail($discussion->user_id);
        $data = json_decode((string) $event->data, true) ?: [];
        $author = User::query()->find((int) $event->actor_id);
        $room = $event->room_id ? $this->world->room((int) $event->room_id)?->name : null;
        $text = (string) ($event->body ?? '');
        $fence = str_repeat('`', max(3, ...array_map('strlen', preg_match_all('/`+/', $text, $m) ? $m[0] : [''])) + 1);

        $lines = [
            sprintf('**%s** reported a message by **%s** (%s, message %d, %s).',
                self::plain((string) $reporter->display_name), self::plain($author ? (string) $author->display_name : (string) ($data['n'] ?? '?')),
                self::plain($room ?? $event->audience), (int) $event->id, $event->created_at),
            '',
            $fence.'text',
            $text,
            $fence,
        ];
        if ($reason !== '') {
            $lines[] = '';
            $lines[] = 'Reason: '.self::plain($reason);
        }
        $lines[] = '';
        $lines[] = 'Remove it in the Hangout with `remove '.(int) $event->id.'`'.($author ? ', mute them with `mute '.self::plain((string) $author->username).' 60`' : '').'.';

        $post = new CommentPost();
        $post->discussion_id = $discussion->id;
        $post->user_id = $owner->id;
        $post->created_at = Carbon::now();
        $post->setContentAttribute(implode("\n", $lines), $owner);
        $post->save();
        $post->refresh();

        if (! $discussion->first_post_id) {
            $discussion->setFirstPost($post);
        }
        $discussion->setLastPost($post);
        $discussion->refreshCommentCount();
        $discussion->refreshParticipantCount();
        $discussion->save();

        return $post;
    }

    /** Names and reasons outside the code block can't start a mention, a link or markup. */
    private static function plain(string $text): string
    {
        return preg_replace('/[\\\\`*_\[\]()<>#@!~|]/u', '', $text) ?? '';
    }

    /** The hidden discussion reports go to, started by the first admin the first time. */
    private function discussion(): Discussion
    {
        $id = (int) $this->settings->get(self::DISCUSSION);
        if ($id && ($discussion = Discussion::query()->find($id))) {
            return $discussion;
        }
        $admin = User::query()->whereIn('id', fn ($q) => $q->select('user_id')->from('group_user')->where('group_id', Group::ADMINISTRATOR_ID))
            ->orderBy('id')->firstOrFail();
        $discussion = Discussion::start('Hangout reports', $admin);
        $discussion->hidden_at = Carbon::now();
        $discussion->hidden_user_id = $admin->id;
        $discussion->save();
        $this->settings->set(self::DISCUSSION, $discussion->id);

        return $discussion;
    }
}
