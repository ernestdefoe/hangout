<?php

namespace ErnestDefoe\Hangout;

use Flarum\User\User;

/** One command being run: who, where, and what goes back to their browser. */
final class Ctx
{
    /** @var array<int, array<string, mixed>> private lines for the actor: {k: locale key, …params} */
    public array $out = [];

    /** @var array<int, array<string, mixed>> events written, so the actor sees them at once */
    public array $events = [];

    /** @var array<string, mixed>|null the new room, after going through a door */
    public ?array $room = null;

    /** @var array<string, mixed> hints for the browser: open a card, switch tab… */
    public array $ui = [];

    public function __construct(
        public readonly User $actor,
        public object $presence,
        public readonly string $rank,
        public object $where,
    ) {
    }

    /** @param array<string, mixed> $params */
    public function say(string $key, array $params = []): void
    {
        $this->out[] = ['k' => $key] + $params;
    }

    public function name(): string
    {
        return (string) $this->actor->display_name;
    }
}
