<?php

declare(strict_types=1);

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| "Send reminder now" sends a reminder — bug hunt B111
|--------------------------------------------------------------------------
|
| A treasurer on Lot 47's ledger pressed "Send reminder now" and arrived on the
| Dunning screen, which had a log, a template editor and "Put in force" — no
| unit, no step to choose and nothing that sent. The send route existed; no page
| called it. The flag flash promised "a notice can still be sent deliberately".
|
| The ledger now opens the Dunning screen FOR ITS UNIT, where the office picks a
| step, reads the notice exactly as the household will receive it, and sends it
| onto the log — the deliberate send a hardship flag leaves alone.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // The ladder the estate sends from — Day 20, Reminder 1 and Reminder 4+.
    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    // The estate database is committed: this file clears the notices it sent.
    $units = Unit::query()->whereIn('reference', ['Lot 7471', 'Lot 7472'])->pluck('id');
    DunningNotice::query()->whereIn('unit_id', $units)->delete();
});

/** A lot of this file's own, owing a charge from the spring, with a resident to address. */
function dunningSendUnit(string $reference): Unit
{
    $unit = FacilitiesFixture::scratchUnit($reference);

    if ($unit->household === null) {
        $household = Household::create(['unit_id' => $unit->id, 'name' => $reference.' household', 'access_restricted' => false]);

        Resident::create([
            'household_id' => $household->id,
            'full_name' => 'Marcia Grant',
            'email' => str_replace(' ', '', strtolower($reference)).'@dunning-send.test',
            'relationship' => 'owner',
            'is_primary' => true,
        ]);

        app(Dues::class)->charge(
            unit: $unit,
            amount: Money::of('12400.00', 'JMD'),
            description: 'Maintenance arrears brought forward',
            dueOn: Carbon::today()->subDays(45),
        );
    }

    return $unit->refresh();
}

it('opens the Dunning screen for the ledger\'s own unit, with every step rendered as the household will read it', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = dunningSendUnit('Lot 7471');

    FacilitiesFixture::boot();

    // What each step would freeze onto the log if sent now, from the renderer a send uses.
    $ladder = DunningTemplate::query()->where('is_active', true)->get();
    $rendered = $ladder->mapWithKeys(fn (DunningTemplate $template): array => [$template->id => app(Collections::class)->render($template, $unit)]);

    // The step the morning ladder has reached at forty-five days overdue.
    $reached = DunningTemplate::query()->where('is_active', true)->where('days_overdue', '<=', 45)->orderByDesc('days_overdue')->orderByDesc('stage')->value('id');

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/dunning?unit='.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Dues/Dunning')
            ->where('canSend', true)
            ->where('sendTo.unit.id', $unit->id)
            ->where('sendTo.unit.reference', $unit->reference)
            ->where('sendTo.steps', function ($steps) use ($rendered): bool {
                $steps = collect($steps);

                return $steps->count() === $rendered->count()
                    && $steps->every(fn (array $step): bool => ($rendered[$step['id']]['subject'] ?? null) === $step['subject']
                        && ($rendered[$step['id']]['body'] ?? null) === $step['body']);
            })
            ->where('sendTo.suggested', $reached));

    // The seeded Reminder 1 reads the household's name and balance, not its tokens.
    $reminder = $rendered[DunningTemplate::query()->where('key', 'reminder1')->value('id')] ?? null;

    expect($reminder)->not->toBeNull()
        ->and($reminder['subject'].$reminder['body'])->not->toContain('{unit_label}');

    // With no unit asked for, the screen is the log and the editor, as it was.
    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/dunning'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sendTo', null));
});

it('sends the chosen step onto the log word for word, and comes back to the unit', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = dunningSendUnit('Lot 7472');
    $template = DunningTemplate::query()->where('key', 'reminder1')->firstOrFail();

    FacilitiesFixture::boot();
    $expected = app(Collections::class)->render($template, $unit);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/dunning'), ['unit' => $unit->id, 'template' => $template->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(FacilitiesFixture::url('/finance/dunning?unit='.$unit->id));

    FacilitiesFixture::boot();

    $notice = DunningNotice::query()->where('unit_id', $unit->id)->sole();

    expect($notice->template_label)->toBe($template->label)
        ->and($notice->sent_by_name)->toBe($treasurer->name)
        ->and($notice->delivery_state)->toBe(DunningNotice::QUEUED)
        ->and($notice->subject)->toBe($expected['subject'])
        ->and($notice->body)->toBe($expected['body']);

    // A step no longer on the ladder is not sent by hand either.
    $retired = DunningTemplate::query()->create([
        'key' => 'dunning-send-retired', 'label' => 'Retired step', 'stage' => 9, 'channel' => 'email',
        'subject' => 'Retired', 'body' => 'Retired wording.', 'days_overdue' => 200, 'is_active' => false,
    ]);

    try {
        $this->actingAs($treasurer)
            ->post(FacilitiesFixture::url('/finance/dunning'), ['unit' => $unit->id, 'template' => $retired->id])
            ->assertSessionHasErrors('template');
    } finally {
        FacilitiesFixture::boot();
        DunningTemplate::query()->whereKey($retired->id)->delete();
    }
});

it('draws each reminder\'s balance off the unit\'s own ledger lines, to the cent', function () {
    /*
     * THE MONEY RULE (M97). The log's Balance is what the unit owes now, read from the ledger as the page is drawn. It is
     * read a second way here, the unit's 1200 lines summed longhand, and the row has to carry that figure exactly; and
     * the page prints it to the cent, where it rounded to the dollar and showed $12,401 for a balance of $12,400.50.
     */
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = dunningSendUnit('Lot 7472');
    $template = DunningTemplate::query()->where('key', 'reminder1')->firstOrFail();

    // Fifty cents more, so a balance rounded to the dollar cannot pass for the ledger's.
    FacilitiesFixture::boot();
    app(Dues::class)->charge(unit: $unit, amount: Money::of('0.50', 'JMD'), description: 'Late fee, part', dueOn: Carbon::today()->subDays(40));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/dunning'), ['unit' => $unit->id, 'template' => $template->id])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $owed = (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unit->id])->owed;

    $props = [];

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/dunning'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    $rows = collect($props['sends'])->where('unit_id', $unit->id);

    expect($owed % 100)->toBe(50)
        ->and($rows)->not->toBeEmpty()
        ->and($rows->every(fn (array $row): bool => $row['balance_minor'] === $owed))->toBeTrue('a reminder row\'s balance is not the unit\'s ledger balance of '.$owed);

    // And the page writes it to the cent, from whole minor units, with no rounding to the dollar.
    $dunning = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/Dunning.vue'));

    expect(str_contains($dunning, 'Math.round(minor / 100)'))->toBeFalse('the Balance column still rounds to the dollar');
});

it('links the ledger\'s send control to its own unit, and draws a send the Dunning screen can make', function () {
    $ledger = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/UnitLedger.vue'));
    $dunning = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/Dunning.vue'));

    expect($ledger)->toContain('financePath(`/dunning?unit=${props.unit.id}`)');

    // The Dunning screen posts unit and template to the send route.
    expect($dunning)->toContain('sendForm.post(`${duesPath.value}/dunning`')
        ->and($dunning)->toMatch('/useForm\(\{\s*unit:/');
});
