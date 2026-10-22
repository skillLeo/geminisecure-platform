<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\EstateAssignment;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Records addressed by id answer what the register would (bug hunt B072)
|--------------------------------------------------------------------------
|
| `records/residents|households|units/{id}` are the rows the Residents
| register is made of, read one at a time — and they carried no gate at all.
| Every seeded estate role holds `estate.residents.view`, so for the committee
| that was invisible; but a Gemini account with platform-wide scope passes
| `EnsureEstateAccess` at every estate and holds no estate permission, and it
| read every resident's name, email and phone by walking the ids, while the
| register itself answered it 403. The route now asks the register's own
| question.
|
| AND A HOUSEHOLD'S RESTRICTED FLAG IS MONEY (D-139). "A list of who is behind
| is itself about what they pay": walking households/1..N and collecting
| `access_restricted` is the arrears register rebuilt, so it goes only to a
| viewer the position rule lets see the estate's money — omitted, not blanked,
| for everybody else, as every other withheld key is.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // One household of this file's own, restricted, so the flag is a real
    // answer and not a false that would agree with anything.
    $unit = FacilitiesFixture::scratchUnit('Lot 996');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Recordcheck household', 'access_restricted' => true]);
    $household->forceFill(['access_restricted' => true])->save();
    $resident = Resident::query()->firstOrCreate(
        ['household_id' => $household->id, 'full_name' => 'Rhea Recordcheck'],
        ['relationship' => 'owner', 'is_primary' => true, 'email' => 'rhea.recordcheck@example.test', 'phone' => '(876) 555 0196'],
    );

    $this->records = [
        'residents' => FacilitiesFixture::url('/records/residents/'.$resident->id),
        'households' => FacilitiesFixture::url('/records/households/'.$household->id),
        'units' => FacilitiesFixture::url('/records/units/'.$unit->id),
    ];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('refuses every Gemini role the three records, exactly as the register refuses it', function () {
    /*
     * Every Gemini role, including the Head of Security with this site in their
     * scope: they reach the estate by assignment and hold no estate permission,
     * so the register refuses them — and the records must say the same.
     */
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create([
        'user_id' => $head->id,
        'tenant_id' => FacilitiesFixture::ESTATE,
        'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id,
        'is_active' => true,
    ]);

    $viewers = [
        Role::ACCOUNTANT => FacilitiesFixture::geminiViewer(Role::ACCOUNTANT),
        Role::ADMIN_ASSISTANT => FacilitiesFixture::geminiViewer(Role::ADMIN_ASSISTANT),
        Role::DISPATCHER => FacilitiesFixture::geminiViewer(Role::DISPATCHER),
        Role::OPERATIONS_MANAGER => FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER),
        Role::DIRECTOR => FacilitiesFixture::geminiViewer(Role::DIRECTOR),
        Role::HEAD_OF_SECURITY => $head->fresh(),
    ];

    foreach ($viewers as $roleName => $viewer) {
        // They do reach the estate — the refusal below is the permission's.
        expect($viewer->canAccessEstate(FacilitiesFixture::ESTATE))->toBeTrue($roleName);

        $this->actingAs($viewer)->getJson(FacilitiesFixture::url('/residents'))->assertForbidden();

        foreach ($this->records as $resource => $url) {
            $response = $this->actingAs($viewer)->getJson($url);

            expect($response->status())->toBe(403, "{$roleName} read {$resource} by id")
                ->and($response->getContent())->not->toContain('rhea.recordcheck@example.test')
                ->and($response->getContent())->not->toContain('555 0196');
        }
    }
});

it('serves the three records to every estate role that reads the register, and the restricted flag only to those who may see the money', function () {
    $roles = [
        Role::COMMUNITY_SUPER_ADMIN => true,
        Role::PRESIDENT => true,
        Role::VICE_PRESIDENT => true,
        Role::TREASURER => true,
        Role::SECRETARY => false,
        Role::PROPERTY_MANAGER => false,
        Role::ESTATE_ADMIN_ASSISTANT => false,
        Role::ESTATE_HEAD_OF_SECURITY => false,
    ];

    foreach ($roles as $roleName => $seesMoney) {
        $viewer = FacilitiesFixture::viewer($roleName);

        // The positive control: every seeded estate role holds the register.
        // A role a community makes need not, and is refused like a Gemini one
        // (RolesAreDynamicTest, on the isolation gate's subject).
        expect($viewer->can('estate.residents.view'))->toBeTrue($roleName);

        $this->actingAs($viewer)->getJson($this->records['residents'])
            ->assertOk()
            ->assertJsonPath('full_name', 'Rhea Recordcheck');

        $this->actingAs($viewer)->getJson($this->records['units'])
            ->assertOk()
            ->assertJsonPath('reference', 'Lot 996');

        $household = $this->actingAs($viewer)->getJson($this->records['households'])
            ->assertOk()
            ->assertJsonPath('name', 'Recordcheck household')
            ->json();

        if ($seesMoney) {
            expect($household)->toHaveKey('access_restricted')
                ->and($household['access_restricted'])->toBeTrue($roleName);
        } else {
            expect($household)->not->toHaveKey('access_restricted', $roleName);
        }
    }
});

it('refuses a Gemini account the same way for an id that exists and one that does not', function () {
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    foreach (['residents', 'households', 'units'] as $resource) {
        $this->actingAs($accountant)
            ->getJson(FacilitiesFixture::url('/records/'.$resource.'/99999999'))
            ->assertForbidden();
    }
});
