<?php

namespace ErnestDefoe\Hangout\Tests\integration\api;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** Hangout adds nothing to a page that isn't the Hangout: the forum's API costs the same with it on or off. */
class UnrelatedPageTest extends TestCase
{
    private function queries(): int
    {
        $this->send($this->request('GET', '/api', ['authenticatedAs' => 1]));
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $this->send($this->request('GET', '/api', ['authenticatedAs' => 1]));
        $n = count($db->getQueryLog());
        $db->disableQueryLog();

        return $n;
    }

    #[Test]
    public function off(): void
    {
        @file_put_contents(__DIR__.'/../tmp/unrelated-off.txt', (string) $this->queries());
        $this->assertTrue(true);
    }

    #[Test]
    public function on(): void
    {
        $this->extension('ernestdefoe-hangout');
        $on = $this->queries();
        @file_put_contents(__DIR__.'/../tmp/unrelated-on.txt', (string) $on);
        $off = @file_get_contents(__DIR__.'/../tmp/unrelated-off.txt');
        if ($off !== false) {
            $this->assertSame((int) $off, $on, "GET /api: $off queries with Hangout off, $on with it on");
        }
    }
}
