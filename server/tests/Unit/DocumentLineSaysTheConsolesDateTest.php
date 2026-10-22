<?php

declare(strict_types=1);

use App\Models\Estate\Document;
use Illuminate\Support\Carbon;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A document says when it was issued in the console's one form (Hassam, 12 October 2026)
|--------------------------------------------------------------------------
|
| A meeting's issued papers, and every other document's line, read "Issued Oct 9, 2026 7:07 PM JA. Kept until Oct 9,
| 2033." from the shared `Document::statusLine()`. The correct form is "9 Oct 2026, 7:07 PM", on the estate's clock,
| with no "JA"; the day it is kept until reads the same way. A paper that could not be made says so without an em dash.
|
*/

uses(TestCase::class);

it('says when a paper was issued as "9 Oct 2026, 7:07 PM", on the estate\'s clock and with no zone tag', function (): void {
    // An estate's clock is the estate's own: the fixture estate's, which is Kingston's. 00:07 UTC on 10 October is 7:07 PM
    // on 9 October there.
    FacilitiesFixture::boot();

    $paper = (new Document)->forceFill([
        'status' => Document::READY,
        'issued_at' => Carbon::parse('2026-10-10 00:07:00', 'UTC'),
        'retain_until' => '2033-10-09',
    ]);

    expect($paper->statusLine())->toBe('Issued 9 Oct 2026, 7:07 PM. Kept until 9 Oct 2033.');
});

it('says a paper that could not be made, and that asking again is safe, without an em dash', function (): void {
    $paper = (new Document)->forceFill(['status' => Document::FAILED, 'failure_reason' => 'the renderer did not answer']);

    expect($paper->statusLine())->toBe('Could not be produced: the renderer did not answer. Asking again is safe: nothing was issued.')
        ->and(str_contains($paper->statusLine(), '—'))->toBeFalse();
});
