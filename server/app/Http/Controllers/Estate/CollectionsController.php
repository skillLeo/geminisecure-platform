<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\DunningTemplateDraft;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\PaymentPlanInstalment;
use App\Models\Estate\Unit;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\Estate\ReminderDelivery;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Payment plans and dunning — board screens community-admin-07 and 08.
 *
 * THREE PERMISSION LEVELS, and the split is the point.
 *
 *   view      reading a household's plan and the dunning log. A committee
 *             member may see how the estate is chasing its arrears.
 *   create    drawing a plan up, recording that a household agreed, withdrawing
 *             a draft, sending a notice. Real writes — but none of them changes
 *             what happens at a gate.
 *   approve   ACTIVATING A PLAN, AND RULING ITS INSTALMENTS MET OR MISSED (bug
 *             hunt B019). Activating lifts an access restriction on a household
 *             in arrears; a missed instalment puts it back, and the last one met
 *             can lift it for good. Each is a decision about who gets through the
 *             gate, not data entry. `approve` is the verb this system reserves
 *             for acts that cannot be walked back by editing the row afterwards
 *             (D-013), and a guest admitted last night is exactly that.
 *
 *             Since M11 B3 each approval on Dues & ledger is its own permission
 *             (D-150). Activating and ruling are ONE of them, `activate_plans`:
 *             both decide whether a household's plan shields it at the gate, and
 *             a role that may put a plan in force is the role that says whether
 *             it is being kept. Putting a reminder wording in force is
 *             `adopt_dunning`, and neither is the power to set a rate.
 *
 * NOTHING HERE POSTS A JOURNAL ENTRY. See `Collections`.
 */
class CollectionsController extends Controller
{
    /** Place on payment plan — board community-admin-07. */
    public function plan(Request $request, Unit $unit, Collections $collections): Response
    {
        return inertia('Estate/Dues/PaymentPlan', [
            'estate' => ['name' => (string) tenant()->name],
            // The count and the first date the form holds, so the preview is the schedule for what was typed.
            ...$collections->planBoard(
                $unit,
                $request->filled('instalments') && ctype_digit((string) $request->query('instalments')) ? (int) $request->query('instalments') : null,
                $request->filled('starts_on') ? (string) $request->query('starts_on') : null,
            ),
            'notices' => $collections->noticesFor($unit),
            'canCreate' => $request->user()->can('estate.dues_ledger.create'),

            // Activating a plan and ruling its instalments are this screen's
            // approvals, and one permission of their own (M11 B3): not the power
            // to set rates or reverse a month.
            'canApprove' => $request->user()->can('estate.dues_ledger.activate_plans'),

            /*
             * Board 7's sub-copy in plain words ("reminders", where it said "dunning"), and a promise the code keeps:
             * `Collections::isProtected` is what the automated ladder and the
             * gate both read, so an active plan silences dunning and lifts the
             * restriction from the same fact.
             */
            'note' => 'This pauses all automated reminders for this unit while the plan is active.',
            'approveReason' => 'Activating a plan lifts the arrears restriction on this household\'s guest '.
                'passes. That is an approval, not data entry, and it needs the Activate and rule payment plans permission on Dues & ledger.',

            // The rulings (bug hunt B019) are approvals for the same reason
            // activating is, and the same permission (M11 B3).
            'ruleReason' => 'Ruling an instalment met or missed decides whether this household\'s plan still shields it '.
                'at the gate, so it is an approval and needs the Activate and rule payment plans permission on Dues & ledger.',
        ]);
    }

    /** Draw the schedule up. A draft, and nothing at a gate has changed yet. */
    public function generate(Request $request, Unit $unit, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'instalments' => ['required', 'integer', 'min:1', 'max:36'],
            'starts_on' => ['required', 'date'],
            'terms' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $plan = $collections->draft(
                unit: $unit,
                instalments: (int) $data['instalments'],
                startsOn: $data['starts_on'],
                terms: $data['terms'] ?? null,
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['instalments' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->planPath($unit))
            ->with('success', 'Plan '.$plan->reference.' drafted. It does nothing until the household agrees to it.');
    }

    /** Record that the household agreed, and who said so. */
    public function agree(Request $request, PaymentPlan $plan, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'agreed_by_name' => ['required', 'string', 'max:160'],
        ]);

        try {
            $collections->agree($plan, $data['agreed_by_name']);
        } catch (DomainException $refused) {
            return back()->withErrors(['agreed_by_name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->planPath($plan->unit))
            ->with('success', 'Agreement recorded for '.$plan->reference.'.');
    }

    /**
     * Activate it — and lift the restriction.
     *
     * Behind `activate_plans`, with the two instalment rulings below; the
     * controller's only other approval is `adopt_dunning`. A refusal here is a
     * refusal with a reason attached: the household has not agreed, or agreed
     * to something other than what is being activated, and the message says
     * which rather than failing the form silently.
     */
    public function activate(Request $request, PaymentPlan $plan, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'agreed_by_name' => ['required', 'string', 'max:160'],
        ]);

        try {
            $collections->activate($plan, $data['agreed_by_name'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['agreed_by_name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->planPath($plan->unit))
            ->with('success', 'Plan '.$plan->reference.' is active. Dunning is paused and the arrears '.
                'restriction is lifted until an instalment is ruled missed.');
    }

    /**
     * Withdraw a draft (bug hunt B019) — the office's `create`, like drawing it
     * up, because a draft lifts nothing and binds nobody.
     */
    public function withdraw(Request $request, PaymentPlan $plan, Collections $collections): RedirectResponse
    {
        try {
            $collections->withdraw($plan, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['plan' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->planPath($plan->unit))
            ->with('success', 'Plan '.$plan->reference.' is withdrawn. It lifted nothing and bound nobody; it stays on '.
                $plan->unit->reference.'\'s record as withdrawn, and a new plan can be drawn up.');
    }

    /**
     * Rule an instalment met — board 7, behind `activate_plans` (bug hunt B019,
     * M11 B3).
     *
     * The money itself is recorded on the ledger as a receipt; this is the
     * ruling that the instalment is satisfied, which is what ends a plan well.
     */
    public function instalmentMet(Request $request, PaymentPlanInstalment $instalment, Collections $collections): RedirectResponse
    {
        try {
            $collections->recordInstalmentMet($instalment, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['instalment' => $refused->getMessage()]);
        }

        $plan = $instalment->plan;
        $message = 'Instalment '.$instalment->sequence.' of '.$plan->reference.' is ruled met.';

        /*
         * SAID AS IT IS. Completing a plan clears the arrears restriction only
         * when the ledger is clear too (see `recordInstalmentMet()`), so the
         * sentence reads the household as it now stands.
         */
        if ($plan->status === PaymentPlan::COMPLETED) {
            $message .= $plan->unit->household?->access_restricted === true
                ? ' Every instalment is met, so the plan is complete. The ledger still shows a balance, so the arrears '.
                    'restriction on guest passes applies again until it is cleared.'
                : ' Every instalment is met, so the plan is complete, and nothing restricts this household\'s guest passes.';
        }

        return redirect()->to($this->planPath($plan->unit))->with('success', $message);
    }

    /**
     * Rule an instalment missed — board 7, behind `activate_plans` (bug hunt
     * B019, M11 B3).
     *
     * This defaults the plan, which takes its shield off at the gate and puts
     * the household back on the morning ladder; the flash says which of those
     * reach this household.
     */
    public function instalmentMissed(Request $request, PaymentPlanInstalment $instalment, Collections $collections): RedirectResponse
    {
        try {
            $collections->markMissed($instalment, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['instalment' => $refused->getMessage()]);
        }

        $plan = $instalment->plan;

        return redirect()->to($this->planPath($plan->unit))->with('success', sprintf(
            'Instalment %d of %s is ruled missed, and the plan has defaulted. %s the morning reminder run no longer passes it over for a plan.',
            $instalment->sequence,
            $plan->reference,
            $plan->unit->household?->access_restricted === true
                ? 'This household\'s guest passes are restricted again at the gate, and'
                : 'Nothing changes at the gate — this household is not restricted for arrears — and',
        ));
    }

    /** Dunning & reminders — board community-admin-08. */
    /**
     * Every payment plan in the estate — board 5's "Payment plans" tab (12 §2,
     * item 18). A read; each row opens that household's own plan.
     */
    public function plans(Request $request, Collections $collections): Response
    {
        return inertia('Estate/Dues/PaymentPlans', [
            'estate' => ['name' => (string) tenant()->name],

            // Every plan's total together is the estate's position (M10 §5);
            // the ledger alone does not grant it. See `Collections::planRegister()`.
            ...$collections->planRegister(
                $request->string('status')->toString(),
                Dues::maySeeEstatePosition($request->user()),
                // Sorted and paged over the whole register (M32 F5).
                Collections::plansTable($request),
            ),
        ]);
    }

    public function dunning(Request $request, Collections $collections): Response
    {
        /*
         * ONE HOUSEHOLD, WHEN THE LEDGER SENT THE OFFICE HERE (bug hunt B111). The
         * ledger's "Send reminder now" opens this screen with `?unit=`, and the
         * panel it gets is where that household's reminder is chosen, read and
         * sent. `integer()` answers 0 for anything that is not one, so a hostile
         * query string is simply no household.
         */
        $unitId = $request->integer('unit');
        $sendUnit = $unitId > 0 ? Unit::query()->with('household')->find($unitId) : null;

        return inertia('Estate/Dues/Dunning', [
            'estate' => ['name' => (string) tenant()->name],
            'sendTo' => $sendUnit === null ? null : $collections->sendPanel($sendUnit),

            // The same question for the log's balance column (M10 §5); the whole log, a page at a time (M32 F5).
            ...$collections->dunningBoard(Collections::sendsTable($request), maySeePosition: Dues::maySeeEstatePosition($request->user())),
            'canSend' => $request->user()->can('estate.dues_ledger.create'),

            /*
             * TWO DIFFERENT GATES (12 §1). Wording a step is `create` — the
             * office proposes. Putting one in force is an approval,
             * `adopt_dunning`, because the committee decides what a household is
             * told about its debt, and the resolution reference is how the
             * notice ties back to the minute that agreed it.
             */
            'canAdopt' => $request->user()->can('estate.dues_ledger.adopt_dunning'),
            'adoptBlockedReason' => 'Putting a wording in force is the committee\'s act, so it needs the Put reminder wording in force permission on Dues & ledger. You are able to word a draft for them.',

            /*
             * REMINDER DELIVERY (M84 §3.5), behind its switch: absent while it is off. What a delivery run would send now,
             * to whom and by which channel, and how many queued reminders it would expire. Reading it sends nothing;
             * whether anything is ever sent is `reminders.delivery_enabled`, which ships false. Withheld exactly as the
             * log is: it names the households in arrears and the amounts in their reminders.
             */
            ...(config('estate_features.reminder_delivery') && Dues::maySeeEstatePosition($request->user())
                ? ['delivery' => app(ReminderDelivery::class)->preview()]
                : []),
        ]);
    }

    /**
     * Save a proposed step. NOTHING THE ESTATE SENDS CHANGES (12 §1).
     *
     * A draft, always — new stage or reworded one. The collections run reads
     * the ladder in force and never a draft, so a template reworded at four in
     * the afternoon does not change what the four-o'clock run sends.
     */
    public function saveDraft(Request $request, Collections $collections): RedirectResponse
    {
        $fields = $request->validate([
            'template_id' => ['nullable', 'integer'],
            'label' => ['required', 'string', 'max:80'],
            // 0 is the pre-due courtesy, which every estate here already has.
            'stage' => ['required', 'integer', 'min:0', 'max:20'],
            'channel' => ['required', 'string', 'max:16'],
            'subject' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:4000'],
            'days_overdue' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $against = isset($fields['template_id'])
            ? DunningTemplate::query()->find($fields['template_id'])
            : null;

        if (isset($fields['template_id']) && $against === null) {
            return back()->withErrors(['label' => 'That step is not on this estate\'s ladder.'])->withInput();
        }

        try {
            $collections->saveDraft($fields, $request->user(), $against);
        } catch (DomainException $refused) {
            return back()->withErrors(['body' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/finance/dunning'))
            ->with('success', 'Saved as a draft. It changes nothing the estate sends until the committee puts it in force against a resolution.');
    }

    /** Put a draft in force, against a committee resolution (12 §1). */
    public function adoptDraft(Request $request, DunningTemplateDraft $draft, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'resolution_reference' => ['required', 'string', 'max:80'],
        ]);

        try {
            $template = $collections->adoptDraft($draft, $data['resolution_reference'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['resolution_reference' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/finance/dunning'))
            ->with('success', $template->label.' is in force under '.$data['resolution_reference'].'. Every notice already sent keeps the wording it went out with.');
    }

    /**
     * Send one notice, and log it as sent.
     *
     * The template is resolved to a row and handed to the service, which
     * renders it against the unit NOW. Nothing downstream re-renders: the log's
     * whole purpose is that a dispute can be settled from what the resident was
     * actually told.
     */
    public function send(Request $request, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'unit' => ['required', 'integer'],
            'template' => ['required', 'integer'],
        ]);

        $unit = Unit::findOrFail($data['unit']);

        // Only a step IN FORCE is sent: a superseded wording is history the log keeps, not something to send again.
        $template = DunningTemplate::query()->where('is_active', true)->find($data['template']);

        if ($template === null) {
            return back()->withErrors(['template' => 'That step is not in force on this estate\'s ladder. Choose one of the steps listed.']);
        }

        $notice = $collections->send($unit, $template, $request->user());

        // Back to this household's panel, where the reminder just sent is now the first of its own.
        return redirect()
            ->to($this->path('/finance/dunning').'?unit='.$unit->id)
            ->with('success', $notice->template_label.' logged for '.$unit->reference.', word for word as it was shown. '.
                'It is queued: nothing here has handed it to a delivery service yet.');
    }

    /** Where board 7 lives for this unit. */
    private function planPath(Unit $unit): string
    {
        return $this->path('/finance/units/'.$unit->id.'/payment-plan');
    }

    /**
     * A console path in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
