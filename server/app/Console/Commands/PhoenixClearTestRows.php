<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\MaintenanceTicket;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\SampleData;
use App\Support\SampleResidents;
use App\Support\Seeded\SeededTables;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 *   php artisan phoenix:clear-test-rows                                                       what is there (writes nothing)
 *   php artisan phoenix:clear-test-rows --tickets=1048,1053 --rename="Orville Brown"          what those would do
 *   … the same with --confirm                                                                 do it, a backup first
 *   php artisan phoenix:clear-test-rows --restore=<the backup file a real run printed>         put it back
 *
 * THE FOUR THINGS A COMMITTEE WOULD READ ALOUD (M60 §C3): "Omar Brown sample", the typed test rows, Aziz Bhatti Town and
 * the 2020 ticket that drags the average resolution to 139.6 days. Hassam runs it, after reading the dry run.
 *
 * THE DRY RUN SAYS, BEFORE ANYTHING: the estate and its database (`SELECT DATABASE()` as proof); every work order raised
 * by a person rather than the seed, each with what marks it as typed while testing (test words, an address or a GPS fix
 * outside Jamaica, a date before 2025) and what points at it; the seeded resident who carries the client's first name and
 * every other row that carries that name, with the rows it never touches; every row anywhere that names Aziz Bhatti
 * Town; the average resolution now and after; and the real command with the numbers in it.
 *
 * WHAT IT CHANGES, ONLY WITH --confirm AND ONLY WHAT IS NAMED: the work orders named in --tickets (each one of the list
 * the dry run printed, never a QA- record, which the QA cleanup takes, never one the seed made) with their timeline, their
 * photos and any DRAFT bill raised for them; and, with --rename, the seeded resident's name wherever it is written in a
 * row this may touch. A bill approved or paid against a named work order, or any other row pointing at one, stops the
 * whole run. Every row is written to a backup file before the one transaction that changes them, and one audit entry
 * names everything; --restore puts it all back.
 *
 * NEVER TOUCHED, AND LISTED WHERE THE NAME OR THE PLACE APPEARS IN THEM: a vote (receipts and marks) and a ballot's
 * structure, a posted journal, a gate event, an audit entry, a document already issued, a panic or duress alert. A
 * candidate IS renamed (its ballot option and nomination): a vote points at the option's id, never at a name. And the
 * rename is made only when nothing it cannot reach carries the name: one line says so, or says what does and leaves the
 * name alone (Hassam, 9 October 2026).
 *
 * ON THE LIVE SERVER, PHOENIX PARK VILLAGE 1 ONLY.
 *
 * @phpstan-type TypedTicket array{id: int, number: int, title: string, place: string, reported: string, by: string, status: string, flags: list<string>, qa: bool, closed: bool}
 */
class PhoenixClearTestRows extends Command
{
    protected $signature = 'phoenix:clear-test-rows
        {--estate=phoenixparkvillage1 : the estate; on the live server Phoenix Park Village 1 only}
        {--tickets= : the work orders to remove, by number, from the list the dry run prints: 1048,1053,...}
        {--rename= : the new name for the seeded resident who carries the client\'s first name}
        {--confirm : make the change; without it nothing is written}
        {--restore= : put back what a real run\'s backup file holds}';

    protected $description = 'List and clear the work orders typed while testing, and rename the seeded resident who carries the client\'s first name (M60 §C3; dry run unless --confirm)';

    /** The one estate it writes to on the live server. */
    private const ESTATE = 'phoenixparkvillage1';

    /** The client's first name (M53 §2: no seeded resident carries it). */
    private const CLIENT_FIRST_NAME = 'Omar';

    /** The place a phone filled in while it was being tested in Pakistan. */
    private const PLACE = 'Aziz Bhatti';

    /** What the dry run offers as the new name when none is given: a Jamaican name, the household's own surname kept. */
    private const SUGGESTED_FIRST_NAME = 'Orville';

    /** A QA record's title (the QA cleanup, `docs/qa/CLEANUP_DRY_RUN.md`, takes those). */
    private const QA_MARK = 'QA-2026';

    /** Jamaica's bounding box: a GPS fix outside it was not taken on the estate. */
    private const JAMAICA = ['lat' => [17.6, 18.6], 'lng' => [-78.5, -76.1]];

    /**
     * A ballot's structure and its votes: never touched. A CANDIDATE is not one of them (Hassam, 9 October 2026: "A
     * candidate record is not a ballot row"): a vote points at its option's id, never at a name, so `ballot_options`
     * and `nominations` carry the name and are renamed with everything else.
     */
    private const BALLOT_TABLES = ['ballots', 'ballot_positions', 'ballot_receipts', 'ballot_marks'];

    public function handle(AuditLogger $audit): int
    {
        $key = (string) $this->option('estate');

        if ($key !== self::ESTATE && app()->environment('production')) {
            $this->error('On the live server this works on Phoenix Park Village 1 ('.self::ESTATE.') only. Nothing was changed.');

            return self::INVALID;
        }

        $estate = Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        if (($restore = trim((string) $this->option('restore'))) !== '') {
            return $estate->run(fn (): int => $this->restore($restore, $key, $audit));
        }

        $named = $this->numbers((string) $this->option('tickets'));

        if ($named === null) {
            $this->error('--tickets takes work-order numbers separated by commas, e.g. --tickets=1048,1053. Nothing was changed.');

            return self::INVALID;
        }

        $rename = trim((string) $this->option('rename'));
        $apply = (bool) $this->option('confirm');

        if ($apply && $named === [] && $rename === '') {
            $this->error('Name what to change: --tickets=… and or --rename="…", as the dry run printed them. Nothing was changed.');

            return self::INVALID;
        }

        return $estate->run(fn (): int => $this->work($estate, $key, $named, $rename, $apply, $audit));
    }

    /**
     * @param  list<int>  $named
     */
    private function work(Tenant $estate, string $key, array $named, string $rename, bool $apply, AuditLogger $audit): int
    {
        $db = DB::connection('tenant');
        $connected = (string) $db->selectOne('select database() as db')->db;

        $this->info(($apply ? 'CLEARING THE TEST ROWS' : 'THE TEST ROWS: DRY RUN, nothing is changed').' at '.$estate->name.' ('.$key.')');
        $this->line('Database: '.$connected.' (SELECT DATABASE() on the connection every statement below runs on).');

        // 1. The work orders people raised, and what marks each as typed while testing.
        $typed = $this->typedTickets($db);
        $this->newLine();
        $this->line('1. WORK ORDERS WITH NO SEEDED MARK AND OUTSIDE EVERY RECORDED SEED RUN ('.$typed->count().'): only those marked below are offered');

        foreach ($typed as $ticket) {
            $this->line(sprintf('   #%d  "%s"  %s · reported %s by %s · %s%s', $ticket['number'], $ticket['title'], $ticket['place'], $ticket['reported'], $ticket['by'], $ticket['status'], $ticket['flags'] === [] ? '' : ' · '.strtoupper(implode(', ', $ticket['flags']))));
        }

        $suggested = $typed->filter(static fn (array $t): bool => $t['flags'] !== [] && ! $t['qa'])->pluck('number')->values()->all();
        $this->line('   QA- records are left to the QA cleanup (docs/qa/CLEANUP_DRY_RUN.md). Marked above as typed while testing: '.($suggested === [] ? 'none' : implode(', ', $suggested)).'.');

        // 2. The seeded resident in the client's first name, and every row that carries that name.
        $renames = $this->renames($db, $key, $rename);

        // 3. Aziz Bhatti Town, anywhere.
        $this->newLine();
        $this->line('3. ROWS THAT NAME '.strtoupper(self::PLACE).' TOWN');
        $places = $this->rowsSaying($db, self::PLACE);

        // A panic alert carries a GPS fix, not a place name: one taken outside Jamaica is said, and never edited (item 6 classifies them).
        $central = DB::connection('mysql')->table('duress_alerts')->where('tenant_id', $key)->whereNotNull('latitude')->whereNotNull('longitude')
            ->where(static fn ($q) => $q->whereNotBetween('latitude', self::JAMAICA['lat'])->orWhereNotBetween('longitude', self::JAMAICA['lng']))
            ->orderBy('id')->pluck('id');

        foreach ($places as $row) {
            $ticketRow = $row['table'] === 'maintenance_tickets';
            $this->line(sprintf('   %s.%s #%d: "%s"%s', $row['table'], $row['column'], $row['id'], self::excerpt($row['value'], self::PLACE), $ticketRow ? ' (a work order: listed in 1)' : ($this->neverTouched($row['table']) || $row['json'] ? ' (never touched: '.$this->whyKept($row['table'], $row['json']).')' : ' (left as it is: say if it should go)')));
        }

        foreach ($central as $alertId) {
            $this->line('   duress_alerts #'.$alertId.': a GPS fix outside Jamaica (a panic alert: classified by item 6, never edited)');
        }

        if ($places === [] && $central->isEmpty()) {
            $this->line('   none');
        }

        // The run's own checks: every number named is a typed work order, not a QA one, with nothing but what this covers pointing at it.
        $plan = $this->planFor($db, $typed, $named);

        // A rename that would leave a record disagreeing is left alone, whole (Hassam, 9 October 2026).
        if ($rename !== '' && $renames['disagree'] !== []) {
            $plan['problems'][] = 'The rename would leave '.count($renames['disagree']).' record'.(count($renames['disagree']) === 1 ? '' : 's').' carrying the old name (listed in 2), so it is left alone: run it without --rename to remove the work orders only.';
        }

        foreach ($plan['problems'] as $problem) {
            $this->error('   '.$problem);
        }

        // 4. The average resolution, now and after.
        $this->newLine();
        $this->line('4. AVERAGE RESOLUTION: '.self::days($this->averageResolution($db, [])).' now; '.self::days($this->averageResolution($db, $plan['ids'])).' with the work orders named removed'.($named === [] ? ' (none named yet)' : '').'.');

        if ($named !== []) {
            $this->newLine();
            $this->line('WHAT --confirm REMOVES (children before parents, in one transaction, after a backup):');

            foreach ($plan['steps'] as [$table, $ids, $what]) {
                $this->line(sprintf('   %s: %d %s  (DELETE FROM `%s` WHERE id IN (%s))', $table, count($ids), $what, $table, $ids === [] ? 'none' : implode(', ', $ids)));
            }
        }

        $this->newLine();
        $renameTo = $renames['disagree'] !== [] ? '' : ($rename !== '' ? $rename : $this->suggestedName($renames));
        $line = 'php artisan phoenix:clear-test-rows --estate='.$key.' --tickets='.implode(',', $named !== [] ? $named : $suggested).($renameTo === '' ? '' : ' --rename="'.$renameTo.'"').' --confirm';
        $this->line('THE REAL COMMAND (once the lists above are right): '.$line);

        if ($plan['problems'] !== []) {
            $this->error('Refused, so nothing is changed: '.count($plan['problems']).' problem'.(count($plan['problems']) === 1 ? '' : 's').' above.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('DRY RUN: nothing was changed.');

            return self::SUCCESS;
        }

        if ($rename === '' && $renames['rows'] !== []) {
            $this->line('No --rename given: the name is left as it is.');
        }

        return $this->apply($db, $key, $plan, $rename === '' ? [] : $renames['rows'], $rename, $audit);
    }

    /* ---- 1. the work orders people raised ----------------------------------------------------------------------- */

    /**
     * Every work order a person raised, oldest number first, with what marks it as typed while testing. The seed's are
     * left out by the rule the launch-day retirement reads (Retirements): its mark (`seeded_at`), or an id inside a range
     * a seeding run recorded for this estate (`sample_data_ranges`), which is what marks the rows Phoenix Park was seeded
     * with before the mark existed (23 September; the mark is M33's, 5 October).
     *
     * @return Collection<int, TypedTicket>
     */
    private function typedTickets(Connection $db): Collection
    {
        $ranges = DB::connection('mysql')->table('sample_data_ranges')
            ->where(static fn ($q) => $q->where('tenant_id', (string) tenant()?->getTenantKey())->orWhereNull('tenant_id'))
            ->where('connection', 'tenant')->where('table_name', 'maintenance_tickets')
            ->get(['from_id', 'to_id']);
        $seeded = static fn (int $id): bool => $ranges->contains(static fn (object $r): bool => $id > (int) $r->from_id && $id <= (int) $r->to_id);

        return $db->table('maintenance_tickets')
            ->whereNull('seeded_at')
            ->orderBy('number')
            ->get(['id', 'number', 'title', 'location_label', 'description', 'latitude', 'longitude', 'reported_by_name', 'reported_at', 'status', 'closed_at'])
            ->reject(static fn (object $t): bool => $seeded((int) $t->id))
            ->map(static function (object $t): array {
                $title = (string) $t->title;
                $reported = Carbon::parse((string) $t->reported_at);

                return [
                    'id' => (int) $t->id,
                    'number' => (int) $t->number,
                    'title' => mb_strimwidth($title, 0, 70, '…'),
                    'place' => (string) $t->location_label,
                    'reported' => $reported->toDateString(),
                    'by' => (string) ($t->reported_by_name ?? 'nobody named'),
                    'status' => (string) $t->status,
                    'flags' => self::flagsOf($t, $reported),
                    'qa' => str_starts_with($title, self::QA_MARK),
                    'closed' => $t->closed_at !== null,
                ];
            })
            ->values();
    }

    /**
     * What marks a work order as typed while testing.
     *
     * @return list<string>
     */
    private static function flagsOf(object $t, Carbon $reported): array
    {
        $title = (string) $t->title;
        $text = $title.' '.$t->location_label.' '.$t->description;
        $flags = [];

        // Typed by hand ("Hhhhhhhhhh", "Hlow", "Hello, sir", "TEST-HASSAM …"), or by the phones' own checks ("Flow check 30966 …").
        if (preg_match('/\btest\b|test-|^h{3,}|^h+low\b|^hello\b|^hi\b|asdf|qwerty|\b(flow|review|reviewer|offline|online|upload)\s+check\b|\bcheck\s+\d{4,}/i', $title) === 1) {
            $flags[] = 'test words';
        }

        if (stripos($text, self::PLACE) !== false || preg_match('/pakistan|lahore|karachi|islamabad|punjab/i', $text) === 1) {
            $flags[] = 'an address outside Jamaica';
        } elseif ($t->latitude !== null && $t->longitude !== null && ((float) $t->latitude < self::JAMAICA['lat'][0] || (float) $t->latitude > self::JAMAICA['lat'][1] || (float) $t->longitude < self::JAMAICA['lng'][0] || (float) $t->longitude > self::JAMAICA['lng'][1])) {
            $flags[] = 'a GPS fix outside Jamaica';
        }

        if ($reported->year < 2025) {
            $flags[] = 'dated '.$reported->year;
        }

        return $flags;
    }

    /**
     * What removing the named work orders takes, child rows first, and what stops it.
     *
     * @param  Collection<int, TypedTicket>  $typed
     * @param  list<int>  $named
     * @return array{ids: list<int>, numbers: list<int>, steps: list<array{0: string, 1: list<int>, 2: string}>, problems: list<string>}
     */
    private function planFor(Connection $db, Collection $typed, array $named): array
    {
        $byNumber = $typed->keyBy('number');
        $problems = [];
        $ids = [];

        foreach ($named as $number) {
            $ticket = $byNumber->get($number);

            if ($ticket === null) {
                $problems[] = '#'.$number.' is not a work order a person raised here (one the seed made, or none at all): it is never removed by this.';
            } elseif ($ticket['qa']) {
                $problems[] = '#'.$number.' is a QA record: the QA cleanup takes it (docs/qa/CLEANUP_DRY_RUN.md).';
            } else {
                $ids[] = (int) $ticket['id'];
            }
        }

        $numbers = array_values(array_intersect($named, $typed->pluck('number')->all()));

        // A bill for a named work order: a draft goes with it; one approved or paid was posted, and stops the run.
        $bills = $numbers === [] ? collect() : $db->table('bills')->whereIn('ticket_id', $numbers)->get(['id', 'reference', 'status', 'ticket_id']);

        foreach ($bills as $bill) {
            if ($bill->status !== 'draft') {
                $problems[] = 'Bill '.$bill->reference.' for #'.$bill->ticket_id.' is '.$bill->status.': it was posted to the ledger, so #'.$bill->ticket_id.' stays.';
            }
        }

        // Anything else that points at them stops the run: only what is listed below is covered.
        $covered = ['maintenance_ticket_activity.maintenance_ticket_id', 'ticket_media.maintenance_ticket_id', 'bills.ticket_id'];

        foreach ($db->select("select table_name as t, column_name as c, referenced_column_name as r from information_schema.key_column_usage where table_schema = database() and referenced_table_name = 'maintenance_tickets'") as $key) {
            if (in_array($key->t.'.'.$key->c, $covered, true) || $ids === []) {
                continue;
            }

            $pointing = $db->table($key->t)->whereIn($key->c, $key->r === 'number' ? $numbers : $ids)->count();

            if ($pointing > 0) {
                $problems[] = $pointing.' row'.($pointing === 1 ? '' : 's').' of '.$key->t.'.'.$key->c.' point at a named work order, and this does not cover that table.';
            }
        }

        $idsOf = static fn (string $table, string $column, array $values) => $values === [] ? [] : $db->table($table)->whereIn($column, $values)->orderBy('id')->pluck('id')->map(intval(...))->all();

        return [
            'ids' => $ids,
            'numbers' => $numbers,
            'steps' => [
                ['bills', $bills->where('status', 'draft')->pluck('id')->map(intval(...))->values()->all(), 'draft bills raised for them (never posted)'],
                ['ticket_media', $idsOf('ticket_media', 'maintenance_ticket_id', $ids), 'photos and files on them'],
                ['maintenance_ticket_activity', $idsOf('maintenance_ticket_activity', 'maintenance_ticket_id', $ids), 'their timelines'],
                ['maintenance_tickets', $ids, 'the work orders'],
            ],
            'problems' => $problems,
        ];
    }

    /* ---- 2. the seeded resident in the client's first name ------------------------------------------------------ */

    /**
     * Prints section 2, says what a rename would change, and checks what it could not (Hassam, 9 October 2026: "confirm
     * in one line that nothing append-only carries that name as a string, and that no already-generated PDF or audit
     * entry would be left disagreeing with the new name. If something does, tell me and leave it alone").
     *
     * @return array{rows: list<array{table: string, column: string, id: int, before: string, after: string}>, from: list<string>, disagree: list<string>}
     */
    private function renames(Connection $db, string $key, string $rename): array
    {
        $this->newLine();
        $this->line('2. THE SEEDED RESIDENT WHO CARRIES THE CLIENT\'S FIRST NAME');

        $people = $db->table('residents')->where('full_name', 'like', self::CLIENT_FIRST_NAME.' %')->orderBy('id')->get(['id', 'full_name', 'email', 'household_id', 'seeded_at']);
        $rows = [];
        $from = [];
        $disagree = [];

        foreach ($people as $person) {
            $seeded = $person->seeded_at !== null || SampleResidents::isSample((string) $person->full_name, $person->email === null ? null : (string) $person->email);
            $unitId = $db->table('households')->where('id', $person->household_id)->value('unit_id');
            $lot = $unitId === null ? null : $db->table('units')->where('id', $unitId)->value('reference');

            if (! $seeded) {
                $this->line('   residents #'.$person->id.' "'.$person->full_name.'" · '.($lot ?? 'no lot').' · A REAL PERSON (not seeded): never renamed.');

                continue;
            }

            $name = (string) $person->full_name;
            $after = $rename !== '' ? $rename : self::SUGGESTED_FIRST_NAME.' '.trim((string) strstr($name, ' '));
            $from[] = $name;
            $this->line('   residents #'.$person->id.' "'.$name.'" · '.($lot ?? 'no lot').' · seeded placeholder → "'.$after.'"');

            foreach ($this->rowsSaying($db, $name) as $row) {
                if ($this->neverTouched($row['table']) || $row['json']) {
                    $disagree[] = sprintf('%s.%s #%d (%s)', $row['table'], $row['column'], $row['id'], $this->whyKept($row['table'], $row['json']));
                    $this->line(sprintf('      kept: %s.%s #%d "%s" (%s)', $row['table'], $row['column'], $row['id'], self::excerpt($row['value'], $name), $this->whyKept($row['table'], $row['json'])));

                    continue;
                }

                $rows[] = ['table' => $row['table'], 'column' => $row['column'], 'id' => $row['id'], 'before' => $row['value'], 'after' => str_replace($name, $after, $row['value'])];
                $this->line(sprintf('      renamed: %s.%s #%d "%s"%s', $row['table'], $row['column'], $row['id'], self::excerpt($row['value'], $name), in_array($row['table'], ['ballot_options', 'nominations'], true) ? ' (the candidate record: no vote points at a name)' : ''));
            }

            // What a rename cannot reach: documents already issued about this person, and the audit log.
            foreach ($this->documentsAbout($db, $name, $unitId === null ? null : (int) $unitId) as $document) {
                $disagree[] = $document;
                $this->line('      kept: '.$document);
            }

            foreach (DB::connection('mysql')->table('audit_log')->where('tenant_id', $key)->where(static fn ($q) => $q->where('before', 'like', '%'.$name.'%')->orWhere('after', 'like', '%'.$name.'%')->orWhere('actor_name', 'like', '%'.$name.'%'))->orderBy('id')->get(['id', 'action']) as $entry) {
                $disagree[] = 'audit_log #'.$entry->id.' ('.$entry->action.', an audit entry: never edited)';
                $this->line('      kept: audit_log #'.$entry->id.' ('.$entry->action.', an audit entry: never edited)');
            }
        }

        if ($people->isEmpty()) {
            $this->line('   none');
        } elseif ($disagree === []) {
            $this->line('   CHECKED: nothing append-only, no document already issued and no audit entry carries '.implode(', ', array_map(static fn (string $n): string => '"'.$n.'"', $from)).', so the rename leaves nothing disagreeing.');
        } else {
            $this->line('   LEFT ALONE: '.count($disagree).' record'.(count($disagree) === 1 ? '' : 's').' above would keep the old name whatever is renamed, so the rename is not made (Hassam, 9 October 2026).');
        }

        return ['rows' => $rows, 'from' => $from, 'disagree' => $disagree];
    }

    /**
     * Documents already issued that may print `$name`: the statements of their lot and the receipts of its payments, the
     * papers of a meeting whose register carries the name, the certificate of a ballot whose paper does. A PDF is a file
     * as it was made; renaming a row does not change it.
     *
     * @return list<string>
     */
    private function documentsAbout(Connection $db, string $name, ?int $unitId): array
    {
        $meetings = $db->table('meeting_attendance')->where('attendee_name', $name)->distinct()->pluck('meeting_id')->map(strval(...))->all();
        $ballots = $db->table('ballot_options')->where('label', $name)->pluck('ballot_id')
            ->merge($db->table('nominations')->where('candidate_name', $name)->pluck('ballot_id'))->unique()->map(strval(...))->values()->all();
        $payments = $unitId === null ? [] : $db->table('payments')->where('unit_id', $unitId)->pluck('id')->map(strval(...))->all();

        return $db->table('documents')
            ->whereNotNull('path')
            ->where(static function ($q) use ($unitId, $meetings, $ballots, $payments): void {
                $q->whereRaw('1 = 0');

                if ($unitId !== null) {
                    $q->orWhere(static fn ($s) => $s->where('subject_type', 'unit')->where('subject_id', (string) $unitId));
                }

                foreach (['meeting' => $meetings, 'ballot' => $ballots, 'payment' => $payments] as $type => $ids) {
                    if ($ids !== []) {
                        $q->orWhere(static fn ($s) => $s->where('subject_type', $type)->whereIn('subject_id', $ids));
                    }
                }
            })
            ->orderBy('id')
            ->get(['id', 'kind', 'title'])
            ->map(static fn (object $d): string => 'documents #'.$d->id.' ('.$d->kind.' "'.$d->title.'", a PDF already issued: it keeps the name it was made with)')
            ->all();
    }

    /** @param array{rows: list<array<string, mixed>>, from: list<string>} $renames */
    private function suggestedName(array $renames): string
    {
        $from = $renames['from'][0] ?? null;

        return $from === null ? '' : self::SUGGESTED_FIRST_NAME.' '.trim((string) strstr($from, ' '));
    }

    /**
     * Every row of the estate's own record tables whose text says `$text`, in any text or JSON column (never a column
     * MySQL computes, never a machinery table: a cache or a delivery queue is not a record anybody reads).
     *
     * @return list<array{table: string, column: string, id: int, value: string, json: bool}>
     */
    private function rowsSaying(Connection $db, string $text): array
    {
        $columns = $db->select("select c.table_name as t, c.column_name as c, c.data_type as d from information_schema.columns c
            join information_schema.columns k on k.table_schema = c.table_schema and k.table_name = c.table_name and k.column_name = 'id'
            join information_schema.tables tb on tb.table_schema = c.table_schema and tb.table_name = c.table_name and tb.table_type = 'BASE TABLE'
            where c.table_schema = database() and c.data_type in ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json')
              and c.extra not like '%GENERATED%' and c.column_name not in ('seeded_by')
            order by c.table_name, c.ordinal_position");

        $found = [];

        foreach ($columns as $column) {
            if (in_array((string) $column->t, SeededTables::ESTATE_MACHINERY, true)) {
                continue;
            }

            foreach ($db->table($column->t)->where($column->c, 'like', '%'.$text.'%')->orderBy('id')->get(['id', $column->c]) as $row) {
                $found[] = ['table' => (string) $column->t, 'column' => (string) $column->c, 'id' => (int) $row->id, 'value' => (string) $row->{$column->c}, 'json' => $column->d === 'json'];
            }
        }

        return $found;
    }

    private function neverTouched(string $table): bool
    {
        return in_array($table, self::BALLOT_TABLES, true) || in_array($table, SampleData::appendOnly('tenant'), true);
    }

    private function whyKept(string $table, bool $json = false): string
    {
        return match (true) {
            in_array($table, self::BALLOT_TABLES, true) => 'a ballot paper: no ballot row is ever touched',
            in_array($table, SampleData::appendOnly('tenant'), true) => 'append-only: a posted record is never edited',
            default => 'a record of what happened, kept as it was written',
        };
    }

    /* ---- 4. the average resolution ------------------------------------------------------------------------------ */

    /**
     * The maintenance tiles' own average (Maintenance::tiles): the mean of reported-to-closed over closed work orders,
     * in days to one decimal place, with the ids given left out.
     *
     * @param  list<int>  $without
     */
    private function averageResolution(Connection $db, array $without): float
    {
        $hours = MaintenanceTicket::query()
            ->whereNotIn('id', $without === [] ? [0] : $without)
            ->get(['id', 'status', 'reported_at', 'sla_hours', 'closed_at'])
            ->filter(static fn (MaintenanceTicket $t): bool => $t->closed_at !== null && $t->isClosed())
            ->map(static fn (MaintenanceTicket $t): float => (float) $t->reported_at->diffInHours($t->closed_at, absolute: true))
            ->values();

        return $hours->isEmpty() ? 0.0 : round($hours->sum() / $hours->count() / 24, 1);
    }

    private static function days(float $days): string
    {
        return number_format($days, 1).' days';
    }

    /* ---- the real run ------------------------------------------------------------------------------------------- */

    /**
     * @param  array{ids: list<int>, numbers: list<int>, steps: list<array{0: string, 1: list<int>, 2: string}>, problems: list<string>}  $plan
     * @param  list<array{table: string, column: string, id: int, before: string, after: string}>  $renames
     */
    private function apply(Connection $db, string $key, array $plan, array $renames, string $rename, AuditLogger $audit): int
    {
        $lines = [];

        foreach ($plan['steps'] as [$table, $ids]) {
            foreach ($ids === [] ? [] : $db->table($table)->whereIn('id', $ids)->orderBy('id')->get() as $row) {
                $lines[] = json_encode(['op' => 'delete', 'table' => $table, 'row' => (array) $row], JSON_THROW_ON_ERROR);
            }
        }

        foreach ($renames as $change) {
            $lines[] = json_encode(['op' => 'update', ...$change], JSON_THROW_ON_ERROR);
        }

        $dir = storage_path('app/private/test-row-backups');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/clear-test-rows-'.$key.'-'.Carbon::now()->format('Ymd-His').'.jsonl';
        File::put($path, implode("\n", $lines)."\n");
        $sha = hash_file('sha256', $path);

        $this->line('Backup written: '.$path);
        $this->line('Its SHA-256:    '.$sha);

        try {
            $db->transaction(function () use ($db, $plan, $renames): void {
                foreach ($plan['steps'] as [$table, $ids]) {
                    if ($ids !== []) {
                        $db->table($table)->whereIn('id', $ids)->delete();
                    }
                }

                foreach ($renames as $change) {
                    $updated = $db->table($change['table'])->where('id', $change['id'])->where($change['column'], $change['before'])->update([$change['column'] => $change['after']]);

                    if ($updated !== 1) {
                        throw new \DomainException($change['table'].' #'.$change['id'].' changed since the dry run read it.');
                    }
                }
            });
        } catch (Throwable $failure) {
            $this->error('Stopped, so nothing was changed: '.$failure->getMessage());

            return self::FAILURE;
        }

        $audit->record(
            action: 'estate.test_rows_cleared',
            entityType: 'Estate',
            entityId: $key,
            before: ['work_orders' => $plan['numbers'], 'renamed' => count($renames)],
            after: ['renamed_to' => $rename === '' ? null : $rename, 'backup' => $path, 'sha256' => $sha, 'via' => 'phoenix:clear-test-rows', 'reason' => 'test rows on screen (M60 §C3)'],
            tenantId: $key,
        );

        $this->info('Done: '.count($plan['ids']).' work order'.(count($plan['ids']) === 1 ? '' : 's').' removed, '.count($renames).' row'.(count($renames) === 1 ? '' : 's').' renamed, on the audit log. Average resolution now '.self::days($this->averageResolution($db, [])).'.');
        $this->line('To put it all back: php artisan phoenix:clear-test-rows --estate='.$key.' --restore='.$path);

        return self::SUCCESS;
    }

    /** Puts back what a real run's backup holds, in one transaction, and says so on the audit log. */
    private function restore(string $path, string $key, AuditLogger $audit): int
    {
        if (! is_file($path)) {
            $this->error('There is no backup at '.$path.'. Nothing was changed.');

            return self::INVALID;
        }

        $entries = array_map(static fn (string $line): array => (array) json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) File::get($path)))));
        $db = DB::connection('tenant');

        // Parents first: the work orders, then what hung off them.
        $order = ['maintenance_tickets' => 0, 'maintenance_ticket_activity' => 1, 'ticket_media' => 2, 'bills' => 3];
        $deleted = array_values(array_filter($entries, static fn (array $e): bool => $e['op'] === 'delete'));
        usort($deleted, static fn (array $a, array $b): int => ($order[$a['table']] ?? 9) <=> ($order[$b['table']] ?? 9));

        try {
            $db->transaction(function () use ($db, $deleted, $entries): void {
                foreach ($deleted as $entry) {
                    if ($db->table($entry['table'])->where('id', $entry['row']['id'])->exists()) {
                        throw new \DomainException($entry['table'].' #'.$entry['row']['id'].' is there already.');
                    }

                    $db->table($entry['table'])->insert($entry['row']);
                }

                foreach ($entries as $entry) {
                    if ($entry['op'] === 'update') {
                        $db->table($entry['table'])->where('id', $entry['id'])->where($entry['column'], $entry['after'])->update([$entry['column'] => $entry['before']]);
                    }
                }
            });
        } catch (Throwable $failure) {
            $this->error('Stopped, so nothing was put back: '.$failure->getMessage());

            return self::FAILURE;
        }

        $audit->record(
            action: 'estate.test_rows_restored',
            entityType: 'Estate',
            entityId: $key,
            before: null,
            after: ['backup' => $path, 'rows' => count($entries), 'via' => 'phoenix:clear-test-rows --restore'],
            tenantId: $key,
        );

        $this->info('Put back: '.count($entries).' row'.(count($entries) === 1 ? '' : 's').' from '.$path.'.');

        return self::SUCCESS;
    }

    /* ---- small things -------------------------------------------------------------------------------------------- */

    /** @return list<int>|null */
    private function numbers(string $option): ?array
    {
        if (trim($option) === '') {
            return [];
        }

        $numbers = [];

        foreach (explode(',', $option) as $part) {
            $part = trim(ltrim(trim($part), '#'));

            if (! ctype_digit($part)) {
                return null;
            }

            $numbers[] = (int) $part;
        }

        return array_values(array_unique($numbers));
    }

    /** Forty characters either side of where `$needle` is. */
    private static function excerpt(string $value, string $needle): string
    {
        $at = mb_stripos($value, $needle);
        $from = max(0, ($at === false ? 0 : $at) - 40);
        $cut = mb_substr($value, $from, mb_strlen($needle) + 80);

        return ($from > 0 ? '…' : '').str_replace(["\r", "\n"], ' ', $cut).($from + mb_strlen($cut) < mb_strlen($value) ? '…' : '');
    }
}
