<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Services\Audit\AuditLogger;
use App\Support\JamaicanNumber;
use Illuminate\Support\Facades\DB;

/**
 * ONE EMERGENCY CONTACT PER NUMBER, ON EVERY HOUSEHOLD (DESIGN §14).
 *
 * The user's screenshot listed "Marcia Ahmed · Spouse · 876-555-0148" four times. `contacts.store` inserted on
 * every call and `household_emergency_contacts` has no unique key, so four Saves were four rows — and a panic
 * texts a household's first five, so the same person was texted four times and a genuine fifth never was.
 *
 * THE SAME NUMBER IS THE SAME DIALLABLE NUMBER. "876-555-0148", "+1 876 555 0148" and "(876) 555-0148" are one
 * phone, so two rows share a number when `JamaicanNumber::e164()` reads them the same; a number it cannot dial
 * is compared by its digits, so two copies of the same unroutable number are still one. A "number" with no
 * digits at all ("--------" passes `contacts.store`'s pattern) is nobody's number in particular: it never
 * matches another row.
 *
 * ONE NUMBER IS TEXTED ONCE; ONLY A TRUE COPY IS DELETED. The panic fan-out and `contacts.store` go by the number
 * alone — one phone is one text, and a number already on the list is answered with the row already there. The
 * REPAIR is stricter, because it deletes: it removes a row only when it is a true copy of an earlier one — the
 * same number AND the same name AND the same relationship (compared ignoring case and spacing). Two different
 * people on one number — a shared home line, "Marcia A." beside "Marcia Ahmed" — are REPORTED and left for the
 * household to tidy in the app (`contacts.destroy`) or the office to decide: a merge nobody can undo must never
 * choose between two people.
 *
 * Three callers: `contacts.store`, the panic fan-out (five DIFFERENT numbers), and the repair below, which the
 * deploy runs.
 */
final class EmergencyContactMerge
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * What two rows' numbers are compared by: the diallable number, or its digits when it cannot be dialled — and
     * '' when there are no digits at all, which callers treat as matching nothing (`comparable`).
     */
    public static function numberKey(?string $phone): string
    {
        $dialable = JamaicanNumber::e164($phone);

        if ($dialable !== null) {
            return $dialable;
        }

        $digits = (string) preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? '' : 'digits:'.$digits;
    }

    /** False for a key with no digits behind it: such a row is never taken for another. */
    public static function comparable(string $key): bool
    {
        return $key !== '';
    }

    /** A name or relationship as the repair compares them: case and spacing do not make two people. */
    private static function fold(?string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $text)));
    }

    /**
     * The first row for each number, in the order given — the rest are copies.
     *
     * @template T of object
     *
     * @param  iterable<T>  $rows  each with a `phone`, ordered as they were nominated
     * @return list<T>
     */
    public static function distinct(iterable $rows): array
    {
        $seen = [];
        $first = [];

        foreach ($rows as $row) {
            $key = self::numberKey(isset($row->phone) ? (string) $row->phone : null);

            if (self::comparable($key) && isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $first[] = $row;
        }

        return $first;
    }

    /**
     * Repair THIS estate's copies (tenancy already open). Per household and per number:
     *   merged   rows that are TRUE copies of an earlier one (same number, name and relationship) go; the earliest
     *            stays. Audited per household with masked numbers, the removed rows' relationships, never a name.
     *   review   one number held by two or more DIFFERENT people: nothing is removed; the ids are reported so the
     *            household (`contacts.destroy`) or the office can decide.
     *
     * @return array{merged: list<array{household_id: int, number: string, kept: int, removed: list<int>}>, review: list<array{household_id: int, number: string, ids: list<int>}>}
     */
    public function merge(bool $dryRun = true): array
    {
        $groups = [];

        foreach (DB::connection('tenant')->table('household_emergency_contacts')->orderBy('household_id')->orderBy('id')->get(['id', 'household_id', 'name', 'relationship', 'phone']) as $row) {
            $key = self::numberKey((string) $row->phone);

            if (! self::comparable($key)) {
                continue;
            }

            $groups[(int) $row->household_id][$key][] = [
                'id' => (int) $row->id,
                'who' => self::fold((string) $row->name).'|'.self::fold($row->relationship === null ? null : (string) $row->relationship),
                'relationship' => $row->relationship === null ? null : (string) $row->relationship,
            ];
        }

        $merged = [];
        $review = [];

        foreach ($groups as $householdId => $numbers) {
            $here = [];

            foreach ($numbers as $key => $rows) {
                if (count($rows) < 2) {
                    continue;
                }

                $number = JamaicanNumber::mask(str_starts_with((string) $key, 'digits:') ? substr((string) $key, 7) : (string) $key);

                // Group by who the row says it is: only rows saying the same thing about the same number are copies.
                $people = [];
                foreach ($rows as $row) {
                    $people[$row['who']][] = $row;
                }

                foreach ($people as $copies) {
                    if (count($copies) > 1) {
                        $here[] = [
                            'household_id' => $householdId,
                            'number' => $number,
                            'kept' => $copies[0]['id'],
                            'removed' => array_map(static fn (array $row): int => $row['id'], array_slice($copies, 1)),
                            'relationships' => array_map(static fn (array $row): ?string => $row['relationship'], array_slice($copies, 1)),
                        ];
                    }
                }

                if (count($people) > 1) {
                    $review[] = ['household_id' => $householdId, 'number' => $number, 'ids' => array_map(static fn (array $copies): int => $copies[0]['id'], array_values($people))];
                }
            }

            if ($here === []) {
                continue;
            }

            if (! $dryRun) {
                $removed = array_merge(...array_map(static fn (array $group): array => $group['removed'], $here));

                DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $householdId)->whereIn('id', $removed)->delete();

                $this->audit->record(
                    action: 'household.contacts_merged',
                    entityType: 'Household',
                    entityId: (string) $householdId,
                    before: ['removed' => array_merge(...array_map(static fn (array $group): array => array_map(static fn (int $id, ?string $relationship): array => ['id' => $id, 'number' => $group['number'], 'relationship' => $relationship], $group['removed'], $group['relationships']), $here))],
                    after: [
                        'merged' => array_map(static fn (array $group): array => ['number' => $group['number'], 'kept' => $group['kept'], 'removed' => $group['removed']], $here),
                        'via' => 'household:merge-duplicate-contacts',
                    ],
                );
            }

            foreach ($here as $group) {
                unset($group['relationships']);
                $merged[] = $group;
            }
        }

        return ['merged' => $merged, 'review' => $review];
    }
}
