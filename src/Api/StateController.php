<?php

namespace ErnestDefoe\Hangout\Api;

use ErnestDefoe\Hangout\Access;
use ErnestDefoe\Hangout\Events;
use ErnestDefoe\Hangout\Feelings;
use ErnestDefoe\Hangout\Ranks;
use ErnestDefoe\Hangout\Settings;
use ErnestDefoe\Hangout\Social;
use ErnestDefoe\Hangout\World;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/hangout: opening the Hangout. A member who may talk is connected
 * (announced to everyone, unless they already were); anyone else who may look
 * in gets a room to watch without being in it.
 */
class StateController extends Controller
{
    public function __construct(
        private readonly World $world,
        private readonly Events $events,
        private readonly Ranks $ranks,
        private readonly Settings $settings,
        private readonly Social $social,
    ) {
    }

    protected function answer(ServerRequestInterface $request, User $actor): array
    {
        Access::assertView($actor);
        $talk = Access::canTalk($actor);
        $head = $this->events->head();

        if ($talk) {
            $presence = $this->world->connect($actor);
            $room = $this->world->room((int) $presence->room_id) ?? $this->world->defaultRoom();
            $me = $this->world->snapshot($actor, $presence, $this->world->profile((int) $actor->id));
        } else {
            $wanted = (int) Arr::get($request->getQueryParams(), 'room', 0);
            $room = ($wanted ? $this->world->room($wanted) : null) ?? $this->world->defaultRoom();
            $me = null;
        }
        $rank = $this->ranks->of($actor);
        $tz = $this->settings->timezone();

        return [
            'me' => $me,
            'room' => $this->world->roomState($room, $actor),
            'rooms' => $this->roomList(),
            'ranks' => $this->ranks->forBrowser(),
            'feelings' => Feelings::names(),
            'head' => $head,
            'can' => [
                'talk' => $talk,
                'shout' => $talk && $actor->can(Settings::SHOUT) && Ranks::can($rank, 'shout'),
                'staff' => $talk && Ranks::can($rank, 'staff'),
                'invisible' => $talk && Ranks::can($rank, 'invisible'),
                'channels' => $talk && ($actor->can('ernestdefoe-hangout.createChannels') || Ranks::can($rank, 'staff')),
            ],
            'rank' => $rank,
            'idle' => $this->settings->idleMinutes() * 60,
            'tz' => $tz->getOffset(new \DateTimeImmutable('now', $tz)),
            'channels' => $talk ? $this->social->channels((int) $actor->id) : [],
            'chanlog' => $talk ? $this->events->recentChannels($this->social->channelIds((int) $actor->id)) : [],
            'friends' => $talk ? $this->social->ids((int) $actor->id, 'friend') : [],
            'enemies' => $talk ? $this->social->ids((int) $actor->id, 'enemy') : [],
            'mail' => $talk ? $this->social->unread((int) $actor->id) : 0,
            'push' => class_exists(\Pusher\Pusher::class) && resolve('container')->bound(\Pusher\Pusher::class),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function roomList(): array
    {
        $counts = resolve('db')->table('hangout_presence')->where('invisible', false)->selectRaw('room_id, count(*) as c')->groupBy('room_id')->pluck('c', 'room_id')->all();

        $out = [];
        foreach ($this->world->rooms() as $r) {
            $out[] = ['id' => (int) $r->id, 'name' => $r->name, 'n' => (int) ($counts[$r->id] ?? 0)];
        }

        return $out;
    }
}
