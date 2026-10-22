<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Alerts\SendHouseholdContactsSms;
use App\Jobs\Alerts\SendPanicSms;
use App\Jobs\Push\SendExpoPush;
use App\Models\AlertSmsDispatch;
use App\Models\DuressAlert;
use App\Models\Estate\Household;
use App\Models\PlatformSmsSetting;
use App\Models\ResidentAccount;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Services\Dispatch\HouseholdContactsSms;
use App\Services\Estate\EmergencyContactMerge;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HouseholdHolder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| One emergency contact per number (DESIGN §14)
|--------------------------------------------------------------------------
|
| The user's screenshot listed "Marcia Ahmed · Spouse · 876-555-0148" FOUR times under Emergency contacts, and
| nothing on the phone could remove them. `contacts.store` inserted on every call and the table has no unique
| key; four Saves were four rows. It matters in an emergency: a panic texts the household's first five, so the
| same person was texted four times and a genuine fifth never was.
|
| Now a number already on the household's list is answered with the row already there (compared as a diallable
| number, however it was typed), a repair merges the copies already stored, and the panic texts five DIFFERENT
| people, counting people rather than rows.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lots, and every contact it adds is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // The framework's TESTING transaction manager, so a panic's "after commit" fan-out runs here as in production.
    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();

    PlatformSmsSetting::query()->delete();
    config(['services.sms.driver' => 'log', 'services.sms.account_sid' => null, 'services.sms.token' => null, 'services.sms.from' => null]);

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 6611');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Dedupe household', 'access_restricted' => false]);
    $this->resident = HouseholdHolder::of($this->household, 'Marcia Dedupe');

    $this->otherUnit = FacilitiesFixture::scratchUnit('Lot 6612');
    $this->other = Household::query()->firstOrCreate(['unit_id' => $this->otherUnit->id], ['name' => 'Other dedupe household', 'access_restricted' => false]);

    $account = ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'dedupe.'.bin2hex(random_bytes(3)).'@example.com', 'full_name' => 'Marcia Dedupe',
        'resident_id' => $this->resident->id, 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->account = $account;
    $this->token = $account->createToken('resident-app:dedupe', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    dedupeForget($this);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
    FacilitiesFixture::boot();

    dedupeForget($this);
});

function dedupeForget(TestCase $test): void
{
    DB::connection('tenant')->table('household_emergency_contacts')->whereIn('household_id', [$test->household->id, $test->other->id])->delete();
}

/**
 * Contacts on file, in order, past the API — the copies the old `contacts.store` let through.
 *
 * @param  list<array{0: string, 1: string|null, 2: string}>  $contacts
 * @return list<int>
 */
function dedupeContacts(int $householdId, array $contacts): array
{
    $ids = [];

    foreach ($contacts as [$name, $relationship, $phone]) {
        $ids[] = (int) DB::connection('tenant')->table('household_emergency_contacts')->insertGetId([
            'household_id' => $householdId, 'name' => $name, 'relationship' => $relationship, 'phone' => $phone, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $ids;
}

/** @param array<string, mixed> $body @param array<string, scalar> $params */
function dedupeCall(TestCase $test, string $name, array $body = [], ?int $status = null, array $params = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->json($entry['method'], $uri, $body, [
        'Idempotency-Key' => 'dedupe-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);
    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

function dedupeRows(int $householdId): Illuminate\Support\Collection
{
    return DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $householdId)->orderBy('id')->get();
}

it('answers a number already on the list with the row already there, however it was typed, and never adds a second (T-EC-1)', function () {
    $first = dedupeCall($this, 'contacts.store', ['name' => 'Marcia Ahmed', 'relationship' => 'Spouse', 'phone' => '876-555-0148', 'email' => 'marcia.ahmed@example.com']);
    expect($first->json('already_listed'))->toBeFalse();

    // The same number three other ways — each Save answered with the first row, 200, and nothing added.
    foreach (['+1 876 555 0148', '(876) 555-0148', '18765550148'] as $typed) {
        $again = dedupeCall($this, 'contacts.store', ['name' => 'Marcia Ahmed', 'relationship' => 'Spouse', 'phone' => $typed, 'email' => 'marcia.ahmed@example.com'], 200);

        // Stored in its one form (M18C §6), however it was typed.
        expect($again->json('id'))->toBe($first->json('id'))
            ->and($again->json('already_listed'))->toBeTrue()
            ->and($again->json('phone'))->toBe('+1 876 555 0148');
    }

    // A different number is a different person.
    $second = dedupeCall($this, 'contacts.store', ['name' => 'Karen Ahmed', 'relationship' => 'Sister', 'phone' => '876 555 0149', 'email' => 'karen.ahmed@example.com']);
    expect($second->json('already_listed'))->toBeFalse()->and($second->json('id'))->not->toBe($first->json('id'));

    expect(dedupeRows($this->household->id))->toHaveCount(2)
        ->and(collect(dedupeCall($this, 'contacts.index')->json('items'))->pluck('phone')->all())->toEqualCanonicalizing(['+1 876 555 0148', '+1 876 555 0149']);

    // Another household's list is its own: the same number there is not "already listed" here, nor there.
    dedupeContacts($this->other->id, [['Marcia Ahmed', 'Friend', '876 555 0150']]);
    expect(dedupeCall($this, 'contacts.store', ['name' => 'Marcia Ahmed', 'relationship' => 'Friend', 'phone' => '876 555 0150', 'email' => 'marcia.friend@example.com'])->json('already_listed'))->toBeFalse()
        ->and(dedupeRows($this->other->id))->toHaveCount(1);
});

it('merges only the TRUE copies already stored — same number, name and relationship — keeping the earliest, per household, reporting and auditing, and a second run changes nothing (T-EC-2)', function () {
    $ids = dedupeContacts($this->household->id, [
        ['Marcia Ahmed', 'Spouse', '876-555-0148'],
        ['Marcia Ahmed', 'Spouse', '+1 876 555 0148'],
        ['Karen Ahmed', 'Sister', '876 555 0149'],
        ['Marcia Ahmed', 'Spouse', '(876) 555-0148'],
        ['Marcia A.', 'Spouse', '876.555.0148'],
        ['marcia  ahmed', 'SPOUSE', '8765550148'],
    ]);
    $otherIds = dedupeContacts($this->other->id, [['Neighbour', null, '876 555 0148'], ['Neighbour', null, '8765550148']]);

    // A dry run says what it would do, per household, masked — and does nothing.
    $code = Artisan::call('household:merge-duplicate-contacts', ['--estate' => $this->site, '--dry-run' => true]);
    $out = Artisan::output();
    FacilitiesFixture::boot();

    expect($code)->toBe(0)
        ->and($out)->toContain('DRY RUN')
        ->and($out)->toContain('household '.$this->household->id)
        ->and($out)->toContain('+1876•••0148')
        // Case and spacing do not make two people: "marcia  ahmed · SPOUSE" is a copy.
        ->and($out)->toContain('would keep #'.$ids[0].', would remove #'.$ids[1].', #'.$ids[3].', #'.$ids[5])
        // "Marcia A." on the same number is a DIFFERENT row, and only a person may choose between the two.
        ->and($out)->toContain('NEEDS A PERSON')
        ->and($out)->toContain('#'.$ids[0].', #'.$ids[4])
        ->and($out)->not->toContain('876-555-0148')
        ->and($out)->not->toContain('Marcia')
        ->and(dedupeRows($this->household->id))->toHaveCount(6);

    // The real run.
    $code = Artisan::call('household:merge-duplicate-contacts', ['--estate' => $this->site]);
    FacilitiesFixture::boot();

    expect($code)->toBe(0)
        ->and(dedupeRows($this->household->id)->pluck('id')->all())->toBe([$ids[0], $ids[2], $ids[4]])
        ->and(dedupeRows($this->other->id)->pluck('id')->all())->toBe([$otherIds[0]]);

    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'household.contacts_merged')->where('entity_id', (string) $this->household->id)->first();
    $after = json_decode((string) $entry->after, true);
    $before = json_decode((string) $entry->before, true);
    expect($entry->tenant_id)->toBe($this->site)
        ->and($after['merged'][0]['kept'])->toBe($ids[0])
        ->and($after['merged'][0]['removed'])->toBe([$ids[1], $ids[3], $ids[5]])
        ->and($after['merged'][0]['number'])->toBe('+1876•••0148')
        // What went can be traced: each removed row's id, masked number and relationship — never a name.
        ->and(array_column($before['removed'], 'id'))->toBe([$ids[1], $ids[3], $ids[5]])
        ->and(array_column($before['removed'], 'relationship'))->toBe(['Spouse', 'Spouse', 'SPOUSE'])
        ->and((string) $entry->after.(string) $entry->before)->not->toContain('Marcia')
        ->and((string) $entry->after.(string) $entry->before)->not->toContain('marcia');

    // Idempotent: nothing more to merge, and the two different people are still only reported.
    $code = Artisan::call('household:merge-duplicate-contacts', ['--estate' => $this->site]);
    $out = Artisan::output();
    FacilitiesFixture::boot();

    expect($code)->toBe(0)->and($out)->toContain('none to merge')->and($out)->toContain('NEEDS A PERSON')
        ->and(dedupeRows($this->household->id))->toHaveCount(3)
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'household.contacts_merged')->where('entity_id', (string) $this->household->id)->count())->toBe(1);
});

it('never takes a number with no digits in it for another row: the store refuses it now, the repair leaves old ones, the panic counts each', function () {
    // "--------" is nobody's number: the store refuses it in words (M18C §6)…
    dedupeCall($this, 'contacts.store', ['name' => 'Blank One', 'relationship' => 'Friend', 'phone' => '--------', 'email' => 'blank.one@example.com'], 422)
        ->assertJsonPath('errors.phone.0', 'That does not look like a phone number. Example: 876 555 0123.');

    // …and two saved before that rule matched nothing, and are two people still.
    dedupeContacts($this->household->id, [['Blank One', 'Friend', '--------'], ['Blank Two', 'Friend', '( ) - - -']]);

    expect(EmergencyContactMerge::comparable(EmergencyContactMerge::numberKey('--------')))->toBeFalse();

    $code = Artisan::call('household:merge-duplicate-contacts', ['--estate' => $this->site]);
    FacilitiesFixture::boot();

    expect($code)->toBe(0)->and(dedupeRows($this->household->id))->toHaveCount(2)
        ->and(EmergencyContactMerge::distinct(dedupeRows($this->household->id)))->toHaveCount(2);
});

it('texts five DIFFERENT people on a panic, and counts people, not rows, beyond the five (T-EC-3)', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);

    // Four copies of one number first — the old fan-out's first five rows were those four and one other.
    $ids = dedupeContacts($this->household->id, [
        ['Marcia Ahmed', 'Spouse', '876-555-0148'],
        ['Marcia Ahmed', 'Spouse', '+1 876 555 0148'],
        ['Marcia Ahmed', 'Spouse', '(876) 555-0148'],
        ['Marcia Ahmed', 'Spouse', '8765550148'],
        ['Contact 1', 'relative', '876 555 0161'],
        ['Contact 2', 'relative', '876 555 0162'],
        ['Contact 3', 'relative', '876 555 0163'],
        ['Contact 4', 'relative', '876 555 0164'],
        ['Contact 5', 'relative', '876 555 0165'],
    ]);

    $answer = dedupeCall($this, 'duress.store', ['mode' => 'audible']);
    $alert = DuressAlert::query()->findOrFail((int) $answer->json('id'));

    // Six people on file: five texted, and ONE — a person — past the cap.
    expect($alert->household_sms_recipients)->toBe(5)
        ->and($alert->household_sms_over_cap)->toBe(1);

    $spy = new class implements SmsGateway
    {
        /** @var list<string> */
        public array $sent = [];

        public function send(string $to, string $message): SmsResult
        {
            $this->sent[] = $to;

            return SmsResult::sent('test', 'ref-'.count($this->sent));
        }
    };

    (new SendHouseholdContactsSms((int) $alert->id))->handle(app(HouseholdContactsSms::class), $spy);
    FacilitiesFixture::boot();

    expect($spy->sent)->toBe(['+18765550148', '+18765550161', '+18765550162', '+18765550163', '+18765550164'])
        ->and(array_unique($spy->sent))->toHaveCount(5);

    // Each text is recorded against the contact nominated FIRST for that number.
    $rows = AlertSmsDispatch::query()->where('duress_alert_id', $alert->id)->where('audience', AlertSmsDispatch::HOUSEHOLD)->orderBy('id')->get();
    expect($rows->pluck('household_emergency_contact_id')->all())->toBe([$ids[0], $ids[4], $ids[5], $ids[6], $ids[7]]);

    $alert->refresh();
    expect($alert->household_sms_delivered)->toBe(5)->and($alert->household_sms_over_cap)->toBe(1);
});

it('treats the same digits as the same number even when they cannot be dialled, and different digits as different people', function () {
    expect(EmergencyContactMerge::numberKey('876-555-0148'))->toBe(EmergencyContactMerge::numberKey('+1 (876) 555 0148'))
        ->and(EmergencyContactMerge::numberKey('555-0148'))->toBe(EmergencyContactMerge::numberKey('555 0148'))
        ->and(EmergencyContactMerge::numberKey('555-0148'))->not->toBe(EmergencyContactMerge::numberKey('876-555-0148'))
        ->and(EmergencyContactMerge::numberKey('+44 20 7946 0958'))->not->toBe(EmergencyContactMerge::numberKey('+1 876 555 0148'));
});
