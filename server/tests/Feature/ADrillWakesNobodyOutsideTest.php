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
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A drill reaches the officers it proves, labelled DRILL, and wakes nobody outside them (M69 SEC-2; M73 §0.2, §2.2)
|--------------------------------------------------------------------------
|
| `panic:walk` raises a real alert marked as a drill to prove the fan-out. It still mailed every Head of Security and
| the committee ("DRILL" in the subject) and queued texts to the site's numbers, the Heads of Security and the
| household's contacts: real people woken at night by a test. Hassam (M73): fix it tonight. A drill now tells the
| officers' phones, as it always did, and none of the five outside legs; each says so where the alert's record is read.
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

    // Every person and number here is this file's: the estate's own Head of Security, with a phone, and one site number.
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    $this->head = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active', 'contact_phone' => '876 555 0161']);
    $this->head->syncRoles([Role::ESTATE_HEAD_OF_SECURITY]);
    EstateAssignment::create(['user_id' => $this->head->id, 'tenant_id' => $this->site, 'role_id' => Role::named(Role::ESTATE_HEAD_OF_SECURITY)->id, 'is_active' => true]);

    SiteEmergencyNumber::query()->create([
        'tenant_id' => $this->site, 'role' => 'dispatch', 'label' => 'Gemini Dispatch', 'number' => '876-354-2119', 'e164' => '+18763542119', 'is_active' => true, 'sort' => 1,
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    FacilitiesFixture::boot();
});

function drillOutsideAlert(string $site, bool $drill): DuressAlert
{
    return app(AlertIntake::class)->record(tenantId: $site, kind: 'panic', raisedByName: 'Rae Drill', unitReference: 'Lot 47', isDrill: $drill);
}

it('mails and texts the people outside on a real panic, so a drill that does not is not just a quiet estate', function () {
    $alert = drillOutsideAlert($this->site, false);

    Notification::assertSentTo($this->head, PanicRaisedAtSite::class);
    Bus::assertDispatched(SendPanicSms::class, fn (SendPanicSms $job): bool => $job->alertId === $alert->id);
    Bus::assertDispatched(SendHeadsOfSecuritySms::class, fn (SendHeadsOfSecuritySms $job): bool => $job->alertId === $alert->id);
});

it('mails and texts nobody outside the officers on a drill, and records each leg as skipped for a drill', function () {
    $drill = drillOutsideAlert($this->site, true);

    expect($drill->is_drill)->toBeTrue();

    Notification::assertNotSentTo($this->head, PanicRaisedAtSite::class);
    Bus::assertNotDispatched(SendPanicSms::class, fn (SendPanicSms $job): bool => $job->alertId === $drill->id);
    Bus::assertNotDispatched(SendHeadsOfSecuritySms::class, fn (SendHeadsOfSecuritySms $job): bool => $job->alertId === $drill->id);
    Bus::assertNotDispatched(SendHouseholdContactsSms::class, fn (SendHouseholdContactsSms $job): bool => $job->alertId === $drill->id);

    expect((int) $drill->fresh()->escalated_contacts)->toBe(0)
        ->and(DuressAlertDelivery::query()->where('duress_alert_id', $drill->id)->where('outcome', DuressAlertDelivery::SKIPPED)->where('note', 'like', '%drill%')->exists())->toBeTrue();
});
