<?php

declare(strict_types=1);

use App\Live\ChannelName;

/*
|--------------------------------------------------------------------------
| ONE GRAMMAR, ONE PARSER (M11 Part C, DESIGN §3.1)
|--------------------------------------------------------------------------
|
| Every live channel name is read by `App\Live\ChannelName` and by nothing else, and
| it refuses anything that is not written exactly one way. A name that could be
| spelled two ways is two names to authorise, and the second spelling is the one
| nobody tested: `estate.PP.security`, `user.07`, a trailing dot, a presence alias.
|
*/

it('reads every family of the grammar, with its tenant, feed, record and streams', function () {
    $cases = [
        'user.12' => [ChannelName::USER, null, null, null, '12', ['c']],
        'gemini.dispatch' => [ChannelName::GEMINI_FEED, null, 'dispatch', null, null, ['c']],
        'gemini.dashboard' => [ChannelName::GEMINI_FEED, null, 'dashboard', null, null, ['c']],
        'gemini.phoenixpark.workforce' => [ChannelName::GEMINI_TENANT_FEED, 'phoenixpark', 'workforce', null, null, ['c']],
        'gemini.phoenixpark.dispatch.alert.55' => [ChannelName::GEMINI_RECORD, 'phoenixpark', 'dispatch', 'alert', '55', ['c']],
        'gemini.phoenixpark.workforce.orders.9' => [ChannelName::GEMINI_RECORD, 'phoenixpark', 'workforce', 'orders', '9', ['c']],
        'estate.phoenixpark.security' => [ChannelName::ESTATE_FEED, 'phoenixpark', 'security', null, null, ['c', 'e']],
        'estate.phoenixpark.structure' => [ChannelName::ESTATE_FEED, 'phoenixpark', 'structure', null, null, ['c', 'e']],
        'estate.phoenixpark.facilities.ticket.1039' => [ChannelName::ESTATE_RECORD, 'phoenixpark', 'facilities', 'ticket', '1039', ['c', 'e']],
        'estate.phoenixpark.governance.ballot.3' => [ChannelName::ESTATE_RECORD, 'phoenixpark', 'governance', 'ballot', '3', ['c', 'e']],
        'guard.phoenixpark.5' => [ChannelName::GUARD, 'phoenixpark', null, null, '5', ['c', 'e']],
        'estate.phoenixpark.guards' => [ChannelName::ESTATE_GUARDS, 'phoenixpark', null, null, null, ['c', 'e']],
        'estate.phoenixpark.passes' => [ChannelName::ESTATE_PASSES, 'phoenixpark', null, null, null, ['e']],
        'approval.phoenixpark.77' => [ChannelName::APPROVAL, 'phoenixpark', null, null, '77', ['e']],
        'household.phoenixpark.14' => [ChannelName::HOUSEHOLD, 'phoenixpark', null, null, '14', ['c', 'e']],
        'resident.phoenixpark.9' => [ChannelName::RESIDENT, 'phoenixpark', null, null, '9', ['c', 'e']],
        'community.phoenixpark' => [ChannelName::COMMUNITY, 'phoenixpark', null, null, null, ['e']],
        'phase.phoenixpark.0123456789' => [ChannelName::PHASE, 'phoenixpark', null, null, '0123456789', ['e']],
        'claim.phoenixpark.31' => [ChannelName::CLAIM, 'phoenixpark', null, null, '31', ['e']],
        'live-pulse' => [ChannelName::PULSE_FAMILY, null, null, null, null, []],
    ];

    foreach ($cases as $name => [$family, $tenant, $feed, $type, $id, $streams]) {
        $channel = ChannelName::parse($name);

        expect($channel)->not->toBeNull($name.' is canonical and must parse');
        expect($channel->family)->toBe($family, $name)
            ->and($channel->name)->toBe($name)
            ->and($channel->tenant)->toBe($tenant, $name)
            ->and($channel->feed)->toBe($feed, $name)
            ->and($channel->type)->toBe($type, $name)
            ->and($channel->id)->toBe($id, $name)
            ->and($channel->streams())->toBe($streams, $name);
    }

    // Every family the registry knows is exercised above, so none can be added unread.
    expect(collect($cases)->map(fn (array $case): string => $case[0])->unique()->sort()->values()->all())
        ->toBe(collect(ChannelName::FAMILIES)->sort()->values()->all());
});

it('keeps the tenant in the second segment of every name that carries one', function () {
    foreach (['gemini.pp1.dispatch', 'estate.pp1.dues', 'guard.pp1.4', 'household.pp1.4', 'community.pp1', 'phase.pp1.abcdef0123', 'claim.pp1.4', 'approval.pp1.4', 'resident.pp1.4', 'estate.pp1.passes'] as $name) {
        expect(ChannelName::parse($name)?->tenant)->toBe(explode('.', $name)[1], $name);
    }
});

it('refuses every spelling that is not the canonical one', function () {
    /*
     * `pp1` is a valid tenant key, so each line below is refused for the one reason it
     * shows and not because its tenant is malformed. The tenant rules have lines of
     * their own: too short (`ab`), too long, a leading digit, upper case.
     */
    $refused = [
        '',
        'User.12', 'user.012', 'user.0', 'user.-1', 'user.12.', '.user.12', 'user..12', 'user. 12', ' user.12', "user.12\n",
        'user.1234567890123456789012',
        'estate.PP1.security', 'estate.pp1.Security', 'estate.9pp.security', 'estate.pp1.security.',
        'estate.ab.security', 'estate.'.str_repeat('a', 31).'.security',
        'estate.pp1.alerts', 'estate.pp1', 'estate.pp1.security.extra', 'estate.pp1.dues.unit.4', 'estate.pp1.facilities.ticket.04',
        'estate.pp1.governance.election.2026', 'estate.pp1.guards.4', 'estate.pp1.passes.4',
        'gemini.pp1.settings', 'gemini.pp1.dashboard', 'gemini.pp1.dispatch.incident.4', 'gemini.nothing', 'gemini',
        'guard.pp1', 'guard.pp1.01', 'phase.pp1.ABCDEF0123', 'phase.pp1.abcdef012', 'phase.pp1.abcdef01234',
        'presence-live.user.12', 'private-live.user.12', 'private-encrypted-live.user.12', 'private-estate.pp1.alerts',
        'App.Models.User.12', 'estate.pp1.alerts', 'livepulse', 'live-pulse.x', 'LIVE-PULSE',
        'unknown.pp1.1', 'community', 'community.pp1.1', 'household.pp1', 'resident.pp1.0', 'claim.pp1.1.2',
    ];

    foreach ($refused as $name) {
        expect(ChannelName::parse($name))->toBeNull('['.$name.'] must not parse');
    }
});

it('takes a wire name only with the private-live. prefix, and never the pulse by that door', function () {
    expect(ChannelName::fromWire('private-live.user.12')?->name)->toBe('user.12')
        ->and(ChannelName::fromWire('private-live.estate.pp1.security')?->family)->toBe(ChannelName::ESTATE_FEED)
        ->and(ChannelName::fromWire('user.12'))->toBeNull()
        ->and(ChannelName::fromWire('presence-live.user.12'))->toBeNull()
        ->and(ChannelName::fromWire('private-encrypted-live.user.12'))->toBeNull()
        ->and(ChannelName::fromWire('Private-live.user.12'))->toBeNull()
        ->and(ChannelName::fromWire('private-live.private-live.user.12'))->toBeNull()
        ->and(ChannelName::fromWire('private-live.live-pulse'))->toBeNull()
        ->and(ChannelName::fromWire('live-pulse'))->toBeNull()
        ->and(ChannelName::fromWire('private-estate.pp1.alerts'))->toBeNull();

    expect(ChannelName::parse('estate.pp1.security')?->wire())->toBe('private-live.estate.pp1.security')
        ->and(ChannelName::parse('live-pulse')?->wire())->toBe('live-pulse');
});

it('formats every family the way it parses, and refuses to format a name it would not parse', function () {
    $formatted = [
        ChannelName::user(12) => ChannelName::USER,
        ChannelName::geminiFeed('dispatch') => ChannelName::GEMINI_FEED,
        ChannelName::geminiTenantFeed('pp1', 'billing') => ChannelName::GEMINI_TENANT_FEED,
        ChannelName::geminiRecord('pp1', 'workforce', 'guard', 44) => ChannelName::GEMINI_RECORD,
        ChannelName::estateFeed('pp1', 'console') => ChannelName::ESTATE_FEED,
        ChannelName::estateRecord('pp1', 'ledger', 'unit', 7) => ChannelName::ESTATE_RECORD,
        ChannelName::guard('pp1', 5) => ChannelName::GUARD,
        ChannelName::estateGuards('pp1') => ChannelName::ESTATE_GUARDS,
        ChannelName::estatePasses('pp1') => ChannelName::ESTATE_PASSES,
        ChannelName::approval('pp1', 77) => ChannelName::APPROVAL,
        ChannelName::household('pp1', 14) => ChannelName::HOUSEHOLD,
        ChannelName::resident('pp1', 9) => ChannelName::RESIDENT,
        ChannelName::community('pp1') => ChannelName::COMMUNITY,
        ChannelName::phase('pp1', ChannelName::phaseKey('Phase 2')) => ChannelName::PHASE,
        ChannelName::claim('pp1', 31) => ChannelName::CLAIM,
        ChannelName::pulse() => ChannelName::PULSE_FAMILY,
    ];

    foreach ($formatted as $name => $family) {
        expect(ChannelName::parse((string) $name)?->family)->toBe($family, (string) $name);
    }

    expect(ChannelName::user(12))->toBe('user.12')
        ->and(ChannelName::estateRecord('pp1', 'facilities', 'ticket', '1039'))->toBe('estate.pp1.facilities.ticket.1039');

    foreach ([
        fn () => ChannelName::user(0),
        fn () => ChannelName::user('012'),
        fn () => ChannelName::estateFeed('PP1', 'security'),
        fn () => ChannelName::estateFeed('pp1', 'alerts'),
        fn () => ChannelName::geminiTenantFeed('pp1', 'dashboard'),
        fn () => ChannelName::geminiRecord('pp1', 'dispatch', 'guard', 4),
        fn () => ChannelName::estateRecord('pp1', 'dues', 'unit', 4),
        fn () => ChannelName::household('pp1', -4),
        fn () => ChannelName::phase('pp1', 'Phase 2'),
        fn () => ChannelName::phaseKey('   '),
    ] as $i => $bad) {
        expect($bad)->toThrow(InvalidArgumentException::class);
    }
});

it('keys a phase by the first ten hex characters of sha1 of its lower-cased, trimmed block', function () {
    expect(ChannelName::phaseKey('Phase 2'))->toBe(substr(sha1('phase 2'), 0, 10))
        ->and(ChannelName::phaseKey('  PHASE 2 '))->toBe(ChannelName::phaseKey('phase 2'))
        ->and(ChannelName::phaseKey('Phase 3'))->not->toBe(ChannelName::phaseKey('Phase 2'));
});
