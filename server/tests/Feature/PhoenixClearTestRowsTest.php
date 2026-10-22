<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Nomination;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\Vendor;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Payables;
use App\Support\Seeded\Seeding;
use Brick\Money\Money;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| THE FOUR THINGS A COMMITTEE WOULD READ ALOUD (M60 §C3, `phoenix:clear-test-rows`)
|--------------------------------------------------------------------------
|
| Work orders typed while testing ("Hhhhhhhhhh", an Aziz Bhatti Town address, one dated 2020 that drags the average
| resolution), and the seeded resident who carries the client's first name. The dry run lists every row with its table
| and id and writes nothing; the real run removes only the work orders named and renames only where a row may be
| touched, after a backup, on the audit log; a ballot paper keeps the name; a QA record, a seeded work order and one with
| a posted bill are refused; the restore puts it all back. On an estate of this file's own, seeded as the demonstration is.
|
*/

function clearRowsEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('cleartestrows', 'CTR', 'Clear Test Rows Estate');
}

/**
 * The demonstration's finance, once a process (in a named function: a closure's static is copied for every test), under
 * the seeding mark as on the live estate, so every row it writes reads as the seed's.
 */
function clearRowsSeedOnce(): void
{
    static $seeded = false;

    if (! $seeded) {
        Seeding::run('phoenix:demo-seed', static fn () => (new EstateFinanceSeeder)->run());
        $seeded = true;
    }
}

/** A work order raised as a person raises one (no seeded mark), on a lot of the estate. */
function clearRowsTicket(string $title, string $place = 'Phase 1 · Lot 3', ?Carbon $reported = null, ?string $by = null): MaintenanceTicket
{
    $unit = Unit::query()->where('reference', 'Lot 3')->firstOrFail();
    $ticket = app(Maintenance::class)->report(title: $title, locationLabel: $place, unit: $unit, category: 'Plumbing', description: 'Raised while testing.', reportedByName: $by ?? 'Rachel Bennett', reportedAt: $reported);

    if ($reported !== null) {
        $ticket->forceFill(['status' => MaintenanceTicket::COMPLETED, 'closed_at' => Carbon::parse('2026-09-01 12:00')])->save();
    }

    return $ticket->fresh();
}

beforeEach(function () {
    clearRowsEstate()->boot();
    clearRowsSeedOnce();

    DB::connection('mysql')->beginTransaction();
    $this->admin = clearRowsEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);
    clearRowsEstate()->boot();

    // Typed while testing, and one ordinary request a person raised.
    $this->typed = [
        'h' => clearRowsTicket('Hhhhhhhhhh'),
        'place' => clearRowsTicket('Water by the gate', '4M42+7VF, Aziz Bhatti Town'),
        'old' => clearRowsTicket('TEST-HASSAM e2e F ancient 2020', 'Phase 1 · Lot 3', Carbon::parse('2020-03-02 10:00')),
        'qa' => clearRowsTicket('QA-20261009 rehearsal: common-area light'),
        'real' => clearRowsTicket('Street light flickering', 'Phase 1 · Lot 3', null, 'Omar Brown'),
    ];

    // One the seed made: never listed, never removed.
    $this->seeded = Seeding::run('phoenix:demo-seed', fn () => clearRowsTicket('Gate lighting'));

    // A draft bill for the 2020 one goes with it; an approved one for the ordinary request would stop the run.
    $vendor = Vendor::query()->orderBy('id')->firstOrFail();
    $payables = app(Payables::class);
    $this->draftBill = $payables->recordBill($vendor, Money::of('1250.00', 'JMD'), 'Callout for the test', Carbon::now()->addDays(10), ticketId: $this->typed['old']->number, ticketLabel: 'test');
    $approved = $payables->recordBill($vendor, Money::of('4800.00', 'JMD'), 'Lamp replaced', Carbon::now()->addDays(10), ticketId: $this->typed['real']->number, ticketLabel: 'lamp');
    $payables->approve($approved, $this->admin);

    // The client's first name on a ballot paper (kept), and a real person called Omar (never renamed).
    $ballot = new Ballot;
    $ballot->forceFill(['year' => 2029, 'code' => 'A', 'title' => '2029 Community Executive', 'kind' => Ballot::ELECTION, 'stage' => Ballot::NOMINATIONS_OPEN, 'quorum_percent' => 25, 'eligible_households' => 10])->save();
    $this->ballotId = $ballot->id;
    $seat = BallotPosition::query()->create(['ballot_id' => $ballot->id, 'name' => 'Asst. PRO', 'seat_count' => 1, 'scope' => BallotPosition::ESTATE, 'sort_order' => 1]);
    $lot31 = Unit::query()->where('reference', 'Lot 31')->firstOrFail();
    $nomination = new Nomination;
    $nomination->forceFill(['ballot_id' => $ballot->id, 'ballot_position_id' => $seat->id, 'unit_id' => $lot31->id, 'candidate_name' => 'Omar Brown', 'nominator_unit_id' => $lot31->id, 'nominator_name' => 'Tanya Simms', 'seconder_unit_id' => $lot31->id, 'seconder_name' => 'Tanya Simms', 'statement' => 'Standing again.', 'status' => Nomination::APPROVED])->save();
    BallotOption::query()->create(['ballot_id' => $ballot->id, 'ballot_position_id' => $seat->id, 'label' => 'Omar Brown', 'unit_id' => $lot31->id, 'sort_order' => 1]);

    $real = new Resident;
    $real->forceFill(['household_id' => DB::connection('tenant')->table('households')->where('unit_id', Unit::query()->where('reference', 'Lot 3')->value('id'))->value('id'), 'full_name' => 'Omar Ebanks', 'email' => 'omar.ebanks@clear-rows.test', 'relationship' => 'tenant', 'is_primary' => false, 'status' => Resident::VERIFIED])->save();
    $this->realOmar = $real;
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    clearRowsEstate()->boot();

    // This file's own rows, whatever a test left: the estate database is committed. Bills first (they hold a number).
    $mine = [...array_map(static fn (MaintenanceTicket $t): int => $t->id, $this->typed), $this->seeded->id];
    $numbers = DB::connection('tenant')->table('maintenance_tickets')->whereIn('id', $mine)->pluck('number');
    DB::connection('tenant')->table('bills')->whereIn('ticket_id', $numbers)->delete();
    DB::connection('tenant')->table('maintenance_tickets')->whereIn('id', $mine)->delete();
    foreach (['ballot_options', 'nominations', 'ballot_positions'] as $table) {
        DB::connection('tenant')->table($table)->where('ballot_id', $this->ballotId)->delete();
    }
    DB::connection('tenant')->table('ballots')->where('id', $this->ballotId)->delete();
    DB::connection('tenant')->table('residents')->where('full_name', 'Omar Ebanks')->delete();
    DB::connection('tenant')->table('residents')->where('full_name', 'Orville Brown')->update(['full_name' => 'Omar Brown']);
});

it('lists every work order a person raised, with what marks it, and the name everywhere it is written, and writes nothing', function () {
    $before = MaintenanceTicket::query()->count();

    expect(Artisan::call('phoenix:clear-test-rows', ['--estate' => 'cleartestrows']))->toBe(0);
    $said = Artisan::output();
    clearRowsEstate()->boot();

    expect($said)->toContain('#'.$this->typed['h']->number.'  "Hhhhhhhhhh"')->toContain('TEST WORDS')
        ->and($said)->toContain('AN ADDRESS OUTSIDE JAMAICA')->toContain('DATED 2020')
        ->and($said)->not->toContain('"Gate lighting"')
        ->and($said)->toContain('"Omar Brown" · Lot 31 · seeded placeholder → "Orville Brown"')
        ->and($said)->toContain('renamed: ballot_options.label')->toContain('renamed: nominations.candidate_name')->toContain('the candidate record: no vote points at a name')
        ->and($said)->toContain('CHECKED: nothing append-only, no document already issued and no audit entry carries "Omar Brown"')
        ->and($said)->toContain('renamed: maintenance_tickets.reported_by_name #'.$this->typed['real']->id)
        ->and($said)->toContain('"Omar Ebanks"')->toContain('A REAL PERSON (not seeded): never renamed')
        ->and($said)->toContain('maintenance_tickets.location_label #'.$this->typed['place']->id)
        ->and($said)->toContain('DRY RUN: nothing was changed.')
        ->and(MaintenanceTicket::query()->count())->toBe($before)
        ->and(Resident::query()->where('full_name', 'Omar Brown')->exists())->toBeTrue();

    // The real command it offers names the three typed while testing, never the QA record or the ordinary request.
    preg_match('/--tickets=([0-9,]+)/', $said, $m);
    expect(explode(',', $m[1] ?? ''))->toEqualCanonicalizing(array_map('strval', [$this->typed['h']->number, $this->typed['place']->number, $this->typed['old']->number]));
});

it('removes only the work orders named, renames the seeded resident where a row may be touched, after a backup, and the restore puts it back', function () {
    $numbers = [$this->typed['h']->number, $this->typed['place']->number, $this->typed['old']->number];

    expect(Artisan::call('phoenix:clear-test-rows', ['--estate' => 'cleartestrows', '--tickets' => implode(',', $numbers), '--rename' => 'Orville Brown', '--confirm' => true]))->toBe(0);
    $said = Artisan::output();
    clearRowsEstate()->boot();

    preg_match('/^Backup written: (.+)$/m', $said, $m);
    $backup = trim($m[1] ?? '');
    preg_match('/AVERAGE RESOLUTION: ([0-9.]+) days now; ([0-9.]+) days/', $said, $avg);

    expect(MaintenanceTicket::query()->whereIn('number', $numbers)->exists())->toBeFalse()
        ->and(DB::connection('tenant')->table('maintenance_ticket_activity')->whereIn('maintenance_ticket_id', [$this->typed['h']->id, $this->typed['place']->id, $this->typed['old']->id])->exists())->toBeFalse()
        ->and(DB::connection('tenant')->table('bills')->where('id', $this->draftBill->id)->exists())->toBeFalse()
        ->and(MaintenanceTicket::query()->whereKey([$this->typed['qa']->id, $this->typed['real']->id, $this->seeded->id])->count())->toBe(3)
        ->and(Resident::query()->where('full_name', 'Orville Brown')->exists())->toBeTrue()
        ->and(Resident::query()->where('full_name', 'Omar Brown')->exists())->toBeFalse()
        ->and(MaintenanceTicket::query()->whereKey($this->typed['real']->id)->value('reported_by_name'))->toBe('Orville Brown')
        // The candidate renamed on the paper, and no vote touched (a vote points at the option's id).
        ->and(DB::connection('tenant')->table('ballot_options')->where('ballot_id', $this->ballotId)->value('label'))->toBe('Orville Brown')
        ->and(DB::connection('tenant')->table('nominations')->where('ballot_id', $this->ballotId)->value('candidate_name'))->toBe('Orville Brown')
        ->and(Resident::query()->whereKey($this->realOmar->id)->value('full_name'))->toBe('Omar Ebanks')
        ->and(is_file($backup))->toBeTrue()
        ->and((float) ($avg[1] ?? 0))->toBeGreaterThan((float) ($avg[2] ?? 0))
        ->and(DB::connection('mysql')->table('audit_log')->where('tenant_id', 'cleartestrows')->where('action', 'estate.test_rows_cleared')->exists())->toBeTrue();

    // Put back: the work orders with their timelines, the draft bill, and the name.
    expect(Artisan::call('phoenix:clear-test-rows', ['--estate' => 'cleartestrows', '--restore' => $backup]))->toBe(0);
    clearRowsEstate()->boot();

    expect(MaintenanceTicket::query()->whereIn('number', $numbers)->count())->toBe(3)
        ->and(DB::connection('tenant')->table('bills')->where('id', $this->draftBill->id)->exists())->toBeTrue()
        ->and(Resident::query()->where('full_name', 'Omar Brown')->exists())->toBeTrue()
        ->and(MaintenanceTicket::query()->whereKey($this->typed['real']->id)->value('reported_by_name'))->toBe('Omar Brown')
        ->and(DB::connection('tenant')->table('ballot_options')->where('ballot_id', $this->ballotId)->value('label'))->toBe('Omar Brown');

    File::delete($backup);
    DB::connection('tenant')->table('bills')->where('id', $this->draftBill->id)->delete();
});

it('refuses a QA record, a work order the seed made and one with a posted bill, and changes nothing', function () {
    $before = MaintenanceTicket::query()->count();

    foreach ([$this->typed['qa']->number => 'QA record', $this->seeded->number => 'never removed by this', $this->typed['real']->number => 'was posted to the ledger'] as $number => $why) {
        expect(Artisan::call('phoenix:clear-test-rows', ['--estate' => 'cleartestrows', '--tickets' => (string) $number, '--confirm' => true]))->not->toBe(0)
            ->and(Artisan::output())->toContain($why)->toContain('Refused, so nothing is changed');
        clearRowsEstate()->boot();
    }

    expect(MaintenanceTicket::query()->count())->toBe($before);
});

it('leaves the name alone, whole, when something it cannot reach carries it (an audit entry, a document already issued)', function () {
    // An audit entry naming the candidate: the rename would leave it disagreeing.
    DB::connection('mysql')->table('audit_log')->insert(['tenant_id' => 'cleartestrows', 'action' => 'election.nomination_approved', 'entity_type' => 'Nomination', 'entity_id' => '1', 'after' => json_encode(['candidate' => 'Omar Brown']), 'created_at' => now()]);

    expect(Artisan::call('phoenix:clear-test-rows', ['--estate' => 'cleartestrows']))->toBe(0);
    $said = Artisan::output();
    expect($said)->toContain('kept: audit_log #')->toContain('LEFT ALONE')->not->toContain('--rename=');

    expect(Artisan::call('phoenix:clear-test-rows', ['--estate' => 'cleartestrows', '--tickets' => (string) $this->typed['h']->number, '--rename' => 'Orville Brown', '--confirm' => true]))->not->toBe(0)
        ->and(Artisan::output())->toContain('so it is left alone')->toContain('Refused, so nothing is changed');
    clearRowsEstate()->boot();

    expect(Resident::query()->where('full_name', 'Omar Brown')->exists())->toBeTrue()
        ->and(MaintenanceTicket::query()->whereKey($this->typed['h']->id)->exists())->toBeTrue();
});

it('works on Phoenix Park Village 1 only on the live server', function () {
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        expect(Artisan::call('phoenix:clear-test-rows', ['--estate' => 'cleartestrows']))->not->toBe(0)
            ->and(Artisan::output())->toContain('Phoenix Park Village 1 (phoenixparkvillage1) only');
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});
