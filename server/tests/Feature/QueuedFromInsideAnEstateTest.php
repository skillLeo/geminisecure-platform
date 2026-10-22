<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\SupervisorOverrideUsed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Anything queued inside an estate is written to the platform's jobs table
|--------------------------------------------------------------------------
|
| THE TRAP (B097). With the `database` driver the jobs table is looked up on
| whatever connection is the DEFAULT when the queue is first used — and for the
| whole of an estate request, and of every /api/v1 request, tenancy makes that
| the estate's own database. An estate database has no `jobs` table, so the write
| throws; where the caller rescued it, the work simply never happened.
|
| `CentralQueue::pin()` corrects one queued thing at a time, and a thing nobody
| remembered to pin was lost: a statement 500'd, and the Head of Security's mail
| about a supervisor override at clock-in — sent from inside the guard's API
| request and rescued — never left. The `database` connection now NAMES the
| platform's database, so the default connection no longer decides where a job
| is written, pinned or not.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();
});

it('writes work queued from inside an estate to the platform\'s jobs table, even when nothing pinned it', function () {
    config(['queue.default' => 'database']);

    $head = User::factory()->create();
    $jobs = DB::connection('mysql')->table('jobs');
    $before = (clone $jobs)->count();

    // Inside the estate, as tenancy leaves it for an estate or /api/v1 request.
    tenancy()->initialize(FacilitiesFixture::platform());

    expect(DB::getDefaultConnection())->toBe('tenant')
        ->and(DB::connection('tenant')->getSchemaBuilder()->hasTable('jobs'))->toBeFalse();

    // The mail `SupervisorOverride::report()` sends, exactly as it sends it: queued,
    // unpinned, from inside the guard's request.
    Notification::send($head, new SupervisorOverrideUsed(
        'Kemar Override', 'GS-OVR-1', 'Phoenix Park', 'Override Gate', 'Dana Dispatcher',
        'biometric_failed', 'Sep 20, 2026 7:02 AM', null, false, 41,
    ));

    // And the plainest thing that can be queued at all.
    dispatch(static function (): void {});

    tenancy()->end();

    $written = (clone $jobs)->orderByDesc('id')->limit(2)->pluck('payload')
        ->map(static fn (string $payload): array => json_decode($payload, true))
        ->all();

    expect((clone $jobs)->count())->toBe($before + 2)
        ->and(array_column($written, 'displayName'))->toContain(SupervisorOverrideUsed::class)
        ->and(array_column($written, 'tenant_id'))->toBe([FacilitiesFixture::ESTATE, FacilitiesFixture::ESTATE]);
});

it('names the platform database on the database queue rather than following the default', function () {
    expect(config('queue.connections.database.connection'))->toBe(config('tenancy.database.central_connection'))
        ->and(config('queue.connections.central.connection'))->toBe(config('tenancy.database.central_connection'));
});
