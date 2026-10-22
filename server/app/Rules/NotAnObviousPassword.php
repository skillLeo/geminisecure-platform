<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NOT ONE OF THE PASSWORDS ANYBODY TRIES FIRST (M32 F9). The platform held a password to ten characters and nothing
 * else, so "password12" and "1234567890" were accepted for a console that holds every estate's money and residents.
 * This refuses the obvious: a word from the short list below, with or without digits and symbols on the end; one
 * character over and over; a run of the keyboard or of the digits; the account's own name or address. A list read on
 * this server, never a lookup sent to somebody else's.
 *
 * Used wherever a console password is set: My account, a reset, and accepting an invitation.
 */
final class NotAnObviousPassword implements ValidationRule
{
    public const MESSAGE = 'That password is one of the first anybody tries. Choose another: a short sentence of your own is easy to remember and hard to guess.';

    private const OBVIOUS = [
        'password', 'passw0rd', 'pa55word', 'p@ssword', 'p@ssw0rd', 'welcome', 'letmein', 'iloveyou', 'admin', 'administrator',
        'changeme', 'changeit', 'secret', 'qwerty', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm', 'abc', 'abcdef', 'abcdefgh',
        'monkey', 'dragon', 'football', 'baseball', 'sunshine', 'princess', 'master', 'shadow', 'superman', 'trustno',
        'gemini', 'geminisecure', 'geminisecurity', 'security', 'jamaica', 'kingston', 'phoenix', 'phoenixpark', 'estate',
    ];

    /** Runs of the digits and of the keyboard — its number row, 1234567890, included — either way round. */
    private const RUNS = ['0123456789', '9876543210', '1234567890', '0987654321', 'qwertyuiop', 'poiuytrewq', 'asdfghjkl', 'zxcvbnm', 'abcdefghijklmnopqrstuvwxyz'];

    public function __construct(private readonly ?User $user = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (self::obvious($value, $this->user)) {
            $fail(self::MESSAGE);
        }
    }

    public static function obvious(string $password, ?User $user = null): bool
    {
        $plain = mb_strtolower(trim($password));

        // The word with whatever digits and symbols were put on either end of it: "Password2026!" is "password".
        $core = (string) preg_replace('/^[^a-z]+|[^a-z]+$/u', '', $plain);

        if (in_array($plain, self::OBVIOUS, true) || ($core !== '' && in_array($core, self::OBVIOUS, true))) {
            return true;
        }

        // One character over and over, or digits alone in a run.
        if (preg_match('/^(.)\1+$/u', $plain) === 1) {
            return true;
        }

        foreach (self::RUNS as $run) {
            if (strlen($plain) >= 6 && str_contains($run, $plain)) {
                return true;
            }
        }

        if ($user instanceof User) {
            $local = mb_strtolower((string) strstr((string) $user->email, '@', true));
            $names = array_filter(array_map('mb_strtolower', preg_split('/[\s.\-_]+/', (string) $user->name) ?: []), static fn (string $n): bool => mb_strlen($n) >= 4);

            $letters = (string) preg_replace('/[^a-z]+/u', '', $plain);
            $localLetters = (string) preg_replace('/[^a-z]+/u', '', $local);

            // The address's letters, when it has any: an address of digits alone must not refuse every password of digits.
            if ($local !== '' && ($core === $local || ($localLetters !== '' && $letters === $localLetters))) {
                return true;
            }

            foreach ($names as $name) {
                if ($core === $name || $letters === $name) {
                    return true;
                }
            }
        }

        return false;
    }
}
