<?php

namespace ErnestDefoe\Hangout\Api;

use ErnestDefoe\Hangout\Access;
use ErnestDefoe\Hangout\HangoutException;
use ErnestDefoe\Hangout\World;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/hangout/people/{id}: the member card. Their look, mood, titles and
 * time spent here, and their latest post the viewer is allowed to see.
 */
class CardController extends Controller
{
    public function __construct(private readonly World $world)
    {
    }

    protected function answer(ServerRequestInterface $request, User $actor): array
    {
        Access::assertView($actor);
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $user = User::query()->with('groups')->find($id);
        if (! $user) {
            throw new HangoutException('no_such_person', 404, ['name' => '']);
        }
        $presence = $this->world->presence($id);
        if ($presence && $presence->invisible && $id !== (int) $actor->id && ! $actor->isAdmin()) {
            $presence = null;
        }
        $profile = $this->world->profile($id);
        $card = $this->world->snapshot($user, $presence, $profile);
        $card['description'] = $profile->description ?? null;
        $card['total'] = (int) ($profile->total_seconds ?? 0) + ($presence ? time() - strtotime((string) $presence->connected_at) : 0);
        $card['joined'] = $user->joined_at?->getTimestamp();
        if ($profile?->partner_id && $profile->partner_state === 'married') {
            $card['partner'] = User::query()->find((int) $profile->partner_id)?->display_name;
        }
        $card['room'] = $presence ? $this->world->room((int) $presence->room_id)?->name : null;

        $post = CommentPost::query()->whereVisibleTo($actor)->where('user_id', $id)->where('type', 'comment')
            ->with('discussion')->orderByDesc('created_at')->first();
        if ($post && $post->discussion) {
            $card['post'] = [
                'excerpt' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $post->formatContent())) ?? ''), 0, 140),
                'discussion' => $post->discussion->title,
                'did' => (int) $post->discussion->id,
                'number' => (int) $post->number,
            ];
        }

        return ['card' => $card];
    }
}
