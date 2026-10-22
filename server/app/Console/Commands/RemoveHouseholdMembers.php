<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Tenant;
use App\Services\Estate\HouseholdMembers;
use App\Support\Seeded\SeededPeople;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 *   php artisan residents:remove-members --estate=phoenixparkvillage1 --household=92 --member=456,457 --reason="…"            what it would do
 *   php artisan residents:remove-members --estate=phoenixparkvillage1 --household=92 --member=456,457 --reason="…" --confirm  do it
 *
 * THE ESTATE OFFICE TAKES PEOPLE OFF A HOUSEHOLD (M35 §6) — exactly as the primary resident does in the Resident App,
 * because it is the same code (`HouseholdMembers::remove`): removed, not deleted; an open invite cancelled; every
 * Resident App account of theirs suspended and signed out, a revoked one left revoked; a pending claim their invite
 * started rejected; their own e-passes cancelled; one `household.member_removed` audit entry each, saying it was this
 * command, who ran it and why.
 *
 * Named one by one, never "everyone but": each id must be a current member of that household. It refuses a primary
 * resident (`residents:repair-primaries` decides who is primary) and a person a seeder invented — sample data leaves on
 * launch day with the rest of it (`launch:retire-sample-data`, KI-25), never one by one. One refusal and nothing is
 * changed for anybody. A dry run unless --confirm; with it, all of them or none of them.
 */
final class RemoveHouseholdMembers extends Command
{
    protected $signature = 'residents:remove-members
        {--estate= : the estate (its subdomain)}
        {--household= : the household id — one estate\'s}
        {--member= : the resident ids to remove, comma-separated}
        {--reason= : why, for the history (required)}
        {--confirm : make the change; without it, only say what it would be}';

    protected $description = 'Take named people off a household as the Resident App does, audited. A dry run unless --confirm.';

    public function handle(HouseholdMembers $members): int
    {
        $key = trim((string) $this->option('estate'));
        $household = trim((string) $this->option('household'));
        $reason = trim((string) $this->option('reason'));
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('member'))), static fn (string $id): bool => $id !== ''));
        $confirm = (bool) $this->option('confirm');

        if ($key === '' || $household === '' || $ids === [] || $reason === '') {
            $this->error('Say which estate, which household, who, and why: --estate= --household= --member=<id,id> --reason="…". Nothing was changed.');

            return self::INVALID;
        }

        foreach ([$household, ...$ids] as $id) {
            if (! ctype_digit($id)) {
                $this->error(sprintf('"%s" is not an id (digits only). Nothing was changed.', $id));

                return self::INVALID;
            }
        }

        $estate = Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error(sprintf('No estate "%s". Nothing was changed.', $key));

            return self::INVALID;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $by = $this->by();

        try {
            return (int) $estate->run(fn (): int => $this->inEstate($members, $key, (int) $household, $ids, $reason, $by, $confirm));
        } catch (Throwable $failure) {
            // stancl's `run()` puts nothing back when its callback throws.
            tenancy()->end();

            throw $failure;
        }
    }

    /** @param  list<int>  $ids */
    private function inEstate(HouseholdMembers $members, string $key, int $householdId, array $ids, string $reason, string $by, bool $confirm): int
    {
        $household = Household::query()->with('unit')->find($householdId);

        if (! $household instanceof Household) {
            $this->error(sprintf('No household %d at %s. Nothing was changed.', $householdId, $key));

            return self::INVALID;
        }

        $people = Resident::withRemoved()->where('household_id', $householdId)->orderBy('id')->get();

        // The mark is asked for by name: a `*` never reads it back (`SampleAwareBuilder`).
        $marked = Resident::withRemoved()->where('household_id', $householdId)->whereNotNull('seeded_at')->pluck('id')->map(intval(...))->all();
        $seeded = new SeededPeople;
        $isSeeded = static fn (int $id): bool => $seeded->estateRowIsSeeded($key, 'residents', $id, in_array($id, $marked, true) ? 'marked' : null);
        $refusals = [];

        foreach ($ids as $id) {
            $person = $people->firstWhere('id', $id);

            $refusals[] = match (true) {
                ! $person instanceof Resident => sprintf('#%d is not a member of household %d.', $id, $householdId),
                $person->removed_at !== null => sprintf('#%d %s was removed already, on %s UTC.', $id, $person->full_name, $person->removed_at->toDateTimeString()),
                $person->is_primary => sprintf('#%d %s is a primary resident: who is primary is residents:repair-primaries\'s to decide, not a removal.', $id, $person->full_name),
                $isSeeded($id) => sprintf('#%d %s is sample data, not a person: it leaves with the rest of it on launch day (launch:retire-sample-data), never one by one.', $id, $person->full_name),
                default => null,
            };
        }

        $refusals = array_values(array_filter($refusals));

        if ($refusals !== []) {
            foreach ($refusals as $refusal) {
                $this->error($refusal);
            }

            $this->error('Nothing was changed.');

            return self::INVALID;
        }

        $chosen = $people->whereIn('id', $ids)->values();
        $plans = $chosen->mapWithKeys(fn (Resident $person): array => [$person->id => $members->plan($person)]);

        $this->info(sprintf(
            '%s%s · household %d (%s%s):',
            $confirm ? '' : 'DRY RUN — nothing is changed. ',
            $key,
            $householdId,
            (string) $household->name,
            (string) $household->unit?->reference === '' ? '' : ', '.$household->unit?->reference,
        ));

        foreach ($chosen as $person) {
            $plan = $plans[$person->id];

            $this->line(sprintf(
                '  %s #%d %s (%s; added %s UTC) — %s, %s, %s, %s',
                $confirm ? 'removing' : 'would remove',
                $person->id,
                $person->full_name,
                $person->relationship !== '' ? $person->relationship : 'no relationship given',
                (string) $person->created_at?->toDateTimeString(),
                $this->count($plan['accounts'], 'Resident App account').' signed out and suspended'.($plan['accounts'] > 0 ? ' ('.$plan['accounts_active'].' active now)' : ''),
                $this->count($plan['claims'], 'pending claim').' rejected',
                $this->count($plan['passes'], 'e-pass', 'e-passes').' cancelled',
                $plan['invite_open'] ? 'their open invite cancelled' : 'no open invite',
            ));
        }

        foreach ($people->whereNull('removed_at')->whereNotIn('id', $ids) as $stays) {
            $this->line(sprintf(
                '  stays: #%d %s%s%s',
                $stays->id,
                $stays->full_name,
                $stays->is_primary ? ' (primary)' : '',
                $isSeeded((int) $stays->id) ? ' (sample data)' : '',
            ));
        }

        $this->line(sprintf(
            'In all: %s %s; %s %s (%d active now); %s %s; %s %s. Why, for the history: "%s".',
            $this->count($chosen->count(), 'person', 'people'),
            $confirm ? 'removed' : 'would be removed',
            $this->count((int) $plans->sum('accounts'), 'Resident App account'),
            $confirm ? 'suspended' : 'would be suspended',
            (int) $plans->sum('accounts_active'),
            $this->count((int) $plans->sum('claims'), 'claim'),
            $confirm ? 'rejected' : 'would be rejected',
            $this->count((int) $plans->sum('passes'), 'e-pass', 'e-passes'),
            $confirm ? 'cancelled' : 'would be cancelled',
            $reason,
        ));

        if (! $confirm) {
            $this->line('To do it: the same command with --confirm.');

            return self::SUCCESS;
        }

        // All of them or none of them: the household's database and the platform's, where the accounts are.
        DB::connection('mysql')->transaction(function () use ($chosen, $members, $by, $reason): void {
            DB::connection('tenant')->transaction(function () use ($chosen, $members, $by, $reason): void {
                foreach ($chosen as $person) {
                    $members->remove($person, $by, null, ['via' => 'residents:remove-members', 'reason' => $reason], $by);
                }
            });
        });

        $this->info(sprintf('Done: %s removed from household %d. Each has a household.member_removed audit entry.', $this->count($chosen->count(), 'person', 'people'), $householdId));

        return self::SUCCESS;
    }

    private function count(int $n, string $one, ?string $many = null): string
    {
        return $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    }

    /** Who ran it: the server account and the machine — for the record, never a credential. */
    private function by(): string
    {
        $account = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '')
            : (string) (getenv('USERNAME') ?: getenv('USER') ?: '');

        return mb_substr(($account !== '' ? $account : 'an operator').' on '.(gethostname() ?: 'this server').' (residents:remove-members)', 0, 191);
    }
}
