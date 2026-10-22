<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M20 §8.1 — the demonstration, rehearsed step by step on the production seed
|--------------------------------------------------------------------------
|
| NOT PART OF THE SUITE. DEMO_SCRIPT.md's set-up and its sixteen steps, in its order, each made by
| the person the script names, on the perf slot's copy of Phoenix Park Village 1 — the estate
| `phoenix:demo-seed` and `company:demo-seed` built, the same commands that filled production.
| Nobody signs in: every console step is the named account through `actingAs`, and every phone
| step is that phone's own API call with its own token. The sign-in code and the enrolment code
| are the local ones this test is issued in-process, exactly as the contract walks read them.
|
|     tools/perf/rehearse.ps1     (snapshots the slot, runs this, puts the slot back)
|
| Each step checks what the script's "See:" line promises. A step that stumbles is recorded with
| what the screen or the server said, and the walk carries on, so one run lists every stumble.
|
*/

use App\Api\Catalogue;
use App\Models\DuressAlert;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Unit;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResidentSignInCode;
use App\Support\SiteTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Performance\Support\ScaleEstate;
use Tests\TestCase;

pest()->extend(TestCase::class);

/** Where the presenter stands in DEMO_SCRIPT §2.1's own example: a room in Lahore. */
const REHEARSAL_LAT = 31.52043;

const REHEARSAL_LNG = 74.35874;

/** One phone's call, as its app makes it: the catalogue's path, the write headers, a fresh request. */
function rehearsalApp(TestCase $test, ?string $token, string $name, array $params = [], array $body = [], array $headers = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    if ($entry['write']) {
        $headers += ['Idempotency-Key' => 'rehearsal-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()];
    }

    rehearsalReset();
    $request = $token === null ? $test : $test->withToken($token);
    $response = $entry['method'] === 'GET'
        ? $request->getJson($uri.($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $request->json($entry['method'], $uri, $body, $headers);
    rehearsalReset();

    return $response;
}

/** A console screen or form, as the named person. `$estate` true: the estate console's own hostname. */
function rehearsalConsole(TestCase $test, User $as, string $method, string $path, array $data = [], bool $estate = false): TestResponse
{
    $url = $estate ? 'http://'.ScaleEstate::ESTATE.'.'.config('app.estate_domain').'/'.ltrim($path, '/') : $path;

    rehearsalReset();
    $response = $method === 'GET' ? $test->actingAs($as)->get($url) : $test->actingAs($as)->from($url)->post($url, $data);
    rehearsalReset();

    return $response;
}

/**
 * The worker production runs, once over what is due now: the ring rounds and the ring pushes are pinned to the
 * central queue (`CentralQueue::pin`), which nothing reads in a test. A delayed round — the escalation, a minute
 * after the raise — is not due yet and stays queued, as it would on the server.
 */
function rehearsalWorker(): void
{
    $queues = array_values(array_unique(array_filter([config('push.ring_queue'), config('push.urgent_queue'), 'default'], static fn ($q): bool => is_string($q) && $q !== '')));
    Artisan::call('queue:work', ['connection' => (string) config('queue.central_connection', 'central'), '--queue' => implode(',', $queues), '--stop-when-empty' => true, '--tries' => 1]);
    rehearsalReset();
}

function rehearsalReset(): void
{
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    app('auth')->forgetGuards();
}

/** The Inertia page's props, from a console screen's HTML answer. */
function rehearsalProps(TestResponse $response): array
{
    $page = $response->viewData('page');

    return is_array($page) ? (array) ($page['props'] ?? []) : [];
}

/** What a refused console form said: every error the session carries, as the screen would show it. */
function rehearsalRefusal(): string
{
    $errors = session('errors');

    return $errors === null ? '' : implode(' | ', $errors->all());
}

it('walks DEMO_SCRIPT.md from the set-up to step 16 and lists every stumble', function (): void {
    ScaleEstate::assertSlot();

    $site = ScaleEstate::ESTATE;
    $estate = Tenant::query()->findOrFail($site);
    $zone = SiteTime::zoneOf($estate);
    $ran = [];
    $stumbles = [];
    $paths = [];

    // The walk goes on after a stumble, so every stumble of one run is on the list.
    $step = static function (string $name, Closure $walk) use (&$ran, &$stumbles): mixed {
        try {
            $out = $walk();
            $ran[] = 'ok     '.$name;

            return $out;
        } catch (Throwable $e) {
            $ran[] = 'STUMBLE '.$name.' — '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 400);
            $stumbles[] = $name;

            return null;
        }
    };
    $must = static function (bool $ok, string $saying): void {
        if (! $ok) {
            throw new RuntimeException($saying);
        }
    };

    // Nothing leaves the laptop: the push service and the mail are caught here. Pushes are ON, as on production —
    // the test environment's default is off (`config/push.php`), and off, a panic reaches no handset by design.
    config(['push.enabled' => true, 'ring.enabled' => true]);
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'rehearsal-ticket']]]), '*' => Http::response([], 200)]);
    Notification::fake();

    /* ── Who is at the table (§1) ── */

    $trevor = User::query()->where('email', 'trevor-property-manager@'.$site.'.demo.geminisl.com')->first();
    $norman = User::query()->where('email', 'norman-treasurer@'.$site.'.demo.geminisl.com')->first();
    $director = User::query()->where('email', ScaleEstate::DIRECTOR_EMAIL)->first()
        ?? User::query()->role(Role::DIRECTOR)->where('console', 'gemini')->first();
    $andre = Guard::query()->where('tenant_id', $site)->where('employee_number', 'GS-PPV-01')->first();

    $step('§1 the people the script names exist: Trevor Wright, Norman Case, a Director, Andre Campbell GS-PPV-01', function () use ($trevor, $norman, $director, $andre, $must) {
        $must($trevor instanceof User && $trevor->name === 'Trevor Wright', 'no Trevor Wright, Property Manager');
        $must($norman instanceof User && $norman->name === 'Norman Case', 'no Norman Case, Treasurer');
        $must($director instanceof User, 'no Director on the Gemini console');
        $must($andre instanceof Guard && str_contains((string) $andre->full_name, 'Andre Campbell'), 'GS-PPV-01 is '.($andre?->full_name ?? 'missing'));
    });

    /* ── §2.1 a demonstration post where the presenter stands ── */

    $post = $step('§2.1 the Director adds "Demonstration post — presenter\'s office" at the presenter\'s spot, 150 m', function () use ($director, $site, $must) {
        $name = 'Demonstration post — presenter\'s office';
        Post::query()->where('tenant_id', $site)->where('name', $name)->delete();
        $answer = rehearsalConsole($this, $director, 'POST', '/guards/sites/'.$site.'/posts', ['name' => $name, 'type' => 'gate', 'lat' => REHEARSAL_LAT, 'lng' => REHEARSAL_LNG, 'radius_m' => 150, 'reason' => 'client demonstration']);
        $must($answer->isRedirect() && rehearsalRefusal() === '', 'refused: '.rehearsalRefusal());
        $post = Post::query()->where('tenant_id', $site)->where('name', $name)->first();
        $must($post instanceof Post, 'the post was not added');

        return $post;
    });

    /* ── §2.2 post the shift and ASSIGN it, on the estate's clock ── */

    $shift = $step('§2.2 the Director posts an 8-hour shift starting an hour ago (estate time) and assigns it to Andre Campbell', function () use ($director, $andre, $post, $zone, $must) {
        $must($post instanceof Post, 'no post from §2.1');
        $start = now($zone)->subHour()->startOfMinute();
        $posted = rehearsalConsole($this, $director, 'POST', '/guards/roster/shifts', ['post_id' => $post->id, 'starts_at' => $start->format('Y-m-d H:i'), 'ends_at' => $start->copy()->addHours(8)->format('Y-m-d H:i')]);
        $must(rehearsalRefusal() === '', 'posting refused: '.rehearsalRefusal());
        $shift = Shift::query()->where('post_id', $post->id)->whereNull('guard_id')->latest('id')->first();
        $must($shift instanceof Shift, 'no open shift after posting ('.$posted->status().')');
        rehearsalConsole($this, $director, 'POST', '/guards/roster/shifts/'.$shift->id.'/assign', ['guard_id' => $andre->id]);
        $must(rehearsalRefusal() === '', 'assigning refused: '.rehearsalRefusal());
        $must((int) $shift->fresh()->guard_id === (int) $andre->id, 'the shift is not Andre\'s after assigning');

        return $shift->fresh();
    });

    /* ── §2.3 enrol the Guard App ── */

    $guardToken = $step('§2.3 the Director issues an enrolment code; phone 2 enrols with it (and, if Andre already has a phone, the Director approves the new one)', function () use ($director, $andre, $must, &$paths) {
        rehearsalConsole($this, $director, 'POST', '/guards/'.$andre->id.'/enrolment-code');
        $issued = session('enrolment_code');
        $must(is_array($issued) && is_string($issued['code'] ?? null), 'no code issued: '.rehearsalRefusal());
        $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $answer = rehearsalApp($this, null, 'devices.enrol', [], ['enrolment_code' => $issued['code'], 'device_uid' => 'rehearsal-phone-2', 'platform' => 'android', 'public_key' => $key, 'label' => 'Phone 2']);

        if ($answer->status() === 202) {
            // A guard who already has a bound handset is not rebound until a supervisor says so (13 D1).
            $paths[] = '§2.3: Andre Campbell already had a handset, so phone 2 waited for approval — Guard workforce → Andre Campbell → "New handset waiting" → Approve handset';
            $page = rehearsalConsole($this, $director, 'GET', '/guards/'.$andre->id);
            $must(str_contains(json_encode(rehearsalProps($page)['rebindRequests'] ?? []), (string) $answer->json('rebind_request_id')), 'the guard\'s page does not show the waiting handset');
            rehearsalConsole($this, $director, 'POST', '/guards/'.$andre->id.'/device-rebinds/'.$answer->json('rebind_request_id').'/approve', ['note' => '']);
            $must(rehearsalRefusal() === '', 'approving refused: '.rehearsalRefusal());
            $answer = rehearsalApp($this, null, 'devices.enrol.status', ['rebind' => $answer->json('rebind_request_id')], ['claim_secret' => $answer->json('claim_secret'), 'device_uid' => 'rehearsal-phone-2']);
        } else {
            $paths[] = '§2.3: phone 2 enrolled at once (Andre Campbell had no handset)';
        }

        $must(in_array($answer->status(), [200, 201], true), 'enrol answered '.$answer->status().': '.$answer->json('error.message'));
        $must(is_string($answer->json('token')), 'no token: '.json_encode($answer->json()));

        return (string) $answer->json('token');
    });

    /* ── §2.4 make phone 2 ready to RING ── */

    $step('§2.4 phone 2 registers for alerts with every ring row On, and "Send a test ring" sends one', function () use ($guardToken, $must) {
        $must(is_string($guardToken), 'no Guard App token from §2.3');
        $ring = ['v' => 1, 'configured' => true, 'notifications' => 'on', 'ring_channel' => 'on', 'full_screen' => 'on', 'dnd_bypass' => 'on',
            'battery' => 'exempt', 'background' => 'allowed', 'alarm_volume' => 'ok', 'alarm_tone' => 'set', 'screen_lock' => 'on', 'sdk' => 35, 'maker' => 'samsung'];
        $push = rehearsalApp($this, $guardToken, 'push.register', [], ['token' => 'ExponentPushToken[rehearsalPhone2xxxxxxx]', 'platform' => 'android', 'ring' => $ring]);
        $must($push->status() === 200, 'push.register answered '.$push->status().': '.$push->json('error.message'));
        $before = count(Http::recorded(static fn ($r) => str_contains($r->url(), 'exp.host')));
        $test = rehearsalApp($this, $guardToken, 'ring.test');
        $must($test->status() === 200, 'ring.test answered '.$test->status().': '.$test->json('error.message'));
        rehearsalWorker();
        $after = count(Http::recorded(static fn ($r) => str_contains($r->url(), 'exp.host')));
        $must($after > $before, 'no test ring went to the push service');
    });

    $step('§2.4.3 location sharing: I agree', function () use ($guardToken, $must) {
        $answer = rehearsalApp($this, $guardToken, 'tracking.consent', [], ['decision' => 'granted', 'policy_version' => (string) config('tracking.policy_version')]);
        $must($answer->status() === 200, 'tracking.consent answered '.$answer->status().': '.$answer->json('error.message'));
    });

    $here = ['latitude' => REHEARSAL_LAT + 20 / 111_320, 'longitude' => REHEARSAL_LNG, 'accuracy_m' => 25];

    $step('§2.6 phone 2 → Clock in → "Within geofence" (not clocked in yet)', function () use ($guardToken, $shift, $here, $must) {
        $must($shift instanceof Shift, 'no shift from §2.2');
        $pre = rehearsalApp($this, $guardToken, 'shifts.preflight', ['shift' => $shift->id], $here);
        $must($pre->status() === 200, 'preflight answered '.$pre->status().': '.$pre->json('error.message'));
        $must($pre->json('allowed') === true, 'not allowed: '.json_encode(collect($pre->json('checks'))->map(fn ($c) => $c['key'].'='.($c['state'] ?? '').': '.($c['detail'] ?? ''))->all()));
        $must(in_array($pre->json('outcome'), ['inside', 'inside_margin'], true), 'outcome '.$pre->json('outcome'));
    });

    /* ── §2.7 something for step 12 to pay: the seed paid every vacant lot in full ── */

    $step('§2.7 Norman → Dues → New charge: Lot 1999, Dues, J$6,200, "Maintenance fee — October", due today', function () use ($norman, $zone, $must) {
        $form = rehearsalConsole($this, $norman, 'GET', '/finance/charges/new', estate: true);
        $must($form->status() === 200, 'New charge answered '.$form->status());
        $account = collect(rehearsalProps($form)['accounts'] ?? [])->first(fn ($a) => str_contains(strtolower((string) ($a['label'] ?? '')), 'dues') || str_contains(strtolower((string) ($a['label'] ?? '')), 'maintenance'))
            ?? collect(rehearsalProps($form)['accounts'] ?? [])->first();
        $must(is_array($account), 'the form offers no GL account');
        rehearsalConsole($this, $norman, 'POST', '/finance/charges', ['unit' => 'Lot 1999', 'type' => 'dues', 'amount' => '6200.00', 'description' => 'Maintenance fee — October', 'due_on' => now($zone)->toDateString(), 'account' => $account['code']], estate: true);
        $must(rehearsalRefusal() === '', 'posting the charge refused: '.rehearsalRefusal());
    });

    /* ── Step 1: the office adds a household ── */

    $email = 'presenter.rehearsal.'.bin2hex(random_bytes(3)).'@example.com';
    $lot = $estate->run(static fn () => Unit::query()->where('reference', 'Lot 1999')->first());

    $step('1 · Trevor adds the presenter to Lot 1999 (Phase 5), "Resident verifies in the app"; the register shows them Pending', function () use ($trevor, $lot, $email, $estate, $must) {
        $must($lot instanceof Unit, 'there is no Lot 1999');
        $must($estate->run(static fn () => Household::query()->where('unit_id', $lot->id)->doesntExist()), 'Lot 1999 is not empty');
        rehearsalConsole($this, $trevor, 'POST', '/residents', ['phase' => (string) $lot->block, 'lot' => 'Lot 1999', 'full_name' => 'Demo Presenter', 'email' => $email, 'phone' => '876 555 0142', 'verification' => 'invite'], estate: true);
        $must(rehearsalRefusal() === '', 'refused: '.rehearsalRefusal());
        $must(str_contains((string) session('success'), 'as Pending'), 'said: '.session('success'));
    });

    /* ── Step 2: the resident signs in ── */

    $residentToken = $step('2 · phone 1 → Phoenix Park Village 1 → the email → a six-digit code by email → Home with Lot 1999', function () use ($site, $email, $trevor, $must, &$paths) {
        $asked = rehearsalApp($this, null, 'auth.otp.request', [], ['estate' => $site, 'channel' => 'email', 'destination' => $email]);
        $must(in_array($asked->status(), [200, 202], true), 'otp.request answered '.$asked->status().': '.$asked->json('error.message'));
        $code = null;
        Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n, array $channels, object $notifiable) use (&$code, $email): bool {
            if (in_array($email, (array) ($notifiable->routes['mail'] ?? []), true) || ($notifiable->routes['mail'] ?? null) === $email) {
                $code = $n->code;
            }

            return true;
        });
        $must(is_string($code) && strlen($code) === 6, 'no six-digit code went to '.$email);
        $in = rehearsalApp($this, null, 'auth.otp.verify', [], ['estate' => $site, 'channel' => 'email', 'destination' => $email, 'code' => $code, 'device_uid' => 'rehearsal-phone-1', 'platform' => 'android']);
        $must($in->status() === 200, 'otp.verify answered '.$in->status().': '.$in->json('error.message'));
        $token = (string) $in->json('token');

        if ($in->json('next') === 'await_approval') {
            // The invited address made the claim; the estate has not chosen to trust invites, so the office approves it.
            $paths[] = 'step 2: the sign-in made a claim for Lot 1999 that waited for the office — Residents → Claims → approve';
            $claim = (int) $in->json('claim.id');
            $claims = rehearsalConsole($this, $trevor, 'GET', '/residents/claims', estate: true);
            $must(str_contains(json_encode(rehearsalProps($claims)), 'Demo Presenter'), 'Residents → Claims does not list the presenter');
            rehearsalConsole($this, $trevor, 'POST', '/residents/claims/'.$claim.'/approve', estate: true);
            $must(rehearsalRefusal() === '', 'approving the claim refused: '.rehearsalRefusal());
        } else {
            $paths[] = 'step 2: the sign-in went straight to '.json_encode($in->json('next'));
        }
        $me = rehearsalApp($this, $token, 'me.show');
        $must($me->status() === 200, 'me.show answered '.$me->status().' — '.$me->json('error.message'));
        $must(str_contains(json_encode($me->json()), 'Lot 1999'), 'Home does not show Lot 1999; state '.json_encode($in->json('account') ?? $in->json('status')));

        return $token;
    });

    /* ── Step 3: the resident invites a visitor ── */

    $pass = $step('3 · Invite visitor → name, "Family visit", now to two hours later → the pass, Active, with its QR and short code', function () use ($residentToken, $must) {
        $must(is_string($residentToken), 'no Resident App token from step 2');
        // Nothing is optional (M18C): the visitor's phone, and a plate or "Arriving on foot or by taxi — no vehicle".
        $answer = rehearsalApp($this, $residentToken, 'visitor_passes.store', [], ['category' => 'single', 'visitor_name' => 'Marcia Brown', 'purpose' => 'Family visit', 'visitor_phone' => '876 555 0177', 'no_vehicle' => true,
            'valid_from' => now()->toIso8601String(), 'valid_to' => now()->addHours(2)->toIso8601String()]);
        $must($answer->status() === 201, 'visitor_passes.store answered '.$answer->status().': '.$answer->json('error.message'));
        $must(($answer->json('status') ?? $answer->json('state')) === 'active', 'the pass is '.json_encode($answer->json('status')));
        $must(is_string($answer->json('token')) && is_string($answer->json('code')), 'no QR token or short code');

        return $answer->json();
    });

    /* ── Step 4: the pass goes to the visitor as a picture ── */

    $step('4 · Share → WhatsApp: the invitation the picture is sent with names the estate, the lot, the day, the window and the clock', function () use ($residentToken, $pass, $must) {
        $must(is_array($pass), 'no pass from step 3');
        $answer = rehearsalApp($this, $residentToken, 'visitor_passes.share', ['pass' => $pass['id']], ['channel' => 'whatsapp']);
        $must($answer->status() === 200, 'share answered '.$answer->status().': '.$answer->json('error.message'));
        $text = (string) $answer->json('message');
        foreach (['Phoenix Park Village 1', 'Lot 1999', 'Jamaica time', (string) $pass['code']] as $needle) {
            $must(str_contains($text, $needle), 'the invitation lacks "'.$needle.'": '.$text);
        }
    });

    /* ── Step 5: Gemini's roster has the shift ── */

    $step('5 · the Director\'s Roster shows the shift at the demonstration post, Andre Campbell\'s', function () use ($director, $shift, $must) {
        $must($shift instanceof Shift, 'no shift from §2.2');
        $roster = rehearsalConsole($this, $director, 'GET', '/guards/roster');
        $must($roster->status() === 200, 'roster answered '.$roster->status());
        $json = json_encode(rehearsalProps($roster));
        $must(str_contains($json, 'Demonstration post') && str_contains($json, 'Andre Campbell'), 'the roster does not show the demonstration shift with Andre Campbell');
    });

    /* ── Step 6: the guard clocks in ── */

    $step('6 · phone 2 → Clock in: Within geofence, Device verified, On post; the Director\'s On duty shows him', function () use ($guardToken, $shift, $here, $director, $must) {
        $must($shift instanceof Shift && is_string($guardToken), 'no shift or no Guard App');
        $in = rehearsalApp($this, $guardToken, 'shifts.clock_in', ['shift' => $shift->id], ['captured_run' => 'rehearsal-run', 'captured_mono_ms' => 1_000, 'mock_location' => false, ...$here]);
        $must($in->status() === 200, 'clock_in answered '.$in->status().': '.$in->json('error.message'));
        $must(in_array($in->json('geofence_outcome'), ['inside', 'inside_margin'], true), 'geofence '.$in->json('geofence_outcome'));
        $must($shift->fresh()->status === 'on_duty', 'the shift is '.$shift->fresh()->status);
        $duty = rehearsalConsole($this, $director, 'GET', '/guards/on-duty');
        $must(str_contains(json_encode(rehearsalProps($duty)), 'Andre Campbell'), 'On duty does not show Andre Campbell');
    });

    /* ── Step 7: the guard scans the pass ── */

    $step('7 · phone 2 scans the pass: VALID PASS → Admit entry; the resident\'s pass shows arrived; a second scan says PASS ALREADY USED', function () use ($guardToken, $residentToken, $pass, $must) {
        $must(is_array($pass), 'no pass from step 3');
        $first = rehearsalApp($this, $guardToken, 'gate.verify', [], ['pass' => $pass['token']]);
        $must($first->status() === 200, 'verify answered '.$first->status().': '.$first->json('error.message'));
        $must(str_contains(strtoupper(json_encode($first->json())), 'VALID'), 'the first scan said '.json_encode($first->json('verdict') ?? $first->json()));
        $entry = rehearsalApp($this, $guardToken, 'gate.entry', [], ['pass_id' => (string) ($first->json('pass_id') ?? $pass['pass_id']), 'category' => 'Visitor']);
        $must($entry->status() === 201, 'entry answered '.$entry->status().': '.$entry->json('error.message'));
        $mine = collect(rehearsalApp($this, $residentToken, 'visitor_passes.index')->json('items'))->firstWhere('id', $pass['id']);
        $must(is_array($mine) && in_array($mine['status'] ?? null, ['used', 'arrived', 'on_site', 'admitted'], true), 'the resident\'s pass is '.json_encode($mine['status'] ?? null));
        $again = rehearsalApp($this, $guardToken, 'gate.verify', [], ['pass' => $pass['token']]);
        $must(str_contains(strtoupper(json_encode($again->json())), 'ALREADY USED'), 'the second scan said '.json_encode($again->json('verdict') ?? $again->json()));
    });

    /* ── Step 8: the resident reports a fault with a photo ── */

    $ticket = $step('8 · Maintenance → "Kitchen tap is leaking" → a photo → "1 photo attached"; Trevor\'s Facilities → Maintenance has it', function () use ($residentToken, $trevor, $must) {
        $raised = rehearsalApp($this, $residentToken, 'tickets.store', [], ['title' => 'Kitchen tap is leaking', 'category' => 'plumbing', 'description' => 'Dripping all night under the sink.', 'priority' => 'medium']);
        $must($raised->status() === 201, 'tickets.store answered '.$raised->status().': '.$raised->json('error.message'));
        $id = (int) $raised->json('id');
        rehearsalReset();
        $photo = $this->withToken($residentToken)->post('/api/v1/tickets/'.$id.'/media', ['file' => UploadedFile::fake()->image('tap.jpg', 1600, 1200)], ['Accept' => 'application/json', 'Idempotency-Key' => 'rehearsal-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()]);
        rehearsalReset();
        $must(in_array($photo->status(), [200, 201], true), 'the photo answered '.$photo->status().': '.$photo->json('error.message'));
        $list = rehearsalConsole($this, $trevor, 'GET', '/facilities/maintenance', estate: true);
        $must(str_contains(json_encode(rehearsalProps($list)), 'Kitchen tap is leaking'), 'Trevor\'s maintenance list does not have it');

        return $id;
    });

    /* ── Step 9: the office sends a supplier, then closes it ── */

    $step('9 · Trevor → Assign vendor with a visit window ahead and a technician\'s number; then Mark completed; phone 1 sees each', function () use ($trevor, $ticket, $residentToken, $estate, $zone, $must) {
        $must(is_int($ticket), 'no ticket from step 8');
        [$number, $vendor] = $estate->run(static fn () => [MaintenanceTicket::query()->findOrFail($ticket)->number, DB::connection('tenant')->table('vendors')->orderBy('id')->value('id')]);
        $must($vendor !== null, 'Phoenix Park has no vendor to assign');
        $from = now($zone)->addHour()->startOfHour();
        rehearsalConsole($this, $trevor, 'POST', '/facilities/maintenance/'.$number.'/assign', ['vendor_id' => $vendor, 'eta_starts_at' => $from->format('Y-m-d\TH:i'), 'eta_ends_at' => $from->copy()->addHours(2)->format('Y-m-d\TH:i'), 'technician_phone' => '876 555 0110'], estate: true);
        $must(rehearsalRefusal() === '', 'assigning refused: '.rehearsalRefusal());
        $seen = collect(rehearsalApp($this, $residentToken, 'tickets.index')->json('items'))->firstWhere('id', $ticket);
        $must(is_array($seen) && ($seen['status'] ?? null) === MaintenanceTicket::ASSIGNED && ($seen['eta_starts_at'] ?? null) !== null, 'phone 1 does not show the visit time: '.json_encode(['status' => $seen['status'] ?? null, 'eta' => $seen['eta_starts_at'] ?? null]));
        rehearsalConsole($this, $trevor, 'POST', '/facilities/maintenance/'.$number.'/resolve', ['resolution' => 'Washer replaced; no more drip.'], estate: true);
        $must(rehearsalRefusal() === '', 'completing refused: '.rehearsalRefusal());
        $done = collect(rehearsalApp($this, $residentToken, 'tickets.index')->json('items'))->firstWhere('id', $ticket);
        $must(($done['status'] ?? null) === MaintenanceTicket::COMPLETED, 'phone 1 shows '.json_encode($done['status'] ?? null));
    });

    /* ── Step 10: the resident confirms it is fixed ── */

    $step('10 · phone 1 → Yes, it\'s fixed; Trevor\'s screen shows it confirmed', function () use ($residentToken, $ticket, $estate, $must) {
        $must(is_int($ticket), 'no ticket from step 8');
        $answer = rehearsalApp($this, $residentToken, 'tickets.confirm_fixed', ['ticket' => $ticket]);
        $must($answer->status() === 200, 'confirm_fixed answered '.$answer->status().': '.$answer->json('error.message'));
        $status = $estate->run(static fn () => MaintenanceTicket::query()->findOrFail($ticket)->status);
        $must($status === MaintenanceTicket::VERIFIED, 'the ticket is '.$status);
    });

    /* ── Step 11: the resident books an amenity ── */

    $step('11 · Book amenity → an amenity → a date → book; under My bookings, with its fee', function () use ($residentToken, $zone, $must, &$paths) {
        $amenities = rehearsalApp($this, $residentToken, 'amenities.index');
        $must($amenities->status() === 200, 'amenities answered '.$amenities->status());
        $first = collect($amenities->json('items'))->first(fn ($a) => ($a['bookable'] ?? true) !== false);
        $must(is_array($first), 'no amenity to book');
        $day = now($zone)->addDays(3)->setTime(10, 0);
        $booked = rehearsalApp($this, $residentToken, 'bookings.store', [], ['amenity_id' => $first['id'], 'starts_at' => $day->toIso8601String(), 'ends_at' => $day->copy()->addHour()->toIso8601String(), 'guests' => 2]);
        $must($booked->status() === 201, 'booking '.$first['name'].' answered '.$booked->status().': '.$booked->json('error.message'));
        $mine = collect(rehearsalApp($this, $residentToken, 'bookings.index')->json('items'))->firstWhere('id', $booked->json('id'));
        $must(is_array($mine), 'not under My bookings');
        $must(array_key_exists('booking_fee', $mine), 'My bookings shows no fee: '.implode(',', array_keys($mine)));
        $paths[] = 'step 11: booked '.$first['name'].', '.($mine['status'] ?? '?').', fee '.json_encode($mine['booking_fee']);
    });

    /* ── Step 12: the resident pays, a marked demonstration payment ── */

    $paid = $step('12 · Pay dues with 4242 4242 4242 4242 → a receipt marked Demonstration payment, the balance down; 4000 … 0002 declines in plain words', function () use ($residentToken, $estate, $lot, $must) {
        $household = $estate->run(static fn () => Household::query()->where('unit_id', $lot->id)->value('id'));
        $before = rehearsalApp($this, $residentToken, 'households.balance', ['household' => $household]);
        $must($before->status() === 200, 'balance answered '.$before->status());
        $pay = rehearsalApp($this, $residentToken, 'payments.card', [], ['amount' => '1000.00', 'brand' => 'visa', 'last4' => '4242']);
        $must(in_array($pay->status(), [200, 201], true), 'payments.card answered '.$pay->status().': '.$pay->json('error.message'));
        $must(str_contains(strtolower(json_encode($pay->json())), 'demonstration'), 'the receipt is not marked demonstration');
        $after = rehearsalApp($this, $residentToken, 'households.balance', ['household' => $household]);
        $must(json_encode($after->json()) !== json_encode($before->json()), 'the balance did not move');
        $decline = rehearsalApp($this, $residentToken, 'payments.card', [], ['amount' => '1000.00', 'brand' => 'visa', 'last4' => '0002']);
        $must($decline->status() >= 400 && is_string($decline->json('error.message')), 'the decline card answered '.$decline->status());

        return $pay->json();
    });

    /* ── Step 13: the treasurer sees it ── */

    $step('13 · Norman → Dues → Receipts: the payment at the top, marked demonstration', function () use ($norman, $must) {
        $receipts = rehearsalConsole($this, $norman, 'GET', '/finance/receipts', estate: true);
        $must($receipts->status() === 200, 'receipts answered '.$receipts->status());
        $props = rehearsalProps($receipts);
        $json = json_encode($props);
        $rows = collect($props)->first(fn ($v) => is_array($v) && array_is_list($v) && isset($v[0]) && is_array($v[0]));
        $must(str_contains($json, 'Lot 1999'), 'the receipts do not show Lot 1999\'s payment; the first row is '.mb_substr(json_encode($rows[0] ?? null), 0, 300).'; props '.implode(',', array_keys($props)));
        $must(stripos($json, 'demonstration') !== false, 'nothing on the receipts is marked demonstration');
    });

    /* ── Step 14: the resident raises a panic ── */

    $pushesBefore = count(Http::recorded(static fn ($r) => str_contains($r->url(), 'exp.host')));
    $alert = $step('14 · phone 1 → Panic → hold: the control room has it, officers alerted; phone 2 is rung', function () use ($residentToken, $must, $pushesBefore) {
        $raised = rehearsalApp($this, $residentToken, 'duress.store', [], ['mode' => 'audible']);
        $must($raised->status() === 201, 'duress.store answered '.$raised->status().': '.$raised->json('error.message'));
        $must((int) $raised->json('alerted_handsets') >= 1, 'no officer\'s phone was alerted: '.json_encode($raised->json()));
        rehearsalWorker();
        $rung = collect(Http::recorded(static fn ($r) => str_contains($r->url(), 'exp.host')))->slice($pushesBefore);
        $must($rung->isNotEmpty(), 'nothing went to the push service');

        return (int) $raised->json('id');
    });

    /* ── Step 15: the office sees it; the control room takes it ── */

    $step('15 · Trevor\'s console has the alert; phone 2 → I\'m responding; the Director → Acknowledge; phone 1 → "Dispatch has seen your alert"', function () use ($alert, $trevor, $director, $residentToken, $guardToken, $must, $pushesBefore) {
        $must(is_int($alert), 'no alert from step 14');
        $watch = rehearsalConsole($this, $trevor, 'GET', '/security/panic-watch', estate: true);
        $must($watch->status() === 200 && str_contains((string) $watch->getContent(), (string) $alert), 'Trevor\'s console does not have alert '.$alert.' ('.$watch->status().')');
        // The ring's own ticket, out of the push the server sent phone 2.
        $ticket = collect(Http::recorded(static fn ($r) => str_contains($r->url(), 'exp.host')))->slice($pushesBefore)
            ->map(static fn ($pair) => json_encode($pair[0]->data()))->map(static fn (string $body) => preg_match('/"rt":"([^"]+)"/', $body, $m) === 1 ? $m[1] : null)->filter()->first();
        $must(is_string($ticket), 'the ring push carried no ticket to answer with');
        $answered = rehearsalApp($this, null, 'ring.answer', [], ['rt' => stripslashes($ticket), 'answer' => 'responding', 'age_ms' => 1800]);
        $must(in_array($answered->status(), [200, 202], true), 'ring.answer answered '.$answered->status().': '.$answered->json('error.message'));
        rehearsalConsole($this, $director, 'POST', '/dispatch/alerts/'.$alert.'/acknowledge');
        $must(rehearsalRefusal() === '', 'acknowledging refused: '.rehearsalRefusal());
        $seen = rehearsalApp($this, $residentToken, 'duress.show', ['alert' => $alert]);
        $must(DuressAlert::query()->findOrFail($alert)->acknowledged_at !== null, 'the alert is not acknowledged');
        $must($seen->status() === 200, 'duress.show answered '.$seen->status());
    });

    /* ── Step 16: the officer arrives and the alert is closed ── */

    $step('16 · phone 2 → On scene; the Director → Resolve & classify → Test or drill → Resolve alert; phone 1: closed', function () use ($alert, $director, $residentToken, $guardToken, $must) {
        $must(is_int($alert), 'no alert from step 14');
        $there = rehearsalApp($this, $guardToken, 'alerts.on_scene', ['alert' => $alert]);
        $must($there->status() === 200, 'on_scene answered '.$there->status().': '.$there->json('error.message'));
        rehearsalConsole($this, $director, 'POST', '/dispatch/alerts/'.$alert.'/resolve', ['classification' => 'drill', 'note' => 'Client demonstration.']);
        $must(rehearsalRefusal() === '', 'resolving refused: '.rehearsalRefusal());
        $closed = rehearsalApp($this, $residentToken, 'duress.show', ['alert' => $alert]);
        $must(in_array($closed->json('status'), ['resolved', 'closed'], true), 'phone 1 reads the alert as '.json_encode($closed->json('status')));
    });

    /* ── §5 after the demonstration ── */

    $step('§5 phone 2 → Clock out', function () use ($guardToken, $shift, $must) {
        $out = rehearsalApp($this, $guardToken, 'shifts.clock_out', ['shift' => $shift->id], ['handover_note' => 'Demonstration finished.']);
        $must($out->status() === 200, 'clock_out answered '.$out->status().': '.$out->json('error.message'));
    });

    $step('§5 the presenter\'s own email off Phoenix Park: phone 1 → Profile → Delete account', function () use ($residentToken, $must) {
        $gone = rehearsalApp($this, $residentToken, 'me.delete_account', [], ['confirm' => 'DELETE']);
        $must($gone->status() === 200, 'delete_account answered '.$gone->status().': '.$gone->json('error.message'));
    });

    fwrite(STDOUT, PHP_EOL.'DEMO_SCRIPT.md rehearsed on '.ScaleEstate::NAME.' ('.$site.', the production seed), '.now($zone)->format('D j M Y g:i A').' '.SiteTime::clockTag($zone).PHP_EOL);
    foreach ($ran as $line) {
        fwrite(STDOUT, $line.PHP_EOL);
    }
    foreach ($paths as $line) {
        fwrite(STDOUT, 'path   '.$line.PHP_EOL);
    }
    fwrite(STDOUT, count($stumbles).' stumble(s).'.PHP_EOL);

    expect($stumbles)->toBe([]);
});
