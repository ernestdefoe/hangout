<?php

namespace ErnestDefoe\Hangout\Api;

use ErnestDefoe\Hangout\Access;
use ErnestDefoe\Hangout\Social;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/hangout/mail: your mail, newest first (only ever your own). */
class MailController extends Controller
{
    public function __construct(private readonly Social $social)
    {
    }

    protected function answer(ServerRequestInterface $request, User $actor): array
    {
        Access::assertTalk($actor);

        return ['mail' => $this->social->inbox((int) $actor->id)];
    }
}
