<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\EstateFeature;
use Illuminate\Support\Facades\DB;

/**
 * What this estate's plan leaves out, among the features that are a module of their own (Hassam's closing order,
 * 12 Oct 2026: "A disabled module is absent from navigation, and a deep link to one shows a clean 'not included in
 * your plan' page, never a stack trace").
 *
 * THE SAME ANSWER THE FEATURES PAGE GIVES, read the same way (`Settings::featureRow()`): an estate's own recorded
 * decision for the feature stands where there is one, and otherwise the plan's grid says. Until this class nothing read
 * either: a module a plan left out was still in the sidebar and every address in it opened.
 *
 * TWO MODULES ARE A FEATURE OF THEIR OWN, and only those two are gated: Accounting (`accounting_core`) and Payroll & HR
 * (`estate_payroll`). The rest of the catalogue is either core, on at every tier, or a part of a module rather than a
 * module (e-voting is the Elections tab of Governance, AI drafting is a button), and hiding a whole module for one of
 * those would hide what the plan does include.
 *
 * OFF UNTIL HASSAM'S WORD (`enforced()`, `estate_features.plan_gate`). Every estate is provisioned on Standard and
 * Standard leaves Payroll & HR out (Premium only in the grid), so on, it would close Payroll & HR on every estate still
 * on Standard, the pilot's included if that is its plan. Built and tested; turned on by one line once each live
 * estate's plan is the one it pays for.
 *
 * AN ESTATE WITH NO PLAN ON RECORD HAS NOTHING SWITCHED OFF. A plan is Gemini Security's to set against the
 * subscription; an estate still being set up has none, and closing its Accounting and Payroll before anybody has
 * chosen its plan would be a decision nobody made.
 *
 * ONE READ OF THE CENTRAL GRID A REQUEST, and one of the estate's own decisions only when there is a plan, kept on the
 * request so the sidebar and a route's gate ask once between them.
 */
final class PlanGate
{
    /** A sidebar item's key => the catalogue feature that is that module. */
    public const MODULES = ['accounting' => 'accounting_core', 'payroll' => 'estate_payroll'];

    /** The module's name, as the sidebar prints it. */
    public const LABELS = ['accounting_core' => 'Accounting', 'estate_payroll' => 'Payroll & HR'];

    /**
     * The estate's plan, and which of the two module features it leaves out.
     *
     * @return array{plan: string|null, plan_name: string|null, left_out: list<string>}
     */
    public static function forEstate(string $tenantKey): array
    {
        $request = request();
        $memo = 'plan-gate.'.$tenantKey;

        if ($request->attributes->has($memo)) {
            /** @var array{plan: string|null, plan_name: string|null, left_out: list<string>} $kept */
            $kept = $request->attributes->get($memo);

            return $kept;
        }

        $answer = self::resolve($tenantKey);
        $request->attributes->set($memo, $answer);

        return $answer;
    }

    /**
     * Whether a module a plan leaves out is closed at all: `estate_features.plan_gate`, off until every live estate's
     * plan has been checked (estates are provisioned on Standard, which leaves Payroll & HR out). Off, nothing closes.
     */
    public static function enforced(): bool
    {
        return (bool) config('estate_features.plan_gate', false);
    }

    /** Is this module closed to this estate because its plan leaves it out? False for a feature that is no module of its own. */
    public static function leavesOut(string $featureKey, string $tenantKey): bool
    {
        if (! self::enforced() || $tenantKey === '' || ! in_array($featureKey, self::MODULES, true)) {
            return false;
        }

        return in_array($featureKey, self::forEstate($tenantKey)['left_out'], true);
    }

    /**
     * @return array{plan: string|null, plan_name: string|null, left_out: list<string>}
     */
    private static function resolve(string $tenantKey): array
    {
        // The estate's first subscription, as `Settings::featuresBoard()` reads it, with which of the two it includes.
        $rows = DB::connection('mysql')->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->leftJoin('plan_features', function ($join): void {
                $join->on('plan_features.plan_id', '=', 'plans.id')->where('plan_features.included', true);
            })
            ->leftJoin('package_features', function ($join): void {
                $join->on('package_features.id', '=', 'plan_features.package_feature_id')
                    ->whereIn('package_features.key', array_values(self::MODULES));
            })
            ->where('subscriptions.tenant_id', $tenantKey)
            ->orderBy('subscriptions.id')
            ->get(['subscriptions.id as subscription', 'plans.key as plan', 'plans.name as plan_name', 'package_features.key as feature']);

        if ($rows->isEmpty()) {
            return ['plan' => null, 'plan_name' => null, 'left_out' => []];
        }

        $first = $rows->first();
        $included = $rows->where('subscription', $first->subscription)->pluck('feature')->filter()->values()->all();

        // The estate's own recorded decision stands where there is one (`Settings::featureRow()`).
        $decided = EstateFeature::query()
            ->whereIn('feature_key', array_values(self::MODULES))
            ->pluck('enabled', 'feature_key')
            ->map(static fn ($enabled): bool => (bool) $enabled)
            ->all();

        $leftOut = [];

        foreach (self::MODULES as $feature) {
            $on = $decided[$feature] ?? in_array($feature, $included, true);

            if (! $on) {
                $leftOut[] = $feature;
            }
        }

        return ['plan' => (string) $first->plan, 'plan_name' => (string) $first->plan_name, 'left_out' => $leftOut];
    }
}
