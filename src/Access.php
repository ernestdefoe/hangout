<?php

namespace ErnestDefoe\Hangout;

use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;

/**
 * Who may look in, talk and shout. Looking needs the forum and the Hangout's
 * view permission; talking needs a signed-in, unsuspended member with the talk
 * permission. Ranks add their own limits on top (Ranks::can).
 */
final class Access
{
    /** A suspended member can't come in at all, not even to watch. */
    public static function canView(User $actor): bool
    {
        return $actor->can('viewForum') && $actor->can(Settings::VIEW) && ! self::suspended($actor);
    }

    public static function canTalk(User $actor): bool
    {
        return ! $actor->isGuest() && self::canView($actor) && $actor->can(Settings::TALK);
    }

    public static function suspended(User $actor): bool
    {
        $until = $actor->getAttribute('suspended_until');

        return $until && strtotime((string) $until) > time();
    }

    public static function assertView(User $actor): void
    {
        if (! self::canView($actor)) {
            throw new PermissionDeniedException();
        }
    }

    public static function assertTalk(User $actor): void
    {
        if (! self::canTalk($actor)) {
            throw new PermissionDeniedException();
        }
    }
}
