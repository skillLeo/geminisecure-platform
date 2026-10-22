<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\JamaicanNumber;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * RESIDENTS FROM A LIST (M98, the Residents census: bulk import with a validation preview).
 *
 * Two presses, as the unit import is. `parse()` reads the file and checks every row against the register as it stands,
 * and writes nothing; `import()` writes the previewed rows, all of them or none, each through `Residents::add()`, so
 * every rule Add resident keeps is kept here too.
 *
 * NO LOT IS MADE. A row naming a lot the estate does not have is refused; lots arrive by the unit import, and an estate's
 * lots are never created, merged or deleted to make a list fit.
 *
 * NOBODY IS EMAILED AND NOBODY IS VERIFIED. Each person goes on the register as Pending with an invite recorded and not
 * sent: the office sends each from the lot's page, which says "This invite was recorded but never sent". Verification
 * is a decision with a name on it (the claims queue, or an officer vouching on Add resident), never a file's side
 * effect.
 *
 * AN OCCUPIED LOT: the person joins its household as a member. A lot holding more than one household is refused, for
 * the office to place the person on the lot's page, where it chooses which.
 */
final class ResidentImport
{
    /** The most rows one file may hold: the estate's whole register, twice over. */
    public const MAX_ROWS = 4000;

    /** What each column may be headed, the words a spreadsheet uses, to the one it means. */
    private const HEADINGS = [
        'lot' => 'lot', 'unit' => 'lot', 'lot number' => 'lot', 'reference' => 'lot',
        'name' => 'name', 'full name' => 'name', 'full_name' => 'name', 'resident' => 'name',
        'relationship' => 'relationship', 'relation' => 'relationship',
        'email' => 'email', 'email address' => 'email', 'e-mail' => 'email',
        'phone' => 'phone', 'phone number' => 'phone', 'mobile' => 'phone', 'telephone' => 'phone',
        'moved in' => 'moved_in', 'moved_in' => 'moved_in', 'moved_in_on' => 'moved_in', 'moved in on' => 'moved_in',
    ];

    public function __construct(
        private readonly Residents $residents,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Read the file and check every row. Nothing is written.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, message: string}>, valid: bool, people: int, households: int}
     */
    public function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', ltrim($csv, "\xEF\xBB\xBF")) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return $this->refusedWhole('The file is empty. The first line names the columns: lot, name, relationship, email, phone, moved in.');
        }

        $columns = array_map(static fn (?string $heading): ?string => self::HEADINGS[mb_strtolower(trim((string) $heading))] ?? null, str_getcsv($lines[0]));

        foreach (['lot', 'name'] as $required) {
            if (! in_array($required, $columns, true)) {
                return $this->refusedWhole('The first line names the columns, and it has no "'.$required.'". Lot and name are required, with an email or a phone for each person.');
            }
        }

        if (count($lines) - 1 > self::MAX_ROWS) {
            return $this->refusedWhole(sprintf('The file holds %d people; one file may hold %d. Split it and import each part.', count($lines) - 1, self::MAX_ROWS));
        }

        $units = $this->unitsByReference();
        $register = $this->registerByLot();
        $seen = [];
        $rows = [];
        $errors = [];
        $today = SiteTime::today()->toDateString();

        foreach (array_slice($lines, 1) as $index => $line) {
            $number = $index + 2;
            $cells = [];

            foreach (str_getcsv($line) as $position => $value) {
                $key = $columns[$position] ?? null;

                if ($key !== null) {
                    $cells[$key] = trim((string) $value);
                }
            }

            $refuse = static function (string $message) use (&$errors, $number): void {
                $errors[] = ['line' => $number, 'message' => $message];
            };

            $lotTyped = $cells['lot'] ?? '';
            $name = preg_replace('/\s+/', ' ', $cells['name'] ?? '') ?? '';
            $email = mb_strtolower($cells['email'] ?? '');
            $phoneTyped = $cells['phone'] ?? '';
            $relationship = mb_strtolower($cells['relationship'] ?? '');
            $movedIn = $cells['moved_in'] ?? '';

            if ($lotTyped === '') {
                $refuse('No lot. Each person is filed at a lot the estate has, as "Lot 47" or "47".');

                continue;
            }

            $unit = $this->unitTyped($units, $lotTyped);

            if ($unit === null) {
                $refuse(sprintf('"%s" is not a lot on this estate\'s register. The import adds people, never lots.', $lotTyped));

                continue;
            }

            if ($name === '' || mb_strlen($name) > 120) {
                $refuse($name === '' ? 'No name.' : 'The name is longer than 120 characters.');

                continue;
            }

            if ($email !== '' && (mb_strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                $refuse(sprintf('"%s" is not an email address.', $cells['email']));

                continue;
            }

            $phone = $phoneTyped === '' ? null : JamaicanNumber::normalise($phoneTyped);

            if ($phoneTyped !== '' && $phone === null) {
                $refuse(sprintf('"%s" is not a phone number. Write it as 876 555 0123, with the area code.', $phoneTyped));

                continue;
            }

            if ($email === '' && $phone === null) {
                $refuse('No email and no phone. The register needs one to reach this person.');

                continue;
            }

            if (mb_strlen($relationship) > 40) {
                $refuse('The relationship is longer than 40 characters.');

                continue;
            }

            if ($movedIn !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $movedIn) !== 1 || ! $this->isDate($movedIn) || $movedIn > $today)) {
                $refuse(sprintf('"%s" is not a day that has come. Write the day moved in as 2024-09-02.', $movedIn));

                continue;
            }

            $key = $unit['id'].'|'.mb_strtolower($name);
            $here = $register[$unit['id']] ?? ['households' => [], 'people' => []];

            if (in_array(mb_strtolower($name), $here['people'], true)) {
                $refuse(sprintf('%s is already on the register at %s.', $name, $unit['reference']));

                continue;
            }

            if (isset($seen[$key])) {
                $refuse(sprintf('%s at %s is on line %d of the file already.', $name, $unit['reference'], $seen[$key]));

                continue;
            }

            if (count($here['households']) > 1) {
                $refuse(sprintf('%s has %d households. Add this person from the lot\'s page, where you choose which one they join.', $unit['reference'], count($here['households'])));

                continue;
            }

            $seen[$key] = $number;
            $newHere = $here['households'] === [] && ! $this->lotInFile($rows, $unit['id']);

            $rows[] = [
                'line' => $number,
                'unit_id' => $unit['id'],
                'lot' => $unit['reference'],
                'phase' => $unit['block'],
                'name' => $name,
                'relationship' => $relationship === '' ? 'owner' : $relationship,
                'email' => $email === '' ? null : $email,
                'phone' => $phone,
                'moved_in' => $movedIn === '' ? null : $movedIn,
                'outcome' => match (true) {
                    $here['households'] !== [] => 'Joins '.$here['households'][0],
                    $newHere => 'New household, its primary resident',
                    default => 'Joins the new household above',
                },
            ];
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
            'valid' => $errors === [] && $rows !== [],
            'people' => count($rows),
            'households' => count(array_filter($rows, static fn (array $row): bool => str_starts_with($row['outcome'], 'New household'))),
        ];
    }

    /**
     * Write the previewed rows: all of them, or none. Each person through `Residents::add()`, Pending, the invite recorded
     * and not sent; their relationship and the day they moved in as the file has them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{people: int, households: int}
     */
    public function import(array $rows, User $by): array
    {
        if ($rows === []) {
            throw new DomainException('There is nobody to import.');
        }

        $households = 0;

        DB::connection('tenant')->transaction(function () use ($rows, $by, &$households): void {
            foreach ($rows as $row) {
                $occupied = Household::query()->where('unit_id', $row['unit_id'])->whereHas('residents')->exists();

                try {
                    $resident = $this->residents->add(
                        phase: (string) $row['phase'],
                        lot: (string) $row['lot'],
                        fullName: (string) $row['name'],
                        email: $row['email'],
                        phone: $row['phone'],
                        verification: 'invite',
                        by: $by,
                        householdChoice: $occupied ? 'member' : null,
                        sendInvite: false,
                    );
                } catch (HouseholdChoiceNeeded $refused) {
                    throw new DomainException(sprintf('Line %d: %s Nothing was imported.', $row['line'], $refused->getMessage()));
                }

                $resident->forceFill([
                    'relationship' => $row['relationship'],
                    'moved_in_on' => $row['moved_in'] ?? $resident->moved_in_on,
                ])->save();

                $households += $occupied ? 0 : 1;
            }
        });

        $this->audit->record(
            action: 'residents.imported',
            entityType: 'Resident',
            entityId: 'import',
            after: ['people' => count($rows), 'households' => $households, 'lots' => count(array_unique(array_column($rows, 'unit_id'))), 'invites_sent' => 0],
            actor: $by,
        );

        return ['people' => count($rows), 'households' => $households];
    }

    /** @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, message: string}>, valid: bool, people: int, households: int} */
    private function refusedWhole(string $message): array
    {
        return ['rows' => [], 'errors' => [['line' => 1, 'message' => $message]], 'valid' => false, 'people' => 0, 'households' => 0];
    }

    /** @return array<string, array{id: int, reference: string, block: string}> every lot, keyed by its reference in lower case */
    private function unitsByReference(): array
    {
        $units = [];

        foreach (Unit::query()->get(['id', 'reference', 'block']) as $unit) {
            $units[mb_strtolower((string) $unit->reference)] = ['id' => (int) $unit->id, 'reference' => (string) $unit->reference, 'block' => (string) $unit->block];
        }

        return $units;
    }

    /**
     * The lot as typed, then as "Lot …" (`Residents::referencesTyped()`'s order): "47" and "lot 47" both mean Lot 47.
     *
     * @param  array<string, array{id: int, reference: string, block: string}>  $units
     * @return array{id: int, reference: string, block: string}|null
     */
    private function unitTyped(array $units, string $typed): ?array
    {
        $typed = trim($typed);
        $asLot = str_starts_with(mb_strtolower($typed), 'lot ') ? 'lot '.trim(mb_substr($typed, 4)) : 'lot '.$typed;

        return $units[mb_strtolower($typed)] ?? $units[mb_strtolower($asLot)] ?? null;
    }

    /**
     * Who is on the register now, lot by lot: the names of its peopled households, and its people's names in lower case.
     *
     * @return array<int, array{households: list<string>, people: list<string>}>
     */
    private function registerByLot(): array
    {
        $byLot = [];

        $rows = DB::connection('tenant')->table('residents')
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->whereNull('households.removed_at')
            ->whereNull('residents.removed_at')
            ->get(['households.unit_id', 'households.id as household_id', 'households.name as household_name', 'residents.full_name']);

        foreach ($rows as $row) {
            $lot = (int) $row->unit_id;
            $byLot[$lot] ??= ['households' => [], 'people' => [], 'ids' => []];

            if (! in_array((int) $row->household_id, $byLot[$lot]['ids'], true)) {
                $byLot[$lot]['ids'][] = (int) $row->household_id;
                $byLot[$lot]['households'][] = (string) $row->household_name;
            }

            $byLot[$lot]['people'][] = mb_strtolower((string) $row->full_name);
        }

        return array_map(static fn (array $lot): array => ['households' => $lot['households'], 'people' => $lot['people']], $byLot);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function lotInFile(array $rows, int $unitId): bool
    {
        foreach ($rows as $row) {
            if ($row['unit_id'] === $unitId) {
                return true;
            }
        }

        return false;
    }

    private function isDate(string $value): bool
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->format('Y-m-d') === $value;
        } catch (Throwable) {
            return false;
        }
    }
}
