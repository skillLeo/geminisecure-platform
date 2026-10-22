<?php

declare(strict_types=1);

use App\Services\Gemini\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| A client's price panel reads where every override's feature sits once — M11 A2
|--------------------------------------------------------------------------
|
| Every override row on board 44 says where its feature normally sits — "Normally
| Premium-only", "Included in Standard", "Not part of any package" — and each row asked
| the database for it: a query per override tied to a feature, on the price panel and on
| the invoice preview beside it. The tiers are now read once for all of a client's
| overrides.
|
| The oracle is the old per-override query, written out here: the active plans whose
| template includes the feature, in tier order. The panel's rows must say exactly what it
| would have said, and must ask the question once however many overrides there are.
|
*/

// Every row here is central, and leaves with the transaction.
beforeEach(function (): void {
    DB::connection('mysql')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/**
 * The note the board used to write for one override, from the query it used to ask.
 */
function tierNoteByTheOldQuery(object $override, bool $removal): string
{
    if ($override->package_feature_id === null) {
        return $removal ? 'Included in the base plan' : 'Not part of any package';
    }

    $plans = DB::connection('mysql')
        ->table('plan_features')
        ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
        ->where('plan_features.package_feature_id', $override->package_feature_id)
        ->where('plan_features.included', true)
        ->where('plans.is_active', true)
        ->orderBy('plans.sort')
        ->pluck('plans.name');

    if ($plans->isEmpty()) {
        return $removal ? 'Included in the base plan' : 'Not part of any package';
    }

    if ($removal) {
        return 'Included in '.$plans->first();
    }

    return $plans->count() === 1 ? 'Normally '.$plans->first().'-only' : 'Normally '.$plans->first().' and above';
}

/**
 * A client on a tier, three tiers with distinct sorts, and features that sit in none, one,
 * two, or only a retired tier.
 *
 * @return array{tenant: string, features: array<string, int>, plans: array<string, int>}
 */
function priceBoardWorld(): array
{
    $db = DB::connection('mysql');
    $now = Carbon::now();
    $tag = (string) random_int(100000, 999999);

    $plan = static fn (string $name, int $sort, bool $active = true): int => (int) $db->table('plans')->insertGetId([
        'key' => 'm11tier'.$tag.$sort, 'name' => $name.$tag, 'price_per_unit_minor' => 300_00 + $sort, 'currency' => 'JMD',
        'min_units' => 10, 'is_active' => $active, 'sort' => 900 + $sort, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $plans = ['low' => $plan('Low', 1), 'mid' => $plan('Mid', 2), 'high' => $plan('High', 3), 'retired' => $plan('Retired', 4, false)];

    $inclusion = [
        'high only' => ['high'],
        'mid and up' => ['mid', 'high'],
        'everywhere' => ['low', 'mid', 'high'],
        'nowhere' => [],
        'retired only' => ['retired'],
    ];

    $features = [];
    foreach ($inclusion as $label => $in) {
        $features[$label] = (int) $db->table('package_features')->insertGetId([
            'key' => 'm11tierfeature'.$tag.'-'.str_replace(' ', '-', $label), 'label' => ucfirst($label), 'kind' => 'toggle', 'is_core' => false,
            'sort' => 900, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ($plans as $key => $planId) {
            $db->table('plan_features')->insert([
                'plan_id' => $planId, 'package_feature_id' => $features[$label], 'included' => in_array($key, $in, true),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    $tenant = 'm11tiers'.$tag;
    $db->table('tenants')->insert([
        'id' => $tenant, 'name' => 'Tier Notes Estate '.$tag, 'status' => 'active', 'demo' => 0, 'data' => '{}',
        'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $db->table('subscriptions')->insert([
        'tenant_id' => $tenant, 'plan_id' => $plans['mid'], 'unit_count' => 120, 'contracted_guards' => 0, 'status' => 'active',
        'started_on' => $now->copy()->subYear()->toDateString(), 'renews_on' => $now->copy()->addYear()->toDateString(),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    return ['tenant' => $tenant, 'features' => $features, 'plans' => $plans];
}

/** @param  list<array{0: string, 1: int|null}>  $overrides  type and feature id, each in force today */
function addOverrides(string $tenant, array $overrides): void
{
    $now = Carbon::now();

    foreach ($overrides as $n => [$type, $feature]) {
        DB::connection('mysql')->table('subscription_line_items')->insert([
            'tenant_id' => $tenant, 'package_feature_id' => $feature, 'type' => $type, 'description' => 'Override '.$n,
            'reason' => $type === 'removal' ? 'Removed at the client\'s request' : 'Agreed at renewal', 'amount_minor' => 1_000_00 + $n,
            'currency' => 'JMD', 'recurrence' => 'monthly', 'effective_from' => $now->copy()->subDays(30 - $n)->toDateString(),
            'effective_to' => null, 'created_by_user_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}

it('writes every override row\'s tier note exactly as the per-row query did', function (): void {
    $w = priceBoardWorld();
    $f = $w['features'];

    $overrides = [];
    foreach ($f as $feature) {
        $overrides[] = ['addition', $feature];
        $overrides[] = ['removal', $feature];
    }
    $overrides[] = ['addition', null];
    $overrides[] = ['removal', null];
    addOverrides($w['tenant'], $overrides);

    $board = app(PlatformSettings::class)->lineItems($w['tenant']);

    $rows = DB::connection('mysql')->table('subscription_line_items')->where('tenant_id', $w['tenant'])->orderBy('effective_from')->get();
    $expected = static fn (string $type): array => $rows->where('type', $type)->values()
        ->map(static fn (object $o): string => tierNoteByTheOldQuery($o, $type === 'removal'))->all();

    // The note is the first part of each row's detail line.
    $notes = static fn (array $list, bool $removal): array => array_map(
        static fn (array $row): string => $removal ? explode(', but ', $row['detail'])[0] : explode(' · ', $row['detail'])[0],
        $list,
    );

    expect($notes($board['additions'], false))->toBe($expected('addition'))
        ->and($notes($board['removals'], true))->toBe($expected('removal'))
        // The comparison is only a proof if every sentence the note can say was said.
        ->and(array_unique([...$notes($board['additions'], false), ...$notes($board['removals'], true)]))->toContain(
            'Normally High'.substr($w['tenant'], 8).'-only',
            'Normally Mid'.substr($w['tenant'], 8).' and above',
            'Normally Low'.substr($w['tenant'], 8).' and above',
            'Not part of any package',
            'Included in Mid'.substr($w['tenant'], 8),
            'Included in the base plan',
        );
});

it('asks where the features sit once, however many overrides the client has', function (): void {
    $w = priceBoardWorld();
    $f = array_values($w['features']);

    $tierReads = static fn (array $m): int => count(array_filter(
        $m['statements'],
        static fn (array $s): bool => str_contains($s['sql'], 'from `plan_features` inner join `plans`'),
    ));

    addOverrides($w['tenant'], [['addition', $f[0]], ['removal', $f[1]]]);
    $few = QueryBudget::measure(fn () => app(PlatformSettings::class)->lineItems($w['tenant']));

    addOverrides($w['tenant'], array_map(static fn (int $k): array => [$k % 2 === 0 ? 'addition' : 'removal', $f[$k % count($f)]], range(0, 19)));
    $many = QueryBudget::measure(fn () => app(PlatformSettings::class)->lineItems($w['tenant']));

    expect(count($many['result']['additions']) + count($many['result']['removals']))->toBe(22)
        ->and($tierReads($few))->toBe(1)
        ->and($tierReads($many))->toBe(1)
        ->and($many['count'])->toBe($few['count']);
});

it('names the lower-numbered of two tiers that share a sort first, every time', function (): void {
    $w = priceBoardWorld();
    $db = DB::connection('mysql');

    // Two active tiers given the same sort, both including the feature: the old query left
    // which came first to MySQL. `plans.id` decides now.
    $db->table('plans')->where('id', $w['plans']['high'])->update(['sort' => $db->table('plans')->where('id', $w['plans']['mid'])->value('sort')]);
    addOverrides($w['tenant'], [['addition', $w['features']['mid and up']]]);

    $board = app(PlatformSettings::class)->lineItems($w['tenant']);

    expect(explode(' · ', $board['additions'][0]['detail'])[0])->toBe('Normally Mid'.substr($w['tenant'], 8).' and above');
});
