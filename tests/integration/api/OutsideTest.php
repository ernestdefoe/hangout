<?php

namespace ErnestDefoe\Hangout\Tests\integration\api;

use Flarum\Locale\LocaleManager;
use Flarum\Messages\DialogMessageThrottler;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * A page or tell for someone who isn't in the Hangout goes out through
 * Parley, ramon/chat or core Messages, and answers come back to Tells.
 * normal (2) is in the Hangout; dana (3) is not.
 */
class OutsideTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private function prepare(string ...$extensions): void
    {
        $this->extension(...[...$extensions, 'ernestdefoe-hangout']);
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'eli', 'email' => 'eli@machine.local', 'is_email_confirmed' => 1],
            ],
        ]);
        // The test app resolves its LocaleManager before the extension's Locales
        // extender is applied, so its strings are added here (a real forum has them).
        $locales = $this->app()->getContainer()->make(LocaleManager::class);
        $locales->addTranslations('en', __DIR__.'/../../../locale/en.yml');
        $locales->clearCache();
    }

    protected function tearDown(): void
    {
        DialogMessageThrottler::$timeout = 10;
        parent::tearDown();
    }

    private function api(string $method, string $path, int $as, array $body = [], array $query = []): array
    {
        $options = ['authenticatedAs' => $as];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $request = $this->request($method, $path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function tell(int $as, string $line): array
    {
        return $this->api('POST', '/api/hangout/act', $as, ['line' => $line]);
    }

    private function db()
    {
        return $this->app()->getContainer()->make('db')->connection();
    }

    private function dialogMessages(): array
    {
        return $this->db()->table('dialog_messages')->orderBy('id')->get()->all();
    }

    #[Test]
    public function off_or_with_nothing_installed_someone_away_is_not_connected(): void
    {
        $this->prepare();
        $this->api('GET', '/api/hangout', 2);
        [$status, $body] = $this->tell(2, 'tell dana = hi');
        $this->assertSame(404, $status);
        $this->assertSame('hangout_not_connected', $body['errors'][0]['code']);
    }

    #[Test]
    public function the_setting_can_turn_it_off(): void
    {
        $this->setting('ernestdefoe-hangout.outside', 'off');
        $this->prepare('flarum-messages');
        $this->api('GET', '/api/hangout', 2);
        $this->assertSame(404, $this->tell(2, 'tell dana = hi')[0]);
        $this->assertSame([], $this->dialogMessages());
    }

    #[Test]
    public function messages_carries_it_and_the_answer_comes_back(): void
    {
        $this->prepare('flarum-messages');
        $head = $this->api('GET', '/api/hangout', 2)[1]['head'];
        [$status, $body] = $this->tell(2, 'tell dana = see you at eight?');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(['k' => 'outside_sent', 'names' => ['dana'], 'via' => 'messages'], $body['out'][0]);

        $sent = $this->dialogMessages();
        $this->assertCount(1, $sent);
        $this->assertSame(2, (int) $sent[0]->user_id);
        $this->assertStringContainsString('Whisper from the Hangout', $sent[0]->content);
        $this->assertStringContainsString('see you at eight?', $sent[0]->content);
        $this->assertStringContainsString('/hangout?tab=tells', $sent[0]->content);

        // dana answers in Messages: normal, in the Hangout, hears it in Tells.
        [$status] = $this->api('POST', '/api/dialog-messages', 3, ['data' => ['type' => 'dialog-messages',
            'attributes' => ['content' => 'yes, see you there'],
            'relationships' => ['dialog' => ['data' => ['type' => 'dialogs', 'id' => (string) $sent[0]->dialog_id]]]]]);
        $this->assertSame(201, $status);
        $events = $this->api('GET', '/api/hangout/poll', 2, [], ['since' => (string) $head])[1]['e'];
        $answer = array_values(array_filter($events, fn ($e) => ($e['d']['via'] ?? null) === 'messages'));
        $this->assertCount(1, $answer);
        $this->assertSame(['tell', 3, 'yes, see you there'], [$answer[0]['k'], $answer[0]['a'], $answer[0]['t']]);

        // reply goes back out to dana, who is still away: past the one-second
        // wait between pages, and with Messages' own ten-second pause lifted.
        sleep(2);
        DialogMessageThrottler::$timeout = 0;
        $this->assertSame(200, $this->tell(2, 'reply great')[0]);
        $this->assertCount(3, $this->dialogMessages());
    }

    #[Test]
    public function someone_in_the_hangout_gets_it_there_and_nothing_goes_out(): void
    {
        $this->prepare('flarum-messages');
        $this->api('GET', '/api/hangout', 2);
        $this->api('GET', '/api/hangout', 3);
        [$status, $body] = $this->tell(2, 'tell dana = hi');
        $this->assertSame(200, $status);
        $this->assertArrayNotHasKey('out', $body);
        $this->assertSame([], $this->dialogMessages());
    }

    #[Test]
    public function a_hangout_enemy_gets_nothing_anywhere(): void
    {
        $this->prepare('flarum-messages');
        $this->api('GET', '/api/hangout', 3);
        $this->tell(3, 'enemy add normal');
        $this->api('POST', '/api/hangout/leave', 3);
        $this->api('GET', '/api/hangout', 2);
        [$status, $body] = $this->tell(2, 'tell dana = hi');
        $this->assertSame(403, $status);
        $this->assertSame('hangout_blocked', $body['errors'][0]['code']);
        $this->assertSame([], $this->dialogMessages());
    }

    #[Test]
    public function an_answer_from_someone_made_an_enemy_since_stays_outside(): void
    {
        $this->prepare('flarum-messages');
        $head = $this->api('GET', '/api/hangout', 2)[1]['head'];
        $this->tell(2, 'tell dana = hi');
        $this->tell(2, 'enemy add dana');
        $dialog = $this->dialogMessages()[0]->dialog_id;
        $this->api('POST', '/api/dialog-messages', 3, ['data' => ['type' => 'dialog-messages',
            'attributes' => ['content' => 'hello?'],
            'relationships' => ['dialog' => ['data' => ['type' => 'dialogs', 'id' => (string) $dialog]]]]]);
        $events = $this->api('GET', '/api/hangout/poll', 2, [], ['since' => (string) $head])[1]['e'];
        $this->assertSame([], array_filter($events, fn ($e) => isset($e['d']['via'])));
    }

    #[Test]
    public function parley_carries_it_one_way(): void
    {
        $this->prepare('ernestdefoe-parley');
        $this->api('GET', '/api/hangout', 2);
        [$status, $body] = $this->tell(2, 'tell dana = over here');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('parley', $body['out'][0]['via']);
        $messages = $this->db()->table('parley_messages')->get()->all();
        $this->assertCount(1, $messages);
        $this->assertSame(2, (int) $messages[0]->user_id);
        $this->assertStringContainsString('Whisper from the Hangout: over here', $messages[0]->body);
    }

    #[Test]
    public function a_parley_block_refuses_it_and_it_does_not_fall_through_to_messages(): void
    {
        $this->prepare('ernestdefoe-parley', 'flarum-messages');
        $this->db()->table('parley_blocks')->insert(['user_id' => 3, 'blocked_id' => 2, 'created_at' => date('Y-m-d H:i:s')]);
        $this->api('GET', '/api/hangout', 2);
        [$status, $body] = $this->tell(2, 'tell dana = hi');
        $this->assertSame(403, $status);
        $this->assertSame('hangout_outside_refused', $body['errors'][0]['code']);
        $this->assertSame(0, $this->db()->table('parley_messages')->count());
        $this->assertSame([], $this->dialogMessages());
    }

    #[Test]
    public function the_order_setting_picks_the_target(): void
    {
        $this->setting('ernestdefoe-hangout.outside', 'messages,parley,chat');
        $this->prepare('ernestdefoe-parley', 'flarum-messages');
        $this->api('GET', '/api/hangout', 2);
        $this->assertSame('messages', $this->tell(2, 'tell dana = hi')[1]['out'][0]['via']);
        $this->assertSame(0, $this->db()->table('parley_messages')->count());
        $this->assertCount(1, $this->dialogMessages());
    }

    #[Test]
    public function one_target_only_does_not_fall_back_when_it_is_missing(): void
    {
        $this->setting('ernestdefoe-hangout.outside', 'parley');
        $this->prepare('flarum-messages');
        $this->api('GET', '/api/hangout', 2);
        $this->assertSame(404, $this->tell(2, 'tell dana = hi')[0]);
        $this->assertSame([], $this->dialogMessages());
    }

    #[Test]
    public function ramon_chat_carries_it_and_the_answer_comes_back(): void
    {
        $this->prepare('ramon-chat');
        $head = $this->api('GET', '/api/hangout', 2)[1]['head'];
        [$status, $body] = $this->tell(2, 'tell dana = in the arcade');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('chat', $body['out'][0]['via']);
        $row = $this->db()->table('hangout_outside')->first();
        $this->assertSame('chat', $row->target);

        [$status] = $this->api('POST', '/api/chat-messages', 3, ['data' => ['type' => 'chat-messages',
            'attributes' => ['channelId' => (int) $row->thread_id, 'content' => 'on my way']]]);
        $this->assertSame(201, $status);
        $events = $this->api('GET', '/api/hangout/poll', 2, [], ['since' => (string) $head])[1]['e'];
        $answer = array_values(array_filter($events, fn ($e) => ($e['d']['via'] ?? null) === 'chat'));
        $this->assertSame('on my way', $answer[0]['t'] ?? null);
    }

    #[Test]
    public function ramon_chat_steps_aside_when_it_hands_direct_messages_to_messages(): void
    {
        $this->setting('ernestdefoe-hangout.outside', 'chat,parley,messages');
        $this->setting('ramon-chat.direct_messages_provider', 'messages');
        $this->prepare('ramon-chat', 'flarum-messages');
        $this->api('GET', '/api/hangout', 2);
        $this->assertSame('messages', $this->tell(2, 'tell dana = hi')[1]['out'][0]['via']);
    }

    #[Test]
    public function an_unrelated_message_costs_the_hangout_one_query(): void
    {
        $this->prepare('flarum-messages');
        $this->app();
        $queries = [];
        $this->db()->listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'hangout_')) {
                $queries[] = $q->sql;
            }
        });
        $this->api('POST', '/api/dialog-messages', 3, ['data' => ['type' => 'dialog-messages',
            'attributes' => ['content' => 'hi eli', 'users' => [['id' => 4]]]]]);
        $this->assertCount(1, $queries, implode("\n", $queries));
    }

    #[Test]
    public function at_most_five_people_away_at_once(): void
    {
        $users = [];
        for ($id = 10; $id < 16; $id++) {
            $users[] = ['id' => $id, 'username' => 'u'.$id, 'email' => "u$id@machine.local", 'is_email_confirmed' => 1];
        }
        $this->prepareDatabase([User::class => $users]);
        $this->prepare('flarum-messages');
        $this->api('GET', '/api/hangout', 2);
        [$status, $body] = $this->tell(2, 'tell u10,u11,u12,u13,u14,u15 = hi all');
        $this->assertSame('hangout_outside_too_many', $body['errors'][0]['code'] ?? null, (string) $status);
        $this->assertSame([], $this->dialogMessages());
    }
}
