<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\AuditEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Putting a client on a tier, and changing the one they are on — board 06.
 *
 * THIS SCREEN PRICES A CLIENT, which is why nothing about it is casual. Every
 * figure it shows is computed from the same arithmetic the invoice uses, and
 * the write it performs is audited with the before and after in the entry.
 *
 * ONE SCREEN, TWO ACTS. An estate mid-onboarding is being ACTIVATED — it has
 * never billed, so the projection is what it will cost once it goes live. A
 * live estate is being RE-PRICED — it is billing today, so the projection is
 * the difference between what it pays now and what it would pay after. The
 * board draws the first; the second is the same screen with a different verb,
 * and conflating them would tell a live client's account manager that their
 * bill is starting from zero.
 *
 * THE PROJECTION IS NOT A PROMISE ABOUT WHEN. A change takes effect at the
 * first full billing cycle, not immediately, because a mid-cycle re-price
 * would need proration nobody has specified. The screen says so in words
 * rather than showing a date it cannot honour.
 */
class ClientPlan
{
    /**
     * What pricing a client needs on top of Clients: Billing & subscriptions' Price clients act.
     *
     * Putting a client on a tier and a unit count sets what it is invoiced from the next cycle,
     * which is a change to what the client owes — so it is Billing's to grant, not Clients'. The
     * plan screen, its save and the new-client form (which puts the client on a tier) are therefore
     * behind this as well as Clients, for the screens as well as the writes: an editor opened to a
     * reader whose every save answers 403 is the screenful of refusals the Clients routes exist to
     * avoid.
     *
     * NOT the read verb, which is what the first fix used (bug hunt B004), and why it was reopened
     * (B069): the Operations Manager holds Billing to read only, and could still re-price a client
     * or take one on at a tier. Read `PlatformMoney::REVENUE_PERMISSION` for who may SEE a price;
     * this is who may CHOOSE one.
     *
     * ITS OWN ACT, NOT THE WHOLE WRITE VERB (M11 B3, D-150). B069 put it on Billing's `update`,
     * which also raised invoices, resent them and issued credit notes, so a role given that verb to
     * resend an unread invoice could re-price the client too. The verb is four acts now, and
     * choosing a client's price is this one; whoever held the verb holds all four.
     */
    public const PRICING_PERMISSION = 'gemini.billing_subscriptions.price_clients';

    /**
     * What both pricing forms say when the contract term is left out altogether. Null is an
     * answer ("Monthly rolling"); a missing field is not one, so it is asked for by name.
     */
    public const TERM_REQUIRED = 'Choose a contract term, or "Monthly rolling" for a client with no committed term.';

    /**
     * May this viewer open the plan screen and save it — Clients update and Billing's Price clients
     * act, exactly what `clients/{tenant}/plan` is behind. Null is no.
     */
    public static function mayPrice(?User $viewer): bool
    {
        return $viewer !== null
            && $viewer->can('gemini.clients.update')
            && $viewer->can(self::PRICING_PERMISSION);
    }

    /**
     * Why an onboarding client with no plan cannot go live — worded for who is reading it.
     *
     * One sentence in one place, because two screens say it: the go-live button's disabled title
     * (`ClientDirectory`) and the refusal of a go-live posted anyway (`ClientOnboarding`).
     *
     * "Set the plan first" only to a reader who can. The Head of Security and the Operations
     * Manager may press "Mark onboarding complete" and may not open the plan screen, and telling
     * them to set the plan was an instruction the platform then refused with a 403 (fix-up of
     * B004). They are told who can instead. Neither version names a figure.
     */
    public static function unplannedReason(bool $mayPrice): string
    {
        return 'This client has no prepared subscription plan, so there would be nothing to bill. '
            .($mayPrice
                ? 'Set the plan first.'
                : 'Its plan is set by a role with the Price clients permission on Billing & subscriptions, and it has to be prepared before this client can go live.');
    }

    /**
     * Everything screen 06 draws for one client.
     *
     * EVERY FIGURE HERE IS BILLING'S, which is why the screen is behind Billing &
     * subscriptions' Price clients act as well as Clients (routes/gemini/clients.php,
     * bug hunt B004 and B069, M11 B3): the tier prices, the per-guard rate and the platform's
     * MRR are what a client is charged and what the company earns (D-139), and
     * every control on the screen changes the first of them.
     *
     * `$platformWide` is the caller's answer to whether this reader may see the
     * whole platform. The platform's MRR is a sum over every client, so a reader
     * confined to their assigned sites is not sent it — omitted, not blanked, and
     * the page leaves out the one sentence that quotes it.
     *
     * @return array<string, mixed>
     */
    public function forEstate(Tenant $estate, bool $platformWide = true): array
    {
        $subscription = Subscription::query()->where('tenant_id', $estate->getTenantKey())->first();
        $plans = Plan::query()->where('is_active', true)->orderBy('sort')->get();
        $onboarding = $estate->status === ClientDirectory::ONBOARDING;

        $currency = $plans->isEmpty()
            ? MoneyFormatter::DEFAULT_CURRENCY
            : (string) $plans->first()->currency;

        return [
            'estate' => [
                'id' => (string) $estate->getTenantKey(),
                'name' => (string) $estate->name,
                'onboarding' => $onboarding,
            ],
            'plans' => $plans->map(fn (Plan $plan): array => [
                'id' => (int) $plan->id,
                'key' => (string) $plan->key,
                'name' => (string) $plan->name,
                'price' => MoneyFormatter::whole((int) $plan->price_per_unit_minor, (string) $plan->currency).'/unit',
                'price_minor' => (int) $plan->price_per_unit_minor,
                'min_units' => (int) $plan->min_units,
            ])->all(),
            'current' => [
                'plan_id' => $subscription?->plan_id,
                'units' => $subscription?->unit_count,
                'guards' => $subscription?->contracted_guards,
                'term_months' => $subscription?->term_months,
            ],
            'guard_rate_minor' => $this->guardRateMinor(),
            'currency' => $currency,

            /*
             * The platform's current MRR, so the note beneath the projection
             * can say what this change does to the headline. Read from the
             * same place the dashboard reads it rather than recomputed, so
             * the two cannot disagree.
             *
             * Only for a reader who may see the whole platform; never worked
             * out for one who may not.
             */
            ...($platformWide ? ['platform_mrr' => MoneyFormatter::whole($this->platformMrrMinor(), $currency)] : []),
            'action' => $onboarding ? 'Activate subscription' : 'Change plan',
            'effective' => $onboarding
                ? 'First full billing cycle after go-live'
                : 'First full billing cycle after the change',
        ];
    }

    /**
     * Apply the change.
     *
     * A single transaction, because a subscription written without its audit
     * entry is a re-price nobody can account for — and this is the act that
     * changes what a client pays.
     *
     * @param  array<string, mixed>  $input
     */
    public function apply(Tenant $estate, User $actor, array $input): void
    {
        $plans = Plan::query()->where('is_active', true)->get()->keyBy('id');

        $data = Validator::make($input, [
            'plan_id' => ['required', 'integer', Rule::in($plans->keys()->all())],

            /*
             * A tier states a minimum, and it is a commercial floor rather
             * than a technical one — the price only works above it. Checked
             * against the CHOSEN plan below, because the minimum differs per
             * tier and a single number here would be wrong for two of them.
             */
            'units' => ['required', 'integer', 'min:1', 'max:100000'],

            'guards' => ['required', 'integer', 'min:0', 'max:500'],

            // Nullable and meaningful: a client with no committed term renews
            // monthly, which is a real arrangement rather than missing data.
            //
            // And PRESENT: `nullable` lets the field be null, not be absent, and
            // the write below reads it. Left out, it passed validation and the
            // save answered 500 on an undefined key. The screen always sends it.
            'term_months' => ['present', 'nullable', 'integer', 'min:1', 'max:120'],
        ], [
            'term_months.present' => self::TERM_REQUIRED,
            'plan_id.required' => 'Choose a tier before activating.',
            'plan_id.in' => 'That tier is no longer available.',
            'units.required' => 'A subscription is priced per unit, so it needs a unit count.',
            'guards.required' => 'Enter 0 if this client manages their own security.',
        ])->validate();

        $plan = $plans[$data['plan_id']];

        /*
         * A CHANGE TO THE CONTRACTED UNITS SAYS WHY (M32 F2). The number is the ceiling the estate's own console holds its
         * phases and lots to, so raising or lowering it is a contract change: it is refused without a reason, and the
         * reason goes into the audit entry beside the before and after. Activating a plan, or changing anything else on
         * it, asks for none.
         */
        $current = Subscription::query()->where('tenant_id', (string) $estate->getTenantKey())->value('unit_count');
        $unitsChange = $current !== null && (int) $current !== (int) $data['units'];
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($unitsChange && (mb_strlen($reason) < 3 || mb_strlen($reason) > 300)) {
            Validator::make([], [])->after(function ($validator) use ($current, $data): void {
                $validator->errors()->add('reason', sprintf(
                    'Say why the contracted units change from %s to %s, in a few words. The estate\'s own console holds its phases and lots to this number, and the audit log keeps the reason beside the change.',
                    number_format((int) $current),
                    number_format((int) $data['units']),
                ));
            })->validate();
        }

        if ((int) $data['units'] < (int) $plan->min_units) {
            Validator::make([], [])->after(function ($validator) use ($plan): void {
                $validator->errors()->add(
                    'units',
                    sprintf(
                        'The %s tier starts at %s units. Choose a lower tier, or raise the unit count.',
                        $plan->name,
                        number_format((int) $plan->min_units),
                    ),
                );
            })->validate();
        }

        DB::connection('mysql')->transaction(function () use ($estate, $actor, $data, $plan, $unitsChange, $reason): void {
            $id = (string) $estate->getTenantKey();
            $existing = Subscription::query()->where('tenant_id', $id)->first();

            $before = $existing === null ? null : [
                'plan_id' => $existing->plan_id,
                'unit_count' => $existing->unit_count,
                'contracted_guards' => $existing->contracted_guards,
                'term_months' => $existing->term_months,
                'status' => $existing->status,
            ];

            $after = [
                'plan_id' => (int) $data['plan_id'],
                'unit_count' => (int) $data['units'],
                'contracted_guards' => (int) $data['guards'],
                'term_months' => $data['term_months'] === null ? null : (int) $data['term_months'],
            ];

            /*
             * The subscription does NOT start billing here.
             *
             * An onboarding estate's subscription stays out of `active` until
             * onboarding is marked complete — that is the act that starts
             * charging, and it lives on the client detail screen with its own
             * checklist behind it. Activating a plan sets what they WILL be
             * billed, not that they are.
             */
            $status = $existing === null ? 'onboarding' : (string) $existing->status;

            Subscription::updateOrCreate(
                ['tenant_id' => $id],
                [
                    ...$after,
                    'status' => $status,
                    'started_on' => $existing === null ? PlatformCalendar::today()->toDateString() : $existing->started_on,
                    'renews_on' => now()->addMonth()->startOfMonth()->toDateString(),
                ],
            );

            AuditEntry::create([
                'tenant_id' => $id,
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'actor_role' => $actor->roles->isEmpty() ? 'No role assigned' : $actor->roles->first()->label,
                'action' => $before === null ? 'client.plan_activated' : 'client.plan_changed',
                'entity_type' => 'subscription',
                'entity_id' => $id,
                'before' => $before,
                'after' => [...$after, 'plan' => $plan->name, ...($unitsChange ? ['reason' => $reason] : [])],
            ]);
        });
    }

    /**
     * The blank form for a client that does not exist yet — board screen 08.
     *
     * The same tiers and the same per-guard rate the activate screen uses, so
     * the projection an account manager sees at sign-up is the one the client
     * will actually be invoiced — and behind Billing's Price clients act as well as
     * Clients for the same reason that screen is (bug hunt B004, B069): the
     * submission puts the new client on a tier.
     *
     * `$viewer` narrows the one other client this form names. With nobody
     * looking, the demonstration estate is still left out.
     *
     * @return array<string, mixed>
     */
    public function newClientForm(?User $viewer = null): array
    {
        $plans = Plan::query()->where('is_active', true)->orderBy('sort')->get();

        return [
            'plans' => $plans->map(fn (Plan $plan): array => [
                'id' => (int) $plan->id,
                'key' => (string) $plan->key,
                'name' => (string) $plan->name,
                'price' => MoneyFormatter::whole((int) $plan->price_per_unit_minor, (string) $plan->currency).'/unit',
                'price_minor' => (int) $plan->price_per_unit_minor,
                'min_units' => (int) $plan->min_units,
            ])->all(),
            'guard_rate_minor' => $this->guardRateMinor(),
            'currency' => $plans->isEmpty()
                ? MoneyFormatter::DEFAULT_CURRENCY
                : (string) $plans->first()->currency,

            /*
             * Who is mid-onboarding right now. The board's note ends "same as
             * Ocean View Gardens is now", which is only true while an estate
             * is in that state — so it names whoever actually is, and says
             * nothing when nobody is.
             *
             * A CLIENT THIS READER MAY SEE, and a real one. It named the first
             * onboarding estate on the platform, which for a reader confined to
             * their sites is a client they may not know exists, and could be
             * the demonstration estate, which is nobody's example of anything.
             */
            'onboarding_example' => Tenant::query()
                ->where('status', ClientDirectory::ONBOARDING)
                ->tap(static fn ($query) => $viewer === null
                    ? OperatorEstates::exclude($query, 'id')
                    : OperatorEstates::narrow($query, $viewer, 'id'))
                ->orderBy('name')
                ->value('name'),

            /*
             * THE ESTATE'S CLOCK, CHOSEN WHEN IT IS TAKEN ON (M19A §9). Every IANA zone, searchable on the form, which
             * shows the time now in the one chosen so a wrong pick is obvious at once. No default is chosen for the
             * operator: a client in Florida left on Jamaica time would be an hour out for half the year.
             */
            'zones' => DateTimeZone::listIdentifiers(),
        ];
    }

    /** The per-guard charge that sits on top of any tier. */
    private function guardRateMinor(): int
    {
        return (int) DB::connection('mysql')
            ->table('platform_rates')
            ->where('key', 'security_provider_guard')
            ->where('is_active', true)
            ->value('amount_minor');
    }

    /** What the platform bills today, tiers plus add-on. */
    private function platformMrrMinor(): int
    {
        $subscriptions = (int) DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', 'active')
            ->sum(DB::raw('subscriptions.unit_count * plans.price_per_unit_minor'));

        $guards = (int) DB::connection('mysql')
            ->table('subscriptions')
            ->where('status', 'active')
            ->sum('contracted_guards');

        return $subscriptions + ($guards * $this->guardRateMinor());
    }
}
