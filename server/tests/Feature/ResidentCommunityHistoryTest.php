<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Document;
use App\Models\Estate\Household;
use App\Models\Estate\Meeting;
use App\Models\Estate\Notice;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\Notices;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M4 §3.5 — what a notice says about itself, and the long lists
|--------------------------------------------------------------------------
|
|   NOTICES   "seen by 212 of 450 households": the console's seen bar, counted
|             in households, over the audience as it stands today. And the files
|             the office put with a notice: listed by name, type and size, and
|             fetched through the resident's own token — for a notice published
|             to that household and for no other.
|   HISTORY   `tickets.index` and `meetings.index` hand the whole history over a
|             page at a time (`before` / `next_before`), with nothing skipped and
|             nothing served twice — even across rows stamped in the same second.
|
| THE ESTATE DATABASE IS COMMITTED, so this file stands on lots and a phase of its
| own (Phase 8 · Lots 986–987, and Phase 7 · Lot 988 as "somebody else"), counts
| only what it can know, and takes away what it puts in.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $home = static function (string $lot, string $phase, array $people): array {
        $unit = FacilitiesFixture::scratchUnit($lot, $phase);
        $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => $lot.' household', 'access_restricted' => false]);

        $residents = [];

        foreach ($people as $i => $name) {
            $resident = Resident::withRemoved()->firstOrCreate(
                ['household_id' => $household->id, 'full_name' => $name],
                ['relationship' => $i === 0 ? 'owner' : 'spouse', 'is_primary' => $i === 0, 'status' => Resident::VERIFIED],
            );
            $resident->forceFill(['removed_at' => null, 'removed_by_resident_id' => null])->save();
            $residents[] = $resident;
        }

        return [$unit, $household, $residents];
    };

    // Lots 986–988, which no other file asks for. (982 was shared with OneTimeRuleTest and
    // PushNotificationsTest, which create it in Phase 9 — so whichever ran first decided the phase,
    // and a Phase 8 notice was served to nobody. `scratchUnit` now refuses that outright.)
    [$this->unit, $this->household, [$this->andrea, $this->marlon]] = $home('Lot 986', 'Phase 8', ['History Andrea', 'History Marlon']);
    [, , [$this->neighbour]] = $home('Lot 987', 'Phase 8', ['History Neighbour']);
    [$this->otherUnit, , [$this->stranger]] = $home('Lot 988', 'Phase 7', ['History Stranger']);

    $token = function (Resident $resident, int $unitId): string {
        $account = ResidentAccount::query()->create([
            'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'history.'.bin2hex(random_bytes(4)).'@example.com',
            'status' => ResidentAccount::ACTIVE, 'full_name' => $resident->full_name, 'unit_id' => $unitId, 'resident_id' => $resident->id,
        ]);

        return $account->createToken('resident-app:history', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    };

    $this->token = $token($this->andrea, $this->unit->id);
    $this->neighbourToken = $token($this->neighbour, $this->neighbour->household->unit_id);

    $this->made = ['notices' => [], 'documents' => [], 'meetings' => [], 'tickets' => []];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    // What this file put into the committed estate database comes out again — files first, then rows.
    $documents = Document::query()->whereIn('id', $this->made['documents'])->get();

    FacilitiesFixture::platform()->run(static function () use ($documents): void {
        foreach ($documents as $document) {
            if ($document->path !== null) {
                Storage::disk('local')->delete((string) $document->path);
            }
        }
    });
    FacilitiesFixture::boot();

    // The document ROWS stay: a kept document cannot be deleted before its seven years are up (the table's own
    // trigger refuses), which is the documents path doing its job. This database is rebuilt every process.
    DB::connection('tenant')->table('notice_reads')->whereIn('notice_id', $this->made['notices'])->delete();
    Notice::query()->whereIn('id', $this->made['notices'])->delete();
    DB::connection('tenant')->table('meeting_rsvps')->whereIn('meeting_id', $this->made['meetings'])->delete();
    Meeting::query()->whereIn('id', $this->made['meetings'])->delete();
    // Photos first: a media row points at its ticket, and the file it named goes with it.
    DB::connection('tenant')->table('ticket_media')->whereIn('maintenance_ticket_id', $this->made['tickets'])->delete();
    DB::connection('tenant')->table('maintenance_tickets')->whereIn('id', $this->made['tickets'])->delete();
    Resident::withRemoved()->whereKey($this->neighbour->id)->update(['removed_at' => null]);
});

/**
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function communityCall(TestCase $test, ?string $token, string $name, array $params = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'hist-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();

    // `withToken` sticks to the test case, so "no token" has to take the last one off again.
    $client = $token === null ? $test->withoutToken() : $test->withToken($token);

    $response = $entry['method'] === 'GET'
        ? $client->get($uri.($body === [] ? '' : '?'.http_build_query($body)), ['Accept' => 'application/json', ...$headers])
        : $client->json($entry['method'], $uri, $body, $headers);

    $response->assertStatus($status ?? $entry['status']);

    // The file itself is not JSON; everything else is held to its catalogue entry.
    if (str_contains((string) $response->headers->get('Content-Type'), 'json')) {
        ApiContract::assertMatches($response, $name);
    }

    FacilitiesFixture::boot();

    return $response;
}

/** @param  array<string, mixed>  $with */
function historyNotice(object $test, array $with = []): Notice
{
    $notice = new Notice;
    $notice->forceFill([
        'kind' => Notice::GENERAL, 'title' => 'History notice '.bin2hex(random_bytes(3)), 'body' => 'Gate 2 is closed while the barrier arm is repaired.',
        'audience_scope' => Notice::ONE_PHASE, 'audience_phase' => 'Phase 8', 'author_name' => 'Property Manager', 'published_at' => now()->subMinutes(5),
        ...$with,
    ])->save();

    $test->made['notices'][] = $notice->id;

    return $notice;
}

function historyAttachment(object $test, Notice $notice, string $filename, string $bytes, string $type = 'application/pdf'): Document
{
    $by = User::factory()->create();

    $document = FacilitiesFixture::platform()->run(static fn (): Document => app(Notices::class)->attach($notice, $filename, $bytes, $type, $by));
    FacilitiesFixture::boot();

    $test->made['documents'][] = $document->id;

    return $document;
}

it('says how many HOUSEHOLDS have seen a notice, over the audience as it stands today — the console\'s count in the phone\'s unit', function () {
    $notice = historyNotice($this);
    $mine = fn (TestResponse $r): array => collect($r->json('items'))->firstWhere('id', $notice->id);

    // Phase 8 is this file's own: two households live there.
    $before = $mine(communityCall($this, $this->token, 'notices.index'));
    expect($before['seen_households'])->toBe(0)->and($before['audience_households'])->toBe(2);

    // Andrea opens it: one household — and the answer to the read already says so.
    $read = communityCall($this, $this->token, 'notices.read', ['notice' => $notice->id]);
    expect($read->json('seen_households'))->toBe(1)->and($read->json('audience_households'))->toBe(2);

    // Marlon opens it too: two PEOPLE, still one household.
    app(Notices::class)->markRead($notice, $this->marlon);
    expect($mine(communityCall($this, $this->token, 'notices.index'))['seen_households'])->toBe(1);

    // The neighbour's household makes two of two.
    communityCall($this, $this->neighbourToken, 'notices.read', ['notice' => $notice->id]);
    $after = $mine(communityCall($this, $this->token, 'notices.index'));
    expect($after['seen_households'])->toBe(2)->and($after['audience_households'])->toBe(2);

    // The console's bar is the same rows counted in people — and the change above did not move it.
    $row = collect(app(Notices::class)->board()['rows'])->firstWhere('id', $notice->id);
    expect($row['seen_count'])->toBe(3)->and($row['audience_size'])->toBe(3)->and($row['seen_pct'])->toBe(100);

    // A household whose only member has been removed is no longer in the audience, nor among those who saw it.
    Resident::withRemoved()->whereKey($this->neighbour->id)->update(['removed_at' => now()]);
    $shrunk = $mine(communityCall($this, $this->token, 'notices.index'));
    expect($shrunk['seen_households'])->toBe(1)->and($shrunk['audience_households'])->toBe(1);

    // …and the console's bar loses that person from BOTH halves — never a receipt kept against an audience
    // it has left, which is how a fully-read phase used to draw a bar past 100%.
    $smaller = collect(app(Notices::class)->board()['rows'])->firstWhere('id', $notice->id);
    expect($smaller['seen_count'])->toBe(2)->and($smaller['audience_size'])->toBe(2)->and($smaller['seen_pct'])->toBe(100);

    Resident::withRemoved()->whereKey($this->neighbour->id)->update(['removed_at' => null]);

    // An estate-wide notice is for every household with somebody on the register — resolved now, not stored.
    $everybody = historyNotice($this, ['audience_scope' => Notice::ESTATE_WIDE, 'audience_phase' => null]);
    $households = DB::connection('tenant')->table('residents')->whereNull('removed_at')->distinct()->count('household_id');
    $wide = collect(communityCall($this, $this->token, 'notices.index')->json('items'))->firstWhere('id', $everybody->id);
    expect($wide['audience_households'])->toBe($households)->and($wide['seen_households'])->toBe(0);
});

it('never draws board 32 past 100%: a receipt left behind by a household that has moved phase is not in the notice\'s audience', function () {
    $notice = historyNotice($this); // Phase 8 only — this file's own phase.
    $board = fn (int $id): array => collect(app(Notices::class)->board()['rows'])->firstWhere('id', $id);

    // Everybody the notice is addressed to opens it: three people, three of three.
    foreach ([$this->andrea, $this->marlon, $this->neighbour] as $resident) {
        app(Notices::class)->markRead($notice, $resident);
    }

    $full = $board($notice->id);
    expect($full['seen_count'])->toBe(3)->and($full['audience_size'])->toBe(3)->and($full['seen_pct'])->toBe(100);

    /*
     * A HOUSEHOLD MOVES HOUSE. A phase is a fact about an address (`residentsByPhase`
     * joins through the unit), so a Phase 8 household that takes a unit in Phase 7
     * leaves its receipt behind on every Phase 8 notice it had opened: the row stays,
     * the person is no longer in that notice's audience. That is the state this read
     * puts the estate in.
     *
     * The numerator used to be a plain count of receipts and the denominator only the
     * phase, so the bar read 133% — and board 32 draws its fill as `width: {pct}%`,
     * which runs past its own track. Both halves are now taken by `audienceOf`.
     */
    app(Notices::class)->markRead($notice, $this->stranger); // Lot 988, Phase 7

    $moved = $board($notice->id);
    expect($moved['seen_count'])->toBe(3)->and($moved['audience_size'])->toBe(3)->and($moved['seen_pct'])->toBe(100);

    // The Resident App's line was already counted this way, and the two screens now agree on WHO was counted.
    $mine = collect(communityCall($this, $this->token, 'notices.index')->json('items'))->firstWhere('id', $notice->id);
    expect($mine['seen_households'])->toBe(2)->and($mine['audience_households'])->toBe(2);

    // An ESTATE-WIDE notice is addressed to every phase, so every phase's receipts count towards it.
    $everybody = historyNotice($this, ['audience_scope' => Notice::ESTATE_WIDE, 'audience_phase' => null]);
    app(Notices::class)->markRead($everybody, $this->andrea);
    app(Notices::class)->markRead($everybody, $this->stranger);
    expect($board($everybody->id)['seen_count'])->toBe(2);

    // A notice nobody has opened is 0, not a division by zero, and a phase with nobody in it is 0 too.
    expect($board(historyNotice($this)->id)['seen_count'])->toBe(0)
        ->and($board(historyNotice($this, ['audience_phase' => 'Phase 8 has no such name'])->id)['seen_pct'])->toBe(0);
});

it('lists a notice\'s attachments and hands the file to the household it was published to — and to nobody else', function () {
    $bytes = "%PDF-1.4\n% history test ".bin2hex(random_bytes(16))."\n%%EOF\n";
    $notice = historyNotice($this);
    $file = historyAttachment($this, $notice, '..\\uploads/Gate2 Contractor "Notice".pdf', $bytes);

    // Listed: a name made safe, a type, a size and an id — and nowhere it is kept.
    $index = communityCall($this, $this->token, 'notices.index');
    $listed = collect($index->json('items'))->firstWhere('id', $notice->id)['attachments'];

    expect($listed)->toHaveCount(1)
        ->and($listed[0])->toBe(['id' => $file->id, 'name' => 'Gate2 Contractor _Notice_.pdf', 'content_type' => 'application/pdf', 'bytes' => strlen($bytes)])
        ->and((string) $index->getContent())->not->toContain('uploads')->not->toContain('estates/')->not->toContain((string) $file->sha256);

    // A notice with nothing put with it says so with an empty list, not a missing key.
    $bare = historyNotice($this);
    expect(collect(communityCall($this, $this->token, 'notices.index')->json('items'))->firstWhere('id', $bare->id)['attachments'])->toBe([]);

    // Fetched with the resident's own token: the very bytes, under the office's name, never cached by anything between.
    $got = communityCall($this, $this->token, 'notices.attachment', ['notice' => $notice->id, 'attachment' => $file->id]);
    expect($got->getContent())->toBe($bytes)
        ->and($got->headers->get('Content-Type'))->toStartWith('application/pdf')
        ->and($got->headers->get('Content-Disposition'))->toContain('Gate2 Contractor _Notice_.pdf')
        ->and($got->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    // No token, no file.
    communityCall($this, null, 'notices.attachment', ['notice' => $notice->id, 'attachment' => $file->id], [], 401);

    /*
     * EVERY "NOT YOURS" IS THE SAME 404 AS "NO SUCH THING": another phase's notice,
     * a draft, a notice dated ahead, an attachment that belongs to a different
     * notice, and ids nobody has. The body is compared, not only the status.
     */
    $unknown = communityCall($this, $this->token, 'notices.attachment', ['notice' => 987_654_321, 'attachment' => 987_654_321], [], 404)->json();

    $theirs = historyNotice($this, ['audience_phase' => 'Phase 7']);
    $theirFile = historyAttachment($this, $theirs, 'phase-7.pdf', $bytes.'7');
    $draft = historyNotice($this, ['published_at' => null]);
    $draftFile = historyAttachment($this, $draft, 'draft.pdf', $bytes.'d');
    $ahead = historyNotice($this, ['published_at' => now()->addDay()]);
    $aheadFile = historyAttachment($this, $ahead, 'ahead.pdf', $bytes.'a');

    foreach ([
        'another phase\'s notice' => [$theirs->id, $theirFile->id],
        'an unpublished notice' => [$draft->id, $draftFile->id],
        'a notice dated ahead' => [$ahead->id, $aheadFile->id],
        'another notice\'s attachment under this notice' => [$notice->id, $theirFile->id],
        'this notice, an attachment nobody has' => [$notice->id, 987_654_321],
    ] as $what => [$noticeId, $attachmentId]) {
        expect(communityCall($this, $this->token, 'notices.attachment', ['notice' => $noticeId, 'attachment' => $attachmentId], [], 404)->json())->toBe($unknown, $what);
    }

    // …and none of those notices is in the list either, so their attachments are never even named.
    $ids = collect(communityCall($this, $this->token, 'notices.index')->json('items'))->pluck('id');
    expect($ids)->toContain($notice->id)->not->toContain($theirs->id)->not->toContain($draft->id)->not->toContain($ahead->id);

    // The household it WAS published to gets it.
    $strangerAccount = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'history.'.bin2hex(random_bytes(4)).'@example.com',
        'status' => ResidentAccount::ACTIVE, 'full_name' => $this->stranger->full_name, 'unit_id' => $this->otherUnit->id, 'resident_id' => $this->stranger->id,
    ]);
    $strangerToken = $strangerAccount->createToken('resident-app:history', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    expect(communityCall($this, $strangerToken, 'notices.attachment', ['notice' => $theirs->id, 'attachment' => $theirFile->id])->getContent())->toBe($bytes.'7');
    communityCall($this, $strangerToken, 'notices.attachment', ['notice' => $notice->id, 'attachment' => $file->id], [], 404);

    // A kind of file a notice does not carry is refused where it is put, in words.
    expect(fn () => historyAttachment($this, $notice, 'run-me.exe', 'MZ', 'application/x-msdownload'))->toThrow(DomainException::class);

    /*
     * AN OLDER NOTICE IS STILL THIS HOUSEHOLD'S. The list stops at a hundred; a busy
     * estate pushes an older notice off the end of it — and a phone that saved the list
     * before that still holds its id. Its file is asked for BY ID under the list's own
     * rule, never looked for inside the list, so it is handed over exactly as before,
     * and the read still lands. (Looked for inside the list, both answered 404 here.)
     */
    for ($i = 0; $i < 100; $i++) {
        historyNotice($this, ['title' => 'History filler '.$i, 'published_at' => now()->subMinute()]);
    }

    expect(collect(communityCall($this, $this->token, 'notices.index')->json('items'))->pluck('id'))->not->toContain($notice->id);
    expect(communityCall($this, $this->token, 'notices.attachment', ['notice' => $notice->id, 'attachment' => $file->id])->getContent())->toBe($bytes);
    communityCall($this, $this->token, 'notices.read', ['notice' => $notice->id]);

    // …and being off the end of the list is still not a way in: the refusals answer as they did.
    communityCall($this, $strangerToken, 'notices.attachment', ['notice' => $notice->id, 'attachment' => $file->id], [], 404);
    communityCall($this, $this->token, 'notices.attachment', ['notice' => $draft->id, 'attachment' => $draftFile->id], [], 404);
});

it('puts the office\'s file with the notice FROM THE CONSOLE, names it back on board 32, and hands that same file to the household', function () {
    /*
     * THE WHOLE PATH, END TO END. Everything above proves the phone's half against a
     * document made in the test; this proves the half the office actually uses. Without
     * the composer's file field, `Notices::attach` is reachable only from a test — the
     * API would list an attachment no estate could ever produce.
     */
    $title = 'History console notice '.bin2hex(random_bytes(3));
    $bytes = "%PDF-1.4\n% console composer ".bin2hex(random_bytes(16))."\n%%EOF\n";
    $upload = UploadedFile::fake()->createWithContent('Gate2 Contractor Notice.pdf', $bytes);

    app('auth')->forgetGuards();

    $this->actingAs(FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN))
        ->post(FacilitiesFixture::url('/governance/notices'), [
            'kind' => Notice::GENERAL,
            'title' => $title,
            'body' => 'Gate 2 is closed while the barrier arm is repaired. The contractor\'s letter is attached.',
            'audience_scope' => 'phase',
            'audience_phase' => 'Phase 8',
            'attachment' => $upload,
        ])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $notice = Notice::query()->where('title', $title)->firstOrFail();
    $this->made['notices'][] = $notice->id;

    $document = collect(app(Notices::class)->attachmentsFor([(int) $notice->id])[(int) $notice->id] ?? [])->first();
    expect($document)->not->toBeNull();
    $this->made['documents'][] = $document->id;

    // Board 32 says what went out with it — a name and an id for the documents route the console already
    // uses, never a path. Without this the office presses Post and cannot tell whether the letter went.
    expect(collect(app(Notices::class)->board()['rows'])->firstWhere('id', $notice->id)['attachments'])
        ->toBe([['id' => (int) $document->id, 'name' => 'Gate2 Contractor Notice.pdf']]);

    // …and it is THAT file, byte for byte, that the household's phone is handed.
    $listed = collect(communityCall($this, $this->token, 'notices.index')->json('items'))->firstWhere('id', $notice->id);
    expect($listed['attachments'])->toBe([[
        'id' => (int) $document->id, 'name' => 'Gate2 Contractor Notice.pdf', 'content_type' => $document->contentType(), 'bytes' => strlen($bytes),
    ]]);
    expect(communityCall($this, $this->token, 'notices.attachment', ['notice' => $notice->id, 'attachment' => $document->id])->getContent())->toBe($bytes);

    // A notice posted with no file is still a notice, and its row carries an empty list rather than nothing.
    $bare = 'History console notice '.bin2hex(random_bytes(3));

    app('auth')->forgetGuards();
    $this->actingAs(FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN))
        ->post(FacilitiesFixture::url('/governance/notices'), [
            'kind' => Notice::GENERAL, 'title' => $bare, 'body' => 'Nothing is attached to this one.',
            'audience_scope' => 'estate', 'audience_phase' => null,
        ])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $second = Notice::query()->where('title', $bare)->firstOrFail();
    $this->made['notices'][] = $second->id;
    expect(collect(app(Notices::class)->board()['rows'])->firstWhere('id', $second->id)['attachments'])->toBe([]);
});

it('hands over every notice published to the household, a page at a time — so the hundred-and-first is reachable at all', function () {
    /*
     * The other two lists page; this one stopped at a hundred with nothing to say so. An older notice
     * was still fetchable BY ID (its file and its read are asserted above with a hundred newer ones
     * in front of it) — which no screen could ever reach, so on a busy estate notice 101 was simply
     * gone. Same cursor, same tie-break, same refusal as `tickets.index` and `meetings.index`.
     */
    $base = Carbon::parse('2026-09-19 12:00:00');
    $rows = [];

    // 103 of this file's own, the NEWEST on the estate (so whatever else is published sorts below them).
    // Three share one second and sit right across the end of the first page.
    for ($i = 0; $i < 103; $i++) {
        $at = $base->copy()->subSeconds(in_array($i, [99, 100, 101], true) ? 99 : $i);
        $rows[] = [
            'kind' => Notice::GENERAL, 'title' => 'History notice page '.$i, 'body' => 'Gate 2 is closed while the barrier arm is repaired.',
            'audience_scope' => Notice::ONE_PHASE, 'audience_phase' => 'Phase 8', 'author_name' => 'Property Manager',
            'published_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ];
    }

    DB::connection('tenant')->table('notices')->insert($rows);
    $mine = DB::connection('tenant')->table('notices')->where('title', 'LIKE', 'History notice page %')->pluck('id')->all();
    $this->made['notices'] = array_merge($this->made['notices'], $mine);

    expect($mine)->toHaveCount(103);

    $served = collect();
    $before = null;
    $pages = 0;

    do {
        $page = communityCall($this, $this->token, 'notices.index', [], $before === null ? [] : ['before' => $before]);
        expect(count($page->json('items')))->toBeLessThanOrEqual(100);

        $served = $served->concat($page->json('items'));
        $before = $page->json('next_before');
        $pages++;
    } while ($before !== null && $pages < 20);

    expect($pages)->toBeGreaterThanOrEqual(2)
        ->and($served->pluck('id')->duplicates())->toBeEmpty()
        ->and($served->pluck('id')->intersect($mine)->count())->toBe(103)
        // Newest first throughout: no page begins before the last one ended.
        ->and($served->pluck('published_at')->map(fn (string $at) => Carbon::parse($at)->getTimestamp())->all())
        ->toBe($served->pluck('published_at')->map(fn (string $at) => Carbon::parse($at)->getTimestamp())->sortDesc()->values()->all());

    // The first page is the hundred it always was, and it still carries everything else it carried.
    $first = communityCall($this, $this->token, 'notices.index');
    expect($first->json('items'))->toHaveCount(100)
        ->and($first->json('next_before'))->toBeString()->not->toBeEmpty()
        ->and($first->json('items.0'))->toHaveKeys(['seen_households', 'audience_households', 'attachments', 'read_at'])
        // `unread` counts THIS answer, as it always did — never the whole history.
        ->and($first->json('unread'))->toBeLessThanOrEqual(100);

    // A cursor this list never handed out is refused in words, exactly as the other two refuse one.
    communityCall($this, $this->token, 'notices.index', [], ['before' => 'not-a-cursor'], 422)->assertJsonPath('error.code', 'cursor_invalid');

    // A cursor does not widen what a household may see: the RULE is still what a notice is asked
    // against, and `before` only says how far back. Phase 7 pages its own history and reaches none of these.
    $strangerAccount = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'history.'.bin2hex(random_bytes(4)).'@example.com',
        'status' => ResidentAccount::ACTIVE, 'full_name' => $this->stranger->full_name, 'unit_id' => $this->otherUnit->id, 'resident_id' => $this->stranger->id,
    ]);
    $strangerToken = $strangerAccount->createToken('resident-app:history', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    // Phase 7 walks its OWN history and never sees a Phase 8 notice, however far back it pages.
    $strangerSeen = collect();
    $strangerBefore = null;
    $strangerPages = 0;

    do {
        $page = communityCall($this, $strangerToken, 'notices.index', [], $strangerBefore === null ? [] : ['before' => $strangerBefore]);
        $strangerSeen = $strangerSeen->concat($page->json('items'));
        $strangerBefore = $page->json('next_before');
        $strangerPages++;
    } while ($strangerBefore !== null && $strangerPages < 20);

    expect($strangerSeen->pluck('id')->intersect($mine))->toBeEmpty();
});

it('refuses a notice attachment larger than a notice may carry, where the other two rules already live', function () {
    /*
     * The size was the composer's validator alone — one level above the type and the emptiness rules,
     * so `attach()` would have kept a file of any size for any caller that did not happen to have that
     * validator in front of it. The controller still validates as well; a rule enforced twice refuses
     * earlier and in the field.
     */
    $notice = historyNotice($this);
    $tooBig = str_repeat('a', Notices::ATTACHMENT_MAX_KB * 1024 + 1);

    expect(fn () => historyAttachment($this, $notice, 'enormous.pdf', $tooBig))
        ->toThrow(DomainException::class, 'larger than 10 MB');

    // Nothing was kept, and the notice still lists nothing.
    FacilitiesFixture::boot();
    expect(app(Notices::class)->attachmentsFor([(int) $notice->id]))->toBe([]);

    // One byte under the limit is a file the office may put with a notice.
    $justUnder = historyAttachment($this, $notice, 'large-but-allowed.pdf', str_repeat('b', Notices::ATTACHMENT_MAX_KB * 1024));
    expect($justUnder->bytes)->toBe(Notices::ATTACHMENT_MAX_KB * 1024);
});

it('counts a ticket\'s photos for the whole page at once, and counts them against the right ticket', function () {
    /*
     * `media_count` was a COUNT per row: a hundred round trips an answer, and "All requests" walked
     * back five pages was five hundred. Asked once for the page, the risk changes shape — a grouped
     * query can key its answers to the wrong row — so this asserts WHOSE photos they are, not just
     * that a number came back.
     */
    $with = communityCall($this, $this->token, 'tickets.store', [], ['title' => 'Photographed gate light', 'category' => 'electrical', 'description' => 'Flickers at night.'], 201);
    $without = communityCall($this, $this->token, 'tickets.store', [], ['title' => 'Unphotographed gate light', 'category' => 'electrical', 'description' => 'Flickers at night.'], 201);

    $this->made['tickets'][] = (int) $with->json('id');
    $this->made['tickets'][] = (int) $without->json('id');

    // A report just filed has no photos on it yet — that is what the answer says, without assuming it.
    expect($with->json('media_count'))->toBe(0)->and($without->json('media_count'))->toBe(0);

    foreach (['one.jpg', 'two.jpg'] as $name) {
        $this->withToken($this->token)->post('/api/v1/tickets/'.$with->json('id').'/media', [
            'file' => UploadedFile::fake()->image($name, 8, 8),
        ], ['Accept' => 'application/json', 'Idempotency-Key' => 'hist-media-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String()])->assertCreated();

        FacilitiesFixture::boot();
    }

    $listed = collect(communityCall($this, $this->token, 'tickets.index')->json('items'));

    expect($listed->firstWhere('id', $with->json('id'))['media_count'])->toBe(2)
        ->and($listed->firstWhere('id', $without->json('id'))['media_count'])->toBe(0)
        // Every other ticket of this household's is still counted, and none inherited a neighbour's photos.
        ->and($listed->pluck('media_count')->filter(static fn ($n): bool => $n > 0)->count())->toBe(1);
});

it('hands over every request the household ever made, a page at a time, with nothing skipped or served twice', function () {
    $base = Carbon::parse('2021-03-01 12:00:00');
    $rows = [];

    // 103 old requests. Three of them share one second, and sit right across the end of the first page.
    for ($i = 0; $i < 103; $i++) {
        $rows[] = [
            'number' => 9_100_000 + $i, 'title' => 'History request '.$i, 'location_label' => 'Lot 986', 'unit_id' => $this->unit->id,
            'priority' => 'low', 'status' => 'verified', 'sla_hours' => 72,
            'reported_at' => $base->copy()->subMinutes(in_array($i, [99, 100, 101], true) ? 99 : $i),
            'created_at' => $base, 'updated_at' => $base,
        ];
    }

    DB::connection('tenant')->table('maintenance_tickets')->insert($rows);
    $this->made['tickets'] = DB::connection('tenant')->table('maintenance_tickets')->whereBetween('number', [9_100_000, 9_100_102])->pluck('id')->all();

    $first = communityCall($this, $this->token, 'tickets.index');
    expect($first->json('items'))->toHaveCount(100)->and($first->json('next_before'))->toBeString()->not->toBeEmpty();

    $second = communityCall($this, $this->token, 'tickets.index', [], ['before' => $first->json('next_before')]);
    expect($second->json('items'))->toHaveCount(3)->and($second->json('next_before'))->toBeNull();

    $served = collect($first->json('items'))->concat($second->json('items'));
    expect($served->pluck('id')->unique()->count())->toBe(103)
        ->and($served->pluck('id')->sort()->values()->all())->toBe(collect($this->made['tickets'])->sort()->values()->all())
        // Newest first throughout: no page begins before the last one ended.
        ->and($served->pluck('reported_at')->map(fn (string $at) => Carbon::parse($at)->getTimestamp())->all())
        ->toBe($served->pluck('reported_at')->map(fn (string $at) => Carbon::parse($at)->getTimestamp())->sortDesc()->values()->all());

    // A cursor this list never handed out is refused in words, not guessed at — and somebody else's list stays theirs.
    communityCall($this, $this->token, 'tickets.index', [], ['before' => 'not-a-cursor'], 422)->assertJsonPath('error.code', 'cursor_invalid');
    expect(collect(communityCall($this, $this->neighbourToken, 'tickets.index')->json('items'))->pluck('id')->intersect($this->made['tickets']))->toBeEmpty();
});

it('hands over every meeting the household could attend, a page at a time — and an old one is closed, not missing', function () {
    $make = function (string $phase, string $startsAt, string $status = Meeting::HELD): Meeting {
        $meeting = new Meeting;
        $meeting->forceFill([
            'type' => Meeting::PHASE, 'title' => $phase.' meeting '.$startsAt, 'starts_at' => $startsAt, 'venue' => 'Club House',
            'audience_scope' => Meeting::PHASE_SUBSET, 'phase' => $phase, 'status' => $status, 'published_at' => Carbon::parse($startsAt)->subDays(21),
        ])->save();

        $this->made['meetings'][] = $meeting->id;

        return $meeting;
    };

    // 52 meetings of Phase 8's own, years back — so they fall past the first page whatever else this estate holds.
    $mine = collect(range(0, 51))->map(fn (int $i): Meeting => $make('Phase 8', Carbon::parse('2019-01-05 10:00:00')->addWeeks($i)->format('Y-m-d H:i:s')));
    $theirs = $make('Phase 7', '2019-02-02 10:00:00');
    $draft = $make('Phase 8', '2019-02-09 10:00:00', Meeting::DRAFT);

    $served = collect();
    $before = null;
    $pages = 0;

    do {
        $page = communityCall($this, $this->token, 'meetings.index', [], $before === null ? [] : ['before' => $before]);
        expect(count($page->json('items')))->toBeLessThanOrEqual(50);

        $served = $served->concat($page->json('items'));
        $before = $page->json('next_before');
        $pages++;
    } while ($before !== null && $pages < 20);

    expect($pages)->toBeGreaterThanOrEqual(2)
        ->and($served->pluck('id')->duplicates())->toBeEmpty()
        ->and($served->pluck('id')->intersect($mine->pluck('id'))->count())->toBe(52)
        ->and($served->pluck('id'))->not->toContain($theirs->id)->not->toContain($draft->id);

    // Replying to a meeting held years ago is CLOSED (409) — it was "not found" while the list stopped at six months.
    communityCall($this, $this->token, 'meetings.rsvp', ['meeting' => $mine->first()->id], ['response' => 'attending'], 409)->assertJsonPath('error.code', 'meeting_closed');
    communityCall($this, $this->token, 'meetings.rsvp', ['meeting' => $theirs->id], ['response' => 'attending'], 404);
    communityCall($this, $this->token, 'meetings.index', [], ['before' => '@@@'], 422)->assertJsonPath('error.code', 'cursor_invalid');
});
