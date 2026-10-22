<?php

declare(strict_types=1);

use App\Live\SummaryPolicy;

/*
|--------------------------------------------------------------------------
| What a live summary may never hold (DESIGN.md §4.1 rule 3, §4.2)
|--------------------------------------------------------------------------
|
| The log is kept for a week and is read back by every catch-up, so it must never become a second
| store of anybody's personal data. Rows carry only a SAFE summary; anything a screen needs beyond
| it is hydrated when published, from the current row, for audiences already entitled to it, and
| never stored. This is the list that holds the line, checked at write time by LiveBus and audited
| over the stored rows by gate step 14 (f-web-probe), which reads `violations()`.
|
*/

/**
 * §4.2, word for word, grouped as the design groups it. Pinned here so the list cannot shrink
 * (or quietly grow) without this file changing in the same commit.
 *
 * @return list<string>
 */
function summaryPolicyDesignList(): array
{
    return [
        // Names
        'who', 'raised_by_name', 'guard_name', 'visitor_name', 'reported_by_name', 'technician_name',
        'responded_by_name', 'logged_by_name', 'officer',
        // Free text and labels
        'subject', 'unit_reference', 'lot', 'detail', 'description', 'body', 'resolution',
        'resolution_note', 'reason', 'note', 'handover_note', 'virtual_link',
        // Contact details and location
        'phone', 'e164', 'email', 'latitude', 'longitude', 'accuracy',
        // Credentials and identifiers
        'token', 'code', 'nonce', 'public_key', 'employee_number', 'psra_number', 'id_type', 'id_number',
        'device_id', 'idempotency_key',
        // Supervisor and geofence data
        'mock_location_flag', 'geofence_distance_m', 'overridden_by', 'override_reason',
        // Money (and any *_minor, below)
        'amount', 'balance', 'total', 'arrears', 'bucket',
        // Standing and ballots
        'access_restricted', 'option_id', 'option_ids', 'choice', 'choices', 'choice_id', 'choice_ids',
        // Identifiers on shared channels
        'account_id', 'resident_account_id', 'household_id',
    ];
}

it('forbids exactly the keys §4.2 names, and the words its categories name', function () {
    $design = summaryPolicyDesignList();
    sort($design);

    $forbidden = SummaryPolicy::FORBIDDEN;
    sort($forbidden);

    // Pinned too, so neither list can shrink without this file changing in the same commit.
    expect($forbidden)->toBe($design)
        ->and(SummaryPolicy::FORBIDDEN_WORDS)->toBe([
            'phone', 'email', 'msisdn', 'latitude', 'longitude', 'accuracy',
            'token', 'nonce', 'password', 'apikey', 'privatekey',
            'geofence',
            'amount', 'balance', 'total', 'arrears', 'bucket', 'price', 'salary', 'wage',
            'choice', 'vote',
        ])
        ->and(SummaryPolicy::FORBIDDEN_ENDINGS)->toBe(['name', 'e164', 'lat', 'lng', 'lon', 'long', 'gps', 'code', 'pin', 'otp', 'secret', 'minor']);
});

it('refuses a key that names a forbidden category however the key is built', function (string $key) {
    expect(SummaryPolicy::isForbidden($key))->toBeTrue()
        ->and(SummaryPolicy::violations([$key => 'x']))->toBe([$key])
        ->and(fn () => SummaryPolicy::assert('probe.changed', ['status' => 'open', $key => 1]))
        ->toThrow(LogicException::class, $key);
})->with([
    // The review's near-misses: contact details, locations, credentials, money and names.
    'visitor_phone', 'phone_e164', 'technician_phone', 'contact_email', 'resident_email',
    'lat', 'lng', 'lon', 'gps_latitude', 'pass_token', 'access_code', 'pin',
    'amount_due', 'balance_due', 'total_paid', 'resident_name',
    // The same, joined or camel-cased, and in the plural.
    'visitorPhone', 'contactemail', 'gpsLat', 'gps_lng', 'long', 'AmountDue', 'subtotal', 'passcode',
    'username', 'pins', 'codes', 'household_ids', 'account_ids', 'notes',
    // Credentials, money and ballots by their own words.
    'mfa_secret', 'api_key', 'password_hash', 'otp', 'deposit_minor', 'unit_price', 'salary_band',
    'wage_rate', 'vote_count', 'selected_choice', 'geofence_ok', 'msisdn', 'e164_number', 'minor_units',
]);

it('does not refuse a key merely because a short word sits inside one of its words', function (string $key) {
    expect(SummaryPolicy::isForbidden($key))->toBeFalse();
})->with([
    'status', 'state', 'kind', 'effect', 'source', 'section', 'keys', 'ids', 'count', 'at',
    'unit_id', 'ballot_id', 'alert_id', 'pass_id', 'shift_id', 'template_id', 'announcement_id',
    'secretary_id', 'latest_at', 'escalated_at', 'is_pinned', 'is_drill', 'renamed', 'classification',
]);

it('refuses every forbidden key, at write time and in the audit', function (string $key) {
    expect(SummaryPolicy::violations([$key => 'x']))->toBe([$key])
        ->and(fn () => SummaryPolicy::assert('probe.changed', ['status' => 'open', $key => 1]))
        ->toThrow(LogicException::class, $key);
})->with(summaryPolicyDesignList());

it('refuses any money key ending _minor', function (string $key) {
    expect(fn () => SummaryPolicy::assert('probe.changed', [$key => 5000]))->toThrow(LogicException::class, $key);
})->with(['amount_minor', 'deposit_minor', 'total_due_minor', 'fee_minor', 'feeMinor']);

it('refuses a value that is not an enum, a record id, a count or a timestamp, whatever its key', function (mixed $value) {
    expect(SummaryPolicy::violations(['status' => $value]))->toBe(['status'])
        ->and(fn () => SummaryPolicy::assert('probe.changed', ['status' => $value]))
        ->toThrow(LogicException::class, 'not an enum, a record id, a count or a timestamp');
})->with([
    'free text' => 'the gate was left open',
    'a name' => 'Ali Khan',
    'an email address' => 'ali@example.com',
    'an international phone number' => '+923001234567',
    'a phone number with spaces' => '0300 1234567',
    'a coordinate pair' => '31.52,74.35',
    'a long token' => str_repeat('a', 65),
    'a line break' => "open\nclosed",
    'a trailing line break' => "open\n",
    'a timestamp with a trailing line break' => "2026-10-02 14:03:11\n",
]);

it('finds a coordinate under a harmless key in a stored summary, which a write refuses as not flat', function () {
    expect(SummaryPolicy::violations(['status' => 31.5204, 'at' => ['x' => 74.35]]))->toBe(['status', 'at.x'])
        ->and(fn () => SummaryPolicy::assert('probe.changed', ['status' => 31.5204]))->toThrow(LogicException::class, 'not flat');
});

it('refuses a bad value inside a list, and finds it by its index', function () {
    expect(SummaryPolicy::violations(['keys' => ['alert:55:open', 'Ali Khan'], 'status' => 'open']))->toBe(['keys.1'])
        ->and(fn () => SummaryPolicy::assert('probe.changed', ['keys' => ['alert:55:open', 'Ali Khan']]))
        ->toThrow(LogicException::class, 'keys.1');
});

it('allows the values a summary is for, timestamps in every shape the application writes included', function (mixed $value) {
    expect(SummaryPolicy::violations(['value' => $value]))->toBe([]);

    SummaryPolicy::assert('probe.changed', ['value' => $value]);
})->with([
    'an enum' => 'acknowledged',
    'an upper-case enum' => 'PANIC',
    'a record id' => 1039,
    'a record id as text' => '1039',
    'a key' => 'alert:55:open',
    'a ULID' => '01J9ZC3K8Q0ZC6B2V5T3M4N7PX',
    'an invoice number' => 'INV-2026-0001',
    'a UTC timestamp' => '2026-10-02T14:03:11.221Z',
    'a database timestamp' => '2026-10-02 14:03:11',
    'a timestamp with its offset' => '2026-10-02T14:03:11+05:00',
    'a date' => '2026-10-02',
    'the empty string' => '',
    'a boolean' => false,
    'null' => null,
    'sixty-four characters' => str_repeat('a', 64),
]);

it('refuses a forbidden key whatever its case or spelling', function (string $key) {
    expect(SummaryPolicy::violations([$key => 'x']))->toBe([$key]);
})->with(['Who', 'WHO', 'raisedByName', 'ResolutionNote', 'Amount_Minor', 'amountMinor', 'e-mail', 'Lot']);

it('finds a forbidden key at any depth of a stored summary', function () {
    expect(SummaryPolicy::violations(['refs' => [['who' => 'x'], ['status' => 'ok']], 'meta' => ['deep' => ['latitude' => 1.2]]]))
        ->toBe(['refs.0.who', 'meta.deep.latitude']);
});

it('allows what a summary is for: enums, booleans, counts, record ids, timestamps and lists of them', function () {
    SummaryPolicy::assert('alert.acknowledged', [
        'status' => 'acknowledged',
        'kind' => 'panic',
        'is_drill' => false,
        'count' => 3,
        'alert_id' => 55,
        'at' => '2026-10-02T14:03:11.221Z',
        'keys' => ['alert:55:open', 'alert:56:open'],
        'tile' => null,
    ]);

    SummaryPolicy::assert('probe.changed', []);

    expect(SummaryPolicy::violations(['status' => 'open', 'ids' => [1, 2]]))->toBe([]);
});

it('refuses a value that is not flat', function (array $summary) {
    expect(fn () => SummaryPolicy::assert('probe.changed', $summary))->toThrow(LogicException::class);
})->with([
    'a nested map' => [['status' => ['state' => 'open']]],
    'a list of maps' => [['rows' => [['id' => 1]]]],
    'an object' => [['at' => new DateTimeImmutable]],
    'a float' => [['score' => 1.5]],
]);

it('refuses a list where a map belongs', function () {
    expect(fn () => SummaryPolicy::assert('probe.changed', ['open', 'closed']))->toThrow(LogicException::class, 'keys');
});

it('holds a summary to 1 KB of JSON', function () {
    // Filled with the longest tokens a summary allows, topped up to exactly the limit.
    $filled = function (int $bytes): array {
        $keys = [];

        while (strlen((string) json_encode(['keys' => [...$keys, str_repeat('a', 64)]])) <= $bytes) {
            $keys[] = str_repeat('a', 64);
        }

        $keys[] = str_repeat('a', $bytes - strlen((string) json_encode(['keys' => [...$keys, '']])));

        return ['keys' => $keys];
    };

    $fits = $filled(SummaryPolicy::MAX_BYTES);
    $over = $filled(SummaryPolicy::MAX_BYTES + 1);

    expect(strlen((string) json_encode($fits)))->toBe(1024)
        ->and(strlen((string) json_encode($over)))->toBe(1025)
        ->and(SummaryPolicy::violations($over))->toBe([]);

    SummaryPolicy::assert('probe.changed', $fits);

    expect(fn () => SummaryPolicy::assert('probe.changed', $over))->toThrow(LogicException::class, '1024');
});
