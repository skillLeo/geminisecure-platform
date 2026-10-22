<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M11 A1 — `demo.enter`, the one Resident App endpoint the main walk cannot reach
|--------------------------------------------------------------------------
|
| NOT PART OF THE SUITE. Run by path against tools/perf/serve.ps1 (tools/perf/sequence.ps1 -Steps demo):
|
|     $env:PERF_OUT = '<dir>'; $env:PERF_LOG = '<server requests.jsonl>'
|     .\t.ps1 tests/Performance/DemoEnterBaselineTest.php
|
| "Explore a demo community" mints a read-only token for the store reviewer's account in the
| demonstration estate. ApiBaselineTest leaves it out because the slot's demonstration estate is
| built empty (ScaleEstate::OTHERS). This gives that estate the one household `demo:seed` gives the
| reviewer — Lot 1, a primary resident, the reviewer's account on it — and measures the call.
|
| NOT MEASURED ON PRODUCTION, ON PURPOSE: each call deletes the reviewer's previous demo token, so
| calling it there would sign out an App Store or Play reviewer who is using the app at that moment.
|
*/

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Services\Reviewer\ReviewerMode;
use Illuminate\Support\Carbon;
use Tests\Performance\Support\ApiProbe;
use Tests\Performance\Support\ScaleEstate;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('measures the demonstration community door, demo.enter', function (): void {
    ScaleEstate::assertSlot();

    $base = rtrim((string) (getenv('PERF_BASE') ?: 'http://127.0.0.1:8123'), '/');
    $log = (string) getenv('PERF_LOG');
    expect($log)->not->toBe('');

    $estate = ReviewerMode::estate();
    expect($estate)->not->toBeNull('the slot has no demonstration estate');

    // The reviewer's home, as demo:seed makes it: Lot 1, one household, its primary resident.
    $home = $estate->run(static function (): array {
        $unit = Unit::query()->updateOrCreate(['reference' => 'Lot 1'], ['block' => 'Phase 1', 'street' => 'Demonstration Way', 'type' => 'residential', 'status' => 'occupied']);
        $household = Household::query()->updateOrCreate(['unit_id' => $unit->id], ['name' => 'Rowe household (demonstration)', 'access_restricted' => false]);
        $resident = Resident::query()->updateOrCreate(
            ['household_id' => $household->id, 'email' => 'rowe@demo.geminisecure.app'],
            ['full_name' => 'Rowe (demonstration resident)', 'phone' => '876-555-0200', 'relationship' => 'owner', 'is_primary' => true],
        );

        return ['unit' => (int) $unit->id, 'resident' => (int) $resident->id];
    });

    $account = ResidentAccount::query()->updateOrCreate(
        ['tenant_id' => $estate->getTenantKey(), 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL],
        ['full_name' => ReviewerMode::RESIDENT_NAME, 'status' => ResidentAccount::ACTIVE, 'resident_id' => $home['resident'], 'unit_id' => $home['unit'], 'verified_at' => Carbon::now()],
    );
    $account->forceFill(['reviewer' => true, 'deleted_at' => null, 'deleted_reference' => null])->save();

    $probe = new ApiProbe($base, $log);
    $answer = $probe->call('demo.enter', null, runs: 10, warm: true);

    expect($answer->json('read_only'))->toBeTrue()
        ->and($answer->json('estate.id'))->toBe($estate->getTenantKey());

    $out = ScaleEstate::out().DIRECTORY_SEPARATOR.'api-demo.json';
    file_put_contents($out, json_encode([
        'measured_at' => now()->toIso8601String(),
        'endpoints' => $probe->summary(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $row = $probe->summary()['demo.enter'];
    fwrite(STDOUT, PHP_EOL.sprintf('demo.enter: n %d, server median %.1f ms, p95 %.1f ms, %s queries, %s bytes', $row['n'], $row['server_ms_median'], $row['server_ms_p95'], (string) $row['queries_median'], (string) $row['bytes_median']).PHP_EOL.'written '.$out.PHP_EOL);
});
