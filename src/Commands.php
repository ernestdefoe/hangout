<?php

namespace ErnestDefoe\Hangout;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * The command line, as TCZ had it: `say`, `:waves`, `"hello`, `page Ben
 * = hi`, `>ben,tutrix how are you`, `who`, `recall`, feelings… The buttons in
 * the browser send the same lines, so everything goes through here.
 *
 * Shorthand tokens follow TCZ's include/config.h: " ' ` say, : ; pose,
 * + = think, > . tell, < , remote, ! shout, ? ask, - _ channel.
 */
final class Commands
{
    public const MAX = 500;
    public const RECALL = 8;

    /** @var array<string, string> word => handler */
    private const WORDS = [
        'say' => 'say', 'ask' => 'ask', 'emote' => 'emote', 'pose' => 'emote', 'me' => 'emote', 'think' => 'think',
        'page' => 'page', 'p' => 'page', 'tell' => 'tell', 't' => 'tell', 'remote' => 'remote', 'reply' => 'reply', 'recall' => 'recall',
        'who' => 'who', 'where' => 'where', 'look' => 'look', 'l' => 'look', 'examine' => 'look', 'scan' => 'card', 'finger' => 'card', 'profile' => 'card',
        'go' => 'go', 'walk' => 'go', 'visit' => 'go', 'home' => 'home', 'sethome' => 'sethome',
        'afk' => 'afk', 'back' => 'back', 'title' => 'title', 'prefix' => 'prefix', 'suffix' => 'suffix',
        'desc' => 'describe', 'describe' => 'describe', 'description' => 'describe', 'feeling' => 'feeling', 'mood' => 'feeling',
        'cmessage' => 'cmessage', 'connectmessage' => 'cmessage', 'dmessage' => 'dmessage', 'disconnectmessage' => 'dmessage',
        'colour' => 'colour', 'color' => 'colour', 'help' => 'help', 'commands' => 'help', 'feelings' => 'feelings', 'feels' => 'feelings', 'moods' => 'moods',
        'time' => 'time', 'quit' => 'quit', 'qquit' => 'quit',
    ];

    /** Phase 2 and later register more words here (see the Social commands). */
    /** @var array<string, callable(Ctx, string): void> */
    private array $extra = [];

    /** @var array<int, User>|null */
    private ?array $connected = null;

    public function __construct(
        private readonly World $world,
        private readonly Events $events,
        private readonly Ranks $ranks,
        private readonly Settings $settings,
        private readonly Cache $cache,
        private readonly ConnectionInterface $db,
    ) {
    }

    /** @var (callable(User, string): ?string)|null */
    private $expander = null;

    /** @var (callable(int, int[]): int[])|null */
    private $blocker = null;

    /** @var (callable(int, string): ?array<int, int>)|null */
    private $grouper = null;

    /** @var (callable(Ctx, string, string): void)|null */
    private $guard = null;

    /** @param callable(Ctx, string): void $handler */
    public function register(string $word, callable $handler): void
    {
        $this->extra[$word] = $handler;
    }

    /** Aliases: a word that stands for a longer command. */
    public function expandWith(callable $expander): void
    {
        $this->expander = $expander;
    }

    /** Who refuses pages and tells from someone (their enemies). */
    public function blockWith(callable $blocker): void
    {
        $this->blocker = $blocker;
    }

    /** A check before a command runs (a mute): handler name and arguments. */
    public function guardWith(callable $guard): void
    {
        $this->guard = $guard;
    }

    /** Named groups of people to page at once ('friends'). */
    public function groupWith(callable $grouper): void
    {
        $this->grouper = $grouper;
    }

    public function run(User $actor, string $line): Ctx
    {
        Access::assertTalk($actor);
        $presence = $this->world->connect($actor);
        $room = $this->world->room((int) $presence->room_id) ?? $this->world->defaultRoom();
        $ctx = new Ctx($actor, $presence, $this->ranks->of($actor), $room);

        $line = self::clean($line);
        if ($line === '') {
            return $ctx;
        }
        $this->world->touch($actor, $presence);
        // "/who" or "/wave ben" is always a command, the way modern chats do it.
        $forced = str_starts_with($line, '/');
        if ($forced) {
            $line = ltrim(mb_substr($line, 1));
        }
        [$word, $args] = $this->split($line);
        $aliased = false;
        // One alias expansion, TCZ style: the rest of the line is added to the end.
        if (! isset(self::WORDS[$word]) && $this->expander && ($alias = ($this->expander)($actor, $word))) {
            [$word, $more] = $this->split($alias);
            $args = trim($more.' '.$args);
            $aliased = true;
        }

        $feeling = null;
        $handler = match (true) {
            isset($this->extra[$word]) => $word,
            isset(self::WORDS[$word]) && ($forced || $aliased || $this->commandLike($word, $args)) => self::WORDS[$word],
            ($feeling = Feelings::find($word)) && ($forced || $aliased || $this->feelingLike($feeling, $args)) => 'feel',
            $forced => 'unknown',
            // Anything else is just talking: "hello from my phone" is said, not TCZ's hello feeling.
            default => 'talk',
        };
        if ($this->guard) {
            ($this->guard)($ctx, $handler === 'talk' ? 'say' : $handler, $args);
        }
        match (true) {
            isset($this->extra[$word]) => ($this->extra[$word])($ctx, $args),
            $handler === 'feel' => $this->feel($ctx, (array) $feeling, $word, $args),
            $handler === 'unknown' => $ctx->say('unknown', ['command' => $word]),
            $handler === 'talk' => $this->say($ctx, $line),
            default => $this->{$handler}($ctx, $args),
        };

        return $ctx;
    }

    /** Commands that read a few words at most; with a whole sentence after them it's chat ("who wants pinball?"). */
    private const SHORT = ['who', 'where', 'look', 'l', 'examine', 'scan', 'finger', 'profile', 'go', 'walk', 'visit', 'home', 'sethome',
        'help', 'commands', 'time', 'back', 'recall', 'feelings', 'feels', 'moods', 'quit', 'qquit'];

    private function commandLike(string $word, string $args): bool
    {
        if (! in_array($word, self::SHORT, true)) {
            return true;
        }
        $words = $args === '' ? 0 : count(preg_split('/\s+/', trim($args)) ?: []);

        return $words <= 2 && ! str_ends_with(trim($args), '?');
    }

    /**
     * A feeling only when it reads like one: on its own ("wave"), with one word
     * ("wave happily"), with one of its keywords ("wave at…"), with "=", or
     * aimed at someone who is here ("hug ben").
     *
     * @param  array<string, mixed>  $feeling
     */
    private function feelingLike(array $feeling, string $args): bool
    {
        $args = trim($args);
        if ($args === '' || str_contains($args, '=') || ! str_contains($args, ' ')) {
            return true;
        }
        // The keyword or the person may follow one adverb: "wave happily at Ben", "hug tightly ben".
        $words = preg_split('/\s+/', mb_strtolower($args)) ?: [];
        foreach ([0, 1] as $skip) {
            $rest = implode(' ', array_slice($words, $skip)).' ';
            foreach ($feeling['keywords'] as $kw) {
                if (str_starts_with($rest, $kw.' ')) {
                    return true;
                }
            }
            $name = rtrim($words[$skip] ?? '', ',');
            $this->find($name ?: '?');
            // Exact names only here: a prefix match would aim "wave the flag" at someone called Theo.
            foreach ($this->connected ?? [] as $user) {
                if ($name === mb_strtolower((string) $user->username) || $name === mb_strtolower((string) $user->display_name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{0: string, 1: string} the command word and the rest */
    private function split(string $line): array
    {
        $tokens = ['"' => 'say', "'" => 'say', '`' => 'say', ':' => 'emote', ';' => 'emote', '+' => 'think', '=' => 'think',
            '>' => 'tell', '.' => 'tell', '<' => 'remote', ',' => 'remote', '!' => 'shout', '?' => 'ask', '-' => 'chat', '_' => 'chat'];
        $first = mb_substr($line, 0, 1);
        // Quote tokens always say; the others need a letter after them, so ":)", "<3", "+1", "-1" and "..." are just said.
        $token = isset($tokens[$first]) && (in_array($first, ['"', "'", '`'], true) || preg_match('/^.\s?\p{L}/u', $line));
        if ($token) {
            return [$tokens[$first], ltrim(mb_substr($line, 1))];
        }
        $parts = preg_split('/\s+/', $line, 2) ?: [''];
        $word = ltrim(mb_strtolower($parts[0]), '@');

        return [$word, $parts[1] ?? ''];
    }

    public static function clean(string $text, int $max = self::MAX): string
    {
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return mb_substr(trim($text), 0, $max);
    }

    // ── talking in the room ─────────────────────────────────────

    /** Talking speed: the admin's lines a minute (staff three times that). Channels and the room share it. */
    public function limit(Ctx $ctx): void
    {
        $per = Ranks::level($ctx->rank) >= 7 ? $this->settings->sayPerMinute() * 3 : $this->settings->sayPerMinute();
        $key = 'ernestdefoe-hangout.talk.'.$ctx->actor->id.'.'.intdiv(time(), 60);
        $this->cache->add($key, 0, 120);
        if ((int) $this->cache->increment($key) > $per) {
            throw new HangoutException('slow_down', 429);
        }
    }

    /** @param array<string, mixed> $data */
    private function roomEvent(Ctx $ctx, string $kind, ?string $body, array $data = [], array $inbox = []): array
    {
        $event = $this->events->write([
            'audience' => 'room', 'kind' => $kind, 'room_id' => (int) $ctx->where->id, 'actor_id' => (int) $ctx->actor->id,
            'body' => $body, 'data' => ['n' => $ctx->name(), 'rk' => $ctx->rank] + $data,
        ], $inbox);
        $ctx->events[] = $event;

        return $event;
    }

    private function needText(string $args, string $usage): string
    {
        $text = self::clean($args);
        if ($text === '') {
            throw new HangoutException('usage', 422, ['usage' => $usage]);
        }

        return $text;
    }

    private function say(Ctx $ctx, string $args): void
    {
        $text = $this->needText($args, 'say <message>');
        $this->limit($ctx);
        // TCZ's construct_message: a question asks, a ! exclaims.
        $verb = str_ends_with($text, '?') ? 'asks' : (str_ends_with($text, '!') ? 'exclaims' : 'says');
        $this->roomEvent($ctx, 'say', $text, ['v' => $verb]);
    }

    private function ask(Ctx $ctx, string $args): void
    {
        $text = $this->needText($args, 'ask <question>');
        $this->limit($ctx);
        $this->roomEvent($ctx, 'say', rtrim($text, '?').'?', ['v' => 'asks']);
    }

    private function emote(Ctx $ctx, string $args): void
    {
        $text = $this->needText($args, ':<action>');
        if (! Ranks::can($ctx->rank, 'emote')) {
            throw new HangoutException('rank_cannot', 403);
        }
        $this->limit($ctx);
        $this->roomEvent($ctx, 'emote', $text);
    }

    private function think(Ctx $ctx, string $args): void
    {
        $text = $this->needText($args, 'think <thought>');
        $this->limit($ctx);
        $this->roomEvent($ctx, 'think', $text);
    }

    /** @param array<string, mixed> $feeling */
    private function feel(Ctx $ctx, array $feeling, string $word, string $args): void
    {
        $this->limit($ctx);
        $here = (int) $ctx->where->id;
        $remote = [];
        $done = Feelings::render($feeling, $ctx->name(), $args, function (string $name) use ($here, &$remote) {
            $user = $this->find($name);
            if (! $user) {
                return null;
            }
            $presence = $this->world->presence((int) $user->id);
            if ($presence && (int) $presence->room_id !== $here) {
                $remote[(int) $user->id] = true;
            }

            return [(int) $user->id, (string) $user->display_name];
        });
        $inbox = array_values(array_filter($done['targets'], fn ($id) => isset($remote[$id])));
        $this->roomEvent($ctx, 'feel', $done['others'], ['u' => $done['you'], 'f' => $feeling['names'][0], 'to' => $done['targets'] ?: null], $inbox);
    }

    // ── long distance: page, tell, remote, recall ───────────────

    private function page(Ctx $ctx, string $args): void
    {
        $this->send($ctx, 'page', $args);
    }

    private function tell(Ctx $ctx, string $args): void
    {
        $this->send($ctx, 'tell', $args);
    }

    private function remote(Ctx $ctx, string $args): void
    {
        $this->send($ctx, 'remote', $args);
    }

    /**
     * page/tell/remote <name>[,<name>…] [=] <message>, or a recall number
     * ('page 2 = thanks' answers the second message in your recall).
     */
    private function send(Ctx $ctx, string $kind, string $args): void
    {
        $args = trim($args);
        if (str_contains($args, '=')) {
            [$who, $message] = array_map('trim', explode('=', $args, 2));
        } else {
            $parts = preg_split('/\s+/', $args, 2) ?: [];
            [$who, $message] = [$parts[0] ?? '', $parts[1] ?? ''];
        }
        $message = self::clean($message);
        if ($who === '' || ($message === '' && $kind !== 'page')) {
            throw new HangoutException('usage', 422, ['usage' => $kind.' <name>[,<name>] [=] <message>']);
        }
        $targets = [];
        $group = $this->grouper ? ($this->grouper)((int) $ctx->actor->id, strtolower(trim($who))) : null;
        if ($group !== null) {
            $ids = $this->db->table('hangout_presence')->whereIn('user_id', $group ?: [0])->where('invisible', false)->pluck('user_id')->all();
            foreach ($ids ? User::query()->whereIn('id', $ids)->get() : [] as $user) {
                $targets[(int) $user->id] = $user;
            }
            if (! $targets) {
                throw new HangoutException('no_friends_on', 404);
            }
            $who = '';
        }
        foreach (array_filter(array_map('trim', explode(',', $who))) as $name) {
            $user = match (true) {
                (bool) preg_match('/^#(\d+)$/', $name, $m) => $this->find($m[1]),
                ctype_digit($name) => $this->recalled($ctx, (int) $name),
                default => $this->find($name),
            };
            if (! $user) {
                throw new HangoutException('not_connected', 404, ['name' => $name]);
            }
            $targets[(int) $user->id] = $user;
        }
        $targets = array_slice($targets, 0, 25, true);
        // TCZ: an enemy's pages and tells never arrive.
        if ($this->blocker && ($blocked = ($this->blocker)((int) $ctx->actor->id, array_keys($targets)))) {
            foreach ($blocked as $id) {
                unset($targets[$id]);
            }
            if (! $targets) {
                throw new HangoutException('blocked', 403, ['name' => '']);
            }
        }
        // Morons may only page staff (TCZ: Apprentice Wizards/Druids and above).
        if (! Ranks::can($ctx->rank, 'page_anyone')) {
            foreach ($this->ranks->ofMany(array_keys($targets)) as $rank) {
                if (! Ranks::can($rank, 'staff')) {
                    throw new HangoutException('moron_page', 403);
                }
            }
        }
        $wait = $ctx->rank === 'moron' ? 10 : 1;
        $key = 'ernestdefoe-hangout.page.'.$ctx->actor->id.'.'.implode(',', array_keys($targets));
        if (! $this->cache->add($key, 1, $wait)) {
            throw new HangoutException('page_wait', 429, ['seconds' => $wait]);
        }
        $this->limit($ctx);
        $event = $this->events->write([
            'audience' => 'user', 'kind' => $kind, 'room_id' => null, 'actor_id' => (int) $ctx->actor->id,
            'body' => $message !== '' ? $message : null,
            'data' => [
                'n' => $ctx->name(), 'rk' => $ctx->rank, 'loc' => $ctx->where->name,
                'to' => array_values(array_map(fn (User $u) => ['id' => (int) $u->id, 'n' => $u->display_name], $targets)),
            ],
        ], array_merge(array_keys($targets), [(int) $ctx->actor->id]));
        $ctx->events[] = $event;
    }

    private function reply(Ctx $ctx, string $args): void
    {
        $last = $this->events->inbox((int) $ctx->actor->id, ['page', 'tell', 'remote'], 20);
        foreach ($last as $event) {
            if (($event['a'] ?? null) !== (int) $ctx->actor->id) {
                $this->send($ctx, $event['k'] === 'page' ? 'page' : 'tell', '#'.$event['a'].' = '.$args);

                return;
            }
        }
        throw new HangoutException('nothing_to_reply', 404);
    }

    private function recalled(Ctx $ctx, int $n): ?User
    {
        $received = array_values(array_filter($this->events->inbox((int) $ctx->actor->id, ['page', 'tell', 'remote'], self::RECALL * 3), fn ($e) => ($e['a'] ?? 0) !== (int) $ctx->actor->id));
        $event = $received[$n - 1] ?? null;

        return $event ? $this->find((string) $event['a'], true) : null;
    }

    /** TCZ's recall: the last eight pages and tells sent to you. */
    private function recall(Ctx $ctx, string $args): void
    {
        $received = array_values(array_filter($this->events->inbox((int) $ctx->actor->id, ['page', 'tell', 'remote'], self::RECALL * 3), fn ($e) => ($e['a'] ?? 0) !== (int) $ctx->actor->id));
        $ctx->say('recall', ['items' => array_slice($received, 0, self::RECALL)]);
        $ctx->ui['tab'] = 'tells';
    }

    // ── who, where, look ────────────────────────────────────────

    private function who(Ctx $ctx, string $args): void
    {
        $people = $this->world->people([], $ctx->actor);
        $filter = mb_strtolower(trim($args));
        if ($filter !== '') {
            $people = array_values(array_filter($people, fn ($p) => str_starts_with(mb_strtolower($p['n']), $filter) || str_starts_with(mb_strtolower($p['u']), $filter)));
        }
        $rooms = $this->db->table('hangout_rooms')->pluck('name', 'id')->all();
        foreach ($people as &$p) {
            $p['room'] = $rooms[$p['r']] ?? '';
        }
        $ctx->say('who', ['people' => $people, 'now' => time()]);
    }

    private function where(Ctx $ctx, string $args): void
    {
        $this->who($ctx, $args);
    }

    private function look(Ctx $ctx, string $args): void
    {
        $args = trim(preg_replace('/^at\s+/i', '', trim($args)) ?? '');
        if ($args === '' || in_array(mb_strtolower($args), ['here', 'room'], true)) {
            $state = $this->world->roomState($ctx->where, $ctx->actor);
            $ctx->say('look_room', [
                'name' => $state['name'], 'description' => $state['description'],
                'exits' => array_map(fn ($e) => $e['name'], $state['exits']),
                'people' => array_values(array_map(fn ($p) => $p['n'], array_filter($state['people'], fn ($p) => $p['id'] !== (int) $ctx->actor->id))),
            ]);

            return;
        }
        $this->card($ctx, $args);
    }

    private function card(Ctx $ctx, string $args): void
    {
        $user = $this->find(trim($args), true);
        if (! $user) {
            throw new HangoutException('no_such_person', 404, ['name' => trim($args)]);
        }
        $ctx->ui['card'] = (int) $user->id;
        $profile = $this->world->profile((int) $user->id);
        $ctx->say('look_person', ['name' => $user->display_name, 'description' => $profile->description ?? null, 'feeling' => $profile->feeling ?? null]);
    }

    // ── moving ──────────────────────────────────────────────────

    private function go(Ctx $ctx, string $args): void
    {
        $name = mb_strtolower(trim(preg_replace('/^(to|the)\s+/i', '', trim($args)) ?? ''));
        if ($name === '') {
            throw new HangoutException('usage', 422, ['usage' => 'go <exit>']);
        }
        foreach ($this->world->exits((int) $ctx->where->id) as $exit) {
            $to = $this->world->room((int) $exit->target_id);
            if ($to && (str_starts_with(mb_strtolower((string) $exit->label), $name) || str_contains(mb_strtolower($to->name), $name))) {
                $this->through($ctx, $to);

                return;
            }
        }
        throw new HangoutException('no_exit', 404, ['name' => trim($args)]);
    }

    public function through(Ctx $ctx, object $to): void
    {
        if ((int) $to->id === (int) $ctx->where->id) {
            return;
        }
        if ($to->locked && ! Ranks::can($ctx->rank, 'staff')) {
            throw new HangoutException('room_locked', 403, ['room' => $to->name]);
        }
        $ctx->presence = $this->world->enter($ctx->actor, $ctx->presence, $to);
        $ctx->where = $to;
        $ctx->room = $this->world->roomState($to, $ctx->actor);
    }

    private function home(Ctx $ctx, string $args): void
    {
        $profile = $this->world->profile((int) $ctx->actor->id);
        $home = ($profile?->home_id ? $this->world->room((int) $profile->home_id) : null) ?? $this->world->defaultRoom();
        $this->through($ctx, $home);
        $ctx->say('home', ['room' => $home->name]);
    }

    private function sethome(Ctx $ctx, string $args): void
    {
        $this->world->saveProfile((int) $ctx->actor->id, ['home_id' => (int) $ctx->where->id]);
        $ctx->say('home_set', ['room' => $ctx->where->name]);
    }

    private function quit(Ctx $ctx, string $args): void
    {
        $ctx->ui['quit'] = true;
    }

    // ── about yourself ──────────────────────────────────────────

    private function afk(Ctx $ctx, string $args): void
    {
        $reason = self::clean($args, 120) ?: null;
        $this->db->table('hangout_presence')->where('user_id', $ctx->actor->id)->update(['afk' => $reason ?? '']);
        $ctx->presence->afk = $reason ?? '';
        if (! $ctx->presence->invisible) {
            $this->roomEvent($ctx, 'afk', $reason);
        }
    }

    private function back(Ctx $ctx, string $args): void
    {
        // touch() already brought them back; nothing else to do.
        $ctx->say('back');
    }

    private function profileText(Ctx $ctx, string $field, string $args, int $max, string $key): void
    {
        $value = self::clean($args, $max);
        if (in_array(mb_strtolower($value), ['reset', 'none', 'off'], true)) {
            $value = '';
        }
        $this->world->saveProfile((int) $ctx->actor->id, [$field => $value !== '' ? $value : null]);
        $ctx->say($value !== '' ? $key : $key.'_reset', ['value' => $value]);
        $this->changed($ctx);
    }

    /** Tell the room someone's name tag or card changed. */
    private function changed(Ctx $ctx): void
    {
        $presence = $this->world->presence((int) $ctx->actor->id);
        $p = $this->world->snapshot($ctx->actor, $presence, $this->world->profile((int) $ctx->actor->id));
        $ctx->events[] = $this->events->write(['audience' => 'room', 'kind' => 'upd', 'room_id' => (int) $ctx->where->id, 'actor_id' => (int) $ctx->actor->id, 'data' => ['p' => $p]]);
    }

    private function title(Ctx $ctx, string $args): void
    {
        $this->profileText($ctx, 'title', $args, 80, 'title_set');
    }

    private function prefix(Ctx $ctx, string $args): void
    {
        $this->profileText($ctx, 'prefix', $args, 40, 'prefix_set');
    }

    private function suffix(Ctx $ctx, string $args): void
    {
        $this->profileText($ctx, 'suffix', $args, 40, 'suffix_set');
    }

    private function describe(Ctx $ctx, string $args): void
    {
        $this->profileText($ctx, 'description', $args, 1000, 'description_set');
    }

    private function cmessage(Ctx $ctx, string $args): void
    {
        $this->profileText($ctx, 'cmessage', $args, 120, 'cmessage_set');
    }

    private function dmessage(Ctx $ctx, string $args): void
    {
        $this->profileText($ctx, 'dmessage', $args, 120, 'dmessage_set');
    }

    private function feeling(Ctx $ctx, string $args): void
    {
        $args = trim($args);
        if ($args === '' || $args === 'list') {
            $this->moods($ctx, '');

            return;
        }
        if (in_array(mb_strtolower($args), ['reset', 'none', 'off'], true)) {
            $this->profileText($ctx, 'feeling', '', 30, 'feeling_set');

            return;
        }
        $mood = Feelings::mood($args);
        if (! $mood) {
            throw new HangoutException('no_such_mood', 404, ['name' => $args]);
        }
        $this->profileText($ctx, 'feeling', $mood, 30, 'feeling_set');
    }

    private function colour(Ctx $ctx, string $args): void
    {
        $value = trim($args);
        if (! preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            throw new HangoutException('usage', 422, ['usage' => 'colour #e8833a']);
        }
        $this->world->saveProfile((int) $ctx->actor->id, ['color' => strtolower($value)]);
        $this->changed($ctx);
    }

    // ── help ────────────────────────────────────────────────────

    private function help(Ctx $ctx, string $args): void
    {
        $ctx->say('help', ['topic' => mb_strtolower(trim($args)) ?: 'index']);
    }

    private function feelings(Ctx $ctx, string $args): void
    {
        $ctx->say('feelings', ['names' => Feelings::names()]);
    }

    private function moods(Ctx $ctx, string $args): void
    {
        $ctx->say('moods', ['names' => Feelings::moods()]);
    }

    private function time(Ctx $ctx, string $args): void
    {
        $ctx->say('time', ['time' => (new \DateTimeImmutable('now', $this->settings->timezone()))->format('g:i A, l j F Y')]);
    }

    // ── finding people ──────────────────────────────────────────

    /**
     * A connected person by name: exact username or display name first, then
     * the one name that starts with it (TCZ's partial matching). With
     * $anyone, members who aren't connected are found by exact name too.
     */
    public function find(string $name, bool $anyone = false): ?User
    {
        $name = trim(ltrim($name, '@'));
        if ($name === '') {
            return null;
        }
        if ($this->connected === null) {
            $ids = $this->db->table('hangout_presence')->where('invisible', false)->pluck('user_id')->all();
            $this->connected = $ids ? User::query()->whereIn('id', $ids)->get()->keyBy('id')->all() : [];
        }
        if (ctype_digit($name) && isset($this->connected[(int) $name])) {
            return $this->connected[(int) $name];
        }
        $lower = mb_strtolower($name);
        $starts = [];
        foreach ($this->connected as $user) {
            $u = mb_strtolower((string) $user->username);
            $d = mb_strtolower((string) $user->display_name);
            if ($u === $lower || $d === $lower) {
                return $user;
            }
            if (str_starts_with($u, $lower) || str_starts_with($d, $lower)) {
                $starts[] = $user;
            }
        }
        if (count($starts) === 1) {
            return $starts[0];
        }
        if ($anyone) {
            return ctype_digit($name) ? User::query()->find((int) $name) : User::query()->where('username', $name)->first();
        }

        return null;
    }
}
