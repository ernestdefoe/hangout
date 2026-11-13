<?php

namespace ErnestDefoe\Hangout;

use Flarum\Foundation\AbstractServiceProvider;

/** Phase two's words (channels, mail, friends…) and the staff's join the command line wherever Commands is made. */
class HangoutServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->resolving(Commands::class, function (Commands $commands, $container) {
            $container->make(Social::class)->register($commands);
            $container->make(Moderation::class)->register($commands);
        });
    }
}
