<?php

namespace ErnestDefoe\Hangout\Api;

use ErnestDefoe\Hangout\World;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/hangout/leave: closing the Hangout (sent as a beacon on page close). */
class LeaveController extends Controller
{
    public function __construct(private readonly World $world)
    {
    }

    protected function answer(ServerRequestInterface $request, User $actor): array
    {
        if (! $actor->isGuest()) {
            $this->world->disconnect((int) $actor->id);
        }

        return ['ok' => true];
    }
}
