<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The TEST card checkout — a preview of paying by card, never a payment
|--------------------------------------------------------------------------
|
| The client asked (25 September 2026) for "a fake page … like Stripe … where the
| user adds card details like 4242 4242 4242 4242, expiry and CVC, just for
| testing, to present how it will look when the API is integrated".
|
| Dues are still paid by hand (Q-012), so this holds the preview to what keeps it a
| preview: its ONLY route is a GET, so nothing on the server can take what is typed
| there; the page makes no request and keeps nothing; it takes only Stripe's test
| numbers; and opening it posts nothing to the ledger.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

it('opens from a unit, with its balance as the amount to pretend to pay, this month at the estate, and the estate\'s zone', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::unit('Lot 47');

    FacilitiesFixture::boot();
    $balance = app(Dues::class)->balanceOf($unit)->getMinorAmount()->toInt();
    $today = Dues::siteDate();

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id.'/test-checkout'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Dues/TestCheckout')
            ->where('unit.id', $unit->id)
            ->where('unit.reference', 'Lot 47')
            ->where('balance_minor', $balance)
            ->where('today.year', (int) $today->year)
            ->where('today.month', (int) $today->month)
            ->has('timezone'));
});

it('shows what the household owes off its own ledger lines, to the cent', function () {
    /*
     * THE MONEY RULE (M97). The "owes" on this page, and the amount it offers to pretend to pay, is the unit's balance:
     * read here a second way, its 1200 lines summed longhand, on a lot of this file's own that owes $12,400.50 (Lot 47
     * owes nothing in the fixture, where a tie of nothing to nothing would prove nothing).
     */
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit('Lot 9931');

    FacilitiesFixture::boot();

    if (DB::connection('tenant')->table('charges')->where('unit_id', $unit->id)->doesntExist()) {
        app(Dues::class)->charge(unit: $unit, amount: Money::of('12400.50', 'JMD'), description: 'Maintenance arrears brought forward', dueOn: Carbon::today()->subDays(10));
    }

    $owed = (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unit->id])->owed;

    expect($owed % 100)->toBe(50);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id.'/test-checkout'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('balance_minor', $owed));
});

it('is behind the ledger\'s own gate: a role that may not read the ledger may not open it', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $unit = FacilitiesFixture::unit('Lot 47');

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id.'/test-checkout'))
        ->assertForbidden();
});

it('has no route that could take a card: the only one is a GET', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_contains($route->uri(), 'test-checkout'));

    expect($routes)->toHaveCount(1)
        ->and(array_values(array_diff($routes->first()->methods(), ['GET', 'HEAD'])))->toBe([]);
});

it('posts nothing: opening it leaves the ledger, the payments and the receipts exactly as they were', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::unit('Lot 47');

    FacilitiesFixture::boot();
    $before = [
        DB::connection('tenant')->table('journals')->count(),
        DB::connection('tenant')->table('journal_lines')->count(),
        DB::connection('tenant')->table('payments')->count(),
    ];

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/finance/units/'.$unit->id.'/test-checkout'))->assertOk();

    FacilitiesFixture::boot();
    expect([
        DB::connection('tenant')->table('journals')->count(),
        DB::connection('tenant')->table('journal_lines')->count(),
        DB::connection('tenant')->table('payments')->count(),
    ])->toBe($before);
});

it('makes no request and keeps nothing — the page and its rules never reach the network or the browser\'s storage', function () {
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/TestCheckout.vue'));
    $rules = (string) file_get_contents(resource_path('js/lib/testCheckout.js'));

    foreach ([$page, $rules] as $code) {
        expect($code)->not->toMatch('/useForm|router\.(post|put|patch|delete|visit|get)\(|axios|fetch\(|XMLHttpRequest|localStorage|sessionStorage|indexedDB|console\.log|sendBeacon/');
    }

    // It asks the browser and password managers NOT to offer a saved card, and never names a card field for autofill.
    expect($page)->not->toContain('autocomplete="cc-')
        ->and(substr_count($page, 'autocomplete="off"'))->toBeGreaterThanOrEqual(5);
});

it('takes only Stripe\'s published test numbers, and says PREVIEW where it could be mistaken for a payment', function () {
    $rules = (string) file_get_contents(resource_path('js/lib/testCheckout.js'));
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/TestCheckout.vue'));

    foreach (['4242424242424242', '5555555555554444', '4000000000000002', '4000000000009995'] as $number) {
        expect($rules)->toContain("'{$number}'");
    }

    // Still unmistakable, now as a preview (DR-052): on the badges, the button and the result.
    expect($rules)->toContain('Never enter a real card here')
        ->and($rules)->toContain('No money moved')
        ->and($rules)->toContain('balance has not changed')
        ->and(substr_count($page, 'PREVIEW'))->toBeGreaterThanOrEqual(3)
        ->and($page)->toContain('(preview)');
});

it('never puts the word "test" beside a sum: the ledger link, the page, its button and its result say preview (DR-052; M77 §6)', function () {
    $rules = (string) file_get_contents(resource_path('js/lib/testCheckout.js'));
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/TestCheckout.vue'));
    $ledger = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/UnitLedger.vue'));

    // "Pay $7,700.00 (test)" beside a household's dues read as if the system were a test.
    foreach (['(test)', 'TEST MODE', 'Test payment succeeded', 'Test card payment', 'the test payment'] as $words) {
        expect(str_contains($page, $words))->toBeFalse('the page still says "'.$words.'"');
    }

    expect(str_contains($ledger, 'Test card payment'))->toBeFalse('the ledger link still says "Test card payment"')
        ->and(str_contains($rules, 'This was a test.'))->toBeFalse('the result under the amount still says "This was a test."');
});

it('is reached from the unit\'s ledger', function () {
    $ledger = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/UnitLedger.vue'));

    expect($ledger)->toContain('/test-checkout')->and($ledger)->toContain('Card payment preview');
});
