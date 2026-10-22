<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Push\PanicFanOut;
use App\Services\Push\PushAnnouncer;
use App\Services\Push\PushNotifier;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A committee's own meeting reaches the committee members' households, and nobody else's (M73 §1, step 6)
|--------------------------------------------------------------------------
|
| Publishing a committee meeting reached no phone. Hassam's rule: it reaches the committee members' households. A
| member's household is the unit of an active Resident App account in the estate whose sign-in email is exactly the
| member's console email; nobody is matched by a name, and a member with no such account is told nothing by phone.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->president = FacilitiesFixture::viewer(Role::PRESIDENT);
    FacilitiesFixture::boot();

    $this->own = FacilitiesFixture::scratchUnit('Lot 1907');
    $this->stranger = FacilitiesFixture::scratchUnit('Lot 1908');

    $this->account = static fn (string $email, int $unitId): ResidentAccount => ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email,
        'status' => ResidentAccount::ACTIVE, 'full_name' => 'Committee test '.$unitId, 'unit_id' => $unitId,
    ]);

    $this->meeting = Meeting::query()->forceCreate([
        'type' => Meeting::COMMITTEE, 'title' => 'Committee meeting · reach test', 'starts_at' => now()->addDays(10)->utc(),
        'status' => Meeting::SCHEDULED, 'audience_scope' => Meeting::COMMITTEE_ONLY, 'published_at' => now()->utc(),
    ]);

    $this->sent = [];
    $notifier = Mockery::mock(PushNotifier::class);
    $notifier->shouldReceive('toEstateResidents')->andReturnUsing(function (string $tenant, $message, ?array $units = null): int {
        $this->sent[] = $units;

        return 0;
    });
    $this->announcer = new PushAnnouncer($notifier, app(PanicFanOut::class));
});

afterEach(function (): void {
    FacilitiesFixture::boot();
    Meeting::query()->whereKey($this->meeting->id)->delete();
    DB::connection('mysql')->rollBack();
});

it('tells the household whose app account is a committee member\'s own, and no other', function (): void {
    ($this->account)(strtoupper((string) $this->president->email), (int) $this->own->id);
    ($this->account)('somebody.else.'.bin2hex(random_bytes(3)).'@example.com', (int) $this->stranger->id);

    $this->announcer->meeting($this->meeting, 'scheduled', FacilitiesFixture::ESTATE);
    $this->announcer->meetingChanged($this->meeting, 'moved', FacilitiesFixture::ESTATE);

    expect($this->sent)->toHaveCount(2)
        ->and($this->sent[0])->toBe([(int) $this->own->id])
        ->and($this->sent[1])->toBe([(int) $this->own->id]);
});

it('tells no phone when no committee member has an app account of their own', function (): void {
    ($this->account)('somebody.else.'.bin2hex(random_bytes(3)).'@example.com', (int) $this->stranger->id);

    $this->announcer->meeting($this->meeting, 'scheduled', FacilitiesFixture::ESTATE);

    expect($this->sent)->toBe([]);
});
