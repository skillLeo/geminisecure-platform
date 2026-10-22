<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan residents:retire-samples --estate=phoenixparkvillage1                  what it would change
 *   php artisan residents:retire-samples --estate=phoenixparkvillage1 --household=101  one household
 *   php artisan residents:retire-samples --estate=phoenixparkvillage1 --confirm        change it
 *
 * A REAL PERSON HOLDS A HOUSEHOLD THE DEMONSTRATION SEEDED (M32 F1). Lot 413's seeded primary, "Lot 413 householder",
 * stayed primary when the office added hassam, and the register showed the placeholder instead of him. For every
 * household whose only other current members are the demonstration's placeholders (`SampleResidents`) and which has a
 * real person whose identity is established (verified, that is: approved, or vouched for by the office), this makes
 * that person the primary resident, if nobody real is already, and RETIRES the placeholders: flagged (`retired_at`,
 * with the reason), removed from every list by the default scope, and kept in the database with their history.
 *
 * Nothing is deleted, and no charge, payment or ledger line is touched: the receivable is the unit's. Every change is
 * audited. Without --confirm it prints what it would change and changes nothing; it is run on production by a person
 * who has read that, never by a deploy.
 */
class ResidentsRetireSamples extends Command
{
    protected $signature = 'residents:retire-samples
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--household= : one household id, of that estate}
        {--confirm : make the changes; without it, only say what they would be}';

    protected $description = 'Make the real person primary on a household the demonstration seeded, and retire its placeholder people';

    public function handle(AuditLogger $audit): int
    {
        $key = (string) $this->option('estate');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Say which estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        $only = $this->option('household');

        if ($only !== null && ! ctype_digit((string) $only)) {
            $this->error('--household takes an id (digits only), not "'.$only.'". Nothing was changed.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('confirm');
        $this->info(($apply ? 'Retiring placeholders at ' : 'What would change at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is changed without --confirm.'));

        $plans = $estate->run(function () use ($only, $apply, $audit, $key): array {
            $plans = [];

            $households = Household::query()->with(['unit', 'residents'])
                ->when($only !== null, static fn ($q) => $q->whereKey((int) $only))
                ->orderBy('id')->get();

            foreach ($households as $household) {
                /** @var Collection<int, Resident> $current */
                $current = $household->residents;
                $samples = $current->filter(static fn (Resident $r): bool => $r->isSample())->values();
                $real = $current->reject(static fn (Resident $r): bool => $r->isSample())->values();

                if ($samples->isEmpty() || $real->isEmpty()) {
                    continue;
                }

                // Who holds it: a real primary if there is one, else the real person verified first.
                $holder = $real->firstWhere('is_primary', true)
                    ?? $real->filter(static fn (Resident $r): bool => $r->isVerified())->sortBy(static fn (Resident $r): string => (string) $r->verified_at.'|'.$r->id)->first();

                if (! $holder instanceof Resident) {
                    $plans[] = ['household' => $household, 'holder' => null, 'samples' => $samples, 'waits' => true];

                    continue;
                }

                $plans[] = ['household' => $household, 'holder' => $holder, 'samples' => $samples, 'waits' => false];

                if ($apply) {
                    $this->retire($household, $holder, $samples, $audit, $key);
                }
            }

            return $plans;
        });

        if ($plans === []) {
            $this->newLine();
            $this->info('Nothing to change: no household has both a placeholder and a real person.');

            return self::SUCCESS;
        }

        $acted = 0;

        foreach ($plans as $plan) {
            $household = $plan['household'];
            $where = ($household->unit->reference ?? 'no unit').' (household '.$household->id.')';
            $names = $plan['samples']->map(static fn (Resident $r): string => $r->full_name.' (sample)')->implode(', ');

            if ($plan['waits']) {
                $this->line('  '.$where.': left as it is — nobody real on it is verified yet, so nobody can hold it; '.$names.' stays.');

                continue;
            }

            $acted++;
            $holder = $plan['holder'];

            $this->line('  '.$where.': '.$holder->full_name.' '.($apply ? 'is' : 'would be').' the primary resident; '.$names.' '.($apply ? ($plan['samples']->count() === 1 ? 'is' : 'are') : 'would be').' retired — hidden from every list, kept with '.($plan['samples']->count() === 1 ? 'its' : 'their').' history.');
        }

        $this->newLine();
        $this->info(($apply ? 'Done: ' : '').$acted.' household'.($acted === 1 ? '' : 's').($apply ? ' changed.' : ' to change. Nothing is changed without --confirm.'));

        return self::SUCCESS;
    }

    /** @param Collection<int, Resident> $samples */
    private function retire(Household $household, Resident $holder, Collection $samples, AuditLogger $audit, string $key): void
    {
        DB::connection('tenant')->transaction(function () use ($household, $holder, $samples, $audit, $key): void {
            $from = $samples->firstWhere('is_primary', true);
            $wasPrimary = $samples->mapWithKeys(static fn (Resident $r): array => [$r->id => (bool) $r->is_primary])->all();

            // The placeholder lets go first, so the household never has two primaries, even for a moment.
            foreach ($samples as $sample) {
                if ($sample->is_primary) {
                    $sample->forceFill(['is_primary' => false])->save();
                }
            }

            if (! $holder->is_primary) {
                $holder->forceFill(['is_primary' => true])->save();

                $audit->record(
                    action: 'residents.primary_moved',
                    entityType: 'Resident',
                    entityId: (string) $holder->id,
                    before: ['primary_resident_id' => $from?->id],
                    after: ['from_resident_id' => $from?->id, 'to_resident_id' => $holder->id, 'by' => 'residents:retire-samples', 'reason' => 'the household\'s placeholder was retired (M32 F1)'],
                    tenantId: $key,
                );
            }

            $reason = 'Demonstration placeholder, retired when '.$holder->full_name.' became the household\'s primary resident (M32 F1).';

            foreach ($samples as $sample) {
                $sample->forceFill(['removed_at' => now(), 'retired_at' => now(), 'retired_reason' => $reason])->save();

                $audit->record(
                    action: 'residents.sample_retired',
                    entityType: 'Resident',
                    entityId: (string) $sample->id,
                    before: ['full_name' => $sample->full_name, 'is_primary' => $wasPrimary[$sample->id] ?? false, 'household_id' => $household->id],
                    after: ['retired' => true, 'holder_resident_id' => $holder->id, 'reason' => $reason],
                    tenantId: $key,
                );
            }
        });
    }
}
