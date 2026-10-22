<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Services\Estate\Governance;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Schedule meeting starts as a committee meeting, not as this year's AGM (the QA session's note, 10 October)
|--------------------------------------------------------------------------
|
| The form opened as an AGM titled "Annual General Meeting 2026". A tester's committee and phase meetings both came out
| titled "Annual General Meeting 2026", because the title stayed when the type changed; and pressing Schedule meeting
| with the defaults would make a second one beside the estate's real AGM, for the whole estate. A committee meeting is
| the one an estate holds most often and the one whose audience is smallest, so the form starts there, under a title
| that says what it is. Choosing AGM still gives the whole estate and its 21 days' notice.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
});

it('opens Schedule meeting as a committee meeting for the committee, titled as one', function (): void {
    $defaults = app(Governance::class)->schedulerDefaults()['defaults'];

    expect($defaults['type'])->toBe(Meeting::COMMITTEE)
        ->and($defaults['title'])->toBe('Committee meeting')
        ->and($defaults['audience_scope'])->toBe(Meeting::COMMITTEE_ONLY)
        ->and($defaults['title'])->not->toContain('Annual General Meeting');
});

it('still offers the AGM, for the whole estate, with its own notice period', function (): void {
    $form = app(Governance::class)->schedulerDefaults();

    expect(array_column($form['types'], 'value'))->toContain(Meeting::AGM)
        ->and($form['defaults']['audience_label'])->toStartWith('Whole estate')
        ->and($form['notice']['days'][Meeting::AGM])->toBeGreaterThanOrEqual($form['notice']['days'][Meeting::COMMITTEE]);
});
