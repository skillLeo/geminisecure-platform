<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Support\EmailAddress;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HOUSEHOLDS WITH MISSING CONTACT DETAILS — the Property Manager's chase list (M18C §5).
 *
 * Every field became required on 27 September; every record saved before then may have blanks, and a blank is a
 * person a panic cannot reach. Nobody is locked out for it — the Resident App asks, politely, on sign-in — and this
 * is the other half: the list the estate office works through, "the difference between a rule and a result".
 *
 * Read inside the estate. What counts as missing:
 *   a member        no phone; no email, unless they chose "alerts go to the primary resident"
 *   a contact       no phone, no email, or no relationship
 * A household with nothing missing is not listed.
 */
final class MissingContactDetails
{
    /**
     * What the chase list orders by (M32 F5): the lot, as a register is read, by default.
     *
     * @return array<string, string|\Closure>
     */
    public static function sorts(): array
    {
        return [
            'lot' => static fn (array $row): string => (string) ($row['unit'] ?? ''),
            'household' => 'household',
            'people' => static fn (array $row): int => count($row['people']),
        ];
    }

    /** The chase list as the address asks for it (M32 F5). */
    public static function table(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::sorts(), 'lot');
    }

    /**
     * @return list<array{household_id: int, household: string, unit: string|null, unit_slug: string|null, people: list<array{name: string, kind: string, missing: list<string>}>}>
     */
    public function households(): array
    {
        $members = Resident::query()->get(['id', 'household_id', 'full_name', 'phone', 'email', 'alerts_to_primary'])->groupBy('household_id');
        $contacts = DB::connection('tenant')->table('household_emergency_contacts')->get(['household_id', 'name', 'relationship', 'phone', 'email'])->groupBy('household_id');

        $blank = static fn (mixed $value): bool => trim((string) $value) === '';

        /*
         * AN EMAIL THAT CANNOT BE REACHED IS AS GOOD AS NONE (M18G §6). This relay reports no bounces to the platform, so
         * the one unreachable address it can know about is one whose domain has nowhere to deliver to ("gmail.comm").
         * Each domain is asked once per page.
         */
        $domains = [];
        $unreachable = static function (mixed $email) use (&$domains): bool {
            $email = trim((string) $email);
            $domain = mb_strtolower((string) substr($email, (int) strrpos($email, '@') + 1));

            return $email !== '' && ! ($domains[$domain] ??= EmailAddress::receivesMail($email));
        };
        $rows = [];

        foreach (Household::query()->with('unit')->orderBy('id')->get() as $household) {
            $people = [];

            foreach ($members->get($household->id, collect()) as $m) {
                $missing = array_values(array_filter([
                    $blank($m->phone) ? 'phone' : null,
                    $blank($m->email) && ! (bool) $m->getAttribute('alerts_to_primary') ? 'email' : null,
                    $unreachable($m->email) ? 'email_unreachable' : null,
                ]));

                if ($missing !== []) {
                    $people[] = ['name' => (string) $m->full_name, 'kind' => 'Household member', 'missing' => $missing];
                }
            }

            foreach ($contacts->get($household->id, collect()) as $c) {
                $missing = array_values(array_filter([
                    $blank($c->phone) ? 'phone' : null,
                    $blank($c->email ?? null) ? 'email' : null,
                    $unreachable($c->email ?? null) ? 'email_unreachable' : null,
                    $blank($c->relationship) ? 'relationship' : null,
                ]));

                if ($missing !== []) {
                    $people[] = ['name' => (string) $c->name, 'kind' => 'Emergency contact', 'missing' => $missing];
                }
            }

            if ($people !== []) {
                $rows[] = [
                    'household_id' => (int) $household->id,
                    'household' => (string) $household->name,
                    'unit' => $household->unit?->reference,
                    'unit_slug' => $household->unit?->slug(),
                    'people' => $people,
                ];
            }
        }

        return $rows;
    }

    /**
     * The counts for the backfill report (M18C §5): households affected, and each gap by kind.
     *
     * @return array{households: int, members_no_phone: int, members_no_email: int, members_no_email_unreachable: int, contacts_no_phone: int, contacts_no_email: int, contacts_no_email_unreachable: int, contacts_no_relationship: int}
     */
    public function counts(): array
    {
        $counts = ['households' => 0, 'members_no_phone' => 0, 'members_no_email' => 0, 'members_no_email_unreachable' => 0, 'contacts_no_phone' => 0, 'contacts_no_email' => 0, 'contacts_no_email_unreachable' => 0, 'contacts_no_relationship' => 0];

        foreach ($this->households() as $row) {
            $counts['households']++;

            foreach ($row['people'] as $person) {
                $who = $person['kind'] === 'Household member' ? 'members' : 'contacts';

                foreach ($person['missing'] as $field) {
                    $counts[$who.'_no_'.$field]++;
                }
            }
        }

        return $counts;
    }
}
