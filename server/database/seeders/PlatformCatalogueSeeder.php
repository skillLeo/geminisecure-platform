<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The commercial catalogue - platform rates, package features, and the
 * per-client overrides that sit on top of a plan.
 *
 * THE BOARDS ARE THE SPECIFICATION FOR DEMO DATA, exactly as BillingSeeder
 * says. A reviewer comparing Platform settings against the design they signed
 * off cannot tell a layout fault from a seeding accident, so what the approved
 * boards state is what this seeds:
 *
 *   board 42  the platform rate card, including the per-guard add-on
 *   board 43  which tier includes which feature, cell for cell
 *   board 44  one addition and one removal on a Standard client
 *
 * ASSUMPTION Q-002: the per-guard Security Provider rate. Board 42 is the only
 * supplied document that states one, in the same way board 02's MRR panel was
 * the only place tier prices were stated and BillingSeeder took them from
 * there. It is an assumption, not a ruling, and it is recorded as one.
 *
 * Runs after BillingSeeder: plan_features hangs off plans, which that seeder
 * creates. Ordering it earlier would silently seed nothing at all.
 */
class PlatformCatalogueSeeder extends Seeder
{
    /**
     * Board 42's rate card, minus the three rows that come from `plans`.
     *
     * [key, label, applies_to, amount in minor units, basis]
     */
    private const RATES = [
        [
            'security_provider_guard',
            'Security Provider add-on',
            'Applies on top of any tier',
            4_500_00,
            'guard',
        ],
    ];

    /**
     * Board 43's rows, in the order it draws them.
     *
     * [key, label, sub_label, kind, is_core]
     *
     * The first three are `is_core`: the board draws them as locked ticks in
     * every column, which is not "all three tiers happen to include this" but
     * "no package may be sold without it". A panic button behind a price tier
     * is the one thing this product must never ship.
     */
    private const FEATURES = [
        ['resident_core', 'Panic button, notices, maintenance, dues payment', null, 'toggle', true],
        ['visitor_passes', 'Visitor QR passes & tracking', null, 'toggle', true],
        ['amenity_booking', 'Amenity booking & deposits', null, 'toggle', true],
        ['guard_app', 'Guard App integration', 'Patrol / incident visibility', 'toggle', false],
        ['evoting', 'e-Voting / elections', null, 'toggle', false],
        ['gated_meetings', 'Resident-gated meetings', null, 'toggle', false],
        ['accounting_core', 'Accounting core', 'GL, AP/AR, bank import', 'toggle', false],
        ['estate_payroll', "Payroll & HR for estate's own staff", null, 'toggle', false],
        ['ai_drafting', 'AI drafting assistant', 'Notices, minutes, summaries', 'toggle', false],
        ['custom_branding', 'Custom branding', null, 'tier_value', false],
    ];

    /**
     * The grid itself, verbatim from board 43.
     *
     * feature key => [essential, standard, premium]. `true`/`false` answer a
     * toggle; a string is a `tier_value` feature's level for that tier.
     *
     * @var array<string, array<int, bool|string>>
     */
    private const GRID = [
        'resident_core' => [true, true, true],
        'visitor_passes' => [true, true, true],
        'amenity_booking' => [true, true, true],
        'guard_app' => [false, true, true],
        'evoting' => [false, true, true],
        'gated_meetings' => [false, true, true],
        'accounting_core' => [false, true, true],
        'estate_payroll' => [false, false, true],
        'ai_drafting' => [false, false, true],
        'custom_branding' => ['Logo only', 'Logo + hero', 'Full theme'],
    ];

    /** The plan keys the grid's three columns correspond to, in order. */
    private const GRID_COLUMNS = ['essential', 'standard', 'premium'];

    public function run(): void
    {
        $now = now();

        foreach (self::RATES as $i => [$key, $label, $appliesTo, $amount, $basis]) {
            $rates = DB::connection('mysql')->table('platform_rates');

            $attributes = [
                'label' => $label,
                'applies_to' => $appliesTo,
                'currency' => 'JMD',
                'basis' => $basis,
                'is_active' => true,
                'sort' => ($i + 1) * 10,
                'updated_at' => $now,
            ];

            /*
             * THE BOARD'S PRICE IS WHERE A RATE STARTS, NOT WHAT IT IS (M10 §3). Since the
             * Director can change the per-guard add-on from a date (`platform_rate_changes`),
             * the column holds the Director's price, and re-running this seeder on a live
             * server used to put the board's figure back over it — the rate card would then
             * disagree with the dated history the invoice run prices from. So the amount is
             * written when the row is CREATED and never again; everything else is still
             * brought up to date.
             */
            if ($rates->clone()->where('key', $key)->exists()) {
                $rates->clone()->where('key', $key)->update($attributes);
            } else {
                $rates->clone()->insert(['key' => $key, 'amount_minor' => $amount, 'created_at' => $now] + $attributes);
            }
        }

        foreach (self::FEATURES as $i => [$key, $label, $sub, $kind, $isCore]) {
            DB::connection('mysql')->table('package_features')->updateOrInsert(
                ['key' => $key],
                [
                    'label' => $label,
                    'sub_label' => $sub,
                    'kind' => $kind,
                    'is_core' => $isCore,
                    'sort' => ($i + 1) * 10,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $plans = DB::connection('mysql')->table('plans')->pluck('id', 'key');
        $features = DB::connection('mysql')->table('package_features')->pluck('id', 'key');

        foreach (self::GRID as $featureKey => $cells) {
            if (! isset($features[$featureKey])) {
                continue;
            }

            foreach (self::GRID_COLUMNS as $column => $planKey) {
                if (! isset($plans[$planKey])) {
                    continue;
                }

                $cell = $cells[$column];

                DB::connection('mysql')->table('plan_features')->updateOrInsert(
                    ['plan_id' => $plans[$planKey], 'package_feature_id' => $features[$featureKey]],
                    [
                        // A tier_value feature is included in every tier by
                        // definition - the tiers differ in HOW MUCH of it, and
                        // the string says which.
                        'included' => is_string($cell) ? true : $cell,
                        'tier_value' => is_string($cell) ? $cell : null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }
        }

        $this->seedOverrides();
    }

    /**
     * Board 44's two overrides, on the Standard client.
     *
     * Ocean View Gardens is this platform's Standard estate, which is what
     * makes both of the board's rows true of it: the AI drafting assistant
     * really is above its tier, and resident-gated meetings really are included
     * in the tier it is on and so can be removed from it. Hanging them on the
     * Premium client instead would produce two rows that contradict the
     * package grid two screens away.
     */
    private function seedOverrides(): void
    {
        $tenantId = DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('plans.key', 'standard')
            ->orderBy('subscriptions.tenant_id')
            ->value('subscriptions.tenant_id');

        if ($tenantId === null) {
            return;
        }

        $features = DB::connection('mysql')->table('package_features')->pluck('id', 'key');

        // The operator who made the change. Nullable in the schema, because an
        // override may outlive the account that made it, but a seeded one that
        // nobody made would demonstrate the wrong thing.
        $author = DB::connection('mysql')
            ->table('users')
            ->where('email', 'director@geminisecurity.test')
            ->value('id');

        $now = now();

        $overrides = [
            [
                'feature' => 'ai_drafting',
                'type' => 'addition',
                'description' => 'AI drafting assistant',
                'reason' => 'Added ahead of their upgrade conversation',
                'amount_minor' => 6_500_00,
                'effective_from' => $now->copy()->subDays(7)->toDateString(),
            ],
            [
                'feature' => 'gated_meetings',
                'type' => 'removal',
                'description' => 'Resident-gated meetings',
                'reason' => "Removed at the client's request",
                // Nought, and deliberately: the tier price does not fall when a
                // feature included in it is switched off. Crediting part of a
                // bundle back would price a tier as the sum of its parts, which
                // is precisely what a tier is not.
                'amount_minor' => 0,
                'effective_from' => $now->copy()->subMonths(2)->startOfMonth()->toDateString(),
            ],
        ];

        foreach ($overrides as $override) {
            DB::connection('mysql')->table('subscription_line_items')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'package_feature_id' => $features[$override['feature']] ?? null,
                    'type' => $override['type'],
                ],
                [
                    'description' => $override['description'],
                    'reason' => $override['reason'],
                    'amount_minor' => $override['amount_minor'],
                    'currency' => 'JMD',
                    'recurrence' => 'monthly',
                    'effective_from' => $override['effective_from'],
                    'effective_to' => null,
                    'created_by_user_id' => $author,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
