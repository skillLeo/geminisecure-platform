<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\VisitorPass;
use App\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Residents from a list, with a preview that checks every row (M98, the Residents census: bulk import)
|--------------------------------------------------------------------------
|
| Two presses, as the unit import is. The preview reads the file and checks every row against the register as it
| stands (the lot is one the estate has, a name, an email or a phone, nobody twice, nobody already there) and writes
| nothing. The commit adds the previewed people, all of them or none, each through Add resident's own service, as
| Pending, with the invite recorded and NOT sent. No lot is ever made; nobody is emailed; nobody is marked verified.
|
| Lots 9874 (vacant) and 9875 (a household already on it) are this file's own scratch lots.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Mail::fake();
    Notification::fake();

    $this->vacant = FacilitiesFixture::scratchUnit('Lot 9874');
    $this->occupied = FacilitiesFixture::scratchUnit('Lot 9875');
    importClear([$this->vacant->id, $this->occupied->id]);

    $present = Household::query()->create(['unit_id' => $this->occupied->id, 'name' => 'Present household', 'access_restricted' => false]);
    $rita = new Resident;
    $rita->forceFill([
        'household_id' => $present->id, 'full_name' => 'Rita Present', 'email' => 'rita.present@example.com', 'phone' => '+1 876 555 0190',
        'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED,
    ])->save();

    $this->office = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    FacilitiesFixture::boot();
    importClear([$this->vacant->id, $this->occupied->id]);

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** @param  list<int>  $unitIds */
function importClear(array $unitIds): void
{
    $households = Household::withRemoved()->whereIn('unit_id', $unitIds)->pluck('id');
    $people = Resident::withRemoved()->whereIn('household_id', $households)->pluck('id');
    ResidentInvite::query()->whereIn('resident_id', $people)->delete();
    VisitorPass::query()->whereIn('household_id', $households)->delete();
    Resident::withRemoved()->whereIn('id', $people)->delete();

    foreach ($households as $id) {
        try {
            Household::withRemoved()->whereKey($id)->delete();
        } catch (Throwable) {
        }
    }
}

/** Preview the file as the office and return the token the preview is kept under. */
function previewResidents(object $test, object $as, string $csv): string
{
    $response = $test->actingAs($as)->post(FacilitiesFixture::url('/residents/import/preview'), [
        'file' => UploadedFile::fake()->createWithContent('residents.csv', $csv),
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect();
    preg_match('/import=([A-Za-z0-9]{20})/', (string) $response->headers->get('Location'), $match);
    FacilitiesFixture::boot();

    return $match[1] ?? '';
}

it('checks every row against the register in the preview, and writes nothing', function () {
    $csv = implode("\n", [
        'lot,name,relationship,email,phone,moved in',
        'Lot 9874,Nia Import,owner,nia.import@example.com,876 555 0101,2024-09-02',
        '9874,Omar Import,spouse,,876 555 0102,',
        'Lot 9875,Rita Present,owner,rita.again@example.com,,',
        'Lot 99999,Nobody Here,owner,nobody@example.com,,',
        'Lot 9875,Sam Join,member,,,',
        'Lot 9875,Tess Join,tenant,tess.join@example.com,,',
        'Lot 9874,Nia Import,owner,nia.twice@example.com,,',
    ]);

    $token = previewResidents($this, $this->office, $csv);
    expect($token)->toHaveLength(20);

    $this->actingAs($this->office)->get(FacilitiesFixture::url('/residents?import='.$token))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('residentImport.valid', false)
            ->where('residentImport.people', 3)
            ->where('residentImport.households', 1)
            ->where('residentImport.errors', [
                ['line' => 4, 'message' => 'Rita Present is already on the register at Lot 9875.'],
                ['line' => 5, 'message' => '"Lot 99999" is not a lot on this estate\'s register. The import adds people, never lots.'],
                ['line' => 6, 'message' => 'No email and no phone. The register needs one to reach this person.'],
                ['line' => 8, 'message' => 'Nia Import at Lot 9874 is on line 2 of the file already.'],
            ])
            ->where('residentImport.rows.0.outcome', 'New household, its primary resident')
            ->where('residentImport.rows.0.phone', '+1 876 555 0101')
            ->where('residentImport.rows.1.outcome', 'Joins the new household above')
            ->where('residentImport.rows.2.outcome', 'Joins Present household'));
    FacilitiesFixture::boot();

    // A file with problems is never added, and the preview wrote nothing.
    $this->actingAs($this->office)->post(FacilitiesFixture::url('/residents/import/commit'), ['token' => $token])
        ->assertSessionHasErrors(['import' => 'Some rows have problems. Fix the file and preview it again.']);
    FacilitiesFixture::boot();

    expect(Household::query()->where('unit_id', $this->vacant->id)->exists())->toBeFalse()
        ->and(Resident::query()->where('full_name', 'Tess Join')->exists())->toBeFalse();
});

it('adds a list that passes whole, each person Pending, with an invite recorded and nobody emailed', function () {
    $csv = implode("\n", [
        'Lot,Full name,Relationship,Email,Phone,Moved in',
        'Lot 9874,Nia Import,owner,nia.import@example.com,876 555 0101,2024-09-02',
        '9874,Omar Import,spouse,,876 555 0102,',
        'Lot 9875,Tess Join,tenant,tess.join@example.com,,',
    ]);

    $token = previewResidents($this, $this->office, $csv);

    $this->actingAs($this->office)->post(FacilitiesFixture::url('/residents/import/commit'), ['token' => $token])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', '3 people added to the register as Pending, 1 new household. No invite has been sent: send each from the lot\'s page.');
    FacilitiesFixture::boot();

    $nia = Resident::query()->where('full_name', 'Nia Import')->sole();
    $omar = Resident::query()->where('full_name', 'Omar Import')->sole();
    $tess = Resident::query()->where('full_name', 'Tess Join')->sole();

    expect($nia->household->unit_id)->toBe($this->vacant->id)
        ->and((bool) $nia->is_primary)->toBeTrue()
        ->and($nia->status)->toBe(Resident::PENDING)
        ->and($nia->verified_at)->toBeNull()
        ->and($nia->relationship)->toBe('owner')
        ->and((string) $nia->moved_in_on)->toStartWith('2024-09-02')
        ->and($omar->household_id)->toBe($nia->household_id)
        ->and((bool) $omar->is_primary)->toBeFalse()
        ->and($omar->relationship)->toBe('spouse')
        ->and($tess->household->name)->toBe('Present household')
        ->and($tess->relationship)->toBe('tenant');

    // An invite recorded for each, and none sent: the lot's page offers to send it.
    $invites = ResidentInvite::query()->whereIn('resident_id', [$nia->id, $omar->id, $tess->id])->get();
    expect($invites)->toHaveCount(3)
        ->and($invites->every(fn (ResidentInvite $invite): bool => (int) $invite->attempts === 0 && $invite->sent_at === null))->toBeTrue();
    Mail::assertNothingSent();
    Notification::assertNothingSent();

    $entry = AuditEntry::query()->where('action', 'residents.imported')->latest('id')->firstOrFail();
    expect($entry->after['people'])->toBe(3)
        ->and($entry->after['households'])->toBe(1)
        ->and($entry->after['invites_sent'])->toBe(0);
});

it('refuses the import to a role that may not add a resident', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::VICE_PRESIDENT))
        ->post(FacilitiesFixture::url('/residents/import/preview'), [
            'file' => UploadedFile::fake()->createWithContent('residents.csv', "lot,name,email\nLot 9874,Nia Import,nia@example.com"),
        ])
        ->assertForbidden();
});
