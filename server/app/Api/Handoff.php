<?php

declare(strict_types=1);

namespace App\Api;

use App\Providers\RateLimitServiceProvider;
use App\Services\Passes\OfflinePassVerifier;
use App\Services\Passes\PassSigningKeys;
use App\Services\Passes\PassToken;
use App\Services\ResidentApp\ResidentSignIn;
use ReflectionClass;

/**
 * MOBILE_HANDOFF.md, written from the catalogue (13 C4).
 *
 * The prose that no entry can hold — how a token is got, how a pass is verified
 * with no signal, how the queue syncs — is written here once; every endpoint
 * table, the ability grid, the error list and the rate limits are read from
 * `Catalogue`, `AppMatrix` and the limiter's own constants. `api:handoff` writes
 * the file and `MobileHandoffTest` fails when the file on disk is not what this
 * renders, so the document cannot describe an API that no longer exists.
 */
final class Handoff
{
    public static function render(): string
    {
        $endpoints = Catalogue::endpoints();

        return implode("\n", [
            self::preamble(),
            self::abilities(),
            self::limits(),
            self::index($endpoints),
            self::endpoints($endpoints),
            self::closing(),
        ]);
    }

    private static function preamble(): string
    {
        $skew = Catalogue::CLOCK_SKEW_SECONDS;
        $abandoned = intdiv(IdempotencyStore::ABANDONED_AFTER_SECONDS, 60);
        $fields = implode(' · ', PassToken::FIELDS);
        $tolerance = OfflinePassVerifier::CLOCK_TOLERANCE_SECONDS;
        $grace = PassSigningKeys::RETIRED_KEY_GRACE_DAYS;
        $ttl = ResidentSignIn::TTL_MINUTES;
        $tries = ResidentSignIn::MAX_ATTEMPTS;

        return <<<MD
        # Mobile handoff — the Guard App and Resident App API

        > **Generated.** `php artisan api:handoff` writes this file from `App\Api\Catalogue`, `App\Api\AppMatrix`
        > and the rate limiter; `MobileHandoffTest` fails when the two disagree. Do not edit it by hand —
        > change the catalogue entry and regenerate.

        Everything below exists, is routed from the same catalogue this was written from, and is exercised on
        every test run: `GuardApiContractTest` walks every Guard App route, `ResidentApiContractTest` every
        Resident App route, each response held to the keys its table lists. A key not in a table is a test
        failure, not a surprise.

        ---

        ## 1. Conventions

        | | |
        |---|---|
        | Base URL | `https://{platform host}/api/v1` |
        | Format | JSON in and out. Send `Accept: application/json`. Multipart only for the two media uploads. |
        | Authentication | `Authorization: Bearer {token}` on every endpoint except the four sign-in endpoints |
        | Writes | `Idempotency-Key` and `X-Device-Time` headers — see §3 |
        | Times | **One rule, everywhere (M4 §3.1).** Every time in a response is a UTC instant — `2026-09-17T14:41:02+00:00`. Show it in the site's IANA zone, which the same response names as `timezone` (or `site.timezone` / `estate.timezone`); never the handset's zone, and never a fixed offset. Sending a time: an ISO 8601 string WITH its offset or `Z` is honoured as the moment it names; one without is read on the site's clock. Dates are `YYYY-MM-DD` and are calendar dates, not instants. |
        | Money | Decimal strings, `"38450.00"`, never numbers. Currency `JMD`. |
        | Ids | Integers unless named `pass_id` (a UUID) |
        | Lists | Bounded, not paginated: most return the latest 100, and the ones that take a range say so. |
        | Tokens | Do not expire. A `401` means the token was revoked — a rebind, a new sign-in on that install, or the office. Enrol or sign in again. |
        | Unknown keys | Ignore response keys the app does not know; new keys are added to this document before they ship. |
        | Errors | `{"error": {"code": "snake_case", "message": "A sentence to show."}}` — §5 |

        ### The rule that shapes the Guard App

        **No endpoint a guard's token reaches returns a household's money.** A household in arrears reaches a
        handset as `access_restricted: true` and an amber verdict, and nothing else — no balance, no bucket, no
        wording that implies one. The one money a guard's handset reads is their **own** payslip. The contract
        tests scan every guard response for money-named keys and currency figures. Build the Guard App assuming
        the figure is unavailable; it is the product, not an oversight.

        ### The rule that shapes the Resident App

        **One household, the token's.** Every dues, pass, ticket and booking endpoint is keyed on the account's
        own unit; an id belonging to another household answers `404 not_found`, exactly as an id nobody has.

        ---

        ## 2. Getting a token

        ### Guard App — enrolment by code

        1. Gemini's office issues a one-time code for the guard: `php artisan device:enrolment-code GS-1041`
           (hashed at rest, 24 hours, once).
        2. The app generates an **Ed25519 key pair** on the device and keeps the secret half in the secure enclave.
        3. `POST /devices/enrol` with the code, a stable install UID, the platform and the public key.
           - A guard with no handset (or this same install): `201`, `status: enrolled`, and the token — shown once.
           - A guard already bound to a different handset: `202`, `status: pending_approval`, a
             `rebind_request_id` and a `claim_secret`. A supervisor approves on the guard's profile; the app polls
             `POST /devices/enrol/{rebind}/collect` and receives the token once. The old handset's token stops
             working at that moment, and the approval is recorded on the guard's shift.
        4. Store the token in the keychain/keystore. One guard, one working handset.

        ### Resident App — sign-in by code, then a unit claim

        1. `POST /auth/otp/request` with the estate, `email` or `sms`, the address, and `intent`: `sign_in` from
           Log in, `sign_up` from Create account. A sign-in at an address the register does not hold answers
           `422 email_not_found` and no code is made; stay on the sign-in screen. Otherwise the answer is identical
           whether or not an account exists. A code lasts {$ttl} minutes and {$tries} tries.
        2. `POST /auth/otp/verify` with the code and the install. The response carries the token and `next`:
           - `claim_unit` — a new account is **pending**. `POST /auth/claim-unit`.
           - `await_approval` — the estate is reviewing the claim. Poll `GET /me`.
           - `home` — the account is active.
        3. A pending token reaches `GET /me` and `POST /auth/claim-unit` and nothing else (`403 account_pending`).
           When the estate approves the claim, the account is active on its next request — no new sign-in.
        4. Text messages need an SMS provider the platform does not have yet: `sms` answers
           `503 sms_unavailable` on every server until one is connected, and whenever a provider refuses the
           code. Offer email. Never tell a resident a code was texted unless the answer was `202`.

        ---

        ## 3. Writes: idempotency and the device clock

        **`Idempotency-Key`** — required on every write marked *write* below (the first seven endpoints accept it
        without requiring it). Choose it when the act happens, store it with the queued act, and reuse it on
        every retry until the server answers. The first answer is stored against this token and key, and every
        retry gets it back byte for byte with `Idempotent-Replayed: true`; nothing happens twice.

        | Same key and… | Answer |
        |---|---|
        | the same request | the stored response, replayed |
        | a different request | `422 idempotency_key_reused` — a bug in the app |
        | the first attempt still running | `409 request_in_progress` — retry with the same key |
        | the first attempt died without answering, {$abandoned} minutes ago or more | `409 idempotent_answer_missing` — final: move past it and tell the person to check whether it went through. A panic or duress is run again instead, and answered with its alert |
        | the first attempt failed with a 5xx | nothing was stored — the retry is a real attempt |

        The ballot is the one exception to "a different request": its record keeps a hash of the method and path
        only, never the paper, so a stored hash cannot be tried against the options to learn a vote. A different
        paper under the same key is answered with the first answer.

        **`X-Device-Time`** — the handset's own clock at the moment the act happened (for a queued act, when it
        was captured, not when it was sent). Required on writes. The server never corrects it: every successful
        write answers `server_time`, `device_time` and `clock_skewed`: true when the device time is more than
        {$skew} seconds AHEAD of the server's. A device time in the past is an act queued with no signal and is not
        flagged, since no queue can put an act in the future. Show a guard whose clock is wrong that it is wrong.

        ---

        ## 4. Offline

        ### Verifying a visitor pass with no signal

        A pass in a QR code is `base64url(payload) . "." . base64url(signature)`. The payload is canonical JSON
        with exactly these fields in this order: {$fields}. It names no visitor, unit or household.

        On the handset:

        1. Split at the `.`; base64url-decode both halves. Two halves, a 64-byte signature and those eight keys in
           that order — anything else is `malformed`.
        2. `site_id` must be the site the handset is posted to — else `wrong_site`. Decide this before any key.
        3. Look up the cached public key for `site_id` and `key_version` (`GET /sites/{site}/pass-keys`, refreshed
           at every sync) — none is `unknown_key`.
        4. Verify the Ed25519 detached signature **over the base64url payload segment exactly as it appears in the
           token** (the ASCII bytes, not the decoded JSON) — failure is `bad_signature`.
        5. Compare `valid_from`/`valid_to` to the handset's clock with {$tolerance} seconds' tolerance —
           `not_yet_valid` / `expired`.
        6. Otherwise the verdict is **`valid_offline`** — never `valid`. Show it as "Valid pass — not checked
           against cancellations".

        What the handset cannot know offline — a cancellation, or a single-use pass already used at another gate —
        it learns at sync: `GET /sync/pull` returns every still-unexpired pass revoked since the last pull; refuse
        those locally from then on. An admission made offline is sent as `POST /gate/entry` with
        `verified_offline: true`; the server records it and answers `reconciliation`, which the app shows the guard.

        Retired signing keys stay published for {$grace} days — the longest a pass runs — so a rotation never
        strands a pass already issued. `libsodium` (`crypto_sign_verify_detached`) or any Ed25519 implementation
        verifies it; `App\Services\Passes\OfflinePassVerifier` is the reference implementation and runs in a test
        with every database connection severed.

        ### The queue

        Capture every write offline with its Idempotency-Key and its device time, in order. When signal returns:

        1. `GET /sync/pull?since={last next_since}` — shifts, order versions, messages, pending alertness checks,
           revoked passes and current pass keys, request and claim decisions.
        2. `POST /sync/batch` with up to fifty queued operations, oldest first, each naming its endpoint by the
           catalogue name in the heading of its table below. Each runs through that endpoint exactly as if sent
           live. The batch stops at the first server failure (`failed`, then `not_attempted`) and never at a
           refusal (`refused` is an answer — show it). Resend what was not `ok` or `refused`, with the same keys.
        3. Media uploads are not batchable: send them after the report they belong to has an id.

        Panic and duress are the exception to queueing quietly: send the moment any signal exists, and prompt the
        user to call the emergency number meanwhile.

        MD;
    }

    private static function abilities(): string
    {
        $rows = ['---', '', '## 5. Abilities', '', 'A token carries the abilities its app holds in `App\Api\AppMatrix` — `{capability}:read`, and `{capability}:write` where the app may change it. Each endpoint below names the one it needs; a token without it is `403 missing_ability`.', '', '| Capability | Covers | Guard App | Resident App |', '|---|---|---|---|'];

        foreach (AppMatrix::CAPABILITIES as $capability => [$covers, $guard, $resident]) {
            $rows[] = sprintf('| `%s` | %s | %s | %s |', $capability, $covers, $guard, $resident);
        }

        $rows[] = '';
        $rows[] = '### Errors any endpoint can return';
        $rows[] = '';
        $rows[] = 'Shape: `{"error": {"code": "…", "message": "…"}}`. A `422 validation_failed` also carries `errors`: `{"field": ["message", …]}`. Branch on `error.code`, never on the status alone; show `error.message`, which is written for the person holding the phone.';
        $rows[] = '';
        $rows[] = '| Status | Code | When |';
        $rows[] = '|---|---|---|';

        foreach (Catalogue::COMMON_ERRORS as $key => $when) {
            [$status, $code] = explode(' ', $key, 2);
            $rows[] = sprintf('| %s | `%s` | %s |', $status, $code, $when);
        }

        return implode("\n", [...$rows, '', self::levels()])."\n";
    }

    /** The Resident App's access levels, from `AppMatrix::LEVELS` — every row, as the server enforces it. */
    private static function levels(): string
    {
        $rows = [
            '### Resident App access levels',
            '',
            'Every person in a household signs in as one register entry, and that entry has an ACCESS LEVEL: `member`, `dependent` or `helper`. A Resident App token carries the same abilities for all three; the level is checked by the server on EVERY request, against the register entry as it stands, so a change takes effect on the next request and nobody is signed out. A refused request answers `403 not_for_your_access_level` with the sentence to show. The primary resident is always `member`, and a register entry that cannot be found is `helper`. `me.show.access` says what the signed-in person may do; hide the rest, but the server is the rule.',
            '',
            '**Levels ship switched OFF** (`me.show.access.levels_open` is false). Until a server switches them on, everybody is `member`: `members.store`, `members.invite` and `members.level` refuse any other level with `409 access_levels_not_open`, and taking a level back to `member` is always allowed. They are switched on only after the Resident App release that reads `panic.contacts` for the panic screen\'s emergency contacts — the one read a Helper keeps of the household — is out, so do not offer Dependent or Helper while `levels_open` is false.',
            '',
            '| Level | Who |',
            '|---|---|',
        ];

        foreach (AppMatrix::LEVELS_ALL as $level) {
            $rows[] = sprintf('| `%s` | %s |', $level, AccessLevels::describe($level));
        }

        $rows = [...$rows, '', 'An endpoint with no row is refused at every level, except panic\'s (`alerts`), which is never removed from anybody.', '', '| Endpoint | Member | Dependent | Helper |', '|---|---|---|---|'];

        foreach (AppMatrix::LEVELS as $endpoint => $levels) {
            $cells = array_map(static fn (string $level): string => in_array($level, $levels, true) ? 'yes' : '—', AppMatrix::LEVELS_ALL);
            $rows[] = sprintf('| `%s` | %s |', $endpoint, implode(' | ', $cells));
        }

        return implode("\n", $rows);
    }

    private static function limits(): string
    {
        $constants = (new ReflectionClass(RateLimitServiceProvider::class))->getConstants();
        $limits = [
            'api-alerts' => [$constants['ALERTS_PER_MINUTE'], 'per device (token)'],
            'api-gate-events' => [$constants['GATE_EVENTS_PER_MINUTE'], 'per device (token)'],
            'api-shift-clock' => [$constants['SHIFT_CLOCK_PER_MINUTE'], 'per device (token)'],
            'api-reads' => [$constants['READS_PER_MINUTE'], 'per device (token)'],
            'api-writes' => [$constants['WRITES_PER_MINUTE'], 'per device (token)'],
            'api-enrol' => [$constants['ENROL_PER_MINUTE'], 'per IP address'],
            'api-ring' => [$constants['RING_PER_TICKET_PER_MINUTE'], 'per ring ticket, and '.$constants['RING_PER_ADDRESS_PER_MINUTE'].' per IP address as well'],
            'api-ring-test' => [$constants['RING_TEST_PER_MINUTE'], 'per device (token)'],
        ];

        $rows = ['---', '', '## 6. Rate limits', '', 'Exceeding one answers `429 rate_limited` with `Retry-After`; retry after it with the same Idempotency-Key. The alert limit sits where no frightened person can reach it and only a loop can.', '', '| Limiter | Per minute | Keyed | Endpoints |', '|---|---|---|---|'];

        foreach ($limits as $name => [$perMinute, $keyed]) {
            $names = array_keys(array_filter(Catalogue::endpoints(), static fn (array $e): bool => $e['throttle'] === $name));
            $rows[] = sprintf('| `%s` | %d | %s | %s |', $name, $perMinute, $keyed, implode(', ', array_map(static fn (string $n): string => '`'.$n.'`', $names)));
        }

        return implode("\n", $rows)."\n";
    }

    /** @param array<string, array<string, mixed>> $endpoints */
    private static function index(array $endpoints): string
    {
        $rows = ['---', '', '## 7. Endpoint index', '', '| Endpoint | Method | Path | App | Ability | Write |', '|---|---|---|---|---|---|'];

        foreach ($endpoints as $name => $e) {
            $rows[] = sprintf(
                '| [`%s`](#%s) | %s | `/%s` | %s | %s | %s |',
                $name,
                self::anchor($name),
                $e['method'],
                $e['uri'],
                self::apps($e),
                $e['capability'] === null ? '—' : '`'.AppMatrix::ability($e['capability'], $e['access']).'`',
                $e['write'] ? 'yes' : '—',
            );
        }

        return implode("\n", $rows)."\n";
    }

    /** @param array<string, array<string, mixed>> $endpoints */
    private static function endpoints(array $endpoints): string
    {
        $out = ['---', '', '## 8. Endpoints', ''];

        foreach ($endpoints as $name => $e) {
            $out[] = '### `'.$name.'`';
            $out[] = '';
            $out[] = $e['summary'];
            $out[] = '';
            $out[] = '| | |';
            $out[] = '|---|---|';
            $out[] = '| Method and path | `'.$e['method'].' /api/v1/'.$e['uri'].'` |';
            $out[] = '| App | '.self::apps($e).(($e['pending'] ?? false) ? ' — pending accounts too' : '').' |';
            $out[] = '| Ability | '.($e['capability'] === null ? 'none — no token' : '`'.AppMatrix::ability($e['capability'], $e['access']).'`').' |';
            $out[] = '| Idempotency-Key | '.self::keyRule($e).' |';
            $out[] = '| X-Device-Time | '.(! $e['write'] ? '—' : (($e['legacy'] ?? false) ? 'accepted, not required' : 'required')).' |';
            $out[] = '| Success | `'.$e['status'].'` |';
            $out[] = '| Rate limit | `'.$e['throttle'].'` |';

            if (($e['where'] ?? []) !== []) {
                $out[] = '| Path parameters | '.implode(', ', array_map(static fn (string $p, string $rx): string => '`'.$p.'` matches `'.$rx.'`', array_keys($e['where']), $e['where'])).' |';
            }

            if ($e['write'] && ($e['batch'] ?? true) && $e['apps'] === [AppMatrix::GUARD]) {
                $out[] = '| In `sync/batch` | yes |';
            }

            $out[] = '';
            $out[] = '**Request**';
            $out[] = '';

            if ($e['request'] === []) {
                $out[] = 'No body.';
            } else {
                $out[] = '| Field | Type · rule · meaning |';
                $out[] = '|---|---|';

                foreach ($e['request'] as $field => $rule) {
                    $out[] = '| `'.$field.'` | '.self::cell($rule).' |';
                }
            }

            $out[] = '';
            $out[] = '**Response** `'.$e['status'].'`';
            $out[] = '';

            if ($e['response'] === []) {
                $out[] = 'No success body: this endpoint answers with an error envelope.';
            } else {
                $out[] = '| Key | Meaning |';
                $out[] = '|---|---|';

                foreach ($e['response'] as $key => $meaning) {
                    $out[] = '| `'.$key.'` | '.self::cell($meaning).' |';
                }
            }

            $out[] = '';
            $out[] = '**Errors** — every one in the envelope `{"error": {"code", "message"}}`';
            $out[] = '';
            $out[] = '| Status | Code | When |';
            $out[] = '|---|---|---|';

            foreach ([...$e['errors'], ...self::applicableCommonErrors($e, $name)] as $key => $when) {
                [$status, $code] = explode(' ', $key, 2);
                $out[] = sprintf('| %s | `%s` | %s |', $status, $code, self::cell($when));
            }

            $out[] = '';
            $out[] = '**Offline** — '.$e['offline'];
            $out[] = '';
        }

        return implode("\n", $out);
    }

    private static function closing(): string
    {
        return <<<MD
        ---

        ## 9. Where the rules live

        The apps must not reimplement any of these; the consoles call the same services.

        | Rule | Owner |
        |---|---|
        | Who may be admitted at a gate | `App\Services\Restriction\RestrictionPolicy` |
        | What a guard may learn about a household | `Household::guardVisibleStanding()` — a boolean |
        | Signing and verifying passes | `App\Services\Passes\VisitorPasses`, `OfflinePassVerifier`, `PassSigningKeys` |
        | Alerts | `App\Services\Dispatch\AlertIntake` |
        | Gate decisions | `App\Services\Dispatch\GateLog` |
        | Clocking on and off | `App\Services\Dispatch\ShiftClock` |
        | Handset enrolment | `App\Services\Devices\DeviceEnrolment` |
        | Resident sign-in and unit claims | `App\Services\ResidentApp\ResidentSignIn`, `ResidentAccounts` |
        | Dues figures | `App\Services\Estate\Dues` — the ledger |
        | The vote | `App\Services\Estate\Governance::castVote` |
        | Every endpoint's contract | `App\Api\Catalogue` |

        ## 10. Building before the apps ship

        `php artisan simulate:alerts` and `php artisan simulate:gate --shift-change` speak to this API over HTTP
        with borrowed guard handsets; every row they write is flagged `is_simulated` and badged on the consoles.
        Send `"simulated": true` only from a simulator.

        ## 11. Not here, deliberately

        | Not built | Why |
        |---|---|
        | SMS delivery | No provider connected; `sms` sign-in answers `503 sms_unavailable` until one is, and a panic's `sms.note` says "No text was sent: no SMS provider is connected." Email works. |
        | Real card payments | Dues are paid by hand until a gateway is ruled in (Q-012). While `DEMO_MODE` is on, the checkout takes Stripe's TEST cards only (D-179): `payments.card`, saved test cards (`cards.*`) and AutoPay (`autopay.*`, M50 §4.2 #3) all post demonstration receipts to 1090 and move no money; with it off, `pay-intent` gives instructions and `autopay.store` refuses. |
        | A push without Expo | Push to a CLOSED app goes through Expo (`push.register` · `push.unregister`, M4 §3.2). There is no direct APNs or FCM path, and a build with no EAS project id gets no token — the apps then notify from their own polling while they are open. |
        | Realtime sockets for handsets | The Reverb channels are console channels. Handsets poll. |
        | Biometric templates | Never leave the device. `alertness/{check}/respond` takes no body at all — the tap is the answer. |
        | Photos of walk-up visitors | No ruling on keeping images of the public; the approval works without one. |
        | Editing a member | A member's name is corrected by the estate office; members are removed by the primary resident only (`members.destroy`). A vehicle, a pet and an emergency contact ARE changed in place (`vehicles.update`, `pets.update`, `contacts.update`, M50 §5.3–§5.4). |

        MD;
    }

    /** @param array<string, mixed> $e */
    private static function apps(array $e): string
    {
        if ($e['apps'] === []) {
            return match (true) {
                str_starts_with((string) $e['uri'], 'devices/') => 'Guard, before enrolment — no token',
                str_starts_with((string) $e['uri'], 'ring/') => 'Guard — a ring ticket, no token',
                default => 'Resident, before sign-in — no token',
            };
        }

        return implode(' + ', array_map(static fn (string $a): string => $a === AppMatrix::GUARD ? 'Guard' : 'Resident', $e['apps']));
    }

    /** @param array<string, mixed> $e */
    private static function keyRule(array $e): string
    {
        return match (true) {
            ! $e['write'] => '—',
            (bool) ($e['legacy'] ?? false) => 'accepted, not required',
            (bool) ($e['opaque'] ?? false) => 'required · opaque (the body is not hashed)',
            default => 'required',
        };
    }

    /**
     * The common errors this endpoint can actually return.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, string>
     */
    private static function applicableCommonErrors(array $e, string $name): array
    {
        $common = Catalogue::COMMON_ERRORS;
        $keys = ['429 rate_limited'];

        if ($e['request'] !== []) {
            $keys[] = '422 validation_failed';
        }

        if ($e['capability'] !== null) {
            array_push($keys, '401 unauthenticated', '403 missing_ability', '403 wrong_app', '403 no_site');

            if (in_array(AppMatrix::GUARD, $e['apps'], true)) {
                $keys[] = '403 guard_not_active';
            }

            if (in_array(AppMatrix::RESIDENT, $e['apps'], true)) {
                $keys[] = '403 account_suspended';

                // The estate office ended the account (Q-M44) — reachable on every resident route.
                $keys[] = '403 account_revoked';

                if (! ($e['pending'] ?? false)) {
                    $keys[] = '403 account_pending';
                }

                // A household member's access level (`AppMatrix::LEVELS`) — wherever some level is refused.
                if (! self::openToEveryLevel($name, $e['capability'])) {
                    $keys[] = '403 not_for_your_access_level';
                }
            }
        }

        if (($e['where'] ?? []) !== []) {
            $keys[] = '404 not_found';
        }

        if ($e['write']) {
            $keys[] = '409 request_in_progress';

            if (! ($e['legacy'] ?? false)) {
                array_push($keys, '422 idempotency_key_required', '422 device_time_required');
            }

            array_push($keys, '422 idempotency_key_reused', '422 device_time_invalid');
        }

        $out = [];

        foreach ($keys as $key) {
            if (! isset($e['errors'][$key])) {
                $out[$key] = $common[$key];
            }
        }

        return $out;
    }

    /**
     * Whether every access level reaches this endpoint — read from the table itself, so a missing row
     * reads as `AppMatrix::levelAllows` would answer it (refused, unless it is panic's `alerts`) without
     * reporting anything from a documentation run.
     */
    private static function openToEveryLevel(string $name, mixed $capability): bool
    {
        if (! array_key_exists($name, AppMatrix::LEVELS)) {
            return $capability === 'alerts';
        }

        return array_diff(AppMatrix::LEVELS_ALL, AppMatrix::LEVELS[$name]) === [];
    }

    private static function anchor(string $name): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower($name)) ?? $name;
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }
}
