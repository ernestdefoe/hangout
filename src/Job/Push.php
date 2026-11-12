<?php

namespace ErnestDefoe\Hangout\Job;

use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Container\Container;

/**
 * Sends an event to each connected reader's own `private-user=<id>` channel
 * (the one channel flarum/realtime authorises for every signed-in browser).
 * A job, so a slow or dead realtime daemon never slows anyone's message: the
 * poll catches up either way.
 */
class Push extends AbstractJob
{
    /**
     * @param  int[]  $userIds
     * @param  array<string, mixed>  $event
     */
    public function __construct(public array $userIds, public array $event)
    {
    }

    public function handle(Container $container): void
    {
        if (! class_exists(\Pusher\Pusher::class) || ! $container->bound(\Pusher\Pusher::class)) {
            return;
        }
        try {
            $pusher = $container->make(\Pusher\Pusher::class);
            foreach (array_chunk($this->userIds, 100) as $chunk) {
                $pusher->trigger(array_map(fn (int $id) => 'private-user='.$id, $chunk), 'hangout', $this->event);
            }
        } catch (\Throwable) {
            // Realtime is down: every browser's poll still brings it.
        }
    }
}
