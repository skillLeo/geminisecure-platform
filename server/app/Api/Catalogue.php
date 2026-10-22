<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DuressController;
use App\Http\Controllers\Api\V1\GateEventController;
use App\Http\Controllers\Api\V1\Guard\ClockInOverrideController;
use App\Http\Controllers\Api\V1\Guard\GateController;
use App\Http\Controllers\Api\V1\Guard\IncidentsController;
use App\Http\Controllers\Api\V1\Guard\MessagesController;
use App\Http\Controllers\Api\V1\Guard\OrdersController;
use App\Http\Controllers\Api\V1\Guard\PatrolController;
use App\Http\Controllers\Api\V1\Guard\PayslipsController;
use App\Http\Controllers\Api\V1\Guard\PresenceController;
use App\Http\Controllers\Api\V1\Guard\ProfileController;
use App\Http\Controllers\Api\V1\Guard\RequestsController;
use App\Http\Controllers\Api\V1\Guard\ResponseController;
use App\Http\Controllers\Api\V1\Guard\ShiftsController;
use App\Http\Controllers\Api\V1\Guard\SyncController;
use App\Http\Controllers\Api\V1\Guard\TrackingController;
use App\Http\Controllers\Api\V1\LiveController;
use App\Http\Controllers\Api\V1\PassKeyController;
use App\Http\Controllers\Api\V1\PassVerificationController;
use App\Http\Controllers\Api\V1\PushController;
use App\Http\Controllers\Api\V1\Resident\AccountController;
use App\Http\Controllers\Api\V1\Resident\AuthController;
use App\Http\Controllers\Api\V1\Resident\BookingsController;
use App\Http\Controllers\Api\V1\Resident\CardIntentsController;
use App\Http\Controllers\Api\V1\Resident\CardsController;
use App\Http\Controllers\Api\V1\Resident\CommunityController;
use App\Http\Controllers\Api\V1\Resident\DeviceKeyController;
use App\Http\Controllers\Api\V1\Resident\DuesController;
use App\Http\Controllers\Api\V1\Resident\ElectionsController;
use App\Http\Controllers\Api\V1\Resident\EstatesController;
use App\Http\Controllers\Api\V1\Resident\HouseholdItemsController;
use App\Http\Controllers\Api\V1\Resident\HouseholdRemovalsController;
use App\Http\Controllers\Api\V1\Resident\LiveChatController;
use App\Http\Controllers\Api\V1\Resident\MeController;
use App\Http\Controllers\Api\V1\Resident\NotificationEmailController;
use App\Http\Controllers\Api\V1\Resident\PanicContactsController;
use App\Http\Controllers\Api\V1\Resident\PassesController;
use App\Http\Controllers\Api\V1\Resident\SessionsController;
use App\Http\Controllers\Api\V1\Resident\SupportContactController;
use App\Http\Controllers\Api\V1\RingController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StandingOrderController;

/**
 * Every /api/v1 endpoint, described once (13 D1, C4).
 *
 * THIS IS THE SPECIFICATION, AND EVERYTHING ELSE READS IT:
 *
 *   routes/api.php            registers each route from its entry — method, path,
 *                             middleware, and the ability computed from
 *                             `capability` + `access` through `AppMatrix`
 *   MOBILE_HANDOFF.md         `php artisan api:handoff` writes the endpoint tables
 *                             from these entries, and `MobileHandoffTest` fails
 *                             when the file and the catalogue disagree
 *   the contract tests        every response is checked against `response`: a key
 *                             not listed is a failure, which is the allowlist that
 *                             enforces invariant 2 on every guard route
 *
 * An entry:
 *
 *   method, uri, action       the route
 *   apps                      which apps' tokens reach it (empty: no token)
 *   capability, access        what it needs from `AppMatrix`
 *   write                     writes take Idempotency-Key and X-Device-Time
 *   legacy                    the first seven endpoints, built before the headers
 *                             existed: both are honoured, neither is required
 *   pending                   a Resident App account that has not been approved may reach it
 *   throttle                  the rate limiter
 *   status                    the success status
 *   request                   field => "type · required|optional · what it is"
 *   response                  key path => what it is. `items.*.id` is every item's id.
 *   errors                    "status code" => when; the common errors are listed once
 *   offline                   what the app does with no signal
 */
final class Catalogue
{
    /** How far a handset's clock may disagree before `clock_skewed` is true. */
    public const CLOCK_SKEW_SECONDS = 120;

    /** The errors any authenticated endpoint can return, documented once. */
    public const COMMON_ERRORS = [
        '401 unauthenticated' => 'No token, or a token that has been revoked.',
        '401 session_expired' => 'The token is real but its IDLE WINDOW has closed: nothing was sent from this phone for thirty minutes (`config/api.php` · `session_idle_minutes`). It is a different fact from `unauthenticated` and needs a different sentence — the handset was not revoked by anybody, it simply went quiet. The window SLIDES: every authenticated request renews it, so an app in use never sees this. Show the message as it is sent, keep everything captured offline, and offer the way back in.',
        '403 missing_ability' => 'The token does not carry the ability this endpoint needs.',
        '403 wrong_app' => 'A Guard App token on a Resident App endpoint, or the reverse.',
        '403 guard_not_active' => 'The guard the token belongs to is suspended, on leave or no longer employed.',
        '403 account_pending' => 'A Resident App account whose unit claim is not yet approved.',
        '403 account_suspended' => 'The estate withdrew this resident\'s access.',
        '403 account_revoked' => 'THE ESTATE OFFICE ENDED THIS ACCOUNT\'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household\'s own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance.',
        '403 not_for_your_access_level' => 'The signed-in person\'s ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed.',
        '403 no_site' => 'The token\'s guard or account is not attached to an estate on this platform.',
        '404 not_found' => 'Nothing at this address, or nothing this handset may see — the two are deliberately the same.',
        '409 request_in_progress' => 'A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below).',
        '409 idempotent_answer_missing' => 'It is not known, or no longer recorded, whether the write went through: its first attempt died before it could answer (a server restart mid-request, answered once it is five minutes old), or the answer stored for this key is gone (pruned, or the database edited by hand). FINAL — do not send the act again on this key or any other; move past it in the queue and tell the person to check whether it went through. A panic or duress is never answered this: it is run again, and the alert it raised is handed back.',
        '422 validation_failed' => 'A field is missing or malformed. `errors` maps each field to its messages.',
        '422 device_time_required' => 'A write without X-Device-Time.',
        '422 device_time_invalid' => 'X-Device-Time is not an ISO 8601 timestamp.',
        '422 idempotency_key_required' => 'A write without Idempotency-Key.',
        '422 idempotency_key_reused' => 'The key was already used for a different request. Use a new key for a new act.',
        '429 rate_limited' => 'Too many requests from this handset. `Retry-After` says when; retry with the same key.',
    ];

    /**
     * @return array<string, array{method: string, uri: string, action: array{0: class-string, 1: string}, apps: list<string>, capability: string|null, access: string, write: bool, legacy?: bool, pending?: bool, opaque?: bool, batch?: bool, throttle: string, status: int, where?: array<string, string>, summary: string, request: array<string, string>, response: array<string, string>, errors: array<string, string>, offline: string}>
     */
    public static function endpoints(): array
    {
        $endpoints = [
            ...self::devices(),
            ...self::session(),
            ...self::push(),
            ...self::live(),
            ...self::legacyGuard(),
            ...self::guardShifts(),
            ...self::guardPost(),
            ...self::guardGate(),
            ...self::guardReports(),
            ...self::guardResponse(),
            ...self::guardRing(),
            ...self::guardSelf(),
            ...self::guardSync(),
            ...self::residentAccount(),
            ...self::residentPasses(),
            ...self::residentDues(),
            ...self::residentCommunity(),
        ];

        /*
         * Every write's response carries both clocks — `DeviceTime` adds them to
         * each successful JSON object a write returns — so every write's entry
         * documents them, whether or not its controller also sets them.
         */
        foreach ($endpoints as $name => $entry) {
            if ($entry['write']) {
                $endpoints[$name]['response'] += self::CLOCKS;
            }
        }

        return $endpoints;
    }

    /**
     * ONE TIME RULE. Every time in every response is a full ISO 8601 instant IN UTC
     * (`2026-09-20T12:00:00+00:00`) and is shown in the site's zone, which each app is handed
     * as `timezone` where it first learns its site. A calendar DATE (`YYYY-MM-DD`) is a date,
     * not an instant, and an opening hour (`HH:MM:SS`) is a time of day on the site's clock.
     */
    private const ZONE = 'the site\'s IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset\'s, never a fixed offset)';

    /** The response keys a write echoes: the server's clock beside the handset's. */
    private const CLOCKS = [
        'server_time' => 'the server\'s time — UTC instant; show in the site\'s zone (`timezone`)',
        'device_time' => 'the handset\'s time, as sent in X-Device-Time — UTC instant; show in the site\'s zone (`timezone`)',
        'clock_skewed' => 'true when the device time is more than two minutes AHEAD of the server\'s. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged',
    ];

    /**
     * A shift as every guard endpoint draws it.
     *
     * @return array<string, string>
     */
    private static function shiftKeys(string $prefix): array
    {
        return [
            $prefix.'id' => 'the shift',
            $prefix.'status' => '`rostered`, `open`, `on_duty`, `completed` or `missed`',
            $prefix.'post.id' => 'the post',
            $prefix.'post.name' => 'e.g. "Main Gate"',
            $prefix.'site.id' => 'the estate',
            $prefix.'site.name' => 'its name',
            $prefix.'site.timezone' => self::ZONE,
            $prefix.'rostered_start' => 'UTC instant; show in the site\'s zone (`timezone`)',
            $prefix.'rostered_end' => 'UTC instant; show in the site\'s zone (`timezone`)',
            $prefix.'actual_start' => 'the first clock-in the server recorded, or null — UTC instant; show in the site\'s zone (`timezone`)',
            $prefix.'actual_end' => 'the clock-out, or null — UTC instant; show in the site\'s zone (`timezone`)',
        ];
    }

    /**
     * The fence a clock-in is judged against, as `shifts.preflight` and `shifts.me.current` carry it (M12 §1).
     *
     * @return array<string, string>
     */
    private static function fenceKeys(string $prefix): array
    {
        return [
            $prefix.'source' => '`post` (the rostered post\'s own), `estate` (the post has none, so the estate\'s) or `none` (neither is placed)',
            $prefix.'name' => 'the post\'s name, or the estate\'s when its fence stands in',
            $prefix.'latitude' => 'degrees north — the fence\'s centre, or null when `source` is `none`',
            $prefix.'longitude' => 'degrees east — the fence\'s centre, or null',
            $prefix.'radius_m' => 'metres, or null',
            $prefix.'good_accuracy_m' => 'metres — the accuracy a phone takes fixes until it reaches (50)',
            $prefix.'poor_accuracy_m' => 'metres — past this the position is not established (200)',
            $prefix.'max_margin_m' => 'metres — the most margin a fix earns (50)',
            $prefix.'margin_per_accuracy' => 'how many times the fix\'s accuracy the margin is, up to `max_margin_m` (2: a phone\'s accuracy is its 68% radius; twice it is about 95%)',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardShifts(): array
    {
        $guard = [AppMatrix::GUARD];
        $shiftWhere = ['shift' => '[0-9]+'];
        $notYours = ['403 not_your_shift' => 'The shift is rostered to another guard at your own site.', '404 not_found' => 'No such shift — an id nobody has, and an id belonging to another estate, answer identically.'];
        $breakKeys = [
            'break_id' => 'the break',
            'shift_id' => 'its shift',
            'started_at' => 'the server\'s time the break started — UTC instant; show in the site\'s zone (`timezone`)',
            'ended_at' => 'when it ended, or null while it runs — UTC instant; show in the site\'s zone (`timezone`)',
        ];

        return [
            'shifts.me.current' => [
                'method' => 'GET', 'uri' => 'shifts/me/current', 'action' => [ShiftsController::class, 'current'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The dashboard: the shift the guard is on, or the next one rostered to them. Board guard-app-01.',
                'request' => [],
                'response' => [
                    'shift' => 'null when nothing is running or rostered ahead',
                    ...self::shiftKeys('shift.'),
                    'shift.on_break' => 'true while a break is open',
                    'shift.break_minutes' => 'breaks taken on this shift so far',
                    'shift.previous_handover_note' => 'what the last guard on this post left for the next, or null',
                    'shift.orders_to_acknowledge' => 'how many of the post\'s order sets this guard has not acknowledged at their current version',
                    'shift.colleagues.*.name' => 'another guard on duty at the same estate now',
                    'shift.colleagues.*.post' => 'their post',
                    ...self::fenceKeys('shift.fence.'),
                    'shift.supervisor_phone' => 'the number a guard at the gate calls for a supervisor, set per estate in Sites & geofences — or null, when none is set',
                ],
                'errors' => [],
                'offline' => 'Show the cached response with its age. Clock-in works offline against the cached `fence`, by the same rule as `shifts.preflight`; this screen reflects it after the next sync.',
            ],
            'shifts.me' => [
                'method' => 'GET', 'uri' => 'shifts/me', 'action' => [ShiftsController::class, 'mine'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'My hours: the guard\'s shifts in a range, with hours worked and night hours. Board guard-app-05. Hours, never pay.',
                'request' => [
                    'from' => 'date · required (query) · YYYY-MM-DD',
                    'to' => 'date · required (query) · on or after `from`, at most 31 days later',
                ],
                'response' => [
                    'from' => 'YYYY-MM-DD — a calendar date at the guard\'s site, as asked for; not an instant',
                    'to' => 'YYYY-MM-DD — a calendar date at the guard\'s site, as asked for; not an instant',
                    'timezone' => self::ZONE,
                    ...self::shiftKeys('items.*.'),
                    'items.*.worked_hours' => 'clock-in to clock-out less breaks, two decimals',
                    'items.*.night_hours' => 'the part of that between 22:00 and 06:00 ON THE SITE\'S CLOCK (the shift\'s `site.timezone`)',
                    'totals.worked_hours' => 'sum',
                    'totals.night_hours' => 'sum',
                    'totals.shifts' => 'count',
                ],
                'errors' => ['422 range_too_long' => 'More than 31 days asked for at once.'],
                'offline' => 'Serve the cached range.',
            ],
            'shifts.preflight' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/preflight', 'action' => [ShiftsController::class, 'preflight'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => $shiftWhere,
                'summary' => 'Before clock-in: the window, the handset binding, the geofence and location integrity — boards guard-app-01 and -10. The geofence is the ROSTERED POST\'s, else the estate\'s, else none (M12 §1): the fix is a circle, its accuracy counted as margin up to `fence.max_margin_m`; past `fence.poor_accuracy_m` the position is not established. `shifts.clock_in` judges the same way and refuses a live start this says is not allowed. The coordinates are compared and not stored.',
                'request' => [
                    'latitude' => 'number · optional · omit when location is refused',
                    'longitude' => 'number · optional',
                    'accuracy_m' => 'integer · optional · the fix\'s accuracy in metres',
                    'mock_location' => 'boolean · optional · the OS reports a mocked location',
                    'device_uid' => 'string · optional · the enrolled install UID',
                ],
                'response' => [
                    'shift_id' => 'the shift',
                    'allowed' => 'false when a check blocks the start: outside the fence, no position established, the window, the binding. `shifts.clock_in` refuses the same start while the guard is there',
                    'flagged_for_review' => 'true when the location looks mocked — clock-in proceeds and the Head of Security is shown it',
                    'distance_m' => 'metres from the fence\'s centre, or null',
                    'radius_m' => 'the fence\'s radius, or null when there is no fence',
                    'within_geofence' => 'true inside (or inside within the margin), false outside or with no position sent, null when no fence is configured or the fix is too inaccurate to judge — read `outcome` for which',
                    'outcome' => '`inside` · `inside_margin` (outside the radius by less than the fix\'s accuracy) · `outside` · `poor_fix` (accuracy worse than `fence.poor_accuracy_m`) · `no_position` · `no_geofence` (neither the post nor the estate is placed; the start is allowed and marked for the office)',
                    ...self::fenceKeys('fence.'),
                    'beyond_m' => 'metres past the radius, or null',
                    'accuracy_m' => 'the fix\'s accuracy as sent, or null',
                    'margin_m' => 'how much of that accuracy was counted, or null',
                    'bearing_deg' => 'which way the fence\'s centre lies FROM the guard, degrees clockwise from north, or null',
                    'direction' => '`north`, `north-east` … `north-west`: the same, in a word — or null',
                    'override_reason' => 'when a supervisor\'s PIN may start this shift over the geofence: `outside_geofence` or `position_unconfirmed`; null otherwise',
                    'supervisor_phone' => 'the number a guard at the gate calls for a supervisor, or null when the estate has none set',
                    'reviewer' => 'true ONLY for an App Store / Play reviewer\'s demonstration account. The geofence was then judged from the POST\'S OWN surveyed coordinates rather than this handset\'s, and the substitution is written to the audit log every time.',
                    'passcode_identity' => 'true ONLY for that same account: this phone may confirm the guard with its own PASSCODE instead of a fingerprint or face. False for everybody else, who still go to the supervisor override.',
                    'checks.*.key' => '`window`, `device`, `geofence` or `location_integrity`',
                    'checks.*.passed' => 'boolean',
                    'checks.*.detail' => 'the sentence to show',
                ],
                'errors' => $notYours,
                'offline' => 'Run the window and device checks on the handset against the cached shift, and the geofence against the cached `shift.fence` by the same rule; clock in and queue the fix with it.',
            ],
            'shifts.break.start' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/break/start', 'action' => [ShiftsController::class, 'breakStart'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-shift-clock', 'status' => 201, 'where' => $shiftWhere,
                'summary' => 'Start a break. A break already open is returned as it is (200).',
                'request' => [],
                'response' => $breakKeys,
                'errors' => [...$notYours, '409 shift_not_on_duty' => 'The guard is not clocked on to this shift.'],
                'offline' => 'Queue with the device time of the tap.',
            ],
            'shifts.break.end' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/break/end', 'action' => [ShiftsController::class, 'breakEnd'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-shift-clock', 'status' => 200, 'where' => $shiftWhere,
                'summary' => 'End the open break.',
                'request' => [],
                'response' => $breakKeys,
                'errors' => [...$notYours, '409 no_break_open' => 'No break is open on this shift.'],
                'offline' => 'Queue after the break start it closes.',
            ],
            'shifts.open' => [
                'method' => 'GET', 'uri' => 'shifts/open', 'action' => [ShiftsController::class, 'open'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Open shifts at the guard\'s estate in the next 14 days. Board guard-app-06. Hours, not pay.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the open shift',
                    'items.*.post.id' => 'the post',
                    'items.*.post.name' => 'its name',
                    'items.*.site.id' => 'the estate',
                    'items.*.site.name' => 'its name',
                    'items.*.site.timezone' => self::ZONE,
                    'items.*.rostered_start' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'items.*.rostered_end' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'items.*.hours' => 'length in hours',
                    'items.*.claim_status' => 'null, or this guard\'s claim: `pending`, `approved`, `declined`',
                    'items.*.rest_warning' => 'true when it falls within 11 hours of another of the guard\'s shifts',
                ],
                'errors' => [],
                'offline' => 'Online only — an open shift is first come, and a cached list is stale.',
            ],
            'shifts.claim' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/claim', 'action' => [ShiftsController::class, 'claim'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201, 'where' => $shiftWhere,
                'summary' => 'Claim an open shift. A supervisor approves; the claim\'s status comes back on `/sync/pull`.',
                'request' => [],
                'response' => [
                    'claim_id' => 'the claim',
                    'shift_id' => 'the shift',
                    'status' => '`pending`',
                    'claimed_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'requires_approval' => 'always true',
                ],
                'errors' => [
                    '404 not_found' => 'No such shift at the guard\'s estate.',
                    '409 shift_not_open' => 'Already filled.',
                    '409 shift_started' => 'Already started.',
                ],
                'offline' => 'Online only.',
            ],
            // A supervisor's PIN in place of the phone's identity check. `opaque`: the PIN never enters the stored request hash.
            'shifts.clock_in_override' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/clock-in/override', 'action' => [ClockInOverrideController::class, 'store'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::WRITE, 'write' => true,
                'opaque' => true, 'batch' => false,
                'throttle' => 'api-supervisor-override', 'status' => 200, 'where' => $shiftWhere,
                'summary' => 'Start the shift on a SUPERVISOR\'S PIN when the phone could not confirm WHO is holding it — three failed matches (board guard-app-39), or no fingerprint or face set up — or WHERE: outside the rostered post\'s geofence, or no position established (M12 §1.3). The fix is judged by the same rule as `shifts.clock_in` and kept on the shift; a mocked location is flagged. The server checks the PIN, flags the shift, writes the audit log and tells the Head of Security. Never resend on a refusal; use a NEW Idempotency-Key for each try. Never keep or log the PIN.',
                'request' => [
                    'supervisor' => 'string · required · the supervisor\'s console sign-in address (their email), as the office knows them',
                    'pin' => 'string · required · the supervisor\'s 6-digit override PIN — typed, sent once, never stored on the phone',
                    'reason' => 'string · required · `biometric_failed`, `no_biometrics`, `outside_geofence` or `position_unconfirmed` — the last two as `shifts.preflight.override_reason` said',
                    'latitude' => 'number · optional · with `longitude`; judged against the fence and kept on the shift the PIN starts',
                    'longitude' => 'number · optional',
                    'accuracy_m' => 'integer · optional · the fix\'s accuracy in metres',
                    'geofence_distance_m' => 'integer · optional · the phone\'s own distance from the post, used only when no position is sent',
                    'mock_location' => 'boolean · optional · the OS reports a mocked location — recorded and flagged, never refused',
                ],
                'response' => [
                    'id' => 'the shift',
                    'status' => '`on_duty`',
                    'actual_start' => 'the server\'s record of when the shift started — UTC instant; show in the site\'s zone (`timezone`)',
                    'actual_end' => 'null — UTC instant; show in the site\'s zone (`timezone`)',
                    'mock_location_flag' => 'true when the handset reported a mocked location; recorded, never refused',
                    'overridden' => 'true — this start was vouched for by a supervisor, not confirmed by the phone',
                    'override_reason' => '`biometric_failed`, `no_biometrics`, `outside_geofence` or `position_unconfirmed`',
                    'overridden_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'overridden_by' => 'the supervisor\'s name, to show the guard who vouched for them',
                ],
                'errors' => [
                    '404 not_found' => 'No such shift, or a shift rostered to another guard — the two are the same answer.',
                    '403 override_refused' => 'The supervisor and PIN were not accepted. ONE answer for an unknown address, somebody who is not a supervisor at this estate, no PIN set, a locked PIN and a wrong PIN. Five wrong tries lock the PIN until the supervisor sets a new one in the console.',
                    '409 shift_already_started' => 'The shift has a start already. Nothing was recorded against a supervisor.',
                ],
                'offline' => 'Online only — a PIN cannot be checked without the server. Never queued, never in `sync/batch`, never stored. Offline: say so plainly, and tell the guard to move to signal or call their supervisor or the control room.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardPost(): array
    {
        $guard = [AppMatrix::GUARD];
        $checkKeys = [
            'check_id' => 'the check',
            'outcome' => '`pending`, `passed`, `missed`, `failed` or `declined`',
            'issued_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'respond_by' => 'two minutes after issue — UTC instant; show in the site\'s zone (`timezone`)',
            'responded_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
        ];

        return [
            'orders.current' => [
                'method' => 'GET', 'uri' => 'sites/{site}/standing-orders/current', 'action' => [OrdersController::class, 'current'],
                'apps' => $guard, 'capability' => 'orders', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['site' => '[a-z0-9]+'],
                'summary' => 'The orders in force for the guard\'s post and the company, each with its version id, and the guard\'s acknowledgement history. Boards guard-app-03 and -04.',
                'request' => [],
                'response' => [
                    'site_id' => 'the site',
                    'items.*.set_id' => 'the order set',
                    'items.*.version_id' => 'THE id to acknowledge',
                    'items.*.version' => 'its number',
                    'items.*.title' => 'title',
                    'items.*.effective_on' => 'YYYY-MM-DD',
                    'items.*.body' => 'the full text',
                    'items.*.requires_acknowledgement' => 'true for the post\'s own orders',
                    'items.*.acknowledged_at' => 'when this guard acknowledged THIS version, or null — UTC instant; show in the site\'s zone (`timezone`)',
                    'history.*.set_id' => 'order set',
                    'history.*.title' => 'its title',
                    'history.*.version' => 'the version acknowledged',
                    'history.*.acknowledged_at' => 'when — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => ['403 wrong_site' => 'A site other than the guard\'s own.'],
                'offline' => 'Cache and show the cached text; queue acknowledgements.',
            ],
            'orders.acknowledge' => [
                'method' => 'POST', 'uri' => 'standing-orders/{version}/acknowledge', 'action' => [OrdersController::class, 'acknowledge'],
                'apps' => $guard, 'capability' => 'orders', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-shift-clock', 'status' => 200, 'where' => ['version' => '[0-9]+'],
                'summary' => 'Acknowledge the version the guard read, by its version id. A revision published meanwhile has a different id, so an old one is refused.',
                'request' => [],
                'response' => [
                    'set_id' => 'the order set',
                    'version_id' => 'the version acknowledged',
                    'version' => 'its number',
                    'acknowledged_at' => 'the first acknowledgement of this version, however many were sent — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => [
                    '404 not_found' => 'No such version.',
                    '409 orders_changed' => 'Revised since, not the guard\'s post, or the guard cannot stand the post (licence lapsed).',
                ],
                'offline' => 'Queue. If a revision landed meanwhile the sync answers 409; show the new version from `/sync/pull`.',
            ],
            'patrol.checkpoints' => [
                'method' => 'GET', 'uri' => 'sites/{site}/checkpoints', 'action' => [PatrolController::class, 'checkpoints'],
                'apps' => $guard, 'capability' => 'patrol', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['site' => '[a-z0-9]+'],
                'summary' => 'The site\'s active patrol checkpoints in tour order, with what this guard has scanned today. Board guard-app-02.',
                'request' => [],
                'response' => [
                    'site_id' => 'the site',
                    'items.*.id' => 'the checkpoint',
                    'items.*.label' => 'e.g. "Pool gate"',
                    'items.*.sequence' => 'tour order',
                    'items.*.post_id' => 'the post it belongs to, or null',
                    'items.*.latitude' => 'degrees north where it stands, or null when it has not been placed on the map yet (M11 §6)',
                    'items.*.longitude' => 'degrees east, or null with latitude',
                    'items.*.last_scanned_at' => 'this guard\'s last scan today, or null — UTC instant; show in the site\'s zone (`timezone`)',
                    'posts.*.id' => 'a post of this site that has been placed on the map',
                    'posts.*.name' => 'e.g. "Main Gate"',
                    'posts.*.latitude' => 'degrees north: its surveyed position',
                    'posts.*.longitude' => 'degrees east: its surveyed position',
                    'posts.*.radius_m' => 'metres: the geofence a guard clocks in within',
                    'tour.total' => 'checkpoints',
                    'tour.scanned_today' => 'distinct checkpoints this guard scanned today',
                ],
                'errors' => ['403 wrong_site' => 'A site other than the guard\'s own.'],
                'offline' => 'Cache. The tag codes are not in this list — the tag itself carries its code.',
            ],
            'patrol.scan' => [
                'method' => 'POST', 'uri' => 'checkpoints/{checkpoint}/scan', 'action' => [PatrolController::class, 'scan'],
                'apps' => $guard, 'capability' => 'patrol', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201, 'where' => ['checkpoint' => '[0-9]+'],
                'summary' => 'Record a checkpoint scan (M21 §1.1). The code read from the QR or NFC tag must be the checkpoint\'s own, the guard must be on a shift at the moment the tag was reached, and a phone that says where it is must be within the checkpoint\'s radius of its placed position. A checkpoint scanned again inside the tour cadence is recorded as early.',
                'request' => [
                    'code' => 'string · required · what the tag encodes',
                    'captured_offline' => 'boolean · optional',
                    'latitude' => 'number · optional · where the phone was when the tag was read; with `longitude`',
                    'longitude' => 'number · optional · with `latitude`',
                    'accuracy_m' => 'integer · optional · the phone\'s own estimate of how sure that position is, in metres',
                ],
                'response' => [
                    'scan_id' => 'the scan',
                    'checkpoint_id' => 'the checkpoint',
                    'label' => 'its label',
                    'shift_id' => 'the shift the guard was standing when the tag was reached',
                    'position' => '`inside`, `inside_margin` (within the margin of the phone\'s reading), `no_position` (the phone sent none), `poor_fix` (too unsure to check) or `unplaced` (the checkpoint is not on the map yet). The last three are recorded, and said so in `note`',
                    'distance_m' => 'metres from the checkpoint\'s placed position, or null when either is unknown',
                    'early' => 'true when this guard scanned this checkpoint within the tour cadence before; recorded, and does not advance the tour',
                    'next_due_at' => 'when the next round at this checkpoint is due — UTC instant; show in the site\'s zone (`timezone`)',
                    'note' => 'one sentence to show beside "confirmed", or null: an early scan, or a position that could not be checked',
                    'tour.total' => 'checkpoints',
                    'tour.scanned_today' => 'distinct checkpoints scanned today',
                ],
                'errors' => [
                    '404 not_found' => 'No such checkpoint at the guard\'s site.',
                    '422 code_mismatch' => 'The tag read belongs to a different checkpoint, or to none.',
                    '422 checkpoint_retired' => 'The checkpoint was taken off its route in Sites & geofences; its tag is not counted. Show the sentence.',
                    '422 not_on_shift' => 'No shift of this guard\'s was running when the tag was reached. Show the sentence: clock in first.',
                    '422 outside_checkpoint' => 'The phone was further from the checkpoint than its radius plus the margin of its own reading. The sentence gives the distance and the direction.',
                ],
                'offline' => 'Queue with the device time of the scan and `captured_offline: true`.',
            ],
            'alertness.respond' => [
                'method' => 'POST', 'uri' => 'alertness/{check}/respond', 'action' => [PresenceController::class, 'respond'],
                'apps' => $guard, 'capability' => 'alertness', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['check' => '[0-9]+'],
                'summary' => 'Answer a random alertness check within two minutes. Board guard-app-04. The answer is the tap itself: no score, image or template is accepted or stored.',
                'request' => [],
                'response' => $checkKeys,
                'errors' => [
                    '404 not_found' => 'No such check for this guard.',
                    '409 check_expired' => 'The two minutes ran out; the check is recorded missed.',
                ],
                'offline' => 'Online only: a check answered after signal returns is late by definition. The sync records it as missed.',
            ],
            'presence.activity' => [
                'method' => 'POST', 'uri' => 'presence/activity', 'action' => [PresenceController::class, 'activity'],
                'apps' => $guard, 'capability' => 'presence', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Report on-post activity. Coordinates, when sent, are compared against the post\'s geofence and discarded — only `within_geofence` is kept. Answers with any pending alertness check.',
                'request' => [
                    'state' => 'string · required · `on_post`, `patrolling`, `on_break` or `away`',
                    'latitude' => 'number · optional',
                    'longitude' => 'number · optional',
                    'accuracy_m' => 'integer · optional',
                    'battery_pct' => 'integer · optional · 0–100',
                ],
                'response' => [
                    'ping_id' => 'the ping',
                    'shift_id' => 'the shift on duty, or null',
                    'state' => 'as sent',
                    'within_geofence' => 'true, false, or null when it could not be decided',
                    'pending_alertness_check' => 'null, or the check to answer now',
                    ...array_combine(array_map(static fn (string $k): string => 'pending_alertness_check.'.$k, array_keys($checkKeys)), $checkKeys),
                ],
                'errors' => [],
                'offline' => 'Drop, do not queue: a stale presence report is not presence.',
            ],
            'presence.summary' => [
                'method' => 'GET', 'uri' => 'guards/me/activity-summary', 'action' => [PresenceController::class, 'summary'],
                'apps' => $guard, 'capability' => 'presence', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The guard\'s own day: minutes on post, breaks, checkpoints, incidents, alertness. Board guard-app-04.',
                'request' => [],
                'response' => [
                    'date' => 'YYYY-MM-DD',
                    'shift_id' => 'today\'s shift, or null',
                    'on_post_minutes' => 'on duty less breaks',
                    'break_minutes' => 'breaks',
                    'checkpoints_scanned' => 'distinct today',
                    'checkpoints_total' => 'active at the site',
                    'incidents_filed' => 'today',
                    'alertness_passed' => 'today',
                    'alertness_missed' => 'today',
                    'last_activity_at' => 'last presence report, or null — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => [],
                'offline' => 'Serve the cached summary with its age.',
            ],
            ...self::guardTracking($guard),
        ];
    }

    /**
     * ON-DUTY LOCATION SHARING (M11 §3; D-174): the Guard App only, and only between clock-in and clock-out.
     *
     * @param  list<string>  $guard
     * @return array<string, array<string, mixed>>
     */
    private static function guardTracking(array $guard): array
    {
        $status = [
            'enabled' => 'true when on-duty location sharing is switched on for this service. When false the app asks for nothing and starts nothing',
            'policy_version' => 'the version of the location policy the app must show before asking — its words are the app\'s own (features/tracking); a different version means ask again',
            'consent' => 'this officer\'s answer to THIS version, or null when they have not answered it',
            'consent.decision' => '`granted` or `refused`',
            'consent.policy_version' => 'the version answered — always the current one',
            'consent.decided_at' => 'when — UTC instant; show in the site\'s zone (`timezone`)',
            'retention_days' => 'integer: how many days a position is kept before it is deleted',
            'sampling.interval_s' => 'seconds between fixes while moving',
            'sampling.distance_m' => 'metres of movement that take a fix sooner',
            'sampling.stationary_interval_s' => 'seconds between fixes while standing still',
            'sampling.low_battery_pct' => '0–100: below this battery level, sample at the low-battery interval',
            'sampling.low_battery_interval_s' => 'seconds between fixes below that battery level',
        ];

        return [
            'tracking.status' => [
                'method' => 'GET', 'uri' => 'guards/me/tracking', 'action' => [TrackingController::class, 'status'],
                'apps' => $guard, 'capability' => 'presence', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Whether on-duty location sharing is on, which policy the officer must be shown, their answer to it, and how the phone should sample. Asked at sign-in and before every clock-in.',
                'request' => [],
                'response' => $status,
                'errors' => [],
                'offline' => 'Cache. With no answer on the phone for the current version, start nothing: a position the server has no consent for is refused.',
            ],
            'tracking.consent' => [
                'method' => 'POST', 'uri' => 'guards/me/tracking/consent', 'action' => [TrackingController::class, 'consent'],
                'apps' => $guard, 'capability' => 'presence', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'The officer\'s answer to the location policy the phone showed. Append-only: a changed mind is a new answer. A refusal is recorded like a consent — the app goes on working and the control room shows "position not shared".',
                'request' => [
                    'decision' => 'string · required · `granted` or `refused`',
                    'policy_version' => 'string · required · the `policy_version` the phone showed',
                ],
                'response' => $status,
                'errors' => [
                    '409 policy_changed' => 'The policy changed after the phone showed it. Read `tracking.status` again, show the new words, and ask again.',
                ],
                'offline' => 'Online only: nothing is shared until the server has the answer, so the consent screen says so and asks again with signal.',
            ],
            'tracking.positions' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/positions', 'action' => [TrackingController::class, 'positions'],
                'apps' => $guard, 'capability' => 'presence', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['shift' => '[0-9]+'],
                'summary' => 'What the phone sampled on this shift, oldest first. Kept only while the shift is OPEN when the batch arrives, clocked in and not yet clocked out (D-232): a batch for a shift that is not open is refused whole, every fix with `no_open_shift`, and nothing in it is stored, a fix taken during the shift and sent after the clock-out included. Within an open shift a fix is kept from the clock-in on, on the SERVER\'s clock (each `captured_at` is corrected by the difference between X-Device-Time — the phone\'s clock when the batch is SENT — and the server\'s), only with the officer\'s consent to the current policy, and for `retention_days`. A fix from before the clock-in is refused, not stored; one resent with the same `id` is stored once.',
                'request' => [
                    'positions' => 'array · required · 1–200 fixes, oldest first',
                    'positions.*.id' => 'string · required · the phone\'s own id for the fix — a resent fix is stored once',
                    'positions.*.latitude' => 'number · required · degrees north',
                    'positions.*.longitude' => 'number · required · degrees east',
                    'positions.*.accuracy_m' => 'integer · optional · metres',
                    'positions.*.source' => 'string · required · `interval`, `movement`, `clock_in`, `checkpoint`, `gate`, `duress` or `clock_out`',
                    'positions.*.battery_pct' => 'integer · optional · 0–100',
                    'positions.*.captured_at' => 'string · required · ISO 8601 with its offset: when the phone took the fix, on the phone\'s clock',
                ],
                'response' => [
                    'shift_id' => 'the shift',
                    'accepted' => 'the ids kept, as a list (a fix already kept is listed again); empty when none was',
                    'accepted.*' => 'a fix id the server kept — drop it from the phone',
                    'refused' => 'the fixes not kept, each with the reason; drop them, do not resend; empty when none was',
                    'refused.*.id' => 'the fix',
                    'refused.*.code' => '`no_open_shift` (the shift is not open: every fix of the batch, nothing stored), `outside_shift` (taken before the clock-in), `in_future` or `off_estate` (more than 5 km outside the estate\'s boundary)',
                    'refused.*.message' => 'the sentence',
                ],
                'errors' => [
                    '403 tracking_off' => 'Location sharing is switched off for this service. Stop sampling.',
                    '403 consent_required' => 'This officer has not agreed to the current policy. Stop sampling and show the policy.',
                    '403 not_your_shift' => 'The shift is rostered to another guard at your own site.',
                    '404 not_found' => 'No such shift — an id nobody has, and an id belonging to another estate, answer identically.',
                ],
                'offline' => 'Keep the fixes on the phone with their capture time and send them when there is signal, in batches — also from the background while the app is closed. Send what is queued BEFORE the clock-out: once the shift is closed nothing more is kept, its last fix included. A fix is never shown as current: the map reads its age from `captured_at`.',
            ],
            'tracking.presence_positions' => [
                'method' => 'POST', 'uri' => 'presence/positions', 'action' => [TrackingController::class, 'presence'],
                'apps' => $guard, 'capability' => 'presence', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Guard live tracking (12 Oct 2026; docs/handoff/GUARD_TRACKING.md 5.1): a batch of readings, oldest first, answered one by one. Stored only while the guard is clocked in and not out when the batch ARRIVES: with no open shift every reading is rejected `no_open_shift` and nothing is stored, so send the queue before the clock-out. A key already stored is `duplicate`, stored once however often it is resent. A phone clock more than two minutes off the server\'s (X-Device-Time), or a reading timed more than two minutes ahead of it, is stored with `flag` for a supervisor, never refused and never corrected.',
                'request' => [
                    'positions' => 'array · required · 1–200 readings, oldest first',
                    'positions.*.idempotency_key' => 'string · required · made on the phone, once per reading, kept with it in the queue',
                    'positions.*.captured_at' => 'string · required · ISO 8601 with its offset: when the phone took the reading, on the phone\'s clock',
                    'positions.*.lat' => 'number · required · degrees north',
                    'positions.*.lng' => 'number · required · degrees east',
                    'positions.*.accuracy_metres' => 'integer · optional · metres',
                    'positions.*.battery_percent' => 'integer · optional · 0–100',
                    'positions.*.is_moving' => 'boolean · optional · whether the phone judged the guard moving',
                    'positions.*.source' => 'string · required · `foreground`, `background`, `significant_change` or `geofence`',
                    'backlog' => 'object · optional · what the phone holds unsent, for the dispatcher (GUARD_TRACKING.md section 9)',
                    'backlog.waiting' => 'integer · optional · readings held and not yet sent',
                    'backlog.oldest_captured_at' => 'string · optional · ISO 8601: when the oldest of them was taken',
                    'thinned' => 'object · optional · readings thinned out of a full queue',
                    'thinned.count' => 'integer · optional · how many',
                    'thinned.from' => 'string · optional · ISO 8601: the earliest thinned reading\'s time',
                    'thinned.to' => 'string · optional · ISO 8601: the latest thinned reading\'s time',
                ],
                'response' => [
                    'shift_id' => 'the open shift the readings were kept on; null when there is none',
                    'results' => 'one per reading, in the order sent',
                    'results.*.idempotency_key' => 'the reading',
                    'results.*.result' => '`accepted` (stored: drop it from the queue), `duplicate` (already stored: drop it) or `rejected` (not stored: drop it, never resend)',
                    'results.*.reason' => 'rejected only: `no_open_shift`, `before_shift` or `impossible_place` (more than 5 km outside the estate\'s boundary)',
                    'results.*.message' => 'rejected only: the sentence for the guard',
                    'results.*.flag' => 'accepted only, when it applies: `clock_ahead` or `clock_behind`, stored and flagged for a supervisor',
                ],
                'errors' => [
                    '403 tracking_off' => 'Location sharing is switched off for this service. Stop sampling.',
                    '403 consent_required' => 'This officer has not agreed to the current wording. Stop sampling and show it.',
                ],
                'offline' => 'Queue each reading with its key, in order; send in batches, oldest first, the moment there is signal, also from the background with the app closed. Never drop one to save space without telling the guard and the dispatcher.',
            ],
            'tracking.my_positions' => [
                'method' => 'GET', 'uri' => 'presence/positions', 'action' => [TrackingController::class, 'mine'],
                'apps' => $guard, 'capability' => 'presence', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'What is recorded about this guard (GUARD_TRACKING.md 5.2): today\'s shift (the open one, or the last one ended today) and every reading stored for it. Nobody is tracked without being able to see what is recorded about them.',
                'request' => [],
                'response' => [
                    'shift' => 'today\'s shift, or null when there is none',
                    'shift.id' => 'the shift',
                    'shift.clocked_in_at' => 'ISO 8601',
                    'shift.clocked_out_at' => 'ISO 8601, or null while it is open',
                    'retention_days' => 'how many days positions are kept before they are deleted',
                    'positions' => 'every reading stored for the shift, oldest first',
                    'positions.*.captured_at' => 'when it was taken, as the phone sent it',
                    'positions.*.lat' => 'degrees north',
                    'positions.*.lng' => 'degrees east',
                    'positions.*.accuracy_metres' => 'metres, or null',
                    'positions.*.source' => 'where the reading came from',
                    'positions.*.flag' => '`clock_ahead`, `clock_behind`, or null',
                ],
                'errors' => [],
                'offline' => 'Show the last answer with its age.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardGate(): array
    {
        $guard = [AppMatrix::GUARD];
        $event = [
            'id' => 'the gate event',
            'verdict' => '`admit`, `exit` or `override`',
            'basis' => '`QR pass`, `QR pass · verified offline`, `Pre-approved`, `guard decision`, or `Override — {reason}`',
            'pass_based' => 'true when the basis was a platform pass or approval',
            'occurred_at' => 'the server\'s time of the event — UTC instant; show in the site\'s zone (`timezone`)',
            ...self::CLOCKS,
        ];
        $approval = [
            'approval_id' => 'the walk-up request',
            'status' => '`pending`, `approved`, `denied` or `expired`',
            'unit' => 'the unit',
            'household' => 'its household\'s name',
            'visitor_name' => 'as recorded',
            'requested_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'respond_by' => 'three minutes after the request — UTC instant; show in the site\'s zone (`timezone`)',
            'responded_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'responded_by_name' => 'the resident who answered, or null',
            'access_restricted' => 'boolean — the household\'s visitors are held at the gate (the estate\'s arrears restriction, read as for a guest pass). An approval does not lift it: admitting is `gate.override`, with a reason (M21 §1.7). The ONLY thing a guard learns about the household\'s standing',
            'guidance' => 'the sentence to show the guard',
        ];

        return [
            'gate.verify' => [
                'method' => 'POST', 'uri' => 'gate/verify', 'action' => [GateController::class, 'verify'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-gate-events', 'status' => 200,
                'summary' => 'Verify a signed pass (or its short code) online: signature, site, window, whether the holder is still a resident, cancellation, use, and the household\'s access restriction. Does NOT admit — record the entry after. `pass.category` `resident` is the resident\'s OWN e-pass, the credential a guard admits a RESIDENT on; when the estate office has ended that person\'s access the verdict is `revoked_resident` and the guard sends them to the Visitor Entrance (Q-M44). Board guard-app-07.',
                'request' => ['pass' => 'string · required · the QR token (`payload.signature`), or the short code, e.g. `PPV2-4471`'],
                'response' => [
                    'verdict' => '`valid`, `restricted`, `revoked_resident` (the estate office ended this person\'s resident access — send them to the Visitor Entrance), `cancelled`, `already_used`, `expired`, `not_yet_valid`, `wrong_site`, `unknown_key`, `bad_signature`, `malformed` or `unknown_pass`',
                    'tone' => '`green`, `amber` (restricted) or `red`',
                    'headline' => 'what the guard is told',
                    'detail' => 'one sentence',
                    'admit_allowed' => 'true only for `valid`',
                    'pass' => 'null unless the pass is this estate\'s and on record',
                    'pass.pass_id' => 'UUID',
                    'pass.category' => '`single`, `recurring`, `contractor`, `delivery` or `resident`',
                    'pass.visitor_name' => 'who it is for',
                    'pass.vehicle_plate' => 'or null',
                    'pass.purpose' => 'or null',
                    'pass.valid_from' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'pass.valid_to' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'pass.single_use' => 'boolean',
                    'pass.status' => '`active`, `used` or `cancelled`',
                    'unit' => 'the unit reference, or null',
                    'household' => 'the household\'s name, or null',
                    'access_restricted' => 'boolean, or null when the pass itself failed — the ONLY thing a guard learns about a household\'s standing',
                    'server_time' => 'the server\'s time — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => [],
                'offline' => 'Verify on the handset with the cached public keys (`/sites/{site}/pass-keys`): the payload\'s eight fields and its Ed25519 signature, with no database. Cancellation and use are unknown offline; `/sync/pull` delivers the revoked list and `/gate/entry` reconciles.',
            ],
            'gate.entry' => [
                'method' => 'POST', 'uri' => 'gate/entry', 'action' => [GateController::class, 'entry'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'Record an admission — on a pass, on an approved walk-up request, or on the guard\'s own decision. A single-use pass is consumed here.',
                'request' => [
                    'category' => 'string · required · e.g. `Visitor`, `Contractor`, `Delivery`, `Resident`',
                    'pass_id' => 'string · optional · the pass admitted on',
                    'approval_id' => 'integer · optional · an approved walk-up request',
                    'subject' => 'string · required without a pass or approval · who came in',
                    'verified_offline' => 'boolean · optional · the pass was verified on the handset with no signal',
                    'post_id' => 'integer · optional · defaults to the guard\'s post',
                ],
                'response' => [
                    ...$event,
                    'reconciliation' => 'null without a pass; else `consumed`, `valid`, or — offline only — `pass_cancelled`, `pass_already_used`, `pass_not_yet_valid` (admitted before its window opened; the pass is NOT used up), `pass_expired`, `household_restricted`, `pass_unknown`: tell the guard and dispatch sees it. The window is judged on the server\'s clock online, and offline on the admission\'s device time but never later than its arrival, with two minutes\' grace each side as at `/gate/verify`',
                    'access_restricted' => 'boolean, or null without a pass',
                ],
                'errors' => [
                    '404 not_found' => 'An online entry on a pass or request that does not exist.',
                    '409 pass_not_admissible' => 'Online, on a pass that is cancelled, used, not yet valid, expired or restricted. Use `/gate/override`.',
                    '409 approval_not_granted' => 'The walk-up request is not approved.',
                    '409 household_restricted' => 'Online, on an approved walk-up for a household whose visitors are held at the gate. Use `/gate/override`, with a reason. (Offline it is recorded and answered with `reconciliation: household_restricted`.)',
                    '422 subject_required' => 'No pass, no approval and no subject.',
                    '403 wrong_site' => 'A post at another estate.',
                ],
                'offline' => 'Queue with `verified_offline: true` and the device time of the admission. The server records it and answers `reconciliation`.',
            ],
            'gate.exit' => [
                'method' => 'POST', 'uri' => 'gate/exit', 'action' => [GateController::class, 'exit'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'Record a departure.',
                'request' => [
                    'category' => 'string · required',
                    'subject' => 'string · required',
                    'pass_id' => 'string · optional',
                    'post_id' => 'integer · optional',
                ],
                'response' => $event,
                'errors' => ['403 wrong_site' => 'A post at another estate.'],
                'offline' => 'Queue with the device time.',
            ],
            'gate.override' => [
                'method' => 'POST', 'uri' => 'gate/override', 'action' => [GateController::class, 'override'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'Admit against the system\'s advice, with a reason. Its own verdict on the gate log.',
                'request' => [
                    'category' => 'string · required',
                    'subject' => 'string · required',
                    'reason' => 'string · required · 5–160 characters',
                    'pass_id' => 'string · optional',
                    'post_id' => 'integer · optional',
                ],
                'response' => $event,
                'errors' => ['403 wrong_site' => 'A post at another estate.'],
                'offline' => 'Queue with the device time.',
            ],
            'gate.search' => [
                'method' => 'GET', 'uri' => 'gate/search', 'action' => [GateController::class, 'search'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Find a unit or household. `access_restricted` is a boolean and the ONLY thing returned about a household\'s standing — no figure, no bucket, no wording implying money.',
                'request' => [
                    'unit' => 'string · required without `name` (query) · part of a unit reference',
                    'name' => 'string · required without `unit` (query) · part of a household or resident name, 2+ characters',
                    // THE PHASE FILTER IS THE SERVER'S. Filtering the page the server returned would
                    // search one phase of twenty rows rather than one phase of the estate — on a 2,000-lot
                    // estate that is silently, confidently wrong.
                    'phase' => 'string · optional (query) · one phase name from `guards.me` → `site.phases`',
                ],
                'response' => [
                    'total' => 'how many units match in the whole estate, which can be more than `items` carries',
                    'limit' => 'how many `items` carries at most',
                    'items.*.unit' => 'the unit reference',
                    'items.*.household' => 'the household\'s name',
                    'items.*.primary_resident' => 'the primary resident\'s name, or null',
                    'items.*.access_restricted' => 'boolean',
                    'items.*.notice' => 'a standing note the guard must read, or null. Today the only one: somebody whose Resident App access the estate office ended is no longer a resident of that unit and goes to the Visitor Entrance. Never money.',
                    'items.*.expected_visitors.*.visitor_name' => 'an active pass valid today',
                    'items.*.expected_visitors.*.category' => 'its category',
                    'items.*.expected_visitors.*.valid_to' => 'UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => [],
                'offline' => 'Online only.',
            ],
            'gate.activity' => [
                'method' => 'GET', 'uri' => 'gate/activity', 'action' => [GateController::class, 'activity'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Today\'s gate log at the guard\'s estate, newest first.',
                'request' => ['limit' => 'integer · optional (query) · 1–100, default 50'],
                'response' => [
                    'date' => 'YYYY-MM-DD',
                    'counts.admitted' => 'today',
                    'counts.exited' => 'today',
                    'counts.overridden' => 'today',
                    'counts.denied' => 'today',
                    'items.*.id' => 'the event',
                    'items.*.verdict' => 'verdict',
                    'items.*.category' => 'category',
                    'items.*.subject' => 'who',
                    'items.*.basis' => 'on what',
                    'items.*.pass_based' => 'boolean',
                    'items.*.guard_name' => 'recorded by',
                    'items.*.post_name' => 'at',
                    'items.*.occurred_at' => 'server time — UTC instant; show in the site\'s zone (`timezone`)',
                    'items.*.device_time' => 'handset time, or null — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => [],
                'offline' => 'Show the cached log plus the queued events not yet synced, marked as such.',
            ],
            'gate.approvals.store' => [
                'method' => 'POST', 'uri' => 'gate/approvals', 'action' => [GateController::class, 'requestApproval'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'A walk-up visitor: record them and ask the household, who answers in the Resident App. Boards guard-app-08 and -02. No photo is stored.',
                'request' => [
                    'unit' => 'string · required · the unit reference',
                    'visitor_name' => 'string · required',
                    'id_type' => 'string · required (M18C)',
                    'id_number' => 'string · required (M18C)',
                    'purpose' => 'string · required (M18C) · 2–160 characters',
                    'vehicle_plate' => 'string · required unless `no_vehicle` is true, and refused when it is',
                    'no_vehicle' => 'boolean · "Arriving on foot or by taxi — no vehicle" (M18C §3.1): a plate, or this — never neither',
                ],
                'response' => [
                    ...$approval,
                    'household_can_answer' => 'boolean — false when nobody in this household can answer in the Resident App (no active account with Member access); call them or apply the estate\'s policy. Absent on a server older than this release',
                ],
                'errors' => ['404 not_found' => 'No such unit.'],
                'offline' => 'Online only — the household cannot be asked without signal. Apply the estate\'s policy.',
            ],
            'gate.approvals.show' => [
                'method' => 'GET', 'uri' => 'gate/approvals/{approval}', 'action' => [GateController::class, 'approval'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['approval' => '[0-9]+'],
                'summary' => 'Poll a walk-up request. Past `respond_by` with no answer it reads `expired`.',
                'request' => [],
                'response' => $approval,
                'errors' => ['404 not_found' => 'No such request.'],
                'offline' => 'Online only.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardReports(): array
    {
        $guard = [AppMatrix::GUARD];
        $incident = [
            'id' => 'the incident',
            'kind' => 'as filed',
            'severity' => '`low`, `med` or `high`',
            'status' => '`open` or `resolved`',
            'detail' => 'the account',
            'location' => 'or null',
            'shift_id' => 'the shift on duty when filed, or null',
            'occurred_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'resolution' => 'what was done, once resolved',
            'closed_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'media_count' => 'attachments',
        ];
        $alert = [
            'id' => 'the alert',
            'kind' => '`duress` from a guard. From a resident: `panic`, or `medical` / `fire` when they chose that `type` — `security`, `other` and no type at all are `panic`',
            'mode' => '`silent` or `audible`',
            'status' => '`open`, `acknowledged`, `responding`, `resolved` or `false_alarm`',
            'cancellable_until' => 'ten seconds after the server received it — UTC instant; show in the site\'s zone (`timezone`)',
            'cancelled_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'acknowledged_at' => 'when dispatch took it, or null — UTC instant; show in the site\'s zone (`timezone`)',

            /*
             * THE REST OF THE RESPONSE (M10 §1.3) — "alerting → acknowledged → dispatched → en
             * route → on scene → resolved". Added beside `acknowledged_at`, never instead of it.
             * Only `dispatched_at` makes "help is on the way" true; an acknowledgement is the
             * control room having the alert and nothing more.
             */
            'dispatched_at' => 'when the control room SENT an officer, or null. The first moment "help is on the way" is true — never say it from `acknowledged_at` — UTC instant; show in the site\'s zone (`timezone`)',
            'en_route_at' => 'when an officer was recorded as on the way (from their handset, or by dispatch after a radio call), or null. May stay null for good: an officer who ran straight there has `on_scene_at` only, and nothing is back-filled — draw the step as not recorded, never as done — UTC instant; show in the site\'s zone (`timezone`)',
            'on_scene_at' => 'when an officer was recorded as at the scene, or null — UTC instant; show in the site\'s zone (`timezone`)',
            'resolved_at' => 'when the alert was closed — resolved, a false alarm, or cancelled from the phone; `status` says which — or null — UTC instant; show in the site\'s zone (`timezone`)',

            'server_time' => 'the server\'s time of the alert — UTC instant; show in the site\'s zone (`timezone`)',
            'device_time' => 'the handset\'s time of the press — UTC instant; show in the site\'s zone (`timezone`)',
            'clock_skewed' => 'true when the device time is more than two minutes AHEAD of the server\'s. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged',

            /*
             * RESTORED, NOT ADDED (M6 §2.1 part 5). These four were written by the panic-fallback
             * package, regenerated into `MOBILE_HANDOFF.md` and `packages/core/api/generated/*`,
             * and then LOST when this file was overwritten with a stale copy at 01:53 on
             * 2026-09-21 — which left `DuressController::shape()` returning four keys the
             * allowlist did not list, and `GuardApiContractTest` red for every agent. The
             * wording is recovered verbatim from the handoff this array generated. If the
             * panic package wants to reword them, it owns them.
             */
            'console_alerted' => 'true, always — the control room has this alert from the moment it is recorded, whoever is on shift',
            'alerted_handsets' => 'how many security HANDSETS the alert was sent to, or null while the fan-out is still running. 0 means none: say so plainly and put the emergency number first. NULL IS NOT 0',
            'alerted_via' => 'which tier answered: `on_duty` (guards clocked on), `rostered` (nobody clocked on, so the officers rostered here today), `escalation` (no handset at all — the site\'s escalation contact was told off-app) or `none`, or null while it is still running',
            'escalated_contacts' => 'how many people were told OFF-APP — the site\'s escalation contacts, e-mailed on EVERY panic whether or not a handset was reached (M10 §1) — or null while it is still running. An email or a console bell, never a phone: it is never "help is coming" and never adds to `alerted_handsets`',
            'rang_handsets' => 'how many officer PHONES the call-style ring has been sent to so far, in any round — including the ring to every officer of the estate that goes at once when `alerted_handsets` is 0 (M18) — or null while the ring has not begun. Sent, not answered. When `alerted_handsets` is 0 and this is above 0, officers\' phones ARE being rung: do not say "no officer\'s phone was reached"',
            'rang_at' => 'when the call-style ring was first sent to a phone (ISO 8601), or null when no round has been sent. The time the resident\'s "Officers alerted" line is ticked with when the ring is what alerted them (M18A)',
            'on_duty_officers' => 'how many officers were CLOCKED ON at the site when the alert was raised (the raiser not counted), or null on an older alert or while the fan-out is still running. With `alerted_handsets` 0 it tells "nobody is on duty" (0) from "officers are on duty but their phones could not be reached" (above 0). Null: claim neither (M18A §1.2)',

            /*
             * THE TEXTS (Q-M09, mobile-api 2026-09-21). The client named four numbers a panic
             * must ALSO be sent to; they are stored per site and texted alongside everything
             * above, never instead of any of it.
             */
            'sms_recipients' => 'how many of the site\'s emergency numbers were ON FILE for this alert, or null while it is still running. NOT how many were texted — never write "N people texted" from it; show `sms.note`',
            'sms_delivered' => 'how many of those texts a PROVIDER ACCEPTED, or null while it is still running. 0 on every alert today — no SMS provider is bought yet, so the message is written down and delivered to nobody. NEVER add this to `alerted_handsets`: a text is not an officer being sent',

            /*
             * WHAT THE TEXTS ACTUALLY DID (M10 §1) — additive, beside the two counts above,
             * which stay exactly as they were. Built from the per-number ledger and from
             * whether a provider is connected, never from a constant. See `PanicSmsOutcome`.
             */
            'sms.provider_connected' => 'true only when an SMS provider can actually send a text right now. false on this platform today: the log driver writes a message down and delivers it to nobody',
            'sms.attempted' => 'how many numbers were handed to a REAL provider for this alert. A message written to the log, or a number nobody can dial, is not an attempt',
            'sms.delivered' => 'how many of those a provider ACCEPTED — the furthest the platform can honestly claim. Never a count of phones that rang',
            'sms.finished' => 'true once the texting has run to the end. Until then `attempted` and `delivered` are "so far"',
            'sms.note' => 'the one sentence to show about texts, e.g. "No text was sent: no SMS provider is connected." Show it as given; never build a claim about texts from the numbers',

            // M10 §1.3 — a real alert raised by `panic:walk` to prove who a panic reaches.
            'is_drill' => 'true when this is a DRILL raised to test the fan-out, never by a handset. Show DRILL before anything else on the screen; false on every alert a person raises',

            // M53 §3A.1 — raised while the office had drill mode on for this account.
            'drill_mode' => 'true when the office had drill mode on for this account when it was pressed: a DRILL (`is_drill` is true too) that rang no officer, emailed and texted nobody and sounded no siren. The counts above stay null for good, so say that instead of waiting on them, and keep the emergency number one press away in case it is real. Absent on a server older than this release',

            // M50 §4.2 #2 — "Message dispatch…": the live channel with the control room, oldest first.
            'messages.*.id' => 'the message',
            'messages.*.from' => '`you` (the resident) or `dispatch` (the control room)',
            'messages.*.name' => 'the dispatcher\'s first name on a reply, or null',
            'messages.*.body' => 'what was written',
            'messages.*.at' => 'when the server received it — UTC instant; show in the site\'s zone (`timezone`)',
            'messages.*.seen_at' => 'when the OTHER side was shown it — the console drew it (your message), or this phone read it back (a reply) — or null: "Sent" until then, "Delivered" after — UTC instant; show in the site\'s zone (`timezone`)',
            'messages.*.client_key' => 'on your own messages, the `client_key` the phone sent it with — how the phone matches a message it queued to the row; null on a reply',

            /*
             * THE HOUSEHOLD'S OWN EMERGENCY CONTACTS (M10 §1.2 item 7) — additive, inside
             * `sms`, beside the site's five fields above, which keep counting the estate's
             * numbers only. `sms.note` names both audiences once the household had anybody
             * to text, and is unchanged word for word when it had nobody.
             */
            'sms.household' => 'null on an officer\'s duress — only a resident\'s panic texts a household. Otherwise the texts to the resident\'s own nominated emergency contacts (the first 5 on file), counted by the same rules as the fields above and never included in them',
            'sms.household.attempted' => 'how many of the household\'s contacts were handed to a REAL provider for this alert. Under the log driver this is 0: the message is written down and delivered to nobody',
            'sms.household.delivered' => 'how many of those a provider ACCEPTED — never "received": the carrier and the phone are downstream. Say it only as the provider accepting it',
            'sms.household.finished' => 'true once the household\'s texting has run to the end, including at once when the household has no contacts on file. Until then `attempted` and `delivered` are "so far"',

            // M12 §2.5 — the same contacts by EMAIL, which goes today (no SMS provider is connected).
            'email' => 'the emails a panic sends',
            'email.household' => 'null on an officer\'s duress. Otherwise the emails to the household\'s own emergency contacts (the first 5) AND its members (M18C: every member with an email of their own, the raiser not included, up to 10), each address once, counted from the ledger',
            'email.household.recipients' => 'how many people this alert is emailing — contacts and members together; null until the fan-out reaches the leg. 0: nobody has an email address on file (or a simulated/demonstration alert)',
            'email.household.members_sent' => 'of `sent`, how many were to the household\'s own members (M18C §4.3)',
            'email.household.contacts_sent' => 'of `sent`, how many were to its emergency contacts',
            'email.household.sent' => 'how many the mail server ACCEPTED — never "received". The only number that may put "emailed" on the screen',
            'email.household.failed' => 'how many were refused after every retry, or whose address was gone by the time it was sent',
            'email.household.finished' => 'true once every recipient has an outcome. Until then `sent` is "so far"',
        ];

        return [
            'incidents.store' => [
                'method' => 'POST', 'uri' => 'incidents', 'action' => [IncidentsController::class, 'store'],
                'apps' => $guard, 'capability' => 'incidents', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'File an incident report. It lands in the same register the console keeps. Board guard-app-03.',
                'request' => [
                    'kind' => 'string · required · e.g. "Attempted unauthorized access"',
                    'severity' => 'string · required · `low`, `med` or `high`',
                    'detail' => 'string · required · at least 20 characters',
                    'location' => 'string · required (M18C) · where, in the officer\'s words — e.g. "back wall by Lot 63"',
                    'latitude' => 'number · optional',
                    'longitude' => 'number · optional',
                    'occurred_at' => 'ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site\'s clock · optional · defaults to now; not in the future',
                ],
                'response' => [...$incident, ...self::CLOCKS],
                'errors' => ['422 occurred_in_future' => '`occurred_at` more than five minutes ahead of the server.'],
                'offline' => 'Queue with the device time; upload media after the report syncs, against the id it returns.',
            ],
            'incidents.me' => [
                'method' => 'GET', 'uri' => 'incidents/me', 'action' => [IncidentsController::class, 'mine'],
                'apps' => $guard, 'capability' => 'incidents', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The guard\'s own reports from the last 90 days.',
                'request' => [],
                'response' => array_combine(array_map(static fn (string $k): string => 'items.*.'.$k, array_keys($incident)), $incident),
                'errors' => [],
                'offline' => 'Serve the cache plus queued reports.',
            ],
            'incidents.media' => [
                'method' => 'POST', 'uri' => 'incidents/{incident}/media', 'action' => [IncidentsController::class, 'media'],
                'apps' => $guard, 'capability' => 'incidents', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201, 'where' => ['incident' => '[0-9]+'],
                'summary' => 'Attach a photo or video (multipart, field `file`). Stored privately and hashed on arrival.',
                'request' => ['file' => 'file · required · JPEG, PNG, HEIC, MP4 or MOV, at most 50 MB'],
                'response' => [
                    'media_id' => 'the attachment',
                    'incident_id' => 'the incident',
                    'filename' => 'as uploaded',
                    'content_type' => 'as detected',
                    'bytes' => 'size',
                    'sha256' => 'hash of the stored bytes',
                ],
                'errors' => ['404 not_found' => 'No incident of this guard\'s with that id.'],
                'offline' => 'Queue the file; upload after the incident has an id. Not batchable.',
            ],
            'duress.store' => [
                'method' => 'POST', 'uri' => 'duress', 'action' => [DuressController::class, 'store'],
                'apps' => [AppMatrix::GUARD, AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-alerts', 'status' => 201,
                'summary' => 'Duress (guard) or panic (resident). Dispatch sees it at once. Cancellable for ten seconds. Guard board guard-app-03; resident panic.',
                'request' => [
                    'mode' => 'string · optional · `silent` (default) or `audible` — the handset\'s behaviour only',
                    'latitude' => 'number · optional',
                    'longitude' => 'number · optional',
                    'captured_offline' => 'boolean · optional',
                    'type' => 'string · optional · `medical`, `fire`, `security` or `other` — NEVER required. The button works with nothing attached, because a person in a panic does not stop to categorise. `medical` and `fire` become the alert\'s `kind`; `security` and `other` are a plain `panic`, which is what the button has always meant. An unlabelled alert reaches exactly the same people. IGNORED ON A GUARD\'S TOKEN: a guard\'s is always `duress`, which carries the silent-mode handling, and a compromised handset must not be able to relabel what it is.',
                ],
                'response' => $alert,
                'errors' => [],
                'offline' => 'Queue with its key and `captured_offline: true`; send the moment any signal returns. Also call the local emergency number.',
            ],
            'duress.cancel' => [
                'method' => 'POST', 'uri' => 'duress/{alert}/cancel', 'action' => [DuressController::class, 'cancel'],
                'apps' => [AppMatrix::GUARD, AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-alerts', 'status' => 200, 'where' => ['alert' => '[0-9]+'],
                'summary' => 'Cancel within ten seconds of the server receiving it, and before dispatch acknowledges.',
                'request' => [
                    'reason' => 'string · optional · why it was a false alarm, max 160 — kept on the alert\'s resolution note for dispatch',
                ],
                'response' => $alert,
                'errors' => [
                    '404 not_found' => 'Not this handset\'s alert.',
                    '409 cancel_window_closed' => 'More than ten seconds. Call dispatch.',
                    '409 already_acknowledged' => 'The control room has taken it — which is not the same as an officer coming. Call dispatch to stand it down.',
                    '409 already_responding' => 'An officer is already on the way or at the scene (they took it from their own handset before the control room did). Call dispatch to stand them down.',
                ],
                'offline' => 'Not possible offline: the alert has not reached anyone to cancel.',
            ],
            'duress.show' => [
                'method' => 'GET', 'uri' => 'duress/{alert}', 'action' => [DuressController::class, 'show'],
                'apps' => [AppMatrix::GUARD, AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['alert' => '[0-9]+'],
                'summary' => 'Read the caller\'s own alert back: has the control room acknowledged it, has an officer been dispatched, are they on the way, are they there, is it resolved (`acknowledged_at`, `dispatched_at`, `en_route_at`, `on_scene_at`, `resolved_at`). Poll while it is open; each step is also pushed as `panic_update` (a guard\'s AUDIBLE duress: `duress_update`; a silent one is never pushed) with `kind` `acknowledged`, `dispatched`, `en_route`, `on_scene` or `closed`. Resident board resident-app-06 (the panic timeline).',
                'request' => [],
                'response' => $alert,
                'errors' => [
                    '404 not_found' => 'Not this handset\'s alert — the same answer as an id nobody has.',
                ],
                'offline' => 'Show the last answer with its age. The alert itself was already sent, or is queued and says so.',
            ],
            'duress.messages.store' => [
                'method' => 'POST', 'uri' => 'duress/{alert}/messages', 'action' => [DuressController::class, 'message'],
                'apps' => [AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-alerts', 'status' => 201, 'where' => ['alert' => '[0-9]+'],
                'summary' => '"Message dispatch…" on the panic screen (board resident-app-06; M50 §4.2 #2): the resident writes to the control room on their OWN open panic. The control room sees it on the alert\'s page at once and replies there; a reply reaches this phone as the live step `alert.message` (read the alert again) and as an urgent `panic_update` push with `kind` `message`, which no switch and no quiet hours hold. Answers with the alert, `messages` included, exactly as `duress.show` reads it.',
                'request' => [
                    'body' => 'string · required · what to say, up to 500 characters kept (longer is cut)',
                    'client_key' => 'string · optional · the phone\'s own id for this message (a UUID). The same key again is the same message, never a second one — send it from the queue as often as the signal needs',
                ],
                'response' => $alert,
                'errors' => [
                    '404 not_found' => 'Not this account\'s alert.',
                    '409 alert_closed' => 'The alert is resolved, closed or cancelled: nobody is reading the thread. Say the sentence given — it says what to do instead.',
                    '422 message_empty' => 'Nothing to send.',
                ],
                'offline' => 'Queue it with its `client_key` and send the moment there is a signal; draw it as "Waiting for signal" until then and never drop it. A refusal is shown on the message with the sentence given.',
            ],
            'duress.audio.store' => [
                'method' => 'POST', 'uri' => 'duress/{alert}/audio', 'action' => [DuressController::class, 'audio'],
                'apps' => [AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-alerts', 'status' => 201, 'where' => ['alert' => '[0-9]+'],
                'summary' => '"Share live audio" on the panic screen (board resident-app-06; M50 §4.2 #1): one piece of what the phone hears, on the resident\'s OWN open panic — multipart, field `file` (AAC in MP4, a few seconds long, at most 2 MB). Record while the switch is on, in the background too, and send each piece as it ends; the control room plays them in order on the alert\'s page as they arrive. The same `seq` again is the same piece, never a second one.',
                'request' => [
                    'file' => 'file · required · the piece: AAC in MP4 (`.m4a`), at most 2 MB',
                    'seq' => 'integer · required · this alert\'s pieces counted from 0 — the same number again is the same piece',
                    'duration_ms' => 'integer · optional · how long the piece is',
                ],
                'response' => [
                    'seq' => 'the piece, as kept',
                    'received' => 'always true',
                ],
                'errors' => [
                    '404 not_found' => 'Not this account\'s alert.',
                    '409 alert_closed' => 'The alert is resolved, closed or cancelled: stop recording, and say the sentence given.',
                    '422 validation_failed' => 'Not an audio piece the app makes, or larger than 2 MB.',
                ],
                'offline' => 'Queue each piece with its `seq` and send them in order once there is a signal — what was heard with no signal still reaches the control room. Stop recording when the alert closes.',
            ],
            'duress.positions.store' => [
                'method' => 'POST', 'uri' => 'duress/{alert}/positions', 'action' => [DuressController::class, 'positions'],
                'apps' => [AppMatrix::GUARD, AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-alerts', 'status' => 200, 'where' => ['alert' => '[0-9]+'],
                'summary' => 'Where the phone that raised this alert is now, while it stays open (12 Oct 2026; docs/handoff/GUARD_TRACKING.md 5.3): every 10 to 15 seconds, the caller\'s OWN alert only. Kept with the alert for ever; the estate\'s Security map and the dispatcher\'s draw the newest and hear it live (`alert.moved`). Never refused for being far from the estate: a person moving away is what the control room must see. Answered reading by reading.',
                'request' => [
                    'positions' => 'array · required · 1–50 readings, oldest first',
                    'positions.*.idempotency_key' => 'string · required · made on the phone, once per reading',
                    'positions.*.captured_at' => 'string · required · ISO 8601 with its offset, on the phone\'s clock',
                    'positions.*.lat' => 'number · required · degrees north',
                    'positions.*.lng' => 'number · required · degrees east',
                    'positions.*.accuracy_metres' => 'integer · optional · metres',
                ],
                'response' => [
                    'alert_id' => 'the alert',
                    'results' => 'one per reading, in the order sent',
                    'results.*.idempotency_key' => 'the reading',
                    'results.*.result' => '`accepted`, `duplicate` (already stored) or `rejected`',
                    'results.*.reason' => 'rejected only: `alert_closed`, the alert is resolved, a false alarm or cancelled: stop sending',
                    'results.*.message' => 'rejected only: the sentence for the person',
                ],
                'errors' => [
                    '404 not_found' => 'Not this phone\'s alert: the same answer as an id nobody has.',
                ],
                'offline' => 'Queue each reading with its key and send them in order the moment there is a signal. Stop when the alert closes.',
            ],

            /*
             * THE PANIC SCREEN'S "TEXT / CALL MY EMERGENCY CONTACTS", AT EVERY ACCESS LEVEL. The sheet took its
             * people from `me.household`, which a Helper may not read; and while no SMS provider is connected the
             * server's own texts to a household's contacts reach nobody, so the phone's sheet is the one way they
             * hear of a panic. Panic is never removed from anybody, so this is a read of its own — under `alerts`,
             * which every token carries — cached while the phone is online, because it is needed most when the
             * alert is held offline and there is no answer to read it from.
             */
            'panic.contacts' => [
                'method' => 'GET', 'uri' => 'panic/contacts', 'action' => [PanicContactsController::class, 'index'],
                'apps' => [AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s emergency contacts, for the panic screen\'s "text / call my emergency contacts" and nothing else. Every access level reads it, because panic is never removed from anybody (a Helper cannot read `me.household` or `contacts.index`). Names, relationship and number only — nothing else about the household — in the order the household added them.',
                'request' => [],
                'response' => [
                    'items.*.name' => 'name',
                    'items.*.relationship' => 'or null',
                    'items.*.phone' => 'number, as entered',
                ],
                'errors' => [],
                'offline' => 'Serve the cache — the panic screen texts and calls these people from the phone itself, and is needed most when there is no signal.',
            ],
            'requests.index' => [
                'method' => 'GET', 'uri' => 'requests', 'action' => [RequestsController::class, 'index'],
                'apps' => $guard, 'capability' => 'requests', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The guard\'s leave, equipment and swap requests, with decisions, and their leave balance in days. Board guard-app-05.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the request',
                    'items.*.kind' => '`leave`, `equipment` or `shift_swap`',
                    'items.*.subject' => 'leave type, or the item',
                    'items.*.quantity' => 'equipment count, or null',
                    'items.*.starts_on' => 'YYYY-MM-DD or null',
                    'items.*.ends_on' => 'YYYY-MM-DD or null',
                    'items.*.days' => 'inclusive, or null',
                    'items.*.reason' => 'or null',
                    'items.*.certificate_attached' => 'boolean — the flag, never the document',
                    'items.*.status' => '`pending`, `approved`, `denied` or `info_requested`',
                    'items.*.decided_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
                    'items.*.decision_note' => 'the supervisor\'s note, or null',
                    'items.*.created_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'leave.entitlement_days' => 'per year',
                    'leave.approved_days_this_year' => 'days',
                    'leave.remaining_days' => 'days',
                ],
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'requests.store' => [
                'method' => 'POST', 'uri' => 'requests', 'action' => [RequestsController::class, 'store'],
                'apps' => $guard, 'capability' => 'requests', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Raise a request. It reaches the dispatch inbox at once.',
                'request' => [
                    'kind' => 'string · required · `leave`, `equipment` or `shift_swap`',
                    'subject' => 'string · required · for leave: `vacation`, `sick`, `bereavement`, `maternity`, `paternity`, `unpaid`, `other`',
                    'quantity' => 'integer · required for equipment',
                    'starts_on' => 'date · required for leave',
                    'ends_on' => 'date · required for leave',
                    'reason' => 'string · required for leave (M18C), vacation included',
                    'certificate_attached' => 'boolean · optional',
                ],
                'response' => [
                    'id' => 'the request', 'kind' => 'kind', 'subject' => 'subject', 'quantity' => 'or null',
                    'starts_on' => 'or null', 'ends_on' => 'or null', 'days' => 'or null', 'reason' => 'or null',
                    'certificate_attached' => 'boolean', 'status' => '`pending`', 'decided_at' => 'null',
                    'decision_note' => 'null', 'created_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'server_time' => 'the server\'s time — UTC instant; show in the site\'s zone (`timezone`)', 'device_time' => 'the handset\'s time, as sent',
                ],
                'errors' => ['422 leave_type_unknown' => 'A leave subject not in the list.'],
                'offline' => 'Queue.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardSelf(): array
    {
        $guard = [AppMatrix::GUARD];
        $message = [
            'id' => 'the message',
            'direction' => '`broadcast`, `outbound` (dispatch to this guard) or `inbound` (this guard to dispatch)',
            'from' => '`dispatch` or `you`',
            'body' => 'text, at most 500',
            'sent_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'read_at' => 'when THIS guard read it on THIS handset (never another guard\'s read), or null — UTC instant; show in the site\'s zone (`timezone`)',
        ];

        return [
            'payslips.me' => [
                'method' => 'GET', 'uri' => 'payslips/me', 'action' => [PayslipsController::class, 'mine'],
                'apps' => $guard, 'capability' => 'payslips', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The guard\'s OWN payslips on approved runs, newest first, as decimal strings. The one route where a guard\'s handset reads money — their own wage. Board guard-app-05.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the payslip',
                    'items.*.run_reference' => 'the payroll run',
                    'items.*.period_label' => 'e.g. "September 2026"',
                    'items.*.period_start' => 'YYYY-MM-DD',
                    'items.*.period_end' => 'YYYY-MM-DD',
                    'items.*.status' => '`approved` or `paid`',
                    'items.*.currency' => '`JMD`',
                    'items.*.gross' => 'decimal string, e.g. "38450.00"',
                    'items.*.deductions.nis' => 'decimal string',
                    'items.*.deductions.nht' => 'decimal string',
                    'items.*.deductions.education_tax' => 'decimal string',
                    'items.*.deductions.paye' => 'decimal string',
                    'items.*.deductions.pension' => 'decimal string — an approved pension, zero unless one is on file',
                    'items.*.net' => 'decimal string',
                    'items.*.paye_note' => 'why PAYE is what it is, or null',

                    /*
                     * THE ARITHMETIC, NOT ONLY THE ANSWER (M8 §5.3). A slip an officer cannot check is a
                     * figure they have to take on trust: the hours they were paid for, the rate each was
                     * paid at, and — the one that matters most — what each deduction was taken ON, which
                     * is a different figure for every one of them.
                     */
                    'items.*.employee.name' => 'the officer, as HR holds it',
                    'items.*.employee.employee_number' => 'e.g. GS-1041',
                    'items.*.employee.trn' => 'the officer\'s TRN, or null — this platform does not hold it (it is HR\'s record). Draw "Not on file", never a blank that reads as zero',
                    'items.*.employee.nis_number' => 'the officer\'s NIS number, or null, for the same reason',
                    'items.*.employer.name' => 'the employer — the security company, never the community',
                    'items.*.employer.address' => 'its postal address',
                    'items.*.employer.email' => 'where a payroll question goes',
                    'items.*.hours' => 'NULL on a slip written before hours were recorded — say "not on record for this period" rather than drawing 0.00',
                    'items.*.hours.regular' => 'decimal string, two places',
                    'items.*.hours.overtime' => 'decimal string, two places',
                    'items.*.hours.regular_rate' => 'the standard hourly rate',
                    'items.*.hours.overtime_rate' => 'the overtime hourly rate — time and a half of the standard',
                    'items.*.hours.overtime_note' => 'the sentence to print beside it',
                    'items.*.earnings.basic' => 'regular hours at the standard rate',
                    'items.*.earnings.overtime' => 'overtime hours at the overtime rate',
                    'items.*.earnings.allowances' => 'allowances for the period',
                    'items.*.earnings.gross' => 'the three added — the same gross as `gross` above',
                    'items.*.earnings.taxable_note' => 'which earnings are taxable',
                    'items.*.lines.*.key' => '`nis`, `nht`, `education_tax`, `paye`, `pension`',
                    'items.*.lines.*.label' => 'what to print',
                    'items.*.lines.*.amount' => 'decimal string',
                    'items.*.lines.*.rate' => 'the rate as a percentage, e.g. "2.5%", or null where there is no rate (a pension)',
                    'items.*.lines.*.base' => 'WHAT IT WAS CALCULATED ON — not the gross for any two of them',
                    'items.*.lines.*.note' => 'the base in words',
                    'items.*.total_deductions' => 'decimal string',
                    'items.*.employer_contributions.nis' => 'paid BY the employer — never a deduction from this wage',
                    'items.*.employer_contributions.nht' => 'decimal string',
                    'items.*.employer_contributions.education_tax' => 'decimal string',
                    'items.*.employer_contributions.heart' => 'decimal string',
                    'items.*.employer_contributions.total' => 'decimal string',
                    'items.*.employer_contributions.note' => 'the sentence that keeps it from reading as money taken off',
                    'items.*.year_to_date.year' => 'the tax year — Jamaica\'s is the calendar year',
                    'items.*.year_to_date.periods' => 'how many approved periods are counted',
                    'items.*.year_to_date.gross' => 'decimal string',
                    'items.*.year_to_date.nis' => 'decimal string',
                    'items.*.year_to_date.nht' => 'decimal string',
                    'items.*.year_to_date.education_tax' => 'decimal string',
                    'items.*.year_to_date.paye' => 'decimal string',
                    'items.*.year_to_date.net' => 'decimal string',
                    'items.*.periods_per_year' => 'how many pay periods a year — 12, 26 or 52; what the thresholds were divided by',
                ],
                'errors' => [],
                'offline' => 'Serve the cache from the secure store; never write a payslip to shared storage.',
            ],
            'payslips.pdf' => [
                'method' => 'GET', 'uri' => 'payslips/{payslip}/pdf', 'action' => [PayslipsController::class, 'pdf'],
                'apps' => $guard, 'capability' => 'payslips', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['payslip' => '[0-9]+'],
                'summary' => 'The same payslip as a PDF to keep or send on — THE FILE ITSELF, not JSON (`Content-Disposition` carries its name). Only the officer\'s own, and only on an approved run.',
                'request' => [],
                'response' => [],
                'errors' => ['404 not_found' => 'Not a payslip of yours, or not on an approved run — the two are deliberately the same answer.'],
                'offline' => 'Needs a connection. A file already downloaded stays on the phone.',
            ],
            'messages.index' => [
                'method' => 'GET', 'uri' => 'messages', 'action' => [MessagesController::class, 'index'],
                'apps' => $guard, 'capability' => 'messages', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Broadcasts to the estate and the guard\'s own thread with dispatch, newest first. Board guard-app-04.',
                'request' => ['since' => 'ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site\'s clock · optional (query) · only newer messages'],
                'response' => [
                    ...array_combine(array_map(static fn (string $k): string => 'items.*.'.$k, array_keys($message)), $message),
                    'unread' => 'messages from dispatch not yet read',
                ],
                'errors' => [],
                'offline' => 'Serve the cache plus queued outgoing messages.',
            ],
            'messages.store' => [
                'method' => 'POST', 'uri' => 'messages', 'action' => [MessagesController::class, 'store'],
                'apps' => $guard, 'capability' => 'messages', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Write to dispatch. Marks the guard\'s own line with dispatch read for THIS guard — never a broadcast, and never for another guard.',
                'request' => ['body' => 'string · required · at most 500'],
                'response' => [...$message, 'server_time' => 'the server\'s time', 'device_time' => 'the handset\'s time, as sent'],
                'errors' => [],
                'offline' => 'Queue with the device time.',
            ],
            // Read state is per guard, per phone.
            'messages.read' => [
                'method' => 'POST', 'uri' => 'messages/read', 'action' => [MessagesController::class, 'read'],
                'apps' => $guard, 'capability' => 'messages', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Mark messages read for THIS guard on THIS handset — send it when a conversation is shown. Idempotent: the first read is the one kept. Another guard\'s read state is never touched.',
                'request' => ['ids' => 'array of integers · required · 1–100 message ids from `messages.index`'],
                'response' => [
                    'ids.*' => 'integer — a message now read for this guard. An id the guard may not see (another guard\'s line, another estate, nobody\'s) is left out, silently',
                    'unread' => 'integer — messages from dispatch this guard has still not read, over the newest hundred',
                ],
                'errors' => [],
                'offline' => 'Queue. Show the conversation as read at once; the server learns when signal returns.',
            ],
            // A guard must be able to see their own licence expiry.
            'guards.me' => [
                'method' => 'GET', 'uri' => 'guards/me', 'action' => [ProfileController::class, 'show'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The guard\'s OWN details as the office holds them: name, service number, site, standing post, and their security licence with its expiry. Board guard-app-26. The guard is the token\'s — there is no id to pass. Never pay.',
                'request' => [],
                'response' => [
                    'name' => 'the guard\'s full name',
                    'employee_number' => 'e.g. "GS-1041"',
                    'site.id' => 'the estate',
                    'site.name' => 'its name',
                    'site.timezone' => 'the IANA zone every time at this site is shown in',
                    // The estate's OWN phases, in the estate's own order. The Guard App draws the search
                    // screen's phase filter from this and from nothing else: an estate with five phases
                    // shows five, one with two shows two, and a list drawn in code would be wrong at the
                    // second estate this platform signs.
                    'site.phases.*.name' => 'a phase of this estate, e.g. "Phase 1", in the estate\'s own order',
                    'site.phases.*.unit_count' => 'how many units stand in it',
                    'post' => 'null when the guard has no standing post',
                    'post.id' => 'the post',
                    'post.name' => 'e.g. "Main Gate"',
                    'licence.name' => 'what the licence is called — "PSRA licence"',
                    'licence.number' => 'the licence number as the office holds it, or null when the office has not recorded one',
                    'licence.expires_on' => 'YYYY-MM-DD, or null when the office has not recorded one',
                    'licence.state' => '`valid`, `expiring`, `expired` or `unknown`',
                    'licence.days_left' => 'integer — calendar days at the site until `expires_on` (0 on the day, negative once expired), or null',
                    'licence.warning_days' => 'integer — `expiring` means this many days or fewer are left (30)',
                ],
                'errors' => [],
                'offline' => 'Serve the cache with its age. Work the warning out again from `expires_on` and today\'s date — a cached `state` goes stale.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardSync(): array
    {
        $guard = [AppMatrix::GUARD];

        return [
            'sync.batch' => [
                'method' => 'POST', 'uri' => 'sync/batch', 'action' => [SyncController::class, 'batch'],
                'apps' => $guard, 'capability' => 'sync', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Upload the offline queue, in order. Each operation runs through its own endpoint with its own Idempotency-Key and device time; a retried batch replays. Stops at the first server failure.',
                'request' => [
                    'operations' => 'array · required · 1–50, in the order they happened',
                    'operations.*.id' => 'string · required · the app\'s own id for the operation, echoed back',
                    'operations.*.endpoint' => 'string · required · a catalogue name, e.g. `shifts.clock_in`, `gate.entry`, `patrol.scan`',
                    'operations.*.params' => 'object · optional · path parameters, e.g. `{"shift": 812}`',
                    'operations.*.body' => 'object · optional · the endpoint\'s request body',
                    'operations.*.idempotency_key' => 'string · required · the key chosen when the operation was queued',
                    'operations.*.device_time' => 'ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site\'s clock · required · when it happened on the handset',
                ],
                'response' => [
                    'results.*.id' => 'the operation id, as sent',
                    'results.*.endpoint' => 'as sent',
                    'results.*.outcome' => '`ok`, `refused` (a 4xx answer — show it), `failed` (5xx — resend) or `not_attempted` (after a failure — resend)',
                    'results.*.status' => 'the HTTP status the endpoint answered, or null',
                    'results.*.replayed' => 'true when answered from a previous attempt with the same key',
                    'results.*.body' => 'the endpoint\'s own response body, exactly as documented for it',
                    'results.*.body.**' => 'opaque here — see the named endpoint',
                    'counts.ok' => 'count',
                    'counts.refused' => 'count',
                    'counts.failed' => 'count',
                    'counts.not_attempted' => 'count',
                    'server_time' => 'the server\'s time — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => [],
                'offline' => 'This IS the offline path. Batchable: every guard write except `incidents.media` and sync itself; an unbatchable name comes back `refused` with `endpoint_not_batchable`.',
            ],
            'sync.pull' => [
                'method' => 'GET', 'uri' => 'sync/pull', 'action' => [SyncController::class, 'pull'],
                'apps' => $guard, 'capability' => 'sync', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'What changed since the last pull: shifts, order versions, messages, pending alertness checks, revoked passes and the current pass keys, request decisions, claim decisions, and any resident report of something HAPPENING NOW at this site while this guard is on duty.',
                'request' => ['since' => 'ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site\'s clock · optional (query) · the previous `next_since`, sent back untouched; default seven days'],
                'response' => [
                    'since' => 'as applied — UTC instant; show in the site\'s zone (`timezone`)',
                    'server_time' => 'the server\'s time — UTC instant; show in the site\'s zone (`timezone`)',
                    'next_since' => 'send this as `since` next time, untouched. It sits a few seconds behind this pull and, when a long list (`messages`, `resident_reports`, `alerts`) was cut to a page, where that list stopped — so the next pull carries on with no gap, and an item can come twice: keep every list by its id — UTC instant; show in the site\'s zone (`timezone`)',
                    'timezone' => self::ZONE,
                    ...self::shiftKeys('shifts.*.'),
                    'orders.*.set_id' => 'order set',
                    'orders.*.version_id' => 'a version published since',
                    'orders.*.version' => 'number',
                    'orders.*.title' => 'title',
                    'orders.*.effective_on' => 'YYYY-MM-DD',
                    'orders.*.requires_acknowledgement' => 'boolean',
                    'messages.*.id' => 'message',
                    'messages.*.direction' => '`broadcast` or `outbound`',
                    'messages.*.body' => 'text',
                    'messages.*.sent_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'alertness_checks.*.check_id' => 'a check to answer now',
                    'alertness_checks.*.issued_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'alertness_checks.*.respond_by' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'passes.revoked.*.pass_id' => 'a still-unexpired pass cancelled or used since — refuse it offline from now on',
                    'passes.revoked.*.status' => '`cancelled` or `used`',
                    'passes.revoked.*.at' => 'when — UTC instant; show in the site\'s zone (`timezone`)',
                    'passes.keys.*.version' => 'key version',
                    'passes.keys.*.public_key' => 'base64url Ed25519 public key',
                    'passes.keys.*.current' => 'boolean',
                    'requests.*.id' => 'a request decided since',
                    'requests.*.kind' => 'kind',
                    'requests.*.status' => 'decision',
                    'requests.*.decided_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'requests.*.decision_note' => 'or null',
                    'claims.*.claim_id' => 'a claim decided since',
                    'claims.*.shift_id' => 'the shift',
                    'claims.*.status' => '`approved` or `declined`',
                    'claims.*.decided_at' => 'UTC instant; show in the site\'s zone (`timezone`)',

                    /*
                     * Guards on duty at that site get the alert. The ticket
                     * itself still belongs to community staff with source = resident.
                     * Notification is not ownership." So this is a READ and nothing
                     * more — there is no endpoint here to take, answer or close one,
                     * and the report's owner, status and assignment never move.
                     * Empty unless this handset's guard is clocked on right now.
                     */
                    'resident_reports.*.report_id' => 'the report a resident said is happening NOW — read-only; it belongs to the estate office and nothing here changes that',
                    'resident_reports.*.number' => 'the number of the report — what the office and the resident both say out loud, e.g. 1042',
                    'resident_reports.*.title' => 'what, in the resident\'s own few words',
                    'resident_reports.*.detail' => 'or null — the rest of what they wrote, clipped',
                    'resident_reports.*.location' => 'where, in words',
                    'resident_reports.*.latitude' => 'degrees north, or null — where the resident was, only if they agreed to attach it',
                    'resident_reports.*.longitude' => 'degrees east, or null',
                    'resident_reports.*.accuracy_m' => 'the fix\'s accuracy in metres, or null — a wide circle is not a doorstep',
                    'resident_reports.*.reported_at' => 'UTC instant; show in the site\'s zone (`timezone`)',

                    /*
                     * THE SAME ALERTS THE PUSH CARRIES, FOR A PHONE THE PUSH CANNOT REACH. A panic
                     * or another officer's duress at this site reaches an on-duty guard by push
                     * (`PushAnnouncer::alertRaised`); with no push token — no Firebase on Android, a
                     * token Expo refused — it reached nobody's screen. Read-only, on duty only,
                     * never the guard's own alert, and never counted in `alerted_handsets`.
                     */
                    'alerts.*.alert_id' => 'an alert still open at this site, raised since — read-only; dispatch owns it',
                    'alerts.*.kind' => '`panic` (a resident) or `duress` (another officer), or medical/fire/intrusion',
                    'alerts.*.where' => 'or null — the unit, e.g. Lot 1; never a person\'s name',
                    'alerts.*.raised_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'alerts.*.is_drill' => 'true when the alert is a DRILL testing who a panic reaches (M10 §1.3) — say DRILL first; false for every real alert',
                ],
                'errors' => [],
                'offline' => 'Pull first whenever signal returns, then upload the batch.',
            ],
        ];
    }

    /**
     * AN OFFICER RESPONDING, FROM THEIR OWN HANDSET (M10 §1.3).
     *
     * "The resident's screen still shows the honest state: alerting → acknowledged → dispatched →
     * en route → on scene → resolved." A dispatcher can send an officer and record the other two
     * after a radio call; these are the officer saying them. Under `alerts`, not a capability of
     * its own, for `push()`'s reason: a new capability is missing from every token already issued,
     * and an officer should not have to re-enrol to say they are on their way to a panic.
     *
     * WHO MAY is `AlertProgress`'s rule: the officer dispatched, or — when nobody was — any officer
     * the alert reached (the guards it was pushed to). Nothing in a panic waits on the dispatcher.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function guardResponse(): array
    {
        $guard = [AppMatrix::GUARD];
        $where = ['alert' => '[0-9]+'];
        $response = self::alertForGuard();

        $refusals = [
            '403 own_alert' => 'The guard\'s OWN alert (their duress). Help is being sent to them; they cannot be the officer sent.',
            '403 not_on_shift' => 'Nobody was sent, and this guard is not among the officers the alert reached: on duty at the site, rostered there today, or rung by its ring when nobody answered.',
            '403 another_officer_responding' => 'Another officer was dispatched, or said "on my way" first. Only their handset records the response; the message names them. Tell dispatch by radio if going too.',
            '404 not_found' => 'No alert with that id at the guard\'s own site — an id at another estate answers identically.',
            '409 alert_closed' => 'The alert was resolved, closed as a false alarm or cancelled. The message says which and when. Nothing more can be recorded.',
        ];

        return self::guardResponseEndpoints($guard, $where, $response, $refusals);
    }

    /**
     * An alert as a responding officer sees it — `ResponseController::shape()`'s keys. Also the
     * `alert` a ring answer hands back, so the phone opens one screen from either.
     *
     * @return array<string, string>
     */
    private static function alertForGuard(): array
    {
        $instant = 'UTC instant; show in the site\'s zone (`timezone`)';

        return [
            'alert_id' => 'the alert',
            'kind' => '`panic`, `medical` or `fire` from a resident, `duress` from another officer, or `intrusion`',
            'is_drill' => 'whether this is a DRILL raised to test the fan-out (M10 §1.3) — show DRILL before anything else; false for every real alert',
            'status' => '`open`, `acknowledged`, `responding` (an officer has been sent or is coming), `resolved` or `false_alarm`',
            'where' => 'the lot, e.g. "Lot 47", or null — never the name of whoever pressed it',
            'raised_at' => 'when the server received it — '.$instant,
            'acknowledged_at' => 'when the control room took it, or null — '.$instant,
            'dispatched_at' => 'when the control room SENT an officer, or null. A guard on shift may respond without being sent — then this stays null — '.$instant,
            'en_route_at' => 'when the responding officer was recorded as on the way, or null. May stay null for good: an officer who ran straight there is recorded on scene only, and nothing is back-filled — '.$instant,
            'on_scene_at' => 'when the responding officer was recorded as at the scene, or null — '.$instant,
            'resolved_at' => 'when the alert was closed — resolved, a false alarm or cancelled — or null — '.$instant,
            'responder' => 'the name of the officer responding (dispatched, or first to say "on my way" when nobody was sent), or null while nobody is',
            'responder_is_you' => 'true when that officer is this handset\'s guard',
            'dispatched_to_you' => 'true when the control room SENT this guard',
            'can_en_route' => 'whether THIS handset may record "on my way" now. Draw the button only when true',
            'can_on_scene' => 'whether THIS handset may record "I am there" now. Draw the button only when true',
            'reason' => 'the sentence saying why "I am there" cannot be recorded from this handset (closed, another officer is responding, already recorded, not on shift), or null when it can. Show it as given',
        ];
    }

    /**
     * @param  list<string>  $guard
     * @param  array<string, string>  $where
     * @param  array<string, string>  $response
     * @param  array<string, string>  $refusals
     * @return array<string, array<string, mixed>>
     */
    private static function guardResponseEndpoints(array $guard, array $where, array $response, array $refusals): array
    {
        return [
            'alerts.show' => [
                'method' => 'GET', 'uri' => 'alerts/{alert}', 'action' => [ResponseController::class, 'show'],
                'apps' => $guard, 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => $where,
                'summary' => 'An alert at the guard\'s own site, as a responding officer sees it: where, what kind, how far the response has got — acknowledged, dispatched, en route, on scene, resolved — and whether THIS handset may record the next step. Open it from the `site_alert` push (its `id`; `kind: dispatched` means this guard was sent) or from `sync.pull` `alerts`. Poll while it is open.',
                'request' => [],
                'response' => $response,
                'errors' => [
                    '403 own_alert' => 'The guard\'s own duress: follow it with `GET /duress/{alert}` instead.',
                    '403 not_on_shift' => 'This guard is neither the officer responding nor one the alert reached.',
                    '404 not_found' => 'No alert with that id at the guard\'s own site — an id at another estate answers identically.',
                ],
                'offline' => 'Show the last answer with its age, and say it may be out of date: the response moves on without this handset.',
            ],
            'alerts.en_route' => [
                'method' => 'POST', 'uri' => 'alerts/{alert}/en-route', 'action' => [ResponseController::class, 'enRoute'],
                'apps' => $guard, 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-alerts', 'status' => 200, 'where' => $where,
                'summary' => '"I am on my way." The resident is pushed "An officer is on the way". Allowed for the officer dispatched — or, when nobody was dispatched, for any officer the alert reached, who then becomes its responder. Forward only: refused once on the way or at the scene is recorded, and refused after the alert is closed. The server records ITS time; the handset time is kept beside it in the audit log.',
                'request' => [],
                'response' => $response,
                'errors' => [
                    ...$refusals,
                    '409 already_en_route' => 'On the way is already recorded — by this handset, or by dispatch after a radio call. The message gives the time.',
                    '409 already_on_scene' => 'At the scene is already recorded; the response only moves forward.',
                ],
                'offline' => 'Queue with its key and the device time of the tap, and send the moment signal returns — the resident is told when the server hears it. A step recorded meanwhile (or the alert closed) comes back refused: show the sentence. Also radio dispatch.',
            ],
            'alerts.on_scene' => [
                'method' => 'POST', 'uri' => 'alerts/{alert}/on-scene', 'action' => [ResponseController::class, 'onScene'],
                'apps' => $guard, 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-alerts', 'status' => 200, 'where' => $where,
                'summary' => '"I am there." The resident is pushed "An officer has arrived". The same rule as `alerts.en_route`; it may be sent without an en route first (an officer who ran straight there), and en route can no longer be recorded after it.',
                'request' => [],
                'response' => $response,
                'errors' => [
                    ...$refusals,
                    '409 already_on_scene' => 'At the scene is already recorded. The message gives the time.',
                ],
                'offline' => 'Queue with its key and the device time of the tap, and send the moment signal returns. Refused if the alert was closed meanwhile: show the sentence. Also radio dispatch.',
            ],
        ];
    }

    /**
     * A PANIC RINGS THE GUARDS' PHONES LIKE A CALL (the guard ring §6).
     *
     * The ring itself is a push — `site_ring`, data-only, woken by the Guard App's own ring
     * module — and it carries a RING TICKET (`rt`): the phone's credential for the two acts
     * below, good for thirty minutes, bound to one push address and one guard. They take no
     * bearer token on purpose. The escalation rings officers whose Guard App session timed
     * out thirty minutes after they last used it, and SessionWindow is not widened for them.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function guardRing(): array
    {
        $alert = [];

        foreach (self::alertForGuard() as $key => $meaning) {
            $alert['alert.'.$key] = $meaning;
        }

        $ticket = [
            '401 ring_ticket_invalid' => 'The ticket is not one this server signed, or what it names is no longer true: the push address was revoked or moved, the guard is no longer active here, or the alert is not theirs to answer. The same answer for all of them. Show "This ring can no longer be answered here. Open the Guard App or radio dispatch."',
            '410 ring_ticket_expired' => 'The ticket is more than thirty minutes old (`ring.ticket_minutes`). The same sentence.',
        ];

        return [
            'ring.answer' => [
                'method' => 'POST', 'uri' => 'ring/answer', 'action' => [RingController::class, 'answer'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-ring', 'status' => 200,
                'summary' => 'Answer a ring from the ring screen, on its ticket — no bearer token. "I\'m responding" is exactly `alerts.en_route`: the same refusals, the resident told "An officer is on the way", and every other phone told to stop ringing. It is REFUSED when the tap is more than two minutes old (`age_ms`, measured on the phone\'s monotonic clock): radio dispatch instead. "Can\'t respond" is recorded; for the whole alert (`scope: alert`, after unlocking) it removes this guard from later rounds, and from the lock screen (`scope: round`) it silences only the ring in front of them. It never renews the Guard App\'s own session.',
                'request' => [
                    'rt' => 'string · required · the ring ticket from the `site_ring` push, at most 1024',
                    'answer' => 'string · required · `responding` or `cannot`',
                    'age_ms' => 'integer · required for `responding` · how many milliseconds ago the guard tapped, measured with the phone\'s monotonic clock (`SystemClock.elapsedRealtime`), never from its wall clock. Over 120000 is refused as stale',
                    'scope' => 'string · optional, `cannot` only · `round` from the lock screen (this ring only), `alert` after unlocking (every later round). Default `alert`',
                    'device_time' => 'string · optional · ISO 8601 with offset, or the X-Device-Time header — kept for the audit only',
                ],
                'response' => [
                    'recorded' => 'boolean — always true',
                    'answer' => '`responding` or `cannot`, as recorded',
                    'code' => '`already_yours` when this guard was already recorded as on the way or at the scene — a replay, or the Guard App got there first — and null otherwise',
                    'session_open' => 'whether the Guard App\'s own session on this phone is still open. False: the alert cannot be opened in the app — say "Recorded: you\'re responding. Dispatch and the resident have been told. Your Guard App session timed out, so the alert can\'t open there — follow it by radio."',
                    ...$alert,
                    'server_time' => 'the server\'s time — UTC instant',
                    'device_time' => 'the device time sent, or null — UTC instant',
                ],
                'errors' => [
                    ...$ticket,
                    '403 ring_ticket_invalid' => 'A TEST ring\'s ticket: "A test ring cannot answer an alert."',
                    '409 ring_answer_stale' => '"I\'m responding" tapped more than two minutes before it reached the server. Nothing is recorded. Show the sentence as sent: "…Radio dispatch to say whether you are going."',
                    '403 own_alert' => 'The guard\'s own alert.',
                    '403 not_on_shift' => 'This guard is not among the officers the alert reached or rang.',
                    '403 another_officer_responding' => 'Another officer is responding; the message names them. Tell dispatch by radio if going too.',
                    '409 alert_closed' => 'The alert was resolved, closed as a false alarm or cancelled. The message says which.',
                    '409 already_en_route' => 'On the way is already recorded, and not as this guard\'s claim. (This guard\'s own claim, replayed, answers 200 with `code: already_yours` instead.)',
                    '409 already_on_scene' => 'At the scene is already recorded.',
                    '422 device_time_invalid' => 'The device time is not an ISO 8601 timestamp.',
                ],
                'offline' => 'Keep the answer on the phone and send it the moment there is signal, every 10 s while the ring screen is shown and on the next start. Drop a `responding` two minutes after the tap, by the monotonic clock, and never send it: say "Not sent: too late to claim by phone. Radio dispatch to say whether you are going." A `cannot` is kept until the ticket expires. A 429 is kept and retried in 10 s.',
            ],
            'ring.receipt' => [
                'method' => 'POST', 'uri' => 'ring/receipt', 'action' => [RingController::class, 'receipt'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-ring', 'status' => 202,
                'summary' => 'What the phone did with a ring, on its ticket — no bearer token. `shown` is the only thing the console counts as "shown on the phone", and its `detail` is the phone\'s live sound check at that moment. Recorded once per phone, round and event; a replay changes nothing. Never touches the Guard App\'s session.',
                'request' => [
                    'rt' => 'string · required · the ring ticket of the ring this is about (a test ring\'s too)',
                    'event' => 'string · required · `shown` (the ring was posted), `full_screen` (the SYSTEM launched the full-screen ring — never a tap), `opened` (a tap, an action or a direct start), `held` (it became the silent notice), `dismissed` (swiped away: neither an answer nor a decline), `stopped`, `suppressed` (the guard is under their own duress), `fallback` (posted on an ordinary channel)',
                    'detail' => 'string · optional, at most 60 · for `shown`, the sound check: `imp=<0-5>;snd=<0|1>;vol=<n>/<max>;dnd=<pass|block|unknown>;fsi=<0|1>;lk=<0|1>`. Otherwise a short code',
                    'ready' => 'integer · optional · the readiness bitmask at that moment, bit set = ON: 0 notifications, 1 ring channel, 2 full-screen alerts (or not needed), 3 Do Not Disturb bypass, 4 battery optimisation exempt, 5 background activity allowed, 6 alarm volume OK (0 is read as off), 7 alarm tone set, 8 screen lock. Read leniently: a value the server cannot read is ignored',
                    'device_time' => 'string · optional · ISO 8601 with offset',
                ],
                'response' => [
                    'recorded' => 'boolean — always true',
                ],
                'errors' => [
                    ...$ticket,
                    '422 device_time_invalid' => 'The device time is not an ISO 8601 timestamp.',
                ],
                'offline' => 'Write the receipt down BEFORE sending it, send it on a background thread, and delete it on a 2xx. Flush what is left on the next push and the next start. A receipt never delays a ring.',
            ],
            'ring.test' => [
                'method' => 'POST', 'uri' => 'ring/test', 'action' => [RingController::class, 'test'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-ring-test', 'status' => 200,
                'summary' => 'Send a test ring to THIS sign-in\'s phone, from the Call-style alerts screen. It is a real push — the whole chain from the server to the ring screen — marked DRILL, fifteen seconds long, with no answer buttons. The phone\'s own `shown` receipt for it is what makes the console say "proven by a test ring".',
                'request' => [],
                'response' => [
                    'sent' => 'boolean — true: the test ring was handed to the push queue. Not proof that the phone rang: its own `shown` receipt is',
                    'ring_state' => '`ready`, `partial` or `not_ready` — the server\'s reading of this phone\'s last readiness report',
                ],
                'errors' => [
                    '409 no_push_address' => 'This sign-in has no push address yet: "This phone has no push address yet: allow notifications, then try again."',
                    '409 ring_not_supported' => 'The phone has not reported call-style alerts (an older Guard App, or an iPhone): "This phone has not reported call-style alerts: update the Guard App."',
                ],
                'offline' => 'Online only, and never queued: a test ring later is not a test of now.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function devices(): array
    {
        return [
            'devices.enrol' => [
                'method' => 'POST', 'uri' => 'devices/enrol', 'action' => [DeviceController::class, 'enrol'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 201,
                'summary' => 'Bind this handset to the guard an enrolment code was issued to, and receive its token. A guard who already has a handset gets a rebind request a supervisor must approve instead — unless it is the same handset under a new install. A USED code is accepted again only from the handset it set up, for the same guard, while that binding is still theirs: a RECOVERY after a reinstall, audited as one and mailed to the Heads of Security. On any other handset a used code stays dead.',
                'request' => [
                    'enrolment_code' => 'string · required · the one-time code Gemini\'s office gave the guard',
                    'device_uid' => 'string · required · a stable identifier for this install, max 120',
                    'platform' => 'string · required · `ios` or `android`',
                    'public_key' => 'string · required · the handset\'s Ed25519 public key, base64url (32 bytes)',
                    'label' => 'string · optional · what the handset is, e.g. "iPhone 15 Pro · Main Gate"',
                    'hardware_id' => 'string · optional · the handset\'s own identity, which a reinstall of the app keeps (Android\'s per-app device id), max 128. Stored only as a hash; without it the phone cannot recover after a reinstall',
                ],
                'response' => [
                    'status' => '`enrolled`, or `pending_approval` for a rebind',
                    'recovered' => 'true when a used code took back the binding this same handset already had (a recovery, not a new enrolment)',
                    'reviewer' => 'true ONLY for an App Store / Play reviewer\'s demonstration account, which the SERVER flags. A handset told true may accept the phone\'s PASSCODE in place of a fingerprint or face at clock-in. False for every real guard, and no build flag turns it on.',
                    'token' => 'the bearer token — shown once, store it in the secure enclave. Absent while pending.',
                    'abilities.*' => 'the abilities the token carries, from the app matrix. Absent while pending.',
                    'guard.id' => 'the guard',
                    'guard.name' => 'their name, to confirm on screen',
                    'guard.employee_number' => 'GS-1041',
                    'site.id' => 'the estate the guard is posted to',
                    'site.name' => 'its name',
                    'site.timezone' => self::ZONE,
                    'rebind_request_id' => 'while pending: the request to poll',
                    'claim_secret' => 'while pending: present this to collect the token once approved. Shown once.',
                ],
                'errors' => [
                    '422 enrolment_code_invalid' => 'The code is unknown, expired, or used — and this is not the handset it set up. One sentence for every case.',
                    '403 guard_not_active' => 'The code\'s guard is suspended or no longer employed.',
                    '422 public_key_invalid' => 'The public key is not 32 base64url-encoded bytes.',
                ],
                'offline' => 'Online only. Enrolment happens once, at the office.',
            ],
            'devices.enrol.status' => [
                'method' => 'POST', 'uri' => 'devices/enrol/{rebind}/collect', 'action' => [DeviceController::class, 'collect'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200, 'where' => ['rebind' => '[0-9]+'],
                'summary' => 'Poll a pending rebind, and collect the token once a supervisor approves it. The previous handset\'s token stops working at that moment.',
                'request' => [
                    'claim_secret' => 'string · required · from the enrol response',
                    'device_uid' => 'string · required · the same UID the request was made with',
                ],
                'response' => [
                    'status' => '`pending_approval`, `approved` (token below, once), `denied`, or `collected`',
                    'token' => 'present only on the first poll after approval',
                    'abilities.*' => 'with the token',
                    'decision_note' => 'the supervisor\'s note, when denied',
                ],
                'errors' => [
                    '404 not_found' => 'No such request, or the claim secret or device UID does not match.',
                ],
                'offline' => 'Online only.',
            ],

            /*
             * SIGNING BACK IN WITHOUT A CODE (M32 F3). The code binds a phone once; a guard signed out after that — the
             * 30-minute rule, a sign-out they chose — gets back in on the same phone with its own key's signature and a
             * fingerprint (or a supervisor's PIN). See `DeviceResume`.
             */
            'devices.challenge' => [
                'method' => 'POST', 'uri' => 'devices/challenge', 'action' => [DeviceController::class, 'challenge'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200,
                'summary' => 'A one-time challenge for a bound phone to sign before `devices.resume`: two minutes, one use. Asking again replaces it. It says nothing about whether the install is bound.',
                'request' => [
                    'device_uid' => 'string · required · the same stable id for this install that `devices.enrol` was sent',
                ],
                'response' => [
                    'challenge' => 'base64url, 32 bytes — sign "gs-resume-v1|<device_uid>|<challenge>" with the handset\'s Ed25519 key',
                    'expires_at' => 'when it stops working (UTC)',
                ],
                'errors' => [],
                'offline' => 'Online only. Signing back in is now or not at all.',
            ],
            'devices.resume' => [
                'method' => 'POST', 'uri' => 'devices/resume', 'action' => [DeviceController::class, 'resume'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200,
                'summary' => 'A bound phone signing back in without a code: the handset\'s own signature over the challenge, and how the person was confirmed — `biometric` (the phone matched the guard) or `supervisor_pin` (checked here, as at clock-in). `check` only asks whether this install is still bound and issues nothing. A new token replaces the guard\'s old ones; the push address moves to it.',
                'request' => [
                    'device_uid' => 'string · required · this install\'s id',
                    'challenge' => 'string · required · from `devices.challenge`',
                    'signature' => 'string · required · Ed25519, base64url (64 bytes), over "gs-resume-v1|<device_uid>|<challenge>"',
                    'method' => 'string · required · `check`, `biometric` or `supervisor_pin`',
                    'supervisor' => 'string · with `supervisor_pin` · the supervisor\'s sign-in address',
                    'pin' => 'string · with `supervisor_pin` · their six-digit PIN. Sent once; never stored on the phone',
                    'hardware_id' => 'string · optional · the handset\'s own identity, as sent to `devices.enrol`. Recorded, hashed, on a binding that has none once the signature proves the install, so the handset can recover after a reinstall',
                ],
                'response' => [
                    'state' => '`bound` (a check) or `resumed` (token below)',
                    'reviewer' => 'true ONLY for an App Store / Play reviewer\'s demonstration account, which the SERVER flags. False for every real guard.',
                    'token' => 'the new bearer token, when resumed — store it in the secure enclave',
                    'abilities.*' => 'with the token',
                    'guard.id' => 'the guard',
                    'guard.name' => 'their name, to greet on screen',
                    'guard.employee_number' => 'GS-1041',
                    'site.id' => 'the estate the guard is posted to',
                    'site.name' => 'its name',
                    'site.timezone' => self::ZONE,
                ],
                'errors' => [
                    '422 challenge_expired' => 'The challenge is unknown, used, or more than two minutes old. Ask for a new one.',
                    '403 device_not_enrolled' => 'No binding for this install, or the signature is not its key\'s: the phone has not been set up. A code from the office sets it up.',
                    '403 device_removed' => 'This install was bound and has been taken out of service; the message says when, and whether by a supervisor, the office, or the same handset recovering under a new install. `error.details.removed_at`, `error.details.removed_by` (`supervisor`, `office` or `guard`).',
                    '403 guard_not_active' => 'The guard is suspended or no longer employed.',
                    '403 override_refused' => 'The supervisor and PIN were not accepted — one sentence for every kind of no, as at clock-in.',
                ],
                'offline' => 'Online only. The phone says so, and the emergency numbers need no data.',
            ],
        ];
    }

    /**
     * SIGNING OUT, FOR REAL (M6, Q-M42). Both apps.
     *
     * The button said "Sign out" and signed nobody out of anything: the phone deleted its
     * own copy of the token and the row on the server lived for ever. A token ever copied
     * off a handset kept working — for ever, on a sold phone, from a backup.
     *
     * UNDER `alerts:read`, NOT A CAPABILITY OF ITS OWN, and for a harder version of the
     * reason `push` gives below: a capability invented today is absent from every token
     * ALREADY ISSUED, so every handset already enrolled and every resident already signed
     * in would be unable to sign out at all. `alerts:read` is the one ability every token
     * this platform mints carries — including the read-only "Explore a demo community"
     * token, which has no `:write` ability of any kind and must still be able to leave.
     *
     * READ, NOT WRITE, in the catalogue's sense: no Idempotency-Key and no X-Device-Time.
     * There is nothing to replay — the token it names does not exist a moment later — so a
     * stored answer could only ever be handed to a request that can no longer authenticate.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function session(): array
    {
        return [
            'auth.sign_out' => [
                'method' => 'DELETE', 'uri' => 'session', 'action' => [SessionController::class, 'destroy'],
                'apps' => [AppMatrix::GUARD, AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::READ,
                'write' => false, 'pending' => true,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Sign out: destroy the token this request was made with, on the SERVER. Call it LAST, after `DELETE /push/token`, and then drop the token from the keychain. It ends this sign-in and nothing else — not the account, not another phone. Signing out twice answers 401: the second attempt has no token to present, and that is the truth rather than an error. A session that has timed out (`session_expired`) is already over; clear the phone and say so.',
                'request' => [],
                'response' => [
                    'signed_out' => 'boolean — always true; this token is destroyed and will never authenticate again',
                ],
                'errors' => [],
                'offline' => 'Online only, and never queued — it names the very token that is about to be dropped, so a copy kept for later could never be sent at all. Best effort: sign the person out of the phone either way, say plainly that the server could not be told, and note that the session ends by itself inside the idle window.',
            ],
        ];
    }

    /**
     * Push to a closed app. Both apps; the owner is the API token's.
     *
     * UNDER `alerts`, NOT A CAPABILITY OF ITS OWN. A token carries the abilities it
     * was issued with; a new capability would be missing from every handset already
     * enrolled and every resident already signed in, and they would have to enrol
     * again to be told about a panic. Both apps already hold `alerts:write` — and
     * being reachable when the app is closed is what an alert is for.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function push(): array
    {
        $both = [AppMatrix::GUARD, AppMatrix::RESIDENT];

        return [
            'push.register' => [
                'method' => 'POST', 'uri' => 'push/token', 'action' => [PushController::class, 'register'],
                'apps' => $both, 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Register this install\'s Expo push token so the server can reach the app when it is CLOSED, with the notification categories switched off on this phone. Idempotent: registering the same token again moves it to whoever is signed in now, so a phone handed on stops receiving the last person\'s alerts. Register again when the token rotates or a switch changes. The token is never echoed back.',
                'request' => [
                    'token' => 'string · required · `ExponentPushToken[…]` or `ExpoPushToken[…]`, from the Expo SDK',
                    'platform' => 'string · required · `ios` or `android`',
                    'muted' => 'array of strings · optional · the categories switched off ON THIS PHONE. Guard App: `messages`, `orders`, `shifts`, `requests`, `payslips`. Resident App: `visitors`, `notices`, `maintenance`, `payments`, `meetings`, `voting`, `bookings`. Anything else is dropped — panic, duress, an alertness check and somebody at the gate cannot be muted',
                    'quiet' => 'object · optional · QUIET HOURS on this phone (board 20; M50 §4.2 #5): `{"on": true, "from": "22:00", "to": "07:00"}`, HH:MM on the ESTATE\'s clock — `to` earlier than `from` runs over midnight. Inside them nothing is pushed to this phone except the urgent types (panic, somebody at the gate). Left out, `on` false, or unreadable: no quiet hours. NEVER refused, like `ring`',
                    'ring' => 'object · optional, Guard App only · whether this phone can ring like a call, read by its ring module: `{"v": 1, "configured": true, "notifications": "on|off", "ring_channel": "on|off", "full_screen": "on|off|not_needed", "dnd_bypass": "on|off|unknown", "battery": "exempt|optimised|unknown", "background": "allowed|restricted|unknown", "alarm_volume": "ok|low|off", "alarm_tone": "set|none", "screen_lock": "on|off", "sdk": 35, "maker": "samsung"}`. NEVER refused: an unknown key is dropped and an unknown value read as unknown. Leave it out (an older app, iOS, Expo Go) and this phone is sent the ordinary alert every round. Send it again whenever it changes',
                ],
                'response' => [
                    'registered' => 'boolean — always true',
                    'muted.*' => 'the categories the server will NOT push to this phone',
                    'always_on.*' => 'the notification types no switch can silence, for the settings screen to say so',
                    'quiet' => 'the quiet hours the server will keep for this phone — `{from, to}` — or null for none',
                    'ring_state' => 'the SERVER\'s reading of `ring`: `ready` (rings like a call), `partial` (rings, but Do Not Disturb bypass, battery, background, alarm volume or screen lock is not right), `not_ready` (notifications, the ring channel, full-screen alerts, the alarm volume or tone is off, or unknown), or null when no `ring` was sent',
                ],
                'errors' => [
                    '422 push_token_invalid' => 'Not an Expo push token.',
                ],
                'offline' => 'Online only, and never queued: try again at the next start. The local notifier covers the app while it is open.',
            ],
            'push.unregister' => [
                'method' => 'DELETE', 'uri' => 'push/token', 'action' => [PushController::class, 'unregister'],
                'apps' => $both, 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Sign-out: stop pushing to the phone this API token registered. Call it BEFORE dropping the API token. The same answer whether or not anything was registered. (A handset revoked, an account suspended or a household member removed loses its push addresses on the server\'s side without this call.)',
                'request' => [],
                'response' => [
                    'registered' => 'boolean — always false: nothing is pushed to this sign-in\'s phone any more',
                ],
                'errors' => [],
                'offline' => 'Online only, and never queued — the API token it names is deleted the moment after it is sent, so a copy kept for later could never be sent at all. Best effort: if it cannot be sent the app gives up the phone\'s device token instead, and the address moves to whoever registers on this phone next.',
            ],
        ];
    }

    /**
     * LIVE UPDATES WHILE THE APP IS OPEN (M11 Part C, DESIGN §3.4, §8.1). Both apps.
     *
     * UNDER `alerts:read`, for the reason `push` and `auth.sign_out` give: it is the one
     * ability every token this platform has issued already carries, so no handset has to
     * enrol again to receive live updates. Each channel is then decided on its own, and
     * its rule asks the token for the ability of the data that channel carries.
     *
     * PENDING ACCOUNTS TOO: a Resident App account waiting on its claim holds its own
     * channel and its claim's, and nothing that belongs to a household.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function live(): array
    {
        $both = [AppMatrix::GUARD, AppMatrix::RESIDENT];
        $unavailable = ['503 live_unavailable' => 'This server is not configured for live updates. Keep refreshing as before; say that live updates are paused.'];

        return [
            'live.config' => [
                'method' => 'GET', 'uri' => 'live/config', 'action' => [LiveController::class, 'config'],
                'apps' => $both, 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Where the live-update socket is, and the channels this handset holds. The socket\'s HOST is the API URL\'s own host — it is never sent — and it is reached at `ws.scheme`/`ws.port` with the Pusher protocol, key `ws.key`. Subscribe each channel as `private-live.{name}`, authorised by `live.auth`, and the public `pulse` channel with no authorisation. Ask again after `access.changed`, after a claim is approved, and when the list is more than ten minutes old.',
                'request' => [],
                'response' => [
                    'ws.key' => 'the socket application key — public by design, it identifies the application and authorises nothing',
                    'ws.port' => 'the socket port on the API URL\'s host: 443 in production',
                    'ws.scheme' => '`https` (connect with TLS, `wss`) or `http` (a development server, `ws`)',
                    'ws.path' => 'the path before `/app/{key}`; empty',
                    'channels.*.name' => 'a channel this handset holds, e.g. `guard.{estate}.{guard}`; subscribe to it as `private-live.{name}`',
                    'channels.*.streams.*' => '`c` (the platform\'s log) and/or `e` (the estate\'s): keep one catch-up cursor per channel and stream',
                    'pulse' => 'the public heartbeat channel, `live-pulse`, carrying `{at, build}` every ten seconds; no pulse for 25 s means live updates are not working',
                    'build' => 'the server\'s build, or null',
                    'principal' => 'this handset\'s key, `g-{guard}` or `r-{account}` — the `user_id` every grant carries',
                ],
                'errors' => $unavailable,
                'offline' => 'Online only. With no answer, keep polling as the screen always has, and show that live updates are paused.',
            ],
            'live.auth' => [
                'method' => 'POST', 'uri' => 'live/auth', 'action' => [LiveController::class, 'auth'],
                'apps' => $both, 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'live-auth', 'status' => 200,
                'summary' => 'Grants for live channels, bound to one socket. Send every channel the socket is about to subscribe to in ONE request (a batch counts once against the limit). Each is answered in the order asked: signed, or refused — and a refusal says nothing about why, or whether what it names exists. Never sign a channel yourself; subscribe with exactly the `auth` and `channel_data` returned.',
                'request' => [
                    'socket_id' => 'string · required · the socket id the connection was given, `{digits}.{digits}`',
                    'channel_names' => 'array of strings · required · 1 to 200 channels, each as subscribed: `private-live.{name}`',
                ],
                'response' => [
                    'channels.*.name' => 'the channel, exactly as asked',
                    'channels.*.auth' => 'the grant to subscribe with, `{key}:{signature}`; null when refused',
                    'channels.*.channel_data' => 'the JSON string to subscribe with, `{"user_id":"g-5"}`; null when refused',
                    'channels.*.streams.*' => '`c` and/or `e`: the cursors this channel keeps; empty when refused',
                    'channels.*.refused' => 'true when this handset may not hold the channel',
                ],
                'errors' => $unavailable,
                'offline' => 'Online only, and never queued: a grant is for one socket, and a socket exists only while there is signal.',
            ],
            'live.catch_up' => [
                'method' => 'POST', 'uri' => 'live/catch-up', 'action' => [LiveController::class, 'catchUp'],
                'apps' => $both, 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'live-catchup', 'status' => 200,
                'summary' => 'Everything a channel missed after its cursor, read from the server\'s log, so nothing is ever lost to a dropped socket. Ask on every subscription, every reconnect and every return to the foreground, when a message\'s `p` is ahead of the cursor, on `live.nudge`, and on a timer while the socket is down (this IS the live update then). Apply each channel\'s events in order, then take its `head` as the cursor; on `reset`, refetch the screens that channel feeds and take the head. The first cursor for a stream is the LOWEST `X-Live-Head` seen since the socket last connected. Every channel is decided again; one this handset may not hold is `refused`, whatever it names.',
                'request' => [
                    'channels' => 'array · required · 1 to 200 channels, each as `live.config` lists it (no `private-live.` prefix)',
                    'channels.*.name' => 'string · required · the channel',
                    'channels.*.c' => 'integer or null · the cursor on the platform stream: the last seq applied, or null for none yet',
                    'channels.*.e' => 'integer or null · the cursor on the estate stream',
                    'channels.*.ep.c' => 'string · optional · the platform stream\'s epoch the cursor belongs to (8 characters, from `ep` in an answer or envelope)',
                    'channels.*.ep.e' => 'string · optional · the estate stream\'s epoch',
                ],
                'response' => [
                    'channels.*.name' => 'the channel, as asked',
                    'channels.*.status' => '`ok`, `refused` (this handset may not hold it — no events, and nothing said about why) or `reset` (the cursor is older than the server keeps, newer than its head, from another epoch, or too far behind: refetch the screen, then take the head)',
                    'channels.*.events.*.v' => 'envelope version, 1',
                    'channels.*.events.*.s' => '`c` or `e`: the stream this event is on',
                    'channels.*.events.*.ep' => 'the stream\'s epoch, 8 characters; a different one than the cursor\'s means reset',
                    'channels.*.events.*.q' => 'the event\'s sequence number on its stream, and the record\'s version',
                    'channels.*.events.*.p' => 'the previous sequence number on THIS channel and stream, or null',
                    'channels.*.events.*.t' => 'the event, `{entity}.{verb}`, e.g. `pass.revoked`',
                    'channels.*.events.*.e' => 'the entity, e.g. `pass`',
                    'channels.*.events.*.i' => 'the record\'s id, as a string',
                    'channels.*.events.*.m' => '`ref` (refetch that one record) or `inline` (its data is in `d`)',
                    'channels.*.events.*.d' => 'inline only: the record as it is now; null means it is gone. Its keys are listed per event by the unit that makes it live',
                    'channels.*.events.*.sum' => 'the event\'s safe summary; its keys are listed per event by the unit that makes it live',
                    'channels.*.events.*.o' => 'the origin nonce of the write that caused it, or null; a handset never sends one',
                    'channels.*.events.*.at' => 'when the change was recorded — UTC instant',
                    'channels.*.events.*.sim' => 'true for a simulated alert\'s events',
                    'channels.*.head.c' => 'the platform stream\'s head: the cursor to take after applying the events',
                    'channels.*.head.e' => 'the estate stream\'s head',
                    'channels.*.ep.c' => 'the platform stream\'s epoch, 8 characters',
                    'channels.*.ep.e' => 'the estate stream\'s epoch',
                    'channels.*.more' => 'true when the answer stopped short (2,000 events in all): ask again from the last event applied, and do not take the head yet',
                    'relay.ok' => 'false when an event has waited more than 5 s to be published: live updates are delayed, say so',
                    'relay.lag_ms' => 'how long, in milliseconds',
                    'build' => 'the server\'s build, or null',
                ],
                'errors' => [],
                'offline' => 'Online only, and never queued: it is asked again the moment there is signal, from the cursors the handset keeps.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function legacyGuard(): array
    {
        $shiftResponse = [
            'id' => 'the shift',
            'status' => '`on_duty` after clock-in, `completed` after clock-out',
            'actual_start' => 'the server\'s record of when the shift started — the FIRST clock-in, however many were sent — UTC instant; show in the site\'s zone (`timezone`)',
            'actual_end' => 'when it ended, or null — UTC instant; show in the site\'s zone (`timezone`)',
            'mock_location_flag' => 'true when the handset reported a mocked location at clock-in; recorded, never refused',
            'geofence_outcome' => 'what the start was judged on (M12 §1): `inside`, `inside_margin`, `no_geofence` — or `outside` / `poor_fix` / `no_position` for a start that waited in the queue and is flagged; null for an older build\'s start',
            'review_reasons' => 'what a supervisor is shown about the start — empty when nothing',
            'review_reasons.*' => '`mock_location`, `outside_at_capture`, `no_geofence`, `clock_disagrees` or `clock_changed`',
            'server_time' => 'the server\'s time — UTC instant; show in the site\'s zone (`timezone`)',
            'device_time' => 'the handset\'s time, as sent — UTC instant; show in the site\'s zone (`timezone`)',
            'clock_skewed' => 'true when the device time is more than two minutes AHEAD of the server\'s. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged',
        ];

        return [
            'alerts.store' => [
                'method' => 'POST', 'uri' => 'alerts', 'action' => [AlertController::class, 'store'],
                'apps' => [AppMatrix::GUARD, AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::WRITE,
                'write' => true, 'legacy' => true, 'throttle' => 'api-alerts', 'status' => 201,
                'summary' => 'Raise a panic, duress, medical, fire or intrusion alert. A guard\'s duress and a resident\'s panic are the same event to dispatch.',
                'request' => [
                    'kind' => 'string · required · `panic`, `duress`, `medical`, `fire` or `intrusion`',
                    'unit_reference' => 'string · optional · the unit the alert is AT, e.g. "Lot 47". A GUARD may name one — an intrusion at the lot they are standing at. On a RESIDENT token it is IGNORED and the token\'s own unit is used: the address on a panic is not the phone\'s to choose, and a wrong one sends people to a neighbour\'s door',
                    'latitude' => 'number · optional',
                    'longitude' => 'number · optional',
                    'captured_offline' => 'boolean · optional · true when the alert was raised with no signal and is being sent now',
                    'tenant_id' => 'string · optional, legacy · must equal the token\'s own estate when sent',
                    'guard_id' => 'integer · optional, legacy · must equal the token\'s own guard when sent',
                ],
                'response' => [
                    'id' => 'the alert',
                    'status' => '`open`',
                    'server_time' => 'the server\'s time — UTC instant; show in the site\'s zone (`timezone`)',
                    'device_time' => 'the handset\'s time, as sent — UTC instant; show in the site\'s zone (`timezone`)',
                    'clock_skewed' => 'true when the device time is more than two minutes AHEAD of the server\'s. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged',
                ],
                'errors' => [
                    '403 wrong_site' => '`tenant_id` or `guard_id` in the body names another estate or guard.',
                ],
                'offline' => 'Queue with its Idempotency-Key and `captured_offline: true`; send the moment signal returns. The device time is the moment of the press. Also call the local emergency number.',
            ],
            'passes.verify' => [
                'method' => 'POST', 'uri' => 'passes/verify', 'action' => [PassVerificationController::class, 'verify'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'gate', 'access' => AppMatrix::WRITE,
                'write' => false, 'legacy' => true, 'throttle' => 'api-gate-events', 'status' => 200,
                'summary' => 'Legacy household verdict by household id. Superseded by `POST /gate/verify`, which verifies a signed pass; kept for the simulator.',
                'request' => [
                    'household_id' => 'integer · required',
                    'pass_category' => 'string · required · e.g. `guest`',
                ],
                'response' => [
                    'verdict' => '`admit` or `restricted`; `deny` when the household is unknown',
                    'tone' => '`green`, `amber` or `red`',
                    'headline' => 'what the guard is told',
                    'detail' => 'one sentence, or null',
                    'household' => 'the household\'s name',
                    'unit' => 'the unit reference',
                    'access_restricted' => 'boolean — the ONLY thing a guard is ever told about a household\'s standing',
                ],
                'errors' => ['404 deny' => 'No such household at this estate (body carries `verdict: deny`).'],
                'offline' => 'Not available offline. Use `POST /gate/verify` with a signed pass, which verifies on the handset.',
            ],
            'gate_events.store' => [
                'method' => 'POST', 'uri' => 'gate-events', 'action' => [GateEventController::class, 'store'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'gate', 'access' => AppMatrix::WRITE,
                'write' => true, 'legacy' => true, 'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'Record what the guard decided at the gate, after the verdict. Legacy form of `/gate/entry`, `/gate/exit` and `/gate/override`.',
                'request' => [
                    'verdict' => 'string · required · `admit`, `deny`, `override` or `exit`',
                    'category' => 'string · required · e.g. `Visitor`, `Contractor`, `Delivery`',
                    'subject' => 'string · required · who or what arrived',
                    'basis' => 'string · required · `QR pass`, `Pre-approved`, `Tag read`, `Guard decision`, or `Household declined` (a walk-up the household said no to: the denial is theirs)',
                    'reason' => 'string · required for `override`',
                    'post_id' => 'integer · optional · defaults to the guard\'s own post',
                    'tenant_id' => 'string · optional, legacy · must equal the token\'s estate',
                    'guard_id' => 'integer · optional, legacy · must equal the token\'s guard',
                ],
                'response' => [
                    'id' => 'the gate event',
                    'verdict' => 'as recorded',
                    'occurred_at' => 'the server\'s time of the event — UTC instant; show in the site\'s zone (`timezone`)',
                    'pass_based' => 'true when the basis was a platform-issued pass or approval',
                    'server_time' => 'the server\'s time — UTC instant; show in the site\'s zone (`timezone`)',
                    'device_time' => 'the handset\'s time, as sent — UTC instant; show in the site\'s zone (`timezone`)',
                    'clock_skewed' => 'true when the device time is more than two minutes AHEAD of the server\'s. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged',
                ],
                'errors' => ['403 wrong_site' => 'A body field names another estate, guard or post.'],
                'offline' => 'Queue with its Idempotency-Key; the device time is when the guard decided.',
            ],
            'shifts.clock_in' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/clock-in', 'action' => [ShiftController::class, 'clockIn'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'shifts', 'access' => AppMatrix::WRITE,
                'write' => true, 'legacy' => true, 'throttle' => 'api-shift-clock', 'status' => 200, 'where' => ['shift' => '[0-9]+'],
                'summary' => 'Clock on — the start the PHONE CONFIRMED, recorded as `app`. A handset no longer chooses how a start is labelled: a start the phone could not confirm goes to `shifts.clock_in_override`, where a supervisor\'s PIN is checked and the shift is flagged. THE GEOFENCE IS JUDGED HERE (M12 §1), by the rule `shifts.preflight` uses, on the fix sent: a start sent while the guard is there that is outside, or has no position, is refused (409); one that waited in the queue is started at the time of the tap and flagged for review. The start is the tap on the SERVER\'s clock (`X-Device-Time` moved by the phone\'s error, from `X-Device-Sent-At`). The first clock-in is the one that happened; a replayed queue never moves it.',
                'request' => [
                    'latitude' => 'number · optional · with `longitude` · where the phone was at the tap — judged against the fence and kept on the shift',
                    'longitude' => 'number · optional',
                    'accuracy_m' => 'integer · optional · the fix\'s accuracy in metres',
                    'captured_run' => 'string · optional · the app\'s run id at the tap (`X-Device-Run` then). Sent by every build that judges the fence; without it the start is recorded as an older build\'s was, and never refused',
                    'captured_mono_ms' => 'integer · optional · the app\'s steady clock at the tap (`X-Device-Mono` then): with the headers on the send, whether the phone\'s clock was changed while it waited',
                    'geofence_distance_m' => 'integer · optional · an OLDER build\'s own distance from the post, stored as sent',
                    'mock_location' => 'boolean · optional · the handset\'s own mock-location detection — recorded, flagged for the Head of Security, never refused alone',
                ],
                'response' => $shiftResponse,
                'errors' => [
                    '403 not_your_shift' => 'The shift is rostered to another guard at your own site.',
                    '404 not_found' => 'No such shift — an id nobody has, and an id belonging to another estate, answer identically.',
                    '409 outside_geofence' => 'Sent while the guard is there, and outside the rostered post\'s geofence. The message says how far and which way; the phone offers a fresh fix and the supervisor override (`outside_geofence`).',
                    '409 position_unconfirmed' => 'Sent while the guard is there, with no position or one too inaccurate to judge. The phone offers a fresh fix and the supervisor override (`position_unconfirmed`).',
                    '422 validation_failed' => 'A `method` other than `app` was sent. `manual` was the old silent unconfirmed start and is refused: an unconfirmed start is never accepted quietly.',
                ],
                'offline' => 'Queue with its Idempotency-Key, the device time of the tap and the fix, judged first on the phone against the cached `shift.fence`. The first clock-in wins. Only ever captured with the identity CONFIRMED by the phone.',
            ],
            'shifts.clock_out' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/clock-out', 'action' => [ShiftController::class, 'clockOut'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'shifts', 'access' => AppMatrix::WRITE,
                'write' => true, 'legacy' => true, 'throttle' => 'api-shift-clock', 'status' => 200, 'where' => ['shift' => '[0-9]+'],
                'summary' => 'Clock off, with an optional handover note for the next shift.',
                'request' => [
                    'handover_note' => 'string · optional · max 500 · read by whoever relieves the post',
                ],
                'response' => $shiftResponse,
                'errors' => [
                    '403 not_your_shift' => 'The shift is rostered to another guard at your own site.',
                    '404 not_found' => 'No such shift — an id nobody has, and an id belonging to another estate, answer identically.',
                    '409 shift_not_started' => 'No clock-in is recorded. Send the queued clock-in first.',
                ],
                'offline' => 'Queue after the clock-in it follows; the sync batch preserves order.',
            ],
            'standing_orders.index' => [
                'method' => 'GET', 'uri' => 'standing-orders', 'action' => [StandingOrderController::class, 'index'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'orders', 'access' => AppMatrix::READ,
                'write' => false, 'legacy' => true, 'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Every order set the guard works to: general orders and their post\'s. Superseded by `GET /sites/{site}/standing-orders/current`.',
                'request' => [],
                'response' => [
                    'orders.*.id' => 'the order set',
                    'orders.*.title' => 'its title',
                    'orders.*.version' => 'the current version',
                    'orders.*.effective_on' => 'YYYY-MM-DD',
                    'orders.*.body' => 'the full text',
                    'orders.*.requires_acknowledgement' => 'true for the guard\'s post orders',
                    'orders.*.acknowledged' => 'whether this guard has acknowledged THIS version',
                ],
                'errors' => [],
                'offline' => 'Cache the last response and show it; an acknowledgement made offline is queued.',
            ],
            'passes.keys' => [
                'method' => 'GET', 'uri' => 'sites/{site}/pass-keys', 'action' => [PassKeyController::class, 'index'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'gate', 'access' => AppMatrix::READ,
                'write' => false, 'throttle' => 'api-reads', 'status' => 200, 'where' => ['site' => '[a-z0-9]+'],
                'summary' => 'The site\'s Ed25519 PUBLIC keys, every version a still-valid pass may carry. Cache them: they are what verifies a pass with no signal.',
                'request' => [],
                'response' => [
                    'site_id' => 'the site',
                    'algorithm' => '`Ed25519`',
                    'keys.*.version' => 'the key version a pass payload names',
                    'keys.*.public_key' => 'base64url, 32 bytes',
                    'keys.*.current' => 'true for the version new passes are signed with',
                    'refresh_after' => 'fetch again after this time — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => ['403 wrong_site' => 'A site other than the guard\'s own.'],
                'offline' => 'Serve from cache. Refresh at every sync; a key the handset has never seen verifies as `unknown_key`.',
            ],
        ];
    }

    /**
     * Prefix every key of a shape.
     *
     * @param  array<string, string>  $shape
     * @return array<string, string>
     */
    private static function under(string $prefix, array $shape): array
    {
        return array_combine(array_map(static fn (string $k): string => $prefix.$k, array_keys($shape)), $shape);
    }

    /** @return array<string, array<string, mixed>> */
    private static function residentAccount(): array
    {
        $resident = [AppMatrix::RESIDENT];
        $claim = [
            'id' => 'the claim',
            'status' => '`pending`, `approved` or `rejected`',
            'submitted_unit' => 'as typed, e.g. "Phase 2 · Lot 47"',
            'submitted_name' => 'as typed',
            'decision_reason' => 'why it was rejected, or null',
            'document_requested' => 'the document the estate asked for, e.g. "photo ID", or null',
            'submitted_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'invited_by' => 'who invited this person — the primary resident\'s name, or "the estate office" — present only on a claim that came from an app invite',
            'invited_at' => 'UTC instant; show in the site\'s zone — present only on a claim that came from an app invite',
        ];
        $member = [
            'id' => 'the resident on the register',
            'full_name' => 'name',
            'relationship' => '`owner`, `tenant`, `spouse`, `child`, …',
            'is_primary' => 'the household\'s primary resident',
            'status' => '`verified`, or `pending` until the estate verifies them',
            'phone' => 'or null — null on a record saved before every field was required (M18C), and a Helper\'s for anybody but the primary resident and the Helper (rules table row 20)',
            'email' => 'or null — null when `alerts_to_primary`, on an older record, and a Helper\'s for anybody but the primary resident and the Helper (row 20)',
            'alerts_to_primary' => 'boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach',
            'access_level' => '`member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release',

            // A Helper's days and hours (8 Oct 2026): null for anybody else.
            'access_window' => 'a Helper\'s days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release',
            'access_window.days' => 'the days their e-Pass works, 1 (Monday) to 7 (Sunday)',
            'access_window.days.*' => 'integer — a day, 1 (Monday) to 7 (Sunday)',
            'access_window.from' => 'from, "07:00" on the estate\'s clock',
            'access_window.to' => 'until, "19:00" — earlier than `from` is an overnight window',
            'access_window.until' => 'A CALENDAR DATE: the last day it works — or null, no end',
            'access_window.paused' => 'boolean — the household paused them: their e-Pass is cancelled and none is issued',
            'access_window.own' => 'boolean — the household set these; false is the estate\'s default for a helper',
            'access_window.line' => 'the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household"',

            // The invite to the Resident App, as it stands — `ResidentInvites::outcome`. Never "sent" unless the mail server accepted it.
            'invite.status' => '`none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed`',
            'invite.email' => 'the one address it is for, or null',
            'invite.sent_at' => 'when the mail server last accepted it (UTC), or null — never merely when it was asked',
            'invite.last_attempt_at' => 'the last try, sent or not (UTC), or null',
            'invite.reason' => 'why it was not sent, in words to show as they are, or null',
            'invite.expires_at' => 'when it lapses (UTC), or null',
            'invite.resend_after' => 'when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now',
            'invite.attempts' => 'how many times it was tried',
        ];
        $vehicle = ['id' => 'the vehicle', 'plate' => 'upper-cased', 'make' => 'or null', 'model' => 'or null', 'colour' => 'or null'];
        $pet = ['id' => 'the pet', 'name' => 'its name', 'kind' => '`dog`, `cat`, `bird`, `fish`, `rabbit` or `other`', 'kind_label' => 'the kind in words to show, "Dog"'];
        $contact = ['id' => 'the contact', 'name' => 'name', 'relationship' => 'or null', 'phone' => 'number', 'email' => 'or null — a panic emails the contact at once (M12 §2.5)'];
        $profile = [
            'account.id' => 'the account',
            'account.status' => '`pending` or `active`',
            'account.channel' => '`email` or `sms`',
            'account.destination_hint' => 'masked, e.g. "a•••@example.com"',
            'account.full_name' => 'or null',
            'account.photo' => 'null, or the profile photo: fetch `account.photo.url` with this token (`me.photo`)',
            'account.photo.url' => 'a path under /api/v1 carrying the photo\'s version (`?v=`), so a changed photo is a new address',
            'account.photo.updated_at' => 'or null — UTC instant',
            'account.shown' => 'what this ACCOUNT has been shown once (M47 §3) — the record is the account\'s, so it holds on every phone, after a sign-out and after a reinstall',
            'account.shown.tour' => 'when the first-run tour was first finished or skipped — UTC instant — or null: show it',
            'estate.id' => 'the estate',
            'estate.name' => 'its name',
            'estate.timezone' => self::ZONE,
            'claim' => 'null until a unit is claimed',
            ...self::under('claim.', $claim),
            'unit' => 'null while pending',
            'unit.id' => 'the unit',
            'unit.reference' => 'e.g. "Lot 47"',
            'unit.phase' => 'e.g. "Phase 2"',
            'household' => 'null while pending',
            'household.id' => 'the household — the id `/households/{household}/…` takes',
            'household.name' => 'its name',
            'resident' => 'the register entry this account signs in as, or null',
            ...self::under('resident.', $member),
            'missing_details' => 'array of strings · what is still missing from this person\'s own register entry — `phone`, `email`, both or neither (M18C §5). Ask ONCE on sign-in, politely ("We need a phone number and an email so we can reach you in an emergency"), let it be dismissed, and ask again next time. Never block anything for it — a panic least of all',
            'missing_details.*' => '`phone` or `email`',
            'drill_mode' => 'object or null · drill mode (M53 §3A.1): while the office has it on for this account, its Panic is a DRILL that wakes nobody. Say so on the Panic screen, with when it ends, so the person pressing it knows; null when it is off. Only the server sets it, and it ends by itself. Absent on a server older than this release',
            'drill_mode.until' => 'when it switches itself off — UTC instant',
            'drill_mode.until_local' => 'the same moment on the estate\'s clock, ready to show, e.g. "11:45 PM"',
            'drill_mode.reason' => 'why the office switched it on, in its own words, or null',
            'account_deletion' => 'this estate\'s deletion windows (M18H §1.2), for what "Delete my account" says BEFORE anything happens',
            'account_deletion.self_days' => 'integer · days after deleting in which the person restores it themselves, by signing in — 7 unless the estate set otherwise',
            'account_deletion.office_days' => 'integer · days after deleting in which the estate office can restore it; after that nothing of the person is left on it — 30 unless the estate set otherwise',

            // The access level (`AppMatrix::LEVELS`) — what this person may do, from the matrix the server enforces.
            'access' => 'null while pending, or what this person may do in the app. The SERVER is the rule and refuses the rest with `403 not_for_your_access_level`. Panic and the person\'s own e-pass are never removed and are not listed. Absent on a server older than this release',
            'access.level' => '`member`, `dependent` or `helper`',
            'access.level_label' => '"Member", "Dependent" or "Helper"',
            'access.is_primary' => 'boolean — the household\'s primary resident, who alone adds, invites, removes and changes the level of members',
            'access.primary_name' => 'the household\'s primary resident\'s name — who to ask to change this person\'s access — or null when the household has none. Absent on a server older than this release',
            'access.levels_open' => 'boolean — whether anybody may be given Dependent or Helper access yet. False until the server switches levels on: everybody is a Member, and `members.store`, `members.invite` and `members.level` refuse any other level with `409 access_levels_not_open` — so do not offer the choice. Absent on a server older than this release',
            'access.may.household' => 'boolean — may read the household\'s members, vehicles and emergency contacts',
            'access.may.household_edit' => 'boolean — may add and remove vehicles and emergency contacts',
            'access.may.manage_members' => 'boolean — may add, invite, remove and change the level of members (the primary resident only)',
            'access.may.visitor_passes' => 'boolean — may issue, change, share and cancel visitor passes',
            'access.may.approvals' => 'boolean — may answer a guard\'s walk-up request',
            'access.may.dues' => 'boolean — may read invoices, balance, statement and receipts',
            'access.may.payments' => 'boolean — may start a payment and manage AutoPay',
            'access.may.tickets' => 'boolean — may report and follow maintenance and security requests',
            'access.may.notices' => 'boolean — may read notices',
            'access.may.meetings' => 'boolean — may read meetings and RSVP',
            'access.may.elections' => 'boolean — may see elections and cast the household\'s ballot',
            'access.may.bookings' => 'boolean — may book amenities',
        ];

        return [
            'estates.index' => [
                'method' => 'GET', 'uri' => 'estates', 'action' => [EstatesController::class, 'index'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200,
                'summary' => 'The active estates a resident can pick from BEFORE signing in — so nobody has to type a community code. No token. Says no more than the sign on the gate: id, name, parish.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the estate\'s id — what `auth.otp.request` / `auth.otp.verify` take as `estate`',
                    'items.*.name' => 'the estate\'s name',
                    'items.*.parish' => 'its parish, or null — tells two estates of the same name apart',
                    'items.*.demo' => 'true for the DEMONSTRATION community: invented data, nobody lives there. Label it as one, and offer "Explore a demo community" (`demo.enter`) only when this list holds one.',
                ],
                'errors' => ['429 rate_limited' => 'Ten requests a minute per address.'],
                'offline' => 'Serve the last list; the typed community code always remains as a fallback.',
            ],
            'auth.otp.request' => [
                'method' => 'POST', 'uri' => 'auth/otp/request', 'action' => [AuthController::class, 'requestCode'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 202,
                'summary' => 'Send a six-digit sign-in code by email or text. A sign-in (`intent: sign_in`) is told at once when the register does not hold the address; Create account, and an app that sends no intent, are answered the same whether or not an account exists (M80 §2.1). Board resident-app-01.',
                'request' => [
                    'estate' => 'string · required · the estate\'s id, e.g. `phoenixpark` — the app ships the list or reads it from a QR code at the estate office',
                    'channel' => 'string · required · `email` or `sms`',
                    'destination' => 'string · required · the email address, or the number in any common format',
                    'intent' => 'string · optional · `sign_in` from Log in (the address is looked up first: `422 email_not_found` when no resident and no app account at this estate holds it), `sign_up` from Create account; absent, as an app already installed sends it, the request is answered as before',
                    'device_uid' => 'string · optional · the install\'s id, as `auth.otp.verify` takes it — counted against for sign-in lookups and for nothing else (the network address when absent); a claim the phone makes, never an identity',
                ],
                'response' => [
                    'sent' => 'always true — the mail server ACCEPTED the code (the request waits for it); anything else is an error below',
                    'channel' => 'as sent',
                    'destination_hint' => 'masked',
                    'destination' => 'the address or number the code went to, IN FULL, exactly as the server read what was typed — show it on the code screen with "Not your email? Change it" (M18G §4.3). It is the requester\'s own typing, echoed',
                    'expires_at' => 'ten minutes from issue — UTC instant; show in the site\'s zone (`timezone`)',
                    'resend_after' => 'a request before this reuses the code already sent — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => [
                    '404 estate_not_found' => 'No active estate by that id.',
                    '422 email_domain_unreachable' => 'The address\'s domain has nowhere to deliver mail to ("…@gmail.comm") — nothing was sent. The message says so, and names the address it probably meant when there is one (M18G §4.2).',
                    '422 email_not_found' => 'A sign-in (`intent: sign_in`) at an address no resident and no app account at this estate holds — no code was made: "We can\'t find that email. Check the spelling, or ask your estate office to add it." Stay on the sign-in screen and show it beside the email field (M80 §2.1).',
                    '422 email_not_sent' => 'Only sample-data people hold this address, and the platform never mails them — no code was sent, and none is left to resend. Show the message: the resident asks the estate office to check the email on their household (M80 §2.1).',
                    '503 sms_unavailable' => 'No SMS provider is connected, or the provider refused the code — nothing was texted. Sign in with email.',
                    '503 mail_unavailable' => 'The code could not be emailed just now — nothing was sent, and the code is withdrawn. Try again in a minute.',
                    '429 rate_limited' => 'Ten requests a minute per address; or five new codes in an hour for this destination at this estate, from any address; or, for a sign-in, thirty lookups an hour from one phone, or ten for one address whether or not the register holds it — nothing was sent, and `Retry-After` says when a new code can be asked for.',
                ],
                'offline' => 'Online only.',
            ],
            'auth.otp.verify' => [
                'method' => 'POST', 'uri' => 'auth/otp/verify', 'action' => [AuthController::class, 'verifyCode'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200,
                'summary' => 'Prove the code and receive this install\'s token. A new account is `pending` until the estate approves a unit claim; signing in again on the same install replaces its token. An address the estate office or the household\'s primary resident invited, proven by this code, arrives at `await_approval` with a claim already matched to its register entry (`claim.invited_by`), or at `home` when the estate trusts its primary residents\' invites. `next` keeps its three values.',
                'request' => [
                    'estate' => 'string · required',
                    'channel' => 'string · required',
                    'destination' => 'string · required · as sent to `/auth/otp/request`',
                    'code' => 'string · required · six digits',
                    'device_uid' => 'string · required · a stable id for this install',
                    'platform' => 'string · required · `ios` or `android`',
                    'device_name' => 'string · optional · what the phone calls itself — its model ("SM-S938B", "iPhone 15"), at most 80 — so the account\'s list of signed-in phones (`me.sessions`) can name it. Never a serial or an IMEI',
                    'hardware_id' => 'string · optional · the handset\'s own id (Android: the per-app device id, which a reinstall keeps; iOS: the vendor id), stored as a hash: signing in again on the same handset ENDS its earlier sign-ins of this account instead of adding one beside them (M49)',
                ],
                'response' => [
                    'token' => 'the bearer token — store it in the keychain/keystore',
                    'abilities.*' => 'the Resident App\'s abilities, from the app matrix',
                    'reviewer' => 'true ONLY for an App Store / Play reviewer\'s demonstration account, flagged on the SERVER. Draw the demonstration banner from it. False for every real resident.',
                    'read_only' => 'boolean · false here — an ordinary sign-in can write. See `demo.enter`, which cannot.',
                    'account.id' => 'the account',
                    'account.status' => '`pending` or `active`',
                    'account.full_name' => 'or null',
                    'estate.id' => 'the estate',
                    'estate.name' => 'its name',
                    'estate.timezone' => self::ZONE,
                    'claim' => 'null, or the account\'s latest claim',
                    ...self::under('claim.', $claim),
                    'next' => '`claim_unit`, `await_approval` or `home` — the screen to open',
                    'restored' => 'boolean · false here; true from `auth.restore` — the account had been deleted and is back (M18H). Say so once: "Your account is back."',
                    'fingerprint_sign_in' => 'boolean · whether THIS install still holds a live fingerprint key (`me.device_key.store`) — what "Face ID / fingerprint" shows once signed in (M49)',
                ],
                'errors' => [
                    '409 account_deleted' => 'THE ACCOUNT AT THIS ADDRESS WAS DELETED AND CAN STILL COME BACK (M18H §2.3). No token. Open the restore screen with the message as sent ("This account was deleted on 27 September. You can restore it until 4 October and nothing will be lost.") and `error.details`: `deleted_on`, `restore_until`, `office_until` (estate-calendar dates), `can_restore` (the person\'s own window is open), `office_email` / `office_phone` (either may be null), and while `can_restore` a `ticket` (with `ticket_expires_at`, fifteen minutes) — "Restore my account" sends it to `auth.restore`. When `can_restore` is false the screen offers only "Contact the estate office". Once the estate\'s last window has closed nothing is refused: the address signs in as a new account.',
                    '404 estate_not_found' => 'No active estate by that id.',
                    '422 otp_invalid' => 'Wrong code; the message says how many tries are left.',
                    '422 otp_expired' => 'Expired, used, or replaced by a newer code.',
                    '423 otp_locked' => 'Five tries spent on this code, however many were in flight at once. Request a new one.',
                    '429 rate_limited' => 'Ten requests a minute per address; or ten codes tried in an hour for this destination at this estate, across every code and from any address — even the right code is refused until `Retry-After`.',
                    '403 account_suspended' => 'The estate withdrew access.',
                    '403 account_revoked' => 'The estate office ended this account\'s access (Q-M44). Refused HERE, before a token exists — a sign-in that succeeds and an app that then fails at everything would be a fake success. Only the estate office can give it back.',
                ],
                'offline' => 'Online only.',
            ],
            'auth.restore' => [
                'method' => 'POST', 'uri' => 'auth/restore', 'action' => [AuthController::class, 'restore'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200,
                'summary' => '"Restore my account" (M18H §2.3): bring back an account the person deleted, inside their own window, and sign this phone in. The ticket is the one `auth.otp.verify` sent with `409 account_deleted` — proof of the code, for that account on this install, good for fifteen minutes. Everything comes back as it was (unit, household, balance, history) except the visitor passes the deletion cancelled; the estate office is emailed. The answer is `auth.otp.verify`\'s, with `restored: true`.',
                'request' => [
                    'estate' => 'string · required',
                    'ticket' => 'string · required · `error.details.ticket` from `409 account_deleted`',
                    'device_uid' => 'string · required · the SAME install id the sign-in sent — a ticket works on the phone it was issued to and no other',
                    'platform' => 'string · required · `ios` or `android`',
                    'device_name' => 'string · what the phone calls itself, as `auth.otp.verify` takes it',
                    'hardware_id' => 'string · optional · the handset\'s own id, as `auth.otp.verify` takes it',
                ],
                'response' => [
                    'token' => 'the bearer token — store it in the keychain/keystore',
                    'abilities.*' => 'the Resident App\'s abilities, from the app matrix',
                    'reviewer' => 'boolean · always false here — the demonstration account is never deleted, so it is never restored',
                    'read_only' => 'boolean · false — a restored sign-in can write',
                    'account.id' => 'the account',
                    'account.status' => '`pending` or `active`',
                    'account.full_name' => 'or null',
                    'estate.id' => 'the estate',
                    'estate.name' => 'its name',
                    'estate.timezone' => self::ZONE,
                    'claim' => 'null, or the account\'s latest claim',
                    ...self::under('claim.', $claim),
                    'next' => '`claim_unit`, `await_approval` or `home` — the screen to open',
                    'restored' => 'boolean · true',
                    'fingerprint_sign_in' => 'boolean · as `auth.otp.verify`',
                ],
                'errors' => [
                    '404 estate_not_found' => 'No active estate by that id.',
                    '422 restore_ticket_invalid' => 'The ticket is more than fifteen minutes old, from another phone, or the account is no longer deleted. Sign in again: the sign-in offers the restore again while it can.',
                    '409 restore_office_only' => 'The person\'s own window closed while the screen was open. `error.details` is `account_deleted`\'s, with `can_restore` false: show "Contact the estate office".',
                    '429 rate_limited' => 'Ten requests a minute per address.',
                ],
                'offline' => 'Online only.',
            ],
            'auth.device_key' => [
                'method' => 'POST', 'uri' => 'auth/device-key', 'action' => [AuthController::class, 'signInWithDeviceKey'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200,
                'summary' => '"Log in with biometrics" (board 03; M49): the phone\'s own prompt has opened its secure store, and the key `me.device_key.store` gave this install signs it in with no emailed code — exactly as `auth.otp.verify` does, with its answer. The key works only with the install id it was issued to and only at its estate; it stops working when the switch is turned off, the phone is signed out from another, or the account is deleted, revoked or suspended. Signing in on a handset (`hardware_id`) ends that handset\'s earlier sign-ins of this account.',
                'request' => [
                    'estate' => 'string · required',
                    'key' => 'string · required · from the secure store',
                    'device_uid' => 'string · required · the SAME install id the key was issued to',
                    'platform' => 'string · required · `ios` or `android`',
                    'device_name' => 'string · optional',
                    'hardware_id' => 'string · optional · the handset\'s own id, as `me.device_key.store` takes it',
                ],
                'response' => [
                    'token' => 'the bearer token — store it in the keychain/keystore',
                    'abilities.*' => 'the Resident App\'s abilities, from the app matrix',
                    'reviewer' => 'boolean · as `auth.otp.verify`: true only for a reviewer\'s demonstration account',
                    'read_only' => 'boolean · false',
                    'account.id' => 'the account',
                    'account.status' => '`pending` or `active`',
                    'account.full_name' => 'or null',
                    'estate.id' => 'the estate',
                    'estate.name' => 'its name',
                    'estate.timezone' => self::ZONE,
                    'claim' => 'null, or the account\'s latest claim',
                    ...self::under('claim.', $claim),
                    'next' => '`claim_unit`, `await_approval` or `home` — the screen to open',
                    'restored' => 'boolean · false',
                    'fingerprint_sign_in' => 'boolean · true here: this install\'s key is live',
                ],
                'errors' => [
                    '401 device_key_invalid' => 'The key is not live: turned off, replaced, signed out from another phone, another install\'s, another estate\'s, or the account was deleted. Forget it on the phone and log in with the emailed code.',
                    '403 account_revoked' => 'The estate office ended this account\'s access. Every key of the account stops working.',
                    '403 account_suspended' => 'The estate withdrew this account\'s access. Every key of the account stops working.',
                    '404 estate_not_found' => 'No active estate by that id.',
                    '429 rate_limited' => 'Ten requests a minute.',
                ],
                'offline' => 'Online only.',
            ],
            'demo.enter' => [
                'method' => 'POST', 'uri' => 'demo/enter', 'action' => [AuthController::class, 'demo'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200,
                'summary' => '"Explore a demo community" — a READ-ONLY session inside the demonstration estate, with no code and no account. The token carries the Resident App\'s abilities with every `:write` removed, so a write is refused at the route\'s own ability middleware and not merely hidden on screen. Label the whole session as a demonstration: the estate holds invented data and nobody lives there. 404 when this platform has no demonstration community — offer the control only when `estates.index` lists one. Every install gets a session of its own: another phone entering the demo never signs this one out.',
                'request' => [
                    'device_uid' => 'string · optional · the same stable id for this install that `auth.otp.verify` takes — asking again with it replaces this install\'s demo session and nobody else\'s. Without it every entry is a session of its own, ended by the idle window.',
                ],
                'response' => [
                    'token' => 'the bearer token — store it in the keychain/keystore, exactly like a real one',
                    'abilities.*' => 'the Resident App\'s READ abilities only',
                    'reviewer' => 'always true',
                    'read_only' => 'always true — nothing in this session can write to any estate',
                    'account.id' => 'the demonstration account',
                    'account.status' => '`active`',
                    'account.full_name' => 'its name, which says it is a demonstration account',
                    'estate.id' => 'the demonstration estate',
                    'estate.name' => 'its name, which says it is a demonstration estate',
                    'estate.timezone' => self::ZONE,
                    'claim' => 'null, or the account\'s claim',
                    ...self::under('claim.', $claim),
                    'next' => '`home`',
                ],
                'errors' => [
                    '404 demo_unavailable' => 'This platform has no demonstration community.',
                    '429 rate_limited' => 'Ten requests a minute per address.',
                ],
                'offline' => 'Online only.',
            ],
            'auth.claim_unit' => [
                'method' => 'POST', 'uri' => 'auth/claim-unit', 'action' => [AuthController::class, 'claimUnit'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Claim a unit. The estate reviews it on its claims screen; once approved, the account is active on its next request. A pending claim is returned as it is (200). Board resident-app-04.',
                'request' => [
                    'full_name' => 'string · required',
                    'lot' => 'string · required · e.g. `47` or `Lot 47`',
                    'phase' => 'string · additional (M18C §3.3) · e.g. `Phase 2` — extra help finding the lot',
                    'phone' => 'string · required (M18C) · a phone number; stored as "+1 876 555 0123"',
                    'relationship' => 'string · required (M18C) · `owner`, `tenant`, `spouse`, `child`, `parent`, `relative`, `other`',
                ],
                'response' => [
                    'account.id' => 'the account',
                    'account.status' => '`pending`',
                    'account.full_name' => 'as claimed',
                    ...self::under('claim.', $claim),
                ],
                'errors' => ['409 already_linked' => 'The account is already active on a unit.'],
                'offline' => 'Online only.',
            ],
            'me.show' => [
                'method' => 'GET', 'uri' => 'me', 'action' => [MeController::class, 'show'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The account, its claim, and once linked its unit, household and register entry. Poll while pending. Board resident-app-10.',
                'request' => [],
                'response' => $profile,
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'me.update' => [
                'method' => 'PATCH', 'uri' => 'me', 'action' => [MeController::class, 'update'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Change the account\'s display name and the register\'s phone and email. The name on the register is the estate\'s.',
                'request' => [
                    'full_name' => 'string · sent only when changed',
                    'phone' => 'string · sent only when changed · never blank (M18C) · a phone number; stored as "+1 876 555 0123"',
                    'email' => 'string · sent only when changed · never blank',
                ],
                'response' => $profile,
                'errors' => [],
                'offline' => 'Queue.',
            ],
            'me.shown' => [
                'method' => 'POST', 'uri' => 'me/shown', 'action' => [MeController::class, 'markShown'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Record that this account has been shown something meant to appear once — the first-run tour, finished or skipped — so it is never shown again on any phone (M47 §3). Idempotent: the first time stands.',
                'request' => [
                    'key' => 'string · required · `tour`',
                ],
                'response' => [
                    'shown' => 'what this account has been shown once, as `account.shown` in `me.show`',
                    'shown.tour' => 'when the tour was first finished or skipped — UTC instant',
                ],
                'errors' => ['422 validation_failed' => 'Not something the app shows once.'],
                'offline' => 'Send when there is a connection; the phone remembers it meanwhile.',
            ],
            'me.delete_account' => [
                'method' => 'DELETE', 'uri' => 'me', 'action' => [AccountController::class, 'destroy'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Delete this account (both app stores require it in the app). DELETED IS A STATE, NOT AN ERASURE (M18H): at once, every sign-in on every phone ends, the e-pass and every unused visitor pass the person sent stop working at the gate, their future bookings are cancelled (a held deposit refunded), every push address goes, and their name leaves the estate\'s active register. Nothing is erased yet, and what belongs to the home — dues, the ledger, the gate log, incidents, ballots, the audit trail — never is. The person can restore it by signing in until `restore_until`; the estate office can until `permanent_on`, after which nothing of the person is left on it. An email says so at once. Never queued: it happens while the person watches, or not at all.',
                'request' => [
                    'confirm' => 'string · required · the word the app showed the person on the confirmation screen (`DELETE`), so a mis-tap cannot do this',
                ],
                'response' => [
                    'deleted' => 'true',
                    'reference' => 'the reference to quote if they ask us about it afterwards',
                    'deleted_at' => 'ISO 8601',
                    'restore_until' => 'the last estate-calendar date (`YYYY-MM-DD`) the person can restore it by signing in — say it on the screen that follows',
                    'permanent_on' => 'the estate-calendar date after which nothing of the person is left on it; until then the estate office can restore it',
                ],
                'errors' => [
                    '422 confirm_required' => 'The confirmation word was not sent.',
                    '409 demo_account_kept' => 'The App Store / Play reviewer\'s DEMONSTRATION account. It is never erased: deleting it would take the credentials in STORE_REVIEW_NOTES.md and the "Explore a demo community" door away from the next reviewer. Show the message as it is sent — it says what would have happened on a real account. False for every real resident.',
                ],
                'offline' => 'Online only, and never queued.',
            ],
            'me.photo' => [
                'method' => 'GET', 'uri' => 'me/photo', 'action' => [MeController::class, 'photo'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The account\'s profile photo — THE FILE ITSELF, not JSON. Only with this token: there is no public address for it. Ask with the `?v=` that `me.show` gives, so a phone may cache it until the photo changes.',
                'request' => ['v' => 'string · optional (query) · the version from `account.photo.url`'],
                'response' => [],
                'errors' => ['404 no_photo' => 'This account has no photo.'],
                'offline' => 'Needs a connection. A photo already fetched stays on the phone.',
            ],
            'me.data_export' => [
                'method' => 'GET', 'uri' => 'me/data-export', 'action' => [MeController::class, 'dataExport'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'api-data-export', 'status' => 200,
                'summary' => '"Download my data" (board resident-app-33, M50 §4.3): everything GeminiSecure holds about the signed-in person, as a PDF — THE FILE ITSELF, not JSON (`Content-Type: application/pdf`; `Content-Disposition` carries its name, "GeminiSecure-my-data-YYYY-MM-DD.pdf"). The account, its sign-ins and phones, the register entry, the household as the person\'s access reaches it, the passes they sent, the visitors they answered, their requests, bookings, payments, cards and AutoPay, their panic alerts and what was said on them, their claim, their invites, the notices they opened and the meetings they answered — and what the file leaves out, and how to ask for it. Only their own: nothing in the request names anybody. Audited (`resident_account.data_exported`), with how much went in and never what.',
                'request' => [],
                'response' => [],
                'errors' => ['429 too_many_requests' => 'Three a minute, twelve an hour, from one phone.'],
                'offline' => 'Online only. The phone hands the file to the share sheet and keeps no copy.',
            ],
            'support.contact' => [
                'method' => 'GET', 'uri' => 'support/contact', 'action' => [SupportContactController::class, 'show'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'How to reach Gemini Security on Help & support (board resident-app-39): its phone line as the console set it — null while none is, and then show NO "Call Gemini Security" at all (no placeholder, no button that rings nothing) — and its address.',
                'request' => [],
                'response' => [
                    'company' => '"Gemini Security"',
                    'phone' => 'the number to call, international form ("+18765550123") — null while the console has none',
                    'phone_display' => 'the same number as a person reads it ("+1 876 555 0123") — null while there is none',
                    'email' => 'the company\'s support address',
                ],
                'errors' => [],
                'offline' => 'Cached: the line as last read.',
            ],
            'support.chat.show' => [
                'method' => 'GET', 'uri' => 'support/chat', 'action' => [LiveChatController::class, 'show'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => '"Live chat" on Help & support (board resident-app-39, M50 §4.3): the account\'s one conversation with Gemini Security, oldest message first (the newest 200). Reading it marks every answer in it delivered. Gemini Security\'s control room answers from its console; `chat.changed` on the account\'s own channel says to read this again, and an answer is pushed (`support_reply`).',
                'request' => [],
                'response' => [
                    'id' => 'integer — the conversation, or null before the first message',
                    'open' => 'boolean — false once Gemini Security marked it resolved; the next message opens it again',
                    'messages.*.id' => 'integer — the message',
                    'messages.*.from' => '`you` or `support`',
                    'messages.*.name' => 'the first name of the member of Gemini Security\'s staff who answered — null on the account\'s own messages',
                    'messages.*.body' => 'what was written, as written',
                    'messages.*.at' => 'UTC instant it reached the server; show in the site\'s zone',
                    'messages.*.seen_at' => 'UTC instant the other side was shown it ("Delivered") — null until then ("Sent")',
                    'messages.*.client_key' => 'the phone\'s own id for its message, so a queued copy is matched to this one — null on an answer',
                    'typical_reply_minutes' => 'integer — how long Gemini Security typically takes to answer, measured over the last 30 days; null until there are enough answers to measure — then say who answers, never a speed',
                    'answered_by' => '"Gemini Security"',
                ],
                'errors' => [],
                'offline' => 'Cached: the conversation as last read, with what is waiting in the queue drawn under it.',
            ],
            'support.chat.messages.store' => [
                'method' => 'POST', 'uri' => 'support/chat/messages', 'action' => [LiveChatController::class, 'store'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Write to Gemini Security on Live chat (M50 §4.3). The first message starts the conversation; a message to a resolved one opens it again. It reaches the control room\'s Live chat tab and bell at once. Not for emergencies: the screen says so, and points to the panic button.',
                'request' => [
                    'body' => 'string · required · what to say, up to 1,000 characters kept (longer is refused)',
                    'client_key' => 'string · optional · the phone\'s own id for this message (a UUID). The same key again is the same message, never a second one — send it from the queue as often as the signal needs',
                ],
                'response' => [
                    'message.id' => 'integer — the message',
                    'message.from' => '`you`',
                    'message.body' => 'what was written',
                    'message.at' => 'UTC instant it reached the server',
                    'message.seen_at' => 'null — until Gemini Security is shown it',
                    'message.client_key' => 'the key it was sent with, or null',
                    'chat.id' => 'integer — the conversation',
                    'chat.open' => 'boolean — true: a message opens a resolved conversation again',
                ],
                'errors' => [
                    '409 demonstration_community' => 'The demonstration community: nobody reads its Live chat. Say the sentence given.',
                    '409 message_conflict' => 'The same message is being sent from the queue at the same moment; read the conversation again.',
                    '422 message_empty' => 'Nothing to send.',
                    '422 validation_failed' => 'More than 1,000 characters.',
                ],
                'offline' => 'Queue it with its `client_key` and send the moment there is a signal; draw it as "Waiting for a connection" until then and never drop it. A refusal is shown on the message with the sentence given.',
            ],
            'me.photo.store' => [
                'method' => 'POST', 'uri' => 'me/photo', 'action' => [MeController::class, 'storePhoto'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Set the account\'s profile photo (multipart, field `file`): a JPEG, PNG or HEIC of at most 5 MB, made small on the phone and written out afresh with no EXIF. It replaces the one before.',
                'request' => ['file' => 'file · required · JPEG, PNG or HEIC, at most 5 MB'],
                'response' => [
                    'photo' => 'the photo, as `account.photo` in `me.show`',
                    'photo.url' => 'a path under /api/v1 with the new version',
                    'photo.updated_at' => 'UTC instant',
                ],
                'errors' => ['422 validation_failed' => 'Not a picture, or larger than 5 MB.'],
                'offline' => 'Queue the file.',
            ],
            'me.photo.remove' => [
                'method' => 'DELETE', 'uri' => 'me/photo', 'action' => [MeController::class, 'removePhoto'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Remove the profile photo: the file is deleted and the account shows its initials again.',
                'request' => [],
                'response' => ['photo' => 'always null'],
                'errors' => [],
                'offline' => 'Queue.',
            ],
            'me.sessions' => [
                'method' => 'GET', 'uri' => 'me/sessions', 'action' => [SessionsController::class, 'index'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Every phone signed in to this account, THIS phone first — Security, "Signed-in phones". A sign-in past the idle window is listed too (`timed_out`): it must sign in again to be used, but it still gets this account\'s notifications until it is signed out here. The demonstration account lists only the phone asking.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the sign-in; send it to `me.sessions.end`',
                    'items.*.device' => 'what the phone called itself when it signed in ("SM-S938B", "iPhone 15"), or null for a sign-in made by an older app — then say "A phone"',
                    'items.*.platform' => '`ios`, `android`, or null',
                    'items.*.signed_in_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'items.*.last_used_at' => 'UTC instant; show in the site\'s zone (`timezone`) — its last request, or when it signed in',
                    'items.*.current' => 'true for the phone making this request',
                    'items.*.timed_out' => 'true when it has been idle past the window: it has to sign in again, and it still gets notifications until signed out',
                ],
                'errors' => [],
                'offline' => 'Serve the cache with its age; signing a phone out needs a connection.',
            ],
            'me.sessions.end' => [
                'method' => 'DELETE', 'uri' => 'me/sessions/{session}', 'action' => [SessionsController::class, 'destroy'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['session' => '[0-9]+'],
                'summary' => 'Sign one of this account\'s OTHER phones out: its sign-in is deleted, so its next request is refused, and it gets no more notifications. Not this phone — that is "Sign out of this phone" (`auth.sign_out`), which also clears what the app keeps on it.',
                'request' => [],
                'response' => [
                    'id' => 'the sign-in that was ended',
                    'signed_out' => 'always true',
                ],
                'errors' => [
                    '404 not_found' => 'No such sign-in on this account — already signed out, or never this account\'s.',
                    '409 this_phone' => 'The id is the phone making the request. Use "Sign out of this phone".',
                ],
                'offline' => 'Online only, and never queued: a phone is signed out while the person watches, or not at all.',
            ],
            'me.sessions.end_others' => [
                'method' => 'DELETE', 'uri' => 'me/sessions', 'action' => [SessionsController::class, 'destroyOthers'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Sign out EVERY other phone of this account at once (the client, 27 Sep) — each exactly as `me.sessions.end` signs one out, and each audited. This phone stays signed in. The demonstration account signs nobody out: its other sign-ins are other people\'s.',
                'request' => [],
                'response' => [
                    'signed_out' => 'how many phones were signed out — 0 when there were none',
                ],
                'errors' => [],
                'offline' => 'Online only, and never queued.',
            ],
            'me.device_key.store' => [
                'method' => 'POST', 'uri' => 'me/device-key', 'action' => [DeviceKeyController::class, 'store'],
                'apps' => $resident, 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => '"Face ID / fingerprint" turned ON on this phone (board 19; M49): a key for THIS install — the token\'s own install, never one the body names — returned ONCE. Keep it in the secure store behind the phone\'s own biometric prompt (expo-secure-store `requireAuthentication`); it outlives a sign-out, and `auth.device_key` signs the phone back in with it, no emailed code. Any earlier key of this install, or of this handset (`hardware_id`), stops working. Every access level may: a Helper signs in too. Not from a demonstration session.',
                'request' => [
                    'platform' => 'string · required · `ios` or `android`',
                    'device_name' => 'string · optional · what the phone calls itself, as `auth.otp.verify` takes it',
                    'hardware_id' => 'string · optional · the handset\'s own id (Android: the per-app device id; iOS: the vendor id) — stored as a hash',
                ],
                'response' => [
                    'key' => 'the key, in the clear, this once — never sent again, never shown',
                    'on' => 'always true',
                ],
                'errors' => [
                    '403 not_this_phone' => 'A demonstration session, or a token that is not a Resident App install\'s own.',
                ],
                'offline' => 'Online only, and never queued: the switch is on only when the server has said so.',
            ],
            'me.device_key.destroy' => [
                'method' => 'DELETE', 'uri' => 'me/device-key', 'action' => [DeviceKeyController::class, 'destroy'],
                'apps' => $resident, 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => '"Face ID / fingerprint" turned OFF on this phone (M49): this install\'s key stops working at once. Delete it from the secure store as well.',
                'request' => [],
                'response' => [
                    'on' => 'always false',
                    'revoked' => 'how many keys stopped working — 0 when there was none',
                ],
                'errors' => [],
                'offline' => 'Best effort when there is no connection: forget the key on the phone, and turn it off on the server the next time this phone is online.',
            ],
            'me.notifications.email.show' => [
                'method' => 'GET', 'uri' => 'me/notifications/email', 'action' => [NotificationEmailController::class, 'show'],
                'apps' => $resident, 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => '"Email" under By channel (board 20; M50 §4.2 #4): whether the news a push carries also reaches this account by EMAIL — to the address it signs in with. The server is the truth for this switch (it is the account\'s, not the phone\'s). Off until the resident turns it on.',
                'request' => [],
                'response' => [
                    'on' => 'boolean',
                    'address' => 'where the emails go — the address this account signs in with; null for an account that does not sign in by email',
                    'muted.*' => 'the categories switched off, so not emailed — the same names `push.register` takes',
                ],
                'errors' => [],
                'offline' => 'Read it when the screen opens; with no connection, show the last answer.',
            ],
            'me.notifications.email.update' => [
                'method' => 'PUT', 'uri' => 'me/notifications/email', 'action' => [NotificationEmailController::class, 'update'],
                'apps' => $resident, 'capability' => 'alerts', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => '"Email" switched on or off (M50 §4.2 #4). Send `muted` with it — and again whenever a category switch changes while Email is on — so an email never carries what this resident switched off. Who is emailed and when: every Resident App push this account is sent, once per record inside the push\'s coalescing window, except "somebody at the gate" (answered in the app within minutes). Emails follow whether or not a phone is registered for push, and quiet hours do not hold them.',
                'request' => [
                    'on' => 'boolean · required',
                    'muted' => 'array of strings · optional · the categories switched off in the app: `visitors`, `notices`, `maintenance`, `payments`, `meetings`, `voting`, `bookings`. Anything else is dropped. Left out: kept as it was',
                ],
                'response' => [
                    'on' => 'boolean — as saved',
                    'address' => 'where the emails go',
                    'muted.*' => 'the categories not emailed, as saved',
                ],
                'errors' => [],
                'offline' => 'Online only, and never queued: the switch shows what the server said.',
            ],
            'me.household' => [
                'method' => 'GET', 'uri' => 'me/household', 'action' => [MeController::class, 'household'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The home screen\'s household: members, vehicles, pets, emergency contacts, and any guard waiting on an answer at the gate. Boards resident-app-02, -09, -17. `pending_approvals` is empty for a person whose access level cannot answer the gate.',
                'request' => [],
                'response' => [
                    'household.id' => 'the household',
                    'household.name' => 'its name',
                    'can_manage_members' => 'boolean — true only for the household\'s PRIMARY resident, who alone may add or remove members',
                    'unit.id' => 'the unit',
                    'unit.reference' => 'reference',
                    'unit.phase' => 'phase',
                    ...self::under('members.*.', $member),
                    ...self::under('vehicles.*.', $vehicle),
                    'pets' => 'the household\'s pets, by name (M50 §5.3d) — drawn as pills beside the vehicles. Absent on a server older than this release',
                    ...self::under('pets.*.', $pet),
                    ...self::under('emergency_contacts.*.', $contact),
                    'pending_approvals.*.id' => 'a walk-up request to answer with `/gate/approval/{id}/respond`',
                    'pending_approvals.*.visitor_name' => 'who is at the gate',
                    'pending_approvals.*.purpose' => 'or null',
                    'pending_approvals.*.vehicle_plate' => 'or null',
                    'pending_approvals.*.post_name' => 'which gate',
                    'pending_approvals.*.guard_name' => 'the guard asking',
                    'pending_approvals.*.requested_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'pending_approvals.*.respond_by' => 'after this the estate\'s policy applies — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => [],
                'offline' => 'Serve the cache. Pending approvals are live only.',
            ],
            'members.index' => [
                'method' => 'GET', 'uri' => 'household-members', 'action' => [MeController::class, 'members'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s members on the register.',
                'request' => [],
                'response' => self::under('items.*.', $member),
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'members.store' => [
                'method' => 'POST', 'uri' => 'household-members', 'action' => [MeController::class, 'addMember'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Add a household member — the PRIMARY resident only. They are `pending` until the estate verifies them. With an email, they are INVITED to the Resident App at once, and `invite` says whether it went. When the invite cannot go — this address can\'t be invited from the app, or the day\'s limit — the member is still added and `invite` says so (`not_sent`, with the reason to show).',
                'request' => [
                    'full_name' => 'string · required',
                    'relationship' => 'string · required · `spouse`, `child`, `parent`, `relative`, `tenant`, `domestic_staff`, `other`',
                    'phone' => 'string · required (M18C: every field of a new member is required) · a phone number — spaces, dashes, a leading 1 or +1 accepted; stored as "+1 876 555 0123"',
                    'email' => 'string · required unless `no_email` is true, and refused when it is',
                    'no_email' => 'boolean · "This person has no email — alerts go to me instead" (M18C §3.2): a young child, an elderly relative. Records the primary resident as the one their alerts reach',
                    'invite' => 'boolean · optional · send them an invite to the Resident App now — true when this is left out (an app that predates it sends none)',
                    'access_level' => 'string · optional · `member`, `dependent`, `helper` — defaults to `member`',
                ],
                'response' => $member,
                'errors' => [
                    '403 not_primary' => 'Only the household\'s primary resident can add members.',
                    '409 member_exists' => 'A current member of this household has the same email, or the same name and phone (M54 §2). Nobody was added; show the message as it is sent.',
                    '409 access_levels_not_open' => 'An `access_level` other than `member` while levels are not open yet (`me.show.access.levels_open` is false). Nobody was added; show the message as it is sent.',
                ],
                'offline' => 'Queue. An add saved while offline is sent with `invite: false`, and the app offers Send invite once the member is on the server: an invite is an online-only write. A server also sends none for an add whose `X-Device-Time` is more than 15 minutes old (an older app\'s replay); `invite` then reads `not_sent` with the reason.',
            ],
            'members.invite' => [
                'method' => 'POST', 'uri' => 'household-members/{member}/invite', 'action' => [MeController::class, 'inviteMember'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['member' => '[0-9]+'],
                'summary' => 'Send — or RESEND — a pending member\'s invite to the Resident App, by email, now. The PRIMARY resident only. A resend waits 1, then 2, then 5 minutes after each send; `invite.resend_after` says when. Audited.',
                'request' => [
                    'access_level' => 'string · optional · `member`, `dependent`, `helper` — set this person\'s level as the invite goes; left out, their level is unchanged. Nothing changes when the invite is refused',
                ],
                'response' => $member,
                'errors' => [
                    '403 not_primary' => 'Only the household\'s primary resident can invite members.',
                    '404 not_found' => 'Not a member of this household — the same answer as an id nobody has.',
                    '409 invite_wait' => 'It was sent moments ago, or this address has had five invites today; the message says when it can go again. For the first, so does `invite.resend_after`.',
                    '409 invite_address_refused' => 'This address can\'t be invited from the app. Ask the estate office. (The reason is recorded for the office only; after five such refusals in a day, every invite this primary resident sends from the app gets this answer until the day is up.)',
                    '409 invite_not_pending' => 'They are already confirmed on the register; there is nothing to invite them to.',
                    '409 primary_is_member' => 'An `access_level` other than `member` for the primary resident, who always has Member access. Nothing was sent.',
                    '409 access_levels_not_open' => 'An `access_level` other than `member` while levels are not open yet (`me.show.access.levels_open` is false). Nothing was sent and nothing changed; show the message as it is sent.',
                    '422 invite_no_email' => 'They have no email address to send it to.',
                ],
                'offline' => 'Online only. Whether it went is the answer, and a send queued for later could not give it.',
            ],
            'members.invite_cancel' => [
                'method' => 'DELETE', 'uri' => 'household-members/{member}/invite', 'action' => [MeController::class, 'cancelInvite'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['member' => '[0-9]+'],
                'summary' => 'Cancel a pending member\'s invite. The PRIMARY resident only. Removing the member cancels it as well. Audited.',
                'request' => [],
                'response' => $member,
                'errors' => [
                    '403 not_primary' => 'Only the household\'s primary resident can cancel invites.',
                    '404 not_found' => 'Not a member of this household — the same answer as an id nobody has.',
                ],
                'offline' => 'Online only.',
            ],
            'members.level' => [
                'method' => 'PATCH', 'uri' => 'household-members/{member}/access-level', 'action' => [MeController::class, 'setMemberLevel'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['member' => '[0-9]+'],
                'summary' => 'Change a member\'s access level — Member, Dependent or Helper — the PRIMARY resident only. Takes effect on their next request; nobody is signed out. Audited.',
                'request' => [
                    'access_level' => 'string · required · `member`, `dependent`, `helper`',
                ],
                'response' => $member,
                'errors' => [
                    '403 not_primary' => 'Only the household\'s primary resident can change a member\'s access.',
                    '404 not_found' => 'Not a member of this household — the same answer as an id nobody has.',
                    '409 primary_is_member' => 'The primary resident always has Member access.',
                    '409 access_levels_not_open' => 'A level other than `member` while levels are not open yet (`me.show.access.levels_open` is false). Nothing changed; show the message as it is sent. Back to `member` is always allowed.',
                ],
                'offline' => 'Online only. It changes what a person may do at once; it is never saved to do later.',
            ],
            'members.access' => [
                'method' => 'PATCH', 'uri' => 'household-members/{member}/access-window', 'action' => [MeController::class, 'setMemberAccess'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['member' => '[0-9]+'],
                'summary' => 'A Helper\'s days, hours and end date, and "pause now" (8 Oct 2026) — the PRIMARY resident only. Their e-Pass works only inside them, at the gate and on their phone; paused, or past the end date, it is cancelled at once (every gate refuses it) and none is issued until resumed. Audited.',
                'request' => [
                    'days' => 'array of integers · required · the days it works, 1 (Monday) to 7 (Sunday), at least one',
                    'from' => 'string · required · "07:00", on the half hour',
                    'to' => 'string · required · "19:00", on the half hour; earlier than `from` is an overnight window',
                    'until' => 'string · optional · the last day it works, YYYY-MM-DD — leave it out for no end',
                    'paused' => 'boolean · required · true blocks them now',
                ],
                'response' => $member,
                'errors' => [
                    '403 not_primary' => 'Only the household\'s primary resident can change a helper\'s hours.',
                    '404 not_found' => 'Not a member of this household — the same answer as an id nobody has.',
                    '409 not_a_helper' => 'Days and hours are for a Helper.',
                    '422 hours_invalid' => 'Not times on the half hour, or the same time twice.',
                ],
                'offline' => 'Online only: blocking somebody is said to have happened only once it has.',
            ],
            'members.destroy' => [
                'method' => 'DELETE', 'uri' => 'household-members/{member}', 'action' => [MeController::class, 'removeMember'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['member' => '[0-9]+'],
                'summary' => 'Remove a member from the household — the PRIMARY resident only. Removed, not deleted: they leave every list at once and lose Resident App access; the record stays for the history that points at it. Audited.',
                'request' => [],
                'response' => [
                    'id' => 'the member removed',
                    'removed' => 'true',
                ],
                'errors' => [
                    '403 not_primary' => 'Only the household\'s primary resident can remove members.',
                    '404 not_found' => 'Not a member of this household — the same answer as an id nobody has.',
                    '409 primary_stays' => 'The primary resident cannot be removed in the app; the estate office hands a household over.',
                ],
                'offline' => 'Online only. Removing someone takes their access away at once; it is never queued to happen later.',
            ],
            'vehicles.index' => [
                'method' => 'GET', 'uri' => 'vehicles', 'action' => [MeController::class, 'vehicles'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s registered vehicles.',
                'request' => [],
                'response' => self::under('items.*.', $vehicle),
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'vehicles.store' => [
                'method' => 'POST', 'uri' => 'vehicles', 'action' => [MeController::class, 'addVehicle'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Register a vehicle. A plate already registered to the household is returned as it is (200).',
                'request' => [
                    'plate' => 'string · required · letters, digits, spaces, hyphens',
                    'make' => 'string · required',
                    'model' => 'string · required',
                    'colour' => 'string · required',
                ],
                'response' => $vehicle,
                'errors' => [],
                'offline' => 'Queue.',
            ],
            'contacts.index' => [
                'method' => 'GET', 'uri' => 'emergency-contacts', 'action' => [MeController::class, 'contacts'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Who to call for this household. Never shown to a guard.',
                'request' => [],
                'response' => self::under('items.*.', $contact),
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'contacts.store' => [
                'method' => 'POST', 'uri' => 'emergency-contacts', 'action' => [MeController::class, 'addContact'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Add an emergency contact. A number already on the household\'s list (compared as a diallable number) is returned as it is (200), with the existing row — never a second row.',
                'request' => [
                    'name' => 'string · required',
                    'relationship' => 'string · required (M18C) · in the resident\'s own words, e.g. "Sister"',
                    'phone' => 'string · required · a phone number — spaces, dashes, a leading 1 or +1, or another country\'s with its +; stored as "+1 876 555 0123"',
                    'email' => 'string · required (M18C) · a valid address — a panic emails the contact at once; kept onto an already-listed contact that had none',
                ],
                'response' => [
                    ...$contact,
                    'already_listed' => 'boolean — true when that number was already on the household\'s list and nothing was added; the existing row is returned. Absent on a server older than this release',
                ],
                'errors' => [],
                'offline' => 'Queue.',
            ],
            'contacts.update' => [
                'method' => 'PATCH', 'uri' => 'emergency-contacts/{contact}', 'action' => [MeController::class, 'updateContact'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['contact' => '[0-9]+'],
                'summary' => 'Change one of the household\'s emergency contacts (M12 §2.5) — above all, add the email a panic now writes to. The household\'s own rows only. A number already on the list for another contact is refused. Audited (fields changed, the address masked).',
                'request' => [
                    'name' => 'string · sent only when changed',
                    'relationship' => 'string · sent only when changed · never blank (M18C)',
                    'phone' => 'string · sent only when changed · never blank',
                    'email' => 'string · sent only when changed · a valid address, never blank',
                ],
                'response' => $contact,
                'errors' => [
                    '404 not_found' => 'Not one of this household\'s emergency contacts.',
                    '409 already_listed' => 'That number is already on the list for another contact.',
                ],
                'offline' => 'Queue.',
            ],
            'contacts.destroy' => [
                'method' => 'DELETE', 'uri' => 'emergency-contacts/{contact}', 'action' => [HouseholdRemovalsController::class, 'destroyContact'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['contact' => '[0-9]+'],
                'summary' => 'Remove one of the household\'s emergency contacts. The household\'s own rows only; the app confirms first. Audited (the relationship and a masked number, never the name).',
                'request' => [],
                'response' => [
                    'id' => 'the contact removed',
                    'removed' => 'true',
                ],
                'errors' => [
                    '404 not_found' => 'Not one of this household\'s emergency contacts — the same answer as an id nobody has.',
                ],
                'offline' => 'Online only. It changes who the estate and a panic can reach at once; it is never saved to do later.',
            ],
            'vehicles.destroy' => [
                'method' => 'DELETE', 'uri' => 'vehicles/{vehicle}', 'action' => [HouseholdRemovalsController::class, 'destroyVehicle'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['vehicle' => '[0-9]+'],
                'summary' => 'Remove one of the household\'s registered vehicles. The household\'s own rows only; the app confirms first. Audited. No gate decision reads these plates, so a removal changes nothing about who is admitted.',
                'request' => [],
                'response' => [
                    'id' => 'the vehicle removed',
                    'removed' => 'true',
                ],
                'errors' => [
                    '404 not_found' => 'Not one of this household\'s vehicles — the same answer as an id nobody has.',
                ],
                'offline' => 'Online only. A removal takes effect at once; it is never saved to do later.',
            ],
            'vehicles.update' => [
                'method' => 'PATCH', 'uri' => 'vehicles/{vehicle}', 'action' => [HouseholdItemsController::class, 'updateVehicle'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['vehicle' => '[0-9]+'],
                'summary' => 'Change one of the household\'s vehicles in place (M50 §5.3b) — its plate, make, model and colour, all four sent. The household\'s own rows only. A plate already on the list for ANOTHER vehicle is refused. Audited, before and after.',
                'request' => [
                    'plate' => 'string · required · letters, digits, spaces, hyphens; upper-cased',
                    'make' => 'string · required',
                    'model' => 'string · required',
                    'colour' => 'string · required',
                ],
                'response' => $vehicle,
                'errors' => [
                    '404 not_found' => 'Not one of this household\'s vehicles — the same answer as an id nobody has.',
                    '409 already_listed' => 'That plate is already on the list for another vehicle. Nothing changed.',
                ],
                'offline' => 'Queue.',
            ],
            'pets.index' => [
                'method' => 'GET', 'uri' => 'pets', 'action' => [HouseholdItemsController::class, 'pets'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s pets (M50 §5.3d), by name. Never read at the gate.',
                'request' => [],
                'response' => self::under('items.*.', $pet),
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'pets.store' => [
                'method' => 'POST', 'uri' => 'pets', 'action' => [HouseholdItemsController::class, 'addPet'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Add a pet: a name and a kind. The same pet again (the name in any capitals, and the kind) is returned as it is (200) — never a second row.',
                'request' => [
                    'name' => 'string · required · 40 at most',
                    'kind' => 'string · required · `dog`, `cat`, `bird`, `fish`, `rabbit` or `other`',
                ],
                'response' => [
                    ...$pet,
                    'already_listed' => 'boolean — true when that pet was already on the list and nothing was added',
                ],
                'errors' => [],
                'offline' => 'Queue.',
            ],
            'pets.update' => [
                'method' => 'PATCH', 'uri' => 'pets/{pet}', 'action' => [HouseholdItemsController::class, 'updatePet'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['pet' => '[0-9]+'],
                'summary' => 'Change one of the household\'s pets — its name and kind, both sent. The household\'s own rows only. Audited.',
                'request' => [
                    'name' => 'string · required · 40 at most',
                    'kind' => 'string · required · `dog`, `cat`, `bird`, `fish`, `rabbit` or `other`',
                ],
                'response' => $pet,
                'errors' => [
                    '404 not_found' => 'Not one of this household\'s pets — the same answer as an id nobody has.',
                ],
                'offline' => 'Queue.',
            ],
            'pets.destroy' => [
                'method' => 'DELETE', 'uri' => 'pets/{pet}', 'action' => [HouseholdItemsController::class, 'destroyPet'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['pet' => '[0-9]+'],
                'summary' => 'Remove one of the household\'s pets. The household\'s own rows only; the app confirms first. Audited.',
                'request' => [],
                'response' => [
                    'id' => 'the pet removed',
                    'removed' => 'true',
                ],
                'errors' => [
                    '404 not_found' => 'Not one of this household\'s pets — the same answer as an id nobody has.',
                ],
                'offline' => 'Online only. A removal takes effect at once; it is never saved to do later.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function residentPasses(): array
    {
        $resident = [AppMatrix::RESIDENT];
        $pass = [
            'id' => 'the pass — the id the pass endpoints take',
            'pass_id' => 'UUID, in the signed payload',
            'category' => '`single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass',
            'visitor_name' => 'who it is for',
            'visitor_phone' => 'or null',
            'purpose' => 'or null',
            'vehicle_plate' => 'or null',
            'no_vehicle' => 'boolean — the resident chose "no vehicle" (M18C §3.1): Walk in or Ride in. False on a pass issued before the choice existed',
            'arrival' => '`walk`, `drive` or `ride` — how the visitor arrives, as Invite visitor asks it (M50 §6.3) — or null on a pass issued before. Absent on a server older than this release',
            'valid_from' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'valid_to' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'single_use' => 'boolean',
            'status' => '`active`, `used`, `cancelled` or `expired`',
            'code' => 'the short code a visitor can read out, e.g. `PPV2-4471`',
            'token' => 'the QR payload: `base64url(payload).base64url(signature)`',
            'share_count' => 'times shared',
            'used_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'cancelled_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'last_admitted_at' => 'or null — the latest time a visitor was let in on this pass, recurring passes too ("Arrived 6:42 PM"); UTC instant; show in the site\'s zone (`timezone`)',
            'last_admitted_post' => 'or null — the gate that admission was at, by the post\'s own name: "Entered · Main Gate" (M47 §5.4). Null when nobody was let in, or the gate did not say',
            'last_admitted_by' => 'or null — the guard who let them in, by name: "by Guard Whyte" (M47 §5.4). Null when nobody was let in on it yet, or it was before the gate recorded who',
            'host' => 'the household member who issued the pass, by name — "Guest of <host>" (M47 §5.5); the visitor is THEIR guest, whoever is signed in',
        ];
        $passErrors = ['404 not_found' => 'Not a pass of this household\'s.'];

        return [
            'visitor_passes.index' => [
                'method' => 'GET', 'uri' => 'visitor-passes', 'action' => [PassesController::class, 'index'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s visitor passes from the last 30 days. Board resident-app-06.',
                'request' => ['status' => 'string · optional (query) · `active`, `used`, `cancelled` or `expired`'],
                'response' => self::under('items.*.', $pass),
                'errors' => [],
                'offline' => 'Serve the cache — the QR codes render offline.',
            ],
            'visitor_passes.store' => [
                'method' => 'POST', 'uri' => 'visitor-passes', 'action' => [PassesController::class, 'store'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Issue a signed visitor pass. At most 31 days long. Board resident-app-05.',
                'request' => [
                    'category' => 'string · required · `single`, `recurring`, `contractor` or `delivery`',
                    'visitor_name' => 'string · required',
                    'visitor_phone' => 'string · required (M18C) · a phone number — spaces, dashes, a leading 1 or +1 accepted; stored as "+1 876 555 0123"',
                    'purpose' => 'string · required (M12 §2.13) · 2–160 characters, e.g. "Family visit" — the guard sees it at the gate',
                    'vehicle_plate' => 'string · required unless `no_vehicle` is true, and refused when it is',
                    'no_vehicle' => 'boolean · "no vehicle" (M18C §3.1) — Walk in or Ride in: a plate, or this — never neither',
                    'arrival' => 'string · optional · `drive` with a plate; `walk` or `ride` with `no_vehicle` (M50 §6.3)',
                    'valid_from' => 'ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site\'s clock · required',
                    'valid_to' => 'ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site\'s clock · required · after `valid_from` · still ahead',
                ],
                'response' => $pass,
                'errors' => [
                    '409 access_restricted' => 'The household is restricted for this category. The wording is the gate\'s; the app shows nothing more.',
                    '422 pass_refused' => 'Longer than 31 days, a window that has already ended (a draft sent after its time), or otherwise refused; the message says why.',
                ],
                'offline' => 'Online only: a pass is signed by the server. The app keeps one written offline as a draft and sends it itself with a stable Idempotency-Key; a refusal is kept on the draft.',
            ],
            'visitor_passes.update' => [
                'method' => 'PATCH', 'uri' => 'visitor-passes/{pass}', 'action' => [PassesController::class, 'update'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['pass' => '[0-9]+'],
                'summary' => 'Change what is not signed: the visitor\'s name, number, purpose or plate. A different window or category is a new pass.',
                'request' => [
                    'visitor_name' => 'string · sent only when changed',
                    'visitor_phone' => 'string · sent only when changed · never blank (M18C)',
                    'purpose' => 'string · sent only when changed · never blank',
                    'vehicle_plate' => 'string · sent only when changed · a plate clears `no_vehicle` and makes it Drive in',
                    'no_vehicle' => 'boolean · sent only when changed · true clears the plate',
                    'arrival' => 'string · optional, with `no_vehicle` · `walk` or `ride` (M50 §6.3)',
                ],
                'response' => $pass,
                'errors' => [...$passErrors, '409 pass_not_active' => 'Used, cancelled or expired.', '422 validation_failed' => 'A signed field (`valid_from`, `valid_to`, `category`) was sent.'],
                'offline' => 'Queue.',
            ],
            'visitor_passes.cancel' => [
                'method' => 'POST', 'uri' => 'visitor-passes/{pass}/cancel', 'action' => [PassesController::class, 'cancel'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['pass' => '[0-9]+'],
                'summary' => 'Cancel a pass. Guards\' handsets learn of it at their next sync; online verification refuses it at once.',
                'request' => [],
                'response' => $pass,
                'errors' => [...$passErrors, '409 pass_already_used' => 'Nothing left to cancel.'],
                'offline' => 'Queue, and tell the resident the gate learns of it when both are online.',
            ],
            'visitor_passes.share' => [
                'method' => 'POST', 'uri' => 'visitor-passes/{pass}/share', 'action' => [PassesController::class, 'share'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['pass' => '[0-9]+'],
                'summary' => 'Count a share and get the message to send. The app renders the QR image from `token` and hands both to the OS share sheet.',
                'request' => ['channel' => 'string · optional · `whatsapp`, `sms`, `email` or `copy`'],
                'response' => [
                    'pass_id' => 'UUID',
                    'share_count' => 'after this share',
                    'code' => 'short code',
                    'token' => 'QR payload',
                    'message' => 'the invitation that goes as the picture\'s caption (M18F §4): greeted by pass type, the date once in the one form ("27 Sep 2026"), the times on the estate\'s clock with no clock named (M98), the code as *CODE* (bold in WhatsApp), and "Sent by" the host. Paragraphs are separated by a blank line',
                    'sms' => 'the same facts as words only, for a text message: plain GSM characters (no dashes or curly quotes), so it stays within two segments',
                ],
                'errors' => [...$passErrors, '409 pass_not_active' => 'Used, cancelled or expired.'],
                'offline' => 'Share from cache; send the count when online.',
            ],
            'epass.show' => [
                'method' => 'GET', 'uri' => 'e-pass', 'action' => [PassesController::class, 'epass'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The resident\'s own signed e-pass, HALF AN HOUR AT A TIME (M47 §6): what the phone shows is good for 30 minutes, so a screenshot of it is dead within the half hour, and `slots` is a day of them (48) to keep for a gate with no signal. `valid_from`, `valid_to` and `token` are the half hour this moment is in. The gate code stays the same for the day. Never restricted for a Member or a Dependent; a Helper\'s works only inside their days and hours (8 Oct 2026: `hours` says them), and not at all while their household has paused them or after their end date. Board resident-app-08.',
                'request' => [],
                'response' => [
                    ...$pass,
                    'slots' => 'the e-pass\'s half hours from the one this moment is in, up to a day of them (48), oldest first — show the one whose window holds now',
                    'slots.*.valid_from' => 'UTC instant',
                    'slots.*.valid_to' => 'UTC instant — thirty minutes after `valid_from`',
                    'slots.*.token' => 'the signed token for that half hour: draw it as the QR. A guard verifies it offline, in that half hour and no other',
                    'refresh_after' => 'collect the next day\'s half hours after this, whenever the app is open with a connection',
                    'hours' => 'a Helper\'s days and hours, in words to show on the e-Pass ("Your e-Pass works Mon–Sat, 7:00 AM – 7:00 PM.") — their `slots` are only those; null for a Member or a Dependent. Absent on a server older than this release',
                ],
                'errors' => [
                    '403 account_pending' => 'No register entry linked yet.',
                    '403 access_paused' => 'A Helper the household has paused. Show the sentence: it says who can turn it back on.',
                    '403 access_ended' => 'A Helper past the end date the household set. Show the sentence.',
                    '403 outside_hours' => 'A Helper with no half hour of their days and hours in the next day. Show the sentence: it says when it works.',
                ],
                'offline' => 'Show the half hour of `slots` whose window holds now; when none does, the phone has run out — connect once to collect more. A guard verifies each offline.',
            ],
            'approvals.respond' => [
                'method' => 'POST', 'uri' => 'gate/approval/{approval}/respond', 'action' => [PassesController::class, 'respond'],
                'apps' => $resident, 'capability' => 'approvals', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['approval' => '[0-9]+'],
                'summary' => 'Approve or deny a visitor a guard is holding at the gate. Board resident-app-07.',
                'request' => ['decision' => 'string · required · `approve` or `deny`'],
                'response' => [
                    'approval_id' => 'the request',
                    'status' => '`approved` or `denied`',
                    'visitor_name' => 'who',
                    'responded_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'visitors_held' => 'boolean — approved, but this household\'s visitors are held at the gate (the estate\'s restriction; its own yes does not lift it, D-213). Say so instead of "letting them in"; the Dues page says why',
                ],
                'errors' => [
                    '404 not_found' => 'Not a request for this household.',
                    '409 approval_expired' => 'The three minutes ran out.',
                    '409 approval_decided' => 'Already answered.',
                ],
                'offline' => 'Online only — a late answer is no answer.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function residentDues(): array
    {
        $resident = [AppMatrix::RESIDENT];
        $payment = [
            'id' => 'the payment',
            'receipt_no' => 'e.g. `PPV-R-04471`',
            'amount' => 'decimal string',
            'currency' => '`JMD`',
            'method' => '`bank`, `cash`, `cheque` or `card`',
            'reference' => 'the payer\'s reference, or null',
            'received_at' => 'A CALENDAR DATE, NOT AN INSTANT: the day the office says the money arrived, carried as midnight UTC (e.g. 2026-07-03T00:00:00+00:00). Read its YYYY-MM-DD and show that day — do NOT move it into the site\'s zone, which would draw the evening before. Only a value that carries a time of day is a moment',
            'status' => '`recorded` or `reversed`',
            'is_demonstration' => 'true when a TEST card paid it in the demonstration checkout (M12 §2.6): a real receipt and a lower balance, and no money moved — say "Demonstration payment" beside it',
        ];
        $household = ['404 not_found' => 'Not this account\'s household — the same answer as an id nobody has.'];

        // A saved TEST card (M50 §4.2 #3): what names it, never its number.
        $card = [
            'id' => 'the saved card',
            'brand' => '`visa`, `mastercard`, `amex` or `discover`',
            'brand_name' => '"Visa", "Mastercard", "American Express" or "Discover"',
            'last4' => 'its last four digits',
            'label' => '"Visa •••• 4242" — draw it as given',
            'expires' => '"MM/YY"',
            'holder' => 'the name on it, or null',
            'default' => 'boolean — the caller\'s default card',
            'expired' => 'boolean — past its month on the estate\'s calendar; it cannot pay',
            'demonstration' => 'boolean — always true while the checkout takes test cards only (D-179)',
        ];

        // The household's AutoPay (M50 §4.2 #3): what it will do, and what it last did.
        $autopay = [
            'available' => 'boolean — AutoPay can be on: the demonstration checkout is switched on',
            'reason' => 'why not, when `available` is false — say it as given; else null',
            'on' => 'boolean',
            'id' => 'the arrangement, for `autopay.destroy` — null when there is none',
            'limit' => 'the most it pays in one month, a decimal string — or null',
            'day' => 'integer — the day of the month it pays, 1–28 — or null',
            'card' => 'the saved card it pays with (as `cards.index` draws one) — or null',
            ...self::under('card.', $card),
            'next_charge_on' => 'A CALENDAR DATE (YYYY-MM-DD) on the estate\'s calendar: the next day it pays — null when off',
            'last_run_on' => 'A CALENDAR DATE: the day it last ran — or null',
            'last_outcome' => 'what its last run did, in a sentence to show as given ("Paid $12,400.00 with Visa •••• 4242 — receipt …", "Nothing was owed, so nothing was charged.") — or null',
            'defaults.limit' => 'the limit offered when turning it on: "15000.00" (the board\'s)',
            'defaults.day' => 'integer — the day offered when turning it on: 28 (the board\'s)',
        ];

        return [
            'invoices.index' => [
                'method' => 'GET', 'uri' => 'invoices', 'action' => [DuesController::class, 'invoices'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s charges, newest first, each with what is still owed on it (payments clear the oldest first). A charge the estate reversed was never owed and is not listed; the statement shows it and its reversal. Board resident-app-11.',
                'request' => [],
                'response' => [
                    'currency' => '`JMD`',
                    'items.*.id' => 'the charge — the id `/invoices/{invoice}/pay-intent` takes',
                    'items.*.reference' => 'e.g. `DUES-2026-09-L47`',
                    'items.*.type' => '`dues`, `special_assessment`, `fine` or `amenity`',
                    'items.*.period' => '`2026-09`, or null',
                    'items.*.description' => 'text',
                    'items.*.amount' => 'decimal string',
                    'items.*.outstanding' => 'decimal string',
                    'items.*.due_on' => 'YYYY-MM-DD',
                    'items.*.status' => '`paid`, `part_paid`, `overdue` or `due`',
                ],
                'errors' => [],
                'offline' => 'Serve the cache with its age; never show a cached balance as current.',
            ],
            'households.balance' => [
                'method' => 'GET', 'uri' => 'households/{household}/balance', 'action' => [DuesController::class, 'balance'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['household' => '[0-9]+'],
                'summary' => 'What the household owes, from the ledger. Board resident-app-11.',
                'request' => [],
                'response' => [
                    'household_id' => 'the household',
                    'unit' => 'reference',
                    'balance' => 'decimal string; negative is credit',
                    'currency' => '`JMD`',
                    'as_at' => 'YYYY-MM-DD',
                    'oldest_unpaid_due_on' => 'or null',
                    'days_overdue' => 'integer',
                    'on_payment_plan' => 'boolean',
                    'plan' => 'object or null · the active payment plan, in a resident\'s terms (M18D §3) — null when there is none',
                    'plan.reference' => 'the plan\'s reference',
                    'plan.instalment' => 'decimal string · the next instalment (else the first)',
                    'plan.instalments' => 'integer · how many were agreed',
                    'plan.made' => 'integer · how many a treasurer has accepted as met',
                    'plan.remaining' => 'integer · how many are still due',
                    'plan.next_due_on' => 'YYYY-MM-DD or null',
                    'plan.next_is_overdue' => 'boolean · the next instalment is past its date on the estate\'s calendar (not yet ruled missed)',
                    'plan.final_due_on' => 'YYYY-MM-DD or null',
                    'plan.agreed_on' => 'YYYY-MM-DD or null · the estate\'s calendar',
                    'plan.frequency' => '`Monthly`',
                    'pay_now' => 'object or null · WHAT TO PAY NOW, AND BY WHEN (M18D §2.2) — the one action on the dues page, the amount in its button. Null when nothing is owed',
                    'pay_now.amount' => 'decimal string · the plan\'s next instalment while a plan holds, else the whole balance',
                    'pay_now.due_on' => 'YYYY-MM-DD or null',
                    'pay_now.reason' => '`instalment`, `overdue` or `due`',
                    'access_restricted' => 'boolean — true while a visitor for this household would be held at the gate (the gate\'s own decision, payment plan included). Tell the resident in words; never work it out from `days_overdue`',
                ],
                'errors' => $household,
                'offline' => 'Serve the cache with its age.',
            ],
            'households.statement' => [
                'method' => 'GET', 'uri' => 'households/{household}/statement', 'action' => [DuesController::class, 'statement'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['household' => '[0-9]+'],
                'summary' => 'The running statement from the journal, newest first. Board resident-app-12.',
                'request' => ['limit' => 'integer · optional (query) · 1–200, default 50'],
                'response' => [
                    'unit' => 'reference',
                    'currency' => '`JMD`',
                    'closing_balance' => 'decimal string',
                    'items.*.date' => 'YYYY-MM-DD',
                    'items.*.description' => 'the line\'s memo',
                    'items.*.reference' => 'the journal entry',
                    'items.*.charge' => 'decimal string, or null',
                    'items.*.payment' => 'decimal string, or null',
                    'items.*.balance' => 'running, decimal string',
                ],
                'errors' => $household,
                'offline' => 'Serve the cache with its age.',
            ],
            'invoices.pay_intent' => [
                'method' => 'POST', 'uri' => 'invoices/{invoice}/pay-intent', 'action' => [DuesController::class, 'payIntent'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['invoice' => '[0-9]+'],
                'summary' => 'How to pay this charge: the amount, the reference to quote and the estate\'s own instructions for a bank transfer, cash or cheque — and, when the demonstration checkout is on (M12 §2.6), that it can be paid in the app with a TEST card (`payments.card`). Board resident-app-13.',
                'request' => [],
                'response' => [
                    'invoice_id' => 'the charge',
                    'online_payment_available' => 'boolean — true when the TEST-card checkout is on',
                    'card_mode' => '`demonstration` when it is on (every card payment is a demonstration: no money moves), or null',
                    'reason' => 'why, in a sentence',
                    'amount_due' => 'decimal string still owed on this charge',
                    'currency' => '`JMD`',
                    'quote_reference' => 'what to write on the transfer or cheque',
                    'instructions' => 'the estate\'s own payment instructions',
                    'estate' => 'the estate\'s name',
                ],
                'errors' => ['404 not_found' => 'Not a charge of this household\'s.'],
                'offline' => 'Serve the cached instructions.',
            ],
            'payments.card' => [
                'method' => 'POST', 'uri' => 'payments/card', 'action' => [DuesController::class, 'cardPayment'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Pay some or all of what the household owes with one of Stripe\'s TEST cards (M12 §2.6, the client\'s instruction overriding D-077) — a DEMONSTRATION payment: a real receipt from the estate\'s sequence and a lower balance, posted to 1090 Demonstration Card Payments and never to the bank; no money moves and no card is stored. The phone checks the card form itself and sends only which test card it was. A declined test card changes nothing.',
                'request' => [
                    'amount' => 'string · required · dollars and cents, e.g. `12400.00` — at most what is owed',
                    'brand' => 'string · required · `visa`, `mastercard`, `amex` or `discover`',
                    'last4' => 'string · required · the test card\'s last four digits — never the whole number',
                ],
                'response' => [
                    ...$payment,
                    'estate' => 'name',
                    'unit' => 'reference',
                    'household' => 'name',
                    'received_by_name' => 'null — the resident paid it in the app',
                    'entered_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'balance' => 'decimal string — what the household owes now, from the ledger',
                ],
                'errors' => [
                    '422 card_declined' => 'The test card is one Stripe declines (declined, insufficient funds, expired, incorrect security code): Stripe\'s own sentence and what to do next. Nothing was charged.',
                    '409 payment_refused' => 'Card payments are off, it is not a test card, nothing is owed, or the amount is more than is owed — the sentence says which.',
                ],
                'offline' => 'Online only — a payment is never queued.',
            ],
            'payments.intents.store' => [
                'method' => 'POST', 'uri' => 'payments/intents', 'action' => [CardIntentsController::class, 'store'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Start paying some or all of what the household owes by card, through the estate\'s card gateway (M16 §2.2). OFF until a card provider is connected — then refused with `payment_refused` and the sentence the rest of the app gives: dues are paid by bank transfer, cash or cheque. The answer names the provider\'s own page to send the resident to (`next_action_url`); the card is typed there and never reaches this platform. The Idempotency-Key is the payment\'s own key: a retry or a double tap finds the one payment it already started. Nothing is paid until the provider\'s signed webhook says so — read `payments.intents.show`, never the return from the provider\'s page.',
                'request' => [
                    'amount' => 'string · required · dollars and cents, e.g. `12400.00` — at most what is owed',
                ],
                'response' => [
                    'reference' => 'string — the payment\'s reference, e.g. `PI-3F9A0C12B7E4`',
                    'status' => 'string — `created`, `authorised`, `captured`, `settled`, `declined` or `refunded`',
                    'amount' => 'decimal string',
                    'currency' => 'JMD',
                    'next_action_url' => 'string|null — the provider\'s hosted card page to open',
                    'decline_reason' => 'string|null — a sentence to show, when declined',
                    'receipt_no' => 'string|null — once captured',
                    'refunded' => 'decimal string',
                ],
                'errors' => [
                    '409 payment_refused' => 'Card payments are not connected, nothing is owed, the amount is more than is owed, or this key was used for a different payment — the sentence says which.',
                ],
                'offline' => 'Online only — a payment is never queued.',
            ],
            'payments.intents.show' => [
                'method' => 'GET', 'uri' => 'payments/intents/{reference}', 'action' => [CardIntentsController::class, 'show'],
                // Loose on purpose: the route must RESOLVE for any id, so a token that may not reach it is refused at the
                // ability (403) like every other route, never at routing (404). The controller checks the reference.
                'where' => ['reference' => '[A-Za-z0-9-]{1,24}'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Where one of the household\'s card payments stands. Only the provider\'s signed webhook moves it, so this — not the phone\'s return from the provider\'s page — is what says a payment went through (M16 §2.2).',
                'request' => [],
                'response' => [
                    'reference' => 'string',
                    'status' => 'string — `created`, `authorised`, `captured`, `settled`, `declined` or `refunded`',
                    'amount' => 'decimal string',
                    'currency' => 'JMD',
                    'next_action_url' => 'string|null',
                    'decline_reason' => 'string|null',
                    'receipt_no' => 'string|null',
                    'refunded' => 'decimal string',
                ],
                'errors' => [
                    '404 not_found' => 'No card payment of the household\'s with that reference.',
                ],
                'offline' => 'Online only.',
            ],
            'payments.index' => [
                'method' => 'GET', 'uri' => 'payments', 'action' => [DuesController::class, 'payments'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Payments recorded for the household, newest first. Board resident-app-14.',
                'request' => [],
                'response' => ['currency' => '`JMD`', ...self::under('items.*.', $payment)],
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'payments.receipt' => [
                'method' => 'GET', 'uri' => 'payments/{payment}/receipt', 'action' => [DuesController::class, 'receipt'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['payment' => '[0-9]+'],
                'summary' => 'One receipt, as the estate issued it.',
                'request' => [],
                'response' => [
                    ...$payment,
                    'estate' => 'name',
                    'unit' => 'reference',
                    'household' => 'name',
                    'received_by_name' => 'who took it, or null',
                    'entered_at' => 'when it was keyed — UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => ['404 not_found' => 'Not a payment of this household\'s.'],
                'offline' => 'Serve the cache.',
            ],
            'autopay.show' => [
                'method' => 'GET', 'uri' => 'autopay', 'action' => [DuesController::class, 'autopayShow'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s AutoPay as it stands (boards resident-app-10, -40; M50 §4.2 #3): on or off, its limit, its day, its card, the next day it pays, and what its last run did. `available` false while the demonstration checkout is off (then it cannot be turned on, and an arrangement made before does not charge).',
                'request' => [],
                'response' => $autopay,
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'autopay.store' => [
                'method' => 'POST', 'uri' => 'autopay', 'action' => [DuesController::class, 'autopay'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'AutoPay turned on, or its terms changed (M50 §4.2 #3, §6.6): on `day` (1–28) `dues:autopay` pays what the household owes, up to `limit`, with the saved `card`, through the same demonstration checkout as Pay — a real receipt, posted to 1090, no money moved. One per household: on again changes it. Only while the demonstration checkout is on (`DemoCardPayments::enabled`).',
                'request' => [
                    'card' => 'integer · required · one of the caller\'s own saved cards (`cards.index`)',
                    'limit' => 'string · optional · the most it pays in one month, in dollars — default "15000.00" (the board\'s); 100 to 1,000,000',
                    'day' => 'integer · optional · the day of the month, 1–28 — default 28 (the board\'s)',
                ],
                'response' => $autopay,
                'errors' => [
                    '404 not_found' => 'Not one of the caller\'s saved cards.',
                    '409 autopay_unavailable' => 'Card payments are off for the estate, the card has expired, or the limit or day is outside its range. Say the sentence given.',
                ],
                'offline' => 'Online only: the switch shows what the server said.',
            ],
            'autopay.destroy' => [
                'method' => 'DELETE', 'uri' => 'autopay/{autopay}', 'action' => [DuesController::class, 'cancelAutopay'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['autopay' => '[0-9]+'],
                'summary' => 'AutoPay turned off (M50 §4.2 #3). Nothing more is paid by it; what it paid stays paid.',
                'request' => [],
                'response' => $autopay,
                'errors' => ['404 not_found' => 'Not this household\'s AutoPay.'],
                'offline' => 'Online only.',
            ],
            'cards.index' => [
                'method' => 'GET', 'uri' => 'me/cards', 'action' => [CardsController::class, 'index'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => '"Saved payment methods" (board resident-app-40) and the saved card on Pay (board resident-app-10): the caller\'s OWN saved cards in this home, the default first. Test cards only (D-179): the brand, the last four, the expiry and the name — never a number. `available` false while the demonstration checkout is off.',
                'request' => [],
                'response' => ['available' => 'boolean — cards can be saved and paid with', ...self::under('items.*.', $card)],
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'cards.store' => [
                'method' => 'POST', 'uri' => 'me/cards', 'action' => [CardsController::class, 'store'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => '"Save card" (board resident-app-35; M50 §4.2 #3): one of Stripe\'s TEST cards, by what names it — the phone checked the form against the test list and wiped any real number, and sends only the brand, last four, expiry and name. The first card saved, or one sent with `default`, is the default. The same card again is the same card.',
                'request' => [
                    'brand' => 'string · required · `visa`, `mastercard`, `amex` or `discover`',
                    'last4' => 'string · required · four digits',
                    'exp_month' => 'integer · required · 1–12',
                    'exp_year' => 'integer · required · four digits, or two',
                    'holder' => 'string · optional · the name on the card',
                    'default' => 'boolean · optional · make it the default ("Set as default payment method")',
                ],
                'response' => $card,
                'errors' => ['422 card_refused' => 'Not a test card, expired, or card payments are off for the estate. Say the sentence given.'],
                'offline' => 'Online only, and never queued: nothing about a card waits on a phone.',
            ],
            'cards.default' => [
                'method' => 'POST', 'uri' => 'me/cards/{card}/default', 'action' => [CardsController::class, 'makeDefault'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['card' => '[0-9]+'],
                'summary' => 'Make one of the caller\'s saved cards their default (M50 §4.2 #3).',
                'request' => [],
                'response' => $card,
                'errors' => ['404 not_found' => 'Not one of the caller\'s saved cards.'],
                'offline' => 'Online only.',
            ],
            'cards.destroy' => [
                'method' => 'DELETE', 'uri' => 'me/cards/{card}', 'action' => [CardsController::class, 'destroy'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['card' => '[0-9]+'],
                'summary' => 'Remove one of the caller\'s saved cards (M50 §4.2 #3). Their next card becomes the default; an AutoPay that used it is turned off, and says why.',
                'request' => [],
                'response' => ['removed' => 'always true'],
                'errors' => ['404 not_found' => 'Not one of the caller\'s saved cards.'],
                'offline' => 'Online only.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function residentCommunity(): array
    {
        $resident = [AppMatrix::RESIDENT];
        $ticket = [
            'id' => 'the ticket',
            'number' => 'e.g. 1042',
            'title' => 'title',
            'category' => 'or null',
            'location' => 'where',
            'description' => 'or null',
            'access_permitted' => 'boolean, or null — true where the household said a technician may be let in with nobody home; null where the request did not say, which is not the same as no',
            'preferred_time' => 'or null — when the household would like the visit, in their own words. A note to the office, never a booking',
            'priority' => '`low`, `medium` or `high`',
            'status' => '`submitted`, `acknowledged`, `assigned`, `in_progress`, `completed`, `verified` or `cancelled`',
            'status_label' => 'the console\'s own word for it: "Submitted", "Under review", "Assigned", "In progress", "Completed", "Closed" or "Cancelled"',
            'reported_at' => 'the SLA runs from here — UTC instant; show in the site\'s zone (`timezone`)',
            'technician_name' => 'or null',
            'eta_starts_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'eta_ends_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'resolution' => 'or null',
            'closed_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'media_count' => 'attachments',
            'vendor' => 'or null — the company the estate gave the job to (M47 §11a)',
            'vendor.name' => '"Island Electric Services"',
            'vendor.phone' => 'or null — the number the card\'s call button rings: the technician\'s, else the company\'s',
            'timeline.*.event' => '`reported`, `acknowledged`, `assigned`, `started`, `resolved`, `note` (a note the office — or the household, `tickets.comment` — added), …',
            'timeline.*.status' => 'status after the event, or null',
            'timeline.*.note' => 'or null',
            'timeline.*.occurred_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'timeline.*.by' => 'or null — who did it (M47 §11.4, §12.5); null for the system, or an entry that names nobody',
            'timeline.*.by.kind' => '`staff`, `resident` or `vendor`',
            'timeline.*.by.name' => '"Renae Cross"',
            'timeline.*.by.role' => 'or null — a member of staff\'s role: "Property Manager", "Dispatcher"',
        ];
        $header = [
            'id' => 'the ballot',
            'year' => 'year',
            'title' => 'title',
            'kind' => '`election` or `resolution`',
            'question' => 'for a resolution, or null',
            'stage' => 'machine stage',
            'stage_label' => 'e.g. "Voting open"',
            'opens_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'closes_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
        ];
        $booking = [
            'id' => 'the booking',
            'reference' => 'e.g. `BKG-2026-09-0004`',
            'amenity.id' => 'amenity',
            'amenity.name' => 'its name',
            'starts_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'ends_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
            'guests' => 'or null',
            'status' => '`pending`, `confirmed`, `declined`, `cancelled`, `completed` or `expired` (a request the estate did not decide before its time came: nobody declined it; M53 §2)',
            'booking_fee' => 'decimal string',
            'deposit' => 'decimal string',
            'deposit_state' => '`none`, `awaiting`, `held`, `refunded` or `forfeited` (it always moves on: a held deposit is refunded when the booking is cancelled in the app or declined, or three days after it ends with no claim made; the estate may keep all or part of one, with a reason; an unpaid one on a booking that is over becomes none)',
            'deposit_kept' => 'decimal string, or null — what the estate kept of a forfeited deposit; the rest of the deposit went back to the household',
            'currency' => '`JMD`',
            'cancellable_until' => 'the app may cancel until this moment — UTC instant; show in the site\'s zone (`timezone`)',
            'declined_reason' => 'or null',
            'cancelled_at' => 'or null — UTC instant; show in the site\'s zone (`timezone`)',
            'fee_charged' => 'boolean — the fee has been raised as a charge on the unit',
            'fee_outstanding' => 'decimal string — what is still owed on the fee (the whole fee when not yet charged; 0.00 with no fee, or on a declined, cancelled or expired booking never charged)',
            'fee_to_clear' => 'decimal string — what a card payment for the fee takes: the fee and every older charge still owing, since payments clear the oldest charge first (0.00 when nothing is owed on the fee)',
            'fee_paid' => 'boolean — a fee was charged and nothing is owed on it',
            'deposit_demonstration' => 'boolean — a TEST card paid the deposit (M12 §2.6): held, refunded or kept like any, and no money moved',
            'confirmation_code' => 'or null — "GZ-2609-A4" (M47 §10): a booking paid for in the app (`bookings.book`), confirmed at once; a short code a guard can read aloud',
            'gate_token' => 'or null — the guests\' pass for the booking\'s hours, signed like any visitor pass: draw it as the QR. Only while the booking is confirmed and still to come',
            'paid_with' => 'or null — "Visa •••• 4417": the (test) card it was paid with, where the deposit goes back to. Never more than the brand and last four',
        ];

        return [
            'tickets.index' => [
                'method' => 'GET', 'uri' => 'tickets', 'action' => [CommunityController::class, 'tickets'],
                'apps' => $resident, 'capability' => 'tickets', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s maintenance tickets with their timelines, newest first — at most 100 an answer, and `next_before` reaches the older ones, so the whole history can be read. Board resident-app-16.',
                'request' => ['before' => 'string · optional (query) · the previous answer\'s `next_before`; leave it out for the newest'],
                'response' => [...self::under('items.*.', $ticket), 'next_before' => 'send this as `before` for the next older page, or null when nothing is older'],
                'errors' => ['422 cursor_invalid' => '`before` is not a `next_before` this list handed out.'],
                'offline' => 'Serve the cache.',
            ],
            'tickets.store' => [
                'method' => 'POST', 'uri' => 'tickets', 'action' => [CommunityController::class, 'reportTicket'],
                'apps' => $resident, 'capability' => 'tickets', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Report a problem, or file a security report (`category` `security`). It lands in the estate\'s maintenance queue — with community staff, source resident, whatever else is sent. Boards resident-app-15 and resident-app-14.',
                'request' => [
                    'title' => 'string · required',
                    'category' => 'string · required (M18C) · `plumbing`, `electrical`, `security`, `landscaping`, `roads`, `water`, `waste`, `amenity`, `other`',
                    'description' => 'string · required (M18C) · what is wrong, in the resident\'s words',
                    'location' => 'string · optional · defaults to the unit',
                    'priority' => 'string · optional · `low`, `medium` (default) or `high`',
                    'access_permitted' => 'boolean · optional · the household will let a technician in with nobody home. Left out means the request did not say, which is not the same as false',
                    'preferred_time' => 'string · optional · when the household would like the visit, in their own words (at most 80). A note to the office, never a booking',
                    'latitude' => 'number · optional · with `longitude`; only where the resident agreed to attach where they are. Omit when location is refused or unavailable — a report is never blocked for want of it',
                    'longitude' => 'number · optional',
                    'accuracy_m' => 'integer · optional · the fix\'s accuracy in metres',
                    'happening_now' => 'boolean · optional · the resident says it is happening as they write. The guards ON DUTY at the site are told, and it appears in their `sync.pull`; the ticket still belongs to community staff with source resident — notification is not ownership',
                ],
                'response' => $ticket,
                'errors' => [],
                'offline' => 'Queue; attach photos after it syncs.',
            ],
            'tickets.media' => [
                'method' => 'POST', 'uri' => 'tickets/{ticket}/media', 'action' => [CommunityController::class, 'ticketMedia'],
                'apps' => $resident, 'capability' => 'tickets', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201, 'where' => ['ticket' => '[0-9]+'],
                'summary' => 'Attach a photo or video (multipart, field `file`). Stored privately, hashed on arrival.',
                'request' => ['file' => 'file · required · JPEG, PNG, HEIC, MP4 or MOV, at most 50 MB'],
                'response' => [
                    'media_id' => 'the attachment',
                    'ticket_id' => 'the ticket',
                    'filename' => 'as uploaded',
                    'content_type' => 'as detected',
                    'bytes' => 'size',
                    'sha256' => 'hash',
                ],
                'errors' => ['404 not_found' => 'Not a ticket of this household\'s.'],
                'offline' => 'Queue the file.',
            ],
            'tickets.confirm_fixed' => [
                'method' => 'POST', 'uri' => 'tickets/{ticket}/fixed', 'action' => [CommunityController::class, 'confirmFixed'],
                'apps' => $resident, 'capability' => 'tickets', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['ticket' => '[0-9]+'],
                'summary' => 'The household confirms the job the estate office marked completed is fixed: the ticket becomes `verified`, the resident named on that stage. Only on the household\'s own request, and only once it is `completed`.',
                'request' => [],
                'response' => $ticket,
                'errors' => [
                    '404 not_found' => 'Not a ticket of this household\'s.',
                    '409 not_completed' => 'The estate office has not marked it finished, so there is nothing to confirm.',
                ],
                'offline' => 'Queue.',
            ],
            'tickets.comment' => [
                'method' => 'POST', 'uri' => 'tickets/{ticket}/comments', 'action' => [CommunityController::class, 'comment'],
                'apps' => $resident, 'capability' => 'tickets', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201, 'where' => ['ticket' => '[0-9]+'],
                'summary' => 'A message to the estate office about one request ("Add a comment", M47 §11b): it goes on the ticket\'s history as a note, in the resident\'s words and under their name, where the office works the job. Any request of the household\'s, open or closed.',
                'request' => ['body' => 'string · required · at most 500 · the message'],
                'response' => $ticket,
                'errors' => ['404 not_found' => 'Not a ticket of this household\'s.'],
                'offline' => 'Queue.',
            ],
            'tickets.still_a_problem' => [
                'method' => 'POST', 'uri' => 'tickets/{ticket}/reopen', 'action' => [CommunityController::class, 'stillAProblem'],
                'apps' => $resident, 'capability' => 'tickets', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['ticket' => '[0-9]+'],
                'summary' => 'The household says a job marked completed is still a problem: the ticket goes back into the estate\'s queue with what is still wrong, the resident named on the reopening. The first closure stays in the history, and the deadline still runs from the original report.',
                'request' => ['reason' => 'string · required · 3–500 · what is still wrong, for the office'],
                'response' => $ticket,
                'errors' => [
                    '404 not_found' => 'Not a ticket of this household\'s.',
                    '409 not_completed' => 'It is still open with the office, so there is nothing to reopen.',
                ],
                'offline' => 'Queue.',
            ],
            'notices.index' => [
                'method' => 'GET', 'uri' => 'notices', 'action' => [CommunityController::class, 'notices'],
                'apps' => $resident, 'capability' => 'notices', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Published notices for the whole estate and the household\'s phase, newest first — at most 100 an answer, and `next_before` reaches the older ones, so the whole history can be read. Board resident-app-17.',
                'request' => ['before' => 'string · optional (query) · the previous answer\'s `next_before`; leave it out for the newest'],
                'response' => [
                    'items.*.id' => 'the notice',
                    'items.*.kind' => '`general` or `urgent`',
                    'items.*.title' => 'title',
                    'items.*.body' => 'text',
                    'items.*.author_name' => 'who posted it',
                    'items.*.posted_as_role' => 'e.g. "Secretary", or null',
                    'items.*.audience' => '"Whole estate" or the phase',
                    'items.*.published_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'items.*.read_at' => 'when this resident read it, or null — UTC instant; show in the site\'s zone (`timezone`)',
                    'items.*.seen_households' => 'how many of the households it is for have had somebody open it — the board\'s "seen by 212 of 450 households"; the console\'s seen bar, counted in households',
                    'items.*.audience_households' => 'how many households it is for — the whole estate or its phase, as they stand today',
                    'items.*.attachments.*.id' => 'the attachment — fetch it from `notices.attachment`',
                    'items.*.attachments.*.name' => 'its file name, e.g. "Gate2_Contractor_Notice.pdf"',
                    'items.*.attachments.*.content_type' => 'e.g. "application/pdf"',
                    'items.*.attachments.*.bytes' => 'integer — its size in bytes',
                    'unread' => 'integer — how many of the notices IN THIS ANSWER this resident has not read; never a figure for the whole history',
                    'next_before' => 'send this as `before` for the next older page, or null when nothing is older',
                ],
                'errors' => ['422 cursor_invalid' => '`before` is not a `next_before` this list handed out.'],
                'offline' => 'Serve the cache; queue reads.',
            ],
            'notices.attachment' => [
                'method' => 'GET', 'uri' => 'notices/{notice}/attachments/{attachment}', 'action' => [CommunityController::class, 'noticeAttachment'],
                'apps' => $resident, 'capability' => 'notices', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['notice' => '[0-9]+', 'attachment' => '[0-9]+'],
                'summary' => 'One file the office put with a notice — THE FILE ITSELF, not JSON (`Content-Type` is the file\'s, `Content-Disposition` carries its name). Only for a notice published to this household, and only with this token: there is no public address for it. Board resident-app-36.',
                'request' => [],
                'response' => [],
                'errors' => ['404 not_found' => 'Not a notice for this household, not an attachment of that notice, or not ready — the three are deliberately the same.'],
                'offline' => 'Needs a connection. A file already fetched stays on the phone.',
            ],
            'notices.read' => [
                'method' => 'POST', 'uri' => 'notices/{notice}/read', 'action' => [CommunityController::class, 'readNotice'],
                'apps' => $resident, 'capability' => 'notices', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['notice' => '[0-9]+'],
                'summary' => 'Mark a notice read — board 30\'s "seen" count. Idempotent.',
                'request' => [],
                'response' => [
                    'notice_id' => 'the notice',
                    'read_at' => 'the first read — UTC instant; show in the site\'s zone (`timezone`)',
                    'seen_households' => 'how many of the households it is for have had somebody open it, this read included',
                    'audience_households' => 'how many households it is for',
                ],
                'errors' => ['404 not_found' => 'Not a notice for this household.'],
                'offline' => 'Queue.',
            ],
            'meetings.index' => [
                'method' => 'GET', 'uri' => 'meetings', 'action' => [CommunityController::class, 'meetings'],
                'apps' => $resident, 'capability' => 'meetings', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'General and phase meetings, newest first — everything ahead, then the past — with agendas and the household\'s RSVP. At most 50 an answer, and `next_before` reaches the older ones, so the whole history can be read. Board resident-app-18.',
                'request' => ['before' => 'string · optional (query) · the previous answer\'s `next_before`; leave it out for the newest'],
                'response' => [
                    'next_before' => 'send this as `before` for the next older page, or null when nothing is older',
                    'items.*.id' => 'the meeting',
                    'items.*.type' => '`agm`, `egm` or `phase`',
                    'items.*.title' => 'title',
                    'items.*.starts_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'items.*.held_as' => '`in_person`, `zoom`, `google_meet`, `teams` or `other` — say the mode by name rather than inferring it from the link',
                    'items.*.platform' => 'or null — the platform by name for an online meeting: "Zoom", "Google Meet", "Microsoft Teams", or for `other` the name the estate typed ("Webex"), else "Other online". Null in person',
                    'items.*.place' => 'the one line to show: the venue for an in-person meeting, the mode for an online one, and both when it is hybrid. Composed on the server so the console, the notice and this app cannot word it three ways.',
                    'items.*.venue' => 'or null — present on an in-person meeting, and on a hybrid one as well',
                    'items.*.virtual_link' => 'or null — the joining link, http or https only. Null whenever `held_as` is `in_person`, even if one was set before the meeting moved offline.',
                    'items.*.status' => '`scheduled`, `held`, `postponed` or `cancelled` — `held` once a published meeting is more than six hours past its start (M21 §1.4); `postponed` is told, then moved with no new date yet (M56 §2.3). A meeting postponed to a new date arrives as `scheduled` at that date, with `my_rsvp` reset (M94 §3.3)',
                    'items.*.date_to_be_set' => 'boolean. True while the meeting is postponed with no new date: show "New date to be set" and never `starts_at`, which is still the day it was first called for. False otherwise',
                    'items.*.reason' => 'or null — why it was postponed or cancelled, in the office\'s words (M56 §1.2)',
                    'items.*.description' => 'or null — what the meeting is about',
                    'items.*.recording_enabled' => 'boolean — tell the resident before they join',
                    'items.*.agenda.*.start_time' => 'HH:MM:SS, or null — a TIME OF DAY on the site\'s clock, as the office typed it; not an instant, never converted',
                    'items.*.agenda.*.text' => 'item',
                    'items.*.minutes_available' => 'boolean',
                    'items.*.my_rsvp' => '`attending`, `apologies`, `not_attending` or null',
                ],
                'errors' => ['422 cursor_invalid' => '`before` is not a `next_before` this list handed out.'],
                'offline' => 'Serve the cache.',
            ],
            'meetings.minutes' => [
                'method' => 'GET', 'uri' => 'meetings/{meeting}/minutes', 'action' => [CommunityController::class, 'minutes'],
                'apps' => $resident, 'capability' => 'meetings', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['meeting' => '[0-9]+'],
                'summary' => 'A meeting\'s minutes, once the estate office has published them (M21 §1.4) — the text as published, when, and who recorded it. Nothing about who attended.',
                'request' => [],
                'response' => [
                    'meeting_id' => 'the meeting',
                    'title' => 'the meeting\'s title',
                    'held_on' => 'YYYY-MM-DD on the site\'s clock',
                    'body' => 'the minutes, as the office wrote them — plain text with line breaks',
                    'recorded_by' => 'who recorded them, or null',
                    'published_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                ],
                'errors' => ['404 not_found' => 'No published minutes for a meeting this household can see.'],
                'offline' => 'Serve the cache.',
            ],
            'meetings.rsvp' => [
                'method' => 'POST', 'uri' => 'meetings/{meeting}/rsvp', 'action' => [CommunityController::class, 'rsvp'],
                'apps' => $resident, 'capability' => 'meetings', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['meeting' => '[0-9]+'],
                'summary' => 'Say whether the household will attend. Not attendance — quorum is counted from the register taken at the meeting.',
                'request' => ['response' => 'string · required · `attending`, `apologies` or `not_attending`'],
                'response' => [
                    'meeting_id' => 'the meeting',
                    'response' => 'as recorded',
                    'responded_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'households_attending' => 'households that said they will attend',
                ],
                'errors' => ['404 not_found' => 'Not a meeting for this household.', '409 meeting_closed' => 'Started, held, postponed or cancelled.'],
                'offline' => 'Queue.',
            ],
            'elections.index' => [
                'method' => 'GET', 'uri' => 'elections', 'action' => [ElectionsController::class, 'index'],
                'apps' => $resident, 'capability' => 'elections', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Elections and resolutions past draft, with the paper and whether the household has voted — never how. Board resident-app-19.',
                'request' => [],
                'response' => [
                    ...self::under('items.*.', $header),
                    'items.*.voting_open' => 'boolean',
                    'items.*.has_voted' => 'boolean — turnout, not choice',
                    'items.*.results_published' => 'boolean',
                    'items.*.positions.*.id' => 'a position',
                    'items.*.positions.*.name' => 'e.g. "Phase 2 Representative"',
                    'items.*.positions.*.seat_count' => 'how many to mark at most',
                    'items.*.positions.*.options.*.id' => 'an option id to send',
                    'items.*.positions.*.options.*.label' => 'the candidate',
                    'items.*.options.*.id' => 'a resolution\'s option id',
                    'items.*.options.*.label' => 'e.g. "For"',
                ],
                'errors' => [],
                'offline' => 'Serve the cache. Voting is online only.',
            ],
            'elections.eligibility' => [
                'method' => 'GET', 'uri' => 'elections/{ballot}/eligibility', 'action' => [ElectionsController::class, 'eligibility'],
                'apps' => $resident, 'capability' => 'elections', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['ballot' => '[0-9]+'],
                'summary' => 'Whether the household may vote, under the estate\'s rules. Board resident-app-20.',
                'request' => [],
                'response' => [
                    'ballot_id' => 'the ballot',
                    'eligible' => 'boolean',
                    'reason' => 'e.g. "arrears >90 days", or null',
                    'checked_on' => 'YYYY-MM-DD',
                    'voting_open' => 'boolean',
                    'has_voted' => 'boolean',
                    'voted_on' => 'YYYY-MM-DD the household\'s paper went in, or null — a date, never a time, and nothing about the paper',
                    'why' => 'why this person holds the vote — the household, the lot, that they are a Member, and that one paper is cast for the whole household (M21 §1.3), or null when the household is not eligible',
                ],
                'errors' => ['404 not_found' => 'No such election.'],
                'offline' => 'Online only.',
            ],
            'elections.ballot' => [
                'method' => 'POST', 'uri' => 'elections/{ballot}/ballot', 'action' => [ElectionsController::class, 'cast'],
                'apps' => $resident, 'capability' => 'elections', 'access' => AppMatrix::WRITE, 'write' => true, 'opaque' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201, 'where' => ['ballot' => '[0-9]+'],
                'summary' => 'Cast the household\'s paper. One per household. The answer names no mark, option or receipt, and the idempotency record keeps no trace of the body. Board resident-app-21.',
                'request' => ['option_ids' => 'array of integers · required · across every position, at most each position\'s seat count'],
                'response' => [
                    'ballot_id' => 'the ballot',
                    'voted' => 'true',
                    'voted_on' => 'YYYY-MM-DD — a date, never a time',
                ],
                'errors' => [
                    '403 not_eligible' => 'The estate\'s rules exclude the household; the reason is given.',
                    '409 poll_closed' => 'Not open.',
                    '409 already_voted' => 'The household\'s paper is in.',
                    '422 paper_invalid' => 'Blank, over-marked, or an option from another ballot.',
                ],
                'offline' => 'Online only. Never queue a vote.',
            ],
            'elections.results' => [
                'method' => 'GET', 'uri' => 'elections/{ballot}/results', 'action' => [ElectionsController::class, 'results'],
                'apps' => $resident, 'capability' => 'elections', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['ballot' => '[0-9]+'],
                'summary' => 'Certified, published results: counts per option, turnout and quorum.',
                'request' => [],
                'response' => [
                    ...$header,
                    'certified_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'published_at' => 'UTC instant; show in the site\'s zone (`timezone`)',
                    'outcome_statement' => 'or null',
                    'turnout.cast' => 'households',
                    'turnout.eligible' => 'households',
                    'turnout.percent' => 'integer',
                    'quorum.required' => 'households',
                    'quorum.met' => 'boolean',
                    'positions.*.id' => 'position',
                    'positions.*.name' => 'name',
                    'positions.*.seat_count' => 'seats',
                    'positions.*.options.*.id' => 'option',
                    'positions.*.options.*.label' => 'candidate',
                    'positions.*.options.*.votes' => 'marks',
                    'options.*.id' => 'option',
                    'options.*.label' => 'label',
                    'options.*.votes' => 'marks',
                ],
                'errors' => ['404 not_found' => 'No such election.', '409 results_not_published' => 'Not yet certified and published.'],
                'offline' => 'Serve the cache once fetched.',
            ],
            'amenities.index' => [
                'method' => 'GET', 'uri' => 'amenities', 'action' => [BookingsController::class, 'amenities'],
                'apps' => $resident, 'capability' => 'bookings', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Bookable amenities and their terms, and whether the household may book. Board resident-app-22.',
                'request' => [],
                'response' => [
                    'may_book' => 'false while the household is restricted from bookings',
                    'card_payments' => 'boolean — a booking can be paid for in the app with a TEST card, which confirms it at once (`bookings.book`, M47 §10); false: a booking is a request the estate approves (`bookings.store`)',
                    'items.*.id' => 'the amenity',
                    'items.*.name' => 'e.g. "Club House"',
                    'items.*.icon_key' => 'icon',
                    'items.*.capacity' => 'guests',
                    'items.*.opens_at' => 'HH:MM:SS — a TIME OF DAY on the site\'s clock, not an instant; never converted',
                    'items.*.closes_at' => 'HH:MM:SS — a TIME OF DAY on the site\'s clock (`24:00:00` is the end of the day), not an instant; never converted',
                    'items.*.booking_fee' => 'decimal string',
                    'items.*.deposit' => 'decimal string',
                    'items.*.currency' => '`JMD`',
                    'items.*.cancellation_hours' => 'or null',
                    'items.*.booking_window_days' => 'or null',
                    'items.*.requires_approval' => 'boolean — a booking of it is a request the estate office approves (`bookings.store`), its fee charged then; it is never paid for in the app, whatever `card_payments` says (M53 §2). False: as `card_payments` says',
                    // When the diary is already taken, earliest first (M21 §1.5): each pending or confirmed booking and each
                    // period the estate closed, from now to the end of the booking window (at most 90 days) — the hours
                    // only, never whose booking or its reference.
                    'items.*.taken.*.starts_at' => 'UTC instant; a period already booked or closed begins — show in the site\'s zone',
                    'items.*.taken.*.ends_at' => 'UTC instant; and ends',
                    'items.*.description' => 'or null — "garden setting": what the place is, after its capacity (M47 §8.2)',
                    'items.*.place' => 'or null — "Phase 2": where it is on the estate (M47 §9.4)',
                    'items.*.hourly_rate' => 'decimal string, or null — "$2,000/hr": the fee is this times the hours booked (`bookings.quote` works it out). Null: one flat `booking_fee` per booking',
                    'items.*.slot_minutes' => 'integer, or null — the blocks the day is booked in, from opening: 120 is "9–11 AM · 11–1 PM · …". Null: one hour',
                    'items.*.rules_summary' => 'or null — "noise cut-off at 9:00 PM and no glassware on the lawn", after "I\'ve read and accept the … house rules, including"',
                    'items.*.rules' => 'or null — the house rules themselves, which the rules link opens',
                ],
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'bookings.quote' => [
                'method' => 'GET', 'uri' => 'amenities/{amenity}/quote', 'action' => [BookingsController::class, 'quote'],
                'apps' => $resident, 'capability' => 'bookings', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['amenity' => '[0-9]+'],
                'summary' => 'What booking these hours would cost, worked out by the server (M47 §9.5) — the phone shows the figures and adds none of them up: the fee (the hourly rate times the hours, or the flat fee), anything the household already owes that a payment clears first (payments clear the oldest charge first, D-179), money paid ahead that pays the fee first, the deposit, and the total the card would be charged.',
                'request' => [
                    'starts_at' => 'ISO 8601 WITH ITS OFFSET · required',
                    'ends_at' => 'ISO 8601 WITH ITS OFFSET · required · after starts_at',
                ],
                'response' => [
                    'minutes' => 'integer — how long, "2 hrs"',
                    'hourly_rate' => 'decimal string, or null for a flat fee — "× $2,000"',
                    'fee' => 'decimal string — "Gazebo rate — 2 hrs × $2,000 … $4,000"',
                    'owed_before' => 'decimal string — what the household owes from before, cleared first by the payment (0.00 for most)',
                    'credit' => 'decimal string — money paid ahead, which pays for the fee first (0.00 for most)',
                    'deposit' => 'decimal string — "Refundable damage deposit"',
                    'total' => 'decimal string — "Total due today", and what "Confirm & pay" charges',
                    'currency' => '`JMD`',
                ],
                'errors' => ['404 not_found' => 'No such amenity, or it is not open for booking.'],
                'offline' => 'Online only — figures for now, never a saved copy.',
            ],
            'bookings.book' => [
                'method' => 'POST', 'uri' => 'bookings/book', 'action' => [BookingsController::class, 'book'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Book these hours AND pay for them with one of Stripe\'s TEST cards — CONFIRMED when it returns (M47 §10, the user\'s "confirmed on payment"). All or nothing: the booking, the estate\'s yes, the fee raised and paid with a real (demonstration) receipt, the deposit held, a confirmation code, and the guests\' pass for its hours (`gate_token`). A declined card, a refusal, or a total that changed since it was shown makes nothing. The estate\'s own checks all stand: hours, the diary, the arrears rule. Only while `card_payments`; otherwise a booking is a request (`bookings.store`).',
                'request' => [
                    'amenity_id' => 'integer · required',
                    'starts_at' => 'ISO 8601 WITH ITS OFFSET · required · in the future, within the booking window',
                    'ends_at' => 'ISO 8601 WITH ITS OFFSET · required',
                    'brand' => 'string · required · `visa`, `mastercard`, `amex` or `discover`',
                    'last4' => 'string · required · the test card\'s last four digits — never the whole number',
                    'total' => 'decimal string · required · the `bookings.quote` total the phone showed — a different one now is refused (409), never taken',
                    'rules_accepted' => 'boolean · true when the house rules box was ticked — required where the amenity has `rules`, and ignored where it has none',
                ],
                'response' => [
                    ...$booking,
                    'receipt' => 'the fee\'s receipt, or null when nothing was owed on it',
                    'receipt.id' => 'the payment — `/payments/{payment}/receipt` shows it',
                    'receipt.receipt_no' => 'e.g. `PPV-R-04471`',
                    'receipt.amount' => 'decimal string',
                    'receipt.reference' => 'e.g. "Demonstration card payment · Visa •••• 4242"',
                    'receipt.is_demonstration' => 'always true',
                ],
                'errors' => [
                    '404 not_found' => 'No such amenity.',
                    '422 card_declined' => 'The test card is one Stripe declines: Stripe\'s own sentence and what to do next. Nothing was made.',
                    '422 booking_refused' => 'Outside hours, the slot is taken, bookings are closed to the household, or too far ahead — the estate\'s own sentence; nothing was made.',
                    '409 payment_refused' => 'Card payments are off, not a test card, or what the booking costs changed since it was shown — the sentence says which. Nothing was charged.',
                    '409 approval_required' => 'The amenity\'s bookings wait for the estate office (`requires_approval`): send a request (`bookings.store`). Nothing was made or charged.',
                ],
                'offline' => 'Online only — a payment is never queued, and a slot is first come.',
            ],
            'bookings.index' => [
                'method' => 'GET', 'uri' => 'bookings', 'action' => [BookingsController::class, 'index'],
                'apps' => $resident, 'capability' => 'bookings', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s bookings, each with its fee and deposit and whether each is paid. Board resident-app-23.',
                'request' => [],
                'response' => [
                    'card_payments' => 'boolean — a booking\'s fee and deposit can be paid in the app with a TEST card (`bookings.pay_card`)',
                    ...self::under('items.*.', $booking),
                ],
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'bookings.pay_card' => [
                'method' => 'POST', 'uri' => 'bookings/{booking}/pay/card', 'action' => [BookingsController::class, 'payCard'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['booking' => '[0-9]+'],
                'summary' => 'Pay a booking\'s FEE or DEPOSIT with one of Stripe\'s TEST cards (M12 §2.6, §2.14) — a demonstration payment: posted to 1090, never the bank; no money moves. The phone checks the card form itself and sends only which test card it was. The fee is raised as a charge first if the office has not raised it (approved bookings only) and paid with a real receipt — for `fee_to_clear`, the fee and every older charge still owing, since payments clear the oldest charge first; the deposit is held. A declined test card changes nothing.',
                'request' => [
                    'part' => 'string · required · `fee` or `deposit`',
                    'brand' => 'string · required · `visa`, `mastercard`, `amex` or `discover`',
                    'last4' => 'string · required · the test card\'s last four digits — never the whole number',
                    'amount' => 'decimal string · optional · for the fee, the `fee_to_clear` the phone showed — a different figure is refused (409), never taken',
                ],
                'response' => [
                    ...self::under('booking.', $booking),
                    'receipt' => 'the fee\'s receipt, or null for a deposit',
                    'receipt.id' => 'the payment — `/payments/{payment}/receipt` shows it',
                    'receipt.receipt_no' => 'e.g. `PPV-R-04471`',
                    'receipt.amount' => 'decimal string',
                    'receipt.reference' => 'e.g. "Demonstration card payment · Visa •••• 4242"',
                    'receipt.is_demonstration' => 'always true',
                ],
                'errors' => [
                    '404 not_found' => 'Not a booking of this household\'s.',
                    '422 card_declined' => 'The test card is one Stripe declines: Stripe\'s own sentence and what to do next. Nothing was charged.',
                    '409 payment_refused' => 'Card payments are off, not a test card, no fee or deposit, not approved yet, already paid, or what the fee takes has changed since it was shown — the sentence says which.',
                ],
                'offline' => 'Online only — a payment is never queued.',
            ],
            'bookings.store' => [
                'method' => 'POST', 'uri' => 'bookings', 'action' => [BookingsController::class, 'store'],
                'apps' => $resident, 'capability' => 'bookings', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Request a booking. It is `pending` and holds the slot until the estate decides.',
                'request' => [
                    'amenity_id' => 'integer · required',
                    'starts_at' => 'ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site\'s clock · required · in the future, within the booking window',
                    'ends_at' => 'ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site\'s clock · required',
                    'guests' => 'integer · optional (M47 §9d: the board asks no guests) · checked against the capacity when sent',
                    'notes' => 'string · additional (M18C §3.3) · a note to the estate office',
                ],
                'response' => $booking,
                'errors' => ['404 not_found' => 'No such amenity.', '422 booking_refused' => 'Outside hours, over capacity, the slot is taken, or bookings are closed to the household; the estate\'s own sentence — except a taken slot, which is said in the household\'s terms: the hours already booked and what to do, never the other booking\'s reference (M21 §1.5).'],
                'offline' => 'Online only — a slot is first come.',
            ],
            'bookings.cancel' => [
                'method' => 'POST', 'uri' => 'bookings/{booking}/cancel', 'action' => [BookingsController::class, 'cancel'],
                'apps' => $resident, 'capability' => 'bookings', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['booking' => '[0-9]+'],
                'summary' => 'Cancel within the booking\'s cancellation terms. A deposit already held is refunded in the same act (the answer says `refunded`); one never paid stops being awaited (`none`).',
                'request' => [],
                'response' => $booking,
                'errors' => ['404 not_found' => 'Not a booking of this household\'s.', '409 cancellation_refused' => 'Inside the cancellation window, already started, or not cancellable.'],
                'offline' => 'Queue.',
            ],
        ];
    }
}
