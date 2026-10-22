<?php

declare(strict_types=1);

use App\Services\ResidentApp\PassInvitation;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The words that go with a visitor pass — M18F §4, and M98
|--------------------------------------------------------------------------
|
| The client's own example: the date once, the host's name, the code standing out, and a words-only version for a
| text message that stays inside two segments. Since M98 (Hassam, 12 Oct 2026) the date is in the one form
| ("27 Sep 2026") and no clock is named: "Everything on a pass is estate time by definition: the estate is in
| Jamaica, the gate is in Jamaica, the visitor arrives in Jamaica." The greeting takes a comma, as the app's does
| (Law 3: no em dash in anything a person reads). The Resident App writes the same words offline (passes.ts
| `passInvitation`); its test (passes.test.ts) holds the same cases.
|
*/

/** @return array{estate: string, unit: ?string, phase: ?string, host: string, zone: string} */
function invitationPlace(array $over = []): array
{
    return [...['estate' => 'Phoenix Park Village 1', 'unit' => 'Lot 47', 'phase' => 'Phase 2', 'host' => 'Hassam Ahmed', 'zone' => 'America/Jamaica'], ...$over];
}

/** @return array{message: string, sms: string} 8:58 – 11:00 AM on Sunday 27 September 2026 in Kingston, unless told otherwise. */
function invitationFor(string $kind, string $from = '2026-09-27T13:58:00Z', string $to = '2026-09-27T16:00:00Z', array $place = []): array
{
    return PassInvitation::words($kind, 'Mmm', 'PPV2-2195', Carbon::parse($from), Carbon::parse($to), invitationPlace($place));
}

it('writes the client\'s single-visit caption, its date in the one form and no clock named (M98)', function () {
    expect(invitationFor('single')['message'])->toBe(
        "Hi Mmm, you're expected at Phoenix Park Village 1, Lot 47 (Phase 2).\n\n"
        ."27 Sep 2026, 8:58 – 11:00 AM.\n\n"
        ."Show this pass at the gate, or give the guard the code *PPV2-2195*.\n\n"
        .'Sent by Hassam Ahmed'
    );
});

it('writes the words-only version in plain GSM characters, inside two segments', function () {
    $sms = invitationFor('single')['sms'];

    expect($sms)->toBe("Hi Mmm - you're expected at Phoenix Park Village 1, Lot 47 on 27 Sep 2026, 8:58-11:00 AM. Give the guard the code PPV2-2195 at the gate. Sent by Hassam Ahmed.");
    // GSM-7 only (no em dash, no curly quote): 153 characters a segment once a message is split, so two segments.
    expect(preg_match('/[^\x20-\x7E\n]/', $sms))->toBe(0)
        ->and(mb_strlen($sms))->toBeLessThanOrEqual(306);
});

it('greets each kind of pass as what it is', function () {
    expect(invitationFor('contractor')['message'])->toStartWith("Hello Mmm, you're booked to work at Phoenix Park Village 1, Lot 47 (Phase 2).")
        ->and(invitationFor('contractor')['message'])->toContain('Please have your ID ready.')
        ->and(invitationFor('delivery')['message'])->toStartWith('Hello Mmm, a delivery to Phoenix Park Village 1, Lot 47 (Phase 2) is expected.')
        ->and(invitationFor('recurring', '2026-09-27T11:00:00Z', '2026-10-03T23:00:00Z')['message'])->toBe(
            "Hi Mmm, here is your pass for Phoenix Park Village 1, Lot 47 (Phase 2).\n\n"
            ."Valid from 27 Sep 2026, 6:00 AM to 3 Oct 2026, 6:00 PM.\n\n"
            ."Show this pass at the gate each time you come, or give the guard the code *PPV2-2195*.\n\n"
            .'Sent by Hassam Ahmed'
        )
        ->and(invitationFor('recurring', '2026-09-27T11:00:00Z', '2026-10-03T23:00:00Z')['sms'])->toBe(
            'Hi Mmm - your pass for Phoenix Park Village 1, Lot 47, valid from 27 Sep 2026, 6:00 AM to 3 Oct 2026, 6:00 PM. Give the guard the code PPV2-2195 at the gate. Sent by Hassam Ahmed.'
        );
});

it('never says anything an app would (no e-Pass, no QR, no signed), names no clock, and has no em dash (M98, Law 3)', function () {
    foreach (['single', 'recurring', 'contractor', 'delivery'] as $kind) {
        foreach (invitationFor($kind) as $text) {
            expect($text)->not->toMatch('/e-?pass|\bQR\b|signed/i')
                ->and(substr_count($text, '27'))->toBe(1)
                ->and(str_contains($text, '27 Sep 2026'))->toBeTrue()
                ->and(preg_match('/Jamaica|[A-Z]\w* time\b|\bJA\b/', $text))->toBe(0)
                ->and(str_contains($text, '—'))->toBeFalse();
        }
        // Every SMS inside two segments, every kind.
        expect(mb_strlen(invitationFor($kind)['sms']))->toBeLessThanOrEqual(306);
    }
});

it('reads an estate somewhere else on its own clock, and names none', function () {
    expect(invitationFor('single', place: ['zone' => 'America/New_York'])['message'])->toContain("\n\n27 Sep 2026, 9:58 AM – 12:00 PM.\n\n");
});

it('tags and names a clock by its zone\'s place — no estate\'s zone written into the product', function () {
    expect(SiteTime::clockName('America/Jamaica'))->toBe('Jamaica time')
        ->and(SiteTime::clockName('America/New_York'))->toBe('New York time')
        ->and(SiteTime::clockTag('America/Jamaica'))->toBe('JA')
        ->and(SiteTime::clockTag('America/New_York'))->toBe('NY')
        ->and(SiteTime::clockTag('America/Port_of_Spain'))->toBe('POS')
        ->and(SiteTime::clockTag('Asia/Karachi'))->toBe('KA');
});
