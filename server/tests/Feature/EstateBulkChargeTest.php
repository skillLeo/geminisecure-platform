<?php

declare(strict_types=1);

use App\Http\Controllers\Estate\DuesController;
use App\Models\Estate\Charge;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A charge to a whole phase or the whole estate — board 35 (12 §2, Wave 2)
|--------------------------------------------------------------------------
|
| TWO PRESSES, and the first posts nothing. A charge against the estate is
| hundreds of journal entries at once; the list is what a treasurer needs in
| front of them before the second press, and the posting is all of them or
| none — a half-posted assessment bills some households and not others.
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

function receivableNet(): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', ['1200'])->net;
}

it('draws the list first and posts nothing, then bills every unit on it in one go', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $units = Unit::query()->count();
    $receivable = receivableNet();
    $charges = Charge::query()->count();

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/charges/new'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canPost', true)
            ->where('bulkPreview', null)
            ->has('phases'));

    // A reader of the ledger raises nothing against anybody.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'estate', 'type' => 'special_assessment', 'amount' => '1.00',
            'description' => 'x', 'due_on' => now()->toDateString(), 'account' => '4000',
        ])
        ->assertForbidden();

    // STEP ONE. The list, and not one journal line.
    $response = $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'estate',
            'type' => 'special_assessment',
            'amount' => '12000.00',
            'description' => 'Perimeter wall assessment',
            'due_on' => now()->addDays(30)->toDateString(),
            'account' => '4000',
        ])
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(receivableNet())->toBe($receivable)
        ->and(Charge::query()->count())->toBe($charges);

    $token = (string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY);
    $token = str_replace('preview=', '', $token);

    $this->actingAs($treasurer)
        ->withSession($response->baseResponse->getSession()->all())
        ->get(FacilitiesFixture::url('/finance/charges/new').'?preview='.$token)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bulkPreview.count', $units)
            ->where('bulkPreview.total_minor', 12_000_00 * $units)
            ->where('bulkPreview.description', 'Perimeter wall assessment')

            // The first lots by name and the rest as a count (M11 A2) — never the
            // whole estate in the page, and never the ids that post.
            ->has('bulkPreview.units', min($units, DuesController::BULK_PREVIEW_SHOWN))
            ->where('bulkPreview.more', max(0, $units - DuesController::BULK_PREVIEW_SHOWN))
            ->missing('bulkPreview.unit_ids'));

    // STEP TWO. Every unit, each with its own charge and its own journal.
    $this->actingAs($treasurer)
        ->withSession($response->baseResponse->getSession()->all())
        ->post(FacilitiesFixture::url('/finance/charges/bulk'), ['token' => $token])
        ->assertRedirect(FacilitiesFixture::url('/finance/arrears'));

    FacilitiesFixture::boot();

    expect(Charge::query()->count())->toBe($charges + $units)
        ->and(receivableNet() - $receivable)->toBe(12_000_00 * $units)
        ->and(Charge::query()->where('description', 'Perimeter wall assessment')->distinct()->count('unit_id'))->toBe($units);

    // Spent: a second press on the same preview cannot bill the estate twice.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/bulk'), ['token' => $token])
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Charge::query()->count())->toBe($charges + $units);
});

it('posts a whole-estate charge from its preview once, even when the post arrives twice — bug hunt B020', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit('Lot 7443', 'Bulk Once Phase');

    $response = $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'phase',
            'phase' => 'Bulk Once Phase',
            'type' => 'special_assessment',
            'amount' => '1500.00',
            'description' => 'Bulk once assessment',
            'due_on' => now()->addDays(30)->toDateString(),
            'account' => '4000',
        ])
        ->assertSessionHasNoErrors();

    $token = str_replace('preview=', '', (string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY));
    $session = $response->baseResponse->getSession()->all();

    /*
     * TWO POSTS CARRYING THE SAME SESSION — two tabs on one preview, or a retried
     * request. Each read the session before the other had spent the token, which
     * is the whole of what "spent" used to rest on.
     */
    $this->actingAs($treasurer)->withSession($session)
        ->post(FacilitiesFixture::url('/finance/charges/bulk'), ['token' => $token])
        ->assertSessionHasNoErrors()
        ->assertRedirect(FacilitiesFixture::url('/finance/arrears'));

    $this->actingAs($treasurer)->withSession($session)
        ->post(FacilitiesFixture::url('/finance/charges/bulk'), ['token' => $token])
        ->assertSessionHasErrors('amount');

    FacilitiesFixture::boot();

    expect(Charge::query()->where('unit_id', $unit->id)->where('description', 'Bulk once assessment')->count())->toBe(1);
});

it('parses the amount on every press, whichever scope it goes to — bug hunt B102', function () {
    /*
     * A Vue behaviour, read off the page itself the way `CategoryBTest` reads its
     * screens: there is no JavaScript runner in this suite. The bulk press used to
     * install a transform that kept the raw "$15,000.00" the blur had tidied the
     * field into, then left an identity transform behind it — so a whole-phase
     * charge failed "must be a number", and so did every single-unit charge after
     * it. ONE transform now, installed once, and it always parses the amount.
     */
    $screen = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/NewCharge.vue'));
    $script = preg_match('/<script setup>(.*?)<\/script>/s', $screen, $found) === 1 ? $found[1] : '';
    $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $script);

    expect(substr_count($code, '.transform('))->toBe(1)
        ->and($code)->toContain('amount: parseAmount(data.amount).toFixed(2)')
        ->and($code)->not->toContain('transform((data) => data)');
});

it('refuses a phase nobody lives in, and posts nothing when one unit of the list has gone', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $dues = app(Dues::class);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'phase',
            'phase' => 'Phase 404',
            'type' => 'dues',
            'amount' => '500.00',
            'description' => 'Nowhere',
            'due_on' => now()->toDateString(),
            'account' => '4000',
        ])
        ->assertSessionHasErrors('amount');

    // ALL OF THEM OR NONE. A list carrying a unit the register no longer has
    // posts nothing at all, rather than billing the rest and losing the one.
    $real = Unit::query()->orderBy('id')->pluck('id')->all();
    $receivable = receivableNet();

    expect(fn () => $dues->chargeMany(
        unitIds: [...$real, 999_999],
        amount: Money::of('500.00', 'JMD'),
        description: 'Half a run',
        dueOn: now()->toDateString(),
        type: 'dues',
        account: '4000',
        by: $treasurer,
    ))->toThrow(DomainException::class);

    FacilitiesFixture::boot();

    expect(receivableNet())->toBe($receivable)
        ->and(Charge::query()->where('description', 'Half a run')->count())->toBe(0);
});
