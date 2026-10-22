<?php

declare(strict_types=1);

use App\Live\ChannelName;
use App\Live\Channels;
use App\Live\Events\RecordLive;
use App\Live\LiveTypes;
use App\Models\Estate\Ballot;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Notice;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\SecurityIncident;
use App\Models\Shift;
use App\Services\Dispatch\ShiftClock;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Every other record moves every screen that shows it (M11 §2; D-175)
|--------------------------------------------------------------------------
|
| One observer tells `{entity}.changed` / `{entity}.removed` — a reference, ids only — to the channels of the screens
| that show the record: the Gemini console's feeds, the estate's pages, the guard's and the household's phones. This
| proves the channels each record reaches, the stream it is on, that nothing about the record rides the event, and
| that a vote is told to nobody.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->estate = FacilitiesFixture::ESTATE;
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    DB::connection('tenant')->table('live_events')->whereIn('entity', ['ticket', 'notice', 'election', 'resident', 'structure'])->where('created_at', '>=', now()->subMinutes(10))->delete();
});

/** The events one record caused, on its own stream. */
function recordEvents(string $connection, string $entity, int|string $id): array
{
    FacilitiesFixture::boot();

    return DB::connection($connection)->table('live_events')->where('entity', $entity)->where('entity_id', (string) $id)->orderBy('id')
        ->get(['type', 'channels', 'summary', 'mode'])
        ->map(static fn (object $r): array => ['type' => $r->type, 'channels' => json_decode((string) $r->channels, true), 'summary' => $r->summary, 'mode' => $r->mode])->all();
}

function recordLiveInEstate(Closure $fn): mixed
{
    // An estate an earlier test left open is ended first: run() would otherwise hand back to it after.
    tenancy()->end();
    FacilitiesFixture::boot();

    return tenancy()->find(FacilitiesFixture::ESTATE)->run(function () use ($fn): mixed {
        // In the estate's own database, or every channel below would name another estate's household (M12 §5.4).
        expect(DB::connection('tenant')->getDatabaseName())->toBe(FacilitiesFixture::database());

        return $fn();
    });
}

it('names every type it can send, for the phones', function () {
    expect(LiveTypes::all())->toContain('incident.changed', 'ticket.changed', 'dues.changed', 'notice.removed', 'shift.changed', 'orders.changed', 'payroll.changed', 'resident.changed', 'account.changed', 'booking.changed', 'structure.changed', 'accounting.changed', 'staff.changed', 'client.changed', 'invoice.changed', 'officer.changed', 'setting.changed', 'chat.changed');
    expect(RecordLive::types())->toHaveCount(44);
});

it('a lot added reaches the Structure page and the Residents pages; a guard\'s record reaches Gemini\'s workforce feed (M18H §7.2)', function () {
    // A lot of its own, new every run, made with the estate open as every request that adds one runs.
    $reference = 'Lot RL-S'.bin2hex(random_bytes(3));
    $unit = recordLiveInEstate(fn () => Unit::query()->create(['reference' => $reference, 'block' => 'Phase 9', 'street' => 'Phase 9 Drive', 'type' => 'residential', 'status' => 'occupied']));
    $events = recordEvents('tenant', 'structure', $unit->id);
    expect($events)->not->toBeEmpty()
        ->and(collect($events)->last()['channels'])->toEqualCanonicalizing([Channels::estateFeed($this->estate, 'structure'), Channels::estateFeed($this->estate, 'residents')]);
    // The estate's database is shared and committed: the lot goes again (a bare lot, nothing points at it).
    recordLiveInEstate(fn () => Unit::query()->whereKey($unit->id)->delete());

    $post = Post::create(['tenant_id' => $this->estate, 'name' => 'RL Officer Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create(['full_name' => 'RL Officer', 'employee_number' => 'GS-RL-9', 'psra_number' => 'PSRA-RL-9', 'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $this->estate, 'post_id' => $post->id]);
    $officer = recordEvents('mysql', 'officer', $guard->id);
    expect($officer)->not->toBeEmpty()
        ->and($officer[0]['type'])->toBe('officer.changed')
        ->and($officer[0]['channels'])->toContain(Channels::geminiFeed($this->estate, 'workforce'), 'gemini.workforce')
        // A reference: nothing about the officer rides the event.
        ->and(json_encode($officer))->not->toContain('RL Officer');
});

it('the register moves by itself: a person or a household changed reaches the Residents pages and the unit\'s own (M18H §6)', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot RL-3');
    $household = recordLiveInEstate(fn () => Household::query()->create(['unit_id' => $unit->id, 'name' => 'RL register household', 'access_restricted' => false]));

    try {
        $person = recordLiveInEstate(fn () => Resident::query()->create(['household_id' => $household->id, 'full_name' => 'RL Register Person', 'email' => 'rl.register@example.com', 'phone' => '+1 876 555 0300', 'relationship' => 'owner', 'is_primary' => true, 'status' => 'verified']));

        $events = recordEvents('tenant', 'resident', $unit->id);
        expect($events)->not->toBeEmpty()
            ->and(collect($events)->pluck('type')->unique()->all())->toBe(['resident.changed'])
            ->and(collect($events)->last()['channels'])->toEqualCanonicalizing([
                Channels::estateFeed($this->estate, 'residents'),
                Channels::estateRecord($this->estate, 'residents', 'unit', $unit->id),
            ])
            // A reference: nobody's name or number rides the event.
            ->and(json_encode($events))->not->toContain('RL Register Person')->not->toContain('0300');

        recordLiveInEstate(fn () => $person->delete());
    } finally {
        recordLiveInEstate(fn () => [Resident::withRemoved()->where('household_id', $household->id)->delete(), Household::query()->whereKey($household->id)->delete()]);
    }
});

it('an account\'s standing reaches the Residents pages, and its every other save does not', function () {
    $account = ResidentAccount::query()->create(['tenant_id' => $this->estate, 'channel' => 'email', 'destination' => 'rl.account.'.bin2hex(random_bytes(3)).'@example.com', 'status' => ResidentAccount::PENDING]);
    expect(recordEvents('mysql', 'account', $account->id))->toHaveCount(1);

    $account->forceFill(['verified_at' => now(), 'device_uid' => 'rl-phone'])->save();
    expect(recordEvents('mysql', 'account', $account->id))->toHaveCount(1);

    $account->forceFill(['status' => ResidentAccount::DELETED, 'deleted_at' => now()])->save();
    $events = recordEvents('mysql', 'account', $account->id);
    expect($events)->toHaveCount(2)
        ->and($events[1]['type'])->toBe('account.changed')
        ->and($events[1]['channels'])->toBe([Channels::estateFeed($this->estate, 'residents')]);
});

it('an incident filed reaches the Gemini workforce feed and its page, the estate\'s Security page and the filing guard', function () {
    $post = Post::create(['tenant_id' => $this->estate, 'name' => 'RL Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create(['full_name' => 'RL Guard', 'employee_number' => 'GS-RL-1', 'psra_number' => 'PSRA-RL-1', 'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $this->estate, 'post_id' => $post->id]);

    $incident = SecurityIncident::create(['tenant_id' => $this->estate, 'guard_id' => $guard->id, 'guard_name' => 'RL Guard', 'kind' => 'Suspicious person', 'detail' => 'A person was seen testing the gate latch at the north fence.', 'severity' => 'medium', 'status' => SecurityIncident::OPEN, 'occurred_at' => now()]);

    $events = recordEvents('mysql', 'incident', $incident->id);
    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('incident.changed')
        ->and($events[0]['mode'])->toBe('ref')
        ->and($events[0]['channels'])->toContain(Channels::geminiFeed($this->estate, 'workforce'), 'gemini.workforce', Channels::estateFeed($this->estate, 'security'), Channels::guard($this->estate, $guard->id))
        // A reference: nothing the report says rides the event.
        ->and(json_encode($events[0]))->not->toContain('latch');
});

it('a clock-in reaches the roster, the live map, the estate\'s "on site now", the guard and the estate\'s guards', function () {
    $post = Post::create(['tenant_id' => $this->estate, 'name' => 'RL Post', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create(['full_name' => 'RL Shift Guard', 'employee_number' => 'GS-RL-2', 'psra_number' => 'PSRA-RL-2', 'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $this->estate, 'post_id' => $post->id]);
    $shift = Shift::create(['tenant_id' => $this->estate, 'guard_id' => $guard->id, 'post_id' => $post->id, 'rostered_start' => now()->subMinutes(5), 'rostered_end' => now()->addHours(8), 'status' => 'rostered']);

    app(ShiftClock::class)->clockIn(shift: $shift, method: ShiftClock::METHOD_APP);

    $last = collect(recordEvents('mysql', 'shift', $shift->id))->last();
    expect($last['type'])->toBe('shift.changed')
        ->and($last['channels'])->toContain(Channels::geminiFeed($this->estate, 'workforce'), Channels::geminiFeed($this->estate, 'dispatch'), Channels::estateFeed($this->estate, 'security'), Channels::estateGuards($this->estate), Channels::guard($this->estate, $guard->id));
});

it('a maintenance ticket reaches the Facilities page, its own page and the household — and the guards when it is happening now', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot RL-1');
    $household = recordLiveInEstate(fn () => Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'RL household', 'access_restricted' => false]));

    // A priority the estate's pages know: a ticket left behind by a failure here broke every dashboard after it.
    $ticket = recordLiveInEstate(fn () => MaintenanceTicket::create(['number' => random_int(700000, 799999), 'title' => 'Light out on the path', 'location_label' => 'Lot RL-1', 'unit_id' => $unit->id, 'category' => 'lighting', 'description' => 'Out since Tuesday.', 'priority' => 'medium', 'status' => 'open', 'reported_by_name' => 'RL Resident', 'reported_at' => now(), 'sla_hours' => 48, 'happening_now' => false]));
    $removed = false;

    try {
        // Every household on the lot — the shared estate may hold another on it — and so this one.
        $households = recordLiveInEstate(fn () => Household::query()->where('unit_id', $unit->id)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        expect($households)->toContain((int) $household->id);

        $events = recordEvents('tenant', 'ticket', $ticket->id);
        expect($events[0]['type'])->toBe('ticket.changed')
            ->and($events[0]['channels'])->toEqualCanonicalizing([
                Channels::estateFeed($this->estate, 'facilities'),
                Channels::estateRecord($this->estate, 'facilities', 'ticket', $ticket->id),
                ...array_map(fn (int $id): string => Channels::household($this->estate, $id), $households),
            ]);

        recordLiveInEstate(fn () => $ticket->forceFill(['happening_now' => true])->save());
        expect(collect(recordEvents('tenant', 'ticket', $ticket->id))->last()['channels'])->toContain(Channels::estateGuards($this->estate));

        recordLiveInEstate(fn () => $ticket->delete());
        $removed = true;
        expect(collect(recordEvents('tenant', 'ticket', $ticket->id))->last()['type'])->toBe('ticket.removed');
    } finally {
        // The estate's database is shared by every file that uses it, and nothing here is in a transaction.
        if (! $removed) {
            recordLiveInEstate(fn () => MaintenanceTicket::query()->whereKey($ticket->id)->delete());
        }
    }
});

it('a ticket on a lot two households share reaches both of them, because both see the lot\'s tickets', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot RL-2');
    [$first, $second] = recordLiveInEstate(fn () => [
        Household::query()->create(['unit_id' => $unit->id, 'name' => 'RL first household', 'access_restricted' => false]),
        Household::query()->create(['unit_id' => $unit->id, 'name' => 'RL second household', 'access_restricted' => false]),
    ]);

    $ticket = recordLiveInEstate(fn () => MaintenanceTicket::create(['number' => random_int(800000, 899999), 'title' => 'Gate latch sticks', 'location_label' => 'Lot RL-2', 'unit_id' => $unit->id, 'category' => 'other', 'description' => 'Both households use it.', 'priority' => 'low', 'status' => 'open', 'reported_by_name' => 'RL Resident', 'reported_at' => now(), 'sla_hours' => 48, 'happening_now' => false]));

    try {
        expect(recordEvents('tenant', 'ticket', $ticket->id)[0]['channels'])
            ->toContain(Channels::household($this->estate, (int) $first->id), Channels::household($this->estate, (int) $second->id));
    } finally {
        recordLiveInEstate(fn () => [MaintenanceTicket::query()->whereKey($ticket->id)->delete(), Household::query()->whereKey([$first->id, $second->id])->delete()]);
    }
});

it('a notice reaches the Governance page and its audience: the community, or the phase it was posted to', function () {
    $whole = recordLiveInEstate(fn () => Notice::create(['kind' => 'general', 'title' => 'Pool closed Saturday', 'body' => 'For cleaning.', 'audience_scope' => 'estate', 'author_name' => 'RL Secretary', 'published_at' => now()]));
    expect(recordEvents('tenant', 'notice', $whole->id)[0]['channels'])->toEqualCanonicalizing([Channels::estateFeed($this->estate, 'governance'), Channels::community($this->estate)]);

    $phase = recordLiveInEstate(fn () => Notice::create(['kind' => 'general', 'title' => 'Water off in Phase 2', 'body' => 'Tuesday morning.', 'audience_scope' => 'phase', 'audience_phase' => 'Phase 2', 'author_name' => 'RL Secretary', 'published_at' => now()]));
    expect(recordEvents('tenant', 'notice', $phase->id)[0]['channels'])->toContain(Channels::phase($this->estate, ChannelName::phaseKey('Phase 2')))
        ->not->toContain(Channels::community($this->estate));

    // The fixture estate is shared: what this wrote goes (and a deletion is told as one).
    recordLiveInEstate(fn () => [$whole->delete(), $phase->delete()]);
    expect(collect(recordEvents('tenant', 'notice', $whole->id))->last()['type'])->toBe('notice.removed');
});

it('an election\'s stage reaches the Governance page, the ballot\'s own page and the community', function () {
    $ballot = recordLiveInEstate(fn () => Ballot::create(['year' => random_int(2400, 2900), 'code' => 'Z', 'title' => 'Committee 2026', 'kind' => 'election', 'stage' => 'draft']));

    expect(recordEvents('tenant', 'election', $ballot->id)[0]['channels'])->toEqualCanonicalizing([
        Channels::estateFeed($this->estate, 'governance'),
        Channels::estateRecord($this->estate, 'governance', 'ballot', $ballot->id),
        Channels::community($this->estate),
    ]);

    recordLiveInEstate(fn () => $ballot->delete());
});

it('an estate record written with no estate open is told to nobody, and the write still happens', function () {
    FacilitiesFixture::boot();
    tenancy()->end();

    expect(fn () => RecordLive::tell('ticket', 1, FacilitiesFixture::ESTATE, [Channels::estateFeed(FacilitiesFixture::ESTATE, 'facilities')]))->not->toThrow(Throwable::class);
});
