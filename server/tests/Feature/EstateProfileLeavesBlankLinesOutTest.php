<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| The estate profile leaves a blank line out, and never invents one (Hassam, 10 October, M73's preamble)
|--------------------------------------------------------------------------
|
| Phoenix Park's address, enquiries email and phone are Hassam's to give (he is asking Omar). Until each is in hand the
| profile draws no empty label and no placeholder for it, and nothing is made up to fill it. The address is the client
| record's, shown and never typed here, so a blank one is a line with nothing to read: it is left out. The email and
| phone are the boxes the estate types them into, so they stay. On an estate of this file's own, whose client record
| starts with no address at all.
|
*/

function profileLinesEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('profilelinestest', 'PLT', 'Profile Lines Test Estate');
}

beforeEach(function (): void {
    profileLinesEstate()->boot();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/**
 * The profile page's props as the Community Super Admin opens it, with the client record's address set to these parts.
 *
 * @return array<string, mixed>
 */
function profileLinesProps(object $test, ?string $line, ?string $parish): array
{
    DB::connection('mysql')->table('tenants')->where('id', 'profilelinestest')->update(['address_line' => $line, 'parish' => $parish]);

    $props = [];
    $test->actingAs(profileLinesEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN))
        ->get(profileLinesEstate()->url('/settings/profile'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $page->component('Estate/Settings/Profile');
            $props = $page->toArray()['props'];
        });

    return $props;
}

/**
 * @param  array<string, mixed>  $props
 * @return array<string, array<string, mixed>>
 */
function profileLinesFields(array $props): array
{
    return collect($props['groups'])->flatMap(fn (array $group): array => $group['fields'])->keyBy('key')->all();
}

it('draws no Address line when the client record holds none, and keeps the boxes the estate types its contact into', function (): void {
    $props = profileLinesProps($this, null, null);
    $fields = profileLinesFields($props);

    expect($fields)->not->toHaveKey('address')
        ->and($props['estate']['address'] ?? null)->toBeNull()
        ->and(array_keys($fields))->toBe(['name', 'total_units', 'phase_count', 'enquiries_email', 'enquiries_phone', 'security_provider'])
        ->and($fields['enquiries_email']['editable'])->toBeTrue()
        ->and($fields['enquiries_phone']['editable'])->toBeTrue()
        // The country the address line is given (the page names Jamaica's clock too, which is not an address).
        ->and(json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->not->toContain(', Jamaica');
});

// One page load per test: a second request in the same test is answered from the estate the first one resolved.
it('treats an address of nothing but spaces as no address, rather than drawing ", Jamaica" on its own', function (?string $line, ?string $parish): void {
    $props = profileLinesProps($this, $line, $parish);

    expect(profileLinesFields($props))->not->toHaveKey('address')
        ->and($props['estate']['address'] ?? null)->toBeNull()
        ->and(json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->not->toContain(', Jamaica');
})->with([
    'spaces for a street, no parish' => ['   ', null],
    'an empty street, spaces for a parish' => ['', '  '],
    'spaces for a street, an empty parish' => ['  ', ''],
]);

it('shows the address the client record holds, read-only, exactly as it is written there', function (): void {
    $fields = profileLinesFields(profileLinesProps($this, 'Hope Road', 'St. Andrew'));

    expect($fields['address']['value'])->toBe('Hope Road, St. Andrew, Jamaica')
        ->and($fields['address']['editable'])->toBeFalse();
});

it('shows half an address as the record holds it: the parish alone, never a guessed street', function (): void {
    expect(profileLinesFields(profileLinesProps($this, '  ', 'St. Catherine'))['address']['value'])->toBe('St. Catherine, Jamaica');
});
