<?php

declare(strict_types=1);

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\DunningTemplateDraft;
use App\Models\Role;
use App\Services\Estate\Collections;
use Database\Seeders\Estate\CollectionsSeeder;
use Illuminate\Support\Collection as LaravelCollection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The dunning template editor — board 8 (12 §1)
|--------------------------------------------------------------------------
|
| THE RULING, in three parts. A new or edited stage saves as a DRAFT and the
| estate keeps sending what it was sending. Putting one in force needs a
| committee resolution reference. And a notice already sent is never altered,
| whatever happens to the template it came from.
|
*/

/*
|--------------------------------------------------------------------------
| THIS FILE PUTS BACK EVERYTHING IT BORROWS — M6 §3.1
|--------------------------------------------------------------------------
|
| Only the CENTRAL connection is transacted below, and the ladder, the drafts
| and the log are in the ESTATE's database, which is committed and shared with
| fifty-three other files in the run. So everything this file writes to that
| database is snapshotted before it starts and put back after it ends.
|
| A TRANSACTION IS NOT AVAILABLE HERE and that is why this is done by hand: every
| assertion below goes through an HTTP request, and `FacilitiesFixture::boot()`
| purges the tenant connection after each one — which would throw the transaction
| away mid-test. So the tables are swept and restored instead.
|
| THREE THINGS WERE WRONG WITH THE EARLIER VERSION OF THIS, and shuffled ordering
| found all three:
|
| 1. It ran `EstateFinanceSeeder` to get the ladder. That seeder's idempotency
|    guard is "450 units and the arrears already add up", which this five-lot
|    fixture can never satisfy — so it TRUNCATED thirty-seven tables of the
|    shared estate, `units` among them, and rebuilt it as a 450-lot Phoenix Park.
|    Every other file that had already made a scratch lot, raised a ticket or
|    recorded a vote on this estate lost it, silently, and only when this file
|    happened to run first. The ladder is `CollectionsSeeder`'s and nothing else
|    here was ever wanted, so that is what runs now: it truncates nothing.
|
| 2. The watermark was taken AFTER the seeding, so the seeded rows were never
|    swept. It is taken before, and the seeding goes out again with the rest.
|
| 3. The assertions were absolute — `sole()` over the whole drafts table, an
|    empty drafts board, "no rung is called Final demand". Every one of those is
|    a claim about rows this file does not own, on a table every other file on
|    this estate may write to. They are scoped to the rows these tests create,
|    or made against a BEFORE reading, one by one, below.
|
| IT SWEEPS BY WATERMARK, NOT BY NAME. Anything this file adds to those tables
| goes out again whatever it is called, so a test added here later cannot
| reintroduce the leak by inventing a new label.
|
*/

/**
 * The estate tables this file writes to, CHILDREN BEFORE PARENTS.
 *
 * A draft and a notice both point at a template, and an instalment at a plan, so
 * a sweep in this order never leaves a row pointing at something already gone.
 *
 * @return list<string>
 */
function dunningTablesTouched(): array
{
    return [
        'dunning_template_drafts',
        'dunning_notices',
        'dunning_templates',
        'payment_plan_instalments',
        'payment_plans',
    ];
}

/**
 * The tables whose EXISTING rows this file may reword rather than merely add to.
 *
 * `dunning_notices` is deliberately absent. It is append-only, nothing here may
 * alter a row in it, and the second test exists to prove exactly that — so a
 * restore pass over it would paper over the failure it is there to catch.
 *
 * @return list<string>
 */
function dunningTablesReworded(): array
{
    return ['dunning_template_drafts', 'dunning_templates'];
}

/**
 * The estate as this file found it: the high-water mark of every table it
 * writes to, and every row of the two it may reword.
 *
 * @return array{marks: array<string, int>, rows: array<string, array<int, array<string, mixed>>>}
 */
function dunningEstateSnapshot(): array
{
    $tenant = DB::connection('tenant');

    $marks = [];

    foreach (dunningTablesTouched() as $table) {
        $marks[$table] = (int) $tenant->table($table)->max('id');
    }

    $rows = [];

    foreach (dunningTablesReworded() as $table) {
        $rows[$table] = collect($tenant->table($table)->get())
            ->mapWithKeys(static fn (object $row): array => [(int) $row->id => (array) $row])
            ->all();
    }

    return ['marks' => $marks, 'rows' => $rows];
}

/**
 * Put the estate back exactly as the snapshot found it.
 *
 * @param  array{marks: array<string, int>, rows: array<string, array<int, array<string, mixed>>>}  $was
 */
function dunningEstateRestore(array $was): void
{
    $tenant = DB::connection('tenant');

    foreach (dunningTablesTouched() as $table) {
        $tenant->table($table)->where('id', '>', $was['marks'][$table])->delete();
    }

    foreach (dunningTablesReworded() as $table) {
        foreach ($was['rows'][$table] as $id => $row) {
            $current = $tenant->table($table)->where('id', $id)->first();

            if ($current === null) {
                $tenant->table($table)->insert($row);

                continue;
            }

            if ((array) $current === $row) {
                continue;
            }

            $tenant->table($table)->where('id', $id)->update($row);
        }
    }
}

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // BEFORE THE SEEDING, so the ladder this file seeds is swept with everything
    // else it writes and the estate is handed on as it was found.
    $this->estateWas = dunningEstateSnapshot();

    // The ladder lives in `CollectionsSeeder`, which the facilities fixture does
    // not run — this suite is about the ladder, so it runs it. It is idempotent
    // and purely additive: it truncates nothing and rebuilds no estate.
    (new CollectionsSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    dunningEstateRestore($this->estateWas);
});

it('saves a reworded step as a draft, and the estate goes on sending what it was sending', function () {
    // Entry on Dues & ledger words a step; the Treasurer is its approver.
    $assistant = FacilitiesFixture::bookkeeper();
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    /*
     * BY KEY, NOT BY POSITION. `where('is_active', true)->orderBy('stage')->first()`
     * is a lottery over a table every other file on this shared estate can add a
     * rung to, and two rungs at one stage leave MySQL free to return either — so
     * `$step` was a different template on different orders, and the test that
     * followed was quietly proving something else.
     */
    $step = DunningTemplate::query()->where('key', 'reminder1')->firstOrFail();
    $wordingInForce = $step->body;

    // The drafts this test makes, told apart from any this estate already held.
    $draftsBefore = $this->estateWas['marks']['dunning_template_drafts'];
    $mine = fn (): LaravelCollection => DunningTemplateDraft::query()
        ->where('id', '>', $draftsBefore)
        ->orderBy('id')
        ->get();

    $this->actingAs($assistant)
        ->get(FacilitiesFixture::url('/finance/dunning'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSend', true)
            ->where('canAdopt', false)
            ->has('channels')

            /*
             * NOT `where('drafts', [])`. That board is the whole unadopted table,
             * and this estate is committed and shared, so an empty list was an
             * assertion about every other file in the run rather than about this
             * screen. What this test owns is that nothing is waiting against THIS
             * step before it words one.
             */
            ->where('drafts', fn (LaravelCollection $drafts): bool => ! $drafts->contains('template_id', $step->id)));

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts'), [
            'template_id' => $step->id,
            'label' => $step->label,
            'stage' => $step->stage,
            'channel' => 'push+email',
            'subject' => 'Your maintenance account, {unit_label}',
            'body' => "Dear {resident_first_name},\n\n{unit_label} is {days_overdue} days past due at {amount_due}.",
            'days_overdue' => $step->days_overdue,
        ])
        ->assertRedirect(FacilitiesFixture::url('/finance/dunning'));

    FacilitiesFixture::boot();

    // SCOPED TO THE ROW THIS TEST MADE. `sole()` over the whole table was an
    // assertion that no other file on this estate has ever left a draft behind.
    $draft = $mine()->sole();

    // THE LADDER IN FORCE HAS NOT MOVED.
    expect(DunningTemplate::query()->findOrFail($step->id)->body)->toBe($wordingInForce)
        ->and($draft->dunning_template_id)->toBe($step->id)
        ->and($draft->resolution_reference)->toBeNull()
        ->and($draft->adopted_at)->toBeNull()
        ->and($draft->drafted_by_name)->toBe($assistant->name);

    // A second save against the same step rewrites the one draft, rather than
    // putting two proposals for one rung in front of the committee.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts'), [
            'template_id' => $step->id,
            'label' => $step->label,
            'stage' => $step->stage,
            'channel' => 'email',
            'subject' => 'Second thoughts',
            'body' => 'Dear {resident_first_name}, please call the office.',
            'days_overdue' => $step->days_overdue,
        ])
        ->assertRedirect();

    FacilitiesFixture::boot();

    /*
     * THE SAME ROW, REWORDED — asserted as a CHANGE rather than as a total. The
     * old `count() === 1` was true only while no other file had a draft open on
     * this estate, and it would have gone red for the next person to write one
     * without anything at all being wrong with this code.
     */
    $after = $mine();

    expect($after)->toHaveCount(1)
        ->and($after->first()->id)->toBe($draft->id)
        ->and($after->first()->subject)->toBe('Second thoughts');

    // A token this estate cannot fill would arrive on a phone as its own text.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts'), [
            'template_id' => $step->id,
            'label' => $step->label,
            'stage' => $step->stage,
            'channel' => 'email',
            'subject' => 'Hello',
            'body' => 'Dear {resident_first_name}, your {strata_lot_number} is overdue.',
            'days_overdue' => 0,
        ])
        ->assertSessionHasErrors('body');

    // A reader of the log words nothing.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts'), [
            'template_id' => $step->id, 'label' => 'x', 'stage' => 1,
            'channel' => 'email', 'subject' => 'x', 'body' => 'x', 'days_overdue' => 0,
        ])
        ->assertForbidden();
});

it('puts a draft in force only against a committee resolution, and never alters a notice already sent', function () {
    $assistant = FacilitiesFixture::bookkeeper();
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    // By key, for the reason the test above gives.
    $step = DunningTemplate::query()->where('key', 'reminder1')->firstOrFail();

    // A notice that went out under the wording in force. This is the row the
    // whole log exists for, and nothing below may touch it.
    $sent = DunningNotice::query()->create([
        'unit_id' => FacilitiesFixture::unit('Lot 9')->id,
        'dunning_template_id' => $step->id,
        'template_label' => $step->label,
        'stage' => $step->stage,
        'channel' => $step->channel,
        'subject' => 'As it was sent',
        'body' => 'This is the wording the resident actually received.',
        'sent_at' => now()->subDay(),
        'delivery_state' => 'delivered',
    ]);

    /*
     * THE ROW THE SERVICE RETURNED, held by id from here on.
     *
     * It used to be found again with `whereNull('dunning_template_id')->sole()`,
     * which is an assertion that nobody else on this shared estate has an open
     * draft for a step that does not exist yet — and "one open draft per step"
     * means the service may quite correctly hand back a row somebody else
     * started. Either way this test wants the row it just wrote, and `saveDraft`
     * already hands it over.
     */
    $draft = app(Collections::class)->saveDraft([
        'label' => 'Final demand',
        'stage' => 9,
        'channel' => 'push+email+sms',
        'subject' => 'Final demand — {unit_label}',
        'body' => 'Dear {resident_first_name}, {amount_due} remains unpaid {days_overdue} days after {due_date}.',
        'days_overdue' => 90,
    ], $assistant);

    FacilitiesFixture::boot();

    $draft = DunningTemplateDraft::query()->findOrFail($draft->id);

    /*
     * The ladder as it stands before anybody tries to adopt anything. What the
     * two refusals below have to prove is that the ladder did not GAIN a rung —
     * not, as it said before, that no rung anywhere on this estate is called
     * "Final demand", which is a claim about other people's rows.
     */
    $ladderWas = DunningTemplate::query()->orderBy('id')->pluck('id')->all();

    // The office words it. Putting it in force is the approver's act.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => 'Res. 2026-14'])
        ->assertForbidden();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => ''])
        ->assertSessionHasErrors('resolution_reference');

    FacilitiesFixture::boot();

    expect(DunningTemplate::query()->orderBy('id')->pluck('id')->all())->toBe($ladderWas);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => 'Res. 2026-14'])
        ->assertRedirect(FacilitiesFixture::url('/finance/dunning'));

    FacilitiesFixture::boot();

    $draft = DunningTemplateDraft::query()->findOrFail($draft->id);

    // The rung READ OFF THE DRAFT, rather than looked up by a label that another
    // file on this estate is free to use as well.
    $live = DunningTemplate::query()->findOrFail($draft->dunning_template_id);

    expect($live->label)->toBe('Final demand')
        ->and($live->stage)->toBe(9)
        ->and($live->days_overdue)->toBe(90)
        ->and($live->is_active)->toBeTrue()
        ->and($draft->adopted_at)->not->toBeNull()
        ->and($draft->resolution_reference)->toBe('Res. 2026-14')
        ->and($draft->adopted_by_name)->toBe($treasurer->name)

        // And the ladder gained exactly that one rung and nothing else.
        ->and(DunningTemplate::query()->orderBy('id')->pluck('id')->all())->toBe([...$ladderWas, $live->id]);

    // THE SENT NOTICE IS UNTOUCHED, subject and body exactly as they went out.
    $sent = DunningNotice::query()->findOrFail($sent->id);

    expect($sent->subject)->toBe('As it was sent')
        ->and($sent->body)->toBe('This is the wording the resident actually received.');

    // An adopted draft is decided, so it leaves the waiting list — and it
    // cannot be put in force a second time.
    expect(collect(app(Collections::class)->draftBoard())->contains('id', $draft->id))->toBeFalse();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => 'Res. 2026-15'])
        ->assertSessionHasErrors('resolution_reference');
});
