<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| A ticket made in the last hour reads "Just now" (FAC-14, register DR-086)
|--------------------------------------------------------------------------
|
| A ticket raised seconds ago read Age "1 hour", which is an hour it had not been waiting. Under an hour it now reads
| "Just now"; from the first full hour it counts hours and then days, as before.
|
*/

function agedTicket(Carbon $reported): MaintenanceTicket
{
    return (new MaintenanceTicket)->forceFill(['number' => 1099, 'title' => 'Gate light out', 'status' => MaintenanceTicket::SUBMITTED, 'reported_at' => $reported]);
}

it('reads "Just now" under an hour, and hours from the first full hour', function () {
    $reported = Carbon::parse('2026-10-11 09:00:00', 'UTC');

    expect(agedTicket($reported)->ageLabel($reported->copy()->addSeconds(10)))->toBe('Just now')
        ->and(agedTicket($reported)->ageLabel($reported->copy()->addMinutes(59)))->toBe('Just now')
        ->and(agedTicket($reported)->ageLabel($reported->copy()->addHour()))->toBe('1 hour')
        ->and(agedTicket($reported)->ageLabel($reported->copy()->addHours(5)))->toBe('5 hours')
        ->and(agedTicket($reported)->ageLabel($reported->copy()->addDays(2)))->toBe('2 days');
});
