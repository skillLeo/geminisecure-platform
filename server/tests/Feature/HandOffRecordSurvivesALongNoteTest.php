<?php

declare(strict_types=1);

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
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Services\Push\PanicDeliveries;
use App\Services\Push\PushAnnouncer;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| ONE LONG SENTENCE NEVER COSTS AN ALERT ITS WHOLE HAND-OFF RECORD (bug hunt B091)
|--------------------------------------------------------------------------
|
| `duress_alert_deliveries.note` was 240 characters and the Heads of Security's
| sentence reached 255 (one texted, one deduplicated, no provider) and 317 (plus
| one with no phone). MySQL runs strict, so the one insert that writes every leg
| was refused, the error was reported and swallowed, and the dispatcher's screen
| and `panic:walk` said "No hand-off was recorded for this alert" — for all seven
| of §1.2's destinations, not only the one with the long sentence.
|
| The column is wide enough for every sentence the recorder writes, and a row that
| is still refused is written on its own so it cannot take the others with it.
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

    // NO PROVIDER — the platform today, and the case that wrote the 255-character sentence.
    PlatformSmsSetting::query()->delete();
    config(['services.sms.driver' => 'log', 'services.sms.account_sid' => null, 'services.sms.token' => null, 'services.sms.from' => null]);

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();

    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

function longNoteEstateHead(?string $phone): User
{
    $head = FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);
    $head->forceFill(['contact_phone' => $phone])->save();

    return $head->fresh();
}

/** The site's own "PPV1 Head of Security" number — the phone one of the estate's heads also gave. */
function longNoteSiteNumber(TestCase $test): void
{
    app(SiteEmergencyNumbers::class)->put($test->site, 'head_of_security', 'PPV1 Head of Security', '1-876-845-9422', 1);
}

function longNotePanic(TestCase $test): DuressAlert
{
    return app(AlertIntake::class)->record(tenantId: $test->site, kind: 'panic', raisedByName: 'Rae Longnote', unitReference: 'Lot 47');
}

it('keeps every leg of the record when the Heads of Security\'s sentence is 255 characters', function () {
    longNoteSiteNumber($this);
    longNoteEstateHead('876 845 9422');   // the site's own number: deduplicated
    longNoteEstateHead('876 555 0123');   // a phone of their own: texted, and written down

    $alert = longNotePanic($this);
    $legs = DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)->get();

    $own = $legs->first(static fn (DuressAlertDelivery $leg): bool => $leg->destination === DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY
        && $leg->channel === DuressAlertDelivery::SMS
        && str_starts_with((string) $leg->note, PanicDeliveries::OWN_PHONE));

    expect($legs->count())->toBeGreaterThan(5)
        ->and($own)->not->toBeNull()
        ->and(mb_strlen((string) $own->note))->toBeGreaterThan(240)
        ->and($own->note)->toBe(PanicDeliveries::OWN_PHONE.'no SMS provider is connected, so the text to 1 Head of Security of the estate\'s own is written down and delivered to nobody. 1 Head of Security of the estate\'s own not texted twice: the same number is already being texted for this alert.');
});

it('keeps it when a head with no phone makes the sentence longer still', function () {
    longNoteSiteNumber($this);
    longNoteEstateHead('876 845 9422');
    longNoteEstateHead('876 555 0123');
    longNoteEstateHead(null);

    $alert = longNotePanic($this);

    $own = DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)
        ->where('destination', DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY)->where('channel', DuressAlertDelivery::SMS)
        ->get()
        ->first(static fn (DuressAlertDelivery $leg): bool => str_starts_with((string) $leg->note, PanicDeliveries::OWN_PHONE));

    expect($own)->not->toBeNull()
        ->and($own->note)->toEndWith('1 Head of Security of the estate\'s own with no phone on file.');
});

it('writes the legs one at a time when the one insert is refused, so one bad row cannot drop the rest', function () {
    Exceptions::fake();

    // The one-statement insert is refused; a row on its own is not.
    app()->instance(PanicDeliveries::class, new class(app(SiteEmergencyNumbers::class)) extends PanicDeliveries
    {
        protected function insert(array $rows): void
        {
            if (count($rows) > 1) {
                throw new RuntimeException('the whole record was refused');
            }

            parent::insert($rows);
        }
    });
    app()->forgetInstance(PushAnnouncer::class);

    $alert = longNotePanic($this);

    expect(DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)->count())->toBeGreaterThan(5);
    Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'the whole record was refused');
});
