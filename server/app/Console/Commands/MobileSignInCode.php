<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Guard;
use App\Models\Tenant;
use App\Notifications\ResidentSignInCode;
use App\Services\Devices\DeviceEnrolment;
use App\Services\ResidentApp\ResidentSignIn;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 *   php artisan mobile:signin-code guard
 *   php artisan mobile:signin-code resident
 *   php artisan mobile:signin-code resident --email=someone@example.com
 *
 * Mints a fresh sign-in code for a mobile TEST account and prints it, so an
 * automated check (or a developer) can sign in without a human in the loop.
 *
 *   guard      takes the test guard's handset out of service, then issues a
 *              one-time enrolment code. With no handset bound, the next phone
 *              to present the code is enrolled AT ONCE — no supervisor approval.
 *              Only ever the test guard (GS-9001) unless --guard names another,
 *              because un-binding a real guard's handset signs them out.
 *   resident   issues a Resident App one-time code through the real sign-in
 *              service and prints it instead of emailing it. Any code still
 *              outstanding for that address is retired first, so the sixty-second
 *              resend window never hands back a code nobody can read.
 *
 * Both codes are ISSUED by the services the apps themselves use — no code is
 * generated, hashed or inserted here — so a change to how codes work cannot
 * leave this command behind.
 *
 * LOCAL ONLY, for the same reason `mobile:test-accounts` is.
 */
class MobileSignInCode extends Command
{
    protected $signature = 'mobile:signin-code
        {who : `guard` or `resident`}
        {--guard='.MobileTestAccounts::GUARD_NUMBER.' : the test guard\'s employee number}
        {--email='.MobileTestAccounts::RESIDENT_EMAIL.' : the resident\'s email address}
        {--estate='.MobileTestAccounts::ESTATE.' : the estate the resident signs in to}
        {--plain : print the code and nothing else (for scripts)}';

    protected $description = 'Mint and print a fresh sign-in code for a mobile test account (local only)';

    public function handle(DeviceEnrolment $enrolment, ResidentSignIn $signIn): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refused: sign-in codes are minted this way on a developer\'s machine only (APP_ENV is '.app()->environment().').');

            return self::FAILURE;
        }

        return match ((string) $this->argument('who')) {
            'guard' => $this->guardCode($enrolment),
            'resident' => $this->residentCode($signIn),
            default => $this->refuse('Say `guard` or `resident`.'),
        };
    }

    private function guardCode(DeviceEnrolment $enrolment): int
    {
        $number = (string) $this->option('guard');
        $guard = Guard::query()->where('employee_number', $number)->first();

        if ($guard === null) {
            return $this->refuse("No guard with employee number [{$number}]. Run `php artisan mobile:test-accounts` first.");
        }

        try {
            // No handset bound → the code enrols the next handset at once, with no rebind to approve.
            $enrolment->revoke($guard);
            $code = $enrolment->issueCode($guard);
        } catch (DomainException $refused) {
            return $this->refuse($refused->getMessage());
        }

        return $this->show($code, "Enrolment code for {$guard->full_name} ({$number}) — valid ".DeviceEnrolment::CODE_HOURS.' hours, one enrolment:');
    }

    private function residentCode(ResidentSignIn $signIn): int
    {
        $estate = Tenant::query()->find((string) $this->option('estate'));

        if (! $estate instanceof Tenant) {
            return $this->refuse('No estate ['.$this->option('estate').'].');
        }

        $email = ResidentSignIn::normalise('email', (string) $this->option('email'));

        // Retire anything outstanding, or the resend window answers with the previous code and sends nothing.
        DB::connection('mysql')->table('resident_otps')
            ->where('tenant_id', $estate->getTenantKey())->where('channel', 'email')
            ->where('destination_hash', hash_hmac('sha256', $email, (string) config('app.key')))
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        // The code exists in plain text in exactly one place: the notification about to be sent. It is
        // caught here instead of being mailed — the same way the platform's own tests read it.
        $fake = Notification::fake();
        $signIn->requestCode($estate, 'email', $email);

        $code = null;
        foreach ($fake->sentNotifications() as $byNotifiable) {
            foreach ($byNotifiable as $byId) {
                foreach ($byId[ResidentSignInCode::class] ?? [] as $sent) {
                    $code = $sent['notification']->code;
                }
            }
        }

        if (! is_string($code)) {
            return $this->refuse('The sign-in service issued no code.');
        }

        return $this->show($code, "Resident App code for {$email} at {$estate->name} — valid ".ResidentSignIn::TTL_MINUTES.' minutes, once:');
    }

    private function show(string $code, string $heading): int
    {
        if ($this->option('plain')) {
            $this->line($code);

            return self::SUCCESS;
        }

        $this->info($heading);
        $this->newLine();
        $this->line('  '.$code);
        $this->newLine();

        return self::SUCCESS;
    }

    private function refuse(string $why): int
    {
        $this->error($why);

        return self::FAILURE;
    }
}
