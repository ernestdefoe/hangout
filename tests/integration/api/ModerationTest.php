<?php

namespace ErnestDefoe\Hangout\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class ModerationTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-flags', 'flarum-suspend', 'ernestdefoe-hangout');
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
        $request = $this->request($method, $path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function act(int $as, string $line): array
    {
        return $this->api('POST', '/api/hangout/act', $as, ['line' => $line])[1];
    }

    private function code(array $body): ?string
    {
        return $body['errors'][0]['code'] ?? null;
    }

    private function enter(int ...$ids): int
    {
        $head = 0;
        foreach ($ids as $id) {
            $head = $this->api('GET', '/api/hangout', $id)[1]['head'];
        }

        return $head;
    }

    #[Test]
    public function a_druid_removes_a_message_from_every_log(): void
    {
        $this->enter(2, 3, 5);
        $said = $this->act(2, 'something rude')['e'][0];
        $head = $said['i'];

        $this->assertSame('hangout_rank_cannot', $this->code($this->act(3, '/remove '.$said['i'])), 'members cannot remove');
        $this->assertSame('removed', $this->act(5, '/remove '.$said['i'])['out'][0]['k']);

        [, $poll] = $this->api('GET', '/api/hangout/poll', 3, [], ['since' => (string) $head]);
        $this->assertSame([['k' => 'rm', 'id' => $said['i']]], array_map(fn ($e) => ['k' => $e['k'], 'id' => $e['d']['id']], $poll['e']));
        $row = $this->database()->table('hangout_events')->where('id', $said['i'])->first();
        $this->assertSame('removed', $row->kind);
        $this->assertNull($row->body, 'the words are gone from the log for good');
        [, $state] = $this->api('GET', '/api/hangout', 4);
        $this->assertNotContains('something rude', array_column($state['room']['log'] ?? [], 't'));
    }

    #[Test]
    public function a_druid_mutes_someone_for_a_set_time(): void
    {
        $this->enter(1, 2, 3, 5);
        $this->assertSame('hangout_rank_cannot', $this->code($this->act(3, 'mute normal 5')), 'members cannot mute');
        $this->assertSame('hangout_outranked', $this->code($this->act(5, 'mute admin 5')), 'a Druid cannot mute a Deity');

        $muted = $this->act(5, 'mute normal 5')['e'][0];
        $this->assertSame(['mute', 5], [$muted['k'], $muted['d']['m']]);

        foreach (['hello?', ':waves', 'tell dana = hi', '!everyone', 'mail dana = hi', 'title loud'] as $line) {
            $out = $this->act(2, $line);
            $this->assertSame('hangout_muted', $this->code($out), $line.' is refused');
            $this->assertSame(5, $out['errors'][0]['meta']['minutes']);
        }
        [, $board] = $this->api('GET', '/api/hangout/bbs', 2);
        [$status] = $this->api('POST', '/api/hangout/bbs', 2, ['topic' => $board['topics'][0]['id'], 'subject' => 'x', 'body' => 'y']);
        $this->assertSame(403, $status, 'no notes on the board either');
        $this->assertArrayHasKey('out', $this->act(2, 'who'), 'they can still look around');

        // It runs out on its own…
        $this->database()->table('hangout_profiles')->where('user_id', 2)->update(['muted_until' => Carbon::now()->subMinute()]);
        $this->assertSame('say', $this->act(2, 'glad to be talking again')['e'][0]['k']);
        // …or a Druid lifts it.
        $this->act(5, 'mute normal 30');
        $this->assertSame('unmute', $this->act(5, 'unmute normal')['e'][0]['k']);
        $this->assertSame('say', $this->act(2, 'that is fair')['e'][0]['k']);
    }

    #[Test]
    public function any_member_reports_a_message_and_it_raises_a_flag(): void
    {
        $this->enter(2, 3, 4);
        $said = $this->act(3, 'buy cheap pills at @eli [link](javascript:alert(1))')['e'][0];

        $this->assertSame('hangout_no_such_message', $this->code($this->act(3, 'report '.$said['i'])), 'not your own');
        $this->assertSame('reported', $this->act(2, 'report '.$said['i'].' spam')['out'][0]['k']);
        $this->assertSame('reported', $this->act(2, 'report '.$said['i'])['out'][0]['k'], 'twice is fine');
        $this->assertSame(1, $this->database()->table('flags')->count(), 'but raises one flag');

        $flag = $this->database()->table('flags')->first();
        $this->assertSame([2, 'hangout', 'spam'], [(int) $flag->user_id, $flag->reason, $flag->reason_detail]);
        $post = $this->database()->table('posts')->where('id', $flag->post_id)->first();
        $this->assertNotNull($this->database()->table('discussions')->where('id', $post->discussion_id)->value('hidden_at'), 'reports live in a hidden discussion');
        $this->assertSame(0, $this->database()->table('notifications')->where('user_id', 4)->count(), 'a quoted @mention notifies nobody');

        // Staff see the flag; the reporter and everyone else can't read the report.
        [, $flags] = $this->api('GET', '/api/flags', 1);
        $this->assertCount(1, $flags['data']);
        [, $html] = $this->api('GET', '/api/posts/'.$flag->post_id, 1);
        $this->assertStringNotContainsString('href="javascript', $html['data']['attributes']['contentHtml'] ?? '');
        $this->assertStringContainsString('buy cheap pills', $html['data']['attributes']['contentHtml'] ?? '');
        $this->assertSame(404, $this->api('GET', '/api/posts/'.$flag->post_id, 2)[0]);
        $this->assertSame(404, $this->api('GET', '/api/posts/'.$flag->post_id, 4)[0]);
    }

    #[Test]
    public function a_tell_can_only_be_reported_by_who_received_it(): void
    {
        $this->enter(2, 3, 4);
        $tell = $this->act(3, 'tell normal = psst')['e'][0];
        $this->assertSame('hangout_no_such_message', $this->code($this->act(4, 'report '.$tell['i'])), 'eli never saw it');
        $this->assertSame('reported', $this->act(2, 'report '.$tell['i'])['out'][0]['k']);
    }

    #[Test]
    public function suspended_members_cannot_enter(): void
    {
        $this->enter(3);
        $this->database()->table('users')->where('id', 3)->update(['suspended_until' => Carbon::now()->addDay()]);
        $this->assertSame(403, $this->api('GET', '/api/hangout', 3)[0]);
        $this->assertSame(403, $this->api('POST', '/api/hangout/act', 3, ['line' => 'hello'])[0]);
        $this->assertSame(403, $this->api('GET', '/api/hangout/poll', 3)[0]);
    }

    #[Test]
    public function suspending_someone_puts_them_out_at_once(): void
    {
        $this->enter(3);
        $this->assertSame(1, $this->database()->table('hangout_presence')->where('user_id', 3)->count());
        [$status] = $this->api('PATCH', '/api/users/3', 1, ['data' => ['type' => 'users', 'id' => '3', 'attributes' => ['suspendedUntil' => Carbon::now()->addDay()->toIso8601String()]]]);
        $this->assertSame(200, $status);
        $this->assertSame(0, $this->database()->table('hangout_presence')->where('user_id', 3)->count());
    }
}
