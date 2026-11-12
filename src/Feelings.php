<?php

namespace ErnestDefoe\Hangout;

/**
 * TCZ's feeling commands ('wave', 'ack', 'aha'…) read from TCZ's own feelings
 * file format (resources/generic.feelings.tcz, ported from TCZ, plus
 * Hangout's local.feelings.tcz, which overrides it), and TCZ's list of moods
 * for `@feeling` (resources/moods.txt, from TCZ's character.c).
 *
 * The public TCZ source ships the feelings loader as empty stubs, so the
 * parsing here follows the file's documented format: `<FEELING> [<ACTION>]
 * [<KEYWORD>] [<NAMES|TEXT>]`, or `<FEELING> <NAMES> = <ACTION>`. $1 is
 * ' '+action, $2 is ' '+keyword+' ', $3 the names or text, %n the user's name,
 * %p their possessive, %. a full stop when names or text were given. TCZ's
 * colour codes (%g%l…) are dropped: the browser colours names itself.
 */
final class Feelings
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $list = null;

    /** @return array<string, array<string, mixed>> command name => feeling */
    public static function all(): array
    {
        if (self::$list === null) {
            self::$list = [];
            foreach (['generic', 'local'] as $file) {
                foreach (self::parse((string) file_get_contents(__DIR__.'/../resources/'.$file.'.feelings.tcz')) as $feeling) {
                    foreach ($feeling['names'] as $name) {
                        self::$list[$name] = $feeling;
                    }
                }
            }
            ksort(self::$list);
        }

        return self::$list;
    }

    public static function find(string $command): ?array
    {
        return self::all()[mb_strtolower($command)] ?? null;
    }

    /** @return string[] one name per feeling, for `help feelings` and the buttons */
    public static function names(): array
    {
        $seen = [];
        foreach (self::all() as $feeling) {
            $seen[$feeling['names'][0]] = true;
        }

        return array_keys($seen);
    }

    /** @return string[] */
    public static function moods(): array
    {
        static $moods = null;

        return $moods ??= array_values(array_filter(array_map('trim', file(__DIR__.'/../resources/moods.txt') ?: [])));
    }

    public static function mood(string $name): ?string
    {
        foreach (self::moods() as $mood) {
            if (strcasecmp($mood, trim($name)) === 0) {
                return $mood;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function parse(string $text): array
    {
        $out = [];
        $cur = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '#') || ! preg_match('/^\s*([A-Za-z]+):\s?(.*)$/', $line, $m)) {
                continue;
            }
            $key = strtolower($m[1]);
            $val = rtrim($m[2]);
            if ($key === 'feeling') {
                if ($cur) {
                    $out[] = $cur;
                }
                $cur = ['names' => array_values(array_filter(array_map(fn ($n) => strtolower(trim($n)), explode(';', $val)))), 'keywords' => [], 'dkeyword' => '', 'daction' => '', 'dtext' => '', 'flags' => [], 'u' => '', 'o' => '', 'r' => ''];
            } elseif ($cur !== null) {
                match ($key) {
                    'keywords' => $cur['keywords'] = array_values(array_filter(array_map('trim', explode(';', strtolower($val))))),
                    'dkeyword' => $cur['dkeyword'] = trim(strtolower($val)),
                    'daction' => $cur['daction'] = trim($val),
                    'dtext' => $cur['dtext'] = trim($val),
                    'flags' => $cur['flags'] = array_map('trim', explode('|', strtoupper($val))),
                    'uecho' => $cur['u'] = $val,
                    'oecho' => $cur['o'] = $val,
                    'recho' => $cur['r'] = $val,
                    default => null,
                };
            }
        }
        if ($cur) {
            $out[] = $cur;
        }

        return $out;
    }

    /**
     * Fill in a feeling.
     *
     * @param  callable(string): ?array{0: int, 1: string}  $findPerson  a name → [id, display name], or null
     * @return array{you: string, others: string, targets: int[]}
     */
    public static function render(array $f, string $actorName, string $args, callable $findPerson): array
    {
        $args = trim($args);
        $action = '';
        if (str_contains($args, '=')) {
            [$args, $action] = array_map('trim', explode('=', $args, 2));
        }
        $words = $args === '' ? [] : preg_split('/\s+/', $args);
        $keyword = '';
        $targets = [];
        $names = [];

        // A leading word that is neither a keyword nor a person is the action ('wave happily at Ben').
        $isKeyword = function (array $words) use ($f): string {
            foreach ($f['keywords'] as $kw) {
                $kwWords = explode(' ', $kw);
                if (array_map('strtolower', array_slice($words, 0, count($kwWords))) === $kwWords) {
                    return $kw;
                }
            }

            return '';
        };
        if ($words && $action === '' && ! $isKeyword($words) && ! $findPerson(rtrim($words[0], ',')) && count($words) >= 1) {
            $rest = array_slice($words, 1);
            if (! $rest || $isKeyword($rest) || $findPerson(rtrim($rest[0], ','))) {
                $action = array_shift($words);
            }
        }
        if ($words && ($kw = $isKeyword($words))) {
            $keyword = $kw;
            $words = array_slice($words, count(explode(' ', $kw)));
        }
        $rest = trim(implode(' ', $words));
        $text = '';
        if ($rest !== '') {
            $all = true;
            foreach (array_filter(array_map('trim', preg_split('/,|\band\b/', $rest) ?: [])) as $name) {
                $person = $findPerson($name);
                if (! $person) {
                    $all = false;
                    break;
                }
                $targets[] = $person[0];
                $names[] = $person[1];
            }
            if ($all && $names) {
                $text = self::listNames($names);
            } else {
                [$targets, $text] = [[], $rest];
            }
        }
        $given = $text !== '';
        if (! $given) {
            $text = $f['dtext'];
        }
        if ($action === '') {
            $action = $f['daction'];
        }
        if ($keyword === '' && $text !== '' && $f['keywords']) {
            $keyword = $f['dkeyword'] ?: $f['keywords'][0];
        }
        $fill = function (string $tpl, bool $you) use ($action, $keyword, $text, $given, $actorName): string {
            $s = strtr($tpl, [
                '$1' => $action !== '' ? ' '.$action : '',
                '$2' => $keyword !== '' && $text !== '' ? ' '.$keyword.' ' : ($text !== '' ? ' ' : ''),
                '$3' => $text,
                '%n' => $actorName,
                '%p' => $you ? 'your' : 'their',
                '%.' => $given ? '.' : '',
                '%%' => '%',
            ]);
            $s = preg_replace('/%[a-z]%[a-z]|%[a-z]/i', '', $s) ?? $s;

            return trim(preg_replace('/\s{2,}/', ' ', $s) ?? $s);
        };
        // Feelings that start with the verb ('goes "Baa!"') have no name in OEcho.
        $others = $fill($f['o'] ?: $f['r'], false);
        if (! str_contains($f['o'] ?: $f['r'], '%n')) {
            $others = $actorName.' '.$others;
        }

        return ['you' => $fill($f['u'], true), 'others' => $others, 'targets' => array_values(array_unique($targets))];
    }

    /** @param string[] $names */
    public static function listNames(array $names): string
    {
        if (count($names) < 2) {
            return (string) ($names[0] ?? '');
        }
        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }
}
