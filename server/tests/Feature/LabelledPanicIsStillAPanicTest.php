<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Events\AlertRaised;
use App\Events\AlertReachKnown;
use App\Models\DuressAlert;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Shift;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\PanicWatch;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A PANIC IS A PANIC, WHATEVER THE RESIDENT MANAGED TO SAY ABOUT IT (bug hunt B086)
|--------------------------------------------------------------------------
|
| M10 §1.3: "let the resident pick if they can — medical, fire, security, other —
| but never require it. An unlabelled panic goes to everyone exactly the same."
| The label is for the responder; it never makes the alert quieter.
|
| It did. A press labelled "Medical" or "Fire" is stored with that kind, and every
| urgency rule asked the kind: the broadcast went out `is_urgent: false`, so no
| dispatch screen sounded its siren; the alert was filed under Incidents, off the
| Panic alerts tab; its row was not red; and it sorted below every open panic. The
| same press with no label sounded the siren. The alert now records that it came
| from the panic button, and urgency asks that.
|
| AN INCIDENT AN OFFICER REPORTS IS STILL AN INCIDENT: a guard's "fire at Lot 12"
| from `POST /alerts` is not a panic press, and stays where it was.
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
    Notification::fake();
    Event::fake([AlertRaised::class, AlertReachKnown::class]);

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();
    config(['services.sms.driver' => 'log']);

    // Nothing of anybody else's on this estate's queue.
    DuressAlert::query()->where('tenant_id', $this->site)->whereNotIn('status', ['resolved', 'false_alarm'])->update(['status' => 'resolved', 'resolved_at' => now()]);
    Shift::query()->where('tenant_id', $this->site)->delete();
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    $unit = FacilitiesFixture::scratchUnit('Lot 471');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Labelled household', 'access_restricted' => false]);
    $resident = Resident::query()->firstOrCreate(['household_id' => $household->id, 'full_name' => 'Rae Labelled'], ['relationship' => 'owner', 'is_primary' => true]);

    $account = ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'rae.labelled@example.com', 'full_name' => 'Rae Labelled',
        'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->residentToken = $account->createToken('resident-app:rae.labelled', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

function labelledPress(TestCase $test, ?string $type): DuressAlert
{
    app('auth')->forgetGuards();

    $answer = $test->withToken($test->residentToken)->postJson('/api/v1/duress', array_filter(['mode' => 'audible', 'type' => $type]), [
        'Idempotency-Key' => 'labelled-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ])->assertCreated();

    FacilitiesFixture::boot();

    return DuressAlert::query()->findOrFail($answer->json('id'));
}

/** @return array<string, mixed> the broadcast this alert went out with */
function labelledBroadcast(DuressAlert $alert): array
{
    $payload = null;

    Event::assertDispatched(AlertRaised::class, function (AlertRaised $event) use ($alert, &$payload): bool {
        if ((int) $event->alert->id !== (int) $alert->id) {
            return false;
        }

        $payload = $event->broadcastWith();

        return true;
    });

    return $payload;
}

/** @return list<array<string, mixed>> the dispatch queue's rows on one tab */
function labelledQueue(TestCase $test, string $tab): array
{
    app('auth')->forgetGuards();

    $rows = $test->actingAs($test->dispatcher)->get('/dispatch/alerts?tab='.$tab)->assertOk()->viewData('page')['props']['alerts'];

    FacilitiesFixture::boot();

    return array_values(array_filter($rows, static fn (array $row): bool => str_starts_with((string) $row['key'], 'alert-')));
}

it('broadcasts a resident\'s medical or fire panic as urgent, exactly as an unlabelled one', function (?string $type) {
    $alert = labelledPress($this, $type);

    expect(labelledBroadcast($alert)['is_urgent'])->toBeTrue();
})->with(['unlabelled' => [null], 'medical' => ['medical'], 'fire' => ['fire'], 'security' => ['security'], 'other' => ['other']]);

it('keeps the label for the responder while treating the press as the panic it is', function () {
    $alert = labelledPress($this, 'medical');

    // The label is still what the responder reads.
    expect($alert->kind)->toBe('medical')
        ->and(labelledBroadcast($alert)['kind_label'])->toBe('Medical');

    // On the Panic alerts tab, not under Incidents, and drawn urgent.
    $panic = collect(labelledQueue($this, 'panic'))->firstWhere('key', 'alert-'.$alert->id);

    expect($panic)->not->toBeNull()
        ->and($panic['urgent'])->toBeTrue()
        ->and($panic['icon'])->toBe('panic')
        ->and(collect(labelledQueue($this, 'incidents'))->pluck('key'))->not->toContain('alert-'.$alert->id);

    // The Gemini console's pop-up calls it urgent too.
    $watched = collect(app(PanicWatch::class)->forViewer($this->dispatcher)['alerts'])->firstWhere('id', $alert->id);

    expect($watched['is_urgent'])->toBeTrue();
});

it('ranks a labelled panic with the panics, above an incident an officer reported after it', function () {
    Carbon::setTestNow(Carbon::now()->subMinutes(2));
    $fire = labelledPress($this, 'fire');
    Carbon::setTestNow();

    // A MEDICAL INCIDENT reported by an officer on the legacy endpoint: an incident, and newer.
    $post = Post::create(['tenant_id' => $this->site, 'name' => 'Label Gate', 'type' => 'gate', 'is_active' => true]);
    $officer = Guard::create([
        'full_name' => 'Officer Reporter', 'employee_number' => 'GS-LBL-1', 'psra_number' => 'PSRA-GS-LBL-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->site, 'post_id' => $post->id,
    ]);
    $token = app(DeviceEnrolment::class)->enrol($officer, 'Label handset')['token'];

    app('auth')->forgetGuards();
    $reported = $this->withToken($token)->postJson('/api/v1/alerts', ['kind' => 'medical', 'unit_reference' => 'Lot 12'], [
        'Idempotency-Key' => 'labelled-incident-'.bin2hex(random_bytes(6)),
        'X-Device-Time' => now()->toIso8601String(),
    ])->assertCreated();
    FacilitiesFixture::boot();

    $incident = DuressAlert::query()->findOrFail($reported->json('id'));

    $order = collect(labelledQueue($this, 'all'))->pluck('key')->all();

    expect(array_search('alert-'.$fire->id, $order, true))->toBeLessThan(array_search('alert-'.$incident->id, $order, true));

    // …and the officer's report is still an incident: not urgent, not on the Panic tab.
    expect(labelledBroadcast($incident)['is_urgent'])->toBeFalse()
        ->and(collect(labelledQueue($this, 'panic'))->pluck('key'))->not->toContain('alert-'.$incident->id)
        ->and(collect(labelledQueue($this, 'incidents'))->pluck('key'))->toContain('alert-'.$incident->id);
});
