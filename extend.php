<?php

/*
 * Hangout: a light 2D talker world, a modern web rewrite of The Chatting Zone
 * (TCZ) by J.P. Boggis, which grew from UglyMUG and TinyMUD. GPL-2.0-or-later.
 *
 * Members walk between rooms and talk in bubbles: say, emote, think,
 * feelings, page and tell, recall, who, ranks. Everything said is one event
 * log read by id; browsers get it pushed through flarum/realtime when it is
 * installed and poll for it every couple of seconds when not.
 *
 * forum.js only adds the route and the nav link; the world (renderer, panels,
 * command bar) is one lazy chunk.
 */

use ErnestDefoe\Hangout\Access;
use ErnestDefoe\Hangout\Api;
use ErnestDefoe\Hangout\Console\PruneCommand;
use ErnestDefoe\Hangout\HangoutServiceProvider;
use ErnestDefoe\Hangout\Http\PageContent;
use ErnestDefoe\Hangout\Notification\MailBlueprint;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\User\Event\Deleted;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the world is a chunk in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/hangout', 'hangout', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/hangout', 'hangout.state', Api\StateController::class)
        ->get('/hangout/poll', 'hangout.poll', Api\PollController::class)
        ->post('/hangout/act', 'hangout.act', Api\ActController::class)
        ->post('/hangout/leave', 'hangout.leave', Api\LeaveController::class)
        ->get('/hangout/who', 'hangout.who', Api\WhoController::class)
        ->get('/hangout/people/{id:\d+}', 'hangout.card', Api\CardController::class)
        ->get('/hangout/art', 'hangout.art', Api\AtlasController::class)
        ->get('/hangout/mail', 'hangout.mail', Api\MailController::class)
        ->get('/hangout/bbs', 'hangout.bbs', Api\BbsController::class)
        ->post('/hangout/bbs', 'hangout.bbs.write', Api\BbsController::class),

    (new Extend\ServiceProvider())
        ->register(HangoutServiceProvider::class),

    (new Extend\Notification())
        ->type(MailBlueprint::class, ['alert']),

    // A deleted member's partner is single again (the other tables cascade).
    (new Extend\Event())
        ->listen(Deleted::class, function (Deleted $event) {
            resolve('db')->table('hangout_profiles')->where('partner_id', $event->user->id)->update(['partner_id' => null, 'partner_state' => null]);
        }),

    // The viewer's own permission: already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('hangoutCanView')
                ->get(fn ($forum, Context $context) => Access::canView($context->getActor())),
        ]),

    // 🚨 String literals, not Settings:: constants: extend.php runs while a deploy
    // swaps vendor/, and a class constant read here fatals every request then.
    (new Extend\Settings())
        ->default('ernestdefoe-hangout.nav', true)
        ->default('ernestdefoe-hangout.shout_minutes', 5)
        ->default('ernestdefoe-hangout.say_per_minute', 30)
        ->default('ernestdefoe-hangout.idle_minutes', 5)
        ->default('ernestdefoe-hangout.keep_days', 7)
        ->serializeToForum('hangoutNav', 'ernestdefoe-hangout.nav', 'boolval'),

    (new Extend\Console())
        ->command(PruneCommand::class)
        ->schedule(PruneCommand::class, function ($event): void {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),
];
