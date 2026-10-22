<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| EVERY RING QUERY NAMES THE CENTRAL DATABASE, AND KEEPS PHP'S CLOCK — G5, G8
|--------------------------------------------------------------------------
|
| A round queued from inside the `/api/v1/duress` request runs with that estate open, and
| there the default connection is the ESTATE's, which has none of the ring's tables: a
| bare `DB::table(...)` would throw, be rescued, and ring nobody. And the tests travel a
| fake clock that MySQL's `NOW()` does not follow — on a machine whose clock is not UTC it
| is hours out — so no ring statement may use the database's clock at all.
|
| Every behaviour test in this folder already runs its jobs with the estate open; this
| is the gate that keeps a future edit from reintroducing either trap.
|
*/

function ringSourceFiles(): array
{
    $files = [
        ...glob(app_path('Services/Ring/*.php')) ?: [],
        ...glob(app_path('Jobs/Ring/*.php')) ?: [],
        app_path('Console/Commands/RingSweep.php'),
        app_path('Jobs/Alerts/SendRingEscalationSms.php'),
        app_path('Http/Controllers/Api/V1/RingController.php'),
    ];

    expect(count($files))->toBeGreaterThan(12);

    return $files;
}

it('touches the database only through central models or a named connection', function () {
    foreach (ringSourceFiles() as $file) {
        $source = (string) file_get_contents($file);

        expect(preg_match('/DB::(table|update|insert|statement|select|delete|unprepared)\s*\(/', $source))
            ->toBe(0, basename($file).' queries the default connection, which is the ESTATE\'s inside a request or a job queued from one');
    }
});

it('never asks MySQL for the time', function () {
    foreach (ringSourceFiles() as $file) {
        $source = (string) file_get_contents($file);

        // SQL's own clock, as SQL writes it — never PHP's `Carbon::now()` or `now()`, which is the clock the ring keeps.
        expect(preg_match('/(?<![:>\w$])(NOW|CURRENT_TIMESTAMP|UTC_TIMESTAMP|SYSDATE)\s*\(|\bCURRENT_TIMESTAMP\b|\bINTERVAL\s+[\'"\d?]/', $source))
            ->toBe(0, basename($file).' uses the database\'s clock; bind Carbon::now() instead');
    }
});

it('reads and writes every ring table through a model on the central connection', function () {
    foreach ([App\Models\DuressAlertRing::class, App\Models\DuressAlertRingRound::class, App\Models\DuressAlertRingTarget::class, App\Models\DuressAlertRingEvent::class] as $model) {
        expect(class_uses_recursive($model))->toContain(Stancl\Tenancy\Database\Concerns\CentralConnection::class);
    }
});
