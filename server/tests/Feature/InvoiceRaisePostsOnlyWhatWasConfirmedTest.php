<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Gemini\InvoiceRun;
use App\Services\Gemini\PlatformSettings;
use App\Support\PlatformPricing;
use Database\Seeders\BillingSeeder;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;
use Tests\Support\SecondSession;

/*
|--------------------------------------------------------------------------
| "Raise … for October at J$X?" posts October at J$X, or nothing (B076)
|--------------------------------------------------------------------------
|
| The preview quotes the invoice run's own draft, but a preview is read some
| time before it is confirmed, and the raise used to post whatever the next
| period came to AT THE MOMENT OF THE PRESS. So:
|
|   a line item or a price changed after the preview was opened, and the raise
|   posted a total the confirmation never quoted;
|
|   somebody else raised October in the meantime, and the press raised
|   NOVEMBER — a month nobody had confirmed — or, when both pressed at once,
|   the second died on the invoice number's unique key with a 500.
|
| Now the press carries the period and the total it confirmed, the run posts
| exactly that or refuses in words, and two raises for one client take turns.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $this->withoutVite();
});

afterEach(function () {
    PlatformPricing::forgetGuardRate();

    while (DB::connection('mysql')->transactionLevel() > 0) {
        DB::connection('mysql')->rollBack();
    }
});

/**
 * A client of this file's own, invoiced for September, inside a transaction.
 * 40 units × $300 + 2 guards × $4,500 = $21,000 a month.
 */
function confirmedRaiseClient(object $test, string $kingstonNow): void
{
    DB::connection('mysql')->beginTransaction();

    if (! DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->exists()) {
        Artisan::call('db:seed', ['--class' => BillingSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PlatformCatalogueSeeder::class, '--force' => true]);
    }

    $test->travelTo(Carbon::parse('2026-09-25 10:00:00', 'America/Jamaica'));

    DB::connection('mysql')->table('platform_rates')->where('key', '!=', PlatformPricing::GUARD_RATE_KEY)->update(['is_active' => false]);
    DB::connection('mysql')->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)
        ->update(['is_active' => true, 'basis' => 'guard', 'amount_minor' => 4_500_00]);
    DB::connection('mysql')->table('platform_rate_changes')->delete();
    PlatformPricing::forgetGuardRate();

    $test->client = 'confirmedraisetest';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $test->client, 'name' => 'Confirmed Raise Test Estate', 'receipt_prefix' => 'CRZ',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'key' => 'confirmedraisetest', 'name' => 'Confirmed Raise', 'price_per_unit_minor' => 300_00,
        'currency' => 'JMD', 'min_units' => 1, 'is_active' => true, 'sort' => 96,
    ]);

    Subscription::query()->create([
        'tenant_id' => $test->client, 'plan_id' => $plan->id, 'unit_count' => 40,
        'contracted_guards' => 2, 'status' => 'active', 'started_on' => '2026-09-01',
    ]);

    foreach ([1, 2] as $n) {
        Guard::create([
            'full_name' => 'Confirmed Raise Guard '.$n,
            'employee_number' => 'GS-CRZ'.$n,
            'psra_number' => 'PSRA-CRZ'.$n,
            'psra_expires_on' => '2027-09-24',
            'employment_type' => 'full_time',
            'status' => 'active',
            'tenant_id' => $test->client,
        ]);
    }

    $test->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    app(InvoiceRun::class)->raise($test->client, $test->director);

    $test->travelTo(Carbon::parse($kingstonNow, 'America/Jamaica'));
}

/** The preview's projection, as the screen holds it when "Raise and post" is pressed. */
function openedPreview(object $test): array
{
    $projection = [];

    $test->actingAs($test->director)
        ->get('/billing/preview/'.$test->client)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$projection) {
            $projection = $page->toArray()['props']['projection'];
        });

    return $projection;
}

function pressRaise(object $test, array $projection): Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->director)
        ->from('/billing/preview/'.$test->client)
        ->post('/billing/preview/'.$test->client.'/raise', [
            'period_start' => $projection['period_start'],
            'total_minor' => $projection['total_minor'],
        ]);
}

it('refuses a raise whose total changed after the preview was opened, and posts nothing', function () {
    confirmedRaiseClient($this, '2026-09-25 11:00:00');

    $opened = openedPreview($this);

    expect($opened)->toMatchArray(['period' => 'October 2026', 'period_start' => '2026-10-01', 'total_minor' => 21_000_00]);

    // Another director adds October's night patrol while the preview is open.
    app(PlatformSettings::class)->addLineItem($this->client, [
        'name' => 'Additional night patrol',
        'type' => 'addition',
        'amount' => '25000',
        'effective_from' => '2026-10-01',
    ], $this->director);

    pressRaise($this, $opened)
        ->assertRedirect('/billing/preview/'.$this->client)
        ->assertSessionHasErrors(['invoice' => 'The preview you confirmed quoted $21,000.00 for October 2026, but it now comes to $46,000.00: a price or a line item changed after it was opened. Nothing was posted. The preview is the current one — confirm it again if it is right.']);

    expect(Invoice::query()->where('tenant_id', $this->client)->whereDate('period_start', '2026-10-01')->exists())->toBeFalse();

    // Opened again, it quotes the new total, and that is what is posted.
    $reopened = openedPreview($this);

    pressRaise($this, $reopened)->assertSessionHasNoErrors();

    expect(Invoice::query()->where('tenant_id', $this->client)->whereDate('period_start', '2026-10-01')->sole()->total_minor)
        ->toBe(46_000_00);
});

it('refuses a raise for a month somebody else raised since, rather than raising the month after it', function () {
    // 5 October: October is next, and November would be only a month ahead.
    confirmedRaiseClient($this, '2026-10-05 11:00:00');

    $opened = openedPreview($this);

    expect($opened['period_start'])->toBe('2026-10-01');

    // Another operator raises October from their own screen.
    app(InvoiceRun::class)->raise($this->client, $this->director);

    pressRaise($this, $opened)
        ->assertSessionHasErrors(['invoice' => 'The preview you confirmed was for October 2026, but the next period to invoice for Confirmed Raise Test Estate is now November 2026. Nothing was posted. The preview is the current one — confirm it again if it is right.']);

    expect(Invoice::query()->where('tenant_id', $this->client)->whereDate('period_start', '2026-11-01')->exists())->toBeFalse()
        ->and(Invoice::query()->where('tenant_id', $this->client)->whereDate('period_start', '2026-10-01')->count())->toBe(1);
});

it('refuses a raise that carries no confirmation, and posts nothing', function () {
    confirmedRaiseClient($this, '2026-09-25 11:00:00');

    $this->actingAs($this->director)
        ->from('/billing/preview/'.$this->client)
        ->post('/billing/preview/'.$this->client.'/raise')
        ->assertSessionHasErrors('invoice');

    expect(Invoice::query()->where('tenant_id', $this->client)->whereDate('period_start', '2026-10-01')->exists())->toBeFalse();
});

it('sends the period and the total it quoted with the press', function () {
    $screen = (string) file_get_contents(resource_path('js/Pages/Gemini/Billing/Preview.vue'));

    expect($screen)->toContain('period_start: props.projection.period_start')
        ->and($screen)->toContain('total_minor: props.projection.total_minor');
});

it('makes a second raise for the same client wait for the first, instead of dying on the invoice number', function () {
    /*
     * COMMITTED, because the other operator is a second database session that has
     * to see the client. Cleaned up in the `finally`. The raise under test runs in
     * a transaction that is rolled back, so it leaves nothing in the books.
     */
    $this->travelTo(Carbon::parse('2031-05-10 10:00:00', 'America/Jamaica'));

    DB::connection('mysql')->table('tenants')->insert([
        'id' => 'raiseracetest', 'name' => 'Raise Race Test Estate', 'receipt_prefix' => 'RRT',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'key' => 'raiseracetest', 'name' => 'Raise Race', 'price_per_unit_minor' => 300_00,
        'currency' => 'JMD', 'min_units' => 1, 'is_active' => true, 'sort' => 95,
    ]);

    Subscription::query()->create([
        'tenant_id' => 'raiseracetest', 'plan_id' => $plan->id, 'unit_count' => 10,
        'contracted_guards' => 0, 'status' => 'active', 'started_on' => '2031-05-01',
    ]);

    // No `actingAs`: an audit entry names its actor, and this account is deleted afterwards.
    $director = User::factory()->create(['status' => 'active']);

    /*
     * THE OTHER OPERATOR presses "Raise and post" for the same client the moment
     * this raise has first read the client's invoices. If this raise holds the
     * client, the rival waits (and, one thread holding both ends, times out); if
     * it holds nothing, the rival raises May and commits, and this one then dies
     * on May's invoice number.
     */
    $rival = null;
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$rival, &$fired, $director): void {
        if ($fired || $query->connectionName !== 'mysql' || preg_match('/from [`"]?invoices[`"]? /i', $query->sql) !== 1) {
            return;
        }

        $fired = true;

        $rival = SecondSession::run(function () use ($director): string {
            try {
                app(InvoiceRun::class)->raise('raiseracetest', $director);

                return 'committed';
            } catch (QueryException) {
                return 'waited';
            }
        });
    });

    DB::connection('mysql')->beginTransaction();

    try {
        $ours = app(InvoiceRun::class)->raise('raiseracetest', $director);

        expect($fired)->toBeTrue()
            ->and($rival)->toBe('waited')
            ->and($ours->period_start->toDateString())->toBe('2031-05-01')
            ->and(Invoice::query()->where('tenant_id', 'raiseracetest')->count())->toBe(1);
    } finally {
        DB::connection('mysql')->rollBack();

        $invoices = DB::connection('mysql')->table('invoices')->where('tenant_id', 'raiseracetest')->pluck('id');
        DB::connection('mysql')->table('invoice_lines')->whereIn('invoice_id', $invoices)->delete();
        DB::connection('mysql')->table('invoices')->whereIn('id', $invoices)->delete();
        DB::connection('mysql')->table('subscriptions')->where('tenant_id', 'raiseracetest')->delete();
        DB::connection('mysql')->table('plans')->where('id', $plan->id)->delete();
        DB::connection('mysql')->table('tenants')->where('id', 'raiseracetest')->delete();
        $director->delete();
    }
});
