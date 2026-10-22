<?php

declare(strict_types=1);

use App\Live\Auth\ChannelRegistry;
use App\Live\ChannelName;
use App\Live\Channels;

/*
|--------------------------------------------------------------------------
| The side that writes and the side that reads name every channel alike (DESIGN.md §3.1, §12.1)
|--------------------------------------------------------------------------
|
| Events name their audiences through App\Live\Channels (the log's builder); grants and catch-up read
| names through App\Live\ChannelName (the registry's parser). Two grammars that drifted would mean
| an event addressed to a channel nobody can hold, or a channel held that no event ever reaches.
| So every family either builder can make is built, parsed back, and held to one answer: the same
| name, the same estate, the same streams, and a rule in the registry that decides it.
|
*/

/**
 * One name of every family, built by BOTH builders where both exist.
 *
 * @return array<string, array{built: string, parsed: string|null}>
 */
function grammarNames(): array
{
    $t = 'phoenixpark';
    $names = [
        'user' => [Channels::user(12), ChannelName::user(12)],
        'gemini fan-in' => [Channels::geminiFanIn('dispatch'), ChannelName::geminiFeed('dispatch')],
        'gemini estate copy' => [Channels::geminiFeed($t, 'workforce'), ChannelName::geminiTenantFeed($t, 'workforce')],
        'gemini record' => [Channels::geminiRecord($t, 'dispatch', 'alert', 55), ChannelName::geminiRecord($t, 'dispatch', 'alert', 55)],
        'guard' => [Channels::guard($t, 5), ChannelName::guard($t, 5)],
        'estate guards' => [Channels::estateGuards($t), ChannelName::estateGuards($t)],
        'estate passes' => [Channels::estatePasses($t), ChannelName::estatePasses($t)],
        'approval' => [Channels::approval($t, 77), ChannelName::approval($t, 77)],
        'household' => [Channels::household($t, 9), ChannelName::household($t, 9)],
        'resident' => [Channels::resident($t, 31), ChannelName::resident($t, 31)],
        'community' => [Channels::community($t), ChannelName::community($t)],
        'phase' => [Channels::phase($t, ' Block A '), ChannelName::phase($t, ChannelName::phaseKey(' Block A '))],
        'claim' => [Channels::claim($t, 424242), ChannelName::claim($t, 424242)],
    ];

    foreach (Channels::ESTATE_FEEDS as $feed) {
        $names['estate feed '.$feed] = [Channels::estateFeed($t, $feed), ChannelName::estateFeed($t, $feed)];
    }

    foreach (Channels::ESTATE_RECORDS as $feed => $types) {
        foreach ($types as $type) {
            $names["estate record {$feed}.{$type}"] = [Channels::estateRecord($t, $feed, $type, 8), ChannelName::estateRecord($t, $feed, $type, 8)];
        }
    }

    foreach (Channels::GEMINI_FEEDS as $feed) {
        $names['gemini fan-in '.$feed] = [Channels::geminiFanIn($feed), ChannelName::geminiFeed($feed)];

        if (! in_array($feed, Channels::GEMINI_PLATFORM_ONLY, true)) {
            $names['gemini copy '.$feed] = [Channels::geminiFeed($t, $feed), ChannelName::geminiTenantFeed($t, $feed)];
        }
    }

    foreach (Channels::GEMINI_RECORDS as $feed => $types) {
        foreach ($types as $type) {
            $names["gemini record {$feed}.{$type}"] = [Channels::geminiRecord($t, $feed, $type, 8), ChannelName::geminiRecord($t, $feed, $type, 8)];
        }
    }

    return array_map(static fn (array $pair): array => ['built' => $pair[0], 'parsed' => $pair[1]], $names);
}

it('builds every family the same way in both builders, and parses each back to the name, estate and streams the log uses', function () {
    foreach (grammarNames() as $family => ['built' => $built, 'parsed' => $other]) {
        $channel = ChannelName::parse($built);

        expect($channel)->not->toBeNull("{$family}: {$built} does not parse")
            ->and($built)->toBe($other, "{$family}: the two builders disagree")
            ->and($channel->name)->toBe($built, $family)
            ->and($channel->tenant)->toBe(Channels::tenantOf($built), "{$family}: the estate")
            ->and($channel->streams())->toBe(Channels::streamsOf($built), "{$family}: the streams")
            ->and(ChannelName::fromWire(ChannelName::WIRE_PREFIX.$built)?->name)->toBe($built, "{$family}: the wire name");
    }
});

it('has a rule in the registry for every family a name can be, and decides none by default', function () {
    foreach (grammarNames() as $family => ['built' => $built]) {
        $channel = ChannelName::parse($built);

        expect(array_key_exists($channel->family, ChannelRegistry::RULES))->toBeTrue("{$family}: no rule entry")
            ->and(ChannelRegistry::RULES[$channel->family])->not->toBeNull("{$family}: {$channel->family} has no rule");
    }

    // The one public channel has no rule, because nothing subscribes to it with a grant.
    expect(ChannelName::parse(Channels::pulse())?->family)->toBe(ChannelName::PULSE_FAMILY)
        ->and(ChannelRegistry::RULES[ChannelName::PULSE_FAMILY])->toBeNull();
});

it('covers every builder App\Live\Channels has, so a new family cannot slip past this test', function () {
    $covered = ['user', 'gemini', 'geminiFanIn', 'geminiFeed', 'geminiRecord', 'estateFeed', 'estateRecord', 'guard', 'estateGuards',
        'estatePasses', 'approval', 'household', 'resident', 'community', 'phase', 'claim', 'pulse'];
    $helpers = ['phaseKey', 'tenantOf', 'familyOf', 'streamsOf', 'isFeed', 'isDemonstrationEstate'];

    $builders = collect((new ReflectionClass(Channels::class))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC))
        ->filter(fn (ReflectionMethod $m) => $m->isStatic() && $m->isPublic() && $m->class === Channels::class)
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->reject(fn (string $name) => in_array($name, $helpers, true))
        ->sort()->values()->all();

    expect($builders)->toBe(collect($covered)->sort()->values()->all());

    // And every row of the grammar's Streams column is one this test builds a name of.
    $families = collect(grammarNames())->map(fn (array $pair) => Channels::familyOf($pair['built']))->unique()->sort()->values()->all();

    expect($families)->toBe(collect(array_keys(Channels::STREAMS))->sort()->values()->all());
});

it('lets no name through that neither builder could make', function () {
    foreach (['User.12', 'user.012', 'user.12.x', 'estate.phoenixpark.nofeed', 'estate.PP.security', 'gemini.settings.x', 'gemini.phoenixpark.settings',
        'phase.phoenixpark.BLOCKA', 'household.phoenixpark.0', 'presence-live.user.12', ''] as $bad) {
        expect(ChannelName::parse($bad))->toBeNull($bad)
            ->and(fn () => Channels::familyOf($bad))->toThrow(InvalidArgumentException::class);
    }
});
