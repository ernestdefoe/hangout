<?php

namespace ErnestDefoe\Hangout\Api;

use ErnestDefoe\Hangout\Access;
use ErnestDefoe\Hangout\Events;
use ErnestDefoe\Hangout\World;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/hangout/poll?since={event id}: what happened after the cursor
 * that this reader may see. Every open Hangout asks every 1.5–2 s without
 * realtime (and every 20 s with it, as a heartbeat and safety net).
 *
 * An up-to-date reader is answered from the cached head id without reading
 * the event table; the heartbeat writes at most every 25 s.
 */
class PollController extends Controller
{
    public function __construct(
        private readonly World $world,
        private readonly Events $events,
    ) {
    }

    protected function answer(ServerRequestInterface $request, User $actor): array
    {
        Access::assertView($actor);
        $query = $request->getQueryParams();
        $since = max(0, (int) Arr::get($query, 'since', 0));
        $head = $this->events->head();

        $roomId = null;
        $userId = null;
        $area = null;
        if (Access::canTalk($actor)) {
            $presence = $this->world->presence((int) $actor->id);
            if (! $presence) {
                // Swept out (a long sleep): the browser reconnects.
                return ['gone' => true];
            }
            $this->world->beat($actor);
            $roomId = (int) $presence->room_id;
            $userId = (int) $actor->id;
        } else {
            $roomId = (int) Arr::get($query, 'room', 0) ?: null;
        }

        if ($since >= $head) {
            return ['upto' => $since, 'e' => []];
        }
        if ($roomId) {
            $area = $this->world->room($roomId)?->area;
        }
        [$events, $more] = $this->events->after($since, $roomId, $area, $userId, $this->channels($userId));

        return [
            'upto' => $events ? end($events)['i'] : $head,
            'e' => $events,
            'more' => $more ?: null,
        ];
    }

    /** @return int[] channels the reader is in (none until channels exist) */
    protected function channels(?int $userId): array
    {
        return [];
    }
}
