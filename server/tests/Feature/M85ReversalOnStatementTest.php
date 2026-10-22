<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Document;
use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Documents\DocumentRenderer;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HouseholdHolder;

/*
|--------------------------------------------------------------------------
| A reversal is never a payment on a statement (EST-B-04, M85 A7)
|--------------------------------------------------------------------------
|
| M81 §3.8: a reversed charge read "Maintenance fee — October 2026" in the payment column, in green, with no reversal
| label, no reason and no receipt number. "A resident who reads a payment they never made will dispute it, and they
| will be right." The order's words: "Reversal — Maintenance fee October 2026 (reason)", as a negative charge, never as
| a payment; said here with a colon, since no em dash is on any page (Law 3).
|
| Lots of this file's own in the shared estate (the fixture is committed), one a case, each charge and reversal left as
| posted.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    tenancy()->initialize(FacilitiesFixture::platform());

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-10-11 15:00:00', 'UTC'));
    $this->treasurer = FacilitiesFixture::viewer(Role::TREASURER);
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
    tenancy()->end();
});

/** A lot billed October's fee, due on the 1st, then the charge reversed from the console with a reason. */
function m85Reversed(string $lot, User $by): Unit
{
    $unit = FacilitiesFixture::scratchUnit($lot, 'Fx Reversal Statement');
    FacilitiesFixture::boot();

    $dues = app(Dues::class);
    $charge = $dues->charge($unit, Money::of(6_200, 'JMD'), 'Maintenance fee October 2026', '2026-10-01');
    $dues->reverseCharge($charge, 'Billed at last year\'s rate', $by);

    FacilitiesFixture::boot();

    return $unit;
}

it('shows the reversal on the unit\'s statement as a negative charge with its reason, never in the payment column', function (): void {
    $unit = m85Reversed('Lot 98581', $this->treasurer);
    $rows = app(Dues::class)->statement($unit, 10);

    expect(collect($rows)->map(fn (array $row): array => [$row['description'], $row['charge_minor'], $row['payment_minor'], $row['balance_minor']])->all())->toBe([
        ['Reversal: Maintenance fee October 2026 (Billed at last year\'s rate)', -6_200_00, null, 0],
        ['Maintenance fee October 2026', 6_200_00, null, 6_200_00],
    ]);
});

it('prints the reversal on the statement PDF in the charge column, as a negative amount with its reason', function (): void {
    $unit = m85Reversed('Lot 98582', $this->treasurer);
    $facts = app(DocumentRenderer::class)->facts(Document::STATEMENT, (string) $unit->id, 'Statement of account');

    $html = view($facts['view'], [
        'estateName' => 'Phoenix Park', 'logo' => null, 'enquiries' => null, 'phone' => null, 'issuedAt' => 'October 11, 2026',
        'timeZone' => 'America/Jamaica', 'issuedBy' => 'Test', 'retainUntil' => 'October 11, 2033', 'title' => 'Statement of account',
        ...$facts['data'],
    ])->render();

    // Description, then the charge cell, then the payment cell (empty), then the balance.
    expect(preg_match('#Reversal: Maintenance fee October 2026 \(Billed at last year(&\#039;|\')s rate\)</td>\s*<td class="num">-\$6,200\.00</td>\s*<td class="num"></td>#', $html))->toBe(1);
});

it('labels the reversal on the Resident App\'s statement with what it reverses and why', function (): void {
    $unit = m85Reversed('Lot 98583', $this->treasurer);

    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Fx Reversal household', 'access_restricted' => false]);
    $resident = HouseholdHolder::of($household, 'Fx Reversal Resident');
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'fx.reversal.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Fx Reversal Resident', 'resident_id' => $resident->id, 'unit_id' => $unit->id,
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $token = $account->createToken('resident-app:reversal', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $entry = Catalogue::endpoints()['households.statement'];
    $response = $this->withToken($token)->getJson('/api/v1/'.str_replace('{household}', (string) $household->id, $entry['uri']));

    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, 'households.statement');

    expect(collect($response->json('items'))->pluck('description')->all())
        ->toBe(['Reversal: Maintenance fee October 2026 (Billed at last year\'s rate)', 'Maintenance fee October 2026']);
});
