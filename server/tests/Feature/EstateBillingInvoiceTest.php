<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An estate's own invoice, itemised — board 40's "View" (12 §2, Wave 4)
|--------------------------------------------------------------------------
|
| A CENTRAL RECORD READ FROM THE ESTATE'S HOSTNAME. The route proves this
| estate owns the invoice; another client's invoice id is a 404, the same as an
| id that does not exist, so guessing ids tells an estate nothing.
|
| THE INVOICE AS ISSUED. Every line, the subscription lines marked, and credit
| notes shown beside the total rather than netted into it.
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
});

/** An issued invoice with a subscription line and a guard add-on line. */
function estateInvoice(string $tenantKey, string $reference): int
{
    $central = DB::connection('mysql');
    $now = Carbon::now();

    $id = (int) $central->table('invoices')->insertGetId([
        'tenant_id' => $tenantKey,
        'reference' => $reference,
        'period' => 'Aug 2026',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'total_minor' => 171_000_00,
        'currency' => 'JMD',
        'due_on' => '2026-08-15',
        'status' => 'issued',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $central->table('invoice_lines')->insert([
        [
            'invoice_id' => $id, 'description' => 'Premium subscription', 'quantity' => 450,
            'unit_price_minor' => 340_00, 'total_minor' => 153_000_00, 'currency' => 'JMD',
            'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'invoice_id' => $id, 'description' => 'Security Provider add-on', 'quantity' => 6,
            'unit_price_minor' => 3_000_00, 'total_minor' => 18_000_00, 'currency' => 'JMD',
            'created_at' => $now, 'updated_at' => $now,
        ],
    ]);

    return $id;
}

it('shows this estate its own invoice, whole, and 404s every other estate\'s', function () {
    $own = estateInvoice(FacilitiesFixture::ESTATE, 'FT-INV-TEST-0826');

    $otherTenant = (string) (DB::connection('mysql')->table('tenants')
        ->where('id', '!=', FacilitiesFixture::ESTATE)
        ->value('id') ?? 'someotherestate');

    $theirs = estateInvoice($otherTenant, 'OT-INV-TEST-0826');

    DB::connection('mysql')->table('credit_notes')->insert([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'invoice_id' => $own,
        'reference' => 'FT-INV-TEST-0826-CN1',
        'amount_minor' => 1_000_00,
        'reason' => 'Two units billed before handover',
        'issued_on' => '2026-08-20',
        'issued_by_id' => null,
        'issued_by_name' => 'Gemini Accounts',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$own))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Settings/BillingInvoice')
            // One number, the one it was issued with (BIL-B-03, register DR-155), never a second derived from its period.
            ->where('invoice.number', 'FT-INV-TEST-0826')
            ->where('invoice.reference', 'FT-INV-TEST-0826')
            ->has('invoice.lines', 2)
            ->where('invoice.lines.0.is_subscription', true)
            ->where('invoice.lines.1.is_subscription', false)
            ->where('invoice.total', '$171,000.00')
            ->where('invoice.subscription_total', '$153,000.00')
            ->where('invoice.credited', '$1,000.00')
            ->where('invoice.payable', '$170,000.00')
            ->has('invoice.notes', 1));

    // ANOTHER CLIENT'S INVOICE IS A 404, not a 403 that confirms it exists.
    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$theirs))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$theirs.'/pdf'))
        ->assertNotFound();

    // What the estate pays is a Settings read; a role without Settings is refused.
    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$own))
        ->assertForbidden();

    // The PDF is an export, and every export is on the record.
    $before = DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count();

    $response = $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$own.'/pdf'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count())->toBe($before + 1);
});

/*
|--------------------------------------------------------------------------
| Every link on the two billing screens is rooted where the estate is served
|--------------------------------------------------------------------------
|
| Bug hunt C04 and B055. Production gives each estate its own hostname and
| local serves them all from one host with the estate in the path
| (`/estate/{tenant}/...`, routes/tenant.php). Board 40's "View", the
| itemised invoice's "Download PDF" and its way back to Billing were written
| as root paths, so in the local shape each was a 404 on the central host
| while every sibling link — the settings column among them — was built from
| the estate's own root. The environment is switched to local for the length
| of the request, which is the only thing the shape is decided by.
|
*/

/** Runs `$work` with the application reporting itself as local, and always puts it back. */
function billingLinksAsLocal(callable $work): mixed
{
    $was = app()->environment();

    app()->detectEnvironment(static fn (): string => 'local');

    try {
        return $work();
    } finally {
        app()->detectEnvironment(static fn (): string => $was);
    }
}

it('roots board 40\'s invoice links, the PDF and the way back at the estate in the local path shape', function () {
    // Board 40 lists invoices only for an estate with a subscription; this one
    // is given one inside the transaction, on a plan of its own.
    $plan = Plan::query()->firstOrCreate(
        ['key' => 'billing-links-test'],
        ['name' => 'Links Test', 'price_per_unit_minor' => 340_00, 'currency' => 'JMD', 'min_units' => 1, 'is_active' => false, 'sort' => 99],
    );

    Subscription::query()->updateOrCreate(
        ['tenant_id' => FacilitiesFixture::ESTATE],
        ['plan_id' => $plan->id, 'unit_count' => 5, 'status' => 'active', 'started_on' => '2026-01-01', 'renews_on' => '2026-10-01', 'term_months' => 12],
    );

    $own = estateInvoice(FacilitiesFixture::ESTATE, 'FT-INV-LINK-0826');
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $root = '/estate/'.FacilitiesFixture::ESTATE;

    billingLinksAsLocal(function () use ($admin, $own, $root): void {
        $this->actingAs($admin)
            ->get(FacilitiesFixture::url('/settings/billing'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($own, $root): void {
                $props = $page->toArray()['props'];

                expect($props['invoices'])->not->toBe([]);

                // The sibling links already were; the invoice links now are too.
                foreach ($props['sections'] as $section) {
                    expect($section['href'])->toStartWith($root.'/settings/');
                }

                foreach ($props['invoices'] as $invoice) {
                    expect($invoice['view_href'])->toStartWith($root.'/settings/billing/invoices/');
                }

                expect(array_column($props['invoices'], 'view_href'))->toContain($root.'/settings/billing/invoices/'.$own);
            });

        $this->actingAs($admin)
            ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$own))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pdfHref', $root.'/settings/billing/invoices/'.$own.'/pdf')
                ->where('billingHref', $root.'/settings/billing'));
    });

    // And on an estate's own hostname — production's shape — the same links are root paths.
    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$own))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pdfHref', '/settings/billing/invoices/'.$own.'/pdf')
            ->where('billingHref', '/settings/billing'));

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'invoices',
            fn ($invoices): bool => collect($invoices)->contains('view_href', '/settings/billing/invoices/'.$own),
        ));
});

it('builds no estate screen link in the shell or on the invoice page from a hard-coded root path', function () {
    /*
     * The two Vue halves of the same fault, read as source because this suite
     * has no browser: "My profile" in the account menu of every estate screen,
     * and the invoice page's back link. Each is now either the server's
     * estate-rooted href or cut from the URL that served the page.
     */
    $shell = (string) file_get_contents(resource_path('js/Layouts/EstateConsole.vue'));
    $invoice = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/BillingInvoice.vue'));

    expect($shell)->not->toContain("router.get('/settings/profile')")
        ->and($shell)->toContain('router.get(`${consoleRoot.value}/settings/profile`)')

        // And the root is found on the estate's HOME page too, "/estate/{key}"
        // with nothing after it, where cutting at the next "/" found nothing
        // and every link built from it fell back to the central host's root.
        ->and($shell)->not->toContain("url.indexOf('/', url.startsWith('/estate/')")
        ->and($shell)->toContain('.match(/^\/estate\/[^/?#]+/)')
        ->and($invoice)->not->toContain('href="/settings/billing"')
        // The shell's back control in words since the Settings pass (12 Oct), on the same server-built href.
        ->and($invoice)->toContain(':back="{ href: billingHref, label: \'Back to Billing & subscription\' }"');
});
