<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONE HOUSEHOLD, ONE PRIMARY RESIDENT — kept that way by the seeders, and put back where the past broke it.
 *
 * The user's screenshot showed two members of one household both marked "Primary" (DESIGN §13), and only the
 * primary may add, invite and remove members. `Resident`'s saving guard refuses a second one from now on, and
 * the two writers that decide it lock the unit first. This class holds the two remaining pieces: how a seeder
 * puts its named person on a household without ever making a second primary, and (`residents:repair-primaries`)
 * how a household already left with two is given one again.
 *
 * THE REPAIR NEVER GUESSES FOR SOMEBODY WHO CAN BE ASKED. Left to itself it keeps the lowest id — what
 * `Household::primaryResident()` already shows on every screen, so no screen's answer changes — but the known
 * case is the user's own household, where the one who added and invited members is the SECOND of the two. So
 * the report prints, for every primary, the evidence of who has actually been acting as one (invites they sent,
 * members they removed, the claim that made them) and the deploy runs it only as a dry run: the real run is a
 * person's decision, per household, with `--keep` naming the one it keeps (G11).
 *
 * No unique index, for DESIGN §13.4's three reasons: its migration would fail on every estate still holding a
 * pair, the suite could not insert one to prove this, and the guard and the lock already close every path.
 */
final class PrimaryResidentRepair
{
    public const KEEP_NEEDS_HOUSEHOLD = '--keep names the primary ONE household keeps, so it needs --household as well.';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * A seeder's named person on a household — primary only when the household has no primary at all.
     *
     * The demonstration seeders used to `updateOrCreate` their person with `is_primary => true` every run. After
     * the office moved primacy with "Make primary", the next run handed it straight back — and, because nothing
     * cleared the other, left two. Now the person's details are refreshed and primacy is given only to a household
     * nobody holds; a household somebody holds keeps its holder.
     *
     * @param  array<string, mixed>  $values  the person's details, never `is_primary`
     */
    public static function seededHolder(Household $household, string $email, array $values): Resident
    {
        unset($values['is_primary']);

        $resident = Resident::query()->updateOrCreate(['household_id' => $household->id, 'email' => $email], $values);

        if (! $resident->is_primary && $household->residents()->where('is_primary', true)->doesntExist()) {
            $resident->forceFill(['is_primary' => true])->save();
        }

        return $resident;
    }

    /**
     * Every household in THIS estate (tenancy already open) holding more than one current primary, and what was
     * — or, on a dry run, would be — done about each.
     *
     * @return list<array{household_id: int, household: string, unit: string, chosen_by: string, kept: array<string, mixed>, demoted: list<array<string, mixed>>}>
     *
     * @throws DomainException when `--keep` names anybody but one of that household's current primaries
     */
    public function repair(?int $householdId = null, ?int $keep = null, bool $dryRun = true): array
    {
        if ($keep !== null && $householdId === null) {
            throw new DomainException(self::KEEP_NEEDS_HOUSEHOLD);
        }

        if ($keep !== null) {
            $this->assertKeepable((int) $householdId, $keep);
        }

        $households = Resident::query()
            ->where('is_primary', true)
            ->when($householdId !== null, static fn ($query) => $query->where('household_id', $householdId))
            ->groupBy('household_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('household_id')
            ->pluck('household_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $report = [];

        foreach ($households as $id) {
            $row = $dryRun ? $this->plan($id, $keep) : $this->apply($id, $keep);

            if ($row !== null) {
                $report[] = $row;
            }
        }

        return $report;
    }

    /** A `--keep` that is not one of this household's current primaries changes nothing, and says why. */
    private function assertKeepable(int $householdId, int $keep): void
    {
        $person = Resident::query()->find($keep);

        if ($person === null || (int) $person->household_id !== $householdId) {
            throw new DomainException(sprintf('Resident %d is not on household %d. --keep names one of that household\'s own primary residents; nothing was changed.', $keep, $householdId));
        }

        if (! $person->is_primary) {
            throw new DomainException(sprintf('%s (resident %d) is not a primary resident of household %d, so they cannot be the one it keeps. Nothing was changed.', $person->full_name, $keep, $householdId));
        }
    }

    /**
     * What would be done, touching nothing.
     *
     * @return array{household_id: int, household: string, unit: string, chosen_by: string, kept: array<string, mixed>, demoted: list<array<string, mixed>>}|null
     */
    private function plan(int $householdId, ?int $keep): ?array
    {
        $primaries = $this->primaries($householdId);

        return count($primaries) < 2 ? null : $this->describe($householdId, $primaries, $keep);
    }

    /**
     * Done: in one transaction, with the unit locked (the same lock `Residents::add` and `approveClaim` take, so
     * neither can decide "is there a primary?" half-way through), re-read, and demoted with a query update — the
     * guard is for rows BECOMING primary, and here they stop being one. Audited once the change has committed.
     *
     * @return array{household_id: int, household: string, unit: string, chosen_by: string, kept: array<string, mixed>, demoted: list<array<string, mixed>>}|null
     */
    private function apply(int $householdId, ?int $keep): ?array
    {
        $row = DB::connection('tenant')->transaction(function () use ($householdId, $keep): ?array {
            $unitId = Household::query()->whereKey($householdId)->value('unit_id');

            if ($unitId !== null) {
                Unit::query()->whereKey((int) $unitId)->lockForUpdate()->first();
            }

            $primaries = $this->primaries($householdId);

            if (count($primaries) < 2) {
                return null;
            }

            $row = $this->describe($householdId, $primaries, $keep);
            $demoted = array_map(static fn (array $person): int => (int) $person['id'], $row['demoted']);

            Resident::query()->whereIn('id', $demoted)->update(['is_primary' => false, 'updated_at' => Carbon::now()]);

            return $row;
        });

        if ($row !== null) {
            $this->audit->record(
                action: 'residents.primary_repaired',
                entityType: 'Household',
                entityId: (string) $householdId,
                before: ['primary_resident_ids' => [$row['kept']['id'], ...array_map(static fn (array $p): int => (int) $p['id'], $row['demoted'])]],
                after: [
                    'kept' => $row['kept']['id'],
                    'demoted' => array_map(static fn (array $p): int => (int) $p['id'], $row['demoted']),
                    'chosen_by' => $row['chosen_by'],
                    'evidence' => [$row['kept'], ...$row['demoted']],
                    'via' => 'residents:repair-primaries',
                ],
            );
        }

        return $row;
    }

    /** @return list<Resident> the household's current primaries, oldest first */
    private function primaries(int $householdId): array
    {
        return Resident::query()->where('household_id', $householdId)->where('is_primary', true)->orderBy('id')->get()->all();
    }

    /**
     * @param  list<Resident>  $primaries
     * @return array{household_id: int, household: string, unit: string, chosen_by: string, kept: array<string, mixed>, demoted: list<array<string, mixed>>}
     */
    private function describe(int $householdId, array $primaries, ?int $keep): array
    {
        $kept = $primaries[0];
        $chosenBy = 'lowest_id';

        if ($keep !== null) {
            foreach ($primaries as $person) {
                if ($person->id === $keep) {
                    $kept = $person;
                    $chosenBy = '--keep';
                }
            }
        }

        $household = Household::query()->with('unit')->find($householdId);

        return [
            'household_id' => $householdId,
            'household' => (string) ($household->name ?? 'Household '.$householdId),
            'unit' => (string) ($household->unit->reference ?? ''),
            'chosen_by' => $chosenBy,
            'kept' => $this->evidence($kept),
            'demoted' => array_values(array_map(fn (Resident $person): array => $this->evidence($person), array_filter($primaries, static fn (Resident $person): bool => $person->id !== $kept->id))),
        ];
    }

    /**
     * What says who has been acting as this household's primary — the evidence a person chooses by.
     *
     * Names and dates only: no email, no phone. The report is printed into a deploy log.
     *
     * @return array<string, mixed>
     */
    private function evidence(Resident $person): array
    {
        $claim = DB::connection('tenant')->table('unit_claims')
            ->where('resolved_resident_id', $person->id)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->first(['id', 'reviewed_by_name', 'reviewed_at']);

        // Whether this person signs in to the Resident App as this entry — usually the plainest sign of who acts as
        // the primary. Central, and read for THIS estate only; status and dates, never the address.
        $account = \App\Models\ResidentAccount::query()
            ->where('tenant_id', (string) tenant()?->getTenantKey())
            ->where('resident_id', $person->id)
            ->orderByDesc('id')
            ->first(['id', 'status', 'updated_at']);

        return [
            'id' => $person->id,
            'name' => $person->full_name,
            'app_account' => $account === null ? null : ['status' => (string) $account->status, 'last_changed' => $account->updated_at?->toDateString()],
            'created_at' => $person->created_at?->toDateString(),
            'verified_by_name' => $person->verified_by_name,
            'invites_sent_in_app' => (int) DB::connection('tenant')->table('resident_invites')->where('invited_by_resident_id', $person->id)->count(),
            'members_removed_in_app' => (int) DB::connection('tenant')->table('residents')->where('removed_by_resident_id', $person->id)->count(),
            'claim_id' => $claim === null ? null : (int) $claim->id,
            'claim_approved_by' => $claim?->reviewed_by_name,
            'claim_approved_at' => $claim === null || $claim->reviewed_at === null ? null : Carbon::parse((string) $claim->reviewed_at)->toDateString(),
        ];
    }
}
