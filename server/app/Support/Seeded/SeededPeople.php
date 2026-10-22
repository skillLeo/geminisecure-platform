<?php

declare(strict_types=1);

namespace App\Support\Seeded;

use App\Console\Commands\CompanyDemoSeed;
use App\Models\Tenant;
use App\Support\JamaicanNumber;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WHICH PEOPLE A SEEDER INVENTED, AND WHO HOLDS AN ADDRESS OR A NUMBER (M35 §5).
 *
 * A PERSON IS SEEDED by M33's own definition, the one `launch:retire-sample-data` reads (KI-25):
 *   - the row carries the mark (`seeded_at`), which every seeder has written since M33;
 *   - or its id is inside a range the demonstration seed recorded before the mark existed (`sample_data_ranges`) —
 *     which is how every seeded row on production is known today, none of them carrying the mark;
 *   - or it is one of `company:demo-seed`'s officers, known by what that command wrote (`CompanyDemoSeed::rowsAt`);
 *   - or it belongs to the demonstration estate, every row of which is sample (`tenants.demo`).
 *
 * ONLY PEOPLE. The tables below hold someone a seeder can make up — a resident, an emergency contact, an officer, a
 * vendor, a visitor. The estate's configuration is never here, whoever wrote it first: its emergency numbers are the
 * client's own (`SiteEmergencyNumbersSeeder` transcribes his instruction), and a panic texts them. Nor are console
 * accounts, which carry no mark; the demonstration's are on the platform's own domain, which no stranger can hold.
 *
 * WHO HOLDS AN ADDRESS: every row, seeded or not, removed or not, whose email or number is the one asked about — read
 * straight from the tables (no scope hides a retired row from this). The platform's own tables always; an estate's
 * when it is open; when none is, the estates whose seeded people predate the mark (`inEstates()`).
 */
final class SeededPeople
{
    /** An estate's tables that hold a person: table => [email column, phone column]. */
    public const ESTATE = [
        'residents' => ['email', 'phone'],
        'household_emergency_contacts' => ['email', 'phone'],
        'resident_invites' => ['email', null],
        'vendors' => ['contact_email', 'contact_phone'],
        'unit_claims' => [null, 'submitted_phone'],
        'visitor_passes' => [null, 'visitor_phone'],
        'maintenance_tickets' => [null, 'technician_phone'],
    ];

    /** The platform's tables that hold a person of an estate. A resident account's destination is by its channel. */
    public const PLATFORM = [
        'guards' => ['email', 'phone'],
        'invitations' => ['email', null],
        'resident_accounts' => ['destination', 'destination'],
    ];

    /** @var array<string, array<string, array<string, list<array{0: int, 1: int}>>>>|null tenant => connection => table => spans */
    private ?array $ranges = null;

    /** @var list<string>|null */
    private ?array $demo = null;

    /** @var array<string, list<int>> */
    private array $officers = [];

    /**
     * Every row holding this address, sorted into seeded people and everybody else.
     *
     * @return array{seeded: list<array{connection: string, table: string, id: int, tenant: string|null}>, real: int}
     */
    public function holdersOfEmail(string $address): array
    {
        $address = mb_strtolower(trim($address));

        if ($address === '' || ! str_contains($address, '@')) {
            return ['seeded' => [], 'real' => 0];
        }

        $found = ['seeded' => [], 'real' => 0];
        $db = DB::connection('mysql');

        foreach ([
            'guards' => $db->select('SELECT id, tenant_id, seeded_at FROM guards WHERE LOWER(TRIM(email)) = ?', [$address]),
            'invitations' => $db->select('SELECT id, tenant_id, seeded_at FROM invitations WHERE LOWER(TRIM(email)) = ?', [$address]),
            'resident_accounts' => $db->select("SELECT id, tenant_id, seeded_at FROM resident_accounts WHERE channel = 'email' AND LOWER(TRIM(destination)) = ?", [$address]),
        ] as $table => $rows) {
            foreach ($rows as $row) {
                $this->sortPlatform($found, $table, $row);
            }
        }

        $found['real'] += (int) ($db->selectOne('SELECT COUNT(*) AS n FROM users WHERE LOWER(TRIM(email)) = ? OR LOWER(TRIM(contact_email)) = ?', [$address, $address])->n ?? 0);

        /*
         * AN INVITE IS ITS RESIDENT'S. Sending one writes a `resident_invites` row holding the address a moment before the
         * mail leaves; unmarked, it made a seeded person's address look like a real person's, and the invite went. So an
         * invite is a seeded holder whenever the person it invites is (`of_resident`).
         */
        $this->inEstates(function (string $tenant, ConnectionInterface $estate) use ($address, &$found): void {
            $rows = $estate->select(
                "SELECT 'residents' AS t, id, seeded_at, NULL AS of_resident, NULL AS of_seeded_at FROM residents WHERE LOWER(TRIM(email)) = ?
                 UNION ALL SELECT 'household_emergency_contacts', id, seeded_at, NULL, NULL FROM household_emergency_contacts WHERE LOWER(TRIM(email)) = ?
                 UNION ALL SELECT 'resident_invites', i.id, i.seeded_at, i.resident_id, r.seeded_at FROM resident_invites i LEFT JOIN residents r ON r.id = i.resident_id WHERE LOWER(TRIM(i.email)) = ?
                 UNION ALL SELECT 'vendors', id, seeded_at, NULL, NULL FROM vendors WHERE LOWER(TRIM(contact_email)) = ?
                 UNION ALL SELECT 'estate_settings', id, NULL, NULL, NULL FROM estate_settings WHERE LOWER(TRIM(enquiries_email)) = ?",
                array_fill(0, 5, $address),
            );

            foreach ($rows as $row) {
                $this->sortEstate($found, $tenant, $row);
            }
        });

        return $found;
    }

    /**
     * Every row holding this number — the same number however it was typed, as `JamaicanNumber::e164()` reads it.
     *
     * @return array{seeded: list<array{connection: string, table: string, id: int, tenant: string|null}>, real: int}
     */
    public function holdersOfPhone(string $number): array
    {
        $digits = self::forms($number);

        if ($digits === []) {
            return ['seeded' => [], 'real' => 0];
        }

        $in = implode(', ', array_fill(0, count($digits), '?'));
        $match = static fn (string $column): string => "REGEXP_REPLACE(IFNULL({$column}, ''), '[^0-9]', '') IN ({$in})";
        $found = ['seeded' => [], 'real' => 0];
        $db = DB::connection('mysql');

        foreach ([
            'guards' => $db->select('SELECT id, tenant_id, seeded_at FROM guards WHERE '.$match('phone'), $digits),
            'resident_accounts' => $db->select("SELECT id, tenant_id, seeded_at FROM resident_accounts WHERE channel = 'sms' AND ".$match('destination'), $digits),
        ] as $table => $rows) {
            foreach ($rows as $row) {
                $this->sortPlatform($found, $table, $row);
            }
        }

        // People who are never a seeder's, and the estate's own configuration: holders, and real ones.
        $found['real'] += (int) ($db->selectOne('SELECT COUNT(*) AS n FROM users WHERE '.$match('phone').' OR '.$match('contact_phone'), [...$digits, ...$digits])->n ?? 0);
        $found['real'] += (int) ($db->selectOne('SELECT COUNT(*) AS n FROM site_emergency_numbers WHERE '.$match('e164').' OR '.$match('number'), [...$digits, ...$digits])->n ?? 0);
        $found['real'] += (int) ($db->selectOne('SELECT COUNT(*) AS n FROM estate_sites WHERE '.$match('supervisor_phone'), $digits)->n ?? 0);

        $parts = [];
        foreach ([...self::ESTATE, 'estate_settings' => [null, 'enquiries_phone']] as $table => [, $phone]) {
            if ($phone !== null) {
                $parts[] = "SELECT '{$table}' AS t, id, ".($table === 'estate_settings' ? 'NULL' : 'seeded_at')." AS seeded_at FROM {$table} WHERE ".$match($phone);
            }
        }

        $this->inEstates(function (string $tenant, ConnectionInterface $estate) use ($parts, $digits, &$found): void {
            $rows = $estate->select(
                implode(' UNION ALL ', $parts),
                array_merge(...array_fill(0, count($parts), $digits)),
            );

            foreach ($rows as $row) {
                $this->sortEstate($found, $tenant, $row);
            }
        });

        return $found;
    }

    /** Is this row of an estate's own table a seeded person? */
    public function estateRowIsSeeded(string $tenant, string $table, int $id, mixed $seededAt): bool
    {
        return $seededAt !== null
            || $this->inRange($tenant, 'tenant', $table, $id)
            || $this->isDemonstration($tenant);
    }

    /** Is this row of one of the platform's people tables a seeded person? */
    public function platformRowIsSeeded(string $table, int $id, ?string $tenant, mixed $seededAt): bool
    {
        if ($seededAt !== null) {
            return true;
        }

        if ($tenant === null || $tenant === '') {
            return false;
        }

        return $this->inRange($tenant, 'mysql', $table, $id)
            || $this->isDemonstration($tenant)
            || ($table === 'guards' && in_array($id, $this->companyOfficers($tenant), true));
    }

    public function isDemonstration(string $tenant): bool
    {
        $this->demo ??= DB::connection('mysql')->table('tenants')->where('demo', true)->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        return in_array($tenant, $this->demo, true);
    }

    /**
     * The digit strings a stored number may be written as, for one E.164 number: "+18765550147" is stored as
     * "18765550147" or "8765550147" (`JamaicanNumber::e164()` reads both as it). Empty when there is no number.
     *
     * @return list<string>
     */
    public static function forms(string $number): array
    {
        $e164 = JamaicanNumber::e164($number);

        if ($e164 === null) {
            return [];
        }

        $digits = substr($e164, 1);

        return strlen($digits) === 11 && str_starts_with($digits, '1') ? [$digits, substr($digits, 1)] : [$digits];
    }

    /**
     * Run `$read` against the estate that is open, through its own connection; or, when none is, against every estate
     * that holds people seeded before the mark existed — the ones the seed recorded ranges for, and the demonstration
     * estate — each through a connection of this class's own, closed again straight after.
     *
     * NEVER BY OPENING TENANCY. Ending tenancy drops the `tenant` connection, and a message is sent from inside things
     * that hold it — a request with its estate open, a job halfway through a transaction. A connection of its own,
     * made from the estate's own credentials, reads the estate and touches nothing else.
     *
     * Any OTHER estate's seeded people were written since M35, on addresses and numbers that reach nobody
     * (`SeedersWriteOnlyUndeliverableContactsTest`), so there is nothing there to find. An estate whose database cannot
     * be read is passed over: a holder nobody can find holds nothing, and the caller decides on what was found.
     *
     * @param  callable(string, ConnectionInterface): void  $read  given the estate's key and the connection to read it on
     */
    public function inEstates(callable $read): void
    {
        $open = tenant();

        if ($open !== null) {
            $read((string) $open->getTenantKey(), DB::connection('tenant'));

            return;
        }

        $keys = DB::connection('mysql')->table('sample_data_ranges')->where('connection', 'tenant')->distinct()->pluck('tenant_id')
            ->merge(DB::connection('mysql')->table('tenants')->where('demo', true)->pluck('id'))
            ->map(static fn ($key): string => (string) $key)->unique()->sort()->values();

        foreach ($keys as $key) {
            $estate = Tenant::query()->find($key);

            if (! $estate instanceof Tenant) {
                continue;
            }

            $name = 'seeded_people_'.preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $estate->getTenantKey()));

            try {
                config(['database.connections.'.$name => $estate->database()->connection()]);
                $read((string) $estate->getTenantKey(), DB::connection($name));
            } catch (Throwable $e) {
                rescue(static fn () => report($e), report: false);
            } finally {
                DB::purge($name);
                config(['database.connections.'.$name => null]);
            }
        }
    }

    /** @param array{seeded: list<array{connection: string, table: string, id: int, tenant: string|null}>, real: int} $found */
    private function sortPlatform(array &$found, string $table, object $row): void
    {
        $tenant = $row->tenant_id === null ? null : (string) $row->tenant_id;

        if ($this->platformRowIsSeeded($table, (int) $row->id, $tenant, $row->seeded_at)) {
            $found['seeded'][] = ['connection' => 'mysql', 'table' => $table, 'id' => (int) $row->id, 'tenant' => $tenant];
        } else {
            $found['real']++;
        }
    }

    /** @param array{seeded: list<array{connection: string, table: string, id: int, tenant: string|null}>, real: int} $found */
    private function sortEstate(array &$found, string $tenant, object $row): void
    {
        $table = (string) $row->t;
        $ofResident = isset($row->of_resident) ? (int) $row->of_resident : null;

        $seeded = array_key_exists($table, self::ESTATE) && (
            $this->estateRowIsSeeded($tenant, $table, (int) $row->id, $row->seeded_at)
            || ($ofResident !== null && $this->estateRowIsSeeded($tenant, 'residents', $ofResident, $row->of_seeded_at ?? null))
        );

        if ($seeded) {
            $found['seeded'][] = ['connection' => 'tenant', 'table' => $table, 'id' => (int) $row->id, 'tenant' => $tenant];
        } else {
            $found['real']++;
        }
    }

    private function inRange(string $tenant, string $connection, string $table, int $id): bool
    {
        if ($this->ranges === null) {
            $this->ranges = [];

            foreach (DB::connection('mysql')->table('sample_data_ranges')->get(['tenant_id', 'connection', 'table_name', 'from_id', 'to_id']) as $range) {
                $this->ranges[(string) $range->tenant_id][(string) $range->connection][(string) $range->table_name][] = [(int) $range->from_id, (int) $range->to_id];
            }
        }

        foreach ($this->ranges[$tenant][$connection][$table] ?? [] as [$from, $to]) {
            // (from, to]: `from` is the table's highest id before the seed ran.
            if ($id > $from && $id <= $to) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    private function companyOfficers(string $tenant): array
    {
        return $this->officers[$tenant] ??= CompanyDemoSeed::rowsAt($tenant)['guards']->pluck('id')->map(intval(...))->values()->all();
    }
}
