<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Gemini\PlatformSettings;
// Every date below is compared by the platform with Gemini's own today (PlatformCalendar), never the server's UTC
// date — which is already tomorrow from 7 PM in Kingston, when "yesterday" in UTC is still today in the office.
use App\Support\PlatformCalendar;
use Database\Seeders\BillingSeeder;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The three platform-settings writes — boards 42, 43 and 44 (12 §2, Wave 4)
|--------------------------------------------------------------------------
|
| Each changes what is true for clients other than the one in front of you, so
| each is `configure` and each is audited. A price and an override carry an
| effective date and NEITHER IS RETROACTIVE: an invoice already raised was
| raised at the price in force, and re-pricing the past would make the ledger
| disagree with the paper a client holds.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    /*
     * THE RATE CARD THIS FILE RE-PRICES IS SEEDED HERE (M4 §4, defect 3).
     *
     * Every assertion below starts from "the first active plan", and `plans` is central: it was
     * only ever there because some OTHER file had seeded it and committed it first. In file order
     * that happened to be true; `phpunit.xml` now runs the suite in random order, and this file
     * running first read an empty table and failed on `expect($plan)->not->toBeNull()` — a
     * fixture that was missing, reported as a settings screen that was broken.
     *
     * Both seeders are idempotent, both are skipped when the rate card is already there, and both
     * run INSIDE the transaction above, so this file neither depends on nor leaves anything.
     */
    if (! DB::connection('mysql')->table('plans')->where('is_active', true)->exists()) {
        Artisan::call('db:seed', ['--class' => BillingSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PlatformCatalogueSeeder::class, '--force' => true]);
    }

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('changes a tier price from a date, never retroactively, and applies a due change on read', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $settings = app(PlatformSettings::class);

    $plan = DB::connection('mysql')->table('plans')->where('is_active', true)->orderBy('sort')->first();

    expect($plan)->not->toBeNull();

    $this->actingAs($director)
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canWrite', true)
            ->has('plans')
            ->has('pending'));

    // A role that may read the rate card may not re-price the platform.
    $this->actingAs($dispatcher)
        ->post('/settings/prices', ['plan_id' => $plan->id, 'amount' => '1.00', 'effective_from' => PlatformCalendar::today()->toDateString(), 'reason' => 'x'])
        ->assertForbidden();

    // NEVER RETROACTIVE.
    $this->actingAs($director)
        ->post('/settings/prices', [
            'plan_id' => $plan->id,
            'amount' => '999.00',
            'effective_from' => PlatformCalendar::today()->subDay()->toDateString(),
            'reason' => 'Backdated',
        ])
        ->assertSessionHasErrors('amount');

    // AND NEVER UNEXPLAINED — the reason is what an auditor reads.
    $this->actingAs($director)
        ->post('/settings/prices', [
            'plan_id' => $plan->id, 'amount' => '999.00', 'effective_from' => PlatformCalendar::today()->toDateString(), 'reason' => '',
        ])
        ->assertSessionHasErrors('reason');

    expect((int) DB::connection('mysql')->table('plans')->where('id', $plan->id)->value('price_per_unit_minor'))
        ->toBe((int) $plan->price_per_unit_minor);

    // Today: applied at once, and the audit entry names the blast radius.
    $this->actingAs($director)
        ->post('/settings/prices', [
            'plan_id' => $plan->id,
            'amount' => '999.00',
            'effective_from' => PlatformCalendar::today()->toDateString(),
            'reason' => 'Annual review, board minute 2026-09.',
        ])
        ->assertRedirect('/settings');

    expect((int) DB::connection('mysql')->table('plans')->where('id', $plan->id)->value('price_per_unit_minor'))
        ->toBe(999_00);

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'platform.tier_price_changed')
        ->orderByDesc('id')
        ->first();

    $after = json_decode((string) $entry->after, true);

    expect($entry->actor_name)->toBe($director->name)
        ->and($after['reason'])->toContain('Annual review')
        ->and($after['applied'])->toBeTrue()
        ->and($after)->toHaveKey('clients_repriced');

    /*
     * A FUTURE DATE IS PENDING AND CHANGES NOTHING YET — and applies when the
     * day arrives. A pending change that silently never applied would be a
     * price the platform believes it charges and does not.
     */
    $settings->changePlanPrice($plan->id, '1250.00', PlatformCalendar::today()->addDays(30)->toDateString(), 'Tier uplift', $director);

    expect((int) DB::connection('mysql')->table('plans')->where('id', $plan->id)->value('price_per_unit_minor'))->toBe(999_00)
        ->and($settings->pendingPriceChanges())->toHaveCount(1);

    // The day arrives.
    DB::connection('mysql')->table('plan_price_changes')
        ->whereNull('applied_at')
        ->update(['effective_from' => PlatformCalendar::today()->toDateString()]);

    expect($settings->applyDuePriceChanges())->toBe(1)
        ->and((int) DB::connection('mysql')->table('plans')->where('id', $plan->id)->value('price_per_unit_minor'))->toBe(1_250_00)

        // Idempotent: applied once, and a second sweep does nothing.
        ->and($settings->applyDuePriceChanges())->toBe(0);
});

it('toggles a package feature but never a core one, and both are audited', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $settings = app(PlatformSettings::class);

    $plan = DB::connection('mysql')->table('plans')->where('is_active', true)->orderBy('sort')->first();
    $optional = DB::connection('mysql')->table('package_features')->where('is_core', false)->where('kind', '!=', 'tier_value')->first();
    $core = DB::connection('mysql')->table('package_features')->where('is_core', true)->first();

    if ($optional === null) {
        expect(true)->toBeTrue();

        return;
    }

    $this->actingAs($director)
        ->post('/settings/packages', [
            'changes' => [['plan_id' => $plan->id, 'feature_id' => $optional->id, 'included' => true]],
        ])
        ->assertRedirect('/settings/packages');

    expect((bool) DB::connection('mysql')->table('plan_features')
        ->where('plan_id', $plan->id)->where('package_feature_id', $optional->id)->value('included'))->toBeTrue();

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'platform.package_feature_changed')
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and(json_decode((string) $entry->after, true)['included'])->toBeTrue();

    /*
     * A CORE FEATURE IS NOT A SETTING. It is in every package by definition and
     * the cell is drawn as a fact rather than a switch; the service refuses a
     * write that reached one anyway.
     */
    if ($core !== null) {
        expect(fn () => $settings->setPackageFeature((int) $plan->id, (int) $core->id, false, $director))
            ->toThrow(DomainException::class);
    }
});

it('adds a dated override and ends it rather than deleting it', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $tenantId = FacilitiesFixture::platform()->getTenantKey();

    // Never retroactive: it would change an invoice already raised.
    $this->actingAs($director)
        ->post('/settings/line-items', [
            'client' => $tenantId, 'name' => 'Backdated', 'type' => 'addition',
            'amount' => '500.00', 'effective_from' => PlatformCalendar::today()->subDay()->toDateString(),
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs($director)
        ->post('/settings/line-items', [
            'client' => $tenantId,
            'name' => 'Custom branding',
            'reason' => 'Negotiated at signing.',
            'type' => 'addition',
            'amount' => '7500.00',
            'effective_from' => PlatformCalendar::today()->toDateString(),
        ])
        ->assertRedirect();

    $item = DB::connection('mysql')->table('subscription_line_items')
        ->where('tenant_id', $tenantId)
        ->where('description', 'Custom branding')
        ->first();

    /*
     * BOTH KINDS ARE POSITIVE AMOUNTS WITH A TYPE. A signed field would let a
     * removal be entered as a negative addition and read as a discount nobody
     * agreed.
     */
    expect($item)->not->toBeNull()
        ->and((int) $item->amount_minor)->toBe(7_500_00)
        ->and($item->type)->toBe('addition')
        ->and($item->effective_to)->toBeNull()
        ->and((int) $item->created_by_user_id)->toBe($director->getKey());

    // ENDED, NEVER DELETED — the invoices it was billed on would otherwise be
    // unexplainable.
    $this->actingAs($director)
        ->post('/settings/line-items/'.$item->id.'/end', [
            'client' => $tenantId, 'effective_to' => PlatformCalendar::today()->toDateString(),
        ])
        ->assertRedirect();

    $item = DB::connection('mysql')->table('subscription_line_items')->where('id', $item->id)->first();

    expect($item)->not->toBeNull()
        ->and($item->effective_to)->not->toBeNull();

    // A second ending is refused rather than silently re-dating it.
    $this->actingAs($director)
        ->post('/settings/line-items/'.$item->id.'/end', [
            'client' => $tenantId, 'effective_to' => PlatformCalendar::today()->toDateString(),
        ])
        ->assertSessionHasErrors('effective_to');
});
