<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Estate\Charge;
use App\Models\Estate\DunningNotice;
use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\Payment;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\ActivityLog;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\Estate\Residents;
use App\Support\NamedActs;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The estate Admin Assistant sees no money it does not need — M10 §5
|--------------------------------------------------------------------------
|
| "The Admin Assistant must not see anything about what anyone pays or what
| the company earns ... the estate's dues totals and arrears amounts where the
| assistant role does not need them ... A field hidden in the interface but
| returned by the API is not hidden."
|
| SINCE M53 (8 Oct 2026, §1 Law 4) THE SEEDED ADMIN ASSISTANT HOLDS NO MONEY
| MODULE AT ALL — no nav item, no page, no figure — and the first test below
| says so. What this file proved of it now holds for the role it described: a
| BOOKKEEPER an estate builds in its roles editor, with the assistant's old
| cells (`FacilitiesFixture::bookkeeper()`). Every rule below is that role's.
|
| THE LINE IS BETWEEN ONE LOT AND THE ESTATE. The bookkeeper holds Entry on Dues
| & ledger, Payments and Accounting — board 24 drew those cells so the office
| can key a receipt, post a charge and record a bill — and every one of those
| acts is done against ONE household or ONE supplier, whose own figures it has
| to see. What it never needs is the estate's position: the arrears total, the
| ageing, the phase bars, a month's takings, the chart's balances, every
| household's balance side by side, or what every supplier was paid.
|
| ONE PREDICATE DECIDES IT, `Dues::maySeeEstatePosition()` — the module's view
| AND `estate.reports.view` — asked of permissions and never of a role name, so
| §4's dynamic roles inherit it from their cells. The same predicate closes the
| D-010 leak the plan found: the Property Manager, the Secretary and the
| estate's Head of Security hold the dashboard and no ledger, and were shipped
| the estate's arrears total and every phase bar anyway.
|
| NOR WHICH HOUSEHOLDS ARE BEHIND. A list of who is in arrears is about what
| they pay with every figure struck out, so for a viewer who fails the rule the
| arrears register, the plan register and the dunning log send no rows at all,
| the activity feed carries no reminder, and the residents list and claims
| queue stop marking restricted households. What stays is one household at a
| time: its own ledger, its own plan, and the verdict on its own screen.
|
| EVERY ASSERTION IS ON THE SERIALISED PAYLOAD, the way `GuardSeesNoMoneyTest`
| works, because the guarantee is about what crosses the wire and not about
| what a template happens to draw.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->money = assistantMoneyLot();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A lot of this file's own, with a charge ninety days overdue and a payment on
 * it — made once per process, because the ledger is append-only and the estate
 * database is committed.
 *
 * WITHOUT IT EVERY "IS WITHHELD" BELOW COULD PASS FOR THE WRONG REASON. The
 * fixture's receivable is otherwise near empty, and a null total on an estate
 * owed nothing proves nothing; with this lot in arrears, the same request as the
 * Treasurer carries a real figure, so the null is the role's and not the data's.
 *
 * AND A REMINDER AND A PLAN, for the same reason about the lists. The fixture
 * seeds no dunning log and no plan, so an empty log would prove nothing; with
 * one of each on this lot, the Treasurer's copy of the screen has a row the
 * Admin Assistant's does not. The reminder is logged each process so it is the
 * newest thing in the feed; the plan is drafted once, because `draft()` refuses
 * a lot that already has one and the estate database outlives the process.
 *
 * @return array{unit_id: int, charge_id: int, journal_id: int, receipt_no: string}
 */
function assistantMoneyLot(): array
{
    static $made = null;

    if ($made !== null) {
        return $made;
    }

    $unit = FacilitiesFixture::scratchUnit('Lot 954');
    $dues = app(Dues::class);

    $charge = $dues->charge(
        unit: $unit,
        amount: Money::of('10000.00', 'JMD'),
        description: 'M10 §5 fixture — annual assessment',
        dueOn: now()->subDays(100),
    );

    $payment = $dues->receive(
        unit: $unit,
        amount: Money::of('6200.00', 'JMD'),
        method: Payment::CASH,
        receivedAt: now(),
    );

    // Logged as `Collections::send()` logs one, without a template this
    // fixture estate does not have — the id is nullable for exactly that.
    DunningNotice::create([
        'unit_id' => $unit->id,
        'template_label' => 'Reminder 1',
        'stage' => 1,
        'channel' => 'email',
        'subject' => 'M10 §5 fixture reminder',
        'body' => 'A reminder logged for the Admin Assistant suite.',
        'delivery_state' => DunningNotice::DELIVERED,
        'sent_at' => now(),
        'sent_by_name' => 'M10 §5 fixture',
    ]);

    if (! PaymentPlan::query()->where('unit_id', $unit->id)->exists()) {
        app(Collections::class)->draft(unit: $unit, instalments: 4, startsOn: now()->addMonth()->startOfMonth());
    }

    return $made = [
        'unit_id' => $unit->id,
        'charge_id' => $charge->id,
        'journal_id' => (int) Journal::query()->where('reference', $charge->journal_ref)->value('id'),
        'receipt_no' => (string) $payment->receipt_no,
    ];
}

/**
 * The whole Inertia props of one estate screen, as the browser receives them.
 *
 * @return array<string, mixed>
 */
function assistantMoneyProps(object $test, User $viewer, string $path): array
{
    $props = [];

    $test->actingAs($viewer)
        ->get(FacilitiesFixture::url($path))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return $props;
}

/**
 * The activity feed exactly as `viewer` is served it, two hundred deep.
 *
 * INSIDE THE ESTATE, because the feed reads the central incident log filtered
 * to `tenant()`, and outside a request nothing has initialised tenancy. The
 * fixture's connection is re-applied afterwards: ending tenancy points the
 * default connection back at the platform.
 *
 * @return list<array<string, mixed>>
 */
function assistantMoneyFeed(User $viewer): array
{
    /*
     * Deep enough that this file's own receipt and reminder are always in it. They are made once per process, and in
     * a full run in random order other files can log a few hundred newer rows on the same fixture estate before these
     * tests read the feed: at 200 the receipt fell off it (found by M24's suite, 1 Oct). What is asserted is which rows
     * a role sees, never how many there are, so the depth changes nothing else.
     */
    $rows = FacilitiesFixture::platform()->run(
        static fn (): array => app(ActivityLog::class)->feed(2000, 0, $viewer)['rows'],
    );

    FacilitiesFixture::boot();

    return $rows;
}

/**
 * Every place in a payload a figure could arrive — whatever it is called.
 *
 * TWO SHAPES, BOTH CAUGHT. A `*_minor` key holding anything but null is an
 * amount by this codebase's own convention. And a STRING that prints money —
 * "$6,200", "J$ 12,400" — is an amount under any key at all, which is the
 * mistake no key-name check can see: a subtitle or a detail that interpolates
 * a figure. `GuardSeesNoMoneyTest` keeps the same second line for the same
 * reason.
 *
 * @param  array<array-key, mixed>  $payload
 * @return list<string> what leaked, with where
 */
function assistantMoneyLeaks(array $payload, string $path = ''): array
{
    $leaks = [];

    foreach ($payload as $key => $value) {
        $here = $path === '' ? (string) $key : $path.'.'.$key;

        if (is_array($value)) {
            array_push($leaks, ...assistantMoneyLeaks($value, $here));

            continue;
        }

        if (is_string($key) && str_ends_with($key, '_minor') && $value !== null) {
            $leaks[] = $here.' = '.var_export($value, true);
        }

        if (is_string($value) && preg_match('/J?\$\s?\d/', $value) === 1) {
            $leaks[] = $here.' = '.$value;
        }
    }

    return $leaks;
}

/* ------------------------------------------------------------------ */
/* the rule, against the seeded matrix */
/* ------------------------------------------------------------------ */

it('decides "may see the estate\'s money" from two permissions, landing on all eight estate roles as the plan derived', function () {
    /*
     * The whole matrix, not a sample. A cell that moves — here or through §4's
     * editor — changes a row of this table, and the table says which.
     */
    $expected = [
        Role::COMMUNITY_SUPER_ADMIN => true,
        Role::PRESIDENT => true,
        Role::VICE_PRESIDENT => true,
        Role::SECRETARY => false,
        Role::PROPERTY_MANAGER => false,
        Role::TREASURER => true,
        Role::ESTATE_ADMIN_ASSISTANT => false,
        Role::ESTATE_HEAD_OF_SECURITY => false,
    ];

    foreach ($expected as $roleName => $maySee) {
        $viewer = FacilitiesFixture::viewer($roleName);

        foreach (['dues_ledger', 'payments', 'accounting_posting'] as $module) {
            expect(Dues::maySeeEstatePosition($viewer, $module))
                ->toBe($maySee, "{$roleName} on {$module}");
        }
    }
});

it('gives the seeded Admin Assistant no money module at all (M53 §1 Law 4): no nav item, no page, no figure', function () {
    $seeded = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);

    // Not one verb on any of the five money modules.
    foreach (FacilitiesFixture::BOOKKEEPER_MODULES as $module) {
        foreach (['view', 'create', 'update', 'delete', 'export'] as $verb) {
            expect($seeded->can("estate.{$module}.{$verb}"))->toBeFalse("estate.{$module}.{$verb}");
        }
    }

    // Every money page refuses it — Bank reconciliation and the bills first, which is where the leak was.
    foreach ([
        '/accounting/reconciliation', '/accounting/bills', '/accounting/vendors', '/accounting/chart-of-accounts',
        '/finance/arrears', '/finance/receipts', '/finance/units/'.$this->money['unit_id'], '/finance/payment-plans',
    ] as $path) {
        $this->actingAs($seeded)->get(FacilitiesFixture::url($path))->assertForbidden();
    }

    // And the console draws no money item for it to click.
    $nav = collect(assistantMoneyProps($this, $seeded, '/')['estateNav'] ?? [])->pluck('key')->all();

    expect($nav)->not->toContain('dues_ledger')
        ->and($nav)->not->toContain('accounting')
        ->and($nav)->not->toContain('payroll');
});

it('rests on cells a bookkeeper an estate may build really holds: the ledger for data entry, and no Reports at all', function () {
    $assistant = FacilitiesFixture::bookkeeper();

    // Entry — what recording a receipt or a bill needs.
    foreach (['dues_ledger', 'payments', 'accounting_posting'] as $module) {
        foreach (['view', 'create', 'update'] as $verb) {
            expect($assistant->can("estate.{$module}.{$verb}"))->toBeTrue("estate.{$module}.{$verb}");
        }

        // And never a file of it, nor the approval that makes a figure final —
        // which on Dues & ledger and Accounting is several named acts since
        // M11 B3, each asked by name rather than by a verb that no longer exists.
        foreach (['export', 'approve', 'configure'] as $verb) {
            $names = NamedActs::under(Console::Estate, $module, $verb);

            foreach ($names === [] ? [$verb] : $names as $name) {
                expect($assistant->can("estate.{$module}.{$name}"))->toBeFalse("estate.{$module}.{$name}");
            }
        }
    }

    // The half that withholds the estate's own picture. Every verb, so a verb
    // added to Reports later fails here until somebody decides about it.
    foreach (['view', 'create', 'update', 'delete', 'approve', 'export', 'configure'] as $verb) {
        expect($assistant->can("estate.reports.{$verb}"))->toBeFalse("estate.reports.{$verb}");
    }
});

/* ------------------------------------------------------------------ */
/* the dashboard */
/* ------------------------------------------------------------------ */

it('sends the seeded Admin Assistant no dues tile and no money at all on its dashboard, not even a link to the register', function () {
    $props = assistantMoneyProps($this, FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT), '/');

    expect(array_column($props['kpis'], 'key'))->not->toContain('dues')
        ->and($props['arrears']['total_minor'] ?? null)->toBeNull()
        ->and($props['arrears']['phases'] ?? [])->toBe([])
        ->and($props['arrears']['ledgerHref'] ?? null)->toBeNull()
        ->and(assistantMoneyLeaks($props))->toBe([]);
});

it('sends a bookkeeper no dues tile, no arrears total and no phase bars, and no panel to explain them', function () {
    $props = assistantMoneyProps($this, FacilitiesFixture::bookkeeper(), '/');

    expect(array_column($props['kpis'], 'key'))->not->toContain('dues')
        ->and($props['arrears']['total_minor'])->toBeNull()
        ->and($props['arrears']['phases'])->toBe([])
        ->and($props['arrears']['positionWithheld'])->toBeTrue()

        // M96 level 3 (Hassam, 12 October): what a role can never read is absent, not explained.
        ->and(array_key_exists('positionReason', $props['arrears']))->toBeFalse()

        // The register itself is still theirs to open — they find a lot there.
        ->and($props['arrears']['ledgerHref'])->not->toBeNull()

        // And nothing else on the screen carries a figure, under any key.
        ->and(assistantMoneyLeaks($props))->toBe([]);
});

it('withholds the same figures from the Property Manager, the Secretary and the Head of Security, who hold no ledger (D-010)', function () {
    foreach ([Role::PROPERTY_MANAGER, Role::SECRETARY, Role::ESTATE_HEAD_OF_SECURITY] as $roleName) {
        $props = assistantMoneyProps($this, FacilitiesFixture::viewer($roleName), '/');

        expect(array_column($props['kpis'], 'key'))->not->toContain('dues', $roleName)
            ->and($props['arrears']['total_minor'])->toBeNull($roleName)
            ->and($props['arrears']['phases'])->toBe([], $roleName)
            ->and($props['arrears']['positionWithheld'])->toBeTrue($roleName)

            // No ledger at all, so no link to it either — the reason stands instead.
            ->and($props['arrears']['ledgerHref'])->toBeNull($roleName)
            ->and(assistantMoneyLeaks($props))->toBe([], $roleName);
    }
});

it('still shows the estate\'s position to the roles that hold both halves', function () {
    foreach ([Role::TREASURER, Role::PRESIDENT, Role::VICE_PRESIDENT, Role::COMMUNITY_SUPER_ADMIN] as $roleName) {
        $props = assistantMoneyProps($this, FacilitiesFixture::viewer($roleName), '/');

        $tile = collect($props['kpis'])->firstWhere('key', 'dues');

        expect($tile)->not->toBeNull($roleName)
            ->and($tile['value_minor'])->toBeGreaterThan(0, $roleName)
            ->and($props['arrears']['total_minor'])->toBe($tile['value_minor'], $roleName)
            ->and($props['arrears']['phases'])->not->toBe([], $roleName)
            ->and($props['arrears']['positionWithheld'])->toBeFalse($roleName);
    }
});

/* ------------------------------------------------------------------ */
/* the activity feed */
/* ------------------------------------------------------------------ */

it('tells a bookkeeper a receipt was issued without saying how much, and the Treasurer both', function () {
    $receipt = $this->money['receipt_no'];

    $detailFor = static function (User $viewer) use ($receipt): ?string {
        foreach (assistantMoneyFeed($viewer) as $row) {
            if ($row['icon'] === 'payment' && str_contains($row['detail'], $receipt)) {
                return $row['detail'];
            }
        }

        return null;
    };

    $assistantSees = $detailFor(FacilitiesFixture::bookkeeper());
    $treasurerSees = $detailFor(FacilitiesFixture::viewer(Role::TREASURER));

    expect($assistantSees)->toBe('Receipt '.$receipt)
        ->and($treasurerSees)->toBe('$6,200 · receipt '.$receipt);

    // And over the wire: the full log and the dashboard panel, whole.
    $assistant = FacilitiesFixture::bookkeeper();

    expect(assistantMoneyLeaks(assistantMoneyProps($this, $assistant, '/activity')))->toBe([])
        ->and(assistantMoneyLeaks(assistantMoneyProps($this, $assistant, '/')['activity']))->toBe([]);
});

it('gives a role with no Payments no payment row, and no role without the estate\'s position a reminder row', function () {
    // The seeded Admin Assistant among them since M53: it holds no Payments either.
    foreach ([Role::PROPERTY_MANAGER, Role::SECRETARY, Role::ESTATE_HEAD_OF_SECURITY, Role::ESTATE_ADMIN_ASSISTANT] as $roleName) {
        $icons = array_column(assistantMoneyFeed(FacilitiesFixture::viewer($roleName)), 'icon');

        // "Lot 954 paid" is a household's financial position with or without the figure.
        expect($icons)->not->toContain('payment', $roleName)
            ->and($icons)->not->toContain('dunning', $roleName);
    }

    /*
     * A BOOKKEEPER HOLDS THE LEDGER AND STILL GETS NO REMINDER ROW.
     * "Reminder 1 sent — Lot 954" says who is behind, and a feed of them is the
     * arrears register one row at a time. Its payment rows stay: a receipt is
     * one household's, and issuing them is the job.
     */
    $assistantIcons = array_column(assistantMoneyFeed(FacilitiesFixture::bookkeeper()), 'icon');

    expect($assistantIcons)->toContain('payment')
        ->and($assistantIcons)->not->toContain('dunning');

    // The Treasurer reads the reminder this file logged, so the absence above
    // is the role's and not an empty log's.
    $treasurerReminders = array_filter(
        assistantMoneyFeed(FacilitiesFixture::viewer(Role::TREASURER)),
        static fn (array $row): bool => $row['icon'] === 'dunning' && str_contains($row['title'], 'Lot 954'),
    );

    expect($treasurerReminders)->not->toBe([]);

    // The existing callers, which pass no viewer, still read the whole feed.
    $whole = FacilitiesFixture::platform()->run(static fn (): array => app(ActivityLog::class)->feed(200)['rows']);
    FacilitiesFixture::boot();

    expect(array_column($whole, 'icon'))->toContain('payment');
});

/* ------------------------------------------------------------------ */
/* the API: records addressed by id */
/* ------------------------------------------------------------------ */

it('refuses the Admin Assistant and the Head of Security the charge and journal records, and serves the Treasurer', function () {
    $charge = FacilitiesFixture::url('/records/charges/'.$this->money['charge_id']);
    $journal = FacilitiesFixture::url('/records/journals/'.$this->money['journal_id']);

    // Holds the ledger, not the estate's position: refused in the controller. (The seeded Admin Assistant holds no
    // ledger since M53 and is refused at the route; a bookkeeper an estate builds is the case the controller answers.)
    $assistant = FacilitiesFixture::bookkeeper();

    $this->actingAs($assistant)->getJson($charge)->assertForbidden();
    $this->actingAs($assistant)->getJson($journal)->assertForbidden();

    // Holds neither module: refused at the route.
    $security = FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);

    $this->actingAs($security)->getJson($charge)->assertForbidden();
    $this->actingAs($security)->getJson($journal)->assertForbidden();

    // The refusal is the same for an id that does not exist — it is not an
    // oracle for which charges are on the ledger.
    $this->actingAs($assistant)->getJson(FacilitiesFixture::url('/records/charges/99999999'))->assertForbidden();

    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)->getJson($charge)->assertOk()->assertJsonPath('amount_minor', 1_000_000);
    $this->actingAs($treasurer)->getJson($journal)->assertOk();
});

it('leaves the record paths that carry no money, and the isolation gate probes, exactly as they were', function () {
    $unit = FacilitiesFixture::url('/records/units/'.$this->money['unit_id']);

    foreach ([Role::ESTATE_HEAD_OF_SECURITY, Role::ESTATE_ADMIN_ASSISTANT, Role::PROPERTY_MANAGER] as $roleName) {
        $this->actingAs(FacilitiesFixture::viewer($roleName))->getJson($unit)->assertOk();
    }
});

/* ------------------------------------------------------------------ */
/* the registers */
/* ------------------------------------------------------------------ */

it('sends the Admin Assistant no arrears register at all — no rows, no strip, no filters — and says why without naming anyone', function () {
    $assistant = FacilitiesFixture::bookkeeper();

    /*
     * EVERY FILTER AT ONCE, and every one ignored. A phase chip or a 90+ chip
     * over a list the server declined to send would only be the same question
     * asked again; the page gets back the unfiltered, empty answer.
     */
    foreach (['/finance/arrears', '/finance/arrears?overdue=1', '/finance/arrears?phase=Phase+9&overdue=1'] as $path) {
        $props = assistantMoneyProps($this, $assistant, $path);

        expect($props['rows'])->toBe([], $path)
            ->and($props['ageing'])->toBe([], $path)
            ->and($props['phases'])->toBe([], $path)
            ->and($props['filters'])->toBe(['phase' => '', 'overdue' => false], $path)
            ->and($props['positionWithheld'])->toBeTrue($path)
            ->and($props['positionReason'])->toBe(Dues::ARREARS_WITHHELD, $path)

            // Neither control is live over a file the route would refuse.
            ->and($props['canExport'])->toBeFalse($path)
            ->and(assistantMoneyLeaks($props))->toBe([], $path);

        // Not a lot reference, a household or a resident anywhere in the answer.
        expect(json_encode($props))->not->toContain('Lot 954', $path);
    }

    // The export stays refused, and nothing claims a file left.
    $this->actingAs($assistant)->get(FacilitiesFixture::url('/finance/arrears/export'))->assertForbidden();

    // The Treasurer's register carries this lot, its figure and its bucket — found by
    // searching for it, since M11 A2 pages the register and a scratch lot's id puts it
    // past the first page on an estate that has been in use.
    $treasurer = assistantMoneyProps($this, FacilitiesFixture::viewer(Role::TREASURER), '/finance/arrears?q='.rawurlencode('Lot 954'));
    $row = collect($treasurer['rows'])->firstWhere('id', $this->money['unit_id']);

    expect($row)->not->toBeNull()
        ->and($row['balance_minor'])->toBe(app(Dues::class)->balanceOf(Unit::query()->findOrFail($this->money['unit_id']))->getMinorAmount()->toInt())
        ->and($row['bucket'])->toBe('d90')
        ->and($treasurer['positionWithheld'])->toBeFalse()
        ->and(array_column($treasurer['ageing'], 'key'))->toBe(['total', 'current', 'd30', 'd60', 'd90']);

    // And its 90+ chip still narrows.
    $overdue = assistantMoneyProps($this, FacilitiesFixture::viewer(Role::TREASURER), '/finance/arrears?overdue=1');

    expect($overdue['filters']['overdue'])->toBeTrue()
        ->and(array_unique(array_column($overdue['rows'], 'bucket')))->toBe(['d90']);
});

it('keeps the receipt sequence for the Admin Assistant and withholds every amount and the month\'s takings', function () {
    $props = assistantMoneyProps($this, FacilitiesFixture::bookkeeper(), '/finance/receipts');

    $mine = collect($props['rows'])->firstWhere('receipt_no', $this->money['receipt_no']);

    expect($mine)->not->toBeNull()
        ->and(array_column($props['kpis'], 'key'))->toBe(['issued', 'gaps'])
        ->and($props['positionWithheld'])->toBeTrue();

    foreach ($props['rows'] as $row) {
        // No amount, and no id of the entry behind it — the register links to no entry record at all (B030).
        expect($row['amount'])->toBeNull()
            ->and($row['amount_minor'])->toBeNull()
            ->and($row)->not->toHaveKey('journal_id');
    }

    expect(assistantMoneyLeaks($props))->toBe([]);

    $treasurer = assistantMoneyProps($this, FacilitiesFixture::viewer(Role::TREASURER), '/finance/receipts');

    expect(collect($treasurer['rows'])->firstWhere('receipt_no', $this->money['receipt_no'])['amount_minor'])->toBe(620_000)
        ->and(array_column($treasurer['kpis'], 'key'))->toContain('month');
});

it('shows the Admin Assistant every account to post against and none of the chart\'s balances', function () {
    $props = assistantMoneyProps($this, FacilitiesFixture::bookkeeper(), '/accounting/chart-of-accounts');

    $rows = collect($props['groups'])->flatMap(static fn (array $group): array => $group['rows']);

    // 1200 IS the estate's total arrears. The code is theirs; the figure is not.
    expect($rows->pluck('code'))->toContain('1200')
        ->and($rows->pluck('balance_minor')->filter(static fn ($v): bool => $v !== null)->all())->toBe([])
        ->and($props['kpis'])->toBe([])
        ->and($props['positionWithheld'])->toBeTrue()
        ->and(assistantMoneyLeaks($props))->toBe([]);

    $treasurer = assistantMoneyProps($this, FacilitiesFixture::viewer(Role::TREASURER), '/accounting/chart-of-accounts');

    expect(array_column($treasurer['kpis'], 'key'))->toBe(['cash', 'receivable', 'payable', 'reserve']);
});

it('shows a bookkeeper and the Admin Assistant no household\'s balance on a list, and says why in words that are true about them', function () {
    // The seeded Admin Assistant holds no ledger since M53: no column, no figure, and the words say the ledger.
    $seeded = assistantMoneyProps($this, FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT), '/residents?view=households');

    expect($seeded['money_visible'])->toBeFalse()
        ->and($seeded['money_reason'])->not->toBe(Residents::LIST_POSITION_WITHHELD)
        ->and(assistantMoneyLeaks($seeded))->toBe([]);

    // The Households tab is the list with a balance on it (M32 F1 made People the register's default).
    $props = assistantMoneyProps($this, FacilitiesFixture::bookkeeper(), '/residents?view=households');

    expect($props['money_visible'])->toBeFalse()
        ->and($props['money_reason'])->toBe(Residents::LIST_POSITION_WITHHELD)
        ->and($props['table']['sortable'])->not->toContain('balance');

    foreach ($props['rows'] as $row) {
        expect($row['standing']['balance_minor'])->toBeNull()
            ->and($row['standing']['bucket'])->toBeNull();
    }

    expect(assistantMoneyLeaks($props))->toBe([]);

    // The President keeps the column.
    expect(assistantMoneyProps($this, FacilitiesFixture::viewer(Role::PRESIDENT), '/residents?view=households')['money_visible'])->toBeTrue();

    // And the People tab's rows carry no money for anybody, the President included: a person is not an account.
    foreach ([Role::ESTATE_ADMIN_ASSISTANT, Role::PRESIDENT] as $roleName) {
        $people = assistantMoneyProps($this, FacilitiesFixture::viewer($roleName), '/residents');

        expect($people['view'])->toBe('people', $roleName)
            ->and($people['money_visible'])->toBeFalse($roleName)
            ->and(assistantMoneyLeaks(['rows' => $people['rows']]))->toBe([], $roleName);
    }

    // The Admin Assistant's whole People page, every prop on it, as the Households page above.
    expect(assistantMoneyLeaks(assistantMoneyProps($this, FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT), '/residents')))->toBe([]);
});

it('stops marking restricted households on a list for every role that fails the rule, and keeps the verdict on the household\'s own screen', function () {
    /*
     * A household of this file's own, restricted for this test only. The
     * estate database is committed, so the flag is put back in `finally` —
     * a restricted household left behind would be held at a gate in some
     * other suite's fixture.
     */
    $unit = FacilitiesFixture::scratchUnit('Lot 955');
    $household = Household::query()->firstOrCreate(
        ['unit_id' => $unit->id],
        ['name' => 'Lot 955 household', 'access_restricted' => false],
    );

    $household->forceFill(['access_restricted' => true])->save();

    // Somebody lives there, or the Households tab does not list it (M87 §1.4: a household is one with a resident).
    $resident = DB::connection('tenant')->table('residents')->insertGetId(['household_id' => $household->id, 'full_name' => 'Resident of Lot 955', 'relationship' => 'owner', 'is_primary' => true, 'status' => 'verified', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    try {
        $rowFor = function (User $viewer) use ($unit): array {
            $props = assistantMoneyProps($this, $viewer, '/residents?view=households&q='.urlencode('Lot 955'));
            $row = collect($props['rows'])->firstWhere('unit_id', $unit->id);

            expect($row)->not->toBeNull();

            return [$row, $props];
        };

        // The Treasurer may read households' standing together, and is told.
        [$treasurerRow] = $rowFor(FacilitiesFixture::viewer(Role::TREASURER));

        expect($treasurerRow['standing']['restricted'])->toBeTrue()
            ->and($treasurerRow['standing']['label'])->toBe(Residents::RESTRICTED_LABEL);

        /*
         * EVERY ROLE THAT FAILS THE RULE, not only the Admin Assistant. The
         * restricted row reads exactly as an unrestricted one — no amber, no
         * verdict, no flag — because on a list the word IS who is behind.
         */
        foreach ([Role::ESTATE_ADMIN_ASSISTANT, Role::PROPERTY_MANAGER, Role::SECRETARY, Role::ESTATE_HEAD_OF_SECURITY] as $roleName) {
            $viewer = FacilitiesFixture::viewer($roleName);
            [$row, $props] = $rowFor($viewer);

            expect($row['standing']['restricted'])->toBeFalse($roleName)
                ->and($row['standing']['tone'])->toBe('neutral', $roleName)
                ->and($row['standing']['label'])->toBe(Residents::WITHHELD_LABEL, $roleName)
                ->and($row['standing']['balance_minor'])->toBeNull($roleName)
                ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain(Residents::RESTRICTED_LABEL, $roleName);

            // One household in front of them, and the verdict is theirs to read
            // — the same words a guard is given at the gate.
            $detail = assistantMoneyProps($this, $viewer, '/residents/'.$treasurerRow['unit_slug']);

            expect($detail['standing']['restricted'])->toBeTrue($roleName)
                ->and($detail['standing']['label'])->toBe(Residents::RESTRICTED_LABEL, $roleName);
        }

        /*
         * The claims queue takes the same flag through the same function. And
         * skipping the verdict can never uncover a balance: money is turned off
         * before the restriction check is passed over, for a viewer who holds
         * the ledger as much as for one who does not.
         */
        $standing = app(Residents::class)->standingOf($household->fresh(), maySeeMoney: true, hideRestriction: true);

        expect($standing['restricted'])->toBeFalse()
            ->and($standing['balance_minor'])->toBeNull()
            ->and($standing['bucket'])->toBeNull();
    } finally {
        $household->forceFill(['access_restricted' => false])->save();
        DB::connection('tenant')->table('residents')->where('id', $resident)->delete();
    }
});

it('sends the Admin Assistant no plan register and no dunning log, which are two more lists of who is behind', function () {
    $assistant = FacilitiesFixture::bookkeeper();
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    /* -------- the plan register -------- */
    foreach (['/finance/payment-plans', '/finance/payment-plans?status=draft'] as $path) {
        $plans = assistantMoneyProps($this, $assistant, $path);

        // No rows, and no counts either: "Defaulted (3)" counts households.
        expect($plans['rows'])->toBe([], $path)
            ->and($plans['counts'])->toBe([], $path)
            ->and($plans['filter'])->toBe('', $path)
            ->and($plans['positionWithheld'])->toBeTrue($path)
            ->and($plans['positionReason'])->toBe(Collections::PLANS_WITHHELD, $path)
            ->and(assistantMoneyLeaks($plans))->toBe([], $path);
    }

    $treasurerPlans = assistantMoneyProps($this, $treasurer, '/finance/payment-plans');

    expect(array_column($treasurerPlans['rows'], 'unit_id'))->toContain($this->money['unit_id'])
        ->and($treasurerPlans['positionWithheld'])->toBeFalse();

    /* -------- the dunning log -------- */
    $dunning = assistantMoneyProps($this, $assistant, '/finance/dunning');

    expect($dunning['sends'])->toBe([])
        ->and($dunning['positionWithheld'])->toBeTrue()
        ->and($dunning['positionReason'])->toBe(Collections::SENDS_WITHHELD)
        ->and(json_encode($dunning))->not->toContain('Lot 954')
        ->and(assistantMoneyLeaks($dunning))->toBe([]);

    // The template editor is the office's and names no household: it stays.
    expect($dunning)->toHaveKeys(['templates', 'mergeFields', 'channels', 'drafts'])
        ->and($dunning['canSend'])->toBeTrue();

    $treasurerDunning = assistantMoneyProps($this, $treasurer, '/finance/dunning');

    expect($treasurerDunning['sends'])->not->toBe([])
        ->and($treasurerDunning['positionWithheld'])->toBeFalse();
});

it('withholds the payables totals from the Admin Assistant and keeps each bill\'s own figures', function () {
    $assistant = FacilitiesFixture::bookkeeper();

    $vendors = assistantMoneyProps($this, $assistant, '/accounting/vendors');

    expect($vendors['paid_ytd_total_minor'])->toBeNull()
        ->and($vendors['positionWithheld'])->toBeTrue()
        ->and(assistantMoneyLeaks($vendors))->toBe([]);

    // The bills keep their own figures — paying one is filled from what it
    // still owes — and lose the three tiles that are the estate's payables.
    $bills = assistantMoneyProps($this, $assistant, '/accounting/bills');

    expect(array_column($bills['kpis'], 'key'))->toBe(['due_week'])
        ->and($bills['positionWithheld'])->toBeTrue();

    $treasurerBills = assistantMoneyProps($this, FacilitiesFixture::viewer(Role::TREASURER), '/accounting/bills');

    expect(array_column($treasurerBills['kpis'], 'key'))->toBe(['payable', 'overdue', 'due_week', 'paid_month']);
});

/* ------------------------------------------------------------------ */
/* exports */
/* ------------------------------------------------------------------ */

it('lets the Admin Assistant take away no file with money in it, and writes no export entry for the refusals', function () {
    $assistant = FacilitiesFixture::bookkeeper();
    $exports = static fn (): int => DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count();
    $before = $exports();

    foreach ([
        '/finance/arrears/export',
        '/finance/receipts/export',
        '/reports',
        '/reports/arrears_ageing',
        '/reports/arrears_ageing/export',
        '/reports/profit_and_loss/export',
        '/payroll',
    ] as $path) {
        $this->actingAs($assistant)->get(FacilitiesFixture::url($path))->assertForbidden();
    }

    expect($exports())->toBe($before);
});

it('refuses the arrears and receipt files to a role given the export verb without the estate\'s position — the second lock, for §4', function () {
    /*
     * No seeded role holds `export` without Reports, so the route gate alone
     * has been enough. §4 lets a Community Super Admin build one that does, and
     * this is that role: the Admin Assistant's cells plus the two export verbs.
     */
    $assistant = FacilitiesFixture::bookkeeper();
    $assistant->givePermissionTo(['estate.dues_ledger.export', 'estate.payments.export']);
    $assistant = $assistant->fresh();

    expect($assistant->can('estate.dues_ledger.export'))->toBeTrue();

    $exports = static fn (): int => DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count();
    $before = $exports();

    $this->actingAs($assistant)->get(FacilitiesFixture::url('/finance/arrears/export'))->assertForbidden();
    $this->actingAs($assistant)->get(FacilitiesFixture::url('/finance/receipts/export'))->assertForbidden();

    // Refused before a row was read, so nothing claims a file left.
    expect($exports())->toBe($before);

    // And the page does not draw a live Export over a file the route refuses.
    expect(assistantMoneyProps($this, $assistant, '/finance/arrears')['canExport'])->toBeFalse();

    // Nor does the receipt register (bug hunt B029), and it says both locks, as the arrears list does.
    $receipts = assistantMoneyProps($this, $assistant, '/finance/receipts');

    expect($receipts['canExport'])->toBeFalse()
        ->and($receipts['exportBlockedReason'])->toContain('Reports access');

    // Who holds both still has the button.
    expect(assistantMoneyProps($this, FacilitiesFixture::viewer(Role::TREASURER), '/finance/receipts')['canExport'])->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* what the Admin Assistant genuinely needs, still working */
/* ------------------------------------------------------------------ */

it('still shows the Admin Assistant the one household\'s balance in front of them, and lets them record a payment against it', function () {
    $assistant = FacilitiesFixture::bookkeeper();
    $unit = Unit::query()->findOrFail($this->money['unit_id']);
    $dues = app(Dues::class);

    $balance = $dues->balanceOf($unit)->getMinorAmount()->toInt();

    // Board 6, one lot: the figure a receipt is keyed against.
    $ledger = assistantMoneyProps($this, $assistant, '/finance/units/'.$unit->id);

    expect($ledger['balance_minor'])->toBe($balance)
        ->and($ledger['canRecord'])->toBeTrue();

    // Board 35, one lot: what the charge is added to.
    $charge = assistantMoneyProps($this, $assistant, '/finance/charges/new?unit='.urlencode($unit->reference));

    expect($charge['currentBalanceMinor'])->toBe($balance)
        ->and($charge['canPost'])->toBeTrue();

    // Board 7, one lot: the plan the register no longer lists is still this
    // household's to open from its own ledger.
    $this->actingAs($assistant)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payment-plan'))
        ->assertOk();

    // And the act itself.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payments'), [
            'method' => 'cash',
            'amount' => '100.00',

            // The estate's today: after 7 PM in Kingston, UTC's is already tomorrow (B023).
            'received_on' => Dues::siteDate()->toDateString(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe($balance - 10_000);
});

it('keeps the Admin Assistant\'s own receipt printable, which is one payment and not the estate\'s takings', function () {
    // Asked for, not rendered here — `EstateDocumentsTest` owns the renderer.
    Queue::fake();
    Storage::fake('local');

    $assistant = FacilitiesFixture::bookkeeper();
    $payment = Payment::query()->where('receipt_no', $this->money['receipt_no'])->firstOrFail();

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/payments/'.$payment->id.'/receipt'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('carries a withheld reason on every screen that names no figure and no household', function () {
    foreach ([
        Dues::POSITION_WITHHELD,
        Dues::ARREARS_WITHHELD,
        Collections::PLANS_WITHHELD,
        Collections::SENDS_WITHHELD,
        Residents::LIST_POSITION_WITHHELD,
    ] as $reason) {
        // A digit would be a figure or a lot number; either is the leak
        // describing itself in the sentence that ships beside it.
        expect(preg_match('/\d/', $reason))->toBe(0, $reason)
            ->and($reason)->not->toContain('$')
            ->and($reason)->not->toContain('Lot ');
    }
});

// A charge record stays exactly what it was for a role that may read it.
it('changes nothing about what a charge record contains', function () {
    expect(Charge::query()->findOrFail($this->money['charge_id'])->amount_minor)->toBe(1_000_000);
});
