<?php

namespace ErnestDefoe\Hangout;

use Flarum\Api\Client;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;

/**
 * A page or tell for someone who isn't in the Hangout goes out as a direct
 * message instead: through Parley, ramon/chat or core's Messages, the first
 * of the admin's order that is installed. Each goes through that extension's
 * own service or API as the sender, so its permissions, blocks and limits
 * hold. Answers in ramon/chat and Messages come back to the sender's Tells
 * while they're in the Hangout; Parley has no event to hear them by, so its
 * whispers are one way.
 */
final class Outside
{
    public const SETTING = 'ernestdefoe-hangout.outside';
    public const DEFAULT = 'parley,chat,messages';
    public const TARGETS = ['parley', 'chat', 'messages'];

    /** People one page may reach outside: each is a direct message in someone's inbox. */
    public const MAX = 5;

    /** How long an answer outside still comes back to the Hangout. */
    public const REPLY_HOURS = 24;

    private ?string $chosen = null;

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly ExtensionManager $extensions,
        private readonly Container $container,
        private readonly ConnectionInterface $db,
        private readonly Translator $translator,
        private readonly UrlGenerator $url,
    ) {
    }

    /** The first target in the admin's order that is installed, or null when it's off or none is. */
    public function target(): ?string
    {
        if ($this->chosen === null) {
            $this->chosen = '';
            $order = array_intersect(array_map('trim', explode(',', (string) ($this->settings->get(self::SETTING) ?? self::DEFAULT))), self::TARGETS);
            foreach ($order as $target) {
                if ($this->available($target)) {
                    $this->chosen = $target;
                    break;
                }
            }
        }

        return $this->chosen ?: null;
    }

    public function available(string $target): bool
    {
        return match ($target) {
            'parley' => $this->extensions->isEnabled('ernestdefoe-parley') && class_exists('Ernestdefoe\\Parley\\Conversations'),
            // ramon/chat hands its "Send message" to Messages when that is on and set so: we follow it.
            'chat' => $this->extensions->isEnabled('ramon-chat') && class_exists('Ramon\\Chat\\Channel')
                && ! ($this->extensions->isEnabled('flarum-messages') && ($this->settings->get('ramon-chat.direct_messages_provider') ?? 'messages') === 'messages'),
            'messages' => $this->extensions->isEnabled('flarum-messages') && class_exists('Flarum\\Messages\\DialogMessage'),
            default => false,
        };
    }

    /**
     * Send one whisper out. Returns false when the target refuses it (a block,
     * a permission, its own rate limit): then it isn't sent anywhere else either.
     */
    public function deliver(string $target, User $from, User $to, string $kind, string $text): bool
    {
        if (! Access::canView($to)) {
            return false;
        }
        $body = $this->body($from, $kind, $text);
        try {
            $thread = match ($target) {
                'parley' => $this->parley($from, $to, $body),
                'chat' => $this->chat($from, $to, $body),
                'messages' => $this->messages($from, $to, $body),
                default => null,
            };
        } catch (\Throwable) {
            return false;
        }
        if (! $thread) {
            return false;
        }
        $this->db->table('hangout_outside')->upsert(
            [['sender_id' => $from->id, 'recipient_id' => $to->id, 'target' => $target, 'thread_id' => $thread, 'sent_at' => date('Y-m-d H:i:s')]],
            ['sender_id', 'recipient_id', 'target'],
            ['thread_id', 'sent_at']
        );

        return true;
    }

    /**
     * An answer in a ramon/chat direct channel or a Messages dialog: the
     * Hangout members who whispered its author there lately, and are in the
     * Hangout now, see it in their Tells.
     *
     * @return int[] the sender ids it went to
     */
    public function answered(string $target, int $thread, int $authorId): array
    {
        return array_map('intval', $this->db->table('hangout_outside')
            ->where('target', $target)->where('thread_id', $thread)->where('recipient_id', $authorId)
            ->where('sent_at', '>', date('Y-m-d H:i:s', time() - self::REPLY_HOURS * 3600))
            ->pluck('sender_id')->all());
    }

    /**
     * Bring an answer outside back as a tell, to each sender who is in the
     * Hangout now and hasn't since made its author an enemy.
     */
    public function relay(string $target, int $thread, int $authorId, string $text): void
    {
        $senders = $this->answered($target, $thread, $authorId);
        $here = $senders ? array_map('intval', $this->db->table('hangout_presence')->whereIn('user_id', $senders)->pluck('user_id')->all()) : [];
        $here = $here ? array_diff($here, $this->container->make(Social::class)->blockedBy($authorId, $here)) : [];
        $text = Commands::clean($text);
        // A muted or suspended author's answer stays where it was written.
        if (! $here || $text === '' || $this->container->make(Moderation::class)->mutedFor($authorId)
            || ! ($author = User::query()->find($authorId)) || Access::suspended($author)) {
            return;
        }
        $events = $this->container->make(Events::class);
        $rank = $this->container->make(Ranks::class)->of($author);
        foreach (User::query()->whereIn('id', $here)->get() as $sender) {
            $events->write([
                'audience' => 'user', 'kind' => 'tell', 'room_id' => null, 'actor_id' => (int) $author->id, 'body' => $text,
                'data' => ['n' => $author->display_name, 'rk' => $rank, 'loc' => '', 'via' => $target, 'to' => [['id' => (int) $sender->id, 'n' => $sender->display_name]]],
            ], [(int) $sender->id, (int) $author->id]);
        }
    }

    private function body(User $from, string $kind, string $text): string
    {
        $line = match ($kind) {
            'remote' => $from->display_name.' '.$text,
            'page' => $text !== '' ? $text : $this->translator->trans('ernestdefoe-hangout.forum.outside.looking', ['name' => $from->display_name]),
            default => $text,
        };

        return $this->translator->trans('ernestdefoe-hangout.forum.outside.body', [
            'label' => $this->translator->trans('ernestdefoe-hangout.forum.outside.label'),
            'text' => $line,
            'url' => $this->url->to('forum')->route('hangout').'?tab=tells',
        ]);
    }

    /** Parley's own service: its gate refuses blocked people, closed inboxes and the unconfirmed. */
    private function parley(User $from, User $to, string $body): int
    {
        $conversations = $this->container->make('Ernestdefoe\\Parley\\Conversations');
        $conversation = $conversations->pair($from, $to);
        $conversations->send($conversation, $from, 'text', $body);

        return (int) $conversation->id;
    }

    /** ramon/chat's public API, as the sender: startDirect, visibility, its rate limits. */
    private function chat(User $from, User $to, string $body): ?int
    {
        $client = $this->client($from);
        $started = json_decode((string) $client->withBody(['data' => ['attributes' => ['userIds' => [(int) $to->id]]]])->post('/chat/direct')->getBody(), true);
        $channel = (int) ($started['data']['id'] ?? 0);
        if (! $channel) {
            return null;
        }
        $sent = $this->client($from)->withBody(['data' => ['type' => 'chat-messages', 'attributes' => ['channelId' => $channel, 'content' => $body]]])->post('/chat-messages');

        return $sent->getStatusCode() < 300 ? $channel : null;
    }

    /** Core Messages' API, as the sender: sendAnyMessage, and whether they can be messaged at all. */
    private function messages(User $from, User $to, string $body): ?int
    {
        $sent = $this->client($from)->withBody(['data' => ['type' => 'dialog-messages', 'attributes' => ['content' => $body, 'users' => [['id' => (int) $to->id]]]]])->post('/dialog-messages');
        $doc = json_decode((string) $sent->getBody(), true);

        return $sent->getStatusCode() < 300 ? ((int) ($doc['data']['relationships']['dialog']['data']['id'] ?? 0) ?: null) : null;
    }

    private function client(User $actor): Client
    {
        return $this->container->make(Client::class)->withActor($actor)->withoutErrorHandling();
    }
}
