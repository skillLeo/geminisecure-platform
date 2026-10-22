<?php

declare(strict_types=1);

use App\Support\SiteTime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EXISTING SHIFTS: FROM "THE DIGITS THE OFFICE TYPED" TO THE UTC MOMENT THEY MEAN (M4 §3.1).
 *
 * The roster was typed on the estate's clock and the digits were stored as they were, so a
 * 07:00 Kingston shift sat in the table as 07:00 "UTC" and the server acted on it five hours
 * early. From now on a typed time is read in the estate's zone and stored true UTC
 * (`App\Support\SiteTime`, `App\Casts\SiteDateTime`). This moves the rows that were already
 * there, ONCE, THROUGH THE ZONE'S NAME — never by adding a number of hours.
 *
 * WHICH VALUES. Only wall-clock ones:
 *   rostered_start, rostered_end   when both are minute-exact (":00" seconds). A person and the
 *                                  seeder type whole minutes. `mobile:test-accounts` builds its
 *                                  shift from `now()`, which is a true moment already and carries
 *                                  its seconds — those rows are left alone.
 *   actual_start, actual_end       ONLY where they carry the seeder's signature — exactly two
 *                                  minutes after the rostered start, exactly three before the
 *                                  rostered end — because the seeder derived them from the typed
 *                                  digits. A real clock-in is the server's `now()` and is not moved.
 *
 * NOT rows written after the new rule went live on this machine (`LIVE_FROM`): from then the
 * roster's own writers stored true UTC, and moving those would break what is already right.
 *
 * CANNOT SHIFT TWICE. Every value moved is written to `site_time_corrections` (before, after,
 * zone). A value with an entry there is never moved again, whatever the migrations table says.
 * `down()` puts back exactly the digits recorded — and only where the value is still the one
 * this wrote, so a shift the office has since re-typed is not disturbed.
 *
 * Touches `shifts` only. No append-only table (incidents, gate events, alerts, audit) is read
 * or written: a moment a handset or the server stamped was always a true moment.
 */
return new class extends Migration
{
    /** When the one time rule's writers went live on the development machine, as stored (UTC digits). */
    private const LIVE_FROM = '2026-09-20 06:56:00';

    private const LEDGER = 'site_time_corrections';

    public function up(): void
    {
        if (! Schema::hasTable(self::LEDGER)) {
            Schema::create(self::LEDGER, function (Blueprint $table) {
                $table->id();
                $table->string('table_name', 64);
                $table->unsignedBigInteger('row_id');
                $table->string('column_name', 64);

                // DATETIME, not TIMESTAMP: these are digits to be kept exactly, never converted by a session zone.
                $table->dateTime('wall_clock');
                $table->dateTime('utc');
                $table->string('zone', 64);
                $table->dateTime('corrected_at');

                $table->unique(['table_name', 'row_id', 'column_name'], 'site_time_corrections_once');
            });
        }

        if (! Schema::hasTable('shifts')) {
            return;
        }

        $pinned = $this->selfUpdatingColumns('shifts');

        DB::transaction(function () use ($pinned): void {
            $done = DB::table(self::LEDGER)->where('table_name', 'shifts')->get()
                ->mapWithKeys(static fn (object $row): array => [$row->row_id.':'.$row->column_name => true])
                ->all();

            $shifts = DB::table('shifts')
                ->where(static fn ($q) => $q->whereNull('created_at')->orWhere('created_at', '<', self::LIVE_FROM))
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'rostered_start', 'rostered_end', 'actual_start', 'actual_end']);

            foreach ($shifts as $shift) {
                $start = (string) $shift->rostered_start;
                $end = (string) $shift->rostered_end;

                // A typed roster line is minute-exact at both ends. Anything else was built from `now()`.
                if (! str_ends_with($start, ':00') || ! str_ends_with($end, ':00')) {
                    continue;
                }

                $zone = SiteTime::zone((string) $shift->tenant_id);
                $moves = ['rostered_start' => $start, 'rostered_end' => $end];

                if ($shift->actual_start !== null && (string) $shift->actual_start === Carbon::parse($start, 'UTC')->addMinutes(2)->format('Y-m-d H:i:s')) {
                    $moves['actual_start'] = (string) $shift->actual_start;
                }

                if ($shift->actual_end !== null && (string) $shift->actual_end === Carbon::parse($end, 'UTC')->subMinutes(3)->format('Y-m-d H:i:s')) {
                    $moves['actual_end'] = (string) $shift->actual_end;
                }

                $update = [];

                foreach ($moves as $column => $digits) {
                    if (isset($done[$shift->id.':'.$column])) {
                        continue;
                    }

                    $utc = Carbon::parse($digits, $zone)->utc()->format('Y-m-d H:i:s');
                    $update[$column] = $utc;

                    DB::table(self::LEDGER)->insert([
                        'table_name' => 'shifts',
                        'row_id' => $shift->id,
                        'column_name' => $column,
                        'wall_clock' => $digits,
                        'utc' => $utc,
                        'zone' => $zone,
                        'corrected_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
                    ]);
                }

                if ($update !== []) {
                    DB::table('shifts')->where('id', $shift->id)->update($update + $this->pin($pinned, $update));
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::LEDGER)) {
            return;
        }

        if (Schema::hasTable('shifts')) {
            $pinned = $this->selfUpdatingColumns('shifts');

            DB::transaction(function () use ($pinned): void {
                foreach (DB::table(self::LEDGER)->where('table_name', 'shifts')->orderBy('id')->get() as $entry) {
                    $column = (string) $entry->column_name;
                    $restore = [$column => (string) $entry->wall_clock];

                    // Only where the value is still the one this migration wrote.
                    DB::table('shifts')
                        ->where('id', $entry->row_id)
                        ->where($column, (string) $entry->utc)
                        ->update($restore + $this->pin($pinned, $restore));
                }

                DB::table(self::LEDGER)->where('table_name', 'shifts')->delete();
            });
        }

        // This migration's own table, and only once nothing else is recorded in it.
        if (DB::table(self::LEDGER)->count() === 0) {
            Schema::drop(self::LEDGER);
        }
    }

    /**
     * Columns MySQL would stamp with the current time on ANY update of the row (the first
     * TIMESTAMP of a table is often `ON UPDATE CURRENT_TIMESTAMP`). Each is set to itself below,
     * which is how MySQL is told to leave it.
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
