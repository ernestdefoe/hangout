<?php

namespace ErnestDefoe\Hangout\Tests\integration\api;

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class SocialTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-hangout');
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'eli', 'email' => 'eli@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 5, 'group_id' => Group::MODERATOR_ID]],
        ]);
    }

    private function api(string $method, string $path, int $as, array $body = [], array $query = []): array
    {
        $options = ['authenticatedAs' => $as];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $request = $this->request($method, '/api/hangout'.$path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function act(int $as, string $line): array
    {
        return $this->api('POST', '/act', $as, ['line' => $line])[1];
    }

    private function code(array $body): ?string
    {
        return $body['errors'][0]['code'] ?? null;
    }

    #[Test]
    public function channels_reach_their_members_in_any_room(): void
    {
        foreach ([2, 3, 4] as $id) {
            $this->api('GET', '', $id);
        }
        $this->act(2, 'channel join gaming');
        $this->act(3, 'channel join gaming');
        $this->act(3, 'go arcade');
        $head = $this->api('GET', '', 4)[1]['head'];

        $this->act(2, '-gaming pinball later?');
        [, $dana] = $this->api('GET', '/poll', 3, [], ['since' => (string) $head]);
        [, $eli] = $this->api('GET', '/poll', 4, [], ['since' => (string) $head]);
        $this->assertSame(['chan'], array_column($dana['e'], 'k'), 'dana hears it from the Arcade');
        $this->assertSame([], $eli['e'], 'eli is not on the channel');
    }

    #[Test]
    public function an_enemy_cannot_page_or_mail_you(): void
    {
        $this->api('GET', '', 2);
        $this->api('GET', '', 3);
        $this->act(3, 'enemy add normal');
        $this->assertSame('hangout_blocked', $this->code($this->api('POST', '/act', 2, ['line' => 'tell dana = hi'])[1]));
        $this->assertSame('hangout_blocked', $this->code($this->api('POST', '/act', 2, ['line' => 'mail dana = hi'])[1]));
    }

    #[Test]
    public function mail_reaches_people_who_are_away_as_a_notification(): void
    {
        $this->api('GET', '', 2);
        $out = $this->act(2, 'mail eli = see you tomorrow');
        $this->assertSame('mail_sent', $out['out'][0]['k']);

        $note = $this->database()->table('notifications')->where('user_id', 4)->first();
        $this->assertSame('hangoutMail', $note->type);

        [, $mail] = $this->api('GET', '/mail', 4);
        $this->assertSame('see you tomorrow', $mail['mail'][0]['body']);
        [, $none] = $this->api('GET', '/mail', 3);
        $this->assertSame([], $none['mail'], 'only your own mail');
    }

    #[Test]
    public function mortals_wait_between_shouts_and_druids_do_not(): void
    {
        $this->api('GET', '', 2);
        $this->api('GET', '', 5);
        $this->assertSame('shout', $this->act(2, 'shout hello all')['e'][0]['k']);
        $this->assertSame('hangout_shout_wait', $this->code($this->api('POST', '/act', 2, ['line' => '!again'])[1]));
        $this->act(5, 'shout one');
        $this->assertSame('shout', $this->act(5, 'shout two')['e'][0]['k']);
    }

    #[Test]
    public function aliases_and_partners_work_like_tcz(): void
    {
        $this->api('GET', '', 2);
        $this->api('GET', '', 3);
        $this->act(2, 'alias hi = say hello there');
        $this->assertSame('hello there everyone', $this->act(2, 'hi everyone')['e'][0]['t']);

        $this->act(2, 'partner dana');
        $this->assertSame('partner', $this->act(3, 'partner accept')['e'][0]['k']);
        [, $card] = $this->api('GET', '/people/3', 4);
        $this->assertSame('normal', $card['card']['partner']);
    }

    #[Test]
    public function the_board_takes_notes_and_only_their_writer_or_staff_takes_them_down(): void
    {
        $this->api('GET', '', 2);
        [, $board] = $this->api('GET', '/bbs', 2);
        $topic = $board['topics'][0]['id'];
        [$status, $note] = $this->api('POST', '/bbs', 2, ['topic' => $topic, 'subject' => 'Party', 'body' => 'Friday in the Lounge']);
        $this->assertSame(200, $status);
        $this->assertSame(403, $this->api('POST', '/bbs', 3, ['delete' => $note['id']])[0]);
        $this->assertSame(200, $this->api('POST', '/bbs', 5, ['delete' => $note['id']])[0]);
    }

    #[Test]
    public function channels_share_the_rooms_talking_speed(): void
    {
        $this->setting('ernestdefoe-hangout.say_per_minute', 2);
        $this->api('GET', '', 2);
        $this->act(2, 'channel join gaming');
        $this->act(2, '-gaming one');
        $this->act(2, '-gaming two');
        $this->assertSame('hangout_slow_down', $this->code($this->act(2, '-gaming three')), 'a channel can\'t be flooded');
    }
}
