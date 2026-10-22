<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\DuressAlert;
use App\Models\Estate\Resident;
use App\Support\JamaicanNumber;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * EVERYBODY THE DISPATCHER CAN CALL ABOUT A PANIC, WITH THEIR NUMBER (M18C §4.2).
 *
 * "The dispatcher console and the estate admins see, on the panic detail: the resident, every household member and
 * every emergency contact, each with their name, relationship and number, ready to tap and dial. A dispatcher should
 * never have to go looking for a number during an emergency."
 *
 * Read from the estate's own database, the household found exactly as the panic's own mail and texts find it
 * (`HouseholdContactsSms::withHousehold`), so the people on screen are the people the panic told. A resident's panic
 * only: an officer's duress has the officer's own number on the guard's record, and no household.
 *
 * `tel` is the diallable form (E.164) or null when the number on file cannot be dialled as written — the row still
 * shows what is on file, so the dispatcher can read it and try. A missing number says so: "No number on file".
 * NEVER IN THE WAY OF THE SCREEN: anything that fails here is reported and the list comes back empty.
 */
final class PanicPeople
{
    public function __construct(private readonly HouseholdContactsSms $household) {}

    /**
     * @return array{resident: array<string, mixed>|null, members: list<array<string, mixed>>, contacts: list<array<string, mixed>>}|null
     */
    public function of(DuressAlert $alert): ?array
    {
        if (! HouseholdContactsSms::appliesTo($alert)) {
            return null;
        }

        try {
            return $this->household->withHousehold($alert, static function (int $household, ?int $raiser): array {
                $members = Resident::query()->where('household_id', $household)->orderByDesc('is_primary')->orderBy('full_name')
                    ->get(['id', 'full_name', 'relationship', 'phone', 'email', 'is_primary', 'alerts_to_primary']);

                $person = static fn (Resident $r): array => [
                    'name' => (string) $r->full_name,
                    'relationship' => self::relationship($r->relationship, (bool) $r->is_primary),
                    ...self::number($r->phone),
                    'email' => $r->email === null || trim((string) $r->email) === '' ? null : (string) $r->email,
                    'alerts_to_primary' => (bool) $r->getAttribute('alerts_to_primary'),
                ];

                $resident = $raiser === null ? null : $members->firstWhere('id', $raiser);

                return [
                    'resident' => $resident === null ? null : $person($resident),
                    'members' => $members->reject(static fn (Resident $r): bool => $r->id === $raiser)->map($person)->values()->all(),
                    'contacts' => DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $household)->orderBy('name')->get()
                        ->map(static fn (object $c): array => [
                            'name' => (string) $c->name,
                            'relationship' => $c->relationship === null || trim((string) $c->relationship) === '' ? 'Emergency contact' : (string) $c->relationship,
                            ...self::number($c->phone),
                            'email' => ($c->email ?? null) === null || trim((string) $c->email) === '' ? null : (string) $c->email,
                        ])->values()->all(),
                ];
            }) ?? ['resident' => null, 'members' => [], 'contacts' => []];
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return ['resident' => null, 'members' => [], 'contacts' => []];
        }
    }

    /** @return array{phone: string, tel: string|null} */
    private static function number(mixed $phone): array
    {
        $phone = trim((string) $phone);

        return $phone === ''
            ? ['phone' => 'No number on file', 'tel' => null]
            : ['phone' => $phone, 'tel' => JamaicanNumber::e164($phone)];
    }

    private static function relationship(mixed $relationship, bool $primary): string
    {
        $label = ucfirst(str_replace('_', ' ', trim((string) $relationship)));

        return $primary ? 'Primary resident'.($label === '' || $label === 'Owner' ? '' : ' · '.$label) : ($label === '' ? 'Household member' : $label);
    }
}
