<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DemonstrationAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Give one estate one committee account, without re-seeding the estate.
 *
 * WHY THIS EXISTS. `phoenix:demo-seed` creates the whole committee, and it
 * rightly refuses to run twice on a live estate — it writes invented history,
 * and an estate somebody is demonstrating from should not have its meetings,
 * charges and incidents rewritten to add one person. But M10 §2 adds an eighth
 * committee role, and every estate already provisioned needs that one account
 * without the other seven being touched.
 *
 * THE PASSWORD IS NEVER PRINTED. It is generated here and appended to the
 * estate's own credentials file on the private disk, mode 600, which is where
 * `phoenix:demo-seed` already puts the rest of the committee's. The command
 * prints the path and not the secret.
 *
 * Idempotent: an account that already exists is reported and left alone,
 * including its password. Re-running to "fix" a forgotten password would rotate
 * a credential somebody is holding.
 */
class EstateAddCommitteeAccount extends Command
{
    protected $signature = 'estate:add-committee-account
        {estate : the tenant id, e.g. phoenixparkvillage1}
        {role : the role name, e.g. estate.head_of_security}
        {name : the person\'s name}
        {email : their sign-in address}';

    protected $description = 'Add one committee account to an estate without re-seeding it';

    public function handle(): int
    {
        $estateId = (string) $this->argument('estate');
        $roleName = (string) $this->argument('role');
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $estate = Tenant::query()->find($estateId);

        if ($estate === null) {
            $this->error("No estate [{$estateId}] on this platform.");

            return self::FAILURE;
        }

        if (! str_starts_with($roleName, 'estate.')) {
            $this->error("[{$roleName}] is not an estate role. This command adds committee accounts, not Gemini staff.");

            return self::FAILURE;
        }

        $role = Role::query()->where('name', $roleName)->first();

        if ($role === null) {
            $this->error("No role [{$roleName}]. Run `php artisan db:seed --class=RbacMatrixSeeder --force` first.");

            return self::FAILURE;
        }

        /*
         * ANOTHER ESTATE'S OWN ROLE IS NOT ONE THIS ESTATE CAN HOLD (M10 §4). A
         * community's custom role carries its tenant id and is named
         * `estate.custom.<estate>.<slug>`, so it passes the prefix check above;
         * this is the tenant fence the console applies, applied here too.
         */
        if ($role->tenant_id !== null && $role->tenant_id !== $estateId) {
            $this->error("[{$roleName}] belongs to another estate and cannot be held on {$estateId}.");

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            /*
             * Left alone, password included. Re-running this to "fix" a
             * forgotten password would rotate a credential somebody is already
             * holding, and the console has a reset flow for that.
             */
            EstateAssignment::updateOrCreate(
                ['user_id' => $existing->id, 'tenant_id' => $estateId],
                ['role_id' => $role->id, 'is_active' => true],
            );

            $this->info("{$email} already exists — the assignment is in place and the password is untouched.");
            $this->reportContactGate($existing);

            return self::SUCCESS;
        }

        $password = Str::password(20);

        $user = User::create([
            'name' => (string) $this->argument('name'),
            'email' => $email,
            'password' => Hash::make($password),
            'console' => Console::Estate->value,
            'status' => 'active',
        ]);

        EstateAssignment::create([
            'user_id' => $user->id,
            'tenant_id' => $estateId,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        /*
         * Appended to the estate's own credentials file on the PRIVATE disk, not
         * printed. A password on a terminal is a password in a scrollback buffer,
         * a screen recording and a support ticket.
         */
        $path = "{$estateId}-committee-credentials.txt";

        Storage::disk('local')->append(
            $path,
            sprintf("%s  %s  %s  %s", now()->toDateTimeString(), $role->label ?? $roleName, $email, $password),
        );

        $this->info("Created {$email} as {$role->label}.");
        $this->line('  The password was written to the private disk, not printed:');
        $this->line('    storage/app/private/'.$path);
        $this->reportContactGate($user);

        return self::SUCCESS;
    }

    /**
     * PRE-VERIFY A DEMONSTRATION ACCOUNT, AND SAY WHAT HAPPENS TO A REAL ONE (M10 §1.4).
     *
     * This command adds demonstration accounts as well as real ones — M10 §2's
     * estate Head of Security was added to the demonstration estate with it. An
     * account given an address on the `demo.geminisl.com` domain `phoenix:demo-seed`
     * uses (or on `.test`) is one no mailbox answers, so the contact gate's one-time
     * code could never reach it. Such an account is pre-verified, by
     * `DemonstrationAccounts`, which refuses every address a person could own.
     *
     * AN ACCOUNT FOR A REAL PERSON IS LEFT UNVERIFIED, deliberately. Only the person
     * who reads a mailbox can prove it; the command says so, so whoever ran it can
     * tell the new committee member what the first sign-in will ask of them.
     */
    private function reportContactGate(User $user): void
    {
        if (DemonstrationAccounts::verify($user) || DemonstrationAccounts::isDemonstrationAddress((string) $user->email)) {
            $this->line('  A demonstration address: pre-verified for alerts, so the contact gate will not stop it.');

            return;
        }

        if ($user->hasVerifiedContact()) {
            $this->line('  Its alert address is already verified.');

            return;
        }

        $this->line('  At first sign-in they will be asked to confirm an alert address with a code sent by email.');
    }
}
