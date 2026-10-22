<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Invitations;
use DomainException;
use Illuminate\Console\Command;

/**
 *   php artisan platform:first-director gemisecja@gmail.com "Gemini Director" < /root/password-file
 *
 * The platform's first Director, on a production server with no Gemini staff yet (M7). See
 * `Invitations::bootstrapFirstDirector()` for why this one account cannot be invited.
 *
 * THE PASSWORD COMES ON STANDARD INPUT AND IS NEVER PRINTED. Not an argument (it would sit in
 * shell history and `ps`), not an option, and not echoed back. The deploy script generates it
 * on the server, writes it to a root-only file, and redirects that file in.
 */
class FirstDirector extends Command
{
    protected $signature = 'platform:first-director
        {email : the address the Director signs in with}
        {name : their display name}';

    protected $description = 'Create the first Gemini Director on a platform with no staff, reading the password from standard input';

    public function handle(Invitations $invitations): int
    {
        $password = rtrim((string) stream_get_contents(STDIN), "\r\n");

        try {
            $user = $invitations->bootstrapFirstDirector(
                (string) $this->argument('email'),
                (string) $this->argument('name'),
                $password,
            );
        } catch (DomainException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info("Created the first Director, {$user->email}. The password is the one supplied; it is not shown here.");

        return self::SUCCESS;
    }
}
