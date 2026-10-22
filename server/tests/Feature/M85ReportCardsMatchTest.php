<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Each report card promises what its report does (M81 §5 "the cards"; M85 Phase B)
|--------------------------------------------------------------------------
|
| The catalogue's cards, transcribed from board 30, promised "vendor performance" with no vendor section, "filterable by
| phase" on a report with no phase split, "exportable by phase" with no phase filter, and "certified results" on a
| report of turnout. "The card matches the report, or the report grows the feature." Each card now says what its report
| does, as the reports stand after REP-2, REP-3 and REP-7.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

it('describes each report as it is', function (): void {
    $this->actingAs(FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN))
        ->get(FacilitiesFixture::url('/reports'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('groups', function ($groups): bool {
            $cards = collect($groups)->flatMap(static fn (array $group): array => $group['cards'])->keyBy('key');

            expect($cards['arrears_ageing']['description'])->toBe("Every unit's balance by ageing bucket, as at today")
                ->and($cards['maintenance_summary']['description'])->toBe('Tickets by category, and how long they took to close')
                ->and($cards['security_incidents']['description'])->toBe('All incident reports for a period you choose')
                ->and($cards['election_turnout']['description'])->toBe('Turnout by phase for the elections held in a period you choose')
                ->and($cards['meeting_minutes']['description'])->toBe('The meetings held in a period you choose, and whether their minutes were issued');

            return true;
        }));
});
