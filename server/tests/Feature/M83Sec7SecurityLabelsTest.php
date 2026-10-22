<?php

declare(strict_types=1);

use App\Models\DuressAlert;
use App\Models\SecurityIncident;
use App\Services\Estate\EstateSecurity;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M83 §1.2 item 4, SEC-7: the Security screen says a word, never a stored code (10 October 2026)
|--------------------------------------------------------------------------
|
| The register's words: "open" beside "Closed", "suspicious" and "property" beside "Fire", and "Raised by Unknown / Lot
| Not supplied". An incident's kind is what the guard typed; Phoenix Park's two sample incidents carry the codes
| `suspicious` and `property`. An incident reads Open or Closed. A lot or a name nobody recorded says so.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('labels an incident\'s kind and whether it is open, never the stored code', function () {
    foreach ([['suspicious', 'closed', now()->subHours(3)], ['property', 'open', null], ['fire', 'open', null], ['Attempted entry', 'open', null]] as [$kind, $status, $closedAt]) {
        SecurityIncident::query()->forceCreate([
            'tenant_id' => FacilitiesFixture::ESTATE,
            'kind' => $kind,
            'detail' => 'SEC-7 '.$kind,
            'severity' => 'low',
            'status' => $status,
            'occurred_at' => now()->subHours(4),
            'closed_at' => $closedAt,
        ]);
    }

    $rows = collect(app(EstateSecurity::class)->incidents(FacilitiesFixture::ESTATE))->keyBy('detail');

    expect($rows['SEC-7 suspicious'])->toMatchArray(['kind_label' => 'Suspicious activity', 'status_label' => 'Closed'])
        // An open incident in the vocabulary's words (M98 §3.5): it waits on somebody, Action required.
        ->and($rows['SEC-7 property'])->toMatchArray(['kind_label' => 'Estate property', 'status_label' => 'Action required'])
        // What a guard typed reads as typed, with a capital.
        ->and($rows['SEC-7 fire']['kind_label'])->toBe('Fire')
        ->and($rows['SEC-7 Attempted entry']['kind_label'])->toBe('Attempted entry');

    // The screen draws the labels, not the codes.
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Security/Index.vue'));

    expect(str_contains($page, '{{ incident.kind_label }}'))->toBeTrue()
        ->and(str_contains($page, '{{ incident.status_label }}'))->toBeTrue()
        ->and(str_contains($page, 'Incident · ${i.kind_label}'))->toBeTrue();
});

it('says a lot or a name was not recorded, never "Not supplied" or "Unknown"', function () {
    $alert = DuressAlert::query()->forceCreate([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'kind' => 'panic',
        'raised_by_name' => null,
        'unit_reference' => null,
        'status' => 'resolved',
        'server_time' => now()->subHour(),
        'resolved_at' => now()->subMinutes(30),
        'is_simulated' => false,
    ]);

    $security = app(EstateSecurity::class);
    $page = $security->alert(FacilitiesFixture::ESTATE, (int) $alert->id);
    $row = collect($security->alerts(FacilitiesFixture::ESTATE))->firstWhere('id', $alert->id);

    expect($page)->toMatchArray(['unit' => 'Lot not recorded', 'who' => 'Name not recorded'])
        ->and($page['steps'][0]['detail'])->toBe('Name not recorded')
        ->and($row)->toMatchArray(['unit' => 'Lot not recorded', 'who' => 'Name not recorded']);
});
