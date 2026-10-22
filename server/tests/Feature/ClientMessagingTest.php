<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Messaging a client's committee — board screen super-admin-11
|--------------------------------------------------------------------------
|
| THE SCREEN MAY ONLY SAY WHAT THE PLATFORM ACTUALLY DOES.
|
| For most of this build `ClientMessaging::send` wrote a row in `client_messages`
| and an audit entry, and that was the whole of it: no committee-facing inbox, no
| mail, no notification, and nothing anywhere ever wrote `client_messages.read_at`.
| The screen meanwhile made three claims on top of that — that the message reached
| "the Estate Console's Notices inbox", that it "copies <name>'s email on file",
| and that "delivery and read status will show here once sent" — while the
| recent-messages list printed "unread" beside every message ever sent, for ever.
|
| That is the shared-broadcast-read-mark defect (M4 §3.4) with the opposite
| failure: there a read state was claimed for everybody, here one was claimed for
| nobody. It mattered more than the wording suggested, because a Gemini operator
| who believes a treasurer has been emailed about an overdue invoice stops there.
|
| THE WORDING WAS FIXED FIRST, BECAUSE IT WAS UNTRUE RATHER THAN UNDECIDED. What
| to BUILD was the client's call and was QUESTIONS.md Q-M40: an inbox, an email,
| or both. They took the inbox — the only one of the three that can carry an
| honest read mark, since an email can report "sent" and never "read". D-M128.
|
| SO THIS FILE NOW HOLDS THE DELIVERY, and it holds it from both ends: the
| operator is told where the message went, the named recipient finds it in their
| own console, nobody else does, and the stamp the sender is later shown means
| "this person had these words on their screen, at this hour on their estate's
| clock". The one claim that is still not made anywhere is the email.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->sendTo = function (int $recipientId, string $category, string $subject, string $body) {
        return $this->actingAs($this->operator)->post('/clients/'.FacilitiesFixture::ESTATE.'/message', [
            'category' => $category,
            'recipient_id' => $recipientId,
            'subject' => $subject,
            'body' => $body,
        ]);
    };
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('records a message on the client\'s file and says where it was delivered', function () {
    $this->operator = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    ($this->sendTo)($treasurer->id, 'billing', 'August invoice is past due', 'The August invoice is now 21 days past due. Please let us know when it will be settled.')
        ->assertRedirect()
        ->assertSessionHas('success', function (string $flash): bool {
            // It says where it went, and it does not claim the one thing that still does not happen.
            return str_contains($flash, 'Delivered')
                && str_contains($flash, 'console')
                && str_contains($flash, 'Nobody is emailed');
        });

    $row = DB::connection('mysql')->table('client_messages')
        ->where('tenant_id', FacilitiesFixture::ESTATE)
        ->where('subject', 'August invoice is past due')
        ->sole();

    expect((int) $row->recipient_id)->toBe($treasurer->id)
        ->and($row->category)->toBe('billing')
        ->and($row->sent_by_name)->toBe($this->operator->name)

        // Not opened by anybody yet. The sender does not set this; only the recipient's own screen does.
        ->and($row->read_at)->toBeNull();

    // The record of a Gemini operator reaching into a client's console stands as it did.
    expect(AuditEntry::query()
        ->where('action', 'client.message_sent')
        ->where('tenant_id', FacilitiesFixture::ESTATE)
        ->where('actor_id', $this->operator->id)
        ->exists())->toBeTrue();
});

it('puts the message in the named recipient\'s own console, in full, and stamps it opened', function () {
    $this->operator = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $body = 'One officer deployed at your estate has a licence expiring next month. We are arranging cover.';

    ($this->sendTo)($treasurer->id, 'compliance', 'Guard licence expiry on file', $body)->assertRedirect();

    FacilitiesFixture::boot();

    $messages = [];

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/messages'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$messages): void {
            $messages = (array) $page->toArray()['props']['messages'];
        });

    $message = collect($messages)->firstWhere('subject', 'Guard licence expiry on file');

    /*
     * THE BODY, NOT A SUMMARY OF IT. A screen that shows a subject and hides the
     * message is one more place delivery can be claimed and not happen — and this
     * read is what stamps the row, so the stamp has to mean the words were shown.
     */
    expect($message)->not->toBeNull()
        ->and($message['body'])->toBe($body)
        ->and($message['category'])->toBe('Compliance');

    $row = DB::connection('mysql')->table('client_messages')
        ->where('subject', 'Guard licence expiry on file')
        ->sole();

    expect($row->read_at)->not->toBeNull();
});

it('shows a committee member only the messages addressed to them', function () {
    $this->operator = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    ($this->sendTo)($treasurer->id, 'billing', 'For the Treasurer only', 'The August invoice is past due.')->assertRedirect();

    FacilitiesFixture::boot();

    $messages = [];

    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/messages'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$messages): void {
            $messages = (array) $page->toArray()['props']['messages'];
        });

    expect(collect($messages)->pluck('subject'))->not->toContain('For the Treasurer only');

    /*
     * AND THE PRESIDENT'S VISIT DID NOT MARK THE TREASURER'S MESSAGE OPENED. The
     * stamp is keyed on the recipient, not on "somebody at this estate looked" —
     * the shared-read-mark failure this whole question came out of.
     */
    expect(DB::connection('mysql')->table('client_messages')->where('subject', 'For the Treasurer only')->value('read_at'))
        ->toBeNull();
});

it('keeps the first opening, not the last', function () {
    $this->operator = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    ($this->sendTo)($treasurer->id, 'general', 'Patrol times changing', 'Night patrols move to 8 PM from Monday.')->assertRedirect();

    FacilitiesFixture::boot();

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/messages'))->assertOk();

    $first = DB::connection('mysql')->table('client_messages')->where('subject', 'Patrol times changing')->value('read_at');

    expect($first)->not->toBeNull();

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/messages'))->assertOk();

    /*
     * The question the column answers is "when did they first have this in front
     * of them", which is the question asked when a client says they were never
     * told. A timestamp that moved on every visit would answer a different one.
     */
    expect(DB::connection('mysql')->table('client_messages')->where('subject', 'Patrol times changing')->value('read_at'))
        ->toBe($first);
});

it('tells the recipient a message is waiting, with a way into it, and stops once it is read', function () {
    $this->operator = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    ($this->sendTo)($treasurer->id, 'billing', 'September invoice attached', 'The September invoice is on your file.')->assertRedirect();

    FacilitiesFixture::boot();

    $bell = function () use ($treasurer): array {
        $items = [];

        $this->actingAs($treasurer)
            ->get(FacilitiesFixture::url('/notifications'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$items): void {
                $items = (array) $page->toArray()['props']['items'];
            });

        return $items;
    };

    $waiting = collect($bell())->first(fn (array $item): bool => str_contains((string) $item['title'], 'September invoice attached'));

    expect($waiting)->not->toBeNull()
        ->and($waiting['title'])->toContain('Gemini Security')

        /*
         * THE ONE ITEM ON THIS BELL THAT CARRIES A LINK. Everything else here is a
         * summary of a record the viewer already reaches through its own module. A
         * message from Gemini Security has no other home, so the title is the way in.
         */
        ->and($waiting['href'])->not->toBeNull();

    // Reading it is what resolves it — not a "mark seen" that leaves it undelivered.
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/messages'))->assertOk();

    expect(collect($bell())->pluck('title')->filter(fn ($title): bool => str_contains((string) $title, 'September invoice attached')))
        ->toBeEmpty();
});

it('reports to the sender whether it has been opened, and never before it has', function () {
    $this->operator = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $url = '/clients/'.FacilitiesFixture::ESTATE.'/message';

    ($this->sendTo)($president->id, 'compliance', 'Incident overnight at the service gate', 'An attempted entry was refused at 2:40 AM.')->assertRedirect();

    FacilitiesFixture::boot();

    $recentLine = function () use ($url): ?array {
        $recent = [];

        $this->actingAs($this->operator)
            ->get($url)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$recent): void {
                $recent = (array) $page->toArray()['props']['recent'];
            });

        return collect($recent)->firstWhere('subject', 'Incident overnight at the service gate');
    };

    $before = $recentLine();

    expect($before)->not->toBeNull()
        ->and($before['detail'])->toContain($president->name)
        ->and($before['detail'])->toContain('Compliance')
        /*
         * The segment separator is part of the assertion on purpose: "not opened
         * yet" contains the word "opened", so a bare substring check for it would
         * pass on the very line it is meant to refuse.
         */
        ->and($before['detail'])->toContain('· not opened yet')
        ->and($before['detail'])->not->toContain('· opened ');

    FacilitiesFixture::boot();
    $this->actingAs($president)->get(FacilitiesFixture::url('/messages'))->assertOk();
    FacilitiesFixture::boot();

    /*
     * "OPENED", NOT "READ", AND THAT IS THE WHOLE POINT OF THE WORD. The platform
     * knows the message was put in front of the person it was addressed to. It does
     * not know what happened behind their eyes, and neither does any email client
     * that has ever claimed otherwise.
     */
    expect(($recentLine())['detail'])->toContain('· opened ')
        ->and(($recentLine())['detail'])->not->toContain('not opened yet');
});

it('promises an inbox and a read mark on the screen, and still promises no email', function () {
    /*
     * The sentences are static template copy, so they are checked against the file the
     * way `OneTimeRuleTest` checks `MOBILE_HANDOFF.md`: the assertion has to be able to
     * reach the words, and a prop test cannot. If any of this stops being true, delete
     * the matching line here rather than working around it.
     */
    $screen = (string) file_get_contents(resource_path('js/Pages/Gemini/Clients/Message.vue'));

    // Everything between <template> and its close — the words an operator actually reads.
    $template = preg_match('/<template>(.*)<\/template>/s', $screen, $found) === 1 ? $found[1] : $screen;

    /*
     * WITHOUT THE COMMENTS. The note beside the copy quotes the sentences it replaced,
     * so that the next person to read it knows what was wrong and why — and a comment is
     * not rendered, so it is not something an operator can be misled by.
     */
    $template = (string) preg_replace('/<!--.*?-->/s', '', $template);

    // The email was the half of Q-M40 that was NOT taken, and it was the original lie.
    expect($template)->not->toContain('email on file')
        ->and($template)->toContain('Nobody is emailed')

        // And it says the true things in their place.
        ->and($template)->toContain('Estate Console')
        ->and($template)->toContain('when they open it');
});
