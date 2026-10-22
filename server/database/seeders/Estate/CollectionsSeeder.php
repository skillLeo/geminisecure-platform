<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\Unit;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The dunning ladder, the send log and one live payment plan — boards 7 and 8.
 *
 * Runs INSIDE tenancy, after `EstateFinanceSeeder` has billed the estate,
 * because every figure on both boards is read from the ledger it raised.
 *
 * IDEMPOTENT, AND ASYMMETRICALLY SO. Templates are keyed and re-seeded, but
 * their SUBJECT AND BODY are written once and never rewritten: board 8 lets a
 * treasurer edit the wording, and a re-seed that silently restored the shipped
 * text would undo their work with no record that it had. The send log and the
 * payment plan are written only into an estate that has none, because a second
 * run would otherwise stack duplicate history on top of real history.
 *
 * NOTHING HERE STORES A BALANCE. Board 8 draws a Balance column and it is summed
 * from the journal when the screen is drawn, so the log and the arrears board
 * beside it cannot disagree. What the resident was actually quoted is preserved
 * where it belongs — rendered into the notice body, frozen at the moment of
 * sending.
 */
class CollectionsSeeder extends Seeder
{
    /**
     * Board 8's three template tabs: "Day 20", "Reminder 1", "Reminder 4+".
     *
     * [key, label, stage, channel, days_overdue, subject, body]
     *
     * The Reminder 1 body is the board's, verbatim, down to the merge tokens.
     * The other two are not drawn anywhere and are written here in the same
     * voice, using only the five tokens board 8 catalogues — a template that
     * reached for a sixth would render a hole in a sentence on a resident's
     * phone.
     *
     * @var list<array{0: string, 1: string, 2: int, 3: string, 4: int, 5: string, 6: string}>
     */
    private const TEMPLATES = [
        [
            'day20',
            'Day 20',
            0,
            'push+email',

            // Zero, because this one fires BEFORE anything is overdue. It is
            // the estate's pre-due courtesy, not a step on the ladder.
            0,
            'Your maintenance fee for {unit_label} is due on {due_date}',
            'Hi {resident_first_name}, the account for {unit_label} stands at {amount_due} and the next '.
                'maintenance fee falls due on {due_date}. Nothing is overdue — this is a reminder so the '.
                'payment does not catch you out.',
        ],
        [
            'reminder1',
            'Reminder 1',
            1,
            'push+email',
            30,
            'Maintenance fee overdue — {unit_label}',
            'Hi {resident_first_name}, your maintenance fee of {amount_due} for {unit_label} is now '.
                'overdue. Please settle it at your earliest convenience to avoid further reminders.',
        ],
        [
            'reminder4plus',
            'Reminder 4+',
            4,

            /*
             * SMS is added at the top of the ladder, which is why the channel
             * is a property of the step and not of the estate. Board 8 shows it
             * outright: Push + Email at reminders 1 and 2, Push + Email + SMS at
             * 4 and 5.
             */
            'push+email+sms',
            90,
            'Final notice — {unit_label} is {days_overdue} days overdue',
            'Hi {resident_first_name}, {unit_label} is {days_overdue} days overdue with {amount_due} '.
                'outstanding. Please contact the office to settle the account or to agree a payment plan. '.
                'Guest access may be restricted while the account remains unpaid.',
        ],
    ];

    /**
     * Board 8's five log rows, in the order it draws them.
     *
     * [lot, step label, stage, template key, days ago, delivery state, detail]
     *
     * THE STEP LABEL IS NOT THE TEMPLATE LABEL, and the log's denormalised
     * column is exactly for this. Board 8 records "Reminder 2" and "Reminder 5"
     * as steps while grouping their wording under the "Reminder 1" and
     * "Reminder 4+" tabs, so the row has to be able to name the step it was
     * without asking a template that never carried that name.
     *
     * @var list<array{0: string, 1: string, 2: int, 3: string, 4: int, 5: string, 6: string|null}>
     */
    private const LOG = [
        ['Lot 47', 'Reminder 1', 1, 'reminder1', 0, DunningNotice::DELIVERED, null],
        ['Lot 31', 'Reminder 4', 4, 'reminder4plus', 0, DunningNotice::DELIVERED, null],
        ['Lot 9', 'Reminder 2', 2, 'reminder1', 1, DunningNotice::DELIVERED, null],

        // A per-channel failure. The push and the email landed; the SMS did
        // not, which is why the log records a detail rather than just "failed".
        ['Lot 21', 'Reminder 5', 5, 'reminder4plus', 0, DunningNotice::FAILED, 'SMS failed'],

        /*
         * Board 8 dates this one "Sep 20, 9:00 AM". It is seeded three weeks
         * back instead of on that date, because the board was drawn against a
         * calendar the estate has since passed — a delivery log with a send in
         * the future is the one row nobody could explain.
         */
        ['Lot 63', 'Advance notice', 0, 'day20', 21, DunningNotice::DELIVERED, null],
    ];

    /** The daily dunning batch runs at 09:00 — the repeated time on board 8. */
    private const BATCH_HOUR = 9;

    public function run(): void
    {
        $templates = $this->seedTemplates();

        $this->seedLog($templates);
        $this->seedPaymentPlan();
    }

    /**
     * @return array<string, DunningTemplate> keyed by template key
     */
    private function seedTemplates(): array
    {
        $out = [];

        foreach (self::TEMPLATES as [$key, $label, $stage, $channel, $daysOverdue, $subject, $body]) {
            $template = DunningTemplate::firstOrNew(['key' => $key]);

            // The structure of a step is the estate's to define and safe to
            // re-seed; its WORDING belongs to whoever last edited board 8.
            $template->fill([
                'label' => $label,
                'stage' => $stage,
                'channel' => $channel,
                'days_overdue' => $daysOverdue,
                'is_active' => true,
            ]);

            if (! $template->exists) {
                $template->subject = $subject;
                $template->body = $body;
            }

            $template->save();

            $out[$key] = $template;
        }

        return $out;
    }

    /**
     * @param  array<string, DunningTemplate>  $templates
     */
    private function seedLog(array $templates): void
    {
        // Only into an estate with no log at all. Re-running against one that
        // has history would stack a second week of reminders on top of a real
        // week, and a dunning log is the one record that must not be padded.
        if (DunningNotice::query()->exists()) {
            return;
        }

        $collections = app(Collections::class);
        $batch = Carbon::today()->setTime(self::BATCH_HOUR, 0);

        foreach (self::LOG as [$reference, $step, $stage, $templateKey, $daysAgo, $state, $detail]) {
            $unit = Unit::where('reference', $reference)->first();

            // A minimal estate will not have every lot the boards name. Skip
            // rather than fail: this seeder describes a log, not a schema.
            if ($unit === null || ! isset($templates[$templateKey])) {
                continue;
            }

            /*
             * Sent through the service, not written by hand, so the seeded
             * bodies are produced by the same renderer the console uses. A
             * fixture that hand-wrote its own copy of the merge output would
             * prove nothing about the path a real notice takes.
             */
            $notice = $collections->send($unit, $templates[$templateKey]);

            $notice->forceFill([
                'template_label' => $step,
                'stage' => $stage,
                'delivery_state' => $state,
                'delivery_detail' => $detail,
                'sent_at' => $batch->copy()->subDays($daysAgo),
            ])->save();
        }
    }

    /**
     * Lot 47's plan — board 7, figure for figure.
     *
     * J$12,400.00 over four monthly instalments of J$3,100.00, agreed by Andrea
     * Fletcher. The total is not written here: it is whatever the ledger says
     * Lot 47 owes, which is the same J$12,400.00 boards 5 and 6 draw, and if it
     * ever stops being that the plan should follow the ledger rather than the
     * mock.
     */
    private function seedPaymentPlan(): void
    {
        $unit = Unit::where('reference', 'Lot 47')->first();

        if ($unit === null || PaymentPlan::where('unit_id', $unit->id)->exists()) {
            return;
        }

        if (app(Dues::class)->balanceOf($unit)->getMinorAmount()->toInt() <= 0) {
            return;
        }

        $collections = app(Collections::class);

        $plan = $collections->draft(
            unit: $unit,
            instalments: Collections::DEFAULT_INSTALMENTS,
            terms: 'Four monthly instalments against the balance outstanding at agreement. Automated '.
                'reminders are paused while the plan is met; a missed instalment resumes them and '.
                'reinstates the arrears restriction on guest passes.',
        );

        $collections->agree($plan, 'Andrea Fletcher');

        /*
         * Activated here rather than through `Collections::activate`, which
         * requires a `User` — the person who approved it. A seeder has no
         * signed-in approver and must not invent one, so the treasurer is
         * recorded by NAME with `approved_by` left null, which is exactly what
         * the nullable pair of columns is for: an act the estate can attribute
         * without pretending to know a platform account.
         */
        $plan->forceFill([
            'status' => PaymentPlan::ACTIVE,
            'approved_by_name' => 'Phoenix Park Treasurer',
        ])->save();
    }
}
