<?php

namespace ErnestDefoe\Hangout;

use Flarum\Settings\SettingsRepositoryInterface;

/** Setting keys, permission names and the defaults behind them. */
final class Settings
{
    public const PREFIX = 'ernestdefoe-hangout.';

    public const VIEW = 'ernestdefoe-hangout.view';
    public const TALK = 'ernestdefoe-hangout.talk';
    public const SHOUT = 'ernestdefoe-hangout.shout';

    public const NAV = 'ernestdefoe-hangout.nav';
    public const RANKS = 'ernestdefoe-hangout.ranks';
    public const SHOUT_MINUTES = 'ernestdefoe-hangout.shout_minutes';
    public const SAY_PER_MINUTE = 'ernestdefoe-hangout.say_per_minute';
    public const IDLE_MINUTES = 'ernestdefoe-hangout.idle_minutes';
    public const KEEP_DAYS = 'ernestdefoe-hangout.keep_days';
    public const TIMEZONE = 'ernestdefoe-hangout.timezone';

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function int(string $key, int $default, int $min = 0, int $max = PHP_INT_MAX): int
    {
        $value = $this->settings->get($key);

        return max($min, min($max, is_numeric($value) ? (int) $value : $default));
    }

    public function string(string $key): string
    {
        return trim((string) $this->settings->get($key));
    }

    public function shoutMinutes(): int
    {
        return $this->int(self::SHOUT_MINUTES, 5, 0, 1440);
    }

    public function sayPerMinute(): int
    {
        return $this->int(self::SAY_PER_MINUTE, 30, 1, 600);
    }

    public function idleMinutes(): int
    {
        return $this->int(self::IDLE_MINUTES, 5, 1, 240);
    }

    public function keepDays(): int
    {
        return $this->int(self::KEEP_DAYS, 7, 1, 365);
    }

    /** The forum's clock, for the sky and "it's 9:42 PM here": the admin's zone, else the server's. */
    public function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->string(self::TIMEZONE) ?: date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
