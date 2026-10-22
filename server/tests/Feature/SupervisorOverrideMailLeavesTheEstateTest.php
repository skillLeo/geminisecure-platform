<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Notifications\SupervisorOverrideUsed;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\SupervisorOverride;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| THE SUPERVISOR-OVERRIDE MAIL IS WRITTEN WHERE A WORKER CAN READ IT (bug hunt B098)
|--------------------------------------------------------------------------
|
| "Every use ... is reported to the Head of Security" (M4 §3.4). The override is
| recorded from `POST /api/v1/shifts/{shift}/clock-in/override`, where
| `ResolveDevice` has opened the ESTATE: tenancy has made the estate's database the
| default, and an estate database has no `jobs` table. The mail was queued unpinned,
| so on the production `database` queue it was written to the estate, threw, was
| rescued, and no Head of Security was ever told. `Support\CentralQueue` is the one
| fix every other queued send from inside an estate already uses.
|
| Proven through the real endpoint, so the estate really is open when the mail is
| queued, with the queue faked so the job the worker would read can be inspected.
|
*/

const SUPERVISOR_MAIL_PIN = '604817';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // Nobody else covering the estate: the Head of Security below is the only one told.
    EstateAssignment::query()->where('tenant_id', FacilitiesFixture::ESTATE)->update(['is_active' => false]);

    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Mail Gate', 'type' => 'gate', 'is_active' => true]);

    $this->guard = Guard::create([
        'full_name' => 'Kemar Mailcheck', 'employee_number' => 'GS-MAIL-1', 'psra_number' => 'PSRA-GS-MAIL-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);

    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Mail handset')['token'];

    $this->shift = Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $this->guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(12), 'status' => 'rostered',
    ]);

    $this->supervisor = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    app(SupervisorOverride::class)->setPin($this->supervisor, SUPERVISOR_MAIL_PIN);

    $this->head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create(['user_id' => $this->head->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

it('queues the override mail to the Head of Security on the central connection, from inside the estate the handset opened', function () {
    Queue::fake();

    $entry = Catalogue::endpoints()['shifts.clock_in_override'];
    app('auth')->forgetGuards();

    $this->withToken($this->token)->json($entry['method'], '/api/v1/'.str_replace('{shift}', (string) $this->shift->id, $entry['uri']), [
        'supervisor' => $this->supervisor->email,
        'pin' => SUPERVISOR_MAIL_PIN,
        'reason' => 'biometric_failed',
        'mock_location' => false,
    ], [
        'Idempotency-Key' => 'supmail-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ])->assertStatus($entry['status']);

    FacilitiesFixture::boot();

    // The shift really started on the PIN, so the report really ran.
    expect(DB::connection('mysql')->table('shifts')->where('id', $this->shift->id)->value('start_method'))->toBe(SupervisorOverride::START_METHOD);

    $jobs = Queue::pushedJobs()[SendQueuedNotifications::class] ?? [];
    $mails = array_values(array_filter($jobs, static fn (array $pushed): bool => $pushed['job']->notification instanceof SupervisorOverrideUsed));

    expect($mails)->toHaveCount(1);

    // WHERE A WORKER READS IT: the central `jobs` table, named — never "the default", which is the estate here.
    expect($mails[0]['job']->connection)->toBe(config('queue.central_connection', 'central'))
        ->and($mails[0]['job']->notifiables->pluck('id')->all())->toBe([$this->head->id]);
});
