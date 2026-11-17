<?php

namespace ErnestDefoe\Hangout\Tests\integration\api;

use Flarum\Locale\LocaleManager;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** The seeded rooms and doors are shown in the forum's language; what an admin renames stays as typed. */
class BuiltinTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private function boot(bool $translated): void
    {
        $this->extension('ernestdefoe-hangout');
        $this->prepareDatabase([User::class => [$this->normalUser()]]);
        // The test app resolves its LocaleManager before the extension's Locales
        // extender is applied, so the strings are added here (a real forum has them).
        $locales = $this->app()->getContainer()->make(LocaleManager::class);
        $locales->addTranslations('en', __DIR__.'/../../../locale/en.yml');
        if ($translated) {
            $locales->addTranslations('en', __DIR__.'/../../fixtures/translated.yml');
        }
        $locales->clearCache();
    }

    private function state(): array
    {
        return json_decode((string) $this->send($this->request('GET', '/api/hangout', ['authenticatedAs' => 2]))->getBody(), true);
    }

    private function db()
    {
        return $this->app()->getContainer()->make('db')->connection();
    }

    #[Test]
    public function seeded_rooms_and_doors_come_from_the_translator(): void
    {
        $this->boot(true);
        $state = $this->state();
        $this->assertSame('Le Hall', $state['room']['name']);
        $doors = array_column($state['room']['exits'], 'name', 'to');
        $arcade = $this->db()->table('hangout_rooms')->where('slug', 'arcade')->value('id');
        $rooftop = $this->db()->table('hangout_rooms')->where('slug', 'rooftop')->value('id');
        $this->assertSame('Salle de jeux', $doors[$arcade]);
        $this->assertSame('Escalier du toit', $doors[$rooftop]);
        $this->assertSame('La Salle de jeux', array_column($state['room']['exits'], 'room', 'to')[$arcade]);
        $this->assertContains('La Salle de jeux', array_column($state['rooms'], 'name'));
        // Untranslated strings fall back to the English the install was seeded with.
        $this->assertContains('The Lounge', array_column($state['rooms'], 'name'));
    }

    #[Test]
    public function an_english_forum_shows_the_same_text_as_before(): void
    {
        $this->boot(false);
        $state = $this->state();
        $this->assertSame('The Lobby', $state['room']['name']);
        $this->assertStringStartsWith('A big, friendly entrance hall.', $state['room']['description']);
        $this->assertEqualsCanonicalizing(['Arcade', 'Rooftop stairs', 'Lounge'], array_column($state['room']['exits'], 'name'));
    }

    #[Test]
    public function what_an_admin_renames_stays_as_typed(): void
    {
        $this->boot(true);
        $this->db()->table('hangout_rooms')->where('slug', 'lobby')->update(['name' => 'Main Hall']);
        $this->db()->table('hangout_exits')->where('label', 'Arcade')->update(['label' => 'Game room']);
        $state = $this->state();
        $this->assertSame('Main Hall', $state['room']['name']);
        $this->assertContains('Game room', array_column($state['room']['exits'], 'name'));
        $this->assertContains('Escalier du toit', array_column($state['room']['exits'], 'name'));
    }
}
