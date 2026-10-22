<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Gemini\SupervisorRegister;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Who currently holds supervisor-override authority — Q-M28, D-M129
|--------------------------------------------------------------------------
|
| A guard whose handset cannot confirm who they are — a failed fingerprint, or a
| phone with no biometrics — can still start a shift if a supervisor types a PIN
| on it. The question put to the client was who should be allowed to hold that
| PIN, by name or by role. They took the recommendation: it stays with whoever the
| console already treats as making a supervisor's decision about a guard, which is
| the holder of `gemini.guard_workforce.update` — the same verb that approves a
| handset rebind, suspends an officer and reassigns them.
|
| SO THE RULE IS UNCHANGED AND THIS FILE DOES NOT RE-ARGUE IT. What the client
| added was that the office must be able to SEE who holds it, and that is the gap
| this closes: before the register, answering "who can start a shift at our gate
| without the guard's fingerprint" meant reading the role matrix, working out which
| roles carry that verb, listing each role's users and crossing off the ones whose
| scope does not reach the estate. A permission nobody can enumerate is one nobody
| reviews.
|
| THE TWO THINGS ASSERTED HERE, THEN:
|
|   1. THE REGISTER IS TRUE AND COMPLETE. Everybody who holds the permission is on
|      it, including somebody who has never set a PIN — they cannot override this
|      minute and they can set one this afternoon with nobody asked, so "who may"
|      is the question, not "who has". Nobody who lacks the permission is on it.
|      And the PIN itself is nowhere in the response, because only its hash is
|      stored and this never reads it.
|
|   2. EVERY OVERRIDE ALREADY NAMES ITS SUPERVISOR. `shifts.overridden_by`, the
|      audit entry, and the count on the register are all the same fact read three
|      ways — which is the point of reading the count off the shifts rather than
|      keeping a tally beside them.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** The register as the screen receives it, for whoever is asking. */
function registerFor(object $test, $viewer): array
{
    $rows = [];

    $test->actingAs($viewer)
        ->get('/guards/supervisors')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$rows): void {
            $page->component('Gemini/Guards/Supervisors');
            $rows = (array) $page->toArray()['props']['supervisors'];
        });

    return $rows;
}

it('lists everybody who may authorise an override, whether or not they have set a PIN', function () {
    $withPin = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $withoutPin = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    app(SupervisorOverride::class)->setPin($withPin, '482913');

    $rows = collect(registerFor($this, FacilitiesFixture::geminiViewer(Role::DIRECTOR)));

    $set = $rows->firstWhere('id', $withPin->id);
    $unset = $rows->firstWhere('id', $withoutPin->id);

    expect($set)->not->toBeNull()
        ->and($set['pin_set'])->toBeTrue()
        ->and($set['standing'])->toBe('ready')
        ->and($set['pin_set_at'])->not->toBeNull()

        /*
         * AUTHORITY WITHOUT A PIN IS STILL AUTHORITY, and a list of PIN holders
         * would have missed it. This person cannot override this minute; they can
         * set a PIN this afternoon, from their own account screen, with nobody
         * asked. The register answers "who may", not "who has".
         */
        ->and($unset)->not->toBeNull()
        ->and($unset['pin_set'])->toBeFalse()
        ->and($unset['standing'])->toBe('no-pin')
        ->and($unset['last_used_at'])->toBeNull();
});

it('leaves out a console user who does not hold the permission', function () {
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    expect($accountant->can(SupervisorOverride::PERMISSION))->toBeFalse(
        'the fixture role has changed; pick a Gemini role that does NOT carry the supervisor verb'
    );

    $rows = collect(registerFor($this, FacilitiesFixture::geminiViewer(Role::DIRECTOR)));

    expect($rows->pluck('id'))->not->toContain($accountant->id);
});

it('never puts a PIN, or anything a PIN could be worked back from, on the screen', function () {
    $supervisor = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    app(SupervisorOverride::class)->setPin($supervisor, '482913');

    $hash = (string) DB::connection('mysql')->table('supervisor_override_pins')
        ->where('user_id', $supervisor->id)
        ->value('pin_hash');

    $body = $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->get('/guards/supervisors')
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('482913')
        ->and($body)->not->toContain($hash)
        ->and($body)->not->toContain('pin_hash');
});

it('shows a locked PIN as locked, and counts the wrong tries that locked it', function () {
    $supervisor = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $override = app(SupervisorOverride::class);
    $override->setPin($supervisor, '482913');

    /*
     * Through `verify()` rather than by writing the columns, so what the register
     * reports is what actually happens to a supervisor whose PIN is being guessed
     * at on a handset — five wrong tries and it is locked until they set a new one.
     */
    for ($try = 0; $try < SupervisorOverride::MAX_FAILED_ATTEMPTS; $try++) {
        expect($override->verify((string) $supervisor->email, '000000', FacilitiesFixture::ESTATE))->toBeNull();
    }

    $row = collect(registerFor($this, FacilitiesFixture::geminiViewer(Role::DIRECTOR)))
        ->firstWhere('id', $supervisor->id);

    expect($row['pin_locked'])->toBeTrue()
        ->and($row['standing'])->toBe('locked')
        ->and($row['failed_attempts'])->toBe(SupervisorOverride::MAX_FAILED_ATTEMPTS);
});

it('records which supervisor authorised every override, and counts them on the register', function () {
    $supervisor = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    app(SupervisorOverride::class)->setPin($supervisor, '482913');

    $post = Post::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'name' => 'Register Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $guard = Guard::create([
        'full_name' => 'Kemar Vouched',
        'employee_number' => 'GS-REG-1',
        'psra_number' => 'PSRA-GS-REG-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $post->id,
    ]);

    $shift = Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'guard_id' => $guard->id,
        'post_id' => $post->id,
        'rostered_start' => now()->subMinutes(10),
        'rostered_end' => now()->addHours(12),
        'actual_start' => now()->subMinutes(5),
        'status' => 'on_duty',
        'start_method' => SupervisorOverride::START_METHOD,
    ]);

    app(SupervisorOverride::class)->record($shift, $supervisor, $guard, SupervisorOverride::REASON_BIOMETRIC_FAILED);

    // ON THE SHIFT ITSELF — the row an insurer or a payroll query reads.
    expect((int) $shift->refresh()->overridden_by)->toBe($supervisor->id)
        ->and($shift->override_reason)->toBe(SupervisorOverride::REASON_BIOMETRIC_FAILED)
        ->and($shift->overridden_at)->not->toBeNull();

    // AND IN THE AUDIT LOG, BY NAME AS WELL AS BY ID — a supervisor can leave the
    // company; the record of who vouched for this guard cannot leave with them.
    $entry = AuditEntry::query()
        ->where('action', 'shift.supervisor_override')
        ->where('entity_id', (string) $shift->id)
        ->sole();

    expect($entry->after['overridden_by'])->toBe($supervisor->id)
        ->and($entry->after['overridden_by_name'])->toBe($supervisor->name);

    /*
     * AND THE SAME FACT, COUNTED, ON THE REGISTER. Read off `shifts.overridden_by`
     * rather than from a tally kept beside it, so the number on this screen cannot
     * disagree with the audit log or with the Head of Security's own list.
     */
    $row = collect(registerFor($this, FacilitiesFixture::geminiViewer(Role::DIRECTOR)))
        ->firstWhere('id', $supervisor->id);

    expect($row['authorised'])->toBe(1)
        ->and(SupervisorRegister::WINDOW_DAYS)->toBe(SupervisorOverride::REPORT_DAYS);
});

it('is refused to a role that may not read the guard workforce', function () {
    /*
     * FOUND AT RUNTIME, NOT NAMED. This test used to hard-code the accountant as "a role that cannot
     * read the roster" - and the accountant CAN read it, which is right, because payroll is worked
     * from the roster. So it failed on an assumption about the permission matrix rather than on the
     * behaviour it exists to check. It now asks the matrix which console role lacks the verb and uses
     * the first; if a future matrix ever grants it to every role, it falls back to a console user with
     * no role at all, who certainly lacks it. The claim under test is unchanged: somebody who may not
     * read the guard workforce is refused the register of who holds override power.
     */
    $outsider = null;
    foreach ([Role::ACCOUNTANT, Role::ADMIN_ASSISTANT, Role::DISPATCHER, Role::HEAD_OF_SECURITY, Role::OPERATIONS_MANAGER] as $role) {
        $user = FacilitiesFixture::geminiViewer($role);
        if (! $user->can('gemini.guard_workforce.view')) {
            $outsider = $user;
            break;
        }
    }
    if ($outsider === null) {
        $outsider = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
        $outsider->syncRoles([]);
        $outsider = $outsider->fresh();
    }

    expect($outsider->can('gemini.guard_workforce.view'))->toBeFalse();

    $this->actingAs($outsider)->get('/guards/supervisors')->assertForbidden();
});
