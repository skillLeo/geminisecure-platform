<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AlertEmailDispatch;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\JamaicanNumber;
use App\Support\Seeded\ReservedContacts;
use App\Support\Seeded\SeededPeople;
use App\Support\Seeded\SeesEveryRow;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 *   php artisan estate:rewrite-seeded-contacts              what it would change, everywhere: every estate and the platform
 *   php artisan estate:rewrite-seeded-contacts --confirm    change it
 *   … --estate=phoenixparkvillage1                          one estate, and its people on the platform, only
 *
 * EVERY SEEDED PERSON'S ADDRESS AND NUMBER, MOVED TO ONES NOBODY HAS (M35 §5.3). The demonstration seed invented
 * residents with addresses on `email.com`, a live public mail service, numbers outside the fictional block, a real
 * utility's customer-care mailbox for a vendor, and addresses on `geminisecure.app`, which nobody has registered and
 * anybody could. The mail transport and the SMS driver already refuse to write to any of them (`SeededRecipients`);
 * this takes the addresses away, so the rows hold nothing a stranger could ever be reached on:
 *
 *   EMAIL   the same name on example.com (`ReservedContacts::reservedEmail`): andrea.fletcher@email.com becomes
 *           andrea.fletcher@example.com. Where two would collide on a unique key, the second gets "+2".
 *   PHONE   the same number with its exchange 555 and its line 01 and its own last two digits, every other character
 *           as typed (`ReservedContacts::fictionalPhone`): 876-555-0047 becomes 876-555-0147.
 *
 * ONLY SEEDED PEOPLE, by M33's definition (`SeededPeople`): the mark, a range the seed recorded before the mark existed —
 * which is how every seeded row on production is known — one of `company:demo-seed`'s officers, or the demonstration
 * estate. A row anybody else wrote is never read for a change. Never the estate's configuration (its emergency numbers
 * are the client's own), never a console account, and never the app-store reviewer's sign-in name, which the reviewer
 * types and which no message is sent to.
 *
 * DRY RUN UNLESS --confirm: every change, row by row by table and id — the old value masked, and the new one masked
 * as far as it would show the old (`shown()`), never a name — and the count per estate, so the printout can be pasted
 * into a report. With --confirm it is ONE TRANSACTION in every database it touches, held open together and committed together
 * at the end, and ONE AUDIT ENTRY naming every row by table and id (never an address). Run twice, the second run finds
 * nothing to change. Nothing is deleted.
 */
final class EstateRewriteSeededContacts extends Command
{
    // It reads and rewrites rows a retirement would hide: a seeded person is one whether shown or not.
    use SeesEveryRow;

    protected $signature = 'estate:rewrite-seeded-contacts
        {--estate= : only this estate, and its people on the platform (every estate when left out)}
        {--confirm : make the change; without it, only say what it would be}';

    protected $description = "Move every seeded person's address and number to ones nobody has (example.com, 555-0100 to 555-0199). A dry run unless --confirm.";

    /** @var array<string, ConnectionInterface> */
    private array $estates = [];

    public function handle(AuditLogger $audit): int
    {
        $only = trim((string) $this->option('estate'));
        $estates = [];

        foreach (Tenant::query()->orderBy('id')->pluck('id') as $id) {
            $estate = $only === '' || (string) $id === $only ? Tenant::query()->find($id) : null;

            if ($estate instanceof Tenant) {
                $estates[] = $estate;
            }
        }

        if ($only !== '' && $estates === []) {
            $this->error("No estate with the id \"{$only}\". Nothing was changed.");

            return self::INVALID;
        }

        $people = new SeededPeople;
        $changes = [];
        $skipped = [];

        try {
            foreach ($estates as $estate) {
                $key = (string) $estate->getTenantKey();
                $db = $this->open($estate);

                foreach (SeededPeople::ESTATE as $table => [$email, $phone]) {
                    foreach ($db->table($table)->orderBy('id')->get(array_values(array_filter(['id', 'seeded_at', $email, $phone]))) as $row) {
                        if ($people->estateRowIsSeeded($key, $table, (int) $row->id, $row->seeded_at)) {
                            array_push($changes, ...self::changesOf($key, 'tenant', $table, $row, $email, $phone));
                        }
                    }
                }
            }

            $keys = array_map(static fn (Tenant $t): string => (string) $t->getTenantKey(), $estates);
            $central = DB::connection('mysql');

            foreach ($central->table('guards')->whereIn('tenant_id', $keys)->orderBy('id')->get(['id', 'tenant_id', 'seeded_at', 'email', 'phone']) as $row) {
                if ($people->platformRowIsSeeded('guards', (int) $row->id, (string) $row->tenant_id, $row->seeded_at)) {
                    array_push($changes, ...self::changesOf((string) $row->tenant_id, 'mysql', 'guards', $row, 'email', 'phone'));
                }
            }

            foreach ($central->table('invitations')->when($only !== '', static fn ($q) => $q->whereIn('tenant_id', $keys))->orderBy('id')->get(['id', 'tenant_id', 'seeded_at', 'email']) as $row) {
                $tenant = $row->tenant_id === null ? null : (string) $row->tenant_id;

                if ($people->platformRowIsSeeded('invitations', (int) $row->id, $tenant, $row->seeded_at)) {
                    array_push($changes, ...self::changesOf($tenant, 'mysql', 'invitations', $row, 'email', null));
                }
            }

            foreach ($central->table('resident_accounts')->whereIn('tenant_id', $keys)->orderBy('id')->get(['id', 'tenant_id', 'seeded_at', 'channel', 'destination', 'reviewer']) as $row) {
                if (! $people->platformRowIsSeeded('resident_accounts', (int) $row->id, (string) $row->tenant_id, $row->seeded_at)) {
                    continue;
                }

                if ((bool) $row->reviewer) {
                    $skipped[] = "resident_accounts #{$row->id} at {$row->tenant_id}: the app-store reviewer's sign-in name — typed by the reviewer, and no message is ever sent to it";

                    continue;
                }

                $isEmail = $row->channel === 'email';
                array_push($changes, ...self::changesOf((string) $row->tenant_id, 'mysql', 'resident_accounts', $row, $isEmail ? 'destination' : null, $isEmail ? null : 'destination'));
            }

            $changes = $this->unique($changes);

            $this->report($estates, $changes, $skipped);

            if ($changes === []) {
                $this->info('Nothing to change: no seeded person holds an address or a number anybody could receive. Nothing was changed.');

                return self::SUCCESS;
            }

            if (! $this->option('confirm')) {
                $this->info('Nothing was changed. Run again with --confirm to make these '.count($changes).' changes, in one transaction, with one audit entry.');

                return self::SUCCESS;
            }

            $this->apply($changes, $audit);
        } finally {
            foreach (array_keys($this->estates) as $name) {
                DB::purge($name);
                config(['database.connections.'.$name => null]);
            }
        }

        $this->info('Rewritten: '.count($changes).' addresses and numbers of seeded people. Every row is still there; none of them can reach anybody now.');
        $this->line('  Run again, it finds nothing to change.');

        return self::SUCCESS;
    }

    /**
     * What one row's email and phone would become.
     *
     * @return list<array{tenant: string|null, connection: string, table: string, id: int, column: string, kind: string, from: string, to: string}>
     */
    private static function changesOf(?string $tenant, string $connection, string $table, object $row, ?string $email, ?string $phone): array
    {
        $out = [];

        if ($email !== null && ($value = trim((string) ($row->{$email} ?? ''))) !== '' && ! ReservedContacts::emailIsReserved($value)) {
            $out[] = ['tenant' => $tenant, 'connection' => $connection, 'table' => $table, 'id' => (int) $row->id, 'column' => $email, 'kind' => 'email', 'from' => $value, 'to' => ReservedContacts::reservedEmail($value)];
        }

        if ($phone !== null && ($value = trim((string) ($row->{$phone} ?? ''))) !== '' && ! ReservedContacts::phoneIsFictional($value)) {
            $out[] = ['tenant' => $tenant, 'connection' => $connection, 'table' => $table, 'id' => (int) $row->id, 'column' => $phone, 'kind' => 'phone', 'from' => $value, 'to' => ReservedContacts::fictionalPhone($value)];
        }

        return $out;
    }

    /**
     * A resident account's destination is unique in its estate and channel: an account rewritten to an address another
     * one already holds, or is about to, gets "+2", "+3" before the @; to a number, the next free line in the block.
     *
     * @param  list<array{tenant: string|null, connection: string, table: string, id: int, column: string, kind: string, from: string, to: string}>  $changes
     * @return list<array{tenant: string|null, connection: string, table: string, id: int, column: string, kind: string, from: string, to: string}>
     */
    private function unique(array $changes): array
    {
        $taken = [];

        foreach ($changes as $i => $change) {
            if ($change['table'] !== 'resident_accounts') {
                continue;
            }

            $channel = $change['kind'] === 'email' ? 'email' : 'sms';
            $free = function (string $to) use ($change, $channel, &$taken): bool {
                return ! isset($taken[$change['tenant'].'|'.$channel.'|'.mb_strtolower($to)])
                    && ! DB::connection('mysql')->table('resident_accounts')->where('tenant_id', $change['tenant'])
                        ->where('channel', $channel)->where('destination', $to)->where('id', '!=', $change['id'])->exists();
            };

            $to = $change['to'];

            for ($n = 2; ! $free($to) && $n <= 101; $n++) {
                $to = $channel === 'email'
                    ? str_replace('@', '+'.$n.'@', $change['to'])
                    : substr($change['to'], 0, -2).str_pad((string) ((((int) substr($change['to'], -2)) + $n - 1) % 100), 2, '0', STR_PAD_LEFT);
            }

            $taken[$change['tenant'].'|'.$channel.'|'.mb_strtolower($to)] = true;
            $changes[$i]['to'] = $to;
        }

        return $changes;
    }

    /**
     * @param  list<Tenant>  $estates
     * @param  list<array{tenant: string|null, connection: string, table: string, id: int, column: string, kind: string, from: string, to: string}>  $changes
     * @param  list<string>  $skipped
     */
    private function report(array $estates, array $changes, array $skipped): void
    {
        $this->line('Seeded people whose address or number somebody could receive, and what each becomes.');
        $this->line('"Seeded" is M33\'s: the mark, a range the seed recorded, the company seed\'s officers, or the demonstration estate.');
        $this->newLine();

        $groups = [];
        foreach ($changes as $change) {
            $groups[$change['tenant'] ?? '(the platform)'][] = $change;
        }

        foreach ($estates as $estate) {
            $key = (string) $estate->getTenantKey();
            $mine = $groups[$key] ?? [];
            $this->line(sprintf('== %s (%s): %d %s', $key, (string) $estate->name, count($mine), count($mine) === 1 ? 'change' : 'changes'));

            if ($mine === []) {
                continue;
            }

            $byTable = [];
            foreach ($mine as $change) {
                $byTable[$change['connection'].'.'.$change['table']][$change['kind']] = ($byTable[$change['connection'].'.'.$change['table']][$change['kind']] ?? 0) + 1;
            }

            foreach ($byTable as $table => $kinds) {
                $this->line(sprintf('   %-45s %s', ($table[0] === 't' ? 'estate ' : 'platform ').substr($table, (int) strpos($table, '.') + 1), implode(', ', array_map(static fn (string $k, int $n): string => $n.' '.($k === 'email' ? ($n === 1 ? 'address' : 'addresses') : ($n === 1 ? 'number' : 'numbers')), array_keys($kinds), $kinds))));
            }

            foreach ($mine as $change) {
                $this->line(sprintf(
                    '     %s #%-5d %-15s %s → %s',
                    $change['table'],
                    $change['id'],
                    $change['column'],
                    $change['kind'] === 'email' ? AlertEmailDispatch::mask($change['from']) : JamaicanNumber::mask($change['from']),
                    self::shown($change),
                ));
            }
        }

        foreach ($groups['(the platform)'] ?? [] as $change) {
            $this->line(sprintf('   platform %s #%d %s → %s', $change['table'], $change['id'], AlertEmailDispatch::mask($change['from']), self::shown($change)));
        }

        foreach ($skipped as $line) {
            $this->line('   left as it is: '.$line);
        }

        $this->newLine();
        $emails = count(array_filter($changes, static fn (array $c): bool => $c['kind'] === 'email'));
        $this->line(sprintf('In all: %d %s and %d %s, on %d rows.', $emails, $emails === 1 ? 'address' : 'addresses', count($changes) - $emails, count($changes) - $emails === 1 ? 'number' : 'numbers', count(array_unique(array_map(static fn (array $c): string => $c['connection'].$c['table'].'#'.$c['id'].'@'.$c['tenant'], $changes)))));
    }

    /**
     * The new value as the dry run prints it. AN ADDRESS KEEPS ITS LOCAL PART, so printed whole it would print the old
     * address's too — and a row inside a seed range may since have been given to a real person. So the local part is
     * masked as the old one is, and the reserved domain, which is nobody's, is shown. A number is shown whole: in
     * 555-0100 to 555-0199 it is nobody's, and it carries no more of the old one than the old one's mask shows.
     *
     * @param  array{kind: string, to: string}  $change
     */
    private static function shown(array $change): string
    {
        $at = strrpos($change['to'], '@');

        if ($change['kind'] !== 'email' || $at === false) {
            return $change['to'];
        }

        return mb_substr(substr($change['to'], 0, $at), 0, 2).'***'.substr($change['to'], $at);
    }

    /**
     * Every database's transaction open at once, every change made, the audit entry written, and every one committed
     * together — so a fault anywhere before the commits changes nothing anywhere.
     *
     * @param  list<array{tenant: string|null, connection: string, table: string, id: int, column: string, kind: string, from: string, to: string}>  $changes
     */
    private function apply(array $changes, AuditLogger $audit): void
    {
        $central = DB::connection('mysql');
        $open = [];

        try {
            $central->beginTransaction();
            $open[] = $central;

            foreach ($this->estates as $db) {
                $db->beginTransaction();
                $open[] = $db;
            }

            foreach ($changes as $change) {
                $db = $change['connection'] === 'mysql' ? $central : $this->estates[self::connectionName((string) $change['tenant'])];
                $db->table($change['table'])->where('id', $change['id'])->update([$change['column'] => $change['to']]);
            }

            $rows = [];
            foreach ($changes as $change) {
                $rows[$change['tenant'] ?? '(platform)'][$change['connection'].'.'.$change['table']][] = $change['id'].':'.$change['column'];
            }

            $audit->record(
                action: 'seeded.contacts_rewritten',
                entityType: 'Platform',
                entityId: 'seeded-contacts',
                after: [
                    'changes' => count($changes),
                    'rows' => $rows,
                    'to' => 'example.com, and 555-0100 to 555-0199',
                    'by' => 'estate:rewrite-seeded-contacts',
                    'why' => 'a seeded person must hold no address or number anybody could receive (M35 §5.3)',
                ],
                actorName: 'estate:rewrite-seeded-contacts',
            );

            foreach (array_reverse($open) as $db) {
                $db->commit();
            }
        } catch (Throwable $e) {
            foreach ($open as $db) {
                rescue(static fn () => $db->rollBack(), report: false);
            }

            throw $e;
        }
    }

    /** The estate's own connection, made from its own credentials and kept for this run. */
    private function open(Tenant $estate): ConnectionInterface
    {
        $name = self::connectionName((string) $estate->getTenantKey());
        config(['database.connections.'.$name => $estate->database()->connection()]);

        return $this->estates[$name] = DB::connection($name);
    }

    private static function connectionName(string $tenant): string
    {
        return 'rewrite_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($tenant));
    }
}
