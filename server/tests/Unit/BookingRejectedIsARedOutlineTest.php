<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A declined booking reads Rejected in a red outline
|--------------------------------------------------------------------------
|
| Hassam, 11 October 2026: "Rejected becomes a red outline, not solid red, not amber." Solid red is an open panic alert
| only, and amber is for errors, warnings and refusals. The diary and the booking page each draw the badge inline, so
| each one's style is read here; the node tests for both pages (estate-bookings-pass, estate-booking-pass) say the same
| of the badge as rendered.
|
*/

function bookingPageSource(string $page): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Facilities/'.$page);
}

it('draws a declined booking as Rejected in a red outline, never a red fill and never amber', function (string $page) {
    $source = bookingPageSource($page);
    $at = strpos($source, 'const REJECTED_BADGE = ');

    expect($at)->not->toBeFalse("{$page} names no REJECTED_BADGE");

    $badge = (string) strtok(substr($source, (int) $at), "\n");

    expect(str_contains($badge, 'inset 0 0 0 1px var(--red-700)'))->toBeTrue("{$page} draws no red outline: {$badge}")
        ->and(str_contains($badge, 'background:var(--red-'))->toBeFalse("{$page} fills the badge red: {$badge}")
        ->and(str_contains($badge, 'amber'))->toBeFalse("{$page} draws the badge amber: {$badge}");
})->with(['Booking.vue', 'Bookings.vue']);
