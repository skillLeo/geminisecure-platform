<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Resident;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Models\Tenant;
use App\Support\Seeded\ReservedContacts;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 *   php artisan mobile:test-accounts
 *   php artisan mobile:test-accounts --fresh-shift
 *
 * The durable TEST accounts the mobile build signs in with, so that no session
 * ever again waits for a human to issue a code or approve a handset:
 *
 *   GUARD      GS-9001 · "Tessa Buildcheck (mobile test)" · Phoenix Park · Main Gate,
 *              with a rostered shift that covers NOW. Every seeded guard already
 *              has a handset, so an automated sign-in as any of them raises a
 *              rebind a supervisor must approve; this one exists to be enrolled
 *              and re-enrolled freely (`mobile:signin-code guard`).
 *   RESIDENT   the Resident App account of the boards' own resident, Andrea
 *              Fletcher (andrea.fletcher@example.com · Phase 2 · Lot 47). She is on
 *              the estate's register already; nothing on the platform let her
 *              sign in. The account is linked to HER register entry, so every
 *              resident screen shows the household the boards draw.
 *
 * IDEMPOTENT: run it as often as you like. It only ever ADDS or refreshes the two
 * test principals and the test guard's own shift — it never touches another
 * guard, another household, a ledger or a statutory figure.
 *
 * LOCAL ONLY. A known test login on a live platform is a back door, so this
 * refuses to run anywhere but a developer's machine or the test suite.
 */
class MobileTestAccounts extends Command
{
    public const GUARD_NUMBER = 'GS-9001';

    public const GUARD_NAME = 'Tessa Buildcheck (mobile test)';

    public const ESTATE = 'phoenixpark';

    /** The seed's address for her: on the domain kept for examples, never a mailbox anybody has (M35 §5). */
    public const RESIDENT_EMAIL = 'andrea.fletcher@example.com';

    protected $signature = 'mobile:test-accounts
        {--fresh-shift : close the test guard\'s running shift and roster a new one that has not been clocked in}';

    protected $description = 'Create or refresh the mobile build\'s durable test guard and test resident (local only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refused: test accounts are created on a developer\'s machine only (APP_ENV is '.app()->environment().').');

            return self::FAILURE;
        }

        $estate = Tenant::query()->find(self::ESTATE);

        if (! $estate instanceof Tenant) {
            $this->error('The estate ['.self::ESTATE.'] does not exist. Provision and seed the platform first.');

            return self::FAILURE;
        }

        $guard = $this->guard($estate);
        $shift = $this->shift($guard, (bool) $this->option('fresh-shift'));
        $account = $this->resident($estate);

        $this->info('Mobile test accounts are in place.');
        $this->newLine();
        $this->table(['Who', 'Signs in with', 'Details'], [
            ['Guard', 'php artisan mobile:signin-code guard', self::GUARD_NUMBER.' · '.self::GUARD_NAME.' · '.$estate->name.' · shift '.$shift->id.' '.$shift->rostered_start->toIso8601String().' → '.$shift->rostered_end->toIso8601String()],
            ['Resident', 'php artisan mobile:signin-code resident', $account === null ? 'NOT CREATED — Andrea Fletcher is not on the register (run the seeders)' : self::RESIDENT_EMAIL.' · account '.$account->id.' · '.$account->status],
        ]);

        return $account === null ? self::FAILURE : self::SUCCESS;
    }

    private function guard(Tenant $estate): Guard
    {
        $post = Post::firstOrCreate(
            ['tenant_id' => $estate->getTenantKey(), 'name' => 'Main Gate'],
            ['type' => 'gate'],
        );

        return Guard::updateOrCreate(
            ['employee_number' => self::GUARD_NUMBER],
            [
                'full_name' => self::GUARD_NAME,
                'psra_number' => 'PSRA-MOBTEST',
                'psra_expires_on' => Carbon::now()->addYear()->toDateString(),
                'employment_type' => 'full_time',
                'status' => 'active',
                'phone' => ReservedContacts::seededPhone(9001),
                'email' => 'mobile.test.guard@geminisecurity.test',
                'hired_on' => Carbon::now()->subMonths(3)->toDateString(),
                'tenant_id' => $estate->getTenantKey(),
                'post_id' => $post->id,
            ],
        );
    }

    /**
     * A shift that covers NOW. `/shifts/me/current` answers the shift the guard is
     * on, else the next one not yet clocked in — so a test that needs to clock in
     * asks for a fresh one, and a test that needs to be on duty simply clocks in.
     */
    private function shift(Guard $guard, bool $fresh): Shift
    {
        $now = Carbon::now();

        if ($fresh) {
            Shift::query()->where('guard_id', $guard->id)->whereNotNull('actual_start')->whereNull('actual_end')
                ->get()->each(fn (Shift $running) => $running->forceFill(['actual_end' => $now, 'status' => 'completed'])->save());

            Shift::query()->where('guard_id', $guard->id)->whereNull('actual_start')->where('rostered_end', '>=', $now)->delete();
        }

        $current = Shift::query()->where('guard_id', $guard->id)
            ->where('rostered_end', '>=', $now->copy()->addHour())
            ->where(fn ($q) => $q->whereNull('actual_end'))
            ->orderBy('rostered_start')
            ->first();

        if ($current instanceof Shift) {
            return $current;
        }

        return Shift::create([
            'tenant_id' => $guard->tenant_id,
            'guard_id' => $guard->id,
            'post_id' => $guard->post_id,
            'rostered_start' => $now->copy()->subMinutes(10),
            'rostered_end' => $now->copy()->addHours(12),
            'status' => Shift::ROSTERED,
        ]);
    }

    /** The central account, linked to Andrea Fletcher's own entry on the estate's register. */
    private function resident(Tenant $estate): ?ResidentAccount
    {
        /** @var array{resident: int, unit: int, name: string}|null $entry */
        $entry = $estate->run(function (): ?array {
            $resident = Resident::query()->with('household')->where('email', self::RESIDENT_EMAIL)->first();

            if ($resident === null || $resident->household === null) {
                return null;
            }

            return ['resident' => (int) $resident->id, 'unit' => (int) $resident->household->unit_id, 'name' => (string) $resident->full_name];
        });

        if ($entry === null) {
            return null;
        }

        return ResidentAccount::query()->updateOrCreate(
            ['tenant_id' => $estate->getTenantKey(), 'channel' => 'email', 'destination' => self::RESIDENT_EMAIL],
            [
                'full_name' => $entry['name'],
                'status' => ResidentAccount::ACTIVE,
                'resident_id' => $entry['resident'],
                'unit_id' => $entry['unit'],
                'verified_at' => Carbon::now(),
            ],
        );
    }
}
