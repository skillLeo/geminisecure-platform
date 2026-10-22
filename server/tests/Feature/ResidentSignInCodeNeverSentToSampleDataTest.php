<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Support\Seeded\Seeding;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A sign-in code the mail guard drops is never answered "sent" (M79 §2, EST-B-03)
|--------------------------------------------------------------------------
|
| The code request sends to whatever address is typed. When every person holding that address is one a seeder
| invented, the mail transport's guard drops the email, rightly (M35 §5: no message reaches an invented person, and
| no stranger signs in as one). The request answered 202 "sent" all the same, and the app told the resident to watch
| their inbox: on 9 and 10 October an address set on a sample household never received a code, while the platform's
| other mail arrived. Now it is refused in one line that says what to do, the code is withdrawn so the resend window
| cannot re-offer it as "sent", and the drop is in the console's Recent emails by row, as every drop is.
|
| An address a real person holds as well goes, as before: one owner may hold two units (EST-Q-01).
| Lot 9466 is this file's own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 9466');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Sign-in seedcheck household', 'access_restricted' => false]);
    DB::connection('tenant')->table('residents')->where('household_id', $this->household->id)->delete();

    signInSeedcheckTransport()->flush();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    if (tenancy()->initialized) {
        tenancy()->end();
    }
    FacilitiesFixture::boot();
    DB::connection('tenant')->table('residents')->where('household_id', $this->household->id)->delete();
});

function signInSeedcheckTransport(): ArrayTransport
{
    $transport = app('mailer')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    return $transport;
}

function signInSeedcheckAsk(string $destination): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/otp/request', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $destination]);
}

/**
 * As on production: Phoenix Park's seed recorded the ranges its people sit in, which is what makes the guard read the
 * estate at all when no estate is open (a code request opens none). Without it the guard reads nothing here and every
 * case below would pass for the wrong reason.
 */
function signInSeedcheckRange(int $id): void
{
    DB::connection('mysql')->table('sample_data_ranges')->insert([
        'tag' => 'phoenix-demo', 'tenant_id' => FacilitiesFixture::ESTATE, 'connection' => 'tenant', 'table_name' => 'residents',
        'from_id' => $id, 'to_id' => $id, 'append_only' => false, 'seeded_at' => now(),
    ]);
}

function signInSeedcheckLiveCodes(): int
{
    return DB::connection('mysql')->table('resident_otps')
        ->where('tenant_id', FacilitiesFixture::ESTATE)->where('channel', 'email')->whereNull('consumed_at')->count();
}

it('refuses in one line, withdraws the code and logs the drop when only invented people hold the address, never answering "sent"', function () {
    $sample = Seeding::run('phoenix:demo-seed', fn (): Resident => Resident::query()->create([
        'household_id' => $this->household->id, 'full_name' => 'Sample Signin', 'relationship' => 'owner', 'is_primary' => true,
        'status' => Resident::VERIFIED, 'email' => 'sample.signin@email.com',
    ]));
    signInSeedcheckRange($sample->id);

    signInSeedcheckAsk('sample.signin@email.com')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'email_not_sent')
        ->assertJsonPath('error.message', 'A sign-in code cannot be sent to this address. Ask your estate office to check the email on your household.')
        ->assertJsonMissingPath('sent');

    // Nothing reached the mail server, no code is left live, and Recent emails says it was not sent.
    expect(signInSeedcheckTransport()->messages())->toHaveCount(0)
        ->and(signInSeedcheckLiveCodes())->toBe(0)
        ->and(DB::connection('mysql')->table('mail_log')->where('outcome', 'not_sent')->pluck('type')->all())->toBe(['Resident sign in code']);

    // Asked again inside the minute: refused again, never "sent" from the resend window.
    signInSeedcheckAsk('sample.signin@email.com')->assertStatus(422)->assertJsonPath('error.code', 'email_not_sent');
});

it('sends the code as before when a real person holds the address too: one owner may hold two units', function () {
    $sample = Seeding::run('phoenix:demo-seed', fn (): Resident => Resident::query()->create([
        'household_id' => $this->household->id, 'full_name' => 'Sample Sharer', 'relationship' => 'owner', 'is_primary' => true,
        'status' => Resident::VERIFIED, 'email' => 'shared.signin@example.org',
    ]));
    signInSeedcheckRange($sample->id);
    Resident::query()->create([
        'household_id' => $this->household->id, 'full_name' => 'Real Sharer', 'relationship' => 'spouse', 'is_primary' => false,
        'status' => Resident::VERIFIED, 'email' => 'shared.signin@example.org',
    ]);

    signInSeedcheckAsk('shared.signin@example.org')->assertStatus(202)->assertJsonPath('sent', true);

    expect(signInSeedcheckTransport()->messages())->toHaveCount(1)
        ->and(signInSeedcheckLiveCodes())->toBe(1);
});
