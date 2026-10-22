<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Plans, subscriptions and a short invoice history.
 *
 * THE BOARDS ARE THE SPECIFICATION FOR DEMO DATA.
 *
 * A reviewer comparing a screen against the design they signed off cannot tell
 * a layout fault from a seeding accident. The approved boards state what each
 * client is — Phoenix Park Village 1 is the flagship Premium account at 450
 * units and $171,000 a month, Ocean View Gardens is mid-onboarding at 320 —
 * so the seed says the same, and every screen that shows a client agrees with
 * every other.
 *
 * This was previously seeded by loop index: estate 0 active, the rest dunning,
 * units 48 + 22i. That made Phoenix Park a dunning Standard client with no
 * MRR, which is the opposite of what the boards draw, and the Client Detail
 * screen's own hero card sizes to its content — so shorter data made a
 * narrower card and shifted the entire action column against the design.
 *
 * PRICES ARE THE BOARDS', and three of them agree.
 *
 * The package builder and the client line items screens both print the rate
 * card — Essential J$180, Standard J$260, Premium J$340 per unit per month —
 * and the invoice board decomposes Phoenix Park's August bill as 450 units at
 * J$340 plus 4 guards at J$4,500, which reconciles to the J$171,000 the
 * dashboard shows. Four boards, one arithmetic.
 *
 * These were first derived from the dashboard's MRR panel alone, which gave
 * J$380 per unit — right total, wrong decomposition, because the guard add-on
 * was invisible in that one figure. The error survived the pixel harness
 * because a tier price is three short strings in a table header and three
 * wrong prices cost less than the 2% threshold. It was the invoice board's
 * breakdown that exposed it.
 *
 * One estate stays in `dunning` deliberately. The billing screen claims that
 * dunning gates billing features only and never restricts access, and that
 * claim is worth nothing if no estate is ever in dunning to demonstrate it.
 */
class BillingSeeder extends Seeder
{
    /**
     * [key, name, price per unit per month in minor units, min units, highlights]
     *
     * The highlights are the board's own copy, in its own order. The first line
     * of each higher tier is "Everything in <the tier below>", which is the
     * whole argument for the price step and only reads correctly first.
     */
    private const PLANS = [
        ['essential', 'Essential', 180_00, 25, [
            'Resident directory & verification',
            'Dues & ledger',
            'Notices & basic reporting',
        ]],
        ['standard', 'Standard', 260_00, 50, [
            'Everything in Essential',
            'Maintenance & amenity booking',
            'Governance & elections',
        ]],
        ['premium', 'Premium', 340_00, 100, [
            'Everything in Standard',
            'Payroll & statutory filing',
            'Full accounting suite',
        ]],
    ];

    /**
     * The per-guard charge that sits on top of any tier.
     *
     * Not a column on `plans`, because that would assert a guard costs a
     * different amount depending on the estate's subscription. It lives in
     * `platform_rates`; this is the key.
     */
    private const GUARD_RATE_KEY = 'security_provider_guard';

    /**
     * How each client settles, as the payment methods board draws them.
     *
     * Ocean View is deliberately absent. It is mid-onboarding, and the board
     * shows exactly that case — "Not yet on file · Needed before go-live" —
     * which can only be demonstrated by a client that genuinely has none.
     *
     * @var array<string, array{institution: string, last_four: string}>
     */
    private const PAYMENT_METHODS = [
        'phoenixpark' => ['institution' => 'NCB Jamaica', 'last_four' => '7712'],
    ];

    /**
     * What each client is, taken from the boards that draw it.
     *
     * Keyed by subdomain. An estate not listed here falls back to the generic
     * profile below, so provisioning a new estate still produces something
     * sensible without anyone editing this file.
     *
     * `renews_on`, `contract_renewal` and `billed_in_advance` are optional.
     * Omitted, a client renews on the generic `now()+1 month` cycle this file
     * always used and has no contract-renewal date at all — which is correct
     * for a fallback estate nobody has drawn a board for. Phoenix Park carries
     * all three because board community-admin-40 states them as fixed facts of
     * that ONE client's account, not as something a formula relative to
     * whatever day this seeder happens to run should keep re-deriving. See
     * `2026_09_11_170000_add_contract_renewal_to_subscriptions_table` for why
     * the renewal date cannot be computed from `started_on` and `term_months`.
     *
     * @var array<string, array{plan: string, units: int, status: string, months: int, renews_on?: string, contract_renewal?: string, billed_in_advance?: bool}>
     */
    private const CLIENTS = [
        // Board super-admin-05: "450 units · 4 guards deployed · $171,000 MRR
        // · Current" and "Premium tier", client since Mar 2024. Board
        // community-admin-40 (the estate's OWN billing screen) draws the
        // software-only slice of the same account: 450 x $340 = $153,000/mo,
        // next invoice Sep 1, contract renewal Mar 2027, and its last three
        // invoices all paid on the first of their own month — "Billed monthly
        // in advance", which is the board's own words and not this file's
        // ordinary in-arrears due date.
        'phoenixpark' => [
            'plan' => 'premium', 'units' => 450, 'status' => 'active', 'months' => 30,
            'renews_on' => '2026-09-01',
            'contract_renewal' => '2027-03-01',
            'billed_in_advance' => true,
        ],

        // Board super-admin-09: Ocean View Gardens, 320 units, still onboarding.
        // Left in dunning so the billing screen has a live example of the state
        // it makes a claim about.
        'oceanview' => ['plan' => 'standard', 'units' => 320, 'status' => 'dunning', 'months' => 7],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $i => [$key, $name, $price, $minUnits, $highlights]) {
            /*
             * THE BOARD'S PRICE IS WHERE A TIER STARTS, NOT WHAT IT IS (M10 §3). The Director
             * changes a tier's per-unit price from a date (`plan_price_changes`), so on a live
             * server this column holds the Director's price; re-running the seeder must not put
             * the board's figure back over it and leave the rate card disagreeing with the dated
             * history the invoice run prices from. The price is set when the plan is CREATED,
             * and every other attribute is still brought up to date.
             */
            $plan = Plan::query()->firstOrNew(['key' => $key]);

            $plan->fill([
                'name' => $name,
                'description' => "Per unit, per month. Minimum {$minUnits} units.",
                'highlights' => $highlights,
                'currency' => 'JMD',
                'min_units' => $minUnits,
                'is_active' => true,
                'sort' => ($i + 1) * 10,
            ]);

            if (! $plan->exists) {
                $plan->price_per_unit_minor = $price;
            }

            $plan->save();
        }

        $plans = Plan::query()->get()->keyBy('key');

        foreach (Tenant::estates() as $i => $estate) {
            $subdomain = (string) $estate->getTenantKey();

            $profile = self::CLIENTS[$subdomain] ?? [
                // A newly provisioned estate nobody drew a board for. Standard
                // tier at its minimum, active, just signed.
                'plan' => 'standard',
                'units' => 50 + ($i * 22),
                'status' => 'active',
                'months' => 1,
            ];

            $plan = $plans[$profile['plan']];

            if (isset(self::PAYMENT_METHODS[$subdomain])) {
                DB::connection('mysql')->table('payment_methods')->updateOrInsert(
                    ['tenant_id' => $subdomain],
                    [
                        ...self::PAYMENT_METHODS[$subdomain],
                        'kind' => 'bank_transfer',
                        'is_default' => true,
                        'status' => 'active',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }

            $subscription = Subscription::updateOrCreate(
                ['tenant_id' => $subdomain],
                [
                    'plan_id' => $plan->id,
                    'unit_count' => $profile['units'],
                    'status' => $profile['status'],
                    'started_on' => now()->subMonths($profile['months'])->toDateString(),
                    'renews_on' => $profile['renews_on'] ?? now()->addMonth()->startOfMonth()->toDateString(),
                    // The board's activate-plan screen shows a 24-month term.
                    'term_months' => 24,
                    'contract_renewal_on' => $profile['contract_renewal'] ?? null,
                ],
            );

            /*
             * The invoice's LINES, and its total derived from them.
             *
             * An invoice carrying only a total is a number nobody can query. A
             * client asking "why is this J$171,000" needs the two lines that
             * make it up, and the invoice board draws exactly those. So the
             * lines are the record and the total is their sum — not the other
             * way round, which is how a total and its breakdown come to
             * disagree.
             */
            $guardRate = (int) DB::connection('mysql')
                ->table('platform_rates')
                ->where('key', self::GUARD_RATE_KEY)
                ->value('amount_minor');

            $guards = (int) ($subscription->contracted_guards ?? 0);

            $lines = [
                [
                    'description' => $plan->name.' subscription',
                    'quantity' => $profile['units'],
                    'unit_price_minor' => (int) $plan->price_per_unit_minor,
                ],
            ];

            // Only when there are guards to charge for. A client managing their
            // own security gets a one-line invoice, not a line reading zero.
            if ($guards > 0 && $guardRate > 0) {
                $lines[] = [
                    'description' => 'Security Provider add-on',
                    'quantity' => $guards,
                    'unit_price_minor' => $guardRate,
                ];
            }

            /*
             * Six months of history, not three.
             *
             * The MRR trend report draws six columns, and a client of thirty
             * months with three invoices on file makes the platform look like
             * it started this quarter. Six is the minimum that report needs to
             * say anything; a client onboarded more recently simply has fewer,
             * because an invoice is not raised before the subscription starts.
             */
            $history = 6;

            /*
             * "Billed monthly in advance", in board community-admin-40's own
             * words, and Phoenix Park is the only client whose board draws
             * that cadence stated as a fact rather than left to this file's
             * ordinary assumption. In arrears — the default below — an
             * invoice is raised for a period already under way and settles
             * two weeks into it, which is why the CURRENT period is normally
             * still open. In advance, the invoice for a period is raised and
             * paid at or before that period's own first day, so a client
             * already ten days into September has already settled September.
             */
            $billedInAdvance = (bool) ($profile['billed_in_advance'] ?? false);

            foreach (range($history - 1, 0) as $monthsAgo) {
                $start = now()->subMonths($monthsAgo)->startOfMonth();

                // No invoice before the client existed. A back-dated invoice
                // would put revenue in a month nobody was billed.
                if ($start->lt(now()->subMonths($profile['months'])->startOfMonth())) {
                    continue;
                }

                // Everything but the current period is settled — unless this
                // client is billed in advance, in which case the current
                // period was already settled before it began.
                $paid = $billedInAdvance || $monthsAgo > 0;

                $invoice = Invoice::updateOrCreate(
                    ['reference' => strtoupper(substr($estate->getTenantKey(), 0, 2))
                        .'-INV-'.$start->format('Ym')],
                    [
                        'tenant_id' => $estate->getTenantKey(),
                        'subscription_id' => $subscription->id,
                        'period' => $start->format('M Y'),
                        'period_start' => $start->toDateString(),
                        'period_end' => $start->copy()->endOfMonth()->toDateString(),
                        'total_minor' => array_sum(array_map(
                            static fn (array $line): int => $line['quantity'] * $line['unit_price_minor'],
                            $lines,
                        )),
                        'currency' => 'JMD',
                        'due_on' => ($billedInAdvance ? $start : $start->copy()->addDays(14))->toDateString(),
                        'status' => $paid ? 'paid' : 'issued',
                        'paid_on' => $paid
                            ? ($billedInAdvance ? $start->toDateString() : $start->copy()->addDays(9)->toDateString())
                            : null,
                    ],
                );

                foreach ($lines as $line) {
                    InvoiceLine::updateOrCreate(
                        ['invoice_id' => $invoice->id, 'description' => $line['description']],
                        [
                            'quantity' => $line['quantity'],
                            'unit_price_minor' => $line['unit_price_minor'],
                            'total_minor' => $line['quantity'] * $line['unit_price_minor'],
                            'currency' => 'JMD',
                        ],
                    );
                }
            }
        }
    }
}
