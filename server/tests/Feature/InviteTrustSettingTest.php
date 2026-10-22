<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\EstateSetting;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| "Trust the main resident's invites" — off by default, and two permissions to change (D-156)
|--------------------------------------------------------------------------
|
| An estate rule about its own register, drawn on Settings → Features under "Resident App". Turning it on hands
| the household's primary resident the office's approval of a unit claim, so it needs Settings configure AND
| Residents approve, a typed reason, and it is audited. It is never set by a stray form key.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    EstateSetting::current()->forceFill(['trust_primary_invites' => false])->save();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    EstateSetting::current()->forceFill(['trust_primary_invites' => false])->save();
});

function trustPost(object $test, object $user, array $body): Illuminate\Testing\TestResponse
{
    $response = $test->actingAs($user)->post(FacilitiesFixture::url('/settings/household-invites/trust'), $body);
    FacilitiesFixture::boot();

    return $response;
}

it('is off by default and cannot be switched on by a stray form key', function () {
    $column = collect(Schema::connection('tenant')->getColumns('estate_settings'))->firstWhere('name', 'trust_primary_invites');

    expect($column)->not->toBeNull()
        ->and((string) $column['default'])->toBeIn(['0', "'0'"]);

    $settings = EstateSetting::current();
    $settings->fill(['trust_primary_invites' => true]);

    expect((bool) $settings->trust_primary_invites)->toBeFalse();
});

it('is drawn on the features page with its three sentences, and says who may change it', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    $this->actingAs($admin)->get(FacilitiesFixture::url('/settings/features'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('residentInvites.enabled', false)
            ->where('residentInvites.label', 'Trust the main resident’s invites')
            ->where('residentInvites.canChange', $admin->can('estate.settings.configure') && $admin->can('estate.residents.approve'))
            ->where('residentInvites.sentences.0', 'Off (recommended): every member a primary resident invites waits for one tap in Unit claims.')
            ->has('residentInvites.sentences', 3));
});

it('switches on and off with a typed reason, each change audited with the reason', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    expect($admin->can('estate.settings.configure'))->toBeTrue()
        ->and($admin->can('estate.residents.approve'))->toBeTrue();

    trustPost($this, $admin, ['enabled' => true, 'reason' => 'The committee agreed at the AGM.'])
        ->assertRedirect(FacilitiesFixture::url('/settings/features'))
        ->assertSessionHasNoErrors();

    expect((bool) EstateSetting::current()->trust_primary_invites)->toBeTrue();

    $entry = AuditEntry::query()->where('tenant_id', FacilitiesFixture::ESTATE)->where('action', 'estate.settings.invite_trust_changed')->orderByDesc('id')->firstOrFail();
    expect($entry->before)->toBe(['trust_primary_invites' => false])
        ->and($entry->after)->toEqual(['trust_primary_invites' => true, 'reason' => 'The committee agreed at the AGM.'])
        ->and((int) $entry->actor_id)->toBe((int) $admin->id);

    // The same state again is refused in words, and writes nothing.
    trustPost($this, $admin, ['enabled' => true, 'reason' => 'Again, to be sure.'])->assertSessionHasErrors('invite_trust');

    trustPost($this, $admin, ['enabled' => false, 'reason' => 'Back to one tap for every member.'])->assertSessionHasNoErrors();
    expect((bool) EstateSetting::current()->trust_primary_invites)->toBeFalse();
});

it('refuses a change with no reason, or one too short to be one', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    trustPost($this, $admin, ['enabled' => true])->assertSessionHasErrors('reason');
    trustPost($this, $admin, ['enabled' => true, 'reason' => 'ok'])->assertSessionHasErrors('reason');

    expect((bool) EstateSetting::current()->trust_primary_invites)->toBeFalse();
});

it('needs Settings configure, and Residents approve as well', function () {
    // No Settings configure: the route refuses.
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    expect($manager->can('estate.settings.configure'))->toBeFalse();
    trustPost($this, $manager, ['enabled' => true, 'reason' => 'I would like it on.'])->assertForbidden();

    // Settings configure without Residents approve: refused in words, and nothing changes.
    $configurer = FacilitiesFixture::viewer(Role::TREASURER);
    $configurer->givePermissionTo(['estate.settings.view', 'estate.settings.configure']);
    $configurer = $configurer->fresh();
    expect($configurer->can('estate.settings.configure'))->toBeTrue()
        ->and($configurer->can('estate.residents.approve'))->toBeFalse();

    trustPost($this, $configurer, ['enabled' => true, 'reason' => 'I would like it on.'])
        ->assertSessionHasErrors(['invite_trust' => 'Trusting the main resident’s invites hands them the approval of a unit claim, so it needs Residents approve access as well as Settings configure. Ask somebody who approves unit claims.']);

    expect((bool) EstateSetting::current()->trust_primary_invites)->toBeFalse()
        ->and(AuditEntry::query()->where('tenant_id', FacilitiesFixture::ESTATE)->where('action', 'estate.settings.invite_trust_changed')->where('actor_id', $configurer->id)->exists())->toBeFalse();
});
