<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Guard;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A post removed, and officers given a post, from the command line (4 October 2026)
|--------------------------------------------------------------------------
|
| The console's Remove and Reassign, for an operator who is told to and signs in as nobody: estate:remove-post takes
| a post away by name with a reason, through the very removal the console makes; estate:post-officers gives named
| officers a post of their own estate. Both say what they would do and change nothing without --confirm, and both
| are audited, by "System", with the reason given.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    Carbon::setTestNow(Carbon::parse('2026-10-13 15:00:00', 'UTC'));

    $this->key = 'postcmdtest';
    DB::connection('mysql')->table('tenants')->insert(['id' => $this->key, 'name' => 'Post Commands Test', 'receipt_prefix' => 'PCMD', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $this->main = Post::create(['tenant_id' => $this->key, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $this->back = Post::create(['tenant_id' => $this->key, 'name' => 'Back Gate', 'also_called' => 'Gate 5, Phase 5 Gate', 'type' => 'gate', 'is_active' => true]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    Carbon::setTestNow();
});

function postCommandsOfficer(string $number, ?Post $post, string $status = 'active', ?string $estate = null): Guard
{
    return Guard::create([
        'full_name' => 'Officer '.$number, 'employee_number' => $number, 'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => $status,
        'tenant_id' => $estate ?? test()->key, 'post_id' => $post?->id,
    ]);
}

/* ── estate:remove-post ──────────────────────────────────────────────────────────────────────────────────── */

it('removes a post by name with the reason given, through the console\'s own removal, and says first what it would do', function () {
    $bla = Post::create(['tenant_id' => $this->key, 'name' => 'bla', 'type' => 'gate', 'is_active' => true]);
    $bla->forceFill(['latitude' => 18.02, 'longitude' => -76.81, 'geofence_radius_m' => 50])->save();
    $reason = 'test post, removed before the client demonstration';

    $this->artisan('estate:remove-post', ['--estate' => $this->key, '--post' => 'bla', '--reason' => $reason])
        ->expectsOutputToContain('Nothing is changed without --confirm.')
        ->expectsOutputToContain('bla would be deleted: nothing was ever done at it.')
        ->assertSuccessful();
    expect(Post::query()->whereKey($bla->id)->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'site.post_removed')->where('entity_id', (string) $bla->id)->exists())->toBeFalse();

    $this->artisan('estate:remove-post', ['--estate' => $this->key, '--post' => 'bla', '--reason' => $reason, '--confirm' => true])
        ->expectsOutputToContain('bla is deleted: nothing was ever done at it.')
        ->expectsOutputToContain('Done. Its posts now: Back Gate (Gate 5, Phase 5 Gate) (not placed), Main Gate (not placed).')
        ->assertSuccessful();

    $entry = AuditEntry::query()->where('action', 'site.post_removed')->where('entity_id', (string) $bla->id)->firstOrFail();
    expect(Post::query()->whereKey($bla->id)->exists())->toBeFalse()
        ->and($entry->after['reason'])->toBe($reason)
        ->and($entry->after['outcome'])->toBe('deleted')
        ->and($entry->tenant_id)->toBe($this->key)
        ->and($entry->actor_id)->toBeNull();

    // Gone, so it is not found again, and the refusal lists what the estate has.
    $this->artisan('estate:remove-post', ['--estate' => $this->key, '--post' => 'bla', '--reason' => $reason, '--confirm' => true])
        ->expectsOutputToContain('has no post "bla". Nothing is removed. Its posts: Back Gate (Gate 5, Phase 5 Gate) (not placed), Main Gate (not placed).')
        ->assertFailed();
});

it('retires a post with a history, and its preview says exactly what the removal then does', function () {
    $side = Post::create(['tenant_id' => $this->key, 'name' => 'Side Gate', 'type' => 'gate', 'is_active' => true]);
    $officer = postCommandsOfficer('GS-PCMD-9', $side);
    Shift::create(['tenant_id' => $this->key, 'guard_id' => $officer->id, 'post_id' => $side->id, 'rostered_start' => now()->subDay(), 'rostered_end' => now()->subDay()->addHours(8), 'actual_start' => now()->subDay(), 'actual_end' => now()->subDay()->addHours(8), 'status' => 'completed']);
    Shift::create(['tenant_id' => $this->key, 'post_id' => $side->id, 'rostered_start' => now()->addDay(), 'rostered_end' => now()->addDay()->addHours(8), 'status' => Shift::OPEN]);
    PatrolCheckpoint::query()->create(['tenant_id' => $this->key, 'post_id' => $side->id, 'label' => 'Side fence', 'code' => PatrolCheckpoint::newCode(), 'sequence' => 1, 'is_active' => true]);

    expect(Artisan::call('estate:remove-post', ['--estate' => $this->key, '--post' => 'side gate', '--reason' => 'closed for good']))->toBe(0)
        ->and(preg_replace('/\s+/', ' ', Artisan::output()))
        ->toContain('Side Gate would be retired')
        ->toContain('1 shift not yet started there would be taken off the roster; 1 officer who has it as their post would have none; 1 checkpoint on it would join the estate\'s own patrol route, with the same tags.');

    expect(Artisan::call('estate:remove-post', ['--estate' => $this->key, '--post' => 'side gate', '--reason' => 'closed for good', '--confirm' => true]))->toBe(0)
        ->and(preg_replace('/\s+/', ' ', Artisan::output()))
        ->toContain('Side Gate is retired')
        ->toContain('1 shift not yet started there is taken off the roster; 1 officer who had it as their post now has none: post them in Guard workforce; 1 checkpoint on it is now on the estate\'s own patrol route, with the same tags.');

    expect($side->refresh()->is_active)->toBeFalse()
        ->and(Shift::query()->where('post_id', $side->id)->count())->toBe(1)
        ->and($officer->refresh()->post_id)->toBeNull();
});

it('refuses an estate that does not exist, a post it cannot find, and a reason too short to read, removing nothing', function () {
    $this->artisan('estate:remove-post', ['--estate' => 'nosuchestate', '--post' => 'Main Gate', '--reason' => 'why not', '--confirm' => true])->assertFailed();
    $this->artisan('estate:remove-post', ['--estate' => $this->key, '--post' => 'North Gate', '--reason' => 'why not', '--confirm' => true])->assertFailed();
    $this->artisan('estate:remove-post', ['--estate' => $this->key, '--post' => 'Main Gate', '--reason' => 'no', '--confirm' => true])->assertFailed();

    expect(Post::query()->whereKey($this->main->id)->where('is_active', true)->exists())->toBeTrue();
});

/* ── estate:post-officers ────────────────────────────────────────────────────────────────────────────────── */

it('gives named officers of the estate a post, says first what would change, and audits each move with the reason', function () {
    $first = postCommandsOfficer('GS-PCMD-1', null);
    $second = postCommandsOfficer('GS-PCMD-2', null);
    $already = postCommandsOfficer('GS-PCMD-3', $this->main);
    $reason = 'their post was removed; the patrol starts at the Main Gate';
    $run = ['--estate' => $this->key, '--post' => 'Main Gate', '--officer' => ['GS-PCMD-1', 'gs-pcmd-2', 'GS-PCMD-3'], '--reason' => $reason];

    $this->artisan('estate:post-officers', $run)
        ->expectsOutputToContain('Nothing is changed without --confirm.')
        ->expectsOutputToContain('GS-PCMD-1 Officer GS-PCMD-1: no post → Main Gate')
        ->expectsOutputToContain('GS-PCMD-3 Officer GS-PCMD-3: already has Main Gate')
        ->assertSuccessful();
    expect($first->refresh()->post_id)->toBeNull();

    $this->artisan('estate:post-officers', [...$run, '--confirm' => true])
        ->expectsOutputToContain('Done. 2 officers now have Main Gate as their post. Its officers by post now: Main Gate 3.')
        ->assertSuccessful();

    expect($first->refresh()->post_id)->toBe($this->main->id)
        ->and($second->refresh()->post_id)->toBe($this->main->id);

    $entry = AuditEntry::query()->where('action', 'guard.reassigned')->where('entity_id', (string) $first->id)->firstOrFail();
    expect($entry->before)->toEqualCanonicalizing(['tenant_id' => $this->key, 'post_id' => null])
        ->and($entry->after['post_id'])->toBe($this->main->id)
        ->and($entry->after['reason'])->toBe($reason)
        ->and($entry->tenant_id)->toBe($this->key)
        ->and($entry->actor_id)->toBeNull()
        ->and(AuditEntry::query()->where('action', 'guard.reassigned')->where('entity_id', (string) $already->id)->exists())->toBeFalse();

    // Once more: there is nothing left to do.
    $this->artisan('estate:post-officers', [...$run, '--confirm' => true])->expectsOutputToContain('Nothing to change.')->assertSuccessful();
});

it('refuses the whole run, moving nobody, for an officer of another estate, a suspended officer, an unknown number or a post the estate lacks', function () {
    $ours = postCommandsOfficer('GS-PCMD-4', null);
    postCommandsOfficer('GS-PCMD-5', null, estate: FacilitiesFixture::ESTATE);
    postCommandsOfficer('GS-PCMD-6', null, status: 'suspended');
    $run = fn (array $officers, string $post = 'Main Gate'): array => ['--estate' => $this->key, '--post' => $post, '--officer' => $officers, '--reason' => 'test run', '--confirm' => true];

    $this->artisan('estate:post-officers', $run(['GS-PCMD-4', 'GS-PCMD-5']))->expectsOutputToContain('works for another client')->assertFailed();
    $this->artisan('estate:post-officers', $run(['GS-PCMD-4', 'GS-PCMD-6']))->expectsOutputToContain('suspended from duty')->assertFailed();
    $this->artisan('estate:post-officers', $run(['GS-PCMD-4', 'GS-NOBODY']))->expectsOutputToContain('no officer has that number')->assertFailed();
    $this->artisan('estate:post-officers', $run(['GS-PCMD-4'], 'Phase 9 Gate'))->assertFailed();
    $this->artisan('estate:post-officers', $run([]))->assertFailed();

    expect($ours->refresh()->post_id)->toBeNull();
});
