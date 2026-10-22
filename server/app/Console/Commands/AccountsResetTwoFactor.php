<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Console;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Console\Command;

/**
 *   php artisan accounts:reset-two-factor <email>
 *
 * A Gemini account whose authenticator is lost, and whose recovery codes are too (M11 §0.3): its key and codes
 * are forgotten, and it sets up a new authenticator at its next sign-in — which still needs its password. Run by
 * somebody with the server, after they have satisfied themselves who is asking; the reset is written to the
 * audit log. It never prints a key or a code.
 */
class AccountsResetTwoFactor extends Command
{
    protected $signature = 'accounts:reset-two-factor {email : the Gemini account\'s sign-in email}';

    protected $description = 'Forget a Gemini account\'s authenticator and recovery codes, so it sets up a new one at its next sign-in';

    public function handle(TwoFactor $twoFactor): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (! $user instanceof User) {
            $this->error('No account signs in with that email.');

            return self::FAILURE;
        }

        if ($user->console !== Console::Gemini) {
            $this->error('That account signs in to an estate console, which asks for no second factor. Nothing to reset.');

            return self::FAILURE;
        }

        $twoFactor->reset($user);
        $this->info("{$user->name}'s authenticator is forgotten. They set up a new one at their next sign-in, after their password.");

        return self::SUCCESS;
    }
}
