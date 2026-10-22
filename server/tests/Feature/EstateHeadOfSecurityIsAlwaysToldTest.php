<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Jobs\Alerts\SendHeadsOfSecuritySms;
use App\Jobs\Alerts\SendHouseholdContactsSms;
use App\Jobs\Alerts\SendPanicSms;
use App\Jobs\Push\SendExpoPush;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\EstateAssignment;
use App\Models\PlatformSmsSetting;
use App\Models\Role;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PanicRaisedAtSite;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\HeadsOfSecuritySms;
use App\Services\Dispatch\SiteEscalation;
use App\Services\Push\PanicDeliveries;
use App\Services\Rbac\RoleEditor;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| THE ESTATE'S OWN HEAD OF SECURITY IS ALWAYS TOLD, WHATEVER THE ROLE IS CALLED
| AND WHEREVER IT SORTS (bug hunt B092, B139)
|--------------------------------------------------------------------------
|
| M10 §1.2 item 5: "The estate's own Head of Security — dashboard alert plus
| email/SMS." Two ways that stopped being true:
|
|   B092  The alert mail is capped at ten people, and the estate's Head of Security
|         sorts LAST among the estate's roles (70). Two Gemini Heads of Security and
|         eight proven committee members used every place, so the estate's Head of
|         Security was never mailed — and the record said there was none.
|
|   B139  The shared Head of Security cannot be changed from an estate; the console
|         says "Copy it". An estate that did so, and moved its Head of Security onto
|         the copy, stopped texting them (the SMS leg asked for the role by NAME) and
|         the record said the estate had no Head of Security of its own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Http::preventStrayRequests();
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class, SendHeadsOfSecuritySms::class]);
    Notification::fake();

    PlatformSmsSetting::query()->delete();
    config(['services.sms.driver' => 'log', 'services.sms.account_sid' => null, 'services.sms.token' => null, 'services.sms.from' => null]);

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();

    // Every person and number here is this file's.
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    FacilitiesFixture::boot();
});

/* ── helpers ─────────────────────────────────────────────────────────── */

function alwaysToldGeminiHead(TestCase $test): User
{
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    EstateAssignment::create([
        'user_id' => $head->id, 'tenant_id' => $test->site,
        'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true,
    ]);

    return $head->fresh();
}

/** An estate console user holding exactly this role at the fixture estate — seeded or a copy. */
function alwaysToldMember(Role $role, ?string $phone = null): User
{
    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active', 'contact_phone' => $phone]);
    $user->syncRoles([$role->name]);

    EstateAssignment::create([
        'user_id' => $user->id, 'tenant_id' => FacilitiesFixture::ESTATE,
        'role_id' => $role->id, 'is_active' => true,
    ]);

    return $user->fresh();
}

/** What the Community Super Admin is told to do: copy the shared Head of Security for this estate. */
function alwaysToldCopyOfTheHeadOfSecurity(): Role
{
    $admin = alwaysToldMember(Role::named(Role::COMMUNITY_SUPER_ADMIN));

    $copy = FacilitiesFixture::platform()->run(
        static fn (): Role => app(RoleEditor::class)->copy($admin, Role::named(Role::ESTATE_HEAD_OF_SECURITY), 'Our Head of Security'),
    );

    FacilitiesFixture::boot();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($copy->name)->not->toBe(Role::ESTATE_HEAD_OF_SECURITY)
        ->and((int) $copy->copied_from_id)->toBe(Role::named(Role::ESTATE_HEAD_OF_SECURITY)->id);

    return $copy;
}

function alwaysToldPanic(TestCase $test): DuressAlert
{
    return app(AlertIntake::class)->record(tenantId: $test->site, kind: 'panic', raisedByName: 'Rae Always', unitReference: 'Lot 47');
}

/** @return Illuminate\Support\Collection<int, DuressAlertDelivery> */
function alwaysToldLegs(DuressAlert $alert, string $destination, string $channel): Illuminate\Support\Collection
{
    return DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)
        ->where('destination', $destination)->where('channel', $channel)->orderBy('id')->get();
}

/* ── B092 · the cap never drops a Head of Security ───────────────────── */

it('mails the estate\'s own Head of Security even when ten proven people rank ahead of them', function () {
    alwaysToldGeminiHead($this);
    alwaysToldGeminiHead($this);

    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::VICE_PRESIDENT, Role::SECRETARY, Role::PROPERTY_MANAGER, Role::TREASURER, Role::ESTATE_ADMIN_ASSISTANT, Role::ESTATE_ADMIN_ASSISTANT] as $name) {
        alwaysToldMember(Role::named($name));
    }

    $estateHead = alwaysToldMember(Role::named(Role::ESTATE_HEAD_OF_SECURITY));

    ['mail' => $mail] = app(SiteEscalation::class)->alertRecipientsFor($this->site);

    expect($mail->pluck('id'))->toContain($estateHead->id);

    $alert = alwaysToldPanic($this);

    Notification::assertSentTo($estateHead, PanicRaisedAtSite::class);

    $legs = alwaysToldLegs($alert, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY, DuressAlertDelivery::EMAIL);

    expect($legs)->toHaveCount(1)
        ->and($legs->first()->recipients)->toBe(1)
        ->and($legs->first()->note)->not->toContain('No Head of Security of the estate\'s own');
});

it('still caps the rest of the committee, so a panic is an escalation and not a broadcast', function () {
    $geminiHead = alwaysToldGeminiHead($this);
    $estateHead = alwaysToldMember(Role::named(Role::ESTATE_HEAD_OF_SECURITY));

    foreach (range(1, SiteEscalation::MAX_CONTACTS + 3) as $ignored) {
        alwaysToldMember(Role::named(Role::ESTATE_ADMIN_ASSISTANT));
    }

    ['mail' => $mail] = app(SiteEscalation::class)->alertRecipientsFor($this->site);

    // Both Heads of Security, outside the cap, and the committee capped at ten.
    expect($mail->pluck('id'))->toContain($geminiHead->id)
        ->and($mail->pluck('id'))->toContain($estateHead->id)
        ->and($mail)->toHaveCount(2 + SiteEscalation::MAX_CONTACTS);
});

it('keeps the holder of a copy of the role outside the cap too', function () {
    $copy = alwaysToldCopyOfTheHeadOfSecurity();

    foreach (range(1, SiteEscalation::MAX_CONTACTS) as $ignored) {
        alwaysToldMember(Role::named(Role::PRESIDENT));
    }

    $head = alwaysToldMember($copy);

    ['mail' => $mail] = app(SiteEscalation::class)->alertRecipientsFor($this->site);

    expect($mail->pluck('id'))->toContain($head->id);
});

/* ── B139 · a copy of the role is still the estate's Head of Security ── */

it('texts the estate\'s Head of Security who holds a copy of the role, and records them as the estate\'s own', function () {
    $copy = alwaysToldCopyOfTheHeadOfSecurity();
    $head = alwaysToldMember($copy, '876 555 0147');

    $plan = app(HeadsOfSecuritySms::class)->plan(new DuressAlert(['tenant_id' => $this->site]));

    expect(collect($plan)->where('group', HeadsOfSecuritySms::ESTATE)->pluck('user_id')->all())->toBe([$head->id]);

    $alert = alwaysToldPanic($this);

    expect($alert->fresh()->staff_sms_recipients)->toBe(1);

    // The text: planned, to their own phone, and said as the estate's own Head of Security's.
    $own = alwaysToldLegs($alert, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY, DuressAlertDelivery::SMS)
        ->filter(static fn (DuressAlertDelivery $leg): bool => str_starts_with((string) $leg->note, PanicDeliveries::OWN_PHONE));

    expect($own)->toHaveCount(1)
        ->and($own->first()->recipients)->toBe(1)
        ->and($own->first()->note)->not->toContain('no Head of Security of its own on file');

    // The mail: filed under who they are, not under "the estate's console".
    Notification::assertSentTo($head, PanicRaisedAtSite::class);

    $mailed = alwaysToldLegs($alert, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY, DuressAlertDelivery::EMAIL);

    expect($mailed)->toHaveCount(1)
        ->and($mailed->first()->recipients)->toBe(1)
        ->and($mailed->first()->note)->not->toContain('No Head of Security of the estate\'s own');

    // The estate console's mail row counts the rest of the committee — here the Community
    // Super Admin who made the copy — and never the Head of Security a second time.
    expect(alwaysToldLegs($alert, DuressAlertDelivery::ESTATE_CONSOLE, DuressAlertDelivery::EMAIL)->sum('recipients'))->toBe(1);
});

it('never treats another estate\'s copy, or a copy of a different role, as this estate\'s Head of Security', function () {
    // A copy of the TREASURER, relabelled to sound like security, is not the Head of Security.
    $admin = alwaysToldMember(Role::named(Role::COMMUNITY_SUPER_ADMIN));
    $treasurerCopy = FacilitiesFixture::platform()->run(
        static fn (): Role => app(RoleEditor::class)->copy($admin, Role::named(Role::TREASURER), 'Security Liaison'),
    );
    FacilitiesFixture::boot();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $liaison = alwaysToldMember($treasurerCopy, '876 555 0199');

    // ANOTHER estate's copy of the Head of Security: that estate's, and never this one's.
    $theirs = new Role;
    $theirs->forceFill([
        'name' => 'estate.custom.anotherestate.their-head-of-security', 'guard_name' => 'web', 'label' => 'Their Head of Security',
        'console' => Console::Estate->value, 'tenant_id' => 'anotherestate', 'is_system' => false, 'sort' => 90,
        'copied_from_id' => Role::named(Role::ESTATE_HEAD_OF_SECURITY)->id,
    ])->save();

    $plan = app(HeadsOfSecuritySms::class)->plan(new DuressAlert(['tenant_id' => $this->site]));

    expect(collect($plan)->pluck('user_id')->all())->not->toContain($liaison->id)
        ->and(SiteEscalation::estateHeadOfSecurityRoles($this->site))->toContain(Role::named(Role::ESTATE_HEAD_OF_SECURITY)->id)
        ->and(SiteEscalation::estateHeadOfSecurityRoles($this->site))->not->toContain($theirs->id)
        ->and(SiteEscalation::estateHeadOfSecurityRoles($this->site))->not->toContain($treasurerCopy->id)
        ->and(SiteEscalation::estateHeadOfSecurityRoles('anotherestate'))->toContain($theirs->id);
});
