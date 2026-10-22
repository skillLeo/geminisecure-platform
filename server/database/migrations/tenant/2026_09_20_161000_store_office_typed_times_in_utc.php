<?php

declare(strict_types=1);

use App\Support\SiteTime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AN ESTATE'S EXISTING OFFICE-TYPED TIMES: FROM "THE DIGITS TYPED" TO THE UTC MOMENT THEY MEAN
 * (M4 §3.1). The estate's half of `store_office_typed_shift_times_in_utc`; read that first.
 *
 * The office typed a meeting for 10:00, a technician for 2–4 PM, a poll that closes at 8 PM —
 * on the estate's clock — and the digits were stored as they were and treated as UTC. They are
 * moved here, ONCE, THROUGH THE ESTATE'S ZONE NAME (`tenants.timezone`), never by a number of hours.
 *
 * EXACTLY THESE COLUMNS, and nothing else:
 *
 *   meetings             starts_at
 *   maintenance_tickets  eta_starts_at, eta_ends_at
 *   amenity_slots        starts_at, ends_at
 *   amenity_bookings     starts_at, ends_at
 *   ballots              nominations_open_at, nominations_close_at, opens_at, closes_at
 *
 * TWO NARROWINGS, because a column can hold both kinds of value:
 *
 *   ballots           "Open voting" stamps `opens_at` with the server's `now()` and "close" stamps
 *                     `closes_at` — true moments already. A typed or seeded time is minute-exact; a
 *                     stamp carries its seconds. Only minute-exact (":00") values are moved.
 *   amenity_bookings  the phone sends a moment with its offset. Until the new rule's writers went
 *                     live (`LIVE_FROM`) that was stored as its Kingston digits like everything
 *                     else, so those rows move; FROM then a phone's booking was stored true UTC, so
 *                     a booking made by a resident account after it is left alone.
 *
 * Everything the SERVER stamps — `reported_at`, `approved_at`, `cancelled_at`, `published_at`,
 * `certified_at`, a pass's window (always stored UTC), every `created_at` — was a true moment
 * all along and is not touched. No append-only table (journals, ballot receipts and marks) is
 * read or written.
 *
 * CANNOT SHIFT TWICE: every value moved is recorded in `site_time_corrections`, and a recorded
 * value is never moved again. `down()` restores the recorded digits, only where the value is
 * still the one this wrote.
 */
return new class extends Migration
{
    /** When the one time rule's writers went live on the development machine, as stored (UTC digits). */
    private const LIVE_FROM = '2026-09-20 06:56:00';

    private const LEDGER = 'site_time_corrections';

    /** table => [columns, minute-exact values only?] */
    private const MOVES = [
        'meetings' => [['starts_at'], false],
        'maintenance_tickets' => [['eta_starts_at', 'eta_ends_at'], false],
        'amenity_slots' => [['starts_at', 'ends_at'], false],
        'amenity_bookings' => [['starts_at', 'ends_at'], false],
        'ballots' => [['nominations_open_at', 'nominations_close_at', 'opens_at', 'closes_at'], true],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::LEDGER)) {
            Schema::create(self::LEDGER, function (Blueprint $table) {
                $table->id();
                $table->string('table_name', 64);
                $table->unsignedBigInteger('row_id');
                $table->string('column_name', 64);

                // DATETIME, not TIMESTAMP: digits to be kept exactly, never converted by a session zone.
                $table->dateTime('wall_clock');
                $table->dateTime('utc');
                $table->string('zone', 64);
                $table->dateTime('corrected_at');

                $table->unique(['table_name', 'row_id', 'column_name'], 'site_time_corrections_once');
            });
        }

        // The estate being migrated is the open one; its zone is read by name.
        $zone = SiteTime::zone();

        foreach (self::MOVES as $table => [$columns, $minuteExactOnly]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $pinned = $this->selfUpdatingColumns($table);

            DB::transaction(function () use ($table, $columns, $minuteExactOnly, $zone, $pinned): void {
                $done = DB::table(self::LEDGER)->where('table_name', $table)->get()
                    ->mapWithKeys(static fn (object $row): array => [$row->row_id.':'.$row->column_name => true])
                    ->all();

                $rows = DB::table($table)->orderBy('id');

                if ($table === 'amenity_bookings') {
                    // A resident's own booking made once the new writers were live is already true UTC.
                    $rows->where(static fn ($q) => $q
                        ->whereNull('resident_id')
                        ->orWhereNull('created_at')
                        ->orWhere('created_at', '<', self::LIVE_FROM));
                }

                foreach ($rows->get(['id', ...$columns]) as $row) {
                    $update = [];

                    foreach ($columns as $column) {
                        $digits = $row->{$column};

                        if ($digits === null || isset($done[$row->id.':'.$column])) {
                            continue;
                        }

                        $digits = (string) $digits;

                        if ($minuteExactOnly && ! str_ends_with($digits, ':00')) {
                            continue;
                        }

                        $utc = Carbon::parse($digits, $zone)->utc()->format('Y-m-d H:i:s');
                        $update[$column] = $utc;

                        DB::table(self::LEDGER)->insert([
                            'table_name' => $table,
                            'row_id' => $row->id,
                            'column_name' => $column,
                            'wall_clock' => $digits,
                            'utc' => $utc,
                            'zone' => $zone,
                            'corrected_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
                        ]);
                    }

                    if ($update !== []) {
                        DB::table($table)->where('id', $row->id)->update($update + $this->pin($pinned, $update));
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::LEDGER)) {
            return;
        }

        foreach (array_keys(self::MOVES) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $pinned = $this->selfUpdatingColumns($table);

            DB::transaction(function () use ($table, $pinned): void {
                foreach (DB::table(self::LEDGER)->where('table_name', $table)->orderBy('id')->get() as $entry) {
                    $column = (string) $entry->column_name;
                    $restore = [$column => (string) $entry->wall_clock];

                    // Only where the value is still the one this migration wrote.
                    DB::table($table)
                        ->where('id', $entry->row_id)
                        ->where($column, (string) $entry->utc)
                        ->update($restore + $this->pin($pinned, $restore));
                }

                DB::table(self::LEDGER)->where('table_name', $table)->delete();
            });
        }

        // This migration's own table, and only once nothing else is recorded in it.
        if (DB::table(self::LEDGER)->count() === 0) {
            Schema::drop(self::LEDGER);
        }
    }

    /**
     * Columns MySQL would stamp with the current time on ANY update of the row (the first
     * TIMESTAMP of a table is often `ON UPDATE CURRENT_TIMESTAMP`). Each is set to itself on
     * every update here, which is how MySQL is told to leave it.
     *
     * @return list<string>
     */
    private function selfUpdatingColumns(string $table): array
    {
        return array_values(array_map(
            static fn (object $row): string => (string) $row->name,
            DB::select(
                'select COLUMN_NAME as name from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and EXTRA like ?',
                [$table, '%on update%'],
            ),
        ));
    }

    /**
     * @param  list<string>  $pinned
     * @param  array<string, string>  $update
     * @return array<string, \Illuminate\Contracts\Database\Query\Expression>
     */
    private function pin(array $pinned, array $update): array
    {
        $keep = [];

        foreach ($pinned as $column) {
            if (! array_key_exists($column, $update)) {
                $keep[$column] = DB::raw('`'.$column.'`');
            }
        }

        return $keep;
    }
};
