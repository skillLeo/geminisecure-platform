<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingAttendance;
use App\Models\Estate\MeetingMinutes;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Push\PanicFanOut;
use App\Services\Push\PushAnnouncer;
use App\Services\Push\PushNotifier;
use App\Support\SiteTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A meeting has a life (the user, M56 §2, 8 October 2026)
|--------------------------------------------------------------------------
|
| "I cannot edit a meeting. I cannot delete one. I cannot postpone one." Draft, Scheduled, In progress, Held, Minutes
| published, Archived; Postponed and Cancelled off Scheduled. Three rules behind all of it: before anyone is told,
| anything may change; nothing the community acted on is deleted (it is cancelled, with a reason and a name); and a new
| date restarts the notice period, counted from today.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: every meeting here carries this file's title and goes afterwards.
|
*/

const LIFE_TITLE = 'Lifecycle Test Meeting';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 9479');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Lifecycle household', 'access_restricted' => false]);
    $person = new Resident;
    $person->forceFill(['household_id' => $this->household->id, 'full_name' => 'Lee Lifecycle', 'email' => 'lee.life.'.bin2hex(random_bytes(3)).'@example.com', 'phone' => '+1 876 555 9479', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $person->email, 'full_name' => 'Lee Lifecycle',
        'resident_id' => $person->id, 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
        'device_uid' => 'life-install-1', 'device_platform' => 'android',
    ]);
    $this->token = $account->createToken('resident-app:life-install-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->president = FacilitiesFixture::viewer(Role::PRESIDENT);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    $ids = Meeting::query()->where('title', 'like', LIFE_TITLE.'%')->pluck('id');
    MeetingMinutes::query()->whereIn('meeting_id', $ids)->delete();
    MeetingAttendance::query()->whereIn('meeting_id', $ids)->delete();
    DB::connection('tenant')->table('meeting_rsvps')->whereIn('meeting_id', $ids)->delete();
    Meeting::query()->whereIn('id', $ids)->delete();
    Resident::withRemoved()->where('household_id', $this->household->id)->delete();
});

/** A meeting of this file's, in whatever state the test needs. */
function lifeMeeting(array $over = []): Meeting
{
    FacilitiesFixture::boot();

    return Meeting::query()->forceCreate([
        'type' => Meeting::EGM, 'title' => LIFE_TITLE, 'starts_at' => now()->addDays(40), 'duration_minutes' => 120,
        'held_as' => Meeting::IN_PERSON, 'venue' => 'Clubhouse', 'audience_scope' => Meeting::WHOLE_ESTATE,
        'quorum_percent' => 20, 'quorum_basis' => Meeting::HOUSEHOLDS, 'eligible_households' => 10,
        'status' => Meeting::SCHEDULED, 'published_at' => now()->subDays(30), 'notice_given_at' => now()->subDays(30),
        ...$over,
    ]);
}

/** The Resident App's call, held to its catalogue entry. */
function lifeApp(TestCase $test, string $token, string $name, array $params = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'life-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $response = $test->withToken($token)->json($entry['method'], $uri, $body, $headers);
    $response->assertStatus($status ?? $entry['status']);

    if (($status ?? $entry['status']) < 300) {
        ApiContract::assertMatches($response, $name);
    }

    FacilitiesFixture::boot();

    return $response;
}

function lifeUrl(Meeting $meeting, string $act): string
{
    return FacilitiesFixture::url('/governance/meetings/'.$meeting->id.'/'.$act);
}

/** A moment typed as the console's date picker types it: the estate's own clock. */
function lifeLocal(CarbonInterface $at): string
{
    return SiteTime::local($at)->format('Y-m-d\TH:i');
}

it('drafts with the audience its type decides, and refuses a date inside that type\'s notice period, naming the earliest (M56 §2.2, §2.5)', function () {
    $form = ['type' => Meeting::AGM, 'title' => LIFE_TITLE.' AGM', 'audience_scope' => Meeting::PHASE_SUBSET, 'phase' => 'Phase 2', 'held_as' => Meeting::IN_PERSON, 'venue' => 'Clubhouse'];

    // An AGM three days out: refused at the save, with the period and the earliest day.
    $this->actingAs($this->president)->post(FacilitiesFixture::url('/governance/meetings'), [...$form, 'starts_at' => lifeLocal(now()->addDays(3))])
        ->assertSessionHasErrors('starts_at');
    expect((string) session('errors')->first('starts_at'))->toMatch("/^Needs 21 days' notice\\. Earliest \\d{1,2} [A-Z][a-z]{2}\\.$/");

    // Outside it: saved, and an AGM is for the whole estate whatever the form said.
    $this->actingAs($this->president)->post(FacilitiesFixture::url('/governance/meetings'), [...$form, 'starts_at' => lifeLocal(now()->addDays(30))])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $agm = Meeting::query()->where('title', LIFE_TITLE.' AGM')->sole();
    expect([$agm->audience_scope, $agm->phase, $agm->duration_minutes, $agm->stage()])->toBe([Meeting::WHOLE_ESTATE, null, 120, Meeting::STAGE_DRAFT]);

    // A phase meeting names its phase.
    $this->actingAs($this->president)->post(FacilitiesFixture::url('/governance/meetings'), [...$form, 'type' => Meeting::PHASE, 'phase' => null, 'title' => LIFE_TITLE.' Phase', 'starts_at' => lifeLocal(now()->addDays(10))])
        ->assertSessionHasErrors('phase');
});

it('edits and deletes a draft, the delete confirmed by typing its name; a published meeting is never deleted', function () {
    $draft = lifeMeeting(['status' => Meeting::DRAFT, 'published_at' => null, 'notice_given_at' => null]);

    $this->actingAs($this->president)->post(lifeUrl($draft, 'edit'), [
        'type' => Meeting::EGM, 'title' => LIFE_TITLE.' renamed', 'starts_at' => lifeLocal(now()->addDays(20)), 'held_as' => Meeting::ZOOM,
        'virtual_link' => 'https://zoom.us/j/555', 'duration_minutes' => 90, 'agenda' => [['text' => 'Welcome', 'start_time' => '10:00']],
    ])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $draft->refresh()->load('agenda');
    expect([$draft->title, $draft->held_as, $draft->duration_minutes, $draft->agenda->pluck('text')->all()])->toBe([LIFE_TITLE.' renamed', Meeting::ZOOM, 90, ['Welcome']]);

    $this->actingAs($this->president)->post(lifeUrl($draft, 'delete'), ['confirm_name' => 'Something else'])->assertSessionHasErrors('confirm_name');
    $this->actingAs($this->president)->post(lifeUrl($draft, 'delete'), ['confirm_name' => LIFE_TITLE.' renamed'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(Meeting::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'meeting.draft_deleted')->where('entity_id', (string) $draft->id)->exists())->toBeTrue();

    // Published: told to households, so cancelled and never deleted.
    $published = lifeMeeting();
    $this->actingAs($this->president)->post(lifeUrl($published, 'delete'), ['confirm_name' => LIFE_TITLE])->assertSessionHasErrors('confirm_name');
    FacilitiesFixture::boot();
    expect(Meeting::query()->whereKey($published->id)->exists())->toBeTrue();
});

it('moves a published meeting only outside the notice period counted from today, resets every reply, and audits from and to (M56 §2.2)', function () {
    $meeting = lifeMeeting();
    lifeApp($this, $this->token, 'meetings.rsvp', ['meeting' => $meeting->id], ['response' => 'attending']);
    $from = $meeting->starts_at->copy();

    // Inside an EGM's 14 days from today: refused, with the earliest day.
    $this->actingAs($this->president)->post(lifeUrl($meeting, 'date'), ['starts_at' => lifeLocal(now()->addDays(5))])
        ->assertSessionHasErrors('starts_at');
    expect((string) session('errors')->first('starts_at'))->toStartWith("Needs 14 days' notice. Earliest ");

    $this->actingAs($this->president)->post(lifeUrl($meeting, 'date'), ['starts_at' => lifeLocal(now()->addDays(20))])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $meeting->refresh();
    expect($meeting->stage())->toBe(Meeting::STAGE_SCHEDULED)
        ->and(abs($meeting->starts_at->diffInDays(now()->addDays(20))))->toBeLessThan(1)
        ->and($meeting->notice_given_at->diffInMinutes(now(), true))->toBeLessThan(5)
        ->and(DB::connection('tenant')->table('meeting_rsvps')->where('meeting_id', $meeting->id)->count())->toBe(0);

    $audit = DB::connection('mysql')->table('audit_log')->where('action', 'meeting.rescheduled')->where('entity_id', (string) $meeting->id)->sole();
    expect(json_decode((string) $audit->before, true)['starts_at'])->toBe(SiteTime::iso($from))
        ->and(json_decode((string) $audit->after, true)['replies_reset'])->toBe(1)
        ->and($audit->actor_id)->toBe($this->president->id);

    // The household's list shows the new date and no reply.
    $listed = collect(lifeApp($this, $this->token, 'meetings.index')->json('items'))->firstWhere('id', $meeting->id);
    expect($listed['my_rsvp'])->toBeNull()->and($listed['status'])->toBe('scheduled');
});

it('postpones with a reason the households read, refuses replies meanwhile, and a new date schedules it again with fresh notice (M56 §2.3)', function () {
    $meeting = lifeMeeting();

    $this->actingAs($this->president)->post(lifeUrl($meeting, 'postpone'), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->president)->post(lifeUrl($meeting, 'postpone'), ['reason' => 'The clubhouse is being repaired.'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect($meeting->refresh()->stage())->toBe(Meeting::STAGE_POSTPONED);

    $listed = collect(lifeApp($this, $this->token, 'meetings.index')->json('items'))->firstWhere('id', $meeting->id);
    // The app is told the date is to be set: `starts_at` is still the day it was first called for.
    expect([$listed['status'], $listed['reason'], $listed['date_to_be_set']])->toBe(['postponed', 'The clubhouse is being repaired.', true]);

    $refused = lifeApp($this, $this->token, 'meetings.rsvp', ['meeting' => $meeting->id], ['response' => 'attending'], 409);
    expect($refused->json('error.message') ?? $refused->json('message'))->toContain('postponed');

    // While postponed, only a new date (or cancelling it): its place is not edited.
    $this->actingAs($this->president)->post(lifeUrl($meeting, 'edit'), ['held_as' => Meeting::IN_PERSON, 'venue' => 'Elsewhere'])->assertSessionHasErrors('meeting');

    $this->actingAs($this->president)->post(lifeUrl($meeting, 'date'), ['starts_at' => lifeLocal(now()->addDays(25))])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $meeting->refresh();
    expect([$meeting->stage(), $meeting->postpone_reason, $meeting->postponed_at])->toBe([Meeting::STAGE_SCHEDULED, null, null]);
});

it('postpones to a new date in one act when the office has one: later than it was, outside the notice period, every reply reset, one message (M94 §3.3)', function () {
    $sent = [];
    $notifier = Mockery::mock(PushNotifier::class);
    $notifier->shouldReceive('toEstateResidents')->andReturnUsing(function (string $tenant, $message) use (&$sent): int {
        $sent[] = $message;

        return 0;
    });
    app()->instance(PushAnnouncer::class, new PushAnnouncer($notifier, app(PanicFanOut::class)));

    $meeting = lifeMeeting();
    lifeApp($this, $this->token, 'meetings.rsvp', ['meeting' => $meeting->id], ['response' => 'attending']);
    $from = $meeting->starts_at->copy();

    // Earlier than it was is not a postponement: refused, and the office is pointed at Change the date.
    $this->actingAs($this->president)->post(lifeUrl($meeting, 'postpone'), ['reason' => 'The hall is booked.', 'starts_at' => lifeLocal(now()->addDays(20))])
        ->assertSessionHasErrors('starts_at');
    expect((string) session('errors')->first('starts_at'))->toEndWith('To bring it forward, use Change the date.');

    // Inside the notice period counted from today: refused with the earliest day, as any new date is.
    $soon = lifeMeeting(['title' => LIFE_TITLE.' soon', 'starts_at' => now()->addDays(3)]);
    $this->actingAs($this->president)->post(lifeUrl($soon, 'postpone'), ['reason' => 'The hall is booked.', 'starts_at' => lifeLocal(now()->addDays(10))])
        ->assertSessionHasErrors('starts_at');
    expect((string) session('errors')->first('starts_at'))->toStartWith("Needs 14 days' notice. Earliest ");

    $sent = [];
    $this->actingAs($this->president)->post(lifeUrl($meeting, 'postpone'), ['reason' => 'The hall is booked.', 'starts_at' => lifeLocal(now()->addDays(60))])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $meeting->refresh();
    expect($meeting->stage())->toBe(Meeting::STAGE_SCHEDULED)
        ->and(abs($meeting->starts_at->diffInDays(now()->addDays(60))))->toBeLessThan(1)
        ->and($meeting->notice_given_at->diffInMinutes(now(), true))->toBeLessThan(5)
        ->and(DB::connection('tenant')->table('meeting_rsvps')->where('meeting_id', $meeting->id)->count())->toBe(0);

    // One act on the record, from and to, with why: not a postponement and then a move.
    $audit = DB::connection('mysql')->table('audit_log')->where('action', 'meeting.postponed')->where('entity_id', (string) $meeting->id)->sole();
    expect(json_decode((string) $audit->before, true)['starts_at'])->toBe(SiteTime::iso($from))
        ->and(json_decode((string) $audit->after, true))->toMatchArray(['reason' => 'The hall is booked.', 'starts_at' => SiteTime::iso($meeting->starts_at), 'replies_reset' => 1])
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'meeting.rescheduled')->where('entity_id', (string) $meeting->id)->exists())->toBeFalse();

    // One message to the households: the new date, why, and that they reply again.
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->kind)->toBe('postponed_to')
        ->and($sent[0]->body)->toContain(' is postponed to ')
        ->and($sent[0]->body)->toContain('The hall is booked')
        ->and($sent[0]->body)->toEndWith('Please reply again.');

    // The household's list shows it scheduled at its new date, waiting for a new reply.
    $listed = collect(lifeApp($this, $this->token, 'meetings.index')->json('items'))->firstWhere('id', $meeting->id);
    expect([$listed['status'], $listed['my_rsvp'], $listed['starts_at'], $listed['date_to_be_set']])->toBe(['scheduled', null, SiteTime::iso($meeting->starts_at), false]);
});

it('shows a postponed meeting with no new date as "New date to be set", never the day it was called for, and keeps it with the meetings to come (M56 §2.3)', function () {
    lifeMeeting(['title' => LIFE_TITLE.' dated', 'starts_at' => now()->addDays(30)]);
    $waiting = lifeMeeting(['title' => LIFE_TITLE.' waiting', 'starts_at' => now()->addDays(2)]);
    $this->actingAs($this->president)->post(lifeUrl($waiting, 'postpone'), ['reason' => 'The hall is booked.'])->assertSessionHasNoErrors();

    // Three days on, the day it was called for has gone by, and it still waits for its new one.
    $this->travel(3)->days();

    $this->actingAs($this->president)->get(FacilitiesFixture::url('/governance/meetings?q='.rawurlencode(LIFE_TITLE)))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.0.title', LIFE_TITLE.' dated')
            ->where('rows.1.title', LIFE_TITLE.' waiting')
            ->where('rows.1.date', 'New date to be set')
            ->where('rows.1.upcoming', true));

    $this->actingAs($this->president)->get(FacilitiesFixture::url('/governance/meetings/'.$waiting->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('meeting.when', fn (string $when): bool => str_starts_with($when, 'New date to be set. It was ')));
});

it('cancels with a reason and the name typed, keeps it on the record marked, and never deletes, reopens or moves it (M56 §1.2)', function () {
    $meeting = lifeMeeting();

    $this->actingAs($this->president)->post(lifeUrl($meeting, 'cancel'), ['reason' => 'Called off by the committee.', 'confirm_name' => 'wrong'])->assertSessionHasErrors('confirm_name');
    $this->actingAs($this->president)->post(lifeUrl($meeting, 'cancel'), ['reason' => 'Called off by the committee.', 'confirm_name' => strtolower(LIFE_TITLE)])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $meeting->refresh();
    expect([$meeting->stage(), $meeting->cancel_reason, $meeting->cancelled_by])->toBe([Meeting::STAGE_CANCELLED, 'Called off by the committee.', $this->president->id]);

    $listed = collect(lifeApp($this, $this->token, 'meetings.index')->json('items'))->firstWhere('id', $meeting->id);
    expect([$listed['status'], $listed['reason']])->toBe(['cancelled', 'Called off by the committee.']);

    $this->actingAs($this->president)->post(lifeUrl($meeting, 'delete'), ['confirm_name' => LIFE_TITLE])->assertSessionHasErrors('confirm_name');
    $this->actingAs($this->president)->post(lifeUrl($meeting, 'date'), ['starts_at' => lifeLocal(now()->addDays(30))])->assertSessionHasErrors('starts_at');

    // On the register, marked, with a badge that does not pretend a quorum failed.
    $this->actingAs($this->president)->get(FacilitiesFixture::url('/governance/meetings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', fn ($rows): bool => ($row = collect($rows)->firstWhere('id', $meeting->id)) !== null
            && $row['stage_label'] === 'Cancelled' && $row['quorum_label'] === 'Not held'));
});

it('edits a published meeting\'s place, description and agenda, never its type or audience (M56 §2)', function () {
    $meeting = lifeMeeting();

    $this->actingAs($this->president)->post(lifeUrl($meeting, 'edit'), [
        'type' => Meeting::PHASE, 'audience_scope' => Meeting::PHASE_SUBSET, 'held_as' => Meeting::IN_PERSON, 'venue' => 'Phase 2 pavilion',
        'description' => 'The annual accounts.', 'agenda' => [['text' => 'Accounts', 'start_time' => null]],
    ])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $meeting->refresh()->load('agenda');
    expect([$meeting->type, $meeting->audience_scope, $meeting->venue, $meeting->description, $meeting->agenda->pluck('text')->all()])
        ->toBe([Meeting::EGM, Meeting::WHOLE_ESTATE, 'Phase 2 pavilion', 'The annual accounts.', ['Accounts']]);
});

it('keeps the register once the meeting has started and counts the quorum from it, and not before (M56 §2)', function () {
    $ahead = lifeMeeting();
    $this->actingAs($this->president)->post(lifeUrl($ahead, 'attendance'), ['lot' => 'Lot 9479', 'state' => 'present'])->assertSessionHasErrors('attendance');

    $on = lifeMeeting(['title' => LIFE_TITLE.' on', 'starts_at' => now()->subMinutes(30)]);
    FacilitiesFixture::boot();
    expect($on->stage())->toBe(Meeting::STAGE_IN_PROGRESS);

    $this->actingAs($this->president)->post(lifeUrl($on, 'attendance'), ['lot' => 'Lot 9479', 'state' => 'present'])->assertSessionHasNoErrors();
    $this->actingAs($this->president)->post(lifeUrl($on, 'attendance'), ['lot' => 'No such lot', 'state' => 'present'])->assertSessionHasErrors('attendance');

    $this->actingAs($this->president)->get(FacilitiesFixture::url('/governance/meetings/'.$on->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('meeting.stage', Meeting::STAGE_IN_PROGRESS)
            ->where('meeting.quorum.present', 1)
            ->where('meeting.acts', ['attendance', 'minutes'])
            ->where('meeting.register.0.state', 'Present'));
});

it('archives a meeting only once its minutes are published', function () {
    $held = lifeMeeting(['starts_at' => now()->subDays(3)]);
    $this->actingAs($this->president)->post(lifeUrl($held, 'archive'))->assertSessionHasErrors('meeting');

    FacilitiesFixture::boot();
    MeetingMinutes::query()->create(['meeting_id' => $held->id, 'body' => 'Minutes of the lifecycle test meeting, recorded in full.', 'published_at' => now()]);

    $this->actingAs($this->president)->post(lifeUrl($held, 'archive'))->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect($held->refresh()->stage())->toBe(Meeting::STAGE_ARCHIVED);
});

it('says on a draft\'s row that it is inside its notice period, disables Publish, and moves it to the earliest day in one press (M56 §2.2)', function () {
    $draft = lifeMeeting(['type' => Meeting::PHASE, 'audience_scope' => Meeting::PHASE_SUBSET, 'phase' => 'Phase 2', 'status' => Meeting::DRAFT, 'published_at' => null, 'notice_given_at' => null, 'starts_at' => now()->addDays(2)]);

    $this->actingAs($this->president)->get(FacilitiesFixture::url('/governance/meetings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', function ($rows) use ($draft): bool {
            $row = collect($rows)->firstWhere('id', $draft->id);

            return $row !== null && str_starts_with((string) $row['publish_refusal'], "Needs 7 days' notice. Earliest ") && str_starts_with((string) $row['move_label'], 'Move to ');
        }));

    $this->actingAs($this->president)->post(lifeUrl($draft, 'publish'))->assertSessionHasErrors('starts_at');
    $this->actingAs($this->president)->post(lifeUrl($draft, 'move-to-earliest'))->assertSessionHasNoErrors();
    $this->actingAs($this->president)->post(lifeUrl($draft, 'publish'))->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect($draft->refresh()->stage())->toBe(Meeting::STAGE_SCHEDULED)
        ->and($draft->notice_given_at)->not->toBeNull();
});

it('gives every row of the register the acts its stage allows, for its menu, the same as the meeting\'s own page (M56 §4)', function () {
    $scheduled = lifeMeeting();
    $draft = lifeMeeting(['title' => LIFE_TITLE.' draft', 'status' => Meeting::DRAFT, 'published_at' => null, 'notice_given_at' => null]);

    $this->actingAs($this->president)->get(FacilitiesFixture::url('/governance/meetings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canEdit', true)
            ->where('rows', function ($rows) use ($scheduled, $draft): bool {
                $acts = collect($rows)->keyBy('id')->map(fn (array $row): array => $row['acts']);

                // A draft forty days out is outside its notice period, so "Move to" is not offered.
                return $acts[$scheduled->id] === ['edit_place', 'reschedule', 'postpone', 'cancel']
                    && $acts[$draft->id] === ['edit', 'publish', 'delete'];
            }));

    // The page the menu opens draws the same acts.
    $this->actingAs($this->president)->get(FacilitiesFixture::url('/governance/meetings/'.$scheduled->id.'?act=postpone'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('meeting.acts', ['edit_place', 'reschedule', 'postpone', 'cancel']));
});

it('lets each act through only with its permission: the Treasurer reads, the Secretary edits but does not tell households', function () {
    $meeting = lifeMeeting();

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))->post(lifeUrl($meeting, 'edit'), ['held_as' => Meeting::IN_PERSON, 'venue' => 'X'])->assertForbidden();

    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $this->actingAs($secretary)->post(lifeUrl($meeting, 'postpone'), ['reason' => 'Because.'])->assertForbidden();
    $this->actingAs($secretary)->post(lifeUrl($meeting, 'edit'), ['held_as' => Meeting::IN_PERSON, 'venue' => 'Secretary venue'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect([$meeting->refresh()->venue, $meeting->stage()])->toBe(['Secretary venue', Meeting::STAGE_SCHEDULED]);
});
