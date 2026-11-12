<?php

namespace ErnestDefoe\Hangout\Api;

use ErnestDefoe\Hangout\Access;
use ErnestDefoe\Hangout\World;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/hangout/who: everyone connected, for the who list. Guests don't
 * see members who hide their online status from the forum.
 */
class WhoController extends Controller
{
    public function __construct(private readonly World $world)
    {
    }

    protected function answer(ServerRequestInterface $request, User $actor): array
    {
        Access::assertView($actor);
        $people = $this->world->people([], $actor);
        if ($actor->isGuest() && $people) {
            $hidden = User::query()->whereIn('id', array_column($people, 'id'))->get(['id', 'preferences'])
                ->filter(fn (User $u) => ! $u->getPreference('discloseOnline'))->pluck('id')->flip()->all();
            $people = array_values(array_filter($people, fn ($p) => ! isset($hidden[$p['id']])));
        }

        return ['people' => $people];
    }
}
