<?php

namespace ErnestDefoe\Hangout\Api;

use ErnestDefoe\Hangout\Access;
use ErnestDefoe\Hangout\Commands;
use ErnestDefoe\Hangout\HangoutException;
use ErnestDefoe\Hangout\Ranks;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TCZ's BBS, as the Lobby's notice board.
 *   GET    /api/hangout/bbs[?topic=id]  topics, and a topic's newest notes
 *   POST   /api/hangout/bbs             {topic, subject, body}
 *   POST   /api/hangout/bbs {delete: id}  your own note (staff: any)
 */
class BbsController extends Controller
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
        private readonly Ranks $ranks,
    ) {
    }

    protected function answer(ServerRequestInterface $request, User $actor): array
    {
        Access::assertView($actor);
        if ($request->getMethod() === 'POST') {
            return $this->write($actor, self::body($request));
        }
        $topics = $this->db->table('hangout_bbs_topics')->orderBy('position')->orderBy('id')->get()->map(fn ($t) => [
            'id' => (int) $t->id, 'name' => $t->name, 'description' => $t->description, 'n' => (int) $t->message_count,
            'last' => $t->last_at ? strtotime((string) $t->last_at) : null,
        ])->all();
        $topic = (int) Arr::get($request->getQueryParams(), 'topic', 0) ?: ($topics[0]['id'] ?? 0);
        $rows = $this->db->table('hangout_bbs_messages')->where('topic_id', $topic)->orderByDesc('id')->limit(30)->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all() ?: [0])->get()->keyBy('id');
        $staff = Ranks::can($this->ranks->of($actor), 'staff');

        return [
            'topics' => $topics,
            'topic' => $topic,
            'messages' => $rows->map(fn ($m) => [
                'id' => (int) $m->id, 'subject' => $m->subject, 'body' => $m->body, 'at' => strtotime((string) $m->created_at),
                'by' => $m->user_id ? ($users->get($m->user_id)->display_name ?? '') : '',
                'byId' => $m->user_id ? (int) $m->user_id : null,
                'mine' => $staff || ((int) $m->user_id === (int) $actor->id && ! $actor->isGuest()),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $body */
    private function write(User $actor, array $body): array
    {
        Access::assertTalk($actor);
        if (isset($body['delete'])) {
            $note = $this->db->table('hangout_bbs_messages')->where('id', (int) $body['delete'])->first();
            if (! $note || ((int) $note->user_id !== (int) $actor->id && ! Ranks::can($this->ranks->of($actor), 'staff'))) {
                throw new HangoutException('permission_denied', 403);
            }
            $this->db->table('hangout_bbs_messages')->where('id', $note->id)->delete();
            $this->db->table('hangout_bbs_topics')->where('id', $note->topic_id)->where('message_count', '>', 0)->decrement('message_count');

            return ['ok' => true];
        }
        $topic = $this->db->table('hangout_bbs_topics')->where('id', (int) ($body['topic'] ?? 0))->first();
        $subject = Commands::clean((string) ($body['subject'] ?? ''), 80);
        $text = mb_substr(trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', (string) ($body['body'] ?? '')) ?? ''), 0, 2000);
        if (! $topic || $subject === '' || $text === '') {
            throw new HangoutException('bbs_incomplete', 422);
        }
        if (! Ranks::can($this->ranks->of($actor), 'staff')) {
            $key = 'ernestdefoe-hangout.bbs.'.$actor->id.'.'.intdiv(time(), 600);
            $this->cache->add($key, 0, 700);
            if ((int) $this->cache->increment($key) > 5) {
                throw new HangoutException('slow_down', 429);
            }
        }
        $now = date('Y-m-d H:i:s');
        $id = $this->db->table('hangout_bbs_messages')->insertGetId(['topic_id' => $topic->id, 'user_id' => $actor->id, 'subject' => $subject, 'body' => $text, 'created_at' => $now]);
        $this->db->table('hangout_bbs_topics')->where('id', $topic->id)->increment('message_count', 1, ['last_at' => $now]);

        return ['id' => (int) $id];
    }
}
