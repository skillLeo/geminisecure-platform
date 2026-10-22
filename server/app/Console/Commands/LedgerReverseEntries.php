<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Account;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Charge;
use App\Models\Estate\Journal;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use App\Support\MoneyFormatter;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan ledger:reverse-entries --estate=phoenixparkvillage1 --bulk-of=CHG-2026-03-0001 --charge=CHG-2026-10-0001
 *       --receipt=PPV-R-11727,PPV-R-11728 --journal=GATE-PROBE --reason="Entered while testing; not real"   what it would do
 *   … the same with --confirm                                                                               do it
 *
 * ENTRIES THAT SHOULD NEVER HAVE BEEN MADE, UNDONE THE ONLY WAY THE LEDGER ALLOWS (M34 §2). On 4 October a whole-phase
 * dues charge was posted at Phoenix Park Village 1 while the system was being explored — 400 charges of J$500 for March
 * 2026 — beside three test charges, ten test payments and a deploy gate's probe. A posted journal cannot be edited or
 * deleted (four triggers and a revoked grant say so), and should not be: this posts each entry's MIRROR through
 * `Ledger::reverse`, marks each charge and payment `reversed` (the status the arrears, the statements and the
 * resident's app already leave out), and writes one audit entry naming everything. Both rows of every pair stay.
 *
 * WHAT IT IS TOLD, AND NOTHING ELSE. Every entry is named: `--bulk-of` a charge reference, for the whole bulk post that
 * charge was raised in; `--charge` one charge; `--receipt` one payment; `--journal` one hand-posted journal. Lists may be
 * comma-separated or repeated. A name it cannot find refuses the whole run.
 *
 * WHAT IT REFUSES, WHOLE — nothing is reversed if any named entry is:
 *   - part of the demonstration's sample (marked `seeded_at`, or inside `sample_data_ranges`): launch day's
 *     `launch:retire-sample-data` hides the sample, and this never reverses it;
 *   - posted in a run with others (a schedule's month, a bank sweep), whose one entry carries other households' lines:
 *     a run is reversed whole, from the Dues page;
 *   - a card payment taken through a real gateway, which is refunded through the gateway, not reversed here;
 *   - a charge or payment's own journal named with --journal: it is reversed through its charge or payment, so that its
 *     status follows; or a journal that is itself a reversal.
 * An entry ALREADY reversed is left as it is and said so, so a run repeated after a partial cleanup does no harm.
 *
 * EACH MIRROR IS DATED THE DAY ITS ENTRY WAS (`Ledger::reverse`'s `$on`): every month's Profit & Loss, every "as at"
 * balance and every statement reads as if the entry had never been posted, while both rows stay and the mirror's own
 * creation time says when it was corrected. All of it in one transaction: every reversal, or none.
 *
 * Without --confirm nothing is written, and it prints what would be: every entry, the reversal each would get, and
 * what the accounts, the months of the Profit & Loss and the Dues page's total would read after. Run on production by
 * a person who has read that dry run, never by a deploy.
 */
class LedgerReverseEntries extends Command
{
    protected $signature = 'ledger:reverse-entries
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--bulk-of=* : a charge reference: every charge of the bulk post it was raised in}
        {--charge=* : a charge reference, alone}
        {--receipt=* : a payment\'s receipt number}
        {--journal=* : a hand-posted journal\'s reference}
        {--reason= : why, ten characters at least; it goes on every reversal\'s memo}
        {--confirm : post the reversals; without it nothing is written}';

    protected $description = 'Reverse named charges, payments and hand-posted journals — each by its mirror, dated the day it was posted';

    private const SAMPLE = '%s is part of the demonstration\'s sample. launch:retire-sample-data hides the sample on launch day; this command never reverses it.';

    /** @var list<string> */
    private array $refusals = [];

    /** @var list<string> */
    private array $skipped = [];

    public function handle(AuditLogger $audit, Ledger $ledger, Dues $dues): int
    {
        $key = (string) $this->option('estate');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Say which estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        $named = [
            'bulk' => self::listOf($this->option('bulk-of')),
            'charge' => self::listOf($this->option('charge')),
            'receipt' => self::listOf($this->option('receipt')),
            'journal' => self::listOf($this->option('journal')),
        ];

        if (array_merge(...array_values($named)) === []) {
            $this->error('Name what to reverse: --bulk-of=, --charge=, --receipt= or --journal=. Nothing was changed.');

            return self::INVALID;
        }

        $reason = trim((string) $this->option('reason'));
        $apply = (bool) $this->option('confirm');

        if ($apply && mb_strlen($reason) < 10) {
            $this->error('Say why, in ten characters at least: --reason="Entered while testing; not real". It goes on every reversal\'s memo. Nothing was changed.');

            return self::INVALID;
        }

        return (int) $estate->run(function () use ($estate, $key, $named, $reason, $apply, $audit, $ledger, $dues): int {
            $plan = $this->plan($key, $named);

            $this->info(($apply ? 'Reversing at ' : 'What --confirm would reverse at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is written without --confirm.'));

            if ($this->refusals !== []) {
                $this->newLine();
                $this->error('Refused, so nothing is reversed:');

                foreach ($this->refusals as $refusal) {
                    $this->line('  - '.$refusal);
                }

                $this->newLine();
                $this->line('Nothing was changed.');

                return self::FAILURE;
            }

            $this->report($plan, $reason, $dues);

            if ($plan['entries'] === []) {
                $this->newLine();
                $this->info('Nothing to reverse: every entry named is reversed already. Nothing was changed.');

                return self::SUCCESS;
            }

            if (! $apply) {
                $this->newLine();
                $this->info(count($plan['entries']).' reversal'.(count($plan['entries']) === 1 ? '' : 's').' would be posted. Nothing is written without --confirm.');

                return self::SUCCESS;
            }

            $posted = $this->reverseAll($plan, $reason, $ledger);

            $audit->record(
                action: 'ledger.entries_reversed',
                entityType: 'Estate',
                entityId: $key,
                after: [
                    'reason' => $reason,
                    'by' => 'ledger:reverse-entries',
                    'charges' => array_values(array_map(static fn (Charge $c): string => $c->reference, $plan['charges'])),
                    'payments' => array_values(array_map(static fn (Payment $p): string => $p->receipt_no, $plan['payments'])),
                    'journals' => array_values(array_map(static fn (Journal $j): string => $j->reference, $plan['manual'])),
                    'reversals' => $posted,
                    'left_as_they_were' => $this->skipped,
                ],
                tenantId: $key,
            );

            $this->newLine();
            $this->info('Done: '.count($posted).' reversal'.(count($posted) === 1 ? '' : 's').' posted, '.self::range($posted).'.');
            $this->line('  '.count($plan['charges']).' charge'.(count($plan['charges']) === 1 ? '' : 's').' and '.count($plan['payments']).' payment'.(count($plan['payments']) === 1 ? '' : 's').' now read reversed. Every original entry stays on the ledger beside its reversal.');

            [$debits, $credits] = $this->trial();
            $this->line('  The ledger '.($debits === $credits ? 'still balances' : 'DOES NOT BALANCE').': debits '.MoneyFormatter::fromMinor($debits).', credits '.MoneyFormatter::fromMinor($credits).'.');

            return self::SUCCESS;
        });
    }

    /**
     * Find every named entry, refuse what cannot be reversed here, and leave out what already is.
     *
     * @param  array{bulk: list<string>, charge: list<string>, receipt: list<string>, journal: list<string>}  $named
     * @return array{charges: array<int, Charge>, payments: array<int, Payment>, manual: array<int, Journal>, bulk: array<string, list<int>>, entries: array<int, array{journal: Journal, what: string}>, byRef: array<string, Journal>}
     */
    private function plan(string $key, array $named): array
    {
        // Read through the sample's scope (M33 §5), so a retired sample row is FOUND and refused rather than "not found".
        return Retirements::seeingEverything(function () use ($key, $named): array {
            $spans = $this->sampleSpans($key);

            /** @var array<int, Charge> $charges */
            $charges = [];
            $bulk = [];
            $found = Charge::query()->whereIn('reference', [...$named['bulk'], ...$named['charge']])->get()->keyBy('reference');

            foreach ($named['bulk'] as $reference) {
                $first = $found[$reference] ?? null;

                if (! $first instanceof Charge) {
                    $this->refusals[] = '--bulk-of='.$reference.': no charge carries that reference.';

                    continue;
                }

                if ($first->bulk_token === null) {
                    $this->refusals[] = '--bulk-of='.$reference.': that charge was not posted in a bulk post. Name it with --charge= instead.';

                    continue;
                }

                $members = Charge::query()->where('bulk_token', $first->bulk_token)->orderBy('id')->get();
                $bulk[$reference] = $members->modelKeys();

                foreach ($members as $charge) {
                    $charges[(int) $charge->id] = $charge;
                }
            }

            foreach ($named['charge'] as $reference) {
                $charge = $found[$reference] ?? null;

                if (! $charge instanceof Charge) {
                    $this->refusals[] = '--charge='.$reference.': no charge carries that reference.';

                    continue;
                }

                $charges[(int) $charge->id] = $charge;
            }

            /** @var array<int, Payment> $payments */
            $payments = [];
            $receipts = Payment::query()->whereIn('receipt_no', $named['receipt'])->get()->keyBy('receipt_no');

            foreach ($named['receipt'] as $receipt) {
                $payment = $receipts[$receipt] ?? null;

                if (! $payment instanceof Payment) {
                    $this->refusals[] = '--receipt='.$receipt.': no payment carries that receipt number.';

                    continue;
                }

                $payments[(int) $payment->id] = $payment;
            }

            /** @var array<int, Journal> $manual */
            $manual = [];
            $journalsNamed = Journal::query()->whereIn('reference', $named['journal'])->get()->keyBy('reference');

            foreach ($named['journal'] as $reference) {
                $journal = $journalsNamed[$reference] ?? null;

                if (! $journal instanceof Journal) {
                    $this->refusals[] = '--journal='.$reference.': no journal carries that reference.';

                    continue;
                }

                $manual[(int) $journal->id] = $journal;
            }

            ksort($charges);
            ksort($payments);
            ksort($manual);

            // Every row the plan reads, each in one query: the entries, how many rows share each, which are reversed and
            // which carry the seeder's mark.
            $refs = array_values(array_filter([
                ...array_map(static fn (Charge $c): ?string => $c->journal_ref, $charges),
                ...array_map(static fn (Payment $p): ?string => $p->journal_ref, $payments),
            ]));
            $journals = self::chunked($refs, static fn (array $chunk): Collection => Journal::query()->whereIn('reference', $chunk)->get())->keyBy('reference');
            $chargesSharing = self::chunked($refs, static fn (array $chunk): Collection => Charge::query()->whereIn('journal_ref', $chunk)->groupBy('journal_ref')->selectRaw('journal_ref, COUNT(*) AS n')->get())->pluck('n', 'journal_ref');
            $paymentsSharing = self::chunked($refs, static fn (array $chunk): Collection => Payment::query()->whereIn('journal_ref', $chunk)->groupBy('journal_ref')->selectRaw('journal_ref, COUNT(*) AS n')->get())->pluck('n', 'journal_ref');
            $journalIds = [...$journals->pluck('id')->map(intval(...))->all(), ...array_keys($manual)];
            $reversed = array_flip(self::chunked($journalIds, static fn (array $chunk): Collection => Journal::query()->whereIn('reverses_journal_id', $chunk)->pluck('reverses_journal_id'))->map(intval(...))->all());
            $marked = [
                'charges' => self::marked('charges', array_keys($charges)),
                'payments' => self::marked('payments', array_keys($payments)),
                'journals' => self::marked('journals', array_keys($manual)),
            ];

            $entries = [];

            foreach ($charges as $id => $charge) {
                $journal = $this->ownJournal($charge->reference, $charge->journal_ref, $journals, Ledger::SOURCE_CHARGE, $id, (int) ($chargesSharing[$charge->journal_ref] ?? 0), isset($marked['charges'][$id]) || self::inSample($spans, 'charges', $id));

                if ($journal === null) {
                    unset($charges[$id]);

                    continue;
                }

                if ($charge->status === 'reversed' || isset($reversed[(int) $journal->id])) {
                    $this->skipped[] = $charge->reference;
                    unset($charges[$id]);

                    continue;
                }

                $entries[(int) $journal->id] = ['journal' => $journal, 'what' => $charge->reference];
            }

            foreach ($payments as $id => $payment) {
                if ($payment->method === Payment::CARD && ! $payment->is_demonstration) {
                    $this->refusals[] = $payment->receipt_no.' is a card payment taken through the gateway. It is refunded through the gateway, not reversed here.';
                    unset($payments[$id]);

                    continue;
                }

                $journal = $this->ownJournal($payment->receipt_no, $payment->journal_ref, $journals, Ledger::SOURCE_PAYMENT, $id, (int) ($paymentsSharing[$payment->journal_ref] ?? 0), isset($marked['payments'][$id]) || self::inSample($spans, 'payments', $id));

                if ($journal === null) {
                    unset($payments[$id]);

                    continue;
                }

                if ($payment->status !== 'recorded' || isset($reversed[(int) $journal->id])) {
                    $this->skipped[] = $payment->receipt_no;
                    unset($payments[$id]);

                    continue;
                }

                $entries[(int) $journal->id] = ['journal' => $journal, 'what' => $payment->receipt_no];
            }

            foreach ($manual as $id => $journal) {
                $refusal = match (true) {
                    isset($marked['journals'][$id]) || self::inSample($spans, 'journals', $id) => sprintf(self::SAMPLE, $journal->reference),
                    $journal->reverses_journal_id !== null => $journal->reference.' is itself a reversal. Reversing it would post again what was corrected.',
                    $journal->source !== Ledger::SOURCE_MANUAL => $journal->reference.' was posted for a '.str_replace('_', ' ', $journal->source).', not by hand. Name the '.str_replace('_', ' ', $journal->source).' itself (--charge= or --receipt=), so its status follows the reversal.',
                    default => null,
                };

                if ($refusal !== null) {
                    $this->refusals[] = $refusal;
                    unset($manual[$id]);

                    continue;
                }

                if (isset($reversed[$id])) {
                    $this->skipped[] = $journal->reference;
                    unset($manual[$id]);

                    continue;
                }

                $entries[$id] = ['journal' => $journal, 'what' => $journal->reference];
            }

            ksort($entries);

            $byRef = [];

            foreach ($entries as $entry) {
                $byRef[$entry['journal']->reference] = $entry['journal'];
            }

            return ['charges' => $charges, 'payments' => $payments, 'manual' => $manual, 'bulk' => $bulk, 'entries' => $entries, 'byRef' => $byRef];
        });
    }

    /**
     * The journal a charge or payment posted for itself alone — or null, with the refusal, when it did not.
     *
     * @param  Collection<array-key, mixed>  $journals  keyed by reference
     */
    private function ownJournal(string $what, ?string $journalRef, Collection $journals, string $source, int $id, int $sharing, bool $sample): ?Journal
    {
        if ($sample) {
            $this->refusals[] = sprintf(self::SAMPLE, $what);

            return null;
        }

        $journal = $journalRef === null ? null : ($journals[$journalRef] ?? null);

        if (! $journal instanceof Journal) {
            $this->refusals[] = $what.': the entry it posted ('.($journalRef ?? 'none recorded').') cannot be found, so it cannot be reversed from here.';

            return null;
        }

        if ($sharing > 1 || $journal->source !== $source || (int) $journal->source_id !== $id) {
            $others = max(0, $sharing - 1);
            $this->refusals[] = $what.' was posted in a run, in one entry ('.$journal->reference.') '.($others > 0 ? 'with '.$others.' other'.($others === 1 ? '' : 's') : 'it does not own').'. A run is reversed whole, from the Dues page.';

            return null;
        }

        return $journal;
    }

    /**
     * Every span of ids the demonstration's seed recorded for this estate's tables, from `sample_data_ranges`.
     *
     * @return array<string, list<array{0: int, 1: int}>>
     */
    private function sampleSpans(string $key): array
    {
        $spans = [];

        foreach (DB::connection('mysql')->table('sample_data_ranges')->where('tenant_id', $key)->where('connection', 'tenant')->get(['table_name', 'from_id', 'to_id']) as $range) {
            $spans[(string) $range->table_name][] = [(int) $range->from_id, (int) $range->to_id];
        }

        return $spans;
    }

    /** @param  array<string, list<array{0: int, 1: int}>>  $spans */
    private static function inSample(array $spans, string $table, int $id): bool
    {
        foreach ($spans[$table] ?? [] as [$from, $to]) {
            if ($id > $from && $id <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which of these rows carry the seeder's mark — asked by name: a `*` select never returns the mark (M33 §5).
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private static function marked(string $table, array $ids): array
    {
        return array_fill_keys(self::chunked($ids, static fn (array $chunk): Collection => DB::connection('tenant')->table($table)
            ->whereIn('id', $chunk)->whereNotNull('seeded_at')->pluck('id'))->map(intval(...))->all(), true);
    }

    /**
     * `$read` over `$values` five hundred at a time — an IN list of 400 lots stays one statement, of 2,000 four.
     *
     * @param  list<int|string>  $values
     * @param  callable(list<int|string>): Collection<int, mixed>  $read
     * @return Collection<int, mixed>
     */
    private static function chunked(array $values, callable $read): Collection
    {
        $out = collect();

        foreach (array_chunk(array_values(array_unique($values)), 500) as $chunk) {
            $out = $out->merge($read($chunk));
        }

        return $out->values();
    }

    /**
     * Everything the dry run prints — and --confirm before it acts: each entry and the day its mirror is dated, then
     * what the books would read after.
     *
     * @param  array{charges: array<int, Charge>, payments: array<int, Payment>, manual: array<int, Journal>, bulk: array<string, list<int>>, entries: array<int, array{journal: Journal, what: string}>, byRef: array<string, Journal>}  $plan
     */
    private function report(array $plan, string $reason, Dues $dues): void
    {
        $units = Unit::query()->whereIn('id', array_values(array_unique([
            ...array_map(static fn (Charge $c): int => (int) $c->unit_id, $plan['charges']),
            ...array_map(static fn (Payment $p): int => (int) $p->unit_id, $plan['payments']),
        ])))->get(['id', 'reference', 'block'])->keyBy('id');
        $accounts = Account::query()->get(['id', 'code', 'name', 'type'])->keyBy('id');
        $lot = static fn (int $unitId): string => (string) ($units[$unitId]->reference ?? 'unit '.$unitId);
        $dated = static fn (?string $reference): string => $reference !== null && isset($plan['byRef'][$reference]) ? $plan['byRef'][$reference]->posted_on->format('j M Y') : '—';

        $this->newLine();
        $this->line('Reason on every reversal: '.($reason === '' ? '(none given yet — --confirm needs --reason="…")' : '"'.$reason.'"'));
        $this->line('Each reversal is dated the day its entry was posted, so every month reads as if the entry had never been made.');

        $inBulk = [];

        foreach ($plan['bulk'] as $named => $ids) {
            $members = array_values(array_filter(array_map(static fn (int $id): ?Charge => $plan['charges'][$id] ?? null, $ids)));
            $inBulk = [...$inBulk, ...$ids];

            if ($members === []) {
                continue;
            }

            $first = $members[0];
            $phases = array_count_values(array_map(static fn (Charge $c): string => (string) ($units[(int) $c->unit_id]->block ?? '—'), $members));
            ksort($phases);

            $this->newLine();
            $this->line(sprintf('THE BULK POST THAT RAISED %s: %d charge%s, %s', $named, count($members), count($members) === 1 ? '' : 's', MoneyFormatter::fromMinor(array_sum(array_map(static fn (Charge $c): int => (int) $c->amount_minor, $members)))));
            $this->line(sprintf(
                '  %s · "%s" · period %s · due %s · credits %s · posted %s by %s',
                str_replace('_', ' ', (string) $first->type),
                (string) $first->description,
                Carbon::createFromFormat('Y-m-d', $first->period.'-01')->format('F Y'),
                $first->due_on->format('j M Y'),
                self::accountLabel($accounts, (int) $first->account_id),
                $first->created_at === null ? 'at a time not recorded' : SiteTime::local($first->created_at)->format('j M Y, g:i A'),
                (string) ($first->posted_by_name ?? 'nobody recorded'),
            ));
            $this->line('  Lots: '.implode(', ', array_map(static fn (string $phase, int $n): string => $n.' in '.$phase, array_keys($phases), $phases)));
            $this->table(['Charge', 'Lot', 'Amount', 'Status', 'Its entry', 'Reversal dated'], array_map(static fn (Charge $c): array => [
                $c->reference, $lot((int) $c->unit_id), MoneyFormatter::fromMinor((int) $c->amount_minor), $c->status, (string) $c->journal_ref, $dated($c->journal_ref),
            ], $members));
        }

        $alone = array_filter($plan['charges'], static fn (Charge $c): bool => ! in_array((int) $c->id, $inBulk, true));

        if ($alone !== []) {
            $this->newLine();
            $this->line(sprintf('CHARGES, ONE BY ONE: %d, %s', count($alone), MoneyFormatter::fromMinor(array_sum(array_map(static fn (Charge $c): int => (int) $c->amount_minor, $alone)))));
            $this->table(['Charge', 'Lot', 'Kind', 'Description', 'Amount', 'Credits', 'Its entry', 'Reversal dated', 'Posted by'], array_map(static fn (Charge $c): array => [
                $c->reference, $lot((int) $c->unit_id), str_replace('_', ' ', (string) $c->type), (string) $c->description, MoneyFormatter::fromMinor((int) $c->amount_minor),
                self::accountLabel($accounts, (int) $c->account_id), (string) $c->journal_ref, $dated($c->journal_ref), (string) ($c->posted_by_name ?? '—'),
            ], array_values($alone)));

            foreach (AmenityBooking::query()->whereIn('fee_charge_id', array_keys($alone))->get(['reference', 'fee_charge_id']) as $booking) {
                $this->line('  '.$plan['charges'][(int) $booking->fee_charge_id]->reference.' is the fee of booking '.$booking->reference.'. The booking stays as it is; its fee then reads as owing nothing.');
            }
        }

        if ($plan['payments'] !== []) {
            $debited = DB::connection('tenant')->table('journal_lines')
                ->whereIn('entry_ref', array_values(array_filter(array_map(static fn (Payment $p): ?string => $p->journal_ref, $plan['payments']))))
                ->where('debit_minor', '>', 0)->pluck('account_id', 'entry_ref');

            $this->newLine();
            $this->line(sprintf('PAYMENTS: %d, %s', count($plan['payments']), MoneyFormatter::fromMinor(array_sum(array_map(static fn (Payment $p): int => (int) $p->amount_minor, $plan['payments'])))));
            $this->table(['Receipt', 'Lot', 'Amount', 'Method', 'Received', 'Into', 'Its entry', 'Reversal dated', 'Entered by'], array_map(static fn (Payment $p): array => [
                $p->receipt_no, $lot((int) $p->unit_id), MoneyFormatter::fromMinor((int) $p->amount_minor), $p->method.($p->is_demonstration ? ' (demonstration)' : ''),
                Payment::calendarDay($p->received_at)->format('j M Y'), isset($debited[$p->journal_ref]) ? self::accountLabel($accounts, (int) $debited[$p->journal_ref]) : '—',
                (string) $p->journal_ref, $dated($p->journal_ref), (string) ($p->received_by_name ?? '—'),
            ], array_values($plan['payments'])));
        }

        if ($plan['manual'] !== []) {
            $this->newLine();
            $this->line('HAND-POSTED JOURNALS: '.count($plan['manual']));

            foreach ($plan['manual'] as $journal) {
                $lines = $journal->lines()->get()->map(static fn ($l): string => ($l->debit_minor > 0 ? 'Dr ' : 'Cr ').self::accountLabel($accounts, (int) $l->account_id).' '.MoneyFormatter::fromMinor((int) max($l->debit_minor, $l->credit_minor)))->implode(' / ');
                $this->line('  '.$journal->reference.' · "'.$journal->memo.'" · dated '.$journal->posted_on->format('j M Y').' · '.$lines.' · reversal dated '.$journal->posted_on->format('j M Y'));
            }
        }

        foreach ($this->skipped as $what) {
            $this->line('  '.$what.' is reversed already; it is left as it is.');
        }

        if ($plan['entries'] !== []) {
            $this->effect($plan, $accounts, $dues);
        }
    }

    /**
     * What the books read now, and after: each account the entries touch, each month of the Profit & Loss they move,
     * and the Dues page's total. Arithmetic on the named entries' own lines — a mirror takes back exactly those — over
     * balances the ledger itself gives.
     *
     * @param  array{entries: array<int, array{journal: Journal, what: string}>}  $plan
     * @param  Collection<int, Account>  $accounts
     */
    private function effect(array $plan, Collection $accounts, Dues $dues): void
    {
        $lines = self::chunked(array_values(array_map(static fn (array $e): string => $e['journal']->reference, $plan['entries'])), static fn (array $chunk): Collection => DB::connection('tenant')->table('journal_lines')
            ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
            ->whereIn('journal_lines.entry_ref', $chunk)
            ->get(['journal_lines.account_id', 'journal_lines.unit_id', 'journal_lines.debit_minor', 'journal_lines.credit_minor', 'journals.posted_on']));

        $byAccount = [];
        $byMonth = [];
        $byUnit = [];

        foreach ($lines as $line) {
            $net = (int) $line->debit_minor - (int) $line->credit_minor;
            $account = $accounts[(int) $line->account_id] ?? null;
            $byAccount[(int) $line->account_id] = ($byAccount[(int) $line->account_id] ?? 0) + $net;

            if ($account !== null && in_array($account->type, [Account::INCOME, Account::EXPENSE], true)) {
                $byMonth[Carbon::parse((string) $line->posted_on)->format('Y-m')][(int) $line->account_id] = ($byMonth[Carbon::parse((string) $line->posted_on)->format('Y-m')][(int) $line->account_id] ?? 0) + $net;
            }

            if ($account !== null && $account->code === '1200' && $line->unit_id !== null) {
                $byUnit[(int) $line->unit_id] = ($byUnit[(int) $line->unit_id] ?? 0) + $net;
            }
        }

        // In the chart's own order, by code.
        uksort($byAccount, static fn (int $a, int $b): int => strcmp((string) ($accounts[$a]->code ?? $a), (string) ($accounts[$b]->code ?? $b)));
        $balances = DB::connection('tenant')->table('journal_lines')->whereIn('account_id', array_keys($byAccount))
            ->groupBy('account_id')->selectRaw('account_id, SUM(debit_minor - credit_minor) AS net')->pluck('net', 'account_id');

        // Each account on its own normal side: a debit balance for an asset or an expense, a credit one for the rest.
        $side = static fn (int $id): int => in_array($accounts[$id]->type ?? Account::ASSET, [Account::ASSET, Account::EXPENSE], true) ? 1 : -1;
        $signed = static fn (int $minor): string => ($minor < 0 ? '-' : '+').MoneyFormatter::fromMinor(abs($minor));

        $this->newLine();
        $this->line('WHAT THE ACCOUNTS WOULD READ (each on its own side: what the estate has or is owed, or what it took in)');
        $this->table(['Account', 'Now', 'Change', 'After'], array_map(static function (int $id, int $net) use ($accounts, $balances, $side, $signed): array {
            $now = (int) ($balances[$id] ?? 0);

            return [self::accountLabel($accounts, $id), MoneyFormatter::fromMinor($side($id) * $now), $signed($side($id) * -$net), MoneyFormatter::fromMinor($side($id) * ($now - $net))];
        }, array_keys($byAccount), $byAccount));

        if ($byMonth !== []) {
            ksort($byMonth);
            $rows = [];

            foreach ($byMonth as $month => $byAcc) {
                $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
                $now = DB::connection('tenant')->table('journal_lines')
                    ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
                    ->whereIn('journal_lines.account_id', array_keys($byAcc))
                    ->whereBetween('journals.posted_on', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
                    ->groupBy('journal_lines.account_id')->selectRaw('journal_lines.account_id AS account_id, SUM(journal_lines.debit_minor - journal_lines.credit_minor) AS net')
                    ->pluck('net', 'account_id');

                uksort($byAcc, static fn (int $a, int $b): int => strcmp((string) ($accounts[$a]->code ?? $a), (string) ($accounts[$b]->code ?? $b)));

                foreach ($byAcc as $id => $net) {
                    $was = (int) ($now[$id] ?? 0);
                    $rows[] = [$start->format('F Y'), self::accountLabel($accounts, $id), MoneyFormatter::fromMinor($side($id) * $was), MoneyFormatter::fromMinor($side($id) * ($was - $net))];
                }
            }

            $this->newLine();
            $this->line('THE PROFIT & LOSS, BY THE MONTH EACH ENTRY IS DATED');
            $this->table(['Month', 'Account', 'Now', 'After'], $rows);
        }

        $all = $dues->unitBalances();
        $after = $all;

        foreach ($byUnit as $unitId => $net) {
            $after[$unitId] = ($after[$unitId] ?? 0) - $net;
        }

        $owing = static fn (array $balances): array => array_filter($balances, static fn (int $b): bool => $b > 0);

        $this->newLine();
        $this->line(sprintf(
            'THE DUES PAGE\'S TOTAL OUTSTANDING: %s on %d lots now; %s on %d lots after.',
            MoneyFormatter::fromMinor(array_sum($owing($all))), count($owing($all)), MoneyFormatter::fromMinor(array_sum($owing($after))), count($owing($after)),
        ));

        $this->newLine();
        $this->line('NOT TOUCHED: no row is deleted and no posted entry is edited. Each charge and payment named keeps every field but its status, which reads reversed. No household, resident, lot, booking or other estate is touched.');
    }

    /**
     * Every reversal, in one transaction: the mirrors, then the statuses. Returns the mirrors' references.
     *
     * @param  array{charges: array<int, Charge>, payments: array<int, Payment>, entries: array<int, array{journal: Journal, what: string}>}  $plan
     * @return list<string>
     */
    private function reverseAll(array $plan, string $reason, Ledger $ledger): array
    {
        return DB::connection('tenant')->transaction(function () use ($plan, $reason, $ledger): array {
            $posted = [];

            foreach ($plan['entries'] as $entry) {
                $journal = $entry['journal'];
                // "Reversal of … (…): why", as every other reversal writes it; the statement reads the reason after the colon.
                $posted[] = $ledger->reverse($journal, 'Reversal of '.$journal->reference.' ('.$entry['what'].'): '.$reason, null, $journal->posted_on)->reference;
            }

            $now = Carbon::now();

            foreach (array_chunk(array_keys($plan['charges']), 500) as $ids) {
                DB::connection('tenant')->table('charges')->whereIn('id', $ids)->update(['status' => 'reversed', 'updated_at' => $now]);
            }

            foreach (array_chunk(array_keys($plan['payments']), 500) as $ids) {
                DB::connection('tenant')->table('payments')->whereIn('id', $ids)->update(['status' => 'reversed', 'updated_at' => $now]);
            }

            return $posted;
        });
    }

    /** @return array{0: int, 1: int} the whole ledger's debits and credits */
    private function trial(): array
    {
        $row = DB::connection('tenant')->table('journal_lines')->selectRaw('COALESCE(SUM(debit_minor), 0) AS dr, COALESCE(SUM(credit_minor), 0) AS cr')->first();

        return [(int) ($row->dr ?? 0), (int) ($row->cr ?? 0)];
    }

    /** @param  Collection<int, Account>  $accounts */
    private static function accountLabel(Collection $accounts, int $id): string
    {
        $account = $accounts[$id] ?? null;

        return $account === null ? 'account '.$id : $account->code.' '.$account->name;
    }

    /** @param  list<string>  $references */
    private static function range(array $references): string
    {
        if (count($references) <= 3) {
            return implode(', ', $references);
        }

        $sorted = $references;
        sort($sorted);

        return $sorted[0].' … '.$sorted[count($sorted) - 1];
    }

    /**
     * "A,B" and repeated flags alike, trimmed, each once, in the order given.
     *
     * @return list<string>
     */
    private static function listOf(mixed $values): array
    {
        $out = [];

        foreach ((array) $values as $value) {
            foreach (explode(',', (string) $value) as $one) {
                $one = trim($one);

                if ($one !== '' && ! in_array($one, $out, true)) {
                    $out[] = $one;
                }
            }
        }

        return $out;
    }
}
