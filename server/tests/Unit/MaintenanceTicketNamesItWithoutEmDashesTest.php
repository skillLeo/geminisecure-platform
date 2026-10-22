<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;

/*
|--------------------------------------------------------------------------
| A work order is named without an em dash (M53 Law 3; Sunday step 6, DR-127)
|--------------------------------------------------------------------------
|
| The work order page's title read "Ticket #1066 — QA-20261009 rehearsal: …", and the queue's ticket cell, a bill's
| reference and the refusals that name a ticket all read "#1042 — Gate lighting": `MaintenanceTicket::label()` wrote
| the em dash once for all of them. They say it with the console's separator, the middot, as the household page and
| the dashboard already do ("Ticket #1063 · Gate light out", HouseholdActivitySaysItWithoutEmDashesTest).
|
*/

function emDashFreeTicket(): MaintenanceTicket
{
    return (new MaintenanceTicket)->forceFill(['number' => 1042, 'title' => 'Gate lighting', 'location_label' => 'Phase 2 · visitor parking']);
}

it('names a work order "#1042 · Gate lighting", never with an em dash', function () {
    expect(emDashFreeTicket()->label())->toBe('#1042 · Gate lighting')
        ->and(str_contains(emDashFreeTicket()->label(), '—'))->toBeFalse();
});

it('heads its summary "Gate lighting · Phase 2 visitor parking", never with an em dash', function () {
    expect(emDashFreeTicket()->summaryTitle())->toBe('Gate lighting · Phase 2 visitor parking')
        ->and(str_contains(emDashFreeTicket()->summaryTitle(), '—'))->toBeFalse();
});
