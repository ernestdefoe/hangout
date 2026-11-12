<?php

namespace ErnestDefoe\Hangout\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * "X sent you Hangout mail." The subject is the sender (a User, which has an
 * API resource: a subject without one 500s every notification list); the
 * mail's id rides in the data, so each mail is its own notification.
 */
class MailBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public User $sender, public int $mailId)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->sender;
    }

    public function getFromUser(): ?User
    {
        return $this->sender;
    }

    public function getData(): mixed
    {
        return ['mail' => $this->mailId];
    }

    public static function getType(): string
    {
        return 'hangoutMail';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
