<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M30 §13: the classic Roster and Sites & geofences pages are unchanged
|--------------------------------------------------------------------------
|
| M30 builds a new Schedule and a new Places beside the classic pages, so the
| client can compare them, and promises the classic pages stay exactly as they
| are on the server (`b354bea`). This is that promise, checked rather than said.
|
| HOW. The pages are requested in a world this file builds and nothing else
| touches: an estate of its own, two gates, two officers, three shifts, a
| checkpoint, a Head of Security assigned to that estate alone, a frozen clock,
| and a FIXED id on every row, so the response does not depend on what other
| files in a shuffled run have inserted. The responses were RECORDED once at
| `b354bea`, before any M30 change (`M30_RECORD_CLASSIC=1`), into
| tests/Fixtures/m30/. Every later run compares against that recording.
|
| WHAT IS COMPARED. The whole HTML document, and the Inertia page object inside
| it with every prop, in order. Three values are written over first, because they
| change on every request and say nothing about the page: the CSRF token, the
| asset version (a hash of the build manifest), and `live.heads`, the platform's
| live-update counter, which every write anywhere advances.
|
| AND THE SOURCE. The page is drawn in the browser from Vue files, so the files
| the two pages are built from — the pages, their components, their layout, their
| composables and libraries, and every stylesheet — are hashed, and the hashes
| recorded at `b354bea` must still match. One file is exempt, by the milestone's
| own order (§1.3): `Subnav.vue`, which gains one plain link, "New view", at the
| end of the bar on these two pages and nothing else; the bar's items, word for
| word, are tested in tests/js/workforce-schedule.test.mjs.
|
| AND ONE IS RE-PINNED, at M32 Stage 3: `GeminiConsole.vue`, the shell every
| Gemini page sits in, gains the words a table's address carries when the search
| box is used (F5) and "My account" in the person's menu (F9). Both are the
| shell's, drawn on every page alike; neither page's own file changes, and the
| two pages' responses above still match the b354bea recording (the shared phone
| example, F7, is left off them in `HandleInertiaRequests`).
|
| AND THE CLASSIC ROSTER IS RE-RECORDED AT M35, BY THAT MILESTONE'S OWN ORDER:
| every row of its day list gains Change guard (Assign, on an open shift) and
| Remove, and "Post open shift" opens the schedule's shift panel in place of a
| form of its own (§2, §3) — so its rows say whether Remove may be pressed, it is
| handed each client's gates for the panel, and it is drawn from the panel's files
| too, which are pinned with it now; and the grid says above it that it shows
| where each officer normally works, not this week's shifts (§4). The layout is
| as it was. The Sites page is not touched: its recording comes out the same.
| M35's last commit (`6eca0896`) then added two zero-specificity rules to `app.css` for both consoles — a dropdown's
| chosen option on its box's middle line, and a link-styled <button> without the browser's grey chrome — and did not
| re-pin the stylesheet; it is re-pinned with the merge (8 Oct, M51). Neither rule changes either page's response.
|
| AND ONE MORE, on 8 October 2026 (M50 §4.3): `useLiveReload.js`, whose list of
| what moves the Gemini console's activity pages (`GEMINI_ACTIVITY`) gains Live
| chat ('chat'). That list is read by the dashboard, the bell and the activity
| feed only; the classic Roster listens to 'shift' and Sites to 'shift', 'client'
| and 'officer', as before, so neither page moves on anything new.
|
| AND THE SITES PAGE AND ITS MAP ARE RE-PINNED ON 9 OCTOBER 2026 (M57.5 §4.5): an estate now has the boundary its site
| plan draws, so the Sites page's `site` carries `boundary` (null on this file's estate, which has none) and passes it
| to `SiteMap.vue`, which draws a boundary as the polygon it is. Nothing else on either page changes; the Roster's
| recording comes out the same.
|
| AND `app.css` AND A COMMENT IN `GeminiConsole.vue` ARE RE-PINNED ON 10 OCTOBER 2026. DR-009 (`fb709d60`, 01:21)
| put the red emergency bar in the page above the shell, DR-053 (`56ab2679`) gave the window the bar's height as its
| scroll padding, and M71 sized every text box (Hassam's rule: at least 80 px tall, the width of its column, dragged
| only taller) and let a board field box grow round one. The first two were committed without re-pinning, and this
| test sat red from 01:21 until now. Stylesheets and a comment change no response: both pages' recordings come out
| the same. Then once more the same day for the sidebar's spacing in a window under 760 px tall (M71 E2E-M4), which
| both consoles' sidebars share; again no response moves.
|
| AND THE CLASSIC ROSTER IS RE-PINNED ON 10 OCTOBER 2026 FOR DR-075 (the defect register's Part 7, the QA session):
| Approve and Decline on an officer's claim ask first, naming who is told, in the console's own dialog and in the words
| `lib/claimQuestion.js` gives the Schedule too. So the Roster is drawn from `ConsoleDialog.vue` and `claimQuestion.js`
| as well, and both are pinned with it now. The dialog is drawn only once a claim is pressed, so neither page's
| response moves: both recordings come out the same.
|
| AND `ConsoleDialog.vue` IS RE-PINNED ON 11 OCTOBER 2026 (M95 §1, M97 item 1, Hassam's order): the consoles' one
| dialog becomes the one modal for every form that used to open on its own page, so it gains a close control in its
| corner, asks before throwing away what was typed, scrolls a long body inside itself with its buttons always in view,
| and takes the whole screen on a phone. The Roster's claim dialog gains the corner control and keeps its words and its
| two buttons. It is still drawn only once a claim is pressed, so neither page's response moves. Re-pinned again the
| same evening (M96 level 2): a greyed primary button's reason is said on the screen under it, not only in its tooltip.
|
| AND `GeminiConsole.vue` IS RE-PINNED ON 11 OCTOBER 2026 (Hassam, from the Security session's report): the Gemini shell
| mounts the shared toast stack, as the estate shell does, so a toast on a Gemini page is drawn at all. The stack draws
| nothing until a toast is raised, so neither page's response moves. Re-pinned again the same night (the Security
| session's report): the shell's top bar wraps into a second row when it is full, where the live line was crushed over
| the tabs; a bar that fits is drawn as before, and neither page's response moves.
|
| AND `ConsoleDialog.vue` IS RE-PINNED AGAIN ON 11 OCTOBER 2026 (Hassam, rule 20): a refusal inside the dialog is an
| amber box and a destructive button a red outline on white, solid red being the open panic's alone; its comment names
| the one save helper (lib/dialogSubmit.js). The Roster's claim dialog keeps its words and buttons; neither page's
| response moves.
|
| AND `app.css` AND `EmptyState.vue` ARE RE-PINNED ON 12 OCTOBER 2026 (Hassam's final red rule, the shared red sweep):
| an urgent notice's tag and icon are amber where every urgent tag was solid red-700, and the empty state's error and
| denied icons are amber where they were red. Neither classic page draws an urgent notice, and its empty state is drawn
| only with no officer or site, so neither page's response moves: both recordings come out the same.
|
| AND `GeminiConsole.vue` IS RE-PINNED ON 12 OCTOBER 2026 (Hassam, the Gemini shell given to the Accounting session):
| the shell takes a `back` prop and draws the shared back control, in words, before the title, as the estate shell does.
| Neither classic page passes one, so neither page's response moves. (Both re-pins met at the batch two merge; the
| fixture's keys are separate, so the recording holds both.) Re-pinned again the same night for the phone: below 1024 px
| the sidebar is a drawer behind a Menu button, as the estate shell's is (369e87ef). Above that width nothing is drawn
| differently, and the classic pages' responses carry no layout, so neither moves.
|
| AND `app.css` ONCE MORE THE SAME NIGHT (the final red rule's middle weight, routed by the hub from Governance): a
| rejected nomination's badge and a failed dunning step are a red outline, where they sat in the amber group; and the
| arrears page's 90+ days card takes the amber ground its words already had. Neither classic page draws any of them,
| so neither page's response moves. (Re-recorded at the merge of m98/settings, from the app.css that merge made.)
|
| AND `app.css`, `SiteMap.vue` AND `Sites.vue` ARE RE-PINNED ON 12 OCTOBER 2026 (RED_MAP.md, routed to Security): a
| warning card's icon tile and a refused gate row's tile are amber where they were pale red; the shared map gains an
| amber `placing` tone, and Sites & geofences draws a position being placed in it, where it used the panic red (and the
| estate pin's title loses its em dash). Neither classic page draws a warning card, a refused row or a position being
| placed, so neither page's response moves.
|
| AND `SiteMap.vue` IS RE-PINNED AGAIN ON 12 OCTOBER 2026 (the estate's security map, taken by the Residents lane from
| Security's c2aaab78): a pin with words is never folded into a counted cluster, the most urgent pin is drawn on top,
| and the map's two messages, offline and a failed draw, are sentences with no em dash. The map draws in the browser and
| the classic Sites page's pins carry no words, so neither page's response moves.
|
| AND `GeminiConsole.vue` AND THE CLASSIC ROSTER ARE RE-PINNED ON 12 OCTOBER 2026 (Hassam: the Gemini console's messages
| in three kinds). The shell says the server's flash as a toast for every Gemini page, and the Roster no longer draws the
| flash or a refusal as a banner of its own; its claim question keeps a refusal in its amber band and stays open, closing
| only when the claim is answered. The flash is empty when either page is drawn, the toast stack draws nothing until a
| toast is raised, and the claim question only once a claim is pressed, so neither page's response moves: both
| recordings came out the same, and only the two source hashes changed.
|
| AND `ConsoleDialog.vue` AND `GeminiConsole.vue` ARE RE-PINNED AGAIN ON 12 OCTOBER 2026 (Hassam's batch 1: every form in a
| dialog reads the same, and the Gemini console fits a phone with its header pinned). The dialog gains one field style
| for whatever form it holds and a rule under a form's title; the shell gains phone rules below 1023 px and a sticky top
| bar. Neither classic page opens a dialog when drawn, and the phone rules and the sticky bar are styles the browser
| applies, so neither page's response moves: only the two source hashes were re-recorded, and both recordings still match.
|
*/

const M30_CLASSIC_DIR = __DIR__.'/../../Fixtures/m30';
const M30_CLASSIC_ESTATE = 'classicpages';

/** The source files the two classic pages are drawn from, relative to the repository root, with their hashes. */
function m30ClassicSources(): array
{
    $root = dirname(__DIR__, 3);
    $files = [
        'resources/js/Pages/Gemini/Operations/Roster.vue',
        'resources/js/Pages/Gemini/Operations/Sites.vue',
        'resources/js/Layouts/GeminiConsole.vue',
        'resources/js/Components/SiteMap.vue',
        'resources/js/Components/EmptyState.vue',
        'resources/js/Components/BoardIcon.vue',
        'resources/js/Components/SkeletonRows.vue',
        'resources/js/composables/useScreenState.js',
        'resources/js/composables/useLiveReload.js',
        'resources/js/lib/coordinates.js',
        'resources/js/lib/checkpointMessages.js',
        // M35 §2, §3: the classic roster opens the schedule's shift panel, and so is drawn from it too.
        'resources/js/Pages/Gemini/Operations/ShiftPanel.vue',
        'resources/js/Components/InfoButton.vue',
        'resources/js/lib/infoTopics.js',
        'resources/js/lib/shiftSpan.js',
        'resources/js/lib/scheduleTime.js',
        'resources/js/live/authorize.js',
        // DR-075 (10 Oct): the classic roster asks before answering a claim, so it is drawn from the dialog and its words.
        'resources/js/Components/ConsoleDialog.vue',
        'resources/js/lib/claimQuestion.js',
    ];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/css', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.css')) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }

    sort($files);

    $hashes = [];
    foreach ($files as $file) {
        // Line endings normalised, so a checkout on another platform is not a change.
        $hashes[$file] = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($root.'/'.$file)));
    }

    return $hashes;
}

/** A row with the id given, whatever the table's counter says. */
function m30ClassicRow(string $class, int $id, array $attributes): object
{
    $class::query()->whereKey($id)->delete();

    $row = new $class;
    $row->forceFill(['id' => $id, ...$attributes])->save();

    return $row;
}

/**
 * The world the pages are requested in, inside the test's transaction.
 */
function m30ClassicWorld(): User
{
    FacilitiesFixture::platform();

    // A fixed instant: Wednesday 7 October 2026, 10:00 AM in Kingston.
    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'UTC'));

    DB::connection('mysql')->table('tenants')->where('id', M30_CLASSIC_ESTATE)->delete();
    DB::connection('mysql')->table('tenants')->insert([
        'id' => M30_CLASSIC_ESTATE,
        'name' => 'Classic Pages Estate',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $main = m30ClassicRow(Post::class, 930001, ['tenant_id' => M30_CLASSIC_ESTATE, 'name' => 'Classic Main Gate', 'type' => 'gate', 'is_active' => true, 'latitude' => 17.9612, 'longitude' => -76.8821, 'geofence_radius_m' => 80]);
    $back = m30ClassicRow(Post::class, 930002, ['tenant_id' => M30_CLASSIC_ESTATE, 'name' => 'Classic Back Gate', 'type' => 'gate', 'is_active' => true]);

    $officer = static fn (int $id, string $name, string $number, object $post): object => m30ClassicRow(Guard::class, $id, [
        'full_name' => $name,
        'employee_number' => $number,
        'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => '2027-06-23',
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => M30_CLASSIC_ESTATE,
        'post_id' => $post->id,
    ]);

    $andre = $officer(930011, 'Classic Andre Camp', 'GS-CL-01', $main);
    $kemar = $officer(930012, 'Classic Kemar Blake', 'GS-CL-02', $back);

    $shift = static fn (int $id, object $post, string $from, string $to, ?object $guard): object => m30ClassicRow(Shift::class, $id, [
        'tenant_id' => M30_CLASSIC_ESTATE,
        'post_id' => $post->id,
        'guard_id' => $guard?->id,
        'rostered_start' => Carbon::parse($from, 'UTC'),
        'rostered_end' => Carbon::parse($to, 'UTC'),
        'status' => $guard === null ? Shift::OPEN : Shift::ROSTERED,
    ]);

    // Today at the estate (Wed 7 Oct): 6 AM – 6 PM and 9 AM – 5 PM; and an open one tomorrow.
    $shift(930021, $main, '2026-10-07 11:00:00', '2026-10-07 23:00:00', $andre);
    $shift(930022, $back, '2026-10-07 14:00:00', '2026-10-07 22:00:00', $kemar);
    $shift(930023, $main, '2026-10-08 11:00:00', '2026-10-08 23:00:00', null);

    m30ClassicRow(PatrolCheckpoint::class, 930031, [
        'tenant_id' => M30_CLASSIC_ESTATE,
        'post_id' => null,
        'label' => 'Classic Pool Deck',
        'sequence' => 1,
        'code' => 'CLASSIC-CODE-1',
        'is_active' => true,
    ]);

    User::query()->whereKey(930041)->delete();
    User::query()->where('email', 'classic-page-viewer@m30.example.com')->delete();
    $viewer = User::factory()->create([
        'id' => 930041,
        'name' => 'Classic Page Viewer',
        'email' => 'classic-page-viewer@m30.example.com',
        'console' => Console::Gemini->value,
        'status' => 'active',
    ]);
    $viewer->syncRoles([Role::HEAD_OF_SECURITY]);

    m30ClassicRow(EstateAssignment::class, 930051, [
        'user_id' => $viewer->id,
        'tenant_id' => M30_CLASSIC_ESTATE,
        'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id,
        'is_active' => true,
    ]);

    return $viewer->fresh();
}

/** The page's response with its three per-request values written over (see the file's note). */
function m30ClassicCanonical(string $html): string
{
    $html = (string) preg_replace('/<meta name="csrf-token" content="[^"]*">/', '<meta name="csrf-token" content="TOKEN">', $html);

    // Inertia writes the page object as raw JSON in <script data-page="app" type="application/json">.
    if (preg_match('/<script data-page="app" type="application\/json">(.*?)<\/script>/s', $html, $found) !== 1) {
        return $html;
    }

    $page = json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);
    $page['version'] = 'VERSION';

    if (isset($page['props']['live']['heads']) && is_array($page['props']['live']['heads'])) {
        $page['props']['live']['heads'] = array_map(static fn (): string => 'HEAD', $page['props']['live']['heads']);
    }

    $shell = str_replace($found[0], '<script data-page="app" type="application/json">PAGE</script>', $html);

    return $shell."\n\n".json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
}

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
});

dataset('classic pages', [
    'the classic roster' => ['/guards/roster', 'classic-roster.txt'],
    'the classic roster, a day opened' => ['/guards/roster?date=2026-10-08', 'classic-roster-day.txt'],
    'the classic sites page' => ['/guards/sites?estate='.M30_CLASSIC_ESTATE, 'classic-sites.txt'],
]);

it('serves the classic page exactly as it was at b354bea', function (string $path, string $file) {
    $viewer = m30ClassicWorld();

    $response = $this->actingAs($viewer)->get($path);
    $response->assertOk();

    $canonical = m30ClassicCanonical((string) $response->getContent());
    $golden = M30_CLASSIC_DIR.'/'.$file;

    if (getenv('M30_RECORD_CLASSIC') === '1') {
        @mkdir(M30_CLASSIC_DIR, 0775, true);
        file_put_contents($golden, $canonical);
    }

    expect(is_file($golden))->toBeTrue('The recording made at b354bea is missing: '.$golden)
        ->and($canonical)->toBe(str_replace("\r\n", "\n", (string) file_get_contents($golden)));
})->with('classic pages');

it('is drawn from exactly the source files it was drawn from at b354bea', function () {
    $golden = M30_CLASSIC_DIR.'/classic-sources.json';
    $now = m30ClassicSources();

    if (getenv('M30_RECORD_CLASSIC') === '1') {
        @mkdir(M30_CLASSIC_DIR, 0775, true);
        file_put_contents($golden, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    $then = json_decode((string) file_get_contents($golden), true, flags: JSON_THROW_ON_ERROR);

    // Every file the classic pages used then is still there, unchanged; a stylesheet added since is a change too.
    expect($now)->toBe($then);
});
