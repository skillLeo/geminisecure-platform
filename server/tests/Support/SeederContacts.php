<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Reviewer\ReviewerMode;
use App\Support\DemonstrationAccounts;
use App\Support\Seeded\ReservedContacts;
use App\Support\Seeded\Retirements;
use App\Support\Seeded\SeededPeople;
use App\Support\Seeded\Seeding;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * EVERY ADDRESS AND NUMBER A SEEDER WRITES, AND WHICH OF THEM SOMEBODY COULD RECEIVE (M35 §5.2, layer 3).
 *
 * Two readings, because a seeder writes in two ways:
 *   THE SOURCE   every string literal in every seeder, factory and seeding command — read with PHP's own tokenizer, so a
 *                comment is never mistaken for a write and an address assembled from pieces ("lot".$n."@…") is read as the
 *                pieces it is made of. A number assembled in code from a 555 prefix is refused outright: it is how the
 *                old seed wrote "876-555-0047", which can be dialled.
 *   THE ROWS     every row a seeder marked, in an estate and on the platform, after the seeders have run.
 *
 * WHAT MAY BE WRITTEN: `ReservedContacts` — an example domain or a reserved top-level name, and the 555-0100 to 555-0199
 * block. And three things the rule names on purpose, each of which is not an invented person's contact:
 *   - the demonstration's console sign-in names, on `demo.geminisl.com` — the platform's own domain, which no stranger
 *     can hold, and which carries no mailbox;
 *   - the app-store reviewer's sign-in name (`ReviewerMode::RESIDENT_EMAIL`), which is answered with a static code and
 *     never written to;
 *   - the client's own emergency numbers, transcribed from his instruction (`SiteEmergencyNumbersSeeder`): the estate's
 *     configuration, kept on launch day, and the numbers a panic texts.
 */
final class SeederContacts
{
    /** Files whose numbers are the client's real configuration, never invented people's, and why. */
    public const REAL_NUMBERS = [
        'SiteEmergencyNumbersSeeder.php' => 'the client\'s own emergency numbers, transcribed from his instruction (Q-M09)',
    ];

    /** Faker calls that make an address or a number somebody may have. `safeEmail` is example.com/.org/.net, and allowed. */
    private const FAKER = ['phoneNumber', 'e164PhoneNumber', 'tollFreePhoneNumber', 'freeEmail', 'companyEmail', 'email'];

    /**
     * Every source that seeds: the seeders, the factories, the seeding commands, and the local test accounts.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        $files = [];

        foreach (['seeders', 'factories'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(database_path($dir), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach (glob(app_path('Console/Commands/*.php')) ?: [] as $file) {
            $code = (string) file_get_contents($file);

            if (preg_match('/protected \$signature = \'([a-z0-9:-]+)/', $code, $m) === 1
                && (in_array($m[1], Seeding::SEEDING_COMMANDS, true) || $m[1] === 'mobile:test-accounts')) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every address or number in this source that a person could receive, one line each.
     *
     * @return list<string>
     */
    public static function inSource(string $code, string $file): array
    {
        $tokens = token_get_all($code);
        $found = [];
        $name = basename($file);
        $count = count($tokens);

        // A Faker call that makes a real-looking address or number, read from the code with its comments taken out.
        $bare = implode('', array_map(static fn ($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t, $tokens));
        $calls = implode('|', self::FAKER);

        if (preg_match_all('/(?:\bfake\(\)|\$this->faker|\$faker)(?:->\w+\([^()]*\))*->('.$calls.')\b/', $bare, $faker) > 0) {
            foreach ($faker[1] as $call) {
                $found[] = "{$name}: Faker's {$call}() makes an address or a number somebody may have";
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // A double-quoted string with variables in it: its literal pieces, with each variable read as "X".
            if ($token === '"') {
                $text = '';
                $line = 0;

                for ($i++; $i < $count && $tokens[$i] !== '"'; $i++) {
                    $piece = $tokens[$i];
                    $text .= is_array($piece) && $piece[0] === T_ENCAPSED_AND_WHITESPACE ? $piece[1] : 'X';
                    $line = $line ?: (is_array($piece) ? $piece[2] : 0);
                }

                array_push($found, ...self::judge($text, $name, $line, false));

                continue;
            }

            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $text = stripcslashes(substr($token[1], 1, -1));

                // An address whose domain is built in code — "…'@'.$key.…" — and the pieces after it say what it is. A
                // lone '@' passed to a function (`Str::before($email, '@')`) is a separator, not an address.
                if (str_ends_with($text, '@') && self::nextIs($tokens, $i, '.')) {
                    $rest = self::restOfExpression($tokens, $i);

                    if (str_contains($rest, 'DemonstrationAccounts::DEMO_DOMAIN')) {
                        continue;
                    }

                    if (preg_match_all("/'([^']*)'/", $rest, $pieces) > 0 && ($domain = implode('', $pieces[1])) !== '') {
                        array_push($found, ...self::judge('x@X'.$domain, $name, $token[2], true));

                        continue;
                    }

                    $found[] = "{$name}:{$token[2]}: an address whose domain is built in code — write the reserved domain as a literal";

                    continue;
                }

                array_push($found, ...self::judge($text, $name, $token[2], true));
            }
        }

        return $found;
    }

    /**
     * Every seeded person in the open estate, and on the platform for this estate, whose address or number somebody
     * could receive.
     *
     * @return list<string>
     */
    public static function inRows(string $tenant): array
    {
        return Retirements::seeingEverything(static fn (): array => self::rows($tenant));
    }

    /** @return list<string> */
    private static function rows(string $tenant): array
    {
        $found = [];
        $estate = DB::connection('tenant');

        foreach (SeededPeople::ESTATE as $table => [$email, $phone]) {
            $columns = array_values(array_filter(['id', $email, $phone]));

            foreach ($estate->table($table)->whereNotNull('seeded_at')->get($columns) as $row) {
                if ($email !== null && ($row->{$email} ?? '') !== '' && ! ReservedContacts::emailIsReserved((string) $row->{$email})) {
                    $found[] = "estate {$table} #{$row->id}: {$email} on ".ReservedContacts::domain((string) $row->{$email});
                }

                if ($phone !== null && ($row->{$phone} ?? '') !== '' && ! ReservedContacts::phoneIsFictional((string) $row->{$phone})) {
                    $found[] = "estate {$table} #{$row->id}: {$phone} can be dialled";
                }
            }
        }

        $central = DB::connection('mysql');

        foreach ($central->table('guards')->where('tenant_id', $tenant)->whereNotNull('seeded_at')->get(['id', 'email', 'phone']) as $row) {
            if (($row->email ?? '') !== '' && ! ReservedContacts::emailIsReserved((string) $row->email)) {
                $found[] = "platform guards #{$row->id}: email on ".ReservedContacts::domain((string) $row->email);
            }

            if (($row->phone ?? '') !== '' && ! ReservedContacts::phoneIsFictional((string) $row->phone)) {
                $found[] = "platform guards #{$row->id}: phone can be dialled";
            }
        }

        foreach ($central->table('invitations')->where('tenant_id', $tenant)->whereNotNull('seeded_at')->get(['id', 'email']) as $row) {
            if (($row->email ?? '') !== '' && ! ReservedContacts::emailIsReserved((string) $row->email)) {
                $found[] = "platform invitations #{$row->id}: email on ".ReservedContacts::domain((string) $row->email);
            }
        }

        foreach ($central->table('resident_accounts')->where('tenant_id', $tenant)->whereNotNull('seeded_at')->get(['id', 'channel', 'destination']) as $row) {
            $destination = (string) $row->destination;
            $bad = $row->channel === 'sms'
                ? ! ReservedContacts::phoneIsFictional($destination)
                : ! ReservedContacts::emailIsReserved($destination) && mb_strtolower($destination) !== ReviewerMode::RESIDENT_EMAIL;

            if ($destination !== '' && $bad) {
                $found[] = "platform resident_accounts #{$row->id}: {$row->channel} destination somebody could receive";
            }
        }

        return $found;
    }

    /**
     * One string literal, judged. `$whole` is false for an interpolated string, whose number checks would read the
     * variables' "X" as a hole.
     *
     * @return list<string>
     */
    private static function judge(string $text, string $file, int $line, bool $whole): array
    {
        $found = [];

        // Every address in it ("a@b.c", or a piece "@b.c" that an address is assembled from).
        if (preg_match_all('/(?:^|[^A-Za-z0-9._%+-])([A-Za-z0-9._%+{}$X-]*@([A-Za-z0-9.{}$X-]+\.[A-Za-z]{2,}))/', $text, $m) > 0) {
            foreach ($m[1] as $k => $address) {
                $domain = strtolower($m[2][$k]);

                if (self::allowedDomain($domain, $address)) {
                    continue;
                }

                $found[] = "{$file}:{$line}: an address on {$domain}, which can receive mail";
            }
        }

        if (! $whole || array_key_exists($file, self::REAL_NUMBERS)) {
            return $found;
        }

        $digits = (string) preg_replace('/\D+/', '', $text);

        // A whole number: only digits and the characters numbers are written with, and ten or eleven digits.
        if (preg_match('/^[\d\s().+-]+$/', $text) === 1 && (strlen($digits) === 10 || (strlen($digits) === 11 && $digits[0] === '1'))
            && ! ReservedContacts::phoneIsFictional($text)) {
            $found[] = "{$file}:{$line}: the number {$text} can be dialled — use 555-0100 to 555-0199";
        }

        // The start of a number finished in code — "876-555-".str_pad(…) — which is how dialable numbers were made.
        if (preg_match('/^\+?1?[\s.-]?\(?\d{3}\)?[\s.-]?555(?:[\s.-]0?)?$/', $text) === 1) {
            $found[] = "{$file}:{$line}: a number built in code from \"{$text}\" — use ReservedContacts::seededPhone()";
        }

        return $found;
    }

    private static function allowedDomain(string $domain, string $address): bool
    {
        // A domain made of a variable ("X") and a reserved top-level name, or a reserved domain written out.
        if (ReservedContacts::emailIsReserved('x@'.str_replace(['{', '}', '$'], '', $domain))) {
            return true;
        }

        // The demonstration's console sign-in names, on the platform's own domain.
        if ($domain === DemonstrationAccounts::DEMO_DOMAIN || str_ends_with('.'.$domain, DemonstrationAccounts::DEMO_DOMAIN_SUFFIX)) {
            return true;
        }

        return mb_strtolower($address) === ReviewerMode::RESIDENT_EMAIL;
    }

    /** @param  array<int, mixed>  $tokens */
    private static function nextIs(array $tokens, int $i, string $what): bool
    {
        for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            return $tokens[$j] === $what;
        }

        return false;
    }

    /**
     * The source of the expression after a token, up to the end of its statement or array item.
     *
     * @param  array<int, mixed>  $tokens
     */
    private static function restOfExpression(array $tokens, int $i): string
    {
        $text = '';
        $depth = 0;

        for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
            $t = $tokens[$j];
            $s = is_array($t) ? $t[1] : $t;

            if ($depth === 0 && ($s === ';' || $s === ',' || $s === ']')) {
                break;
            }

            $depth += match ($s) {
                '(', '[' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth < 0) {
                break;
            }

            $text .= $s;
        }

        return $text;
    }
}
