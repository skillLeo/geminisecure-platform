<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\AuditEntry;
use App\Models\DuressAlert;
use App\Models\DuressAlertMessage;
use App\Models\Estate\Household;
use App\Models\Estate\HouseholdCard;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Resident;
use App\Models\Estate\TicketActivity;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Services\ResidentApp\ResidentDataExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M50 §4.3 — "Download my data" gives the person a copy of everything held about them
|--------------------------------------------------------------------------
|
| Board 33's row promises "A copy of everything GeminiSecure holds about you", and a tap said it was not available. Now
| it is a PDF of the person's own records: their account and sign-ins, their place on the register, their household as
| their access reaches it, the passes they sent, their requests and what they wrote on them, their cards, their panics
| and what was said on them — and never another person's details, never a secret, never how the household voted.
|
| The estate database is shared by the run and not rolled back: this file stands on Lots 9511 and 9512 of its own, and
| every name it writes carries this run's own mark.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->mark = strtoupper(bin2hex(random_bytes(2)));
    $unit = FacilitiesFixture::scratchUnit('Lot 9511');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Export household', 'access_restricted' => false]);
    $this->resident = Tests\Support\HouseholdHolder::of($this->household, 'Dana Export', ['email' => 'dana.register@example.com', 'phone' => '876-555-0141']);
    $this->unit = $unit;

    $this->account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'dana.'.strtolower($this->mark).'@example.com',
        'full_name' => 'Dana Export', 'resident_id' => $this->resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->token = exportToken($this->account);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function exportToken(ResidentAccount $account): string
{
    return $account->createToken('resident-app:export-'.bin2hex(random_bytes(3)), AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
}

function exportCall(TestCase $test, string $token, int $status = 200): TestResponse
{
    app('auth')->forgetGuards();
    $response = $test->withToken($token)->get('/api/v1/me/data-export', ['Accept' => 'application/json']);
    $response->assertStatus($status);
    FacilitiesFixture::boot();

    return $response;
}

/** The file's sections as plain text, keyed by section: what a reader of the PDF would find in each. */
function exportText(ResidentAccount $account): array
{
    $data = app(ResidentDataExport::class)->collect($account);

    return collect($data['sections'])->mapWithKeys(static fn (array $s): array => [$s['key'] => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])->all();
}

it('makes a PDF of the person\'s own records, and audits how much went in — never what', function () {
    $mark = $this->mark;
    DB::connection('tenant')->table('household_vehicles')->insert(['household_id' => $this->household->id, 'plate' => 'PE '.$mark, 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Silver', 'added_by_account_id' => $this->account->id, 'created_at' => now(), 'updated_at' => now()]);
    VisitorPass::query()->create([
        'pass_id' => (string) Str::uuid(), 'unit_id' => $this->unit->id, 'household_id' => $this->household->id,
        'issued_by_resident_id' => $this->resident->id, 'issued_by_name' => 'Dana Export', 'category' => 'single',
        'visitor_name' => 'Visitor '.$mark, 'visitor_phone' => '876-555-0190', 'purpose' => 'Family visit',
        'valid_from' => now()->subHour(), 'valid_to' => now()->addHours(3), 'single_use' => true,
        'nonce' => bin2hex(random_bytes(16)), 'key_version' => 1, 'token' => 'export-'.bin2hex(random_bytes(6)),
        'code' => 'EXP-'.random_int(1000, 9999), 'status' => VisitorPass::ACTIVE,
    ]);
    $ticket = MaintenanceTicket::query()->create([
        'number' => (int) MaintenanceTicket::query()->max('number') + 1, 'title' => 'Leaking tap '.$mark, 'location_label' => 'Lot 9511',
        'unit_id' => $this->unit->id, 'category' => 'plumbing', 'priority' => 'medium', 'status' => 'submitted',
        'reported_by_name' => 'Dana Export', 'reported_at' => now()->subDay(), 'sla_hours' => 72,
    ]);
    TicketActivity::query()->create([
        'maintenance_ticket_id' => $ticket->id, 'event' => TicketActivity::NOTE, 'note' => 'Still dripping '.$mark,
        'actor_kind' => TicketActivity::RESIDENT, 'actor_name' => 'Dana Export', 'occurred_at' => now(),
    ]);
    HouseholdCard::query()->where('resident_account_id', $this->account->id)->delete();
    HouseholdCard::query()->create(['unit_id' => $this->unit->id, 'resident_account_id' => $this->account->id, 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030, 'holder' => 'Dana Export', 'is_default' => true, 'is_demonstration' => true]);
    $alert = DuressAlert::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'kind' => 'duress', 'status' => 'resolved', 'server_time' => now()->subHours(2), 'device_time' => now()->subHours(2),
        'idempotency_key' => 'export-'.bin2hex(random_bytes(4)), 'raised_by_account_id' => $this->account->id, 'raised_by_name' => 'Dana Export', 'latitude' => 18.0179, 'longitude' => -76.8099,
    ]);
    DuressAlertMessage::query()->create(['duress_alert_id' => $alert->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'author' => 'resident', 'resident_account_id' => $this->account->id, 'body' => 'Someone at the back door '.$mark, 'client_key' => (string) Str::uuid()]);

    $file = exportCall($this, $this->token);
    expect((string) $file->headers->get('Content-Type'))->toBe('application/pdf')
        ->and((string) $file->headers->get('Content-Disposition'))->toMatch('/attachment; filename="GeminiSecure-my-data-\d{4}-\d{2}-\d{2}\.pdf"/')
        ->and((string) $file->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr((string) $file->getContent(), 0, 5))->toBe('%PDF-');

    $text = exportText($this->account);
    expect(array_keys($text))->toBe(['account', 'register', 'household', 'sign_ins', 'notifications', 'e_pass', 'visitor_passes', 'gate_answers', 'requests', 'bookings', 'money', 'panics', 'claim', 'invites', 'notices', 'meetings', 'elections'])
        ->and($text['account'])->toContain($this->account->destination)
        ->and($text['register'])->toContain('dana.register@example.com')->toContain('876-555-0141')->toContain('Primary resident')
        ->and($text['household'])->toContain('PE '.$mark)
        ->and($text['sign_ins'])->toContain('Signed in')
        ->and($text['visitor_passes'])->toContain('Visitor '.$mark)
        ->and($text['requests'])->toContain('Leaking tap '.$mark)->toContain('Still dripping '.$mark)
        ->and($text['money'])->toContain('Visa •••• 4242')
        ->and($text['panics'])->toContain('Someone at the back door '.$mark)->toContain('18.0179');

    // Audited: the counts of each section, and nothing of what was in them.
    $entry = AuditEntry::query()->where('action', 'resident_account.data_exported')->where('entity_id', (string) $this->account->id)->latest('id')->first();
    expect($entry)->not->toBeNull();
    $logged = json_encode($entry->after, JSON_UNESCAPED_UNICODE);
    expect($entry->after['sections']['visitor_passes'])->toBeGreaterThanOrEqual(1)
        ->and($logged)->not->toContain($this->account->destination)->not->toContain('Dana')->not->toContain($mark);
});

it('never holds another person\'s details: the other members by name alone, another household not at all', function () {
    $mark = $this->mark;
    Resident::query()->create(['household_id' => $this->household->id, 'full_name' => 'Kim Other '.$mark, 'email' => 'kim.'.strtolower($mark).'@example.com', 'phone' => '876-555-0177', 'relationship' => 'spouse', 'is_primary' => false]);

    $elsewhere = FacilitiesFixture::scratchUnit('Lot 9512');
    $neighbours = Household::query()->firstOrCreate(['unit_id' => $elsewhere->id], ['name' => 'Neighbour household', 'access_restricted' => false]);
    $neighbour = Tests\Support\HouseholdHolder::of($neighbours, 'Lee Neighbour');
    VisitorPass::query()->create([
        'pass_id' => (string) Str::uuid(), 'unit_id' => $elsewhere->id, 'household_id' => $neighbours->id,
        'issued_by_resident_id' => $neighbour->id, 'issued_by_name' => 'Lee Neighbour', 'category' => 'single',
        'visitor_name' => 'Not yours '.$mark, 'valid_from' => now(), 'valid_to' => now()->addHour(), 'single_use' => true,
        'nonce' => bin2hex(random_bytes(16)), 'key_version' => 1, 'token' => 'export-'.bin2hex(random_bytes(6)),
        'code' => 'EXP-'.random_int(1000, 9999), 'status' => VisitorPass::ACTIVE,
    ]);

    $all = implode(' ', exportText($this->account));
    expect($all)->toContain('Kim Other '.$mark)
        ->not->toContain('kim.'.strtolower($mark).'@example.com')
        ->not->toContain('876-555-0177')
        ->not->toContain('Not yours '.$mark)
        // Never a secret: no token, no pass code.
        ->not->toContain($this->token);
});

it('narrows the household\'s records by the person\'s access, as the app does — and a Helper still gets their own', function () {
    $helper = Resident::query()->create(['household_id' => $this->household->id, 'full_name' => 'Helen Helper '.$this->mark, 'relationship' => 'helper', 'is_primary' => false]);
    $helper->forceFill(['access_level' => AppMatrix::HELPER])->save();
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'helen.'.strtolower($this->mark).'@example.com',
        'full_name' => 'Helen Helper '.$this->mark, 'resident_id' => $helper->id, 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    exportCall($this, exportToken($account));

    $text = exportText($account);
    expect($text['register'])->toContain('Helper')
        ->and($text['household'])->toContain('does not include the household')->not->toContain('Dana Export')
        ->and($text['money'])->toContain('does not include the household\'s dues')
        ->and($text['elections'])->toContain('does not include voting');
});

it('gives an account still waiting on its claim its own account and sign-ins, and says what it is not yet linked to', function () {
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'waiting.'.strtolower($this->mark).'@example.com',
        'full_name' => 'Wes Waiting', 'status' => ResidentAccount::PENDING, 'verified_at' => now(),
    ]);

    exportCall($this, exportToken($account));

    $text = exportText($account);
    expect($text['account'])->toContain('waiting.'.strtolower($this->mark).'@example.com')
        ->and($text['register'])->toContain('not on the estate\'s register yet')
        ->and($text['household'])->toContain('not linked to a home')
        ->and($text['sign_ins'])->toContain('A phone');
});

it('is the heaviest read a phone can ask for, so three a minute from one phone', function () {
    foreach (range(1, 3) as $ignored) {
        exportCall($this, $this->token);
    }

    expect(exportCall($this, $this->token, 429)->json('error.code'))->toBeString();
});
