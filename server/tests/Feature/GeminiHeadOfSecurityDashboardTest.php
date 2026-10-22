<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\User;
use App\Services\Gemini\PlatformOverview;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HeadOfSecurityScope;

/*
|--------------------------------------------------------------------------
| The dashboard and its activity feed, inside the Head of Security's scope —
| bug hunt B007
|--------------------------------------------------------------------------
|
| Every Gemini role holds the Dashboard in full, and `PlatformOverview` read it
| for nobody in particular: a Head of Security assigned to one estate was told
| which clients were onboarding elsewhere, which officers were hired at them and
| the PSRA numbers of the ones whose licence had lapsed — facts the Guard
| workforce and Clients screens deliberately keep from that role — and the four
| cards counted the whole platform. D-015 puts the role's scope on every cell it
| holds, this one included.
|
| Officers not yet posted belong to no estate and stay, as they do on the role's
| own workforce screens. The Director's view is unchanged, against the same rows.
|
| TWO FOLLOW-UPS FROM REVIEW. The viewer was optional on every public method, so
| a caller that left it out got the whole platform again, and an unused
| `recentEstates()` took no viewer at all: the viewer is now required, first,
| on every public method, and that is pinned by reflection. And a site-scoped
| role that holds Billing had its sites' MRR labelled "Platform MRR": the card
| and the tier panel now say whose figures they are.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    HeadOfSecurityScope::seed($this);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('names nothing from another estate on the Head of Security\'s activity feed, and everything on the Director\'s', function () {
    $overview = app(PlatformOverview::class);

    // A long feed, so an absence is the scope and not the row falling off the end.
    $keys = array_column($overview->activityFeed($this->head, false, 50), 'key');

    expect($keys)->not->toContain('estate:'.$this->theirs)
        ->and($keys)->not->toContain('estate:'.$this->onboarding)
        ->and($keys)->not->toContain('hire:'.$this->theirsGuard->id)
        ->and($keys)->not->toContain('licence:'.$this->lapsed->id)
        ->and($keys)->toContain('estate:'.$this->mine)
        ->and($keys)->toContain('hire:'.$this->mineGuard->id);

    $everything = array_column($overview->activityFeed($this->director, true, 50), 'key');

    expect($everything)->toContain('estate:'.$this->theirs)
        ->and($everything)->toContain('hire:'.$this->theirsGuard->id)
        ->and($everything)->toContain('licence:'.$this->lapsed->id);

    // And the two screens themselves carry none of it: names, officers, licence numbers.
    foreach (['/dashboard', '/dashboard/activity'] as $screen) {
        $scan = HeadOfSecurityScope::scan(HeadOfSecurityScope::props($this->actingAs($this->head)->get($screen)));

        foreach ([$this->names[$this->theirs], $this->names[$this->onboarding], $this->theirsGuard->full_name, $this->lapsed->full_name, $this->lapsed->psra_number] as $foreign) {
            // str_contains, so the failure can say which screen named what: toContain() takes needles, not a message.
            expect(str_contains($scan, strtolower($foreign)))->toBeFalse("{$screen} named {$foreign}");
        }
    }
});

it('counts the Head of Security\'s own sites on the dashboard cards', function () {
    $cards = collect(HeadOfSecurityScope::props($this->actingAs($this->head)->get('/dashboard'))['kpis'])->keyBy('key');

    expect($cards['clients']['value'])->toBe('1')
        ->and($cards['units']['value'])->toBe('120')
        ->and($cards->keys()->all())->not->toContain('mrr');

    // An officer taken on at another estate moves the Director's count and not theirs.
    $overview = app(PlatformOverview::class);
    $before = (int) collect($overview->kpis($this->head, false))->keyBy('key')['guards']['value'];
    $directorBefore = (int) collect($overview->kpis($this->director, true))->keyBy('key')['guards']['value'];

    HeadOfSecurityScope::officer($this->theirs, 'Kemar '.$this->token);

    expect((int) collect($overview->kpis($this->head, false))->keyBy('key')['guards']['value'])->toBe($before)
        ->and((int) collect($overview->kpis($this->director, true))->keyBy('key')['guards']['value'])->toBe($directorBefore + 1);

    // An officer not yet posted anywhere belongs to no estate, and the Head of Security still counts them.
    HeadOfSecurityScope::officer(null, 'Unposted '.$this->token);

    expect((int) collect($overview->kpis($this->head, false))->keyBy('key')['guards']['value'])->toBe($before + 1);
});

it('narrows the Platform MRR card and the tier panel for a site-scoped role that holds Billing', function () {
    $copy = HeadOfSecurityScope::billingCopy($this->suffix, $this->mine);

    $props = HeadOfSecurityScope::props($this->actingAs($copy)->get('/dashboard'));
    $cards = collect($props['kpis'])->keyBy('key');
    $currency = Plan::query()->value('currency') ?? MoneyFormatter::DEFAULT_CURRENCY;

    // 120 units at J$340, no guards: its one client's MRR, not the platform's.
    expect($cards['mrr']['value'])->toBe(MoneyFormatter::whole(40_800_00, (string) $currency))
        ->and(array_column($props['tiers'], 'label'))->toBe([$this->planName])
        ->and(HeadOfSecurityScope::scan($props))->not->toContain(strtolower($this->names[$this->theirs]));

    /*
     * AND IT SAYS SO. A sum over one role's sites under the words "Platform MRR" is a false label
     * (fix-up of B007): /billing prints the platform's figure under those words to the same reader.
     * No card this reader is sent may carry the platform's name, and the page is told the figures
     * are its sites' so the tier panel can say the same.
     */
    expect($cards['mrr']['label'])->toBe('MRR at your sites')
        ->and(array_column($props['kpis'], 'label'))->not->toContain('Platform MRR')
        ->and($props['sites_only'])->toBeTrue();

    $directorProps = HeadOfSecurityScope::props($this->actingAs($this->director)->get('/dashboard'));
    $director = collect($directorProps['kpis'])->keyBy('key');

    expect($director['mrr']['value'])->not->toBe($cards['mrr']['value'])
        ->and($director['mrr']['label'])->toBe('Platform MRR')
        ->and($directorProps['sites_only'])->toBeFalse();

    // The Head of Security is counted over its sites too, and told so, though it is sent no MRR at all.
    expect(HeadOfSecurityScope::props($this->actingAs($this->head)->get('/dashboard'))['sites_only'])->toBeTrue();

    // And the page turns the tier panel's heading on that answer, not on a guess of its own.
    $page = (string) file_get_contents(resource_path('js/Pages/Gemini/Dashboard.vue'));

    expect($page)->toContain("sites_only ? 'MRR by subscription tier at your sites' : 'MRR by subscription tier'")
        ->and($page)->toContain('sites_only: { type: Boolean');
});

it('asks every caller who is looking: no public method of the overview reads the platform by default', function () {
    /*
     * Fix-up of B007. The viewer was optional, and a caller that left it out was handed every
     * estate's names — the defect B007 fixed — on the strength of a class note that said every
     * method took it, while `recentEstates()` took none. A required first `User $viewer` makes
     * leaving it out an error rather than a leak; the Director, whose scope is every site, is how a
     * caller asks for the company's own figures.
     */
    $methods = (new ReflectionClass(PlatformOverview::class))->getMethods(ReflectionMethod::IS_PUBLIC);

    expect($methods)->not->toBeEmpty();

    foreach ($methods as $method) {
        if ($method->getDeclaringClass()->getName() !== PlatformOverview::class || $method->isConstructor()) {
            continue;
        }

        $first = $method->getParameters()[0] ?? null;
        $type = $first?->getType();

        expect($first)->not->toBeNull("{$method->getName()}() takes no viewer")
            ->and($first?->getName())->toBe('viewer', "{$method->getName()}() does not take the viewer first")
            ->and($type instanceof ReflectionNamedType && $type->getName() === User::class)->toBeTrue("{$method->getName()}()'s viewer is not a User")
            ->and($type?->allowsNull())->toBeFalse("{$method->getName()}()'s viewer may be null")
            ->and($first?->isOptional())->toBeFalse("{$method->getName()}()'s viewer may be left out");
    }
});
