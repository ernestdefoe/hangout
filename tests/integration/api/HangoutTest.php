<?php

namespace ErnestDefoe\Hangout\Tests\integration\api;

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class HangoutTest extends TestCase
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
            'group_user' => [
                ['user_id' => 5, 'group_id' => Group::MODERATOR_ID],
            ],
        ]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function api(string $method, string $path, ?int $as, array $body = [], array $query = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
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

    #[Test]
    public function opening_connects_a_member_into_the_lobby_and_tells_everyone(): void
    {
        [$status, $body] = $this->api('GET', '', 2);

        $this->assertSame(200, $status);
        $this->assertSame('The Lobby', $body['room']['name']);
        $this->assertSame(2, $body['me']['id']);
        $this->assertSame('mortal', $body['me']['rk']);
        $this->assertSame('druid', $this->api('GET', '', 5)[1]['me']['rk'], 'Mods are Druids out of the box');

        [, $poll] = $this->api('GET', '/poll', 3, [], ['since' => '0']);
        $this->api('GET', '', 3);
        [, $poll] = $this->api('GET', '/poll', 3, [], ['since' => '0']);
        $kinds = array_column($poll['e'], 'k');
        $this->assertContains('connect', $kinds);
    }

    #[Test]
    public function guests_cannot_look_in_by_default(): void
    {
        $this->assertSame(403, $this->api('GET', '', null)[0]);
        $this->assertSame(403, $this->api('GET', '/poll', null)[0]);
        $this->assertSame(403, $this->api('GET', '/who', null)[0]);
    }

    #[Test]
    public function saying_reaches_the_room_and_tells_reach_only_their_people(): void
    {
        foreach ([2, 3, 4] as $id) {
            $this->api('GET', '', $id);
        }
        $head = $this->api('GET', '', 4)[1]['head'];

        $say = $this->act(2, 'say hello there');
        $this->assertSame('say', $say['e'][0]['k']);
        $this->act(2, 'tell dana = just between us');

        [, $dana] = $this->api('GET', '/poll', 3, [], ['since' => (string) $head]);
        [, $eli] = $this->api('GET', '/poll', 4, [], ['since' => (string) $head]);

        $this->assertSame(['say', 'tell'], array_column($dana['e'], 'k'));
        $this->assertSame(['say'], array_column($eli['e'], 'k'), 'eli never sees the tell');

        $recall = $this->act(3, 'recall');
        $this->assertSame('just between us', $recall['out'][0]['items'][0]['t']);
    }

    #[Test]
    public function an_up_to_date_poll_reads_no_events(): void
    {
        $this->api('GET', '', 2);
        $head = $this->api('GET', '', 2)[1]['head'];
        [$status, $body] = $this->api('GET', '/poll', 2, [], ['since' => (string) $head]);
        $this->assertSame(200, $status);
        $this->assertSame([], $body['e']);
        $this->assertSame($head, $body['upto']);
    }

    #[Test]
    public function feelings_follow_tczs_file_format(): void
    {
        $this->api('GET', '', 2);
        $this->api('GET', '', 3);

        $wave = $this->act(2, 'wave happily at dana');
        $this->assertSame('normal waves happily at dana.', $wave['e'][0]['t']);
        $this->assertSame('You wave happily at dana.', $wave['e'][0]['d']['u']);

        $hug = $this->act(2, 'hug dana = tightly');
        $this->assertSame('You hug dana tightly.', $hug['e'][0]['d']['u']);

        $puke = $this->act(2, 'puke');
        $this->assertSame('normal throws up all over the place.', $puke['e'][0]['t']);
    }

    #[Test]
    public function walking_and_doors_move_people_between_rooms(): void
    {
        $this->api('GET', '', 2);
        $this->assertTrue($this->api('POST', '/act', 2, ['walk' => [3, 9]])[1]['ok']);
        usleep(300000);
        $this->assertFalse($this->api('POST', '/act', 2, ['walk' => [99, 99]])[1]['ok']);

        $go = $this->act(2, 'go lounge');
        $this->assertSame('The Lounge', $go['room']['name']);
        $this->assertSame(2, $go['room']['people'][0]['id']);
    }

    #[Test]
    public function titles_moods_and_afk_show_on_the_who_list(): void
    {
        $this->api('GET', '', 2);
        $this->act(2, 'title the Night Owl');
        $this->act(2, 'feeling chirpy');
        $this->act(2, 'afk making coffee');

        [, $who] = $this->api('GET', '/who', 3);
        $me = $who['people'][0];
        $this->assertSame('the Night Owl', $me['ti']);
        $this->assertSame('Chirpy', $me['fe']);
        $this->assertSame('making coffee', $me['afk']);

        $this->assertSame('hangout_no_such_mood', $this->api('POST', '/act', 2, ['line' => 'feeling gruntled'])[1]['errors'][0]['code']);
    }

    #[Test]
    public function the_member_card_shows_only_posts_the_viewer_may_see(): void
    {
        $this->api('GET', '', 2);
        [$status, $body] = $this->api('GET', '/people/2', 3);
        $this->assertSame(200, $status);
        $this->assertSame(2, $body['card']['id']);
        $this->assertArrayNotHasKey('post', $body['card']);
    }

    #[Test]
    public function leaving_disconnects_and_says_goodbye(): void
    {
        $this->api('GET', '', 2);
        $this->act(2, 'dmessage heads off to bed');
        $head = $this->api('GET', '', 3)[1]['head'];
        $this->api('POST', '/leave', 2);

        // Within the grace nothing is said (a reload must not spam the room)…
        [, $soon] = $this->api('GET', '/poll', 3, [], ['since' => (string) $head]);
        $this->assertNotContains('disconnect', array_column($soon['e'], 'k'));

        // …after it, their own goodbye.
        $this->database()->table('hangout_presence')->where('user_id', 2)->update(['seen_at' => date('Y-m-d H:i:s', time() - 1000)]);
        $this->app()->getContainer()->make('cache.store')->forget('ernestdefoe-hangout.sweep');
        [, $poll] = $this->api('GET', '/poll', 3, [], ['since' => (string) $head]);
        $bye = array_values(array_filter($poll['e'], fn ($e) => $e['k'] === 'disconnect'))[0];
        $this->assertSame('heads off to bed', $bye['t']);
        $this->assertTrue($this->api('GET', '/poll', 2)[1]['gone']);
    }

    #[Test]
    public function room_state_costs_the_same_queries_for_one_person_as_for_twenty(): void
    {
        $rows = [];
        for ($i = 10; $i < 30; $i++) {
            $rows[] = ['id' => $i, 'username' => "member$i", 'email' => "m$i@machine.local", 'is_email_confirmed' => 1];
        }
        $this->prepareDatabase([User::class => $rows]);

        $count = function (): int {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->api('GET', '/who', 2);
            $n = count($db->getQueryLog());
            $db->disableQueryLog();

            return $n;
        };

        $this->api('GET', '', 2);
        $one = $count();
        for ($i = 10; $i < 30; $i++) {
            $this->api('GET', '', $i);
        }
        $twenty = $count();

        $this->assertSame($one, $twenty, "who: $one queries for one person, $twenty for twenty-one");
    }

    #[Test]
    public function every_endpoint_costs_the_same_for_one_person_as_for_twenty_one_as_guest_or_admin(): void
    {
        $rows = [];
        for ($i = 10; $i < 30; $i++) {
            $rows[] = ['id' => $i, 'username' => "member$i", 'email' => "m$i@machine.local", 'is_email_confirmed' => 1];
        }
        $this->prepareDatabase([
            User::class => $rows,
            'group_permission' => [['group_id' => Group::GUEST_ID, 'permission' => 'ernestdefoe-hangout.view']],
        ]);

        $count = function (string $path, ?int $as, array $query = []): int {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            [$status] = $this->api('GET', $path, $as, [], $query);
            $n = count($db->getQueryLog());
            $db->disableQueryLog();
            $this->assertSame(200, $status, $path);

            return $n;
        };
        $measure = function () use ($count): array {
            $head = $this->api('GET', '', 1)[1]['head'];
            $this->act(2, 'say hello');

            return [
                'state admin' => $count('', 1), 'state guest' => $count('', null),
                'poll admin' => $count('/poll', 1, ['since' => (string) $head]), 'poll guest' => $count('/poll', null, ['since' => (string) $head, 'room' => '1']),
                'who admin' => $count('/who', 1), 'who guest' => $count('/who', null),
                'card admin' => $count('/people/2', 1), 'card guest' => $count('/people/2', null),
            ];
        };

        $this->api('GET', '', 2);
        $one = $measure();
        for ($i = 10; $i < 30; $i++) {
            $this->api('GET', '', $i);
        }
        $twenty = $measure();

        @file_put_contents(__DIR__.'/../tmp/queries-'.getenv('DB_DRIVER').'.json', json_encode(['2 people' => $one, '22 people' => $twenty], JSON_PRETTY_PRINT));
        foreach ($one as $what => $n) {
            $this->assertLessThanOrEqual($n, $twenty[$what], "$what: $n queries with 2 people, {$twenty[$what]} with 22");
        }
    }

    #[Test]
    public function plain_chat_is_said_and_commands_still_work(): void
    {
        $this->api('GET', '', 2);
        $this->api('GET', '', 3);
        $kind = fn (string $line) => ($this->act(2, $line)['e'][0]['k'] ?? null) ?? ($this->act(2, $line)['out'][0]['k'] ?? null);

        $this->assertSame('say', $this->act(2, 'hello from my phone')['e'][0]['k']);
        $this->assertSame('hello from my phone', $this->act(2, 'hello from my phone')['e'][0]['t']);
        $this->assertSame('say', $this->act(2, 'who wants pinball tonight?')['e'][0]['k']);
        $this->assertSame('say', $this->act(2, ':)')['e'][0]['k']);
        $this->assertSame('say', $this->act(2, '<3 this place')['e'][0]['k']);
        $this->assertSame('feel', $this->act(2, 'wave at dana')['e'][0]['k']);
        $this->assertSame('feel', $this->act(2, 'hug dana')['e'][0]['k']);
        $this->assertSame('feel', $this->act(2, 'wave')['e'][0]['k']);
        $this->assertSame('emote', $this->act(2, ':waves')['e'][0]['k']);
        $this->assertSame('who', $this->act(2, 'who')['out'][0]['k']);
        $this->assertSame('who', $this->act(2, '/who')['out'][0]['k']);
        $this->assertSame('unknown', $this->act(2, '/blorp')['out'][0]['k']);
        $this->assertSame('tell', $this->act(2, 'tell dana = hi')['e'][0]['k']);
    }

    #[Test]
    public function two_people_never_share_a_tile(): void
    {
        foreach ([2, 3, 4] as $id) {
            $this->api('GET', '', $id);
        }
        $where = fn (int $id) => $this->database()->table('hangout_presence')->where('user_id', $id)->first(['x', 'y']);
        $spots = array_map(fn ($id) => $where($id)->x.','.$where($id)->y, [2, 3, 4]);
        $this->assertCount(3, array_unique($spots), 'arrivals spread out');

        $first = $this->api('POST', '/act', 2, ['walk' => [3, 9]])[1];
        $this->assertSame([3, 9], [$first['x'], $first['y']]);
        $second = $this->api('POST', '/act', 3, ['walk' => [3, 9]])[1];
        $this->assertTrue($second['ok']);
        $this->assertNotSame([3, 9], [$second['x'], $second['y']], 'a taken tile resolves to a free one');
        $this->assertLessThanOrEqual(1, max(abs($second['x'] - 3), abs($second['y'] - 9)), 'the nearest free one');
        $this->assertSame($second['x'].','.$second['y'], $where(3)->x.','.$where(3)->y);
    }
}
