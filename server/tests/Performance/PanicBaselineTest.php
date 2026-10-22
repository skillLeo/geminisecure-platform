<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M11 A1 — a resident's panic at Phoenix Park's production size, leg by leg
|--------------------------------------------------------------------------
|
| NOT PART OF THE SUITE. Run by path against tools/perf/serve.ps1 started with -Reverb (so the
| broadcast leg is a real publish to a Reverb of the slot's own, as production publishes to its own):
|
|     $env:PERF_OUT = '<dir outside the repo>'; $env:PERF_LOG = '<server requests.jsonl>'
|     .\t.ps1 tests/Performance/PanicBaselineTest.php
|
| THE RESIDENT'S PATH, OVER HTTP. `POST /api/v1/duress` with a resident's token, answered by the
| production-shaped server — so the fan-out, the queue hand-offs, the mail, the texts and the
| broadcast run the code a real press runs, in a fresh PHP request. What is reported is read back:
| the request's own time from the server's log, and every leg's hand-off time from
| `duress_alert_deliveries`, which the platform itself writes (M10 §1.3). Nothing is predicted.
|
| HAND-OFF, NEVER ARRIVAL — the platform's own caveat. A queued leg is timed to the moment it was
| queued; no worker runs here, so nothing is ever delivered, and nothing can be said about how long
| a phone, a mailbox or a console took to show it.
|
| THE DESTINATIONS PRODUCTION HAS, MADE PRESENT: an officer on duty with a registered handset, the
| Gemini Head of Security covering the estate, the estate's own Head of Security and committee
| (all pre-verified demonstration accounts, as on the server), the site's four emergency numbers,
| and the household's own emergency contacts. There is no SMS provider on production (SMS-01), and
| none here, so every text leg is honestly "not sent".
|
*/

use App\Api\AppMatrix;
use App\Models\DevicePushToken;
use App\Models\DuressAlertDelivery;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Devices\DeviceEnrolment;
use Tests\Performance\Support\ApiProbe;
use Tests\Performance\Support\PanicLegs;
use Tests\Performance\Support\ScaleEstate;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('hands a resident\'s panic to every destination, and says how long each leg took to hand off', function (): void {
    ScaleEstate::assertSlot();

    $base = rtrim((string) (getenv('PERF_BASE') ?: 'http://127.0.0.1:8123'), '/');
    $log = (string) getenv('PERF_LOG');
    $presses = (int) (getenv('PERF_PANICS') ?: 5);
    expect($log)->not->toBe('');

    $size = ScaleEstate::size();
    expect($size['units'])->toBe(2000)->and($size['households'])->toBe(441);

    $estate = Tenant::query()->findOrFail(ScaleEstate::ESTATE);
    $site = ScaleEstate::ESTATE;

    // An officer on duty now, with a handset that has registered for push — the push leg's audience.
    $officer = Guard::query()->where('tenant_id', $site)->where('employee_number', 'GS-PPV-02')->firstOrFail();
    $onDuty = Shift::query()->where('guard_id', $officer->id)->where('status', 'on_duty')->exists();

    if (! $onDuty) {
        Shift::withoutEvents(static fn () => Shift::query()->create([
            'tenant_id' => $site, 'guard_id' => $officer->id, 'post_id' => $officer->post_id,
            'rostered_start' => now()->subHours(2), 'rostered_end' => now()->addHours(10), 'actual_start' => now()->subHours(2), 'status' => 'on_duty',
        ]));
    }

    $enrolled = app(DeviceEnrolment::class)->enrol($officer, 'Perf panic handset', ['device_uid' => 'perf-panic-handset', 'platform' => 'android', 'public_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]);

    /*
     * THE HANDSET REGISTERS FOR PUSH AS `PushTokens::register()` REGISTERS IT: bound to the access
     * token it enrolled with, and live again if an earlier run left the row revoked. Enrolling a new
     * handset revokes the officer's old push address, so on a second run over the same data the row
     * this updates is a revoked one — and a revoked address is never pushed, which would measure a
     * panic that reached no officer at all.
     */
    DevicePushToken::query()->updateOrCreate(
        ['tenant_id' => $site, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => $officer->id, 'app' => AppMatrix::GUARD],
        ['access_token_id' => (int) strtok((string) $enrolled['token'], '|'), 'expo_token' => 'ExponentPushToken[perfpanicxxxxxxxxxxxxx]', 'platform' => 'android', 'muted' => [], 'last_registered_at' => now(), 'revoked_at' => null, 'revoked_reason' => null],
    );

    // A household on the estate, and its primary resident's Resident App token.
    [$unitId, $residentId, $residentName] = $estate->run(static function (): array {
        $household = Household::query()->whereHas('residents', static fn ($q) => $q->where('is_primary', true))->orderBy('id')->firstOrFail();
        $resident = Resident::query()->where('household_id', $household->id)->where('is_primary', true)->firstOrFail();

        return [(int) $household->unit_id, (int) $resident->id, (string) $resident->full_name];
    });

    $account = ResidentAccount::query()->create([
        'tenant_id' => $site, 'channel' => 'email', 'destination' => 'perf.panic.'.bin2hex(random_bytes(4)).'@example.com', 'full_name' => $residentName,
        'resident_id' => $residentId, 'unit_id' => $unitId, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $token = $account->createToken('resident-app:perf-panic', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $probe = new ApiProbe($base, $log);

    /*
     * ONE PRESS FIRST THAT IS NOT COUNTED. The server's OPcache and the database's buffer pool
     * start cold after a restart, and production's are warm; the first press after a restart was
     * measured at 7 s where the steady state is a fraction of that. It is raised and cancelled like
     * the rest, and left out of every figure.
     */
    $warm = (new ApiProbe($base, $log))->call('duress.store', $token, body: ['mode' => 'audible'])->json('id');
    (new ApiProbe($base, $log))->call('duress.cancel', $token, ['alert' => $warm]);

    $answers = [];
    foreach (range(1, $presses) as $i) {
        $answer = $probe->call('duress.store', $token, body: ['mode' => 'audible']);
        $id = (int) $answer->json('id');
        $answers[$id] = ['alerted_handsets' => $answer->json('alerted_handsets'), 'console_alerted' => $answer->json('console_alerted'), 'escalated_contacts' => $answer->json('escalated_contacts'), 'sms_recipients' => $answer->json('sms_recipients')];

        $probe->call('duress.cancel', $token, ['alert' => $id]);
    }

    // Every leg as the platform recorded it, and the same leg of every press side by side.
    $walks = array_map(static fn (array $walk): array => [...$walk, 'answer' => $answers[$walk['alert']]], PanicLegs::walks(array_keys($answers)));
    $rows = PanicLegs::summarise($walks);

    $summary = $probe->summary()['duress.store'];
    $fanout = ApiProbe::median(array_values(array_filter(array_column($walks, 'fanout_ms'), static fn ($v): bool => $v !== null)));
    $out = ScaleEstate::out().DIRECTORY_SEPARATOR.'panic-local.json';
    file_put_contents($out, json_encode([
        'measured_at' => now()->toIso8601String(),
        'presses' => $presses,
        'broadcasting' => DuressAlertDelivery::query()->whereIn('duress_alert_id', array_column($walks, 'alert'))->where('channel', DuressAlertDelivery::BROADCAST)->pluck('outcome')->unique()->values(),
        'request' => ['server_ms_median' => $summary['server_ms_median'], 'server_ms_p95' => $summary['server_ms_p95'], 'client_ms_median' => $summary['client_ms_median'], 'queries_median' => $summary['queries_median'], 'slowest' => $summary['slowest']],
        'fanout_ms' => array_column($walks, 'fanout_ms'),
        'fanout_ms_median' => $fanout,
        'legs' => $rows,
        'walks' => $walks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    fwrite(STDOUT, PHP_EOL.sprintf('duress.store: server median %.1f ms, p95 %.1f ms, %s queries; fan-out median %s ms', $summary['server_ms_median'], $summary['server_ms_p95'], (string) $summary['queries_median'], (string) $fanout).PHP_EOL);
    foreach ($rows as $row) {
        fwrite(STDOUT, sprintf('%-44s %-9s %-8s rcpt %-4s n %d  median %-7s max %-7s %s', mb_substr($row['label'], 0, 44), $row['channel'], $row['outcome'], (string) $row['recipients'], $row['n'], (string) $row['elapsed_ms_median'], (string) $row['elapsed_ms_max'], mb_substr((string) $row['note'], 0, 70)).PHP_EOL);
    }
    fwrite(STDOUT, 'written '.$out.PHP_EOL);

    // Seven destinations, every one with at least one leg, on every press — and the officer's
    // handset actually pushed on every press, or the push leg's figure is of nothing.
    expect($walks)->toHaveCount($presses)
        ->and(PanicLegs::everyDestinationOnEveryPress($walks))->toBeTrue()
        ->and(collect($answers)->pluck('alerted_handsets')->every(static fn ($n): bool => (int) $n >= 1))->toBeTrue();
});
