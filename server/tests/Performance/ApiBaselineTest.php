<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M11 A1 — every /api/v1 endpoint the two apps call, at Phoenix Park's production size
|--------------------------------------------------------------------------
|
| NOT PART OF THE SUITE (tests/Performance is outside phpunit.xml's testsuites). Run by path:
|
|     tools/perf/serve.ps1 running (the production-shaped server), tools/perf/smtp-sink.cjs running
|     $env:PERF_OUT  = '<a directory outside the repository>'
|     $env:PERF_LOG  = '<the server's requests.jsonl>'
|     $env:PERF_BASE = 'http://127.0.0.1:8123'
|     .\t.ps1 tests/Performance/ApiBaselineTest.php
|
| THE WALK IS THE CONTRACT TESTS' WALK (ResidentApiContractTest, GuardApiContractTest), on the
| 2,000-lot estate instead of the five-lot fixture: the same payloads, the same order, the same
| expected status for every call — so a figure here is for the request the app really makes.
|
| READS are called once to warm the server's caches and then PERF_READ_RUNS times (default 20),
| so the 95th percentile the target names is a real order statistic. WRITES change the estate, so
| each is made as often as its effect allows (usually three) and never warmed.
|
| The figures are read back from the server's own log, per request, by tag (see ApiProbe). This
| file only makes the calls and arranges the state each one needs.
|
*/

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Enums\Console;
use App\Models\DevicePushToken;
use App\Models\DuressAlertRingEvent;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\Notice;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\Unit;
use App\Models\Guard;
use App\Models\Payslip;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResidentSignInCode;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\Estate\Governance;
use App\Services\Estate\Notices;
use App\Services\Passes\VisitorPasses;
use App\Services\ResidentApp\ResidentSignIn;
use App\Services\Ring\RingTargets;
use App\Services\Ring\RingTicket;
use App\Support\DemonstrationAccounts;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Performance\Support\ApiProbe;
use Tests\Performance\Support\ScaleEstate;
use Tests\TestCase;

pest()->extend(TestCase::class);

/** A handset's Ed25519 public key: 32 bytes, base64url. The walk only needs its shape. */
function perfPublicKey(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** A phone-camera-sized photo (1600 x 1200 JPEG), made here so the upload is a realistic size. */
function perfPhoto(): string
{
    $image = imagecreatetruecolor(1600, 1200);

    for ($y = 0; $y < 1200; $y += 8) {
        for ($x = 0; $x < 1600; $x += 8) {
            imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, imagecolorallocate($image, random_int(0, 255), ($x + $y) % 256, random_int(0, 255)));
        }
    }

    ob_start();
    imagejpeg($image, null, 85);

    return (string) ob_get_clean();
}

it('measures every Resident App and Guard App endpoint at Phoenix Park\'s production size', function (): void {
    ScaleEstate::assertSlot();

    $base = rtrim((string) (getenv('PERF_BASE') ?: 'http://127.0.0.1:8123'), '/');
    $log = (string) getenv('PERF_LOG');
    $reads = (int) (getenv('PERF_READ_RUNS') ?: 20);

    expect($log)->not->toBe('', 'Set PERF_LOG to the server\'s requests.jsonl.');

    $size = ScaleEstate::size();
    expect($size['units'])->toBe(2000)->and($size['households'])->toBe(441);

    $estate = Tenant::query()->findOrFail(ScaleEstate::ESTATE);
    $site = ScaleEstate::ESTATE;
    $probe = new ApiProbe($base, $log);
    $started = microtime(true);

    /* ── The household the Resident App is signed in as: one in good standing, as the contract walk arranges ── */

    [$unitId, $householdId, $residentId, $residentName, $unitRef, $unitBlock] = $estate->run(static function (): array {
        foreach (Household::query()->orderBy('id')->cursor() as $household) {
            $unit = Unit::query()->find($household->unit_id);

            if ($unit === null || $household->access_restricted || app(Collections::class)->isProtected($unit) || ! app(Governance::class)->eligibility($unit)['eligible']) {
                continue;
            }

            $primary = Resident::query()->where('household_id', $household->id)->where('is_primary', true)->orderBy('id')->first();

            if ($primary !== null) {
                return [$unit->id, $household->id, $primary->id, (string) $primary->full_name, (string) $unit->reference, (string) $unit->block];
            }
        }

        throw new RuntimeException('No household in good standing with a primary resident at '.ScaleEstate::ESTATE.'.');
    });

    $account = ResidentAccount::query()->create([
        'tenant_id' => $site, 'channel' => 'email', 'destination' => 'perf.'.bin2hex(random_bytes(4)).'@example.com', 'full_name' => $residentName,
        'resident_id' => $residentId, 'unit_id' => $unitId, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $t = $account->createToken('resident-app:perf', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    /* ── Before any token: the estate pick-list, and signing in by code ── */

    $probe->call('estates.index', null, runs: 10, warm: true);

    foreach (range(1, 3) as $i) {
        $probe->call('auth.otp.request', null, body: ['estate' => $site, 'channel' => 'email', 'destination' => 'perf.otp.'.bin2hex(random_bytes(4)).'@example.com']);
    }

    // The code exists in plain text only in the notification; it is caught here, where it is issued,
    // exactly as mobile:signin-code and the contract test read it — then proved over HTTP.
    $pending = [];
    $pendingEmails = [];
    $codeFor = static function (string $email) use ($estate): string {
        Notification::fake();
        app(ResidentSignIn::class)->requestCode($estate, 'email', $email);
        $code = null;
        Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n) use (&$code): bool {
            $code = $n->code;

            return true;
        });

        return (string) $code;
    };

    foreach (range(1, 3) as $i) {
        $email = 'perf.verify.'.bin2hex(random_bytes(4)).'@example.com';
        $pendingEmails[] = $email;

        $pending[] = $probe->call('auth.otp.verify', null, body: ['estate' => $site, 'channel' => 'email', 'destination' => $email, 'code' => $codeFor($email), 'device_uid' => 'perf-install-'.$i, 'platform' => 'android'])->json('token');
    }

    $probe->call('auth.claim_unit', $pending[0], body: ['full_name' => 'Perf Claimant', 'lot' => $unitRef, 'phase' => $unitBlock, 'relationship' => 'owner']);

    /*
     * ── The resident's writes FIRST, then the reads ──
     *
     * So that a list is read with something in it: the household's vehicles, contacts, passes,
     * tickets and bookings are read after the walk has added its own, rather than measured as an
     * empty `{"items":[]}` that no household with a history would ever be sent.
     */

    foreach (range(1, 3) as $i) {
        $probe->call('me.update', $t, body: ['full_name' => $residentName, 'phone' => '+1876555'.random_int(1000, 9999)]);
    }

    $members = [];
    foreach (range(1, 3) as $i) {
        $members[] = $probe->call('members.store', $t, body: ['full_name' => 'Perf Member '.$i.' '.bin2hex(random_bytes(2)), 'relationship' => 'child', 'phone' => '876 555 0120', 'no_email' => true])->json('id');
    }
    foreach ($members as $member) {
        $probe->call('members.destroy', $t, ['member' => $member]);
    }

    /*
     * An invitation to join the household, taken back, its level set, and the invitee removed (M12). Member level,
     * which is always open; Dependent and Helper wait on `access.levels_open`.
     *
     * FROM ANOTHER HOUSEHOLD, CHOSEN AT RANDOM, ONCE A RUN, so the walk's own household never meets the daily caps
     * however often the walk is run in a day.
     *
     * `members.store` with an address SENDS the invite, so `members.invite` straight after it is a resend inside the
     * minute's wait — `409 invite_wait`, the product working (`ResidentInvites::cooldownAfter`). A primary resident
     * who resends does it minutes later, not in the same second, so the walk puts the first send ten minutes back
     * before it measures the resend.
     */
    [$inviterUnit, $inviterResident, $inviterName] = $estate->run(static function () use ($householdId): array {
        $household = Household::query()->where('id', '!=', $householdId)->where('access_restricted', false)
            ->whereHas('residents', static fn ($q) => $q->where('is_primary', true))->inRandomOrder()->firstOrFail();
        $primary = Resident::query()->where('household_id', $household->id)->where('is_primary', true)->orderBy('id')->firstOrFail();

        return [(int) $household->unit_id, (int) $primary->id, (string) $primary->full_name];
    });
    $inviter = ResidentAccount::query()->create([
        'tenant_id' => $site, 'channel' => 'email', 'destination' => 'perf.inviter.'.bin2hex(random_bytes(4)).'@example.com', 'full_name' => $inviterName,
        'resident_id' => $inviterResident, 'unit_id' => $inviterUnit, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $ti = $inviter->createToken('resident-app:perf-inviter', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    $invitee = $probe->call('members.store', $ti, body: ['full_name' => 'Perf Invitee '.bin2hex(random_bytes(2)), 'relationship' => 'spouse', 'phone' => '876 555 0121', 'email' => 'perf.invitee.'.bin2hex(random_bytes(4)).'@example.com'], variant: 'invitee')->json('id');
    $estate->run(static fn () => ResidentInvite::query()->where('resident_id', $invitee)->update(['last_attempt_at' => now()->subMinutes(10)]));
    $probe->call('members.invite', $ti, ['member' => $invitee]);
    $probe->call('members.invite_cancel', $ti, ['member' => $invitee]);
    $probe->call('members.level', $ti, ['member' => $invitee], ['access_level' => AppMatrix::MEMBER]);
    $probe->call('members.destroy', $ti, ['member' => $invitee], variant: 'invitee');

    /*
     * The walk's own leftovers from earlier runs go first. Each run keeps two contacts and two vehicles so the lists
     * read below have something in them; across a day of runs they piled up, and a new number that matched a kept one
     * was answered `200 already_listed` — the product's de-duplication, not a fault — where the walk expected a 201.
     */
    $estate->run(static function () use ($householdId): void {
        DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $householdId)->where('name', 'like', 'Perf Contact %')->delete();
        DB::connection('tenant')->table('household_vehicles')->where('household_id', $householdId)->where('plate', 'like', 'PF %')->delete();
    });

    $vehicles = [];
    $contacts = [];
    foreach (range(1, 3) as $i) {
        $vehicles[] = $probe->call('vehicles.store', $t, body: ['plate' => 'PF '.random_int(1000, 9999).chr(65 + $i), 'make' => 'Toyota', 'model' => 'Axio', 'colour' => 'Silver'])->json('id');
        $contacts[] = $probe->call('contacts.store', $t, body: ['name' => 'Perf Contact '.$i, 'relationship' => 'sister', 'phone' => '+1 876 555 0'.random_int(100, 999), 'email' => 'perf.contact.'.$i.'@example.com'])->json('id');
    }
    foreach ($contacts as $i => $contact) {
        $probe->call('contacts.update', $t, ['contact' => $contact], ['email' => 'perf.contact.'.$i.'.'.bin2hex(random_bytes(3)).'@example.com']);
    }
    // One of each removed; two stay, so the lists read below still have something in them.
    $probe->call('contacts.destroy', $t, ['contact' => $contacts[0]]);
    $probe->call('vehicles.destroy', $t, ['vehicle' => $vehicles[0]]);

    $passes = [];
    foreach (range(1, 5) as $i) {
        $passes[] = $probe->call('visitor_passes.store', $t, body: [
            'category' => 'single', 'visitor_name' => 'Perf Visitor '.$i, 'purpose' => 'Family visit', 'visitor_phone' => '876 555 0123', 'vehicle_plate' => 'PC 4471',
            'valid_from' => now()->toIso8601String(), 'valid_to' => now()->addHours(3)->toIso8601String(),
        ])->json('id');
    }
    foreach (array_slice($passes, 0, 3) as $pass) {
        $probe->call('visitor_passes.update', $t, ['pass' => $pass], ['vehicle_plate' => '1234 AB']);
        $probe->call('visitor_passes.share', $t, ['pass' => $pass], ['channel' => 'whatsapp']);
        $probe->call('visitor_passes.cancel', $t, ['pass' => $pass]);
    }

    $walkUps = $estate->run(static function () use ($unitId, $householdId): array {
        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = DB::connection('tenant')->table('gate_approval_requests')->insertGetId([
                'unit_id' => $unitId, 'household_id' => $householdId, 'guard_id' => 1, 'guard_name' => 'Perf Officer', 'post_name' => 'Main Gate',
                'visitor_name' => 'Perf courier '.$i, 'status' => 'pending', 'requested_at' => now(), 'respond_by' => now()->addMinutes(3),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ids;
    });
    foreach ($walkUps as $walkUp) {
        $probe->call('approvals.respond', $t, ['approval' => $walkUp], ['decision' => 'approve']);
    }

    foreach (range(1, 3) as $i) {
        $probe->call('push.register', $t, body: ['token' => 'ExponentPushToken[perfResident'.bin2hex(random_bytes(6)).']', 'platform' => 'android', 'muted' => ['maintenance']]);
        $probe->call('push.unregister', $t);
    }

    /*
     * BILLED THE WAY THE OFFICE BILLS, so the ledger owes it (found M17). A charge row written straight into the table
     * is on no ledger, and the card paths below check the amount against what the LEDGER says is owed: across a day of
     * runs the walk's own card payments used up the household's real balance and `payments.card` was refused as "more
     * than is owed". `Dues::charge` posts the receivable with the row, so every run starts with J$12,500 to pay.
     *
     * DUE YESTERDAY, so it is the OLDEST thing owed. Money clears the oldest charge first: due ten days out, the walk's
     * card payments cleared the booking fees raised below (due today) before it, and `bookings.pay_card` was refused as
     * "already paid".
     */
    $chargeId = $estate->run(static fn (): int => (int) app(Dues::class)->charge(
        Unit::query()->findOrFail($unitId), Money::ofMinor(12_500_00, 'JMD'), 'Maintenance fee', now()->subDay()->toDateString(),
    )->id);
    foreach (range(1, 3) as $i) {
        $probe->call('invoices.pay_intent', $t, ['invoice' => $chargeId]);
        $probe->call('autopay.store', $t);
        $probe->call('autopay.destroy', $t, ['autopay' => 1]);
    }

    // Paying by card: the demonstration test card (D-179), and the card gateway's own path (M16 §2.2) against the
    // simulated provider this server runs with. A card payment is never queued, so each is made once per call.
    foreach (range(1, 3) as $i) {
        $probe->call('payments.card', $t, body: ['amount' => '1000.00', 'brand' => 'visa', 'last4' => '4242']);
    }
    $intents = [];
    foreach (range(1, 3) as $i) {
        $intents[] = (string) $probe->call('payments.intents.store', $t, body: ['amount' => '500.00'])->json('reference');
    }
    $probe->call('payments.intents.show', $t, ['reference' => $intents[0]], runs: $reads, warm: true);

    $photo = perfPhoto();
    $tickets = [];
    foreach (range(1, 3) as $i) {
        $ticket = $probe->call('tickets.store', $t, body: ['title' => 'Street light out by the gate '.$i, 'category' => 'electrical', 'description' => 'Out since Tuesday night.', 'priority' => 'low'])->json('id');
        $probe->call('tickets.media', $t, ['ticket' => $ticket], file: ['file', $photo, 'street-light-'.$i.'.jpg']);
        $tickets[] = (int) $ticket;
    }

    // The office marks two done; the resident confirms one and says the other is still a problem.
    $estate->run(static fn () => MaintenanceTicket::query()->whereKey(array_slice($tickets, 0, 2))->update(['status' => MaintenanceTicket::COMPLETED]));
    $probe->call('tickets.confirm_fixed', $t, ['ticket' => $tickets[0]]);
    $probe->call('tickets.still_a_problem', $t, ['ticket' => $tickets[1]], ['reason' => 'The light is out again after dark.']);

    // The resident's own photo, and the other phones signed in to the account.
    foreach (range(1, 3) as $i) {
        $probe->call('me.photo.store', $t, file: ['file', $photo, 'me-'.$i.'.jpg']);
    }
    $probe->call('me.photo', $t, runs: $reads, warm: true);
    $probe->call('me.photo.remove', $t);
    $secondPhone = $account->createToken('resident-app:perf-second-phone', AppMatrix::abilitiesFor(AppMatrix::RESIDENT));
    $probe->call('me.sessions', $t, runs: $reads, warm: true);
    $probe->call('me.sessions.end', $t, ['session' => $secondPhone->accessToken->id]);

    [$noticeIds, $attachment] = $estate->run(static function (): array {
        $notice = new Notice;
        $notice->forceFill([
            'kind' => Notice::URGENT, 'title' => 'Water lock-off Thursday (perf)', 'body' => 'NWC works on the main line, 10 AM to 2 PM.',
            'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'Estate Secretary', 'published_at' => now()->subMinute(),
        ])->save();

        $letter = "%PDF-1.4\n% perf walk\n".str_repeat('0', 180_000)."\n%%EOF\n";
        $by = User::query()->where('email', ScaleEstate::ESTATE_ADMIN_EMAIL)->firstOrFail();
        $attached = app(Notices::class)->attach($notice, 'NWC letter.pdf', $letter, 'application/pdf', $by);

        return [Notice::query()->orderByDesc('id')->limit(3)->pluck('id')->all(), [$notice->id, $attached->id]];
    });
    foreach ($noticeIds as $notice) {
        $probe->call('notices.read', $t, ['notice' => $notice]);
    }
    $probe->call('notices.attachment', $t, ['notice' => $attachment[0], 'attachment' => $attachment[1]], runs: 5, warm: true);

    $meetingId = $estate->run(static function (): int {
        $meeting = new Meeting;
        $meeting->forceFill([
            'type' => Meeting::AGM, 'title' => 'Annual General Meeting (perf) '.bin2hex(random_bytes(2)), 'starts_at' => now()->addDays(30), 'venue' => 'Club House',
            'audience_scope' => Meeting::WHOLE_ESTATE, 'status' => Meeting::SCHEDULED, 'published_at' => now(),
        ])->save();

        return $meeting->id;
    });
    foreach (['attending', 'not_attending', 'attending'] as $answer) {
        $probe->call('meetings.rsvp', $t, ['meeting' => $meetingId], ['response' => $answer]);
    }

    [$open, $options, $certified] = $estate->run(static function (): array {
        $make = static function (string $title, string $stage, bool $published): array {
            $ballot = new Ballot;
            $ballot->forceFill([
                'year' => (int) now()->year, 'title' => $title, 'kind' => Ballot::ELECTION, 'stage' => $stage,
                'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(2), 'eligible_households' => 441,
                'certified_at' => $stage === Ballot::CERTIFIED ? now()->subHour() : null,
                'published_at' => $published ? now()->subMinutes(30) : null,
            ])->save();

            $position = new BallotPosition;
            $position->forceFill(['ballot_id' => $ballot->id, 'name' => 'Estate Executive', 'seat_count' => 2, 'scope' => 'estate', 'sort_order' => 1])->save();

            $ids = [];
            foreach (['Andre Clarke', 'Simone Grant', 'Winston Hall'] as $i => $label) {
                $option = new BallotOption;
                $option->forceFill(['ballot_id' => $ballot->id, 'ballot_position_id' => $position->id, 'label' => $label, 'sort_order' => $i])->save();
                $ids[] = $option->id;
            }

            return [$ballot->id, $ids];
        };

        [$open, $options] = $make('Perf Election '.bin2hex(random_bytes(2)), Ballot::VOTING_OPEN, false);
        [$certified] = $make('Perf Certified '.bin2hex(random_bytes(2)), Ballot::CERTIFIED, true);

        return [$open, $options, $certified];
    });
    $probe->call('elections.eligibility', $t, ['ballot' => $open], runs: $reads, warm: true);
    $probe->call('elections.ballot', $t, ['ballot' => $open], ['option_ids' => [$options[0]]]);
    $probe->call('elections.results', $t, ['ballot' => $certified], runs: $reads, warm: true);

    $pool = $estate->run(static fn (): int => (int) Amenity::query()->where('name', 'Pool Deck')->value('id'));
    expect($pool)->toBeGreaterThan(0, 'Phoenix Park has no Pool Deck to book');
    $day = now('America/Jamaica')->addDays(9)->startOfDay();
    // The paid bookings below are confirmed and stay; the next run asks for the same mornings, and the diary refuses a
    // double booking (the product working). The walk's own future Pool Deck bookings are let go first.
    $estate->run(static fn () => AmenityBooking::query()->where('unit_id', $unitId)->where('amenity_id', $pool)->where('starts_at', '>', now())
        ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])->update(['status' => AmenityBooking::CANCELLED]));
    foreach (range(0, 2) as $i) {
        $booking = $probe->call('bookings.store', $t, body: [
            'amenity_id' => $pool, 'starts_at' => $day->copy()->addDays($i)->setTime(7, 0)->toIso8601String(), 'ends_at' => $day->copy()->addDays($i)->setTime(8, 0)->toIso8601String(), 'guests' => 2,
        ])->json('id');
        $probe->call('bookings.cancel', $t, ['booking' => $booking]);
    }

    // A booking the office has confirmed with a fee, paid by (test) card — the fee is raised as a charge and paid.
    foreach (range(0, 2) as $i) {
        $paid = $probe->call('bookings.store', $t, body: [
            'amenity_id' => $pool, 'starts_at' => $day->copy()->addDays(4 + $i)->setTime(9, 0)->toIso8601String(), 'ends_at' => $day->copy()->addDays(4 + $i)->setTime(10, 0)->toIso8601String(), 'guests' => 2,
        ])->json('id');
        $estate->run(static fn () => AmenityBooking::query()->whereKey($paid)->update(['status' => AmenityBooking::CONFIRMED, 'fee_minor' => 1_500_00]));
        $probe->call('bookings.pay_card', $t, ['booking' => $paid], ['part' => 'fee', 'brand' => 'visa', 'last4' => '4242']);
    }

    /* ── The resident's reads, now that the household has a history ── */

    $probe->call('panic.contacts', $t, runs: $reads, warm: true);

    // Live updates while the app is open (M11 Part C): which channels, their signatures, and what they missed.
    $liveChannels = collect($probe->call('live.config', $t, runs: $reads, warm: true)->json('channels'))->pluck('name')->all();
    foreach (range(1, 3) as $i) {
        $probe->call('live.auth', $t, body: ['socket_id' => '1234.567'.$i, 'channel_names' => array_map(static fn (string $c): string => 'private-live.'.$c, $liveChannels)]);
        $probe->call('live.catch_up', $t, body: ['channels' => array_map(static fn (string $c): array => ['name' => $c, 'c' => null, 'e' => null], $liveChannels)]);
    }

    foreach (['me.show', 'me.household', 'members.index', 'vehicles.index', 'contacts.index', 'visitor_passes.index', 'epass.show', 'invoices.index', 'payments.index', 'tickets.index', 'notices.index', 'meetings.index', 'elections.index', 'amenities.index', 'bookings.index'] as $name) {
        $probe->call($name, $t, runs: $reads, warm: true);
    }

    $probe->call('households.balance', $t, ['household' => $householdId], runs: $reads, warm: true);
    $probe->call('households.statement', $t, ['household' => $householdId], ['limit' => 10], runs: $reads, warm: true);

    $paymentId = $estate->run(static fn (): ?int => Payment::query()->where('unit_id', $unitId)->orderByDesc('id')->value('id'));
    expect($paymentId)->not->toBeNull('the household has no payment to read a receipt for');
    $probe->call('payments.receipt', $t, ['payment' => $paymentId], runs: $reads, warm: true);

    // The resident's panic, cancelled at once as the contract walk does: its fan-out is measured
    // leg by leg in PanicBaselineTest; here it is the endpoint's own answer.
    $panics = [];
    foreach (range(1, 3) as $i) {
        $panic = $probe->call('duress.store', $t, body: ['mode' => 'audible'])->json('id');
        $probe->call('duress.cancel', $t, ['alert' => $panic]);
        $panics[] = $panic;
    }
    $probe->call('duress.show', $t, ['alert' => $panics[0]], runs: $reads, warm: true);

    $probe->call('me.delete_account', $pending[1], body: ['confirm' => 'DELETE']);

    // Signing in to it again is told it was deleted, and "Restore my account" brings it back (M18H §2.3).
    $deleted = $probe->call('auth.otp.verify', null, body: ['estate' => $site, 'channel' => 'email', 'destination' => $pendingEmails[1], 'code' => $codeFor($pendingEmails[1]), 'device_uid' => 'perf-install-2', 'platform' => 'android'], status: 409, variant: 'deleted');
    $probe->call('auth.restore', null, body: ['estate' => $site, 'ticket' => (string) $deleted->json('error.details.ticket'), 'device_uid' => 'perf-install-2', 'platform' => 'android']);
    $probe->call('auth.sign_out', $pending[2]);

    /* ── The Guard App: GS-PPV-01, the officer phoenix:demo-seed rosters for a fortnight ── */

    $guard = Guard::query()->where('employee_number', 'GS-PPV-01')->where('tenant_id', $site)->firstOrFail();
    $post = $guard->post()->firstOrFail();
    $enrolment = app(DeviceEnrolment::class);
    $g = $enrolment->enrol($guard, 'Perf handset', ['device_uid' => 'perf-guard-handset', 'platform' => 'android', 'public_key' => perfPublicKey()])['token'];

    // Enrolling by code over HTTP: three of company:demo-seed's officers, each with no handset yet.
    foreach (Guard::query()->where('tenant_id', $site)->where('employee_number', 'like', 'GS-20%')->orderBy('employee_number')->limit(3)->get() as $i => $officer) {
        $enrolment->revoke($officer);
        $probe->call('devices.enrol', null, body: ['enrolment_code' => $enrolment->issueCode($officer), 'device_uid' => 'perf-enrol-'.$i, 'platform' => 'android', 'public_key' => perfPublicKey(), 'label' => 'Perf enrol '.$i]);
    }

    // And the poll a second handset makes while a supervisor decides (202, then collect).
    $rebind = Guard::query()->where('tenant_id', $site)->where('employee_number', 'like', 'GS-20%')->orderByDesc('employee_number')->firstOrFail();
    $enrolment->enrol($rebind, 'Old handset', ['device_uid' => 'perf-old-phone', 'platform' => 'android', 'public_key' => perfPublicKey()]);
    $asked = $probe->call('devices.enrol', null, body: ['enrolment_code' => $enrolment->issueCode($rebind), 'device_uid' => 'perf-new-phone', 'platform' => 'android', 'public_key' => perfPublicKey()], status: 202, variant: 'rebind pending');
    foreach (range(1, 3) as $i) {
        $probe->call('devices.enrol.status', null, ['rebind' => $asked->json('rebind_request_id')], ['claim_secret' => $asked->json('claim_secret'), 'device_uid' => 'perf-new-phone']);
    }

    // The watch the roster has this officer on now; one is made if the fortnight has run out.
    $shift = Shift::query()->where('guard_id', $guard->id)->whereNull('actual_start')->where('rostered_start', '<=', now()->addMinutes(20))->where('rostered_end', '>', now())->orderBy('rostered_start')->first()
        ?? Shift::query()->create(['tenant_id' => $site, 'guard_id' => $guard->id, 'post_id' => $post->id, 'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(12), 'status' => 'rostered']);

    $openShifts = [];
    foreach (range(1, 3) as $i) {
        $openShifts[] = Shift::query()->create(['tenant_id' => $site, 'guard_id' => null, 'post_id' => $post->id, 'rostered_start' => now()->addDays(3 + $i), 'rostered_end' => now()->addDays(3 + $i)->addHours(12), 'status' => Shift::OPEN])->id;
    }

    $here = ['latitude' => (float) $post->latitude, 'longitude' => (float) $post->longitude, 'accuracy_m' => 3];

    $probe->call('shifts.me.current', $g, runs: $reads, warm: true);
    $probe->call('shifts.preflight', $g, ['shift' => $shift->id], $here, runs: 3);
    $probe->call('shifts.clock_in', $g, ['shift' => $shift->id], ['geofence_distance_m' => 2]);

    // On-duty location sharing (M11 §3): the policy, the officer's answer, and what the phone sampled on shift.
    $probe->call('tracking.status', $g, runs: $reads, warm: true);
    $probe->call('tracking.consent', $g, body: ['decision' => 'granted', 'policy_version' => (string) config('tracking.policy_version')]);
    foreach (range(1, 3) as $i) {
        $probe->call('tracking.positions', $g, ['shift' => $shift->id], ['positions' => [
            ['id' => 'perf-fix-'.$i.'-'.bin2hex(random_bytes(3)), 'latitude' => (float) $post->latitude, 'longitude' => (float) $post->longitude, 'accuracy_m' => 6, 'source' => 'interval', 'battery_pct' => 80 - $i, 'captured_at' => now()->subSeconds(20)->toIso8601String()],
        ]]);
    }

    // Live updates, the officer's side.
    $guardChannels = collect($probe->call('live.config', $g, runs: $reads, warm: true, variant: 'guard')->json('channels'))->pluck('name')->all();
    $probe->call('live.auth', $g, body: ['socket_id' => '4321.8765', 'channel_names' => array_map(static fn (string $c): string => 'private-live.'.$c, $guardChannels)], variant: 'guard');
    $probe->call('live.catch_up', $g, body: ['channels' => array_map(static fn (string $c): array => ['name' => $c, 'c' => null, 'e' => null], $guardChannels)], variant: 'guard');
    $probe->call('shifts.break.start', $g, ['shift' => $shift->id]);
    $probe->call('shifts.break.end', $g, ['shift' => $shift->id]);
    $probe->call('shifts.me', $g, body: ['from' => now()->subDays(6)->toDateString(), 'to' => now()->addDay()->toDateString()], runs: $reads, warm: true);
    $probe->call('shifts.open', $g, runs: $reads, warm: true);
    foreach ($openShifts as $open) {
        $probe->call('shifts.claim', $g, ['shift' => $open]);
    }

    $setId = DB::connection('mysql')->table('standing_order_sets')->insertGetId([
        'title' => 'Perf orders', 'category' => 'post_specific', 'summary' => 'Post orders', 'tenant_id' => $site, 'post_id' => $post->id, 'version' => 1,
        'body' => 'Log every contractor out as well as in.', 'effective_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $versionId = DB::connection('mysql')->table('standing_order_versions')->insertGetId([
        'standing_order_set_id' => $setId, 'version' => 1, 'title' => 'Perf orders', 'summary' => 'Post orders',
        'body' => 'Log every contractor out as well as in.', 'effective_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $probe->call('orders.current', $g, ['site' => $site], runs: $reads, warm: true);
    $probe->call('orders.acknowledge', $g, ['version' => $versionId]);
    $probe->call('standing_orders.index', $g, runs: $reads, warm: true);

    $probe->call('patrol.checkpoints', $g, ['site' => $site], runs: $reads, warm: true);
    foreach (DB::connection('mysql')->table('patrol_checkpoints')->where('tenant_id', $site)->orderBy('sequence')->limit(3)->get() as $checkpoint) {
        $probe->call('patrol.scan', $g, ['checkpoint' => $checkpoint->id], ['code' => $checkpoint->code]);
    }

    $check = DB::connection('mysql')->table('alertness_checks')->insertGetId([
        'guard_id' => $guard->id, 'shift_id' => $shift->id, 'outcome' => 'pending', 'issued_at' => now(), 'respond_by' => now()->addMinutes(2), 'server_time' => now(),
    ]);
    foreach (range(1, 3) as $i) {
        $probe->call('presence.activity', $g, body: ['state' => 'on_post', ...$here, 'battery_pct' => 81 - $i]);
    }
    $probe->call('alertness.respond', $g, ['check' => $check]);
    $probe->call('presence.summary', $g, runs: $reads, warm: true);

    /* ── The gate: passes, the search across 2,000 lots, walk-ups ── */

    $probe->call('passes.keys', $g, ['site' => $site], runs: $reads, warm: true);

    $issue = static fn (string $visitor): object => $estate->run(static fn () => app(VisitorPasses::class)->issue($site, Unit::query()->findOrFail($unitId), $residentId, $residentName, [
        'category' => 'single', 'visitor_name' => $visitor, 'purpose' => 'Family visit', 'valid_from' => Carbon::now()->subMinutes(5), 'valid_to' => Carbon::now()->addHours(2),
    ]));

    $verified = $issue('Perf Gate Visitor');
    $probe->call('gate.verify', $g, body: ['pass' => $verified->token], runs: 5, warm: true);

    foreach (range(1, 3) as $i) {
        $admitted = $issue('Perf Entry '.$i);
        $probe->call('gate.entry', $g, body: ['pass_id' => $admitted->pass_id, 'category' => 'Visitor']);
        $probe->call('gate.exit', $g, body: ['category' => 'Visitor', 'subject' => 'Perf Entry '.$i.' · '.$unitRef]);
        $probe->call('gate.override', $g, body: ['category' => 'Contractor', 'subject' => 'JPS crew '.$i, 'reason' => 'Power outage on the estate']);
    }

    $surname = (string) collect(explode(' ', $residentName))->last();
    $probe->call('gate.search', $g, body: ['unit' => 'Lot 1'], runs: $reads, warm: true, variant: 'unit=Lot 1');
    $probe->call('gate.search', $g, body: ['name' => $surname], runs: $reads, warm: true, variant: 'name=surname');
    $probe->call('gate.search', $g, body: ['unit' => 'Lot', 'phase' => 'Phase 5'], runs: $reads, warm: true, variant: 'unit=Lot, phase=Phase 5');
    $probe->call('gate.activity', $g, runs: $reads, warm: true);

    $approvals = [];
    foreach (range(1, 3) as $i) {
        $approvals[] = $probe->call('gate.approvals.store', $g, body: ['unit' => $unitRef, 'visitor_name' => 'Perf walk-up '.$i, 'id_type' => "Driver's licence", 'id_number' => 'D1234567', 'purpose' => 'Delivery', 'vehicle_plate' => 'PC 4471'])->json('approval_id');
    }
    $probe->call('gate.approvals.show', $g, ['approval' => $approvals[0]], runs: $reads, warm: true);

    foreach (range(1, 3) as $i) {
        $probe->call('passes.verify', $g, body: ['household_id' => $householdId, 'pass_category' => 'guest']);
        $probe->call('gate_events.store', $g, body: ['verdict' => 'admit', 'category' => 'Resident vehicle', 'subject' => $unitRef.' · 55'.random_int(10, 99).' JX', 'basis' => 'Tag read']);
    }

    /* ── The guard ring (G1–G30): a handset that rings like a call, its test ring, and answering a real one ── */

    $ringReady = ['v' => 1, 'configured' => true, 'notifications' => 'on', 'ring_channel' => 'on', 'full_screen' => 'on', 'dnd_bypass' => 'on',
        'battery' => 'exempt', 'background' => 'allowed', 'alarm_volume' => 'ok', 'alarm_tone' => 'set', 'screen_lock' => 'on', 'sdk' => 35, 'maker' => 'samsung'];
    $probe->call('push.register', $g, body: ['token' => 'ExponentPushToken[perfGuardRing'.bin2hex(random_bytes(4)).']', 'platform' => 'android', 'ring' => $ringReady], variant: 'guard ring');
    $probe->call('ring.test', $g);

    // The ticket rides in the push; it is minted here exactly as the send job mints it, for the same claims.
    $pushRow = DevicePushToken::query()->live()->ownedBy($site, DevicePushToken::GUARD, (int) $guard->id)->where('app', AppMatrix::GUARD)->latest('id')->firstOrFail();
    $testSeq = (int) DuressAlertRingEvent::query()->where('duress_alert_id', 0)->where('push_token_id', $pushRow->id)->max('test_seq');
    $testTicket = RingTicket::issue(['a' => 0, 't' => $site, 'g' => (int) $guard->id, 'p' => (int) $pushRow->id, 'r' => 0, 's' => $testSeq, 'k' => RingTicket::TEST]);
    foreach (range(1, 3) as $i) {
        $probe->call('ring.receipt', null, body: ['rt' => $testTicket, 'event' => 'shown', 'detail' => 'imp=4;snd=1;vol=7/7;dnd=pass;fsi=1;lk=1']);
    }

    // A resident's panic that rang this officer, answered "on my way" from the ring screen.
    foreach (range(1, 3) as $i) {
        $rung = $estate->run(static fn () => app(AlertIntake::class)->record(tenantId: $site, kind: 'panic', raisedByName: $residentName, unitReference: $unitRef, idempotencyKey: 'perf-ring-'.bin2hex(random_bytes(4))));
        RingTargets::add($rung, [(int) $guard->id], 'estate', 1);
        $ringTicket = RingTicket::issue(['a' => (int) $rung->id, 't' => $site, 'g' => (int) $guard->id, 'p' => (int) $pushRow->id, 'r' => 1, 's' => 0, 'k' => RingTicket::RING]);
        $probe->call('ring.answer', null, body: ['rt' => $ringTicket, 'answer' => 'responding', 'age_ms' => 1800]);
    }

    /* ── Reports, duress, responding to a resident's panic ── */

    $incidents = [];
    foreach (range(1, 3) as $i) {
        $incidents[] = $probe->call('incidents.store', $g, body: ['kind' => 'Attempted unauthorized access', 'severity' => 'med', 'location' => 'North fence', 'detail' => 'Two men attempted to climb the north fence and left when challenged. Perf '.$i.'.'])->json('id');
    }
    foreach ($incidents as $i => $incident) {
        $probe->call('incidents.media', $g, ['incident' => $incident], file: ['file', $photo, 'north-fence-'.$i.'.jpg']);
    }
    $probe->call('incidents.me', $g, runs: $reads, warm: true);

    $duress = [];
    foreach (range(1, 3) as $i) {
        $pressed = $probe->call('duress.store', $g, body: ['mode' => 'silent'])->json('id');
        $probe->call('duress.cancel', $g, ['alert' => $pressed]);
        $duress[] = $pressed;
    }
    $probe->call('duress.show', $g, ['alert' => $duress[0]], runs: $reads, warm: true, variant: 'guard');

    foreach (range(1, 3) as $i) {
        $probe->call('alerts.store', $g, body: ['kind' => 'medical']);
    }

    $panicHere = $estate->run(static fn () => app(AlertIntake::class)->record(tenantId: $site, kind: 'panic', raisedByName: $residentName, unitReference: $unitRef, idempotencyKey: 'perf-response-'.bin2hex(random_bytes(4))));
    $probe->call('alerts.show', $g, ['alert' => $panicHere->id], runs: $reads, warm: true);
    $probe->call('alerts.en_route', $g, ['alert' => $panicHere->id]);
    $probe->call('alerts.on_scene', $g, ['alert' => $panicHere->id]);

    /* ── The guard's own: requests, pay, messages, sync ── */

    foreach (range(1, 3) as $i) {
        $probe->call('requests.store', $g, body: ['kind' => 'leave', 'subject' => 'vacation', 'reason' => 'Family trip', 'starts_on' => now()->addMonths($i)->toDateString(), 'ends_on' => now()->addMonths($i)->addDays(4)->toDateString()]);
    }
    $probe->call('requests.index', $g, runs: $reads, warm: true);

    $slip = Payslip::query()->where('guard_id', $guard->id)->orderByDesc('id')->value('id');
    expect($slip)->not->toBeNull('phoenix:demo-seed built no payslip for GS-PPV-01');
    $probe->call('payslips.me', $g, runs: $reads, warm: true);
    $probe->call('payslips.pdf', $g, ['payslip' => $slip], runs: 5, warm: true);

    $probe->call('messages.index', $g, runs: $reads, warm: true);
    foreach (range(1, 3) as $i) {
        $probe->call('messages.store', $g, body: ['body' => 'Barrier arm sticking at the main gate. Perf '.$i.'.']);
    }
    $unread = DB::connection('mysql')->table('dispatch_messages')->where('tenant_id', $site)->pluck('id')->all();
    foreach (range(1, 3) as $i) {
        $probe->call('messages.read', $g, body: ['ids' => $unread]);
    }

    $probe->call('sync.pull', $g, body: ['since' => now()->subHour()->toIso8601String()], runs: $reads, warm: true);
    $firstCheckpoint = DB::connection('mysql')->table('patrol_checkpoints')->where('tenant_id', $site)->orderBy('sequence')->first();
    // The queued scan below was made forty minutes ago, on this shift: a scan belongs to a running shift (M21 §1.1).
    Shift::query()->whereKey($shift->id)->update(['actual_start' => now()->subHour()]);
    foreach (range(1, 3) as $i) {
        $probe->call('sync.batch', $g, body: ['operations' => [
            ['id' => 'op-1', 'endpoint' => 'patrol.scan', 'params' => ['checkpoint' => $firstCheckpoint->id], 'body' => ['code' => $firstCheckpoint->code, 'captured_offline' => true], 'idempotency_key' => 'perf-queued-scan-'.bin2hex(random_bytes(4)), 'device_time' => now()->subMinutes(40)->toIso8601String()],
            ['id' => 'op-2', 'endpoint' => 'messages.store', 'body' => ['body' => 'Back on post after the outage.'], 'idempotency_key' => 'perf-queued-msg-'.bin2hex(random_bytes(4)), 'device_time' => now()->subMinutes(35)->toIso8601String()],
        ]]);
    }

    $probe->call('guards.me', $g, runs: $reads, warm: true);

    // A supervisor's PIN on the next watch, as the contract walk vouches for one.
    $dispatcher = User::query()->firstOrNew(['email' => 'perf-dispatcher@demo.geminisl.com']);
    $dispatcher->forceFill(['name' => 'Perf Dispatcher', 'console' => Console::Gemini->value, 'status' => 'active', 'password' => bin2hex(random_bytes(16)), 'email_verified_at' => now()])->save();
    $dispatcher->syncRoles([Role::DISPATCHER]);
    DemonstrationAccounts::verify($dispatcher);
    app(SupervisorOverride::class)->setPin($dispatcher->fresh(), '482913');
    $nextShift = Shift::query()->create(['tenant_id' => $site, 'guard_id' => $guard->id, 'post_id' => $post->id, 'rostered_start' => now()->addMinutes(5), 'rostered_end' => now()->addHours(12), 'status' => 'rostered']);

    $probe->call('presence.summary', $g, runs: 1, variant: 'before clock-out');
    $probe->call('shifts.clock_out', $g, ['shift' => $shift->id], ['handover_note' => 'Barrier arm sticking; logged with dispatch.']);
    $probe->call('shifts.clock_in_override', $g, ['shift' => $nextShift->id], ['supervisor' => $dispatcher->email, 'pin' => '482913', 'reason' => 'biometric_failed', 'geofence_distance_m' => 2]);

    foreach (range(1, 3) as $i) {
        $probe->call('push.register', $g, body: ['token' => 'ExponentPushToken[perfGuard'.bin2hex(random_bytes(6)).']', 'platform' => 'android', 'muted' => ['payslips']], variant: 'guard');
        $probe->call('push.unregister', $g, variant: 'guard');
    }

    $probe->call('auth.sign_out', $g, variant: 'guard');

    /* ── What was not called, and why — said, not hidden ── */

    $called = collect(array_keys($probe->samples))->map(static fn (string $k): string => explode(' [', $k)[0])->unique()->values()->all();
    $appRoutes = collect(Catalogue::endpoints())->keys()->all();
    $notCalled = array_values(array_diff($appRoutes, $called));

    $summary = $probe->summary();
    $out = ScaleEstate::out().DIRECTORY_SEPARATOR.'api-local.json';
    file_put_contents($out, json_encode([
        'started_at' => Carbon::createFromTimestamp((int) $started)->toIso8601String(),
        'measured_at' => now()->toIso8601String(),
        'base' => $base,
        'read_runs' => $reads,
        'walk_seconds' => round(microtime(true) - $started, 1),
        'size' => $size,
        'not_called' => $notCalled,
        'endpoints' => $summary,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    fwrite(STDOUT, PHP_EOL.sprintf('%-44s %4s %9s %9s %9s %5s %8s %8s', 'endpoint', 'n', 'srv med', 'srv p95', 'cli p95', 'q', 'bytes', 'gzip').PHP_EOL);
    foreach ($summary as $key => $row) {
        fwrite(STDOUT, sprintf('%-44s %4d %9.1f %9.1f %9.1f %5s %8s %8s', mb_substr($key, 0, 44), $row['n'], $row['server_ms_median'], $row['server_ms_p95'], $row['client_ms_p95'], (string) $row['queries_median'], (string) $row['bytes_median'], (string) $row['gzip_bytes_median']).PHP_EOL);
    }
    fwrite(STDOUT, 'not called: '.implode(', ', $notCalled).PHP_EOL.'written '.$out.PHP_EOL);

    // Every endpoint either app calls was measured, except the one kept for the store reviewers.
    expect($notCalled)->toBe(['demo.enter']);
});
