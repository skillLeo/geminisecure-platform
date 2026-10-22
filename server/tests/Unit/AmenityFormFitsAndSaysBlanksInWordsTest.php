<?php

declare(strict_types=1);

use App\Models\Estate\Amenity;
use App\Services\Estate\Amenities;

/*
|--------------------------------------------------------------------------
| The amenity form fits its dialog and says a blank in words (Hassam, batch 1 item 2, 12 Oct, live)
|--------------------------------------------------------------------------
|
| /facilities/amenities/settings, "Edit the Gazebo": the form was wider than the dialog (the name and the booking fee
| cut off, a sideways scrollbar inside it), and its read-back said "cancel nullh ahead, book up to null" for an amenity
| with no cancellation rule and no booking horizon. Two columns that may shrink, one on a phone; a blank said as "cancel
| any time" and "no limit on how far ahead"; and the server's own line about an edit never prints a blank as "h".
| Read as text where it is the page (a unit test boots no application; the JS runner is banned).
|
*/

function amenitySettingsSource(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Facilities/AmenitySettings.vue'));
}

it('fits two shrinkable columns in the dialog, one on a phone', function (): void {
    $vue = amenitySettingsSource();
    $grid = substr($vue, (int) strpos($vue, ".am-fields {\n"), 160);

    expect(str_contains($grid, 'grid-template-columns: repeat(2, minmax(0, 1fr));'))->toBeTrue('two columns that may shrink')
        ->and(str_contains($vue, 'repeat(4, 1fr)'))->toBeFalse('four columns that cannot shrink below an input')
        ->and(str_contains($vue, "@media (max-width: 640px) {\n    .am-fields {\n        grid-template-columns: minmax(0, 1fr);"))->toBeTrue('one column on a phone')
        ->and(str_contains($vue, "    width: 100%;\n    min-width: 0;\n    box-sizing: border-box;\n    height: 34px;"))->toBeTrue('each input fills its column');
});

it('reads a blank cancellation rule and booking horizon back in words, never as null', function (): void {
    $vue = amenitySettingsSource();
    $readBack = substr($vue, (int) strpos($vue, 'const readBack = computed('), 900);

    expect(str_contains($readBack, '${form.cancellation_hours}'))->toBeFalse('the raw cancellation hours, "nullh" when blank')
        ->and(str_contains($readBack, '${form.booking_window_days}'))->toBeFalse('the raw horizon, "null" when blank')
        ->and(str_contains($readBack, '${cancelWords(form.cancellation_hours)}, ${windowWords(form.booking_window_days)}'))->toBeTrue()
        ->and(str_contains($vue, "return h === null || h === 0 ? 'cancel any time' :"))->toBeTrue()
        ->and(str_contains($vue, "return d === null ? 'no limit on how far ahead' :"))->toBeTrue();
});

it('says a blank cancellation notice as "any time" in the line about an edit', function (): void {
    $service = (new ReflectionClass(Amenities::class))->newInstanceWithoutConstructor();
    $gazebo = (new Amenity)->forceFill(['name' => 'Gazebo', 'booking_fee_minor' => 300000, 'deposit_minor' => null, 'hourly_rate_minor' => null, 'cancellation_hours' => null, 'currency' => 'JMD']);
    $before = ['fee' => $gazebo->feeLabel(), 'deposit' => $gazebo->depositLabel(), 'cancellation_hours' => 24];

    $line = $service->termsEffect($gazebo, $before, 0);

    expect(str_contains($line, 'Cancellation notice 24h → any time.'))->toBeTrue($line)
        ->and(str_contains($line, 'h → h'))->toBeFalse($line);
});
