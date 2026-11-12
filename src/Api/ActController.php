<?php

namespace ErnestDefoe\Hangout\Api;

use ErnestDefoe\Hangout\Commands;
use ErnestDefoe\Hangout\HangoutException;
use ErnestDefoe\Hangout\World;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/hangout/act: doing something.
 *   {line: "say hello"}  any command, as typed or sent by a button
 *   {walk: [x, y]}       walk to a floor tile (sent once); answers where they ended up
 *   {exit: id}           go through a door
 */
class ActController extends Controller
{
    public function __construct(
        private readonly Commands $commands,
        private readonly World $world,
        private readonly Cache $cache,
    ) {
    }

    protected function answer(ServerRequestInterface $request, User $actor): array
    {
        $body = self::body($request);

        if (isset($body['walk']) && is_array($body['walk'])) {
            $ctx = $this->commands->run($actor, '');
            if (! $this->cache->add('ernestdefoe-hangout.walk.'.$actor->id.'.'.intdiv((int) (microtime(true) * 4), 1), 1, 1)) {
                throw new HangoutException('slow_down', 429);
            }
            $this->world->touch($actor, $ctx->presence);
            $at = $this->world->walk($actor, $ctx->presence, (int) ($body['walk'][0] ?? -1), (int) ($body['walk'][1] ?? -1));

            // Where they really ended up: a taken tile resolves to the nearest free one.
            return $at ? ['ok' => true, 'x' => $at[0], 'y' => $at[1]] : ['ok' => false];
        }

        if (isset($body['exit'])) {
            $ctx = $this->commands->run($actor, '');
            $this->world->touch($actor, $ctx->presence);
            $exit = null;
            foreach ($this->world->exits((int) $ctx->where->id) as $e) {
                if ((int) $e->id === (int) $body['exit']) {
                    $exit = $e;
                }
            }
            $to = $exit ? $this->world->room((int) $exit->target_id) : null;
            if (! $to) {
                throw new HangoutException('no_exit', 404, ['name' => '']);
            }
            $this->commands->through($ctx, $to);

            return ['room' => $ctx->room];
        }

        $ctx = $this->commands->run($actor, (string) ($body['line'] ?? ''));

        return array_filter([
            'e' => $ctx->events,
            'out' => $ctx->out,
            'room' => $ctx->room,
            'ui' => $ctx->ui ?: null,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
