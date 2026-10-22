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
   whether or not an account exists. A code lasts 10 minutes and 5 tries.
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
| the first attempt died without answering, 5 minutes ago or more | `409 idempotent_answer_missing` — final: move past it and tell the person to check whether it went through. A panic or duress is run again instead, and answered with its alert |
| the first attempt failed with a 5xx | nothing was stored — the retry is a real attempt |

The ballot is the one exception to "a different request": its record keeps a hash of the method and path
only, never the paper, so a stored hash cannot be tried against the options to learn a vote. A different
paper under the same key is answered with the first answer.

**`X-Device-Time`** — the handset's own clock at the moment the act happened (for a queued act, when it
was captured, not when it was sent). Required on writes. The server never corrects it: every successful
write answers `server_time`, `device_time` and `clock_skewed`: true when the device time is more than
120 seconds AHEAD of the server's. A device time in the past is an act queued with no signal and is not
flagged, since no queue can put an act in the future. Show a guard whose clock is wrong that it is wrong.

---

## 4. Offline

### Verifying a visitor pass with no signal

A pass in a QR code is `base64url(payload) . "." . base64url(signature)`. The payload is canonical JSON
with exactly these fields in this order: pass_id · tenant_id · site_id · valid_from · valid_to · single_use · nonce · key_version. It names no visitor, unit or household.

On the handset:

1. Split at the `.`; base64url-decode both halves. Two halves, a 64-byte signature and those eight keys in
   that order — anything else is `malformed`.
2. `site_id` must be the site the handset is posted to — else `wrong_site`. Decide this before any key.
3. Look up the cached public key for `site_id` and `key_version` (`GET /sites/{site}/pass-keys`, refreshed
   at every sync) — none is `unknown_key`.
4. Verify the Ed25519 detached signature **over the base64url payload segment exactly as it appears in the
   token** (the ASCII bytes, not the decoded JSON) — failure is `bad_signature`.
5. Compare `valid_from`/`valid_to` to the handset's clock with 120 seconds' tolerance —
   `not_yet_valid` / `expired`.
6. Otherwise the verdict is **`valid_offline`** — never `valid`. Show it as "Valid pass — not checked
   against cancellations".

What the handset cannot know offline — a cancellation, or a single-use pass already used at another gate —
it learns at sync: `GET /sync/pull` returns every still-unexpired pass revoked since the last pull; refuse
those locally from then on. An admission made offline is sent as `POST /gate/entry` with
`verified_offline: true`; the server records it and answers `reconciliation`, which the app shows the guard.

Retired signing keys stay published for 31 days — the longest a pass runs — so a rotation never
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

---

## 5. Abilities

A token carries the abilities its app holds in `App\Api\AppMatrix` — `{capability}:read`, and `{capability}:write` where the app may change it. Each endpoint below names the one it needs; a token without it is `403 missing_ability`.

| Capability | Covers | Guard App | Resident App |
|---|---|---|---|
| `alerts` | Raise, and cancel within the grace period, a panic or duress alert; register this phone to be alerted while the app is closed, and receive live updates while the app is open | write | write |
| `gate` | Verify passes, record entries, exits and overrides, search units, read the gate log | write | none |
| `passes` | Issue, share, cancel and read visitor passes; the resident's own e-pass | none | write |
| `approvals` | Answer a guard's walk-up entry request for the resident's own unit | none | write |
| `shifts` | Read the roster, pre-flight, clock on and off, take breaks, claim open shifts | write | none |
| `orders` | Read and acknowledge standing orders for the guard's posts | write | none |
| `patrol` | Read a site's checkpoints and scan them | write | none |
| `alertness` | Answer a random alertness check | write | none |
| `presence` | Report on-post activity, share the officer's position between clock-in and clock-out with their consent, and read the guard's own activity summary | write | none |
| `incidents` | File incident reports with media, and read the guard's own | write | none |
| `requests` | Raise leave and equipment requests, and read the guard's own | write | none |
| `payslips` | Read the guard's own payslips — their own wage, and nobody else's | read | none |
| `messages` | Read and send messages with dispatch | write | none |
| `sync` | Upload a queue captured offline, and pull what changed | write | none |
| `household` | The resident's own profile, household members, vehicles and emergency contacts | none | write |
| `dues` | The resident's OWN household's invoices, balance, statement and receipts | none | read |
| `payments` | Start a payment and manage AutoPay for the resident's own household | none | write |
| `tickets` | Report maintenance problems with media, and follow them | none | write |
| `notices` | Read notices and mark them read | none | write |
| `meetings` | Read meetings and RSVP | none | write |
| `elections` | Read elections, check eligibility, cast the household's ballot, read results | none | write |
| `bookings` | Read amenities, book and cancel | none | write |

### Errors any endpoint can return

Shape: `{"error": {"code": "…", "message": "…"}}`. A `422 validation_failed` also carries `errors`: `{"field": ["message", …]}`. Branch on `error.code`, never on the status alone; show `error.message`, which is written for the person holding the phone.

| Status | Code | When |
|---|---|---|
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 401 | `session_expired` | The token is real but its IDLE WINDOW has closed: nothing was sent from this phone for thirty minutes (`config/api.php` · `session_idle_minutes`). It is a different fact from `unauthenticated` and needs a different sentence — the handset was not revoked by anybody, it simply went quiet. The window SLIDES: every authenticated request renews it, so an app in use never sees this. Show the message as it is sent, keep everything captured offline, and offer the way back in. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 409 | `idempotent_answer_missing` | It is not known, or no longer recorded, whether the write went through: its first attempt died before it could answer (a server restart mid-request, answered once it is five minutes old), or the answer stored for this key is gone (pruned, or the database edited by hand). FINAL — do not send the act again on this key or any other; move past it in the queue and tell the person to check whether it went through. A panic or duress is never answered this: it is run again, and the alert it raised is handed back. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |

### Resident App access levels

Every person in a household signs in as one register entry, and that entry has an ACCESS LEVEL: `member`, `dependent` or `helper`. A Resident App token carries the same abilities for all three; the level is checked by the server on EVERY request, against the register entry as it stands, so a change takes effect on the next request and nobody is signed out. A refused request answers `403 not_for_your_access_level` with the sentence to show. The primary resident is always `member`, and a register entry that cannot be found is `helper`. `me.show.access` says what the signed-in person may do; hide the rest, but the server is the rule.

**Levels ship switched OFF** (`me.show.access.levels_open` is false). Until a server switches them on, everybody is `member`: `members.store`, `members.invite` and `members.level` refuse any other level with `409 access_levels_not_open`, and taking a level back to `member` is always allowed. They are switched on only after the Resident App release that reads `panic.contacts` for the panic screen's emergency contacts — the one read a Helper keeps of the household — is out, so do not offer Dependent or Helper while `levels_open` is false.

| Level | Who |
|---|---|
| `member` | An adult of the household: passes, dues, voting and everything else. Only the primary resident adds or removes people. |
| `dependent` | A child or an elderly relative: the panic button, their own e-pass, notices and maintenance. No payments, passes or voting. |
| `helper` | Domestic staff: the panic button, with the household's emergency contacts to text or call from it, and their own e-pass to come and go. Nothing else about the household, its money or its votes. |

An endpoint with no row is refused at every level, except panic's (`alerts`), which is never removed from anybody.

| Endpoint | Member | Dependent | Helper |
|---|---|---|---|
| `auth.sign_out` | yes | yes | yes |
| `push.register` | yes | yes | yes |
| `push.unregister` | yes | yes | yes |
| `auth.claim_unit` | yes | yes | yes |
| `me.show` | yes | yes | yes |
| `me.delete_account` | yes | yes | yes |
| `me.sessions` | yes | yes | yes |
| `me.sessions.end` | yes | yes | yes |
| `me.sessions.end_others` | yes | yes | yes |
| `me.device_key.store` | yes | yes | yes |
| `me.device_key.destroy` | yes | yes | yes |
| `me.notifications.email.show` | yes | yes | yes |
| `me.notifications.email.update` | yes | yes | yes |
| `me.photo` | yes | yes | yes |
| `me.photo.store` | yes | yes | yes |
| `me.photo.remove` | yes | yes | yes |
| `me.data_export` | yes | yes | yes |
| `support.contact` | yes | yes | yes |
| `support.chat.show` | yes | yes | yes |
| `support.chat.messages.store` | yes | yes | yes |
| `me.shown` | yes | yes | yes |
| `panic.contacts` | yes | yes | yes |
| `live.config` | yes | yes | yes |
| `live.auth` | yes | yes | yes |
| `live.catch_up` | yes | yes | yes |
| `alerts.store` | yes | yes | yes |
| `duress.store` | yes | yes | yes |
| `duress.cancel` | yes | yes | yes |
| `duress.show` | yes | yes | yes |
| `duress.messages.store` | yes | yes | yes |
| `duress.audio.store` | yes | yes | yes |
| `me.update` | yes | yes | yes |
| `epass.show` | yes | yes | yes |
| `me.household` | yes | yes | — |
| `members.index` | yes | yes | — |
| `members.store` | yes | — | — |
| `members.invite` | yes | — | — |
| `members.invite_cancel` | yes | — | — |
| `members.level` | yes | — | — |
| `members.access` | yes | — | — |
| `members.destroy` | yes | — | — |
| `vehicles.index` | yes | yes | — |
| `vehicles.store` | yes | — | — |
| `vehicles.destroy` | yes | — | — |
| `vehicles.update` | yes | — | — |
| `pets.index` | yes | yes | — |
| `pets.store` | yes | — | — |
| `pets.update` | yes | — | — |
| `pets.destroy` | yes | — | — |
| `contacts.index` | yes | yes | — |
| `contacts.store` | yes | — | — |
| `contacts.update` | yes | — | — |
| `contacts.destroy` | yes | — | — |
| `visitor_passes.index` | yes | — | — |
| `visitor_passes.store` | yes | — | — |
| `visitor_passes.update` | yes | — | — |
| `visitor_passes.cancel` | yes | — | — |
| `visitor_passes.share` | yes | — | — |
| `approvals.respond` | yes | — | — |
| `invoices.index` | yes | — | — |
| `households.balance` | yes | — | — |
| `households.statement` | yes | — | — |
| `payments.index` | yes | — | — |
| `payments.receipt` | yes | — | — |
| `invoices.pay_intent` | yes | — | — |
| `payments.card` | yes | — | — |
| `payments.intents.store` | yes | — | — |
| `payments.intents.show` | yes | — | — |
| `autopay.show` | yes | — | — |
| `autopay.store` | yes | — | — |
| `autopay.destroy` | yes | — | — |
| `cards.index` | yes | — | — |
| `cards.store` | yes | — | — |
| `cards.default` | yes | — | — |
| `cards.destroy` | yes | — | — |
| `tickets.index` | yes | yes | — |
| `tickets.store` | yes | yes | — |
| `tickets.media` | yes | yes | — |
| `tickets.confirm_fixed` | yes | yes | — |
| `tickets.still_a_problem` | yes | yes | — |
| `tickets.comment` | yes | yes | — |
| `notices.index` | yes | yes | — |
| `notices.attachment` | yes | yes | — |
| `notices.read` | yes | yes | — |
| `meetings.index` | yes | — | — |
| `meetings.minutes` | yes | — | — |
| `meetings.rsvp` | yes | — | — |
| `elections.index` | yes | — | — |
| `elections.eligibility` | yes | — | — |
| `elections.ballot` | yes | — | — |
| `elections.results` | yes | — | — |
| `amenities.index` | yes | — | — |
| `bookings.index` | yes | — | — |
| `bookings.store` | yes | — | — |
| `bookings.cancel` | yes | — | — |
| `bookings.pay_card` | yes | — | — |
| `bookings.quote` | yes | — | — |
| `bookings.book` | yes | — | — |

---

## 6. Rate limits

Exceeding one answers `429 rate_limited` with `Retry-After`; retry after it with the same Idempotency-Key. The alert limit sits where no frightened person can reach it and only a loop can.

| Limiter | Per minute | Keyed | Endpoints |
|---|---|---|---|
| `api-alerts` | 30 | per device (token) | `alerts.store`, `duress.store`, `duress.cancel`, `duress.messages.store`, `duress.audio.store`, `duress.positions.store`, `alerts.en_route`, `alerts.on_scene` |
| `api-gate-events` | 120 | per device (token) | `passes.verify`, `gate_events.store`, `gate.verify`, `gate.entry`, `gate.exit`, `gate.override`, `gate.approvals.store` |
| `api-shift-clock` | 20 | per device (token) | `shifts.clock_in`, `shifts.clock_out`, `shifts.break.start`, `shifts.break.end`, `orders.acknowledge` |
| `api-reads` | 240 | per device (token) | `live.config`, `standing_orders.index`, `passes.keys`, `shifts.me.current`, `shifts.me`, `shifts.preflight`, `shifts.open`, `orders.current`, `patrol.checkpoints`, `presence.summary`, `tracking.status`, `tracking.my_positions`, `gate.search`, `gate.activity`, `gate.approvals.show`, `incidents.me`, `duress.show`, `panic.contacts`, `requests.index`, `alerts.show`, `payslips.me`, `payslips.pdf`, `messages.index`, `guards.me`, `sync.pull`, `me.show`, `me.photo`, `support.contact`, `support.chat.show`, `me.sessions`, `me.notifications.email.show`, `me.household`, `members.index`, `vehicles.index`, `contacts.index`, `pets.index`, `visitor_passes.index`, `epass.show`, `invoices.index`, `households.balance`, `households.statement`, `payments.intents.show`, `payments.index`, `payments.receipt`, `autopay.show`, `cards.index`, `tickets.index`, `notices.index`, `notices.attachment`, `meetings.index`, `meetings.minutes`, `elections.index`, `elections.eligibility`, `elections.results`, `amenities.index`, `bookings.quote`, `bookings.index` |
| `api-writes` | 120 | per device (token) | `auth.sign_out`, `push.register`, `push.unregister`, `shifts.claim`, `patrol.scan`, `alertness.respond`, `presence.activity`, `tracking.consent`, `tracking.positions`, `tracking.presence_positions`, `incidents.store`, `incidents.media`, `requests.store`, `messages.store`, `messages.read`, `sync.batch`, `auth.claim_unit`, `me.update`, `me.shown`, `me.delete_account`, `support.chat.messages.store`, `me.photo.store`, `me.photo.remove`, `me.sessions.end`, `me.sessions.end_others`, `me.device_key.store`, `me.device_key.destroy`, `me.notifications.email.update`, `members.store`, `members.invite`, `members.invite_cancel`, `members.level`, `members.access`, `members.destroy`, `vehicles.store`, `contacts.store`, `contacts.update`, `contacts.destroy`, `vehicles.destroy`, `vehicles.update`, `pets.store`, `pets.update`, `pets.destroy`, `visitor_passes.store`, `visitor_passes.update`, `visitor_passes.cancel`, `visitor_passes.share`, `approvals.respond`, `invoices.pay_intent`, `payments.card`, `payments.intents.store`, `autopay.store`, `autopay.destroy`, `cards.store`, `cards.default`, `cards.destroy`, `tickets.store`, `tickets.media`, `tickets.confirm_fixed`, `tickets.comment`, `tickets.still_a_problem`, `notices.read`, `meetings.rsvp`, `elections.ballot`, `bookings.book`, `bookings.pay_card`, `bookings.store`, `bookings.cancel` |
| `api-enrol` | 10 | per IP address | `devices.enrol`, `devices.enrol.status`, `devices.challenge`, `devices.resume`, `estates.index`, `auth.otp.request`, `auth.otp.verify`, `auth.restore`, `auth.device_key`, `demo.enter` |
| `api-ring` | 30 | per ring ticket, and 120 per IP address as well | `ring.answer`, `ring.receipt` |
| `api-ring-test` | 1 | per device (token) | `ring.test` |

---

## 7. Endpoint index

| Endpoint | Method | Path | App | Ability | Write |
|---|---|---|---|---|---|
| [`devices.enrol`](#devicesenrol) | POST | `/devices/enrol` | Guard, before enrolment — no token | — | — |
| [`devices.enrol.status`](#devicesenrolstatus) | POST | `/devices/enrol/{rebind}/collect` | Guard, before enrolment — no token | — | — |
| [`devices.challenge`](#deviceschallenge) | POST | `/devices/challenge` | Guard, before enrolment — no token | — | — |
| [`devices.resume`](#devicesresume) | POST | `/devices/resume` | Guard, before enrolment — no token | — | — |
| [`auth.sign_out`](#authsign_out) | DELETE | `/session` | Guard + Resident | `alerts:read` | — |
| [`push.register`](#pushregister) | POST | `/push/token` | Guard + Resident | `alerts:write` | yes |
| [`push.unregister`](#pushunregister) | DELETE | `/push/token` | Guard + Resident | `alerts:write` | yes |
| [`live.config`](#liveconfig) | GET | `/live/config` | Guard + Resident | `alerts:read` | — |
| [`live.auth`](#liveauth) | POST | `/live/auth` | Guard + Resident | `alerts:read` | — |
| [`live.catch_up`](#livecatch_up) | POST | `/live/catch-up` | Guard + Resident | `alerts:read` | — |
| [`alerts.store`](#alertsstore) | POST | `/alerts` | Guard + Resident | `alerts:write` | yes |
| [`passes.verify`](#passesverify) | POST | `/passes/verify` | Guard | `gate:write` | — |
| [`gate_events.store`](#gate_eventsstore) | POST | `/gate-events` | Guard | `gate:write` | yes |
| [`shifts.clock_in`](#shiftsclock_in) | POST | `/shifts/{shift}/clock-in` | Guard | `shifts:write` | yes |
| [`shifts.clock_out`](#shiftsclock_out) | POST | `/shifts/{shift}/clock-out` | Guard | `shifts:write` | yes |
| [`standing_orders.index`](#standing_ordersindex) | GET | `/standing-orders` | Guard | `orders:read` | — |
| [`passes.keys`](#passeskeys) | GET | `/sites/{site}/pass-keys` | Guard | `gate:read` | — |
| [`shifts.me.current`](#shiftsmecurrent) | GET | `/shifts/me/current` | Guard | `shifts:read` | — |
| [`shifts.me`](#shiftsme) | GET | `/shifts/me` | Guard | `shifts:read` | — |
| [`shifts.preflight`](#shiftspreflight) | POST | `/shifts/{shift}/preflight` | Guard | `shifts:read` | — |
| [`shifts.break.start`](#shiftsbreakstart) | POST | `/shifts/{shift}/break/start` | Guard | `shifts:write` | yes |
| [`shifts.break.end`](#shiftsbreakend) | POST | `/shifts/{shift}/break/end` | Guard | `shifts:write` | yes |
| [`shifts.open`](#shiftsopen) | GET | `/shifts/open` | Guard | `shifts:read` | — |
| [`shifts.claim`](#shiftsclaim) | POST | `/shifts/{shift}/claim` | Guard | `shifts:write` | yes |
| [`shifts.clock_in_override`](#shiftsclock_in_override) | POST | `/shifts/{shift}/clock-in/override` | Guard | `shifts:write` | yes |
| [`orders.current`](#orderscurrent) | GET | `/sites/{site}/standing-orders/current` | Guard | `orders:read` | — |
| [`orders.acknowledge`](#ordersacknowledge) | POST | `/standing-orders/{version}/acknowledge` | Guard | `orders:write` | yes |
| [`patrol.checkpoints`](#patrolcheckpoints) | GET | `/sites/{site}/checkpoints` | Guard | `patrol:read` | — |
| [`patrol.scan`](#patrolscan) | POST | `/checkpoints/{checkpoint}/scan` | Guard | `patrol:write` | yes |
| [`alertness.respond`](#alertnessrespond) | POST | `/alertness/{check}/respond` | Guard | `alertness:write` | yes |
| [`presence.activity`](#presenceactivity) | POST | `/presence/activity` | Guard | `presence:write` | yes |
| [`presence.summary`](#presencesummary) | GET | `/guards/me/activity-summary` | Guard | `presence:read` | — |
| [`tracking.status`](#trackingstatus) | GET | `/guards/me/tracking` | Guard | `presence:read` | — |
| [`tracking.consent`](#trackingconsent) | POST | `/guards/me/tracking/consent` | Guard | `presence:write` | yes |
| [`tracking.positions`](#trackingpositions) | POST | `/shifts/{shift}/positions` | Guard | `presence:write` | yes |
| [`tracking.presence_positions`](#trackingpresence_positions) | POST | `/presence/positions` | Guard | `presence:write` | yes |
| [`tracking.my_positions`](#trackingmy_positions) | GET | `/presence/positions` | Guard | `presence:read` | — |
| [`gate.verify`](#gateverify) | POST | `/gate/verify` | Guard | `gate:write` | — |
| [`gate.entry`](#gateentry) | POST | `/gate/entry` | Guard | `gate:write` | yes |
| [`gate.exit`](#gateexit) | POST | `/gate/exit` | Guard | `gate:write` | yes |
| [`gate.override`](#gateoverride) | POST | `/gate/override` | Guard | `gate:write` | yes |
| [`gate.search`](#gatesearch) | GET | `/gate/search` | Guard | `gate:read` | — |
| [`gate.activity`](#gateactivity) | GET | `/gate/activity` | Guard | `gate:read` | — |
| [`gate.approvals.store`](#gateapprovalsstore) | POST | `/gate/approvals` | Guard | `gate:write` | yes |
| [`gate.approvals.show`](#gateapprovalsshow) | GET | `/gate/approvals/{approval}` | Guard | `gate:read` | — |
| [`incidents.store`](#incidentsstore) | POST | `/incidents` | Guard | `incidents:write` | yes |
| [`incidents.me`](#incidentsme) | GET | `/incidents/me` | Guard | `incidents:read` | — |
| [`incidents.media`](#incidentsmedia) | POST | `/incidents/{incident}/media` | Guard | `incidents:write` | yes |
| [`duress.store`](#duressstore) | POST | `/duress` | Guard + Resident | `alerts:write` | yes |
| [`duress.cancel`](#duresscancel) | POST | `/duress/{alert}/cancel` | Guard + Resident | `alerts:write` | yes |
| [`duress.show`](#duressshow) | GET | `/duress/{alert}` | Guard + Resident | `alerts:read` | — |
| [`duress.messages.store`](#duressmessagesstore) | POST | `/duress/{alert}/messages` | Resident | `alerts:write` | yes |
| [`duress.audio.store`](#duressaudiostore) | POST | `/duress/{alert}/audio` | Resident | `alerts:write` | yes |
| [`duress.positions.store`](#duresspositionsstore) | POST | `/duress/{alert}/positions` | Guard + Resident | `alerts:write` | yes |
| [`panic.contacts`](#paniccontacts) | GET | `/panic/contacts` | Resident | `alerts:read` | — |
| [`requests.index`](#requestsindex) | GET | `/requests` | Guard | `requests:read` | — |
| [`requests.store`](#requestsstore) | POST | `/requests` | Guard | `requests:write` | yes |
| [`alerts.show`](#alertsshow) | GET | `/alerts/{alert}` | Guard | `alerts:read` | — |
| [`alerts.en_route`](#alertsen_route) | POST | `/alerts/{alert}/en-route` | Guard | `alerts:write` | yes |
| [`alerts.on_scene`](#alertson_scene) | POST | `/alerts/{alert}/on-scene` | Guard | `alerts:write` | yes |
| [`ring.answer`](#ringanswer) | POST | `/ring/answer` | Guard — a ring ticket, no token | — | — |
| [`ring.receipt`](#ringreceipt) | POST | `/ring/receipt` | Guard — a ring ticket, no token | — | — |
| [`ring.test`](#ringtest) | POST | `/ring/test` | Guard | `alerts:write` | yes |
| [`payslips.me`](#payslipsme) | GET | `/payslips/me` | Guard | `payslips:read` | — |
| [`payslips.pdf`](#payslipspdf) | GET | `/payslips/{payslip}/pdf` | Guard | `payslips:read` | — |
| [`messages.index`](#messagesindex) | GET | `/messages` | Guard | `messages:read` | — |
| [`messages.store`](#messagesstore) | POST | `/messages` | Guard | `messages:write` | yes |
| [`messages.read`](#messagesread) | POST | `/messages/read` | Guard | `messages:write` | yes |
| [`guards.me`](#guardsme) | GET | `/guards/me` | Guard | `shifts:read` | — |
| [`sync.batch`](#syncbatch) | POST | `/sync/batch` | Guard | `sync:write` | yes |
| [`sync.pull`](#syncpull) | GET | `/sync/pull` | Guard | `sync:read` | — |
| [`estates.index`](#estatesindex) | GET | `/estates` | Resident, before sign-in — no token | — | — |
| [`auth.otp.request`](#authotprequest) | POST | `/auth/otp/request` | Resident, before sign-in — no token | — | — |
| [`auth.otp.verify`](#authotpverify) | POST | `/auth/otp/verify` | Resident, before sign-in — no token | — | — |
| [`auth.restore`](#authrestore) | POST | `/auth/restore` | Resident, before sign-in — no token | — | — |
| [`auth.device_key`](#authdevice_key) | POST | `/auth/device-key` | Resident, before sign-in — no token | — | — |
| [`demo.enter`](#demoenter) | POST | `/demo/enter` | Resident, before sign-in — no token | — | — |
| [`auth.claim_unit`](#authclaim_unit) | POST | `/auth/claim-unit` | Resident | `household:write` | yes |
| [`me.show`](#meshow) | GET | `/me` | Resident | `household:read` | — |
| [`me.update`](#meupdate) | PATCH | `/me` | Resident | `household:write` | yes |
| [`me.shown`](#meshown) | POST | `/me/shown` | Resident | `household:write` | yes |
| [`me.delete_account`](#medelete_account) | DELETE | `/me` | Resident | `household:write` | yes |
| [`me.photo`](#mephoto) | GET | `/me/photo` | Resident | `household:read` | — |
| [`me.data_export`](#medata_export) | GET | `/me/data-export` | Resident | `household:read` | — |
| [`support.contact`](#supportcontact) | GET | `/support/contact` | Resident | `household:read` | — |
| [`support.chat.show`](#supportchatshow) | GET | `/support/chat` | Resident | `household:read` | — |
| [`support.chat.messages.store`](#supportchatmessagesstore) | POST | `/support/chat/messages` | Resident | `household:write` | yes |
| [`me.photo.store`](#mephotostore) | POST | `/me/photo` | Resident | `household:write` | yes |
| [`me.photo.remove`](#mephotoremove) | DELETE | `/me/photo` | Resident | `household:write` | yes |
| [`me.sessions`](#mesessions) | GET | `/me/sessions` | Resident | `household:read` | — |
| [`me.sessions.end`](#mesessionsend) | DELETE | `/me/sessions/{session}` | Resident | `household:write` | yes |
| [`me.sessions.end_others`](#mesessionsend_others) | DELETE | `/me/sessions` | Resident | `household:write` | yes |
| [`me.device_key.store`](#medevice_keystore) | POST | `/me/device-key` | Resident | `alerts:read` | — |
| [`me.device_key.destroy`](#medevice_keydestroy) | DELETE | `/me/device-key` | Resident | `alerts:read` | — |
| [`me.notifications.email.show`](#menotificationsemailshow) | GET | `/me/notifications/email` | Resident | `alerts:read` | — |
| [`me.notifications.email.update`](#menotificationsemailupdate) | PUT | `/me/notifications/email` | Resident | `alerts:read` | — |
| [`me.household`](#mehousehold) | GET | `/me/household` | Resident | `household:read` | — |
| [`members.index`](#membersindex) | GET | `/household-members` | Resident | `household:read` | — |
| [`members.store`](#membersstore) | POST | `/household-members` | Resident | `household:write` | yes |
| [`members.invite`](#membersinvite) | POST | `/household-members/{member}/invite` | Resident | `household:write` | yes |
| [`members.invite_cancel`](#membersinvite_cancel) | DELETE | `/household-members/{member}/invite` | Resident | `household:write` | yes |
| [`members.level`](#memberslevel) | PATCH | `/household-members/{member}/access-level` | Resident | `household:write` | yes |
| [`members.access`](#membersaccess) | PATCH | `/household-members/{member}/access-window` | Resident | `household:write` | yes |
| [`members.destroy`](#membersdestroy) | DELETE | `/household-members/{member}` | Resident | `household:write` | yes |
| [`vehicles.index`](#vehiclesindex) | GET | `/vehicles` | Resident | `household:read` | — |
| [`vehicles.store`](#vehiclesstore) | POST | `/vehicles` | Resident | `household:write` | yes |
| [`contacts.index`](#contactsindex) | GET | `/emergency-contacts` | Resident | `household:read` | — |
| [`contacts.store`](#contactsstore) | POST | `/emergency-contacts` | Resident | `household:write` | yes |
| [`contacts.update`](#contactsupdate) | PATCH | `/emergency-contacts/{contact}` | Resident | `household:write` | yes |
| [`contacts.destroy`](#contactsdestroy) | DELETE | `/emergency-contacts/{contact}` | Resident | `household:write` | yes |
| [`vehicles.destroy`](#vehiclesdestroy) | DELETE | `/vehicles/{vehicle}` | Resident | `household:write` | yes |
| [`vehicles.update`](#vehiclesupdate) | PATCH | `/vehicles/{vehicle}` | Resident | `household:write` | yes |
| [`pets.index`](#petsindex) | GET | `/pets` | Resident | `household:read` | — |
| [`pets.store`](#petsstore) | POST | `/pets` | Resident | `household:write` | yes |
| [`pets.update`](#petsupdate) | PATCH | `/pets/{pet}` | Resident | `household:write` | yes |
| [`pets.destroy`](#petsdestroy) | DELETE | `/pets/{pet}` | Resident | `household:write` | yes |
| [`visitor_passes.index`](#visitor_passesindex) | GET | `/visitor-passes` | Resident | `passes:read` | — |
| [`visitor_passes.store`](#visitor_passesstore) | POST | `/visitor-passes` | Resident | `passes:write` | yes |
| [`visitor_passes.update`](#visitor_passesupdate) | PATCH | `/visitor-passes/{pass}` | Resident | `passes:write` | yes |
| [`visitor_passes.cancel`](#visitor_passescancel) | POST | `/visitor-passes/{pass}/cancel` | Resident | `passes:write` | yes |
| [`visitor_passes.share`](#visitor_passesshare) | POST | `/visitor-passes/{pass}/share` | Resident | `passes:write` | yes |
| [`epass.show`](#epassshow) | GET | `/e-pass` | Resident | `passes:read` | — |
| [`approvals.respond`](#approvalsrespond) | POST | `/gate/approval/{approval}/respond` | Resident | `approvals:write` | yes |
| [`invoices.index`](#invoicesindex) | GET | `/invoices` | Resident | `dues:read` | — |
| [`households.balance`](#householdsbalance) | GET | `/households/{household}/balance` | Resident | `dues:read` | — |
| [`households.statement`](#householdsstatement) | GET | `/households/{household}/statement` | Resident | `dues:read` | — |
| [`invoices.pay_intent`](#invoicespay_intent) | POST | `/invoices/{invoice}/pay-intent` | Resident | `payments:write` | — |
| [`payments.card`](#paymentscard) | POST | `/payments/card` | Resident | `payments:write` | yes |
| [`payments.intents.store`](#paymentsintentsstore) | POST | `/payments/intents` | Resident | `payments:write` | yes |
| [`payments.intents.show`](#paymentsintentsshow) | GET | `/payments/intents/{reference}` | Resident | `dues:read` | — |
| [`payments.index`](#paymentsindex) | GET | `/payments` | Resident | `dues:read` | — |
| [`payments.receipt`](#paymentsreceipt) | GET | `/payments/{payment}/receipt` | Resident | `dues:read` | — |
| [`autopay.show`](#autopayshow) | GET | `/autopay` | Resident | `payments:read` | — |
| [`autopay.store`](#autopaystore) | POST | `/autopay` | Resident | `payments:write` | — |
| [`autopay.destroy`](#autopaydestroy) | DELETE | `/autopay/{autopay}` | Resident | `payments:write` | — |
| [`cards.index`](#cardsindex) | GET | `/me/cards` | Resident | `payments:read` | — |
| [`cards.store`](#cardsstore) | POST | `/me/cards` | Resident | `payments:write` | — |
| [`cards.default`](#cardsdefault) | POST | `/me/cards/{card}/default` | Resident | `payments:write` | — |
| [`cards.destroy`](#cardsdestroy) | DELETE | `/me/cards/{card}` | Resident | `payments:write` | — |
| [`tickets.index`](#ticketsindex) | GET | `/tickets` | Resident | `tickets:read` | — |
| [`tickets.store`](#ticketsstore) | POST | `/tickets` | Resident | `tickets:write` | yes |
| [`tickets.media`](#ticketsmedia) | POST | `/tickets/{ticket}/media` | Resident | `tickets:write` | yes |
| [`tickets.confirm_fixed`](#ticketsconfirm_fixed) | POST | `/tickets/{ticket}/fixed` | Resident | `tickets:write` | yes |
| [`tickets.comment`](#ticketscomment) | POST | `/tickets/{ticket}/comments` | Resident | `tickets:write` | yes |
| [`tickets.still_a_problem`](#ticketsstill_a_problem) | POST | `/tickets/{ticket}/reopen` | Resident | `tickets:write` | yes |
| [`notices.index`](#noticesindex) | GET | `/notices` | Resident | `notices:read` | — |
| [`notices.attachment`](#noticesattachment) | GET | `/notices/{notice}/attachments/{attachment}` | Resident | `notices:read` | — |
| [`notices.read`](#noticesread) | POST | `/notices/{notice}/read` | Resident | `notices:write` | yes |
| [`meetings.index`](#meetingsindex) | GET | `/meetings` | Resident | `meetings:read` | — |
| [`meetings.minutes`](#meetingsminutes) | GET | `/meetings/{meeting}/minutes` | Resident | `meetings:read` | — |
| [`meetings.rsvp`](#meetingsrsvp) | POST | `/meetings/{meeting}/rsvp` | Resident | `meetings:write` | yes |
| [`elections.index`](#electionsindex) | GET | `/elections` | Resident | `elections:read` | — |
| [`elections.eligibility`](#electionseligibility) | GET | `/elections/{ballot}/eligibility` | Resident | `elections:read` | — |
| [`elections.ballot`](#electionsballot) | POST | `/elections/{ballot}/ballot` | Resident | `elections:write` | yes |
| [`elections.results`](#electionsresults) | GET | `/elections/{ballot}/results` | Resident | `elections:read` | — |
| [`amenities.index`](#amenitiesindex) | GET | `/amenities` | Resident | `bookings:read` | — |
| [`bookings.quote`](#bookingsquote) | GET | `/amenities/{amenity}/quote` | Resident | `bookings:read` | — |
| [`bookings.book`](#bookingsbook) | POST | `/bookings/book` | Resident | `payments:write` | yes |
| [`bookings.index`](#bookingsindex) | GET | `/bookings` | Resident | `bookings:read` | — |
| [`bookings.pay_card`](#bookingspay_card) | POST | `/bookings/{booking}/pay/card` | Resident | `payments:write` | yes |
| [`bookings.store`](#bookingsstore) | POST | `/bookings` | Resident | `bookings:write` | yes |
| [`bookings.cancel`](#bookingscancel) | POST | `/bookings/{booking}/cancel` | Resident | `bookings:write` | yes |

---

## 8. Endpoints

### `devices.enrol`

Bind this handset to the guard an enrolment code was issued to, and receive its token. A guard who already has a handset gets a rebind request a supervisor must approve instead — unless it is the same handset under a new install. A USED code is accepted again only from the handset it set up, for the same guard, while that binding is still theirs: a RECOVERY after a reinstall, audited as one and mailed to the Heads of Security. On any other handset a used code stays dead.

| | |
|---|---|
| Method and path | `POST /api/v1/devices/enrol` |
| App | Guard, before enrolment — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `201` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `enrolment_code` | string · required · the one-time code Gemini's office gave the guard |
| `device_uid` | string · required · a stable identifier for this install, max 120 |
| `platform` | string · required · `ios` or `android` |
| `public_key` | string · required · the handset's Ed25519 public key, base64url (32 bytes) |
| `label` | string · optional · what the handset is, e.g. "iPhone 15 Pro · Main Gate" |
| `hardware_id` | string · optional · the handset's own identity, which a reinstall of the app keeps (Android's per-app device id), max 128. Stored only as a hash; without it the phone cannot recover after a reinstall |

**Response** `201`

| Key | Meaning |
|---|---|
| `status` | `enrolled`, or `pending_approval` for a rebind |
| `recovered` | true when a used code took back the binding this same handset already had (a recovery, not a new enrolment) |
| `reviewer` | true ONLY for an App Store / Play reviewer's demonstration account, which the SERVER flags. A handset told true may accept the phone's PASSCODE in place of a fingerprint or face at clock-in. False for every real guard, and no build flag turns it on. |
| `token` | the bearer token — shown once, store it in the secure enclave. Absent while pending. |
| `abilities.*` | the abilities the token carries, from the app matrix. Absent while pending. |
| `guard.id` | the guard |
| `guard.name` | their name, to confirm on screen |
| `guard.employee_number` | GS-1041 |
| `site.id` | the estate the guard is posted to |
| `site.name` | its name |
| `site.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `rebind_request_id` | while pending: the request to poll |
| `claim_secret` | while pending: present this to collect the token once approved. Shown once. |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `enrolment_code_invalid` | The code is unknown, expired, or used — and this is not the handset it set up. One sentence for every case. |
| 403 | `guard_not_active` | The code's guard is suspended or no longer employed. |
| 422 | `public_key_invalid` | The public key is not 32 base64url-encoded bytes. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only. Enrolment happens once, at the office.

### `devices.enrol.status`

Poll a pending rebind, and collect the token once a supervisor approves it. The previous handset's token stops working at that moment.

| | |
|---|---|
| Method and path | `POST /api/v1/devices/enrol/{rebind}/collect` |
| App | Guard, before enrolment — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |
| Path parameters | `rebind` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `claim_secret` | string · required · from the enrol response |
| `device_uid` | string · required · the same UID the request was made with |

**Response** `200`

| Key | Meaning |
|---|---|
| `status` | `pending_approval`, `approved` (token below, once), `denied`, or `collected` |
| `token` | present only on the first poll after approval |
| `abilities.*` | with the token |
| `decision_note` | the supervisor's note, when denied |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such request, or the claim secret or device UID does not match. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only.

### `devices.challenge`

A one-time challenge for a bound phone to sign before `devices.resume`: two minutes, one use. Asking again replaces it. It says nothing about whether the install is bound.

| | |
|---|---|
| Method and path | `POST /api/v1/devices/challenge` |
| App | Guard, before enrolment — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `device_uid` | string · required · the same stable id for this install that `devices.enrol` was sent |

**Response** `200`

| Key | Meaning |
|---|---|
| `challenge` | base64url, 32 bytes — sign "gs-resume-v1\|<device_uid>\|<challenge>" with the handset's Ed25519 key |
| `expires_at` | when it stops working (UTC) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only. Signing back in is now or not at all.

### `devices.resume`

A bound phone signing back in without a code: the handset's own signature over the challenge, and how the person was confirmed — `biometric` (the phone matched the guard) or `supervisor_pin` (checked here, as at clock-in). `check` only asks whether this install is still bound and issues nothing. A new token replaces the guard's old ones; the push address moves to it.

| | |
|---|---|
| Method and path | `POST /api/v1/devices/resume` |
| App | Guard, before enrolment — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `device_uid` | string · required · this install's id |
| `challenge` | string · required · from `devices.challenge` |
| `signature` | string · required · Ed25519, base64url (64 bytes), over "gs-resume-v1\|<device_uid>\|<challenge>" |
| `method` | string · required · `check`, `biometric` or `supervisor_pin` |
| `supervisor` | string · with `supervisor_pin` · the supervisor's sign-in address |
| `pin` | string · with `supervisor_pin` · their six-digit PIN. Sent once; never stored on the phone |
| `hardware_id` | string · optional · the handset's own identity, as sent to `devices.enrol`. Recorded, hashed, on a binding that has none once the signature proves the install, so the handset can recover after a reinstall |

**Response** `200`

| Key | Meaning |
|---|---|
| `state` | `bound` (a check) or `resumed` (token below) |
| `reviewer` | true ONLY for an App Store / Play reviewer's demonstration account, which the SERVER flags. False for every real guard. |
| `token` | the new bearer token, when resumed — store it in the secure enclave |
| `abilities.*` | with the token |
| `guard.id` | the guard |
| `guard.name` | their name, to greet on screen |
| `guard.employee_number` | GS-1041 |
| `site.id` | the estate the guard is posted to |
| `site.name` | its name |
| `site.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `challenge_expired` | The challenge is unknown, used, or more than two minutes old. Ask for a new one. |
| 403 | `device_not_enrolled` | No binding for this install, or the signature is not its key's: the phone has not been set up. A code from the office sets it up. |
| 403 | `device_removed` | This install was bound and has been taken out of service; the message says when, and whether by a supervisor, the office, or the same handset recovering under a new install. `error.details.removed_at`, `error.details.removed_by` (`supervisor`, `office` or `guard`). |
| 403 | `guard_not_active` | The guard is suspended or no longer employed. |
| 403 | `override_refused` | The supervisor and PIN were not accepted — one sentence for every kind of no, as at clock-in. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only. The phone says so, and the emergency numbers need no data.

### `auth.sign_out`

Sign out: destroy the token this request was made with, on the SERVER. Call it LAST, after `DELETE /push/token`, and then drop the token from the keychain. It ends this sign-in and nothing else — not the account, not another phone. Signing out twice answers 401: the second attempt has no token to present, and that is the truth rather than an error. A session that has timed out (`session_expired`) is already over; clear the phone and say so.

| | |
|---|---|
| Method and path | `DELETE /api/v1/session` |
| App | Guard + Resident — pending accounts too |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `signed_out` | boolean — always true; this token is destroyed and will never authenticate again |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Online only, and never queued — it names the very token that is about to be dropped, so a copy kept for later could never be sent at all. Best effort: sign the person out of the phone either way, say plainly that the server could not be told, and note that the session ends by itself inside the idle window.

### `push.register`

Register this install's Expo push token so the server can reach the app when it is CLOSED, with the notification categories switched off on this phone. Idempotent: registering the same token again moves it to whoever is signed in now, so a phone handed on stops receiving the last person's alerts. Register again when the token rotates or a switch changes. The token is never echoed back.

| | |
|---|---|
| Method and path | `POST /api/v1/push/token` |
| App | Guard + Resident — pending accounts too |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `token` | string · required · `ExponentPushToken[…]` or `ExpoPushToken[…]`, from the Expo SDK |
| `platform` | string · required · `ios` or `android` |
| `muted` | array of strings · optional · the categories switched off ON THIS PHONE. Guard App: `messages`, `orders`, `shifts`, `requests`, `payslips`. Resident App: `visitors`, `notices`, `maintenance`, `payments`, `meetings`, `voting`, `bookings`. Anything else is dropped — panic, duress, an alertness check and somebody at the gate cannot be muted |
| `quiet` | object · optional · QUIET HOURS on this phone (board 20; M50 §4.2 #5): `{"on": true, "from": "22:00", "to": "07:00"}`, HH:MM on the ESTATE's clock — `to` earlier than `from` runs over midnight. Inside them nothing is pushed to this phone except the urgent types (panic, somebody at the gate). Left out, `on` false, or unreadable: no quiet hours. NEVER refused, like `ring` |
| `ring` | object · optional, Guard App only · whether this phone can ring like a call, read by its ring module: `{"v": 1, "configured": true, "notifications": "on\|off", "ring_channel": "on\|off", "full_screen": "on\|off\|not_needed", "dnd_bypass": "on\|off\|unknown", "battery": "exempt\|optimised\|unknown", "background": "allowed\|restricted\|unknown", "alarm_volume": "ok\|low\|off", "alarm_tone": "set\|none", "screen_lock": "on\|off", "sdk": 35, "maker": "samsung"}`. NEVER refused: an unknown key is dropped and an unknown value read as unknown. Leave it out (an older app, iOS, Expo Go) and this phone is sent the ordinary alert every round. Send it again whenever it changes |

**Response** `200`

| Key | Meaning |
|---|---|
| `registered` | boolean — always true |
| `muted.*` | the categories the server will NOT push to this phone |
| `always_on.*` | the notification types no switch can silence, for the settings screen to say so |
| `quiet` | the quiet hours the server will keep for this phone — `{from, to}` — or null for none |
| `ring_state` | the SERVER's reading of `ring`: `ready` (rings like a call), `partial` (rings, but Do Not Disturb bypass, battery, background, alarm volume or screen lock is not right), `not_ready` (notifications, the ring channel, full-screen alerts, the alarm volume or tone is off, or unknown), or null when no `ring` was sent |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `push_token_invalid` | Not an Expo push token. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only, and never queued: try again at the next start. The local notifier covers the app while it is open.

### `push.unregister`

Sign-out: stop pushing to the phone this API token registered. Call it BEFORE dropping the API token. The same answer whether or not anything was registered. (A handset revoked, an account suspended or a household member removed loses its push addresses on the server's side without this call.)

| | |
|---|---|
| Method and path | `DELETE /api/v1/push/token` |
| App | Guard + Resident — pending accounts too |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `registered` | boolean — always false: nothing is pushed to this sign-in's phone any more |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only, and never queued — the API token it names is deleted the moment after it is sent, so a copy kept for later could never be sent at all. Best effort: if it cannot be sent the app gives up the phone's device token instead, and the address moves to whoever registers on this phone next.

### `live.config`

Where the live-update socket is, and the channels this handset holds. The socket's HOST is the API URL's own host — it is never sent — and it is reached at `ws.scheme`/`ws.port` with the Pusher protocol, key `ws.key`. Subscribe each channel as `private-live.{name}`, authorised by `live.auth`, and the public `pulse` channel with no authorisation. Ask again after `access.changed`, after a claim is approved, and when the list is more than ten minutes old.

| | |
|---|---|
| Method and path | `GET /api/v1/live/config` |
| App | Guard + Resident — pending accounts too |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `ws.key` | the socket application key — public by design, it identifies the application and authorises nothing |
| `ws.port` | the socket port on the API URL's host: 443 in production |
| `ws.scheme` | `https` (connect with TLS, `wss`) or `http` (a development server, `ws`) |
| `ws.path` | the path before `/app/{key}`; empty |
| `channels.*.name` | a channel this handset holds, e.g. `guard.{estate}.{guard}`; subscribe to it as `private-live.{name}` |
| `channels.*.streams.*` | `c` (the platform's log) and/or `e` (the estate's): keep one catch-up cursor per channel and stream |
| `pulse` | the public heartbeat channel, `live-pulse`, carrying `{at, build}` every ten seconds; no pulse for 25 s means live updates are not working |
| `build` | the server's build, or null |
| `principal` | this handset's key, `g-{guard}` or `r-{account}` — the `user_id` every grant carries |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 503 | `live_unavailable` | This server is not configured for live updates. Keep refreshing as before; say that live updates are paused. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Online only. With no answer, keep polling as the screen always has, and show that live updates are paused.

### `live.auth`

Grants for live channels, bound to one socket. Send every channel the socket is about to subscribe to in ONE request (a batch counts once against the limit). Each is answered in the order asked: signed, or refused — and a refusal says nothing about why, or whether what it names exists. Never sign a channel yourself; subscribe with exactly the `auth` and `channel_data` returned.

| | |
|---|---|
| Method and path | `POST /api/v1/live/auth` |
| App | Guard + Resident — pending accounts too |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `live-auth` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `socket_id` | string · required · the socket id the connection was given, `{digits}.{digits}` |
| `channel_names` | array of strings · required · 1 to 200 channels, each as subscribed: `private-live.{name}` |

**Response** `200`

| Key | Meaning |
|---|---|
| `channels.*.name` | the channel, exactly as asked |
| `channels.*.auth` | the grant to subscribe with, `{key}:{signature}`; null when refused |
| `channels.*.channel_data` | the JSON string to subscribe with, `{"user_id":"g-5"}`; null when refused |
| `channels.*.streams.*` | `c` and/or `e`: the cursors this channel keeps; empty when refused |
| `channels.*.refused` | true when this handset may not hold the channel |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 503 | `live_unavailable` | This server is not configured for live updates. Keep refreshing as before; say that live updates are paused. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Online only, and never queued: a grant is for one socket, and a socket exists only while there is signal.

### `live.catch_up`

Everything a channel missed after its cursor, read from the server's log, so nothing is ever lost to a dropped socket. Ask on every subscription, every reconnect and every return to the foreground, when a message's `p` is ahead of the cursor, on `live.nudge`, and on a timer while the socket is down (this IS the live update then). Apply each channel's events in order, then take its `head` as the cursor; on `reset`, refetch the screens that channel feeds and take the head. The first cursor for a stream is the LOWEST `X-Live-Head` seen since the socket last connected. Every channel is decided again; one this handset may not hold is `refused`, whatever it names.

| | |
|---|---|
| Method and path | `POST /api/v1/live/catch-up` |
| App | Guard + Resident — pending accounts too |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `live-catchup` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `channels` | array · required · 1 to 200 channels, each as `live.config` lists it (no `private-live.` prefix) |
| `channels.*.name` | string · required · the channel |
| `channels.*.c` | integer or null · the cursor on the platform stream: the last seq applied, or null for none yet |
| `channels.*.e` | integer or null · the cursor on the estate stream |
| `channels.*.ep.c` | string · optional · the platform stream's epoch the cursor belongs to (8 characters, from `ep` in an answer or envelope) |
| `channels.*.ep.e` | string · optional · the estate stream's epoch |

**Response** `200`

| Key | Meaning |
|---|---|
| `channels.*.name` | the channel, as asked |
| `channels.*.status` | `ok`, `refused` (this handset may not hold it — no events, and nothing said about why) or `reset` (the cursor is older than the server keeps, newer than its head, from another epoch, or too far behind: refetch the screen, then take the head) |
| `channels.*.events.*.v` | envelope version, 1 |
| `channels.*.events.*.s` | `c` or `e`: the stream this event is on |
| `channels.*.events.*.ep` | the stream's epoch, 8 characters; a different one than the cursor's means reset |
| `channels.*.events.*.q` | the event's sequence number on its stream, and the record's version |
| `channels.*.events.*.p` | the previous sequence number on THIS channel and stream, or null |
| `channels.*.events.*.t` | the event, `{entity}.{verb}`, e.g. `pass.revoked` |
| `channels.*.events.*.e` | the entity, e.g. `pass` |
| `channels.*.events.*.i` | the record's id, as a string |
| `channels.*.events.*.m` | `ref` (refetch that one record) or `inline` (its data is in `d`) |
| `channels.*.events.*.d` | inline only: the record as it is now; null means it is gone. Its keys are listed per event by the unit that makes it live |
| `channels.*.events.*.sum` | the event's safe summary; its keys are listed per event by the unit that makes it live |
| `channels.*.events.*.o` | the origin nonce of the write that caused it, or null; a handset never sends one |
| `channels.*.events.*.at` | when the change was recorded — UTC instant |
| `channels.*.events.*.sim` | true for a simulated alert's events |
| `channels.*.head.c` | the platform stream's head: the cursor to take after applying the events |
| `channels.*.head.e` | the estate stream's head |
| `channels.*.ep.c` | the platform stream's epoch, 8 characters |
| `channels.*.ep.e` | the estate stream's epoch |
| `channels.*.more` | true when the answer stopped short (2,000 events in all): ask again from the last event applied, and do not take the head yet |
| `relay.ok` | false when an event has waited more than 5 s to be published: live updates are delayed, say so |
| `relay.lag_ms` | how long, in milliseconds |
| `build` | the server's build, or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Online only, and never queued: it is asked again the moment there is signal, from the cursors the handset keeps.

### `alerts.store`

Raise a panic, duress, medical, fire or intrusion alert. A guard's duress and a resident's panic are the same event to dispatch.

| | |
|---|---|
| Method and path | `POST /api/v1/alerts` |
| App | Guard + Resident |
| Ability | `alerts:write` |
| Idempotency-Key | accepted, not required |
| X-Device-Time | accepted, not required |
| Success | `201` |
| Rate limit | `api-alerts` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `kind` | string · required · `panic`, `duress`, `medical`, `fire` or `intrusion` |
| `unit_reference` | string · optional · the unit the alert is AT, e.g. "Lot 47". A GUARD may name one — an intrusion at the lot they are standing at. On a RESIDENT token it is IGNORED and the token's own unit is used: the address on a panic is not the phone's to choose, and a wrong one sends people to a neighbour's door |
| `latitude` | number · optional |
| `longitude` | number · optional |
| `captured_offline` | boolean · optional · true when the alert was raised with no signal and is being sent now |
| `tenant_id` | string · optional, legacy · must equal the token's own estate when sent |
| `guard_id` | integer · optional, legacy · must equal the token's own guard when sent |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the alert |
| `status` | `open` |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | `tenant_id` or `guard_id` in the body names another estate or guard. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its Idempotency-Key and `captured_offline: true`; send the moment signal returns. The device time is the moment of the press. Also call the local emergency number.

### `passes.verify`

Legacy household verdict by household id. Superseded by `POST /gate/verify`, which verifies a signed pass; kept for the simulator.

| | |
|---|---|
| Method and path | `POST /api/v1/passes/verify` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-gate-events` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `household_id` | integer · required |
| `pass_category` | string · required · e.g. `guest` |

**Response** `200`

| Key | Meaning |
|---|---|
| `verdict` | `admit` or `restricted`; `deny` when the household is unknown |
| `tone` | `green`, `amber` or `red` |
| `headline` | what the guard is told |
| `detail` | one sentence, or null |
| `household` | the household's name |
| `unit` | the unit reference |
| `access_restricted` | boolean — the ONLY thing a guard is ever told about a household's standing |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `deny` | No such household at this estate (body carries `verdict: deny`). |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Not available offline. Use `POST /gate/verify` with a signed pass, which verifies on the handset.

### `gate_events.store`

Record what the guard decided at the gate, after the verdict. Legacy form of `/gate/entry`, `/gate/exit` and `/gate/override`.

| | |
|---|---|
| Method and path | `POST /api/v1/gate-events` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | accepted, not required |
| X-Device-Time | accepted, not required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `verdict` | string · required · `admit`, `deny`, `override` or `exit` |
| `category` | string · required · e.g. `Visitor`, `Contractor`, `Delivery` |
| `subject` | string · required · who or what arrived |
| `basis` | string · required · `QR pass`, `Pre-approved`, `Tag read`, `Guard decision`, or `Household declined` (a walk-up the household said no to: the denial is theirs) |
| `reason` | string · required for `override` |
| `post_id` | integer · optional · defaults to the guard's own post |
| `tenant_id` | string · optional, legacy · must equal the token's estate |
| `guard_id` | integer · optional, legacy · must equal the token's guard |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the gate event |
| `verdict` | as recorded |
| `occurred_at` | the server's time of the event — UTC instant; show in the site's zone (`timezone`) |
| `pass_based` | true when the basis was a platform-issued pass or approval |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A body field names another estate, guard or post. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its Idempotency-Key; the device time is when the guard decided.

### `shifts.clock_in`

Clock on — the start the PHONE CONFIRMED, recorded as `app`. A handset no longer chooses how a start is labelled: a start the phone could not confirm goes to `shifts.clock_in_override`, where a supervisor's PIN is checked and the shift is flagged. THE GEOFENCE IS JUDGED HERE (M12 §1), by the rule `shifts.preflight` uses, on the fix sent: a start sent while the guard is there that is outside, or has no position, is refused (409); one that waited in the queue is started at the time of the tap and flagged for review. The start is the tap on the SERVER's clock (`X-Device-Time` moved by the phone's error, from `X-Device-Sent-At`). The first clock-in is the one that happened; a replayed queue never moves it.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/clock-in` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | accepted, not required |
| X-Device-Time | accepted, not required |
| Success | `200` |
| Rate limit | `api-shift-clock` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `latitude` | number · optional · with `longitude` · where the phone was at the tap — judged against the fence and kept on the shift |
| `longitude` | number · optional |
| `accuracy_m` | integer · optional · the fix's accuracy in metres |
| `captured_run` | string · optional · the app's run id at the tap (`X-Device-Run` then). Sent by every build that judges the fence; without it the start is recorded as an older build's was, and never refused |
| `captured_mono_ms` | integer · optional · the app's steady clock at the tap (`X-Device-Mono` then): with the headers on the send, whether the phone's clock was changed while it waited |
| `geofence_distance_m` | integer · optional · an OLDER build's own distance from the post, stored as sent |
| `mock_location` | boolean · optional · the handset's own mock-location detection — recorded, flagged for the Head of Security, never refused alone |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the shift |
| `status` | `on_duty` after clock-in, `completed` after clock-out |
| `actual_start` | the server's record of when the shift started — the FIRST clock-in, however many were sent — UTC instant; show in the site's zone (`timezone`) |
| `actual_end` | when it ended, or null — UTC instant; show in the site's zone (`timezone`) |
| `mock_location_flag` | true when the handset reported a mocked location at clock-in; recorded, never refused |
| `geofence_outcome` | what the start was judged on (M12 §1): `inside`, `inside_margin`, `no_geofence` — or `outside` / `poor_fix` / `no_position` for a start that waited in the queue and is flagged; null for an older build's start |
| `review_reasons` | what a supervisor is shown about the start — empty when nothing |
| `review_reasons.*` | `mock_location`, `outside_at_capture`, `no_geofence`, `clock_disagrees` or `clock_changed` |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard at your own site. |
| 404 | `not_found` | No such shift — an id nobody has, and an id belonging to another estate, answer identically. |
| 409 | `outside_geofence` | Sent while the guard is there, and outside the rostered post's geofence. The message says how far and which way; the phone offers a fresh fix and the supervisor override (`outside_geofence`). |
| 409 | `position_unconfirmed` | Sent while the guard is there, with no position or one too inaccurate to judge. The phone offers a fresh fix and the supervisor override (`position_unconfirmed`). |
| 422 | `validation_failed` | A `method` other than `app` was sent. `manual` was the old silent unconfirmed start and is refused: an unconfirmed start is never accepted quietly. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its Idempotency-Key, the device time of the tap and the fix, judged first on the phone against the cached `shift.fence`. The first clock-in wins. Only ever captured with the identity CONFIRMED by the phone.

### `shifts.clock_out`

Clock off, with an optional handover note for the next shift.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/clock-out` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | accepted, not required |
| X-Device-Time | accepted, not required |
| Success | `200` |
| Rate limit | `api-shift-clock` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `handover_note` | string · optional · max 500 · read by whoever relieves the post |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the shift |
| `status` | `on_duty` after clock-in, `completed` after clock-out |
| `actual_start` | the server's record of when the shift started — the FIRST clock-in, however many were sent — UTC instant; show in the site's zone (`timezone`) |
| `actual_end` | when it ended, or null — UTC instant; show in the site's zone (`timezone`) |
| `mock_location_flag` | true when the handset reported a mocked location at clock-in; recorded, never refused |
| `geofence_outcome` | what the start was judged on (M12 §1): `inside`, `inside_margin`, `no_geofence` — or `outside` / `poor_fix` / `no_position` for a start that waited in the queue and is flagged; null for an older build's start |
| `review_reasons` | what a supervisor is shown about the start — empty when nothing |
| `review_reasons.*` | `mock_location`, `outside_at_capture`, `no_geofence`, `clock_disagrees` or `clock_changed` |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard at your own site. |
| 404 | `not_found` | No such shift — an id nobody has, and an id belonging to another estate, answer identically. |
| 409 | `shift_not_started` | No clock-in is recorded. Send the queued clock-in first. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue after the clock-in it follows; the sync batch preserves order.

### `standing_orders.index`

Every order set the guard works to: general orders and their post's. Superseded by `GET /sites/{site}/standing-orders/current`.

| | |
|---|---|
| Method and path | `GET /api/v1/standing-orders` |
| App | Guard |
| Ability | `orders:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `orders.*.id` | the order set |
| `orders.*.title` | its title |
| `orders.*.version` | the current version |
| `orders.*.effective_on` | YYYY-MM-DD |
| `orders.*.body` | the full text |
| `orders.*.requires_acknowledgement` | true for the guard's post orders |
| `orders.*.acknowledged` | whether this guard has acknowledged THIS version |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Cache the last response and show it; an acknowledgement made offline is queued.

### `passes.keys`

The site's Ed25519 PUBLIC keys, every version a still-valid pass may carry. Cache them: they are what verifies a pass with no signal.

| | |
|---|---|
| Method and path | `GET /api/v1/sites/{site}/pass-keys` |
| App | Guard |
| Ability | `gate:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `site` matches `[a-z0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `site_id` | the site |
| `algorithm` | `Ed25519` |
| `keys.*.version` | the key version a pass payload names |
| `keys.*.public_key` | base64url, 32 bytes |
| `keys.*.current` | true for the version new passes are signed with |
| `refresh_after` | fetch again after this time — UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A site other than the guard's own. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |

**Offline** — Serve from cache. Refresh at every sync; a key the handset has never seen verifies as `unknown_key`.

### `shifts.me.current`

The dashboard: the shift the guard is on, or the next one rostered to them. Board guard-app-01.

| | |
|---|---|
| Method and path | `GET /api/v1/shifts/me/current` |
| App | Guard |
| Ability | `shifts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `shift` | null when nothing is running or rostered ahead |
| `shift.id` | the shift |
| `shift.status` | `rostered`, `open`, `on_duty`, `completed` or `missed` |
| `shift.post.id` | the post |
| `shift.post.name` | e.g. "Main Gate" |
| `shift.site.id` | the estate |
| `shift.site.name` | its name |
| `shift.site.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `shift.rostered_start` | UTC instant; show in the site's zone (`timezone`) |
| `shift.rostered_end` | UTC instant; show in the site's zone (`timezone`) |
| `shift.actual_start` | the first clock-in the server recorded, or null — UTC instant; show in the site's zone (`timezone`) |
| `shift.actual_end` | the clock-out, or null — UTC instant; show in the site's zone (`timezone`) |
| `shift.on_break` | true while a break is open |
| `shift.break_minutes` | breaks taken on this shift so far |
| `shift.previous_handover_note` | what the last guard on this post left for the next, or null |
| `shift.orders_to_acknowledge` | how many of the post's order sets this guard has not acknowledged at their current version |
| `shift.colleagues.*.name` | another guard on duty at the same estate now |
| `shift.colleagues.*.post` | their post |
| `shift.fence.source` | `post` (the rostered post's own), `estate` (the post has none, so the estate's) or `none` (neither is placed) |
| `shift.fence.name` | the post's name, or the estate's when its fence stands in |
| `shift.fence.latitude` | degrees north — the fence's centre, or null when `source` is `none` |
| `shift.fence.longitude` | degrees east — the fence's centre, or null |
| `shift.fence.radius_m` | metres, or null |
| `shift.fence.good_accuracy_m` | metres — the accuracy a phone takes fixes until it reaches (50) |
| `shift.fence.poor_accuracy_m` | metres — past this the position is not established (200) |
| `shift.fence.max_margin_m` | metres — the most margin a fix earns (50) |
| `shift.fence.margin_per_accuracy` | how many times the fix's accuracy the margin is, up to `max_margin_m` (2: a phone's accuracy is its 68% radius; twice it is about 95%) |
| `shift.supervisor_phone` | the number a guard at the gate calls for a supervisor, set per estate in Sites & geofences — or null, when none is set |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Show the cached response with its age. Clock-in works offline against the cached `fence`, by the same rule as `shifts.preflight`; this screen reflects it after the next sync.

### `shifts.me`

My hours: the guard's shifts in a range, with hours worked and night hours. Board guard-app-05. Hours, never pay.

| | |
|---|---|
| Method and path | `GET /api/v1/shifts/me` |
| App | Guard |
| Ability | `shifts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `from` | date · required (query) · YYYY-MM-DD |
| `to` | date · required (query) · on or after `from`, at most 31 days later |

**Response** `200`

| Key | Meaning |
|---|---|
| `from` | YYYY-MM-DD — a calendar date at the guard's site, as asked for; not an instant |
| `to` | YYYY-MM-DD — a calendar date at the guard's site, as asked for; not an instant |
| `timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `items.*.id` | the shift |
| `items.*.status` | `rostered`, `open`, `on_duty`, `completed` or `missed` |
| `items.*.post.id` | the post |
| `items.*.post.name` | e.g. "Main Gate" |
| `items.*.site.id` | the estate |
| `items.*.site.name` | its name |
| `items.*.site.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `items.*.rostered_start` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.rostered_end` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.actual_start` | the first clock-in the server recorded, or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.actual_end` | the clock-out, or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.worked_hours` | clock-in to clock-out less breaks, two decimals |
| `items.*.night_hours` | the part of that between 22:00 and 06:00 ON THE SITE'S CLOCK (the shift's `site.timezone`) |
| `totals.worked_hours` | sum |
| `totals.night_hours` | sum |
| `totals.shifts` | count |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `range_too_long` | More than 31 days asked for at once. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cached range.

### `shifts.preflight`

Before clock-in: the window, the handset binding, the geofence and location integrity — boards guard-app-01 and -10. The geofence is the ROSTERED POST's, else the estate's, else none (M12 §1): the fix is a circle, its accuracy counted as margin up to `fence.max_margin_m`; past `fence.poor_accuracy_m` the position is not established. `shifts.clock_in` judges the same way and refuses a live start this says is not allowed. The coordinates are compared and not stored.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/preflight` |
| App | Guard |
| Ability | `shifts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `shift` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `latitude` | number · optional · omit when location is refused |
| `longitude` | number · optional |
| `accuracy_m` | integer · optional · the fix's accuracy in metres |
| `mock_location` | boolean · optional · the OS reports a mocked location |
| `device_uid` | string · optional · the enrolled install UID |

**Response** `200`

| Key | Meaning |
|---|---|
| `shift_id` | the shift |
| `allowed` | false when a check blocks the start: outside the fence, no position established, the window, the binding. `shifts.clock_in` refuses the same start while the guard is there |
| `flagged_for_review` | true when the location looks mocked — clock-in proceeds and the Head of Security is shown it |
| `distance_m` | metres from the fence's centre, or null |
| `radius_m` | the fence's radius, or null when there is no fence |
| `within_geofence` | true inside (or inside within the margin), false outside or with no position sent, null when no fence is configured or the fix is too inaccurate to judge — read `outcome` for which |
| `outcome` | `inside` · `inside_margin` (outside the radius by less than the fix's accuracy) · `outside` · `poor_fix` (accuracy worse than `fence.poor_accuracy_m`) · `no_position` · `no_geofence` (neither the post nor the estate is placed; the start is allowed and marked for the office) |
| `fence.source` | `post` (the rostered post's own), `estate` (the post has none, so the estate's) or `none` (neither is placed) |
| `fence.name` | the post's name, or the estate's when its fence stands in |
| `fence.latitude` | degrees north — the fence's centre, or null when `source` is `none` |
| `fence.longitude` | degrees east — the fence's centre, or null |
| `fence.radius_m` | metres, or null |
| `fence.good_accuracy_m` | metres — the accuracy a phone takes fixes until it reaches (50) |
| `fence.poor_accuracy_m` | metres — past this the position is not established (200) |
| `fence.max_margin_m` | metres — the most margin a fix earns (50) |
| `fence.margin_per_accuracy` | how many times the fix's accuracy the margin is, up to `max_margin_m` (2: a phone's accuracy is its 68% radius; twice it is about 95%) |
| `beyond_m` | metres past the radius, or null |
| `accuracy_m` | the fix's accuracy as sent, or null |
| `margin_m` | how much of that accuracy was counted, or null |
| `bearing_deg` | which way the fence's centre lies FROM the guard, degrees clockwise from north, or null |
| `direction` | `north`, `north-east` … `north-west`: the same, in a word — or null |
| `override_reason` | when a supervisor's PIN may start this shift over the geofence: `outside_geofence` or `position_unconfirmed`; null otherwise |
| `supervisor_phone` | the number a guard at the gate calls for a supervisor, or null when the estate has none set |
| `reviewer` | true ONLY for an App Store / Play reviewer's demonstration account. The geofence was then judged from the POST'S OWN surveyed coordinates rather than this handset's, and the substitution is written to the audit log every time. |
| `passcode_identity` | true ONLY for that same account: this phone may confirm the guard with its own PASSCODE instead of a fingerprint or face. False for everybody else, who still go to the supervisor override. |
| `checks.*.key` | `window`, `device`, `geofence` or `location_integrity` |
| `checks.*.passed` | boolean |
| `checks.*.detail` | the sentence to show |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard at your own site. |
| 404 | `not_found` | No such shift — an id nobody has, and an id belonging to another estate, answer identically. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Run the window and device checks on the handset against the cached shift, and the geofence against the cached `shift.fence` by the same rule; clock in and queue the fix with it.

### `shifts.break.start`

Start a break. A break already open is returned as it is (200).

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/break/start` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-shift-clock` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `201`

| Key | Meaning |
|---|---|
| `break_id` | the break |
| `shift_id` | its shift |
| `started_at` | the server's time the break started — UTC instant; show in the site's zone (`timezone`) |
| `ended_at` | when it ended, or null while it runs — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard at your own site. |
| 404 | `not_found` | No such shift — an id nobody has, and an id belonging to another estate, answer identically. |
| 409 | `shift_not_on_duty` | The guard is not clocked on to this shift. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time of the tap.

### `shifts.break.end`

End the open break.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/break/end` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-shift-clock` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `break_id` | the break |
| `shift_id` | its shift |
| `started_at` | the server's time the break started — UTC instant; show in the site's zone (`timezone`) |
| `ended_at` | when it ended, or null while it runs — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard at your own site. |
| 404 | `not_found` | No such shift — an id nobody has, and an id belonging to another estate, answer identically. |
| 409 | `no_break_open` | No break is open on this shift. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue after the break start it closes.

### `shifts.open`

Open shifts at the guard's estate in the next 14 days. Board guard-app-06. Hours, not pay.

| | |
|---|---|
| Method and path | `GET /api/v1/shifts/open` |
| App | Guard |
| Ability | `shifts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the open shift |
| `items.*.post.id` | the post |
| `items.*.post.name` | its name |
| `items.*.site.id` | the estate |
| `items.*.site.name` | its name |
| `items.*.site.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `items.*.rostered_start` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.rostered_end` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.hours` | length in hours |
| `items.*.claim_status` | null, or this guard's claim: `pending`, `approved`, `declined` |
| `items.*.rest_warning` | true when it falls within 11 hours of another of the guard's shifts |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Online only — an open shift is first come, and a cached list is stale.

### `shifts.claim`

Claim an open shift. A supervisor approves; the claim's status comes back on `/sync/pull`.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/claim` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `201`

| Key | Meaning |
|---|---|
| `claim_id` | the claim |
| `shift_id` | the shift |
| `status` | `pending` |
| `claimed_at` | UTC instant; show in the site's zone (`timezone`) |
| `requires_approval` | always true |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such shift at the guard's estate. |
| 409 | `shift_not_open` | Already filled. |
| 409 | `shift_started` | Already started. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only.

### `shifts.clock_in_override`

Start the shift on a SUPERVISOR'S PIN when the phone could not confirm WHO is holding it — three failed matches (board guard-app-39), or no fingerprint or face set up — or WHERE: outside the rostered post's geofence, or no position established (M12 §1.3). The fix is judged by the same rule as `shifts.clock_in` and kept on the shift; a mocked location is flagged. The server checks the PIN, flags the shift, writes the audit log and tells the Head of Security. Never resend on a refusal; use a NEW Idempotency-Key for each try. Never keep or log the PIN.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/clock-in/override` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | required · opaque (the body is not hashed) |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-supervisor-override` |
| Path parameters | `shift` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `supervisor` | string · required · the supervisor's console sign-in address (their email), as the office knows them |
| `pin` | string · required · the supervisor's 6-digit override PIN — typed, sent once, never stored on the phone |
| `reason` | string · required · `biometric_failed`, `no_biometrics`, `outside_geofence` or `position_unconfirmed` — the last two as `shifts.preflight.override_reason` said |
| `latitude` | number · optional · with `longitude`; judged against the fence and kept on the shift the PIN starts |
| `longitude` | number · optional |
| `accuracy_m` | integer · optional · the fix's accuracy in metres |
| `geofence_distance_m` | integer · optional · the phone's own distance from the post, used only when no position is sent |
| `mock_location` | boolean · optional · the OS reports a mocked location — recorded and flagged, never refused |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the shift |
| `status` | `on_duty` |
| `actual_start` | the server's record of when the shift started — UTC instant; show in the site's zone (`timezone`) |
| `actual_end` | null — UTC instant; show in the site's zone (`timezone`) |
| `mock_location_flag` | true when the handset reported a mocked location; recorded, never refused |
| `overridden` | true — this start was vouched for by a supervisor, not confirmed by the phone |
| `override_reason` | `biometric_failed`, `no_biometrics`, `outside_geofence` or `position_unconfirmed` |
| `overridden_at` | UTC instant; show in the site's zone (`timezone`) |
| `overridden_by` | the supervisor's name, to show the guard who vouched for them |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such shift, or a shift rostered to another guard — the two are the same answer. |
| 403 | `override_refused` | The supervisor and PIN were not accepted. ONE answer for an unknown address, somebody who is not a supervisor at this estate, no PIN set, a locked PIN and a wrong PIN. Five wrong tries lock the PIN until the supervisor sets a new one in the console. |
| 409 | `shift_already_started` | The shift has a start already. Nothing was recorded against a supervisor. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — a PIN cannot be checked without the server. Never queued, never in `sync/batch`, never stored. Offline: say so plainly, and tell the guard to move to signal or call their supervisor or the control room.

### `orders.current`

The orders in force for the guard's post and the company, each with its version id, and the guard's acknowledgement history. Boards guard-app-03 and -04.

| | |
|---|---|
| Method and path | `GET /api/v1/sites/{site}/standing-orders/current` |
| App | Guard |
| Ability | `orders:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `site` matches `[a-z0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `site_id` | the site |
| `items.*.set_id` | the order set |
| `items.*.version_id` | THE id to acknowledge |
| `items.*.version` | its number |
| `items.*.title` | title |
| `items.*.effective_on` | YYYY-MM-DD |
| `items.*.body` | the full text |
| `items.*.requires_acknowledgement` | true for the post's own orders |
| `items.*.acknowledged_at` | when this guard acknowledged THIS version, or null — UTC instant; show in the site's zone (`timezone`) |
| `history.*.set_id` | order set |
| `history.*.title` | its title |
| `history.*.version` | the version acknowledged |
| `history.*.acknowledged_at` | when — UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A site other than the guard's own. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |

**Offline** — Cache and show the cached text; queue acknowledgements.

### `orders.acknowledge`

Acknowledge the version the guard read, by its version id. A revision published meanwhile has a different id, so an old one is refused.

| | |
|---|---|
| Method and path | `POST /api/v1/standing-orders/{version}/acknowledge` |
| App | Guard |
| Ability | `orders:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-shift-clock` |
| Path parameters | `version` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `set_id` | the order set |
| `version_id` | the version acknowledged |
| `version` | its number |
| `acknowledged_at` | the first acknowledgement of this version, however many were sent — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such version. |
| 409 | `orders_changed` | Revised since, not the guard's post, or the guard cannot stand the post (licence lapsed). |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue. If a revision landed meanwhile the sync answers 409; show the new version from `/sync/pull`.

### `patrol.checkpoints`

The site's active patrol checkpoints in tour order, with what this guard has scanned today. Board guard-app-02.

| | |
|---|---|
| Method and path | `GET /api/v1/sites/{site}/checkpoints` |
| App | Guard |
| Ability | `patrol:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `site` matches `[a-z0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `site_id` | the site |
| `items.*.id` | the checkpoint |
| `items.*.label` | e.g. "Pool gate" |
| `items.*.sequence` | tour order |
| `items.*.post_id` | the post it belongs to, or null |
| `items.*.latitude` | degrees north where it stands, or null when it has not been placed on the map yet (M11 §6) |
| `items.*.longitude` | degrees east, or null with latitude |
| `items.*.last_scanned_at` | this guard's last scan today, or null — UTC instant; show in the site's zone (`timezone`) |
| `posts.*.id` | a post of this site that has been placed on the map |
| `posts.*.name` | e.g. "Main Gate" |
| `posts.*.latitude` | degrees north: its surveyed position |
| `posts.*.longitude` | degrees east: its surveyed position |
| `posts.*.radius_m` | metres: the geofence a guard clocks in within |
| `tour.total` | checkpoints |
| `tour.scanned_today` | distinct checkpoints this guard scanned today |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A site other than the guard's own. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |

**Offline** — Cache. The tag codes are not in this list — the tag itself carries its code.

### `patrol.scan`

Record a checkpoint scan (M21 §1.1). The code read from the QR or NFC tag must be the checkpoint's own, the guard must be on a shift at the moment the tag was reached, and a phone that says where it is must be within the checkpoint's radius of its placed position. A checkpoint scanned again inside the tour cadence is recorded as early.

| | |
|---|---|
| Method and path | `POST /api/v1/checkpoints/{checkpoint}/scan` |
| App | Guard |
| Ability | `patrol:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `checkpoint` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `code` | string · required · what the tag encodes |
| `captured_offline` | boolean · optional |
| `latitude` | number · optional · where the phone was when the tag was read; with `longitude` |
| `longitude` | number · optional · with `latitude` |
| `accuracy_m` | integer · optional · the phone's own estimate of how sure that position is, in metres |

**Response** `201`

| Key | Meaning |
|---|---|
| `scan_id` | the scan |
| `checkpoint_id` | the checkpoint |
| `label` | its label |
| `shift_id` | the shift the guard was standing when the tag was reached |
| `position` | `inside`, `inside_margin` (within the margin of the phone's reading), `no_position` (the phone sent none), `poor_fix` (too unsure to check) or `unplaced` (the checkpoint is not on the map yet). The last three are recorded, and said so in `note` |
| `distance_m` | metres from the checkpoint's placed position, or null when either is unknown |
| `early` | true when this guard scanned this checkpoint within the tour cadence before; recorded, and does not advance the tour |
| `next_due_at` | when the next round at this checkpoint is due — UTC instant; show in the site's zone (`timezone`) |
| `note` | one sentence to show beside "confirmed", or null: an early scan, or a position that could not be checked |
| `tour.total` | checkpoints |
| `tour.scanned_today` | distinct checkpoints scanned today |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such checkpoint at the guard's site. |
| 422 | `code_mismatch` | The tag read belongs to a different checkpoint, or to none. |
| 422 | `checkpoint_retired` | The checkpoint was taken off its route in Sites & geofences; its tag is not counted. Show the sentence. |
| 422 | `not_on_shift` | No shift of this guard's was running when the tag was reached. Show the sentence: clock in first. |
| 422 | `outside_checkpoint` | The phone was further from the checkpoint than its radius plus the margin of its own reading. The sentence gives the distance and the direction. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time of the scan and `captured_offline: true`.

### `alertness.respond`

Answer a random alertness check within two minutes. Board guard-app-04. The answer is the tap itself: no score, image or template is accepted or stored.

| | |
|---|---|
| Method and path | `POST /api/v1/alertness/{check}/respond` |
| App | Guard |
| Ability | `alertness:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `check` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `check_id` | the check |
| `outcome` | `pending`, `passed`, `missed`, `failed` or `declined` |
| `issued_at` | UTC instant; show in the site's zone (`timezone`) |
| `respond_by` | two minutes after issue — UTC instant; show in the site's zone (`timezone`) |
| `responded_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such check for this guard. |
| 409 | `check_expired` | The two minutes ran out; the check is recorded missed. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only: a check answered after signal returns is late by definition. The sync records it as missed.

### `presence.activity`

Report on-post activity. Coordinates, when sent, are compared against the post's geofence and discarded — only `within_geofence` is kept. Answers with any pending alertness check.

| | |
|---|---|
| Method and path | `POST /api/v1/presence/activity` |
| App | Guard |
| Ability | `presence:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `state` | string · required · `on_post`, `patrolling`, `on_break` or `away` |
| `latitude` | number · optional |
| `longitude` | number · optional |
| `accuracy_m` | integer · optional |
| `battery_pct` | integer · optional · 0–100 |

**Response** `201`

| Key | Meaning |
|---|---|
| `ping_id` | the ping |
| `shift_id` | the shift on duty, or null |
| `state` | as sent |
| `within_geofence` | true, false, or null when it could not be decided |
| `pending_alertness_check` | null, or the check to answer now |
| `pending_alertness_check.check_id` | the check |
| `pending_alertness_check.outcome` | `pending`, `passed`, `missed`, `failed` or `declined` |
| `pending_alertness_check.issued_at` | UTC instant; show in the site's zone (`timezone`) |
| `pending_alertness_check.respond_by` | two minutes after issue — UTC instant; show in the site's zone (`timezone`) |
| `pending_alertness_check.responded_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Drop, do not queue: a stale presence report is not presence.

### `presence.summary`

The guard's own day: minutes on post, breaks, checkpoints, incidents, alertness. Board guard-app-04.

| | |
|---|---|
| Method and path | `GET /api/v1/guards/me/activity-summary` |
| App | Guard |
| Ability | `presence:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `date` | YYYY-MM-DD |
| `shift_id` | today's shift, or null |
| `on_post_minutes` | on duty less breaks |
| `break_minutes` | breaks |
| `checkpoints_scanned` | distinct today |
| `checkpoints_total` | active at the site |
| `incidents_filed` | today |
| `alertness_passed` | today |
| `alertness_missed` | today |
| `last_activity_at` | last presence report, or null — UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cached summary with its age.

### `tracking.status`

Whether on-duty location sharing is on, which policy the officer must be shown, their answer to it, and how the phone should sample. Asked at sign-in and before every clock-in.

| | |
|---|---|
| Method and path | `GET /api/v1/guards/me/tracking` |
| App | Guard |
| Ability | `presence:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `enabled` | true when on-duty location sharing is switched on for this service. When false the app asks for nothing and starts nothing |
| `policy_version` | the version of the location policy the app must show before asking — its words are the app's own (features/tracking); a different version means ask again |
| `consent` | this officer's answer to THIS version, or null when they have not answered it |
| `consent.decision` | `granted` or `refused` |
| `consent.policy_version` | the version answered — always the current one |
| `consent.decided_at` | when — UTC instant; show in the site's zone (`timezone`) |
| `retention_days` | integer: how many days a position is kept before it is deleted |
| `sampling.interval_s` | seconds between fixes while moving |
| `sampling.distance_m` | metres of movement that take a fix sooner |
| `sampling.stationary_interval_s` | seconds between fixes while standing still |
| `sampling.low_battery_pct` | 0–100: below this battery level, sample at the low-battery interval |
| `sampling.low_battery_interval_s` | seconds between fixes below that battery level |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Cache. With no answer on the phone for the current version, start nothing: a position the server has no consent for is refused.

### `tracking.consent`

The officer's answer to the location policy the phone showed. Append-only: a changed mind is a new answer. A refusal is recorded like a consent — the app goes on working and the control room shows "position not shared".

| | |
|---|---|
| Method and path | `POST /api/v1/guards/me/tracking/consent` |
| App | Guard |
| Ability | `presence:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `decision` | string · required · `granted` or `refused` |
| `policy_version` | string · required · the `policy_version` the phone showed |

**Response** `200`

| Key | Meaning |
|---|---|
| `enabled` | true when on-duty location sharing is switched on for this service. When false the app asks for nothing and starts nothing |
| `policy_version` | the version of the location policy the app must show before asking — its words are the app's own (features/tracking); a different version means ask again |
| `consent` | this officer's answer to THIS version, or null when they have not answered it |
| `consent.decision` | `granted` or `refused` |
| `consent.policy_version` | the version answered — always the current one |
| `consent.decided_at` | when — UTC instant; show in the site's zone (`timezone`) |
| `retention_days` | integer: how many days a position is kept before it is deleted |
| `sampling.interval_s` | seconds between fixes while moving |
| `sampling.distance_m` | metres of movement that take a fix sooner |
| `sampling.stationary_interval_s` | seconds between fixes while standing still |
| `sampling.low_battery_pct` | 0–100: below this battery level, sample at the low-battery interval |
| `sampling.low_battery_interval_s` | seconds between fixes below that battery level |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `policy_changed` | The policy changed after the phone showed it. Read `tracking.status` again, show the new words, and ask again. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only: nothing is shared until the server has the answer, so the consent screen says so and asks again with signal.

### `tracking.positions`

What the phone sampled on this shift, oldest first. Kept only while the shift is OPEN when the batch arrives, clocked in and not yet clocked out (D-232): a batch for a shift that is not open is refused whole, every fix with `no_open_shift`, and nothing in it is stored, a fix taken during the shift and sent after the clock-out included. Within an open shift a fix is kept from the clock-in on, on the SERVER's clock (each `captured_at` is corrected by the difference between X-Device-Time — the phone's clock when the batch is SENT — and the server's), only with the officer's consent to the current policy, and for `retention_days`. A fix from before the clock-in is refused, not stored; one resent with the same `id` is stored once.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/positions` |
| App | Guard |
| Ability | `presence:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `shift` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `positions` | array · required · 1–200 fixes, oldest first |
| `positions.*.id` | string · required · the phone's own id for the fix — a resent fix is stored once |
| `positions.*.latitude` | number · required · degrees north |
| `positions.*.longitude` | number · required · degrees east |
| `positions.*.accuracy_m` | integer · optional · metres |
| `positions.*.source` | string · required · `interval`, `movement`, `clock_in`, `checkpoint`, `gate`, `duress` or `clock_out` |
| `positions.*.battery_pct` | integer · optional · 0–100 |
| `positions.*.captured_at` | string · required · ISO 8601 with its offset: when the phone took the fix, on the phone's clock |

**Response** `200`

| Key | Meaning |
|---|---|
| `shift_id` | the shift |
| `accepted` | the ids kept, as a list (a fix already kept is listed again); empty when none was |
| `accepted.*` | a fix id the server kept — drop it from the phone |
| `refused` | the fixes not kept, each with the reason; drop them, do not resend; empty when none was |
| `refused.*.id` | the fix |
| `refused.*.code` | `no_open_shift` (the shift is not open: every fix of the batch, nothing stored), `outside_shift` (taken before the clock-in), `in_future` or `off_estate` (more than 5 km outside the estate's boundary) |
| `refused.*.message` | the sentence |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `tracking_off` | Location sharing is switched off for this service. Stop sampling. |
| 403 | `consent_required` | This officer has not agreed to the current policy. Stop sampling and show the policy. |
| 403 | `not_your_shift` | The shift is rostered to another guard at your own site. |
| 404 | `not_found` | No such shift — an id nobody has, and an id belonging to another estate, answer identically. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Keep the fixes on the phone with their capture time and send them when there is signal, in batches — also from the background while the app is closed. Send what is queued BEFORE the clock-out: once the shift is closed nothing more is kept, its last fix included. A fix is never shown as current: the map reads its age from `captured_at`.

### `tracking.presence_positions`

Guard live tracking (12 Oct 2026; docs/handoff/GUARD_TRACKING.md 5.1): a batch of readings, oldest first, answered one by one. Stored only while the guard is clocked in and not out when the batch ARRIVES: with no open shift every reading is rejected `no_open_shift` and nothing is stored, so send the queue before the clock-out. A key already stored is `duplicate`, stored once however often it is resent. A phone clock more than two minutes off the server's (X-Device-Time), or a reading timed more than two minutes ahead of it, is stored with `flag` for a supervisor, never refused and never corrected.

| | |
|---|---|
| Method and path | `POST /api/v1/presence/positions` |
| App | Guard |
| Ability | `presence:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `positions` | array · required · 1–200 readings, oldest first |
| `positions.*.idempotency_key` | string · required · made on the phone, once per reading, kept with it in the queue |
| `positions.*.captured_at` | string · required · ISO 8601 with its offset: when the phone took the reading, on the phone's clock |
| `positions.*.lat` | number · required · degrees north |
| `positions.*.lng` | number · required · degrees east |
| `positions.*.accuracy_metres` | integer · optional · metres |
| `positions.*.battery_percent` | integer · optional · 0–100 |
| `positions.*.is_moving` | boolean · optional · whether the phone judged the guard moving |
| `positions.*.source` | string · required · `foreground`, `background`, `significant_change` or `geofence` |
| `backlog` | object · optional · what the phone holds unsent, for the dispatcher (GUARD_TRACKING.md section 9) |
| `backlog.waiting` | integer · optional · readings held and not yet sent |
| `backlog.oldest_captured_at` | string · optional · ISO 8601: when the oldest of them was taken |
| `thinned` | object · optional · readings thinned out of a full queue |
| `thinned.count` | integer · optional · how many |
| `thinned.from` | string · optional · ISO 8601: the earliest thinned reading's time |
| `thinned.to` | string · optional · ISO 8601: the latest thinned reading's time |

**Response** `200`

| Key | Meaning |
|---|---|
| `shift_id` | the open shift the readings were kept on; null when there is none |
| `results` | one per reading, in the order sent |
| `results.*.idempotency_key` | the reading |
| `results.*.result` | `accepted` (stored: drop it from the queue), `duplicate` (already stored: drop it) or `rejected` (not stored: drop it, never resend) |
| `results.*.reason` | rejected only: `no_open_shift`, `before_shift` or `impossible_place` (more than 5 km outside the estate's boundary) |
| `results.*.message` | rejected only: the sentence for the guard |
| `results.*.flag` | accepted only, when it applies: `clock_ahead` or `clock_behind`, stored and flagged for a supervisor |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `tracking_off` | Location sharing is switched off for this service. Stop sampling. |
| 403 | `consent_required` | This officer has not agreed to the current wording. Stop sampling and show it. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue each reading with its key, in order; send in batches, oldest first, the moment there is signal, also from the background with the app closed. Never drop one to save space without telling the guard and the dispatcher.

### `tracking.my_positions`

What is recorded about this guard (GUARD_TRACKING.md 5.2): today's shift (the open one, or the last one ended today) and every reading stored for it. Nobody is tracked without being able to see what is recorded about them.

| | |
|---|---|
| Method and path | `GET /api/v1/presence/positions` |
| App | Guard |
| Ability | `presence:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `shift` | today's shift, or null when there is none |
| `shift.id` | the shift |
| `shift.clocked_in_at` | ISO 8601 |
| `shift.clocked_out_at` | ISO 8601, or null while it is open |
| `retention_days` | how many days positions are kept before they are deleted |
| `positions` | every reading stored for the shift, oldest first |
| `positions.*.captured_at` | when it was taken, as the phone sent it |
| `positions.*.lat` | degrees north |
| `positions.*.lng` | degrees east |
| `positions.*.accuracy_metres` | metres, or null |
| `positions.*.source` | where the reading came from |
| `positions.*.flag` | `clock_ahead`, `clock_behind`, or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Show the last answer with its age.

### `gate.verify`

Verify a signed pass (or its short code) online: signature, site, window, whether the holder is still a resident, cancellation, use, and the household's access restriction. Does NOT admit — record the entry after. `pass.category` `resident` is the resident's OWN e-pass, the credential a guard admits a RESIDENT on; when the estate office has ended that person's access the verdict is `revoked_resident` and the guard sends them to the Visitor Entrance (Q-M44). Board guard-app-07.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/verify` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-gate-events` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `pass` | string · required · the QR token (`payload.signature`), or the short code, e.g. `PPV2-4471` |

**Response** `200`

| Key | Meaning |
|---|---|
| `verdict` | `valid`, `restricted`, `revoked_resident` (the estate office ended this person's resident access — send them to the Visitor Entrance), `cancelled`, `already_used`, `expired`, `not_yet_valid`, `wrong_site`, `unknown_key`, `bad_signature`, `malformed` or `unknown_pass` |
| `tone` | `green`, `amber` (restricted) or `red` |
| `headline` | what the guard is told |
| `detail` | one sentence |
| `admit_allowed` | true only for `valid` |
| `pass` | null unless the pass is this estate's and on record |
| `pass.pass_id` | UUID |
| `pass.category` | `single`, `recurring`, `contractor`, `delivery` or `resident` |
| `pass.visitor_name` | who it is for |
| `pass.vehicle_plate` | or null |
| `pass.purpose` | or null |
| `pass.valid_from` | UTC instant; show in the site's zone (`timezone`) |
| `pass.valid_to` | UTC instant; show in the site's zone (`timezone`) |
| `pass.single_use` | boolean |
| `pass.status` | `active`, `used` or `cancelled` |
| `unit` | the unit reference, or null |
| `household` | the household's name, or null |
| `access_restricted` | boolean, or null when the pass itself failed — the ONLY thing a guard learns about a household's standing |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Verify on the handset with the cached public keys (`/sites/{site}/pass-keys`): the payload's eight fields and its Ed25519 signature, with no database. Cancellation and use are unknown offline; `/sync/pull` delivers the revoked list and `/gate/entry` reconciles.

### `gate.entry`

Record an admission — on a pass, on an approved walk-up request, or on the guard's own decision. A single-use pass is consumed here.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/entry` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `category` | string · required · e.g. `Visitor`, `Contractor`, `Delivery`, `Resident` |
| `pass_id` | string · optional · the pass admitted on |
| `approval_id` | integer · optional · an approved walk-up request |
| `subject` | string · required without a pass or approval · who came in |
| `verified_offline` | boolean · optional · the pass was verified on the handset with no signal |
| `post_id` | integer · optional · defaults to the guard's post |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the gate event |
| `verdict` | `admit`, `exit` or `override` |
| `basis` | `QR pass`, `QR pass · verified offline`, `Pre-approved`, `guard decision`, or `Override — {reason}` |
| `pass_based` | true when the basis was a platform pass or approval |
| `occurred_at` | the server's time of the event — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |
| `reconciliation` | null without a pass; else `consumed`, `valid`, or — offline only — `pass_cancelled`, `pass_already_used`, `pass_not_yet_valid` (admitted before its window opened; the pass is NOT used up), `pass_expired`, `household_restricted`, `pass_unknown`: tell the guard and dispatch sees it. The window is judged on the server's clock online, and offline on the admission's device time but never later than its arrival, with two minutes' grace each side as at `/gate/verify` |
| `access_restricted` | boolean, or null without a pass |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | An online entry on a pass or request that does not exist. |
| 409 | `pass_not_admissible` | Online, on a pass that is cancelled, used, not yet valid, expired or restricted. Use `/gate/override`. |
| 409 | `approval_not_granted` | The walk-up request is not approved. |
| 409 | `household_restricted` | Online, on an approved walk-up for a household whose visitors are held at the gate. Use `/gate/override`, with a reason. (Offline it is recorded and answered with `reconciliation: household_restricted`.) |
| 422 | `subject_required` | No pass, no approval and no subject. |
| 403 | `wrong_site` | A post at another estate. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with `verified_offline: true` and the device time of the admission. The server records it and answers `reconciliation`.

### `gate.exit`

Record a departure.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/exit` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `category` | string · required |
| `subject` | string · required |
| `pass_id` | string · optional |
| `post_id` | integer · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the gate event |
| `verdict` | `admit`, `exit` or `override` |
| `basis` | `QR pass`, `QR pass · verified offline`, `Pre-approved`, `guard decision`, or `Override — {reason}` |
| `pass_based` | true when the basis was a platform pass or approval |
| `occurred_at` | the server's time of the event — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A post at another estate. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time.

### `gate.override`

Admit against the system's advice, with a reason. Its own verdict on the gate log.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/override` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `category` | string · required |
| `subject` | string · required |
| `reason` | string · required · 5–160 characters |
| `pass_id` | string · optional |
| `post_id` | integer · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the gate event |
| `verdict` | `admit`, `exit` or `override` |
| `basis` | `QR pass`, `QR pass · verified offline`, `Pre-approved`, `guard decision`, or `Override — {reason}` |
| `pass_based` | true when the basis was a platform pass or approval |
| `occurred_at` | the server's time of the event — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A post at another estate. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time.

### `gate.search`

Find a unit or household. `access_restricted` is a boolean and the ONLY thing returned about a household's standing — no figure, no bucket, no wording implying money.

| | |
|---|---|
| Method and path | `GET /api/v1/gate/search` |
| App | Guard |
| Ability | `gate:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `unit` | string · required without `name` (query) · part of a unit reference |
| `name` | string · required without `unit` (query) · part of a household or resident name, 2+ characters |
| `phase` | string · optional (query) · one phase name from `guards.me` → `site.phases` |

**Response** `200`

| Key | Meaning |
|---|---|
| `total` | how many units match in the whole estate, which can be more than `items` carries |
| `limit` | how many `items` carries at most |
| `items.*.unit` | the unit reference |
| `items.*.household` | the household's name |
| `items.*.primary_resident` | the primary resident's name, or null |
| `items.*.access_restricted` | boolean |
| `items.*.notice` | a standing note the guard must read, or null. Today the only one: somebody whose Resident App access the estate office ended is no longer a resident of that unit and goes to the Visitor Entrance. Never money. |
| `items.*.expected_visitors.*.visitor_name` | an active pass valid today |
| `items.*.expected_visitors.*.category` | its category |
| `items.*.expected_visitors.*.valid_to` | UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Online only.

### `gate.activity`

Today's gate log at the guard's estate, newest first.

| | |
|---|---|
| Method and path | `GET /api/v1/gate/activity` |
| App | Guard |
| Ability | `gate:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `limit` | integer · optional (query) · 1–100, default 50 |

**Response** `200`

| Key | Meaning |
|---|---|
| `date` | YYYY-MM-DD |
| `counts.admitted` | today |
| `counts.exited` | today |
| `counts.overridden` | today |
| `counts.denied` | today |
| `items.*.id` | the event |
| `items.*.verdict` | verdict |
| `items.*.category` | category |
| `items.*.subject` | who |
| `items.*.basis` | on what |
| `items.*.pass_based` | boolean |
| `items.*.guard_name` | recorded by |
| `items.*.post_name` | at |
| `items.*.occurred_at` | server time — UTC instant; show in the site's zone (`timezone`) |
| `items.*.device_time` | handset time, or null — UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Show the cached log plus the queued events not yet synced, marked as such.

### `gate.approvals.store`

A walk-up visitor: record them and ask the household, who answers in the Resident App. Boards guard-app-08 and -02. No photo is stored.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/approvals` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `unit` | string · required · the unit reference |
| `visitor_name` | string · required |
| `id_type` | string · required (M18C) |
| `id_number` | string · required (M18C) |
| `purpose` | string · required (M18C) · 2–160 characters |
| `vehicle_plate` | string · required unless `no_vehicle` is true, and refused when it is |
| `no_vehicle` | boolean · "Arriving on foot or by taxi — no vehicle" (M18C §3.1): a plate, or this — never neither |

**Response** `201`

| Key | Meaning |
|---|---|
| `approval_id` | the walk-up request |
| `status` | `pending`, `approved`, `denied` or `expired` |
| `unit` | the unit |
| `household` | its household's name |
| `visitor_name` | as recorded |
| `requested_at` | UTC instant; show in the site's zone (`timezone`) |
| `respond_by` | three minutes after the request — UTC instant; show in the site's zone (`timezone`) |
| `responded_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `responded_by_name` | the resident who answered, or null |
| `access_restricted` | boolean — the household's visitors are held at the gate (the estate's arrears restriction, read as for a guest pass). An approval does not lift it: admitting is `gate.override`, with a reason (M21 §1.7). The ONLY thing a guard learns about the household's standing |
| `guidance` | the sentence to show the guard |
| `household_can_answer` | boolean — false when nobody in this household can answer in the Resident App (no active account with Member access); call them or apply the estate's policy. Absent on a server older than this release |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such unit. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — the household cannot be asked without signal. Apply the estate's policy.

### `gate.approvals.show`

Poll a walk-up request. Past `respond_by` with no answer it reads `expired`.

| | |
|---|---|
| Method and path | `GET /api/v1/gate/approvals/{approval}` |
| App | Guard |
| Ability | `gate:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `approval` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `approval_id` | the walk-up request |
| `status` | `pending`, `approved`, `denied` or `expired` |
| `unit` | the unit |
| `household` | its household's name |
| `visitor_name` | as recorded |
| `requested_at` | UTC instant; show in the site's zone (`timezone`) |
| `respond_by` | three minutes after the request — UTC instant; show in the site's zone (`timezone`) |
| `responded_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `responded_by_name` | the resident who answered, or null |
| `access_restricted` | boolean — the household's visitors are held at the gate (the estate's arrears restriction, read as for a guest pass). An approval does not lift it: admitting is `gate.override`, with a reason (M21 §1.7). The ONLY thing a guard learns about the household's standing |
| `guidance` | the sentence to show the guard |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such request. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Online only.

### `incidents.store`

File an incident report. It lands in the same register the console keeps. Board guard-app-03.

| | |
|---|---|
| Method and path | `POST /api/v1/incidents` |
| App | Guard |
| Ability | `incidents:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `kind` | string · required · e.g. "Attempted unauthorized access" |
| `severity` | string · required · `low`, `med` or `high` |
| `detail` | string · required · at least 20 characters |
| `location` | string · required (M18C) · where, in the officer's words — e.g. "back wall by Lot 63" |
| `latitude` | number · optional |
| `longitude` | number · optional |
| `occurred_at` | ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site's clock · optional · defaults to now; not in the future |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the incident |
| `kind` | as filed |
| `severity` | `low`, `med` or `high` |
| `status` | `open` or `resolved` |
| `detail` | the account |
| `location` | or null |
| `shift_id` | the shift on duty when filed, or null |
| `occurred_at` | UTC instant; show in the site's zone (`timezone`) |
| `resolution` | what was done, once resolved |
| `closed_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `media_count` | attachments |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `occurred_in_future` | `occurred_at` more than five minutes ahead of the server. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time; upload media after the report syncs, against the id it returns.

### `incidents.me`

The guard's own reports from the last 90 days.

| | |
|---|---|
| Method and path | `GET /api/v1/incidents/me` |
| App | Guard |
| Ability | `incidents:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the incident |
| `items.*.kind` | as filed |
| `items.*.severity` | `low`, `med` or `high` |
| `items.*.status` | `open` or `resolved` |
| `items.*.detail` | the account |
| `items.*.location` | or null |
| `items.*.shift_id` | the shift on duty when filed, or null |
| `items.*.occurred_at` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.resolution` | what was done, once resolved |
| `items.*.closed_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.media_count` | attachments |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cache plus queued reports.

### `incidents.media`

Attach a photo or video (multipart, field `file`). Stored privately and hashed on arrival.

| | |
|---|---|
| Method and path | `POST /api/v1/incidents/{incident}/media` |
| App | Guard |
| Ability | `incidents:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `incident` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `file` | file · required · JPEG, PNG, HEIC, MP4 or MOV, at most 50 MB |

**Response** `201`

| Key | Meaning |
|---|---|
| `media_id` | the attachment |
| `incident_id` | the incident |
| `filename` | as uploaded |
| `content_type` | as detected |
| `bytes` | size |
| `sha256` | hash of the stored bytes |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No incident of this guard's with that id. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue the file; upload after the incident has an id. Not batchable.

### `duress.store`

Duress (guard) or panic (resident). Dispatch sees it at once. Cancellable for ten seconds. Guard board guard-app-03; resident panic.

| | |
|---|---|
| Method and path | `POST /api/v1/duress` |
| App | Guard + Resident |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-alerts` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `mode` | string · optional · `silent` (default) or `audible` — the handset's behaviour only |
| `latitude` | number · optional |
| `longitude` | number · optional |
| `captured_offline` | boolean · optional |
| `type` | string · optional · `medical`, `fire`, `security` or `other` — NEVER required. The button works with nothing attached, because a person in a panic does not stop to categorise. `medical` and `fire` become the alert's `kind`; `security` and `other` are a plain `panic`, which is what the button has always meant. An unlabelled alert reaches exactly the same people. IGNORED ON A GUARD'S TOKEN: a guard's is always `duress`, which carries the silent-mode handling, and a compromised handset must not be able to relabel what it is. |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the alert |
| `kind` | `duress` from a guard. From a resident: `panic`, or `medical` / `fire` when they chose that `type` — `security`, `other` and no type at all are `panic` |
| `mode` | `silent` or `audible` |
| `status` | `open`, `acknowledged`, `responding`, `resolved` or `false_alarm` |
| `cancellable_until` | ten seconds after the server received it — UTC instant; show in the site's zone (`timezone`) |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `acknowledged_at` | when dispatch took it, or null — UTC instant; show in the site's zone (`timezone`) |
| `dispatched_at` | when the control room SENT an officer, or null. The first moment "help is on the way" is true — never say it from `acknowledged_at` — UTC instant; show in the site's zone (`timezone`) |
| `en_route_at` | when an officer was recorded as on the way (from their handset, or by dispatch after a radio call), or null. May stay null for good: an officer who ran straight there has `on_scene_at` only, and nothing is back-filled — draw the step as not recorded, never as done — UTC instant; show in the site's zone (`timezone`) |
| `on_scene_at` | when an officer was recorded as at the scene, or null — UTC instant; show in the site's zone (`timezone`) |
| `resolved_at` | when the alert was closed — resolved, a false alarm, or cancelled from the phone; `status` says which — or null — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time of the alert — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time of the press — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |
| `console_alerted` | true, always — the control room has this alert from the moment it is recorded, whoever is on shift |
| `alerted_handsets` | how many security HANDSETS the alert was sent to, or null while the fan-out is still running. 0 means none: say so plainly and put the emergency number first. NULL IS NOT 0 |
| `alerted_via` | which tier answered: `on_duty` (guards clocked on), `rostered` (nobody clocked on, so the officers rostered here today), `escalation` (no handset at all — the site's escalation contact was told off-app) or `none`, or null while it is still running |
| `escalated_contacts` | how many people were told OFF-APP — the site's escalation contacts, e-mailed on EVERY panic whether or not a handset was reached (M10 §1) — or null while it is still running. An email or a console bell, never a phone: it is never "help is coming" and never adds to `alerted_handsets` |
| `rang_handsets` | how many officer PHONES the call-style ring has been sent to so far, in any round — including the ring to every officer of the estate that goes at once when `alerted_handsets` is 0 (M18) — or null while the ring has not begun. Sent, not answered. When `alerted_handsets` is 0 and this is above 0, officers' phones ARE being rung: do not say "no officer's phone was reached" |
| `rang_at` | when the call-style ring was first sent to a phone (ISO 8601), or null when no round has been sent. The time the resident's "Officers alerted" line is ticked with when the ring is what alerted them (M18A) |
| `on_duty_officers` | how many officers were CLOCKED ON at the site when the alert was raised (the raiser not counted), or null on an older alert or while the fan-out is still running. With `alerted_handsets` 0 it tells "nobody is on duty" (0) from "officers are on duty but their phones could not be reached" (above 0). Null: claim neither (M18A §1.2) |
| `sms_recipients` | how many of the site's emergency numbers were ON FILE for this alert, or null while it is still running. NOT how many were texted — never write "N people texted" from it; show `sms.note` |
| `sms_delivered` | how many of those texts a PROVIDER ACCEPTED, or null while it is still running. 0 on every alert today — no SMS provider is bought yet, so the message is written down and delivered to nobody. NEVER add this to `alerted_handsets`: a text is not an officer being sent |
| `sms.provider_connected` | true only when an SMS provider can actually send a text right now. false on this platform today: the log driver writes a message down and delivers it to nobody |
| `sms.attempted` | how many numbers were handed to a REAL provider for this alert. A message written to the log, or a number nobody can dial, is not an attempt |
| `sms.delivered` | how many of those a provider ACCEPTED — the furthest the platform can honestly claim. Never a count of phones that rang |
| `sms.finished` | true once the texting has run to the end. Until then `attempted` and `delivered` are "so far" |
| `sms.note` | the one sentence to show about texts, e.g. "No text was sent: no SMS provider is connected." Show it as given; never build a claim about texts from the numbers |
| `is_drill` | true when this is a DRILL raised to test the fan-out, never by a handset. Show DRILL before anything else on the screen; false on every alert a person raises |
| `drill_mode` | true when the office had drill mode on for this account when it was pressed: a DRILL (`is_drill` is true too) that rang no officer, emailed and texted nobody and sounded no siren. The counts above stay null for good, so say that instead of waiting on them, and keep the emergency number one press away in case it is real. Absent on a server older than this release |
| `messages.*.id` | the message |
| `messages.*.from` | `you` (the resident) or `dispatch` (the control room) |
| `messages.*.name` | the dispatcher's first name on a reply, or null |
| `messages.*.body` | what was written |
| `messages.*.at` | when the server received it — UTC instant; show in the site's zone (`timezone`) |
| `messages.*.seen_at` | when the OTHER side was shown it — the console drew it (your message), or this phone read it back (a reply) — or null: "Sent" until then, "Delivered" after — UTC instant; show in the site's zone (`timezone`) |
| `messages.*.client_key` | on your own messages, the `client_key` the phone sent it with — how the phone matches a message it queued to the row; null on a reply |
| `sms.household` | null on an officer's duress — only a resident's panic texts a household. Otherwise the texts to the resident's own nominated emergency contacts (the first 5 on file), counted by the same rules as the fields above and never included in them |
| `sms.household.attempted` | how many of the household's contacts were handed to a REAL provider for this alert. Under the log driver this is 0: the message is written down and delivered to nobody |
| `sms.household.delivered` | how many of those a provider ACCEPTED — never "received": the carrier and the phone are downstream. Say it only as the provider accepting it |
| `sms.household.finished` | true once the household's texting has run to the end, including at once when the household has no contacts on file. Until then `attempted` and `delivered` are "so far" |
| `email` | the emails a panic sends |
| `email.household` | null on an officer's duress. Otherwise the emails to the household's own emergency contacts (the first 5) AND its members (M18C: every member with an email of their own, the raiser not included, up to 10), each address once, counted from the ledger |
| `email.household.recipients` | how many people this alert is emailing — contacts and members together; null until the fan-out reaches the leg. 0: nobody has an email address on file (or a simulated/demonstration alert) |
| `email.household.members_sent` | of `sent`, how many were to the household's own members (M18C §4.3) |
| `email.household.contacts_sent` | of `sent`, how many were to its emergency contacts |
| `email.household.sent` | how many the mail server ACCEPTED — never "received". The only number that may put "emailed" on the screen |
| `email.household.failed` | how many were refused after every retry, or whose address was gone by the time it was sent |
| `email.household.finished` | true once every recipient has an outcome. Until then `sent` is "so far" |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its key and `captured_offline: true`; send the moment any signal returns. Also call the local emergency number.

### `duress.cancel`

Cancel within ten seconds of the server receiving it, and before dispatch acknowledges.

| | |
|---|---|
| Method and path | `POST /api/v1/duress/{alert}/cancel` |
| App | Guard + Resident |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-alerts` |
| Path parameters | `alert` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `reason` | string · optional · why it was a false alarm, max 160 — kept on the alert's resolution note for dispatch |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the alert |
| `kind` | `duress` from a guard. From a resident: `panic`, or `medical` / `fire` when they chose that `type` — `security`, `other` and no type at all are `panic` |
| `mode` | `silent` or `audible` |
| `status` | `open`, `acknowledged`, `responding`, `resolved` or `false_alarm` |
| `cancellable_until` | ten seconds after the server received it — UTC instant; show in the site's zone (`timezone`) |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `acknowledged_at` | when dispatch took it, or null — UTC instant; show in the site's zone (`timezone`) |
| `dispatched_at` | when the control room SENT an officer, or null. The first moment "help is on the way" is true — never say it from `acknowledged_at` — UTC instant; show in the site's zone (`timezone`) |
| `en_route_at` | when an officer was recorded as on the way (from their handset, or by dispatch after a radio call), or null. May stay null for good: an officer who ran straight there has `on_scene_at` only, and nothing is back-filled — draw the step as not recorded, never as done — UTC instant; show in the site's zone (`timezone`) |
| `on_scene_at` | when an officer was recorded as at the scene, or null — UTC instant; show in the site's zone (`timezone`) |
| `resolved_at` | when the alert was closed — resolved, a false alarm, or cancelled from the phone; `status` says which — or null — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time of the alert — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time of the press — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |
| `console_alerted` | true, always — the control room has this alert from the moment it is recorded, whoever is on shift |
| `alerted_handsets` | how many security HANDSETS the alert was sent to, or null while the fan-out is still running. 0 means none: say so plainly and put the emergency number first. NULL IS NOT 0 |
| `alerted_via` | which tier answered: `on_duty` (guards clocked on), `rostered` (nobody clocked on, so the officers rostered here today), `escalation` (no handset at all — the site's escalation contact was told off-app) or `none`, or null while it is still running |
| `escalated_contacts` | how many people were told OFF-APP — the site's escalation contacts, e-mailed on EVERY panic whether or not a handset was reached (M10 §1) — or null while it is still running. An email or a console bell, never a phone: it is never "help is coming" and never adds to `alerted_handsets` |
| `rang_handsets` | how many officer PHONES the call-style ring has been sent to so far, in any round — including the ring to every officer of the estate that goes at once when `alerted_handsets` is 0 (M18) — or null while the ring has not begun. Sent, not answered. When `alerted_handsets` is 0 and this is above 0, officers' phones ARE being rung: do not say "no officer's phone was reached" |
| `rang_at` | when the call-style ring was first sent to a phone (ISO 8601), or null when no round has been sent. The time the resident's "Officers alerted" line is ticked with when the ring is what alerted them (M18A) |
| `on_duty_officers` | how many officers were CLOCKED ON at the site when the alert was raised (the raiser not counted), or null on an older alert or while the fan-out is still running. With `alerted_handsets` 0 it tells "nobody is on duty" (0) from "officers are on duty but their phones could not be reached" (above 0). Null: claim neither (M18A §1.2) |
| `sms_recipients` | how many of the site's emergency numbers were ON FILE for this alert, or null while it is still running. NOT how many were texted — never write "N people texted" from it; show `sms.note` |
| `sms_delivered` | how many of those texts a PROVIDER ACCEPTED, or null while it is still running. 0 on every alert today — no SMS provider is bought yet, so the message is written down and delivered to nobody. NEVER add this to `alerted_handsets`: a text is not an officer being sent |
| `sms.provider_connected` | true only when an SMS provider can actually send a text right now. false on this platform today: the log driver writes a message down and delivers it to nobody |
| `sms.attempted` | how many numbers were handed to a REAL provider for this alert. A message written to the log, or a number nobody can dial, is not an attempt |
| `sms.delivered` | how many of those a provider ACCEPTED — the furthest the platform can honestly claim. Never a count of phones that rang |
| `sms.finished` | true once the texting has run to the end. Until then `attempted` and `delivered` are "so far" |
| `sms.note` | the one sentence to show about texts, e.g. "No text was sent: no SMS provider is connected." Show it as given; never build a claim about texts from the numbers |
| `is_drill` | true when this is a DRILL raised to test the fan-out, never by a handset. Show DRILL before anything else on the screen; false on every alert a person raises |
| `drill_mode` | true when the office had drill mode on for this account when it was pressed: a DRILL (`is_drill` is true too) that rang no officer, emailed and texted nobody and sounded no siren. The counts above stay null for good, so say that instead of waiting on them, and keep the emergency number one press away in case it is real. Absent on a server older than this release |
| `messages.*.id` | the message |
| `messages.*.from` | `you` (the resident) or `dispatch` (the control room) |
| `messages.*.name` | the dispatcher's first name on a reply, or null |
| `messages.*.body` | what was written |
| `messages.*.at` | when the server received it — UTC instant; show in the site's zone (`timezone`) |
| `messages.*.seen_at` | when the OTHER side was shown it — the console drew it (your message), or this phone read it back (a reply) — or null: "Sent" until then, "Delivered" after — UTC instant; show in the site's zone (`timezone`) |
| `messages.*.client_key` | on your own messages, the `client_key` the phone sent it with — how the phone matches a message it queued to the row; null on a reply |
| `sms.household` | null on an officer's duress — only a resident's panic texts a household. Otherwise the texts to the resident's own nominated emergency contacts (the first 5 on file), counted by the same rules as the fields above and never included in them |
| `sms.household.attempted` | how many of the household's contacts were handed to a REAL provider for this alert. Under the log driver this is 0: the message is written down and delivered to nobody |
| `sms.household.delivered` | how many of those a provider ACCEPTED — never "received": the carrier and the phone are downstream. Say it only as the provider accepting it |
| `sms.household.finished` | true once the household's texting has run to the end, including at once when the household has no contacts on file. Until then `attempted` and `delivered` are "so far" |
| `email` | the emails a panic sends |
| `email.household` | null on an officer's duress. Otherwise the emails to the household's own emergency contacts (the first 5) AND its members (M18C: every member with an email of their own, the raiser not included, up to 10), each address once, counted from the ledger |
| `email.household.recipients` | how many people this alert is emailing — contacts and members together; null until the fan-out reaches the leg. 0: nobody has an email address on file (or a simulated/demonstration alert) |
| `email.household.members_sent` | of `sent`, how many were to the household's own members (M18C §4.3) |
| `email.household.contacts_sent` | of `sent`, how many were to its emergency contacts |
| `email.household.sent` | how many the mail server ACCEPTED — never "received". The only number that may put "emailed" on the screen |
| `email.household.failed` | how many were refused after every retry, or whose address was gone by the time it was sent |
| `email.household.finished` | true once every recipient has an outcome. Until then `sent` is "so far" |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this handset's alert. |
| 409 | `cancel_window_closed` | More than ten seconds. Call dispatch. |
| 409 | `already_acknowledged` | The control room has taken it — which is not the same as an officer coming. Call dispatch to stand it down. |
| 409 | `already_responding` | An officer is already on the way or at the scene (they took it from their own handset before the control room did). Call dispatch to stand them down. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Not possible offline: the alert has not reached anyone to cancel.

### `duress.show`

Read the caller's own alert back: has the control room acknowledged it, has an officer been dispatched, are they on the way, are they there, is it resolved (`acknowledged_at`, `dispatched_at`, `en_route_at`, `on_scene_at`, `resolved_at`). Poll while it is open; each step is also pushed as `panic_update` (a guard's AUDIBLE duress: `duress_update`; a silent one is never pushed) with `kind` `acknowledged`, `dispatched`, `en_route`, `on_scene` or `closed`. Resident board resident-app-06 (the panic timeline).

| | |
|---|---|
| Method and path | `GET /api/v1/duress/{alert}` |
| App | Guard + Resident |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `alert` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the alert |
| `kind` | `duress` from a guard. From a resident: `panic`, or `medical` / `fire` when they chose that `type` — `security`, `other` and no type at all are `panic` |
| `mode` | `silent` or `audible` |
| `status` | `open`, `acknowledged`, `responding`, `resolved` or `false_alarm` |
| `cancellable_until` | ten seconds after the server received it — UTC instant; show in the site's zone (`timezone`) |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `acknowledged_at` | when dispatch took it, or null — UTC instant; show in the site's zone (`timezone`) |
| `dispatched_at` | when the control room SENT an officer, or null. The first moment "help is on the way" is true — never say it from `acknowledged_at` — UTC instant; show in the site's zone (`timezone`) |
| `en_route_at` | when an officer was recorded as on the way (from their handset, or by dispatch after a radio call), or null. May stay null for good: an officer who ran straight there has `on_scene_at` only, and nothing is back-filled — draw the step as not recorded, never as done — UTC instant; show in the site's zone (`timezone`) |
| `on_scene_at` | when an officer was recorded as at the scene, or null — UTC instant; show in the site's zone (`timezone`) |
| `resolved_at` | when the alert was closed — resolved, a false alarm, or cancelled from the phone; `status` says which — or null — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time of the alert — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time of the press — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |
| `console_alerted` | true, always — the control room has this alert from the moment it is recorded, whoever is on shift |
| `alerted_handsets` | how many security HANDSETS the alert was sent to, or null while the fan-out is still running. 0 means none: say so plainly and put the emergency number first. NULL IS NOT 0 |
| `alerted_via` | which tier answered: `on_duty` (guards clocked on), `rostered` (nobody clocked on, so the officers rostered here today), `escalation` (no handset at all — the site's escalation contact was told off-app) or `none`, or null while it is still running |
| `escalated_contacts` | how many people were told OFF-APP — the site's escalation contacts, e-mailed on EVERY panic whether or not a handset was reached (M10 §1) — or null while it is still running. An email or a console bell, never a phone: it is never "help is coming" and never adds to `alerted_handsets` |
| `rang_handsets` | how many officer PHONES the call-style ring has been sent to so far, in any round — including the ring to every officer of the estate that goes at once when `alerted_handsets` is 0 (M18) — or null while the ring has not begun. Sent, not answered. When `alerted_handsets` is 0 and this is above 0, officers' phones ARE being rung: do not say "no officer's phone was reached" |
| `rang_at` | when the call-style ring was first sent to a phone (ISO 8601), or null when no round has been sent. The time the resident's "Officers alerted" line is ticked with when the ring is what alerted them (M18A) |
| `on_duty_officers` | how many officers were CLOCKED ON at the site when the alert was raised (the raiser not counted), or null on an older alert or while the fan-out is still running. With `alerted_handsets` 0 it tells "nobody is on duty" (0) from "officers are on duty but their phones could not be reached" (above 0). Null: claim neither (M18A §1.2) |
| `sms_recipients` | how many of the site's emergency numbers were ON FILE for this alert, or null while it is still running. NOT how many were texted — never write "N people texted" from it; show `sms.note` |
| `sms_delivered` | how many of those texts a PROVIDER ACCEPTED, or null while it is still running. 0 on every alert today — no SMS provider is bought yet, so the message is written down and delivered to nobody. NEVER add this to `alerted_handsets`: a text is not an officer being sent |
| `sms.provider_connected` | true only when an SMS provider can actually send a text right now. false on this platform today: the log driver writes a message down and delivers it to nobody |
| `sms.attempted` | how many numbers were handed to a REAL provider for this alert. A message written to the log, or a number nobody can dial, is not an attempt |
| `sms.delivered` | how many of those a provider ACCEPTED — the furthest the platform can honestly claim. Never a count of phones that rang |
| `sms.finished` | true once the texting has run to the end. Until then `attempted` and `delivered` are "so far" |
| `sms.note` | the one sentence to show about texts, e.g. "No text was sent: no SMS provider is connected." Show it as given; never build a claim about texts from the numbers |
| `is_drill` | true when this is a DRILL raised to test the fan-out, never by a handset. Show DRILL before anything else on the screen; false on every alert a person raises |
| `drill_mode` | true when the office had drill mode on for this account when it was pressed: a DRILL (`is_drill` is true too) that rang no officer, emailed and texted nobody and sounded no siren. The counts above stay null for good, so say that instead of waiting on them, and keep the emergency number one press away in case it is real. Absent on a server older than this release |
| `messages.*.id` | the message |
| `messages.*.from` | `you` (the resident) or `dispatch` (the control room) |
| `messages.*.name` | the dispatcher's first name on a reply, or null |
| `messages.*.body` | what was written |
| `messages.*.at` | when the server received it — UTC instant; show in the site's zone (`timezone`) |
| `messages.*.seen_at` | when the OTHER side was shown it — the console drew it (your message), or this phone read it back (a reply) — or null: "Sent" until then, "Delivered" after — UTC instant; show in the site's zone (`timezone`) |
| `messages.*.client_key` | on your own messages, the `client_key` the phone sent it with — how the phone matches a message it queued to the row; null on a reply |
| `sms.household` | null on an officer's duress — only a resident's panic texts a household. Otherwise the texts to the resident's own nominated emergency contacts (the first 5 on file), counted by the same rules as the fields above and never included in them |
| `sms.household.attempted` | how many of the household's contacts were handed to a REAL provider for this alert. Under the log driver this is 0: the message is written down and delivered to nobody |
| `sms.household.delivered` | how many of those a provider ACCEPTED — never "received": the carrier and the phone are downstream. Say it only as the provider accepting it |
| `sms.household.finished` | true once the household's texting has run to the end, including at once when the household has no contacts on file. Until then `attempted` and `delivered` are "so far" |
| `email` | the emails a panic sends |
| `email.household` | null on an officer's duress. Otherwise the emails to the household's own emergency contacts (the first 5) AND its members (M18C: every member with an email of their own, the raiser not included, up to 10), each address once, counted from the ledger |
| `email.household.recipients` | how many people this alert is emailing — contacts and members together; null until the fan-out reaches the leg. 0: nobody has an email address on file (or a simulated/demonstration alert) |
| `email.household.members_sent` | of `sent`, how many were to the household's own members (M18C §4.3) |
| `email.household.contacts_sent` | of `sent`, how many were to its emergency contacts |
| `email.household.sent` | how many the mail server ACCEPTED — never "received". The only number that may put "emailed" on the screen |
| `email.household.failed` | how many were refused after every retry, or whose address was gone by the time it was sent |
| `email.household.finished` | true once every recipient has an outcome. Until then `sent` is "so far" |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this handset's alert — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Show the last answer with its age. The alert itself was already sent, or is queued and says so.

### `duress.messages.store`

"Message dispatch…" on the panic screen (board resident-app-06; M50 §4.2 #2): the resident writes to the control room on their OWN open panic. The control room sees it on the alert's page at once and replies there; a reply reaches this phone as the live step `alert.message` (read the alert again) and as an urgent `panic_update` push with `kind` `message`, which no switch and no quiet hours hold. Answers with the alert, `messages` included, exactly as `duress.show` reads it.

| | |
|---|---|
| Method and path | `POST /api/v1/duress/{alert}/messages` |
| App | Resident |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-alerts` |
| Path parameters | `alert` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `body` | string · required · what to say, up to 500 characters kept (longer is cut) |
| `client_key` | string · optional · the phone's own id for this message (a UUID). The same key again is the same message, never a second one — send it from the queue as often as the signal needs |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the alert |
| `kind` | `duress` from a guard. From a resident: `panic`, or `medical` / `fire` when they chose that `type` — `security`, `other` and no type at all are `panic` |
| `mode` | `silent` or `audible` |
| `status` | `open`, `acknowledged`, `responding`, `resolved` or `false_alarm` |
| `cancellable_until` | ten seconds after the server received it — UTC instant; show in the site's zone (`timezone`) |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `acknowledged_at` | when dispatch took it, or null — UTC instant; show in the site's zone (`timezone`) |
| `dispatched_at` | when the control room SENT an officer, or null. The first moment "help is on the way" is true — never say it from `acknowledged_at` — UTC instant; show in the site's zone (`timezone`) |
| `en_route_at` | when an officer was recorded as on the way (from their handset, or by dispatch after a radio call), or null. May stay null for good: an officer who ran straight there has `on_scene_at` only, and nothing is back-filled — draw the step as not recorded, never as done — UTC instant; show in the site's zone (`timezone`) |
| `on_scene_at` | when an officer was recorded as at the scene, or null — UTC instant; show in the site's zone (`timezone`) |
| `resolved_at` | when the alert was closed — resolved, a false alarm, or cancelled from the phone; `status` says which — or null — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time of the alert — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time of the press — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |
| `console_alerted` | true, always — the control room has this alert from the moment it is recorded, whoever is on shift |
| `alerted_handsets` | how many security HANDSETS the alert was sent to, or null while the fan-out is still running. 0 means none: say so plainly and put the emergency number first. NULL IS NOT 0 |
| `alerted_via` | which tier answered: `on_duty` (guards clocked on), `rostered` (nobody clocked on, so the officers rostered here today), `escalation` (no handset at all — the site's escalation contact was told off-app) or `none`, or null while it is still running |
| `escalated_contacts` | how many people were told OFF-APP — the site's escalation contacts, e-mailed on EVERY panic whether or not a handset was reached (M10 §1) — or null while it is still running. An email or a console bell, never a phone: it is never "help is coming" and never adds to `alerted_handsets` |
| `rang_handsets` | how many officer PHONES the call-style ring has been sent to so far, in any round — including the ring to every officer of the estate that goes at once when `alerted_handsets` is 0 (M18) — or null while the ring has not begun. Sent, not answered. When `alerted_handsets` is 0 and this is above 0, officers' phones ARE being rung: do not say "no officer's phone was reached" |
| `rang_at` | when the call-style ring was first sent to a phone (ISO 8601), or null when no round has been sent. The time the resident's "Officers alerted" line is ticked with when the ring is what alerted them (M18A) |
| `on_duty_officers` | how many officers were CLOCKED ON at the site when the alert was raised (the raiser not counted), or null on an older alert or while the fan-out is still running. With `alerted_handsets` 0 it tells "nobody is on duty" (0) from "officers are on duty but their phones could not be reached" (above 0). Null: claim neither (M18A §1.2) |
| `sms_recipients` | how many of the site's emergency numbers were ON FILE for this alert, or null while it is still running. NOT how many were texted — never write "N people texted" from it; show `sms.note` |
| `sms_delivered` | how many of those texts a PROVIDER ACCEPTED, or null while it is still running. 0 on every alert today — no SMS provider is bought yet, so the message is written down and delivered to nobody. NEVER add this to `alerted_handsets`: a text is not an officer being sent |
| `sms.provider_connected` | true only when an SMS provider can actually send a text right now. false on this platform today: the log driver writes a message down and delivers it to nobody |
| `sms.attempted` | how many numbers were handed to a REAL provider for this alert. A message written to the log, or a number nobody can dial, is not an attempt |
| `sms.delivered` | how many of those a provider ACCEPTED — the furthest the platform can honestly claim. Never a count of phones that rang |
| `sms.finished` | true once the texting has run to the end. Until then `attempted` and `delivered` are "so far" |
| `sms.note` | the one sentence to show about texts, e.g. "No text was sent: no SMS provider is connected." Show it as given; never build a claim about texts from the numbers |
| `is_drill` | true when this is a DRILL raised to test the fan-out, never by a handset. Show DRILL before anything else on the screen; false on every alert a person raises |
| `drill_mode` | true when the office had drill mode on for this account when it was pressed: a DRILL (`is_drill` is true too) that rang no officer, emailed and texted nobody and sounded no siren. The counts above stay null for good, so say that instead of waiting on them, and keep the emergency number one press away in case it is real. Absent on a server older than this release |
| `messages.*.id` | the message |
| `messages.*.from` | `you` (the resident) or `dispatch` (the control room) |
| `messages.*.name` | the dispatcher's first name on a reply, or null |
| `messages.*.body` | what was written |
| `messages.*.at` | when the server received it — UTC instant; show in the site's zone (`timezone`) |
| `messages.*.seen_at` | when the OTHER side was shown it — the console drew it (your message), or this phone read it back (a reply) — or null: "Sent" until then, "Delivered" after — UTC instant; show in the site's zone (`timezone`) |
| `messages.*.client_key` | on your own messages, the `client_key` the phone sent it with — how the phone matches a message it queued to the row; null on a reply |
| `sms.household` | null on an officer's duress — only a resident's panic texts a household. Otherwise the texts to the resident's own nominated emergency contacts (the first 5 on file), counted by the same rules as the fields above and never included in them |
| `sms.household.attempted` | how many of the household's contacts were handed to a REAL provider for this alert. Under the log driver this is 0: the message is written down and delivered to nobody |
| `sms.household.delivered` | how many of those a provider ACCEPTED — never "received": the carrier and the phone are downstream. Say it only as the provider accepting it |
| `sms.household.finished` | true once the household's texting has run to the end, including at once when the household has no contacts on file. Until then `attempted` and `delivered` are "so far" |
| `email` | the emails a panic sends |
| `email.household` | null on an officer's duress. Otherwise the emails to the household's own emergency contacts (the first 5) AND its members (M18C: every member with an email of their own, the raiser not included, up to 10), each address once, counted from the ledger |
| `email.household.recipients` | how many people this alert is emailing — contacts and members together; null until the fan-out reaches the leg. 0: nobody has an email address on file (or a simulated/demonstration alert) |
| `email.household.members_sent` | of `sent`, how many were to the household's own members (M18C §4.3) |
| `email.household.contacts_sent` | of `sent`, how many were to its emergency contacts |
| `email.household.sent` | how many the mail server ACCEPTED — never "received". The only number that may put "emailed" on the screen |
| `email.household.failed` | how many were refused after every retry, or whose address was gone by the time it was sent |
| `email.household.finished` | true once every recipient has an outcome. Until then `sent` is "so far" |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this account's alert. |
| 409 | `alert_closed` | The alert is resolved, closed or cancelled: nobody is reading the thread. Say the sentence given — it says what to do instead. |
| 422 | `message_empty` | Nothing to send. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue it with its `client_key` and send the moment there is a signal; draw it as "Waiting for signal" until then and never drop it. A refusal is shown on the message with the sentence given.

### `duress.audio.store`

"Share live audio" on the panic screen (board resident-app-06; M50 §4.2 #1): one piece of what the phone hears, on the resident's OWN open panic — multipart, field `file` (AAC in MP4, a few seconds long, at most 2 MB). Record while the switch is on, in the background too, and send each piece as it ends; the control room plays them in order on the alert's page as they arrive. The same `seq` again is the same piece, never a second one.

| | |
|---|---|
| Method and path | `POST /api/v1/duress/{alert}/audio` |
| App | Resident |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-alerts` |
| Path parameters | `alert` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `file` | file · required · the piece: AAC in MP4 (`.m4a`), at most 2 MB |
| `seq` | integer · required · this alert's pieces counted from 0 — the same number again is the same piece |
| `duration_ms` | integer · optional · how long the piece is |

**Response** `201`

| Key | Meaning |
|---|---|
| `seq` | the piece, as kept |
| `received` | always true |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this account's alert. |
| 409 | `alert_closed` | The alert is resolved, closed or cancelled: stop recording, and say the sentence given. |
| 422 | `validation_failed` | Not an audio piece the app makes, or larger than 2 MB. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue each piece with its `seq` and send them in order once there is a signal — what was heard with no signal still reaches the control room. Stop recording when the alert closes.

### `duress.positions.store`

Where the phone that raised this alert is now, while it stays open (12 Oct 2026; docs/handoff/GUARD_TRACKING.md 5.3): every 10 to 15 seconds, the caller's OWN alert only. Kept with the alert for ever; the estate's Security map and the dispatcher's draw the newest and hear it live (`alert.moved`). Never refused for being far from the estate: a person moving away is what the control room must see. Answered reading by reading.

| | |
|---|---|
| Method and path | `POST /api/v1/duress/{alert}/positions` |
| App | Guard + Resident |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-alerts` |
| Path parameters | `alert` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `positions` | array · required · 1–50 readings, oldest first |
| `positions.*.idempotency_key` | string · required · made on the phone, once per reading |
| `positions.*.captured_at` | string · required · ISO 8601 with its offset, on the phone's clock |
| `positions.*.lat` | number · required · degrees north |
| `positions.*.lng` | number · required · degrees east |
| `positions.*.accuracy_metres` | integer · optional · metres |

**Response** `200`

| Key | Meaning |
|---|---|
| `alert_id` | the alert |
| `results` | one per reading, in the order sent |
| `results.*.idempotency_key` | the reading |
| `results.*.result` | `accepted`, `duplicate` (already stored) or `rejected` |
| `results.*.reason` | rejected only: `alert_closed`, the alert is resolved, a false alarm or cancelled: stop sending |
| `results.*.message` | rejected only: the sentence for the person |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this phone's alert: the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue each reading with its key and send them in order the moment there is a signal. Stop when the alert closes.

### `panic.contacts`

The household's emergency contacts, for the panic screen's "text / call my emergency contacts" and nothing else. Every access level reads it, because panic is never removed from anybody (a Helper cannot read `me.household` or `contacts.index`). Names, relationship and number only — nothing else about the household — in the order the household added them.

| | |
|---|---|
| Method and path | `GET /api/v1/panic/contacts` |
| App | Resident |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.name` | name |
| `items.*.relationship` | or null |
| `items.*.phone` | number, as entered |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache — the panic screen texts and calls these people from the phone itself, and is needed most when there is no signal.

### `requests.index`

The guard's leave, equipment and swap requests, with decisions, and their leave balance in days. Board guard-app-05.

| | |
|---|---|
| Method and path | `GET /api/v1/requests` |
| App | Guard |
| Ability | `requests:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the request |
| `items.*.kind` | `leave`, `equipment` or `shift_swap` |
| `items.*.subject` | leave type, or the item |
| `items.*.quantity` | equipment count, or null |
| `items.*.starts_on` | YYYY-MM-DD or null |
| `items.*.ends_on` | YYYY-MM-DD or null |
| `items.*.days` | inclusive, or null |
| `items.*.reason` | or null |
| `items.*.certificate_attached` | boolean — the flag, never the document |
| `items.*.status` | `pending`, `approved`, `denied` or `info_requested` |
| `items.*.decided_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.decision_note` | the supervisor's note, or null |
| `items.*.created_at` | UTC instant; show in the site's zone (`timezone`) |
| `leave.entitlement_days` | per year |
| `leave.approved_days_this_year` | days |
| `leave.remaining_days` | days |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cache.

### `requests.store`

Raise a request. It reaches the dispatch inbox at once.

| | |
|---|---|
| Method and path | `POST /api/v1/requests` |
| App | Guard |
| Ability | `requests:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `kind` | string · required · `leave`, `equipment` or `shift_swap` |
| `subject` | string · required · for leave: `vacation`, `sick`, `bereavement`, `maternity`, `paternity`, `unpaid`, `other` |
| `quantity` | integer · required for equipment |
| `starts_on` | date · required for leave |
| `ends_on` | date · required for leave |
| `reason` | string · required for leave (M18C), vacation included |
| `certificate_attached` | boolean · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the request |
| `kind` | kind |
| `subject` | subject |
| `quantity` | or null |
| `starts_on` | or null |
| `ends_on` | or null |
| `days` | or null |
| `reason` | or null |
| `certificate_attached` | boolean |
| `status` | `pending` |
| `decided_at` | null |
| `decision_note` | null |
| `created_at` | UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `leave_type_unknown` | A leave subject not in the list. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `alerts.show`

An alert at the guard's own site, as a responding officer sees it: where, what kind, how far the response has got — acknowledged, dispatched, en route, on scene, resolved — and whether THIS handset may record the next step. Open it from the `site_alert` push (its `id`; `kind: dispatched` means this guard was sent) or from `sync.pull` `alerts`. Poll while it is open.

| | |
|---|---|
| Method and path | `GET /api/v1/alerts/{alert}` |
| App | Guard |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `alert` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `alert_id` | the alert |
| `kind` | `panic`, `medical` or `fire` from a resident, `duress` from another officer, or `intrusion` |
| `is_drill` | whether this is a DRILL raised to test the fan-out (M10 §1.3) — show DRILL before anything else; false for every real alert |
| `status` | `open`, `acknowledged`, `responding` (an officer has been sent or is coming), `resolved` or `false_alarm` |
| `where` | the lot, e.g. "Lot 47", or null — never the name of whoever pressed it |
| `raised_at` | when the server received it — UTC instant; show in the site's zone (`timezone`) |
| `acknowledged_at` | when the control room took it, or null — UTC instant; show in the site's zone (`timezone`) |
| `dispatched_at` | when the control room SENT an officer, or null. A guard on shift may respond without being sent — then this stays null — UTC instant; show in the site's zone (`timezone`) |
| `en_route_at` | when the responding officer was recorded as on the way, or null. May stay null for good: an officer who ran straight there is recorded on scene only, and nothing is back-filled — UTC instant; show in the site's zone (`timezone`) |
| `on_scene_at` | when the responding officer was recorded as at the scene, or null — UTC instant; show in the site's zone (`timezone`) |
| `resolved_at` | when the alert was closed — resolved, a false alarm or cancelled — or null — UTC instant; show in the site's zone (`timezone`) |
| `responder` | the name of the officer responding (dispatched, or first to say "on my way" when nobody was sent), or null while nobody is |
| `responder_is_you` | true when that officer is this handset's guard |
| `dispatched_to_you` | true when the control room SENT this guard |
| `can_en_route` | whether THIS handset may record "on my way" now. Draw the button only when true |
| `can_on_scene` | whether THIS handset may record "I am there" now. Draw the button only when true |
| `reason` | the sentence saying why "I am there" cannot be recorded from this handset (closed, another officer is responding, already recorded, not on shift), or null when it can. Show it as given |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `own_alert` | The guard's own duress: follow it with `GET /duress/{alert}` instead. |
| 403 | `not_on_shift` | This guard is neither the officer responding nor one the alert reached. |
| 404 | `not_found` | No alert with that id at the guard's own site — an id at another estate answers identically. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Show the last answer with its age, and say it may be out of date: the response moves on without this handset.

### `alerts.en_route`

"I am on my way." The resident is pushed "An officer is on the way". Allowed for the officer dispatched — or, when nobody was dispatched, for any officer the alert reached, who then becomes its responder. Forward only: refused once on the way or at the scene is recorded, and refused after the alert is closed. The server records ITS time; the handset time is kept beside it in the audit log.

| | |
|---|---|
| Method and path | `POST /api/v1/alerts/{alert}/en-route` |
| App | Guard |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-alerts` |
| Path parameters | `alert` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `alert_id` | the alert |
| `kind` | `panic`, `medical` or `fire` from a resident, `duress` from another officer, or `intrusion` |
| `is_drill` | whether this is a DRILL raised to test the fan-out (M10 §1.3) — show DRILL before anything else; false for every real alert |
| `status` | `open`, `acknowledged`, `responding` (an officer has been sent or is coming), `resolved` or `false_alarm` |
| `where` | the lot, e.g. "Lot 47", or null — never the name of whoever pressed it |
| `raised_at` | when the server received it — UTC instant; show in the site's zone (`timezone`) |
| `acknowledged_at` | when the control room took it, or null — UTC instant; show in the site's zone (`timezone`) |
| `dispatched_at` | when the control room SENT an officer, or null. A guard on shift may respond without being sent — then this stays null — UTC instant; show in the site's zone (`timezone`) |
| `en_route_at` | when the responding officer was recorded as on the way, or null. May stay null for good: an officer who ran straight there is recorded on scene only, and nothing is back-filled — UTC instant; show in the site's zone (`timezone`) |
| `on_scene_at` | when the responding officer was recorded as at the scene, or null — UTC instant; show in the site's zone (`timezone`) |
| `resolved_at` | when the alert was closed — resolved, a false alarm or cancelled — or null — UTC instant; show in the site's zone (`timezone`) |
| `responder` | the name of the officer responding (dispatched, or first to say "on my way" when nobody was sent), or null while nobody is |
| `responder_is_you` | true when that officer is this handset's guard |
| `dispatched_to_you` | true when the control room SENT this guard |
| `can_en_route` | whether THIS handset may record "on my way" now. Draw the button only when true |
| `can_on_scene` | whether THIS handset may record "I am there" now. Draw the button only when true |
| `reason` | the sentence saying why "I am there" cannot be recorded from this handset (closed, another officer is responding, already recorded, not on shift), or null when it can. Show it as given |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `own_alert` | The guard's OWN alert (their duress). Help is being sent to them; they cannot be the officer sent. |
| 403 | `not_on_shift` | Nobody was sent, and this guard is not among the officers the alert reached: on duty at the site, rostered there today, or rung by its ring when nobody answered. |
| 403 | `another_officer_responding` | Another officer was dispatched, or said "on my way" first. Only their handset records the response; the message names them. Tell dispatch by radio if going too. |
| 404 | `not_found` | No alert with that id at the guard's own site — an id at another estate answers identically. |
| 409 | `alert_closed` | The alert was resolved, closed as a false alarm or cancelled. The message says which and when. Nothing more can be recorded. |
| 409 | `already_en_route` | On the way is already recorded — by this handset, or by dispatch after a radio call. The message gives the time. |
| 409 | `already_on_scene` | At the scene is already recorded; the response only moves forward. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its key and the device time of the tap, and send the moment signal returns — the resident is told when the server hears it. A step recorded meanwhile (or the alert closed) comes back refused: show the sentence. Also radio dispatch.

### `alerts.on_scene`

"I am there." The resident is pushed "An officer has arrived". The same rule as `alerts.en_route`; it may be sent without an en route first (an officer who ran straight there), and en route can no longer be recorded after it.

| | |
|---|---|
| Method and path | `POST /api/v1/alerts/{alert}/on-scene` |
| App | Guard |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-alerts` |
| Path parameters | `alert` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `alert_id` | the alert |
| `kind` | `panic`, `medical` or `fire` from a resident, `duress` from another officer, or `intrusion` |
| `is_drill` | whether this is a DRILL raised to test the fan-out (M10 §1.3) — show DRILL before anything else; false for every real alert |
| `status` | `open`, `acknowledged`, `responding` (an officer has been sent or is coming), `resolved` or `false_alarm` |
| `where` | the lot, e.g. "Lot 47", or null — never the name of whoever pressed it |
| `raised_at` | when the server received it — UTC instant; show in the site's zone (`timezone`) |
| `acknowledged_at` | when the control room took it, or null — UTC instant; show in the site's zone (`timezone`) |
| `dispatched_at` | when the control room SENT an officer, or null. A guard on shift may respond without being sent — then this stays null — UTC instant; show in the site's zone (`timezone`) |
| `en_route_at` | when the responding officer was recorded as on the way, or null. May stay null for good: an officer who ran straight there is recorded on scene only, and nothing is back-filled — UTC instant; show in the site's zone (`timezone`) |
| `on_scene_at` | when the responding officer was recorded as at the scene, or null — UTC instant; show in the site's zone (`timezone`) |
| `resolved_at` | when the alert was closed — resolved, a false alarm or cancelled — or null — UTC instant; show in the site's zone (`timezone`) |
| `responder` | the name of the officer responding (dispatched, or first to say "on my way" when nobody was sent), or null while nobody is |
| `responder_is_you` | true when that officer is this handset's guard |
| `dispatched_to_you` | true when the control room SENT this guard |
| `can_en_route` | whether THIS handset may record "on my way" now. Draw the button only when true |
| `can_on_scene` | whether THIS handset may record "I am there" now. Draw the button only when true |
| `reason` | the sentence saying why "I am there" cannot be recorded from this handset (closed, another officer is responding, already recorded, not on shift), or null when it can. Show it as given |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `own_alert` | The guard's OWN alert (their duress). Help is being sent to them; they cannot be the officer sent. |
| 403 | `not_on_shift` | Nobody was sent, and this guard is not among the officers the alert reached: on duty at the site, rostered there today, or rung by its ring when nobody answered. |
| 403 | `another_officer_responding` | Another officer was dispatched, or said "on my way" first. Only their handset records the response; the message names them. Tell dispatch by radio if going too. |
| 404 | `not_found` | No alert with that id at the guard's own site — an id at another estate answers identically. |
| 409 | `alert_closed` | The alert was resolved, closed as a false alarm or cancelled. The message says which and when. Nothing more can be recorded. |
| 409 | `already_on_scene` | At the scene is already recorded. The message gives the time. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its key and the device time of the tap, and send the moment signal returns. Refused if the alert was closed meanwhile: show the sentence. Also radio dispatch.

### `ring.answer`

Answer a ring from the ring screen, on its ticket — no bearer token. "I'm responding" is exactly `alerts.en_route`: the same refusals, the resident told "An officer is on the way", and every other phone told to stop ringing. It is REFUSED when the tap is more than two minutes old (`age_ms`, measured on the phone's monotonic clock): radio dispatch instead. "Can't respond" is recorded; for the whole alert (`scope: alert`, after unlocking) it removes this guard from later rounds, and from the lock screen (`scope: round`) it silences only the ring in front of them. It never renews the Guard App's own session.

| | |
|---|---|
| Method and path | `POST /api/v1/ring/answer` |
| App | Guard — a ring ticket, no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-ring` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `rt` | string · required · the ring ticket from the `site_ring` push, at most 1024 |
| `answer` | string · required · `responding` or `cannot` |
| `age_ms` | integer · required for `responding` · how many milliseconds ago the guard tapped, measured with the phone's monotonic clock (`SystemClock.elapsedRealtime`), never from its wall clock. Over 120000 is refused as stale |
| `scope` | string · optional, `cannot` only · `round` from the lock screen (this ring only), `alert` after unlocking (every later round). Default `alert` |
| `device_time` | string · optional · ISO 8601 with offset, or the X-Device-Time header — kept for the audit only |

**Response** `200`

| Key | Meaning |
|---|---|
| `recorded` | boolean — always true |
| `answer` | `responding` or `cannot`, as recorded |
| `code` | `already_yours` when this guard was already recorded as on the way or at the scene — a replay, or the Guard App got there first — and null otherwise |
| `session_open` | whether the Guard App's own session on this phone is still open. False: the alert cannot be opened in the app — say "Recorded: you're responding. Dispatch and the resident have been told. Your Guard App session timed out, so the alert can't open there — follow it by radio." |
| `alert.alert_id` | the alert |
| `alert.kind` | `panic`, `medical` or `fire` from a resident, `duress` from another officer, or `intrusion` |
| `alert.is_drill` | whether this is a DRILL raised to test the fan-out (M10 §1.3) — show DRILL before anything else; false for every real alert |
| `alert.status` | `open`, `acknowledged`, `responding` (an officer has been sent or is coming), `resolved` or `false_alarm` |
| `alert.where` | the lot, e.g. "Lot 47", or null — never the name of whoever pressed it |
| `alert.raised_at` | when the server received it — UTC instant; show in the site's zone (`timezone`) |
| `alert.acknowledged_at` | when the control room took it, or null — UTC instant; show in the site's zone (`timezone`) |
| `alert.dispatched_at` | when the control room SENT an officer, or null. A guard on shift may respond without being sent — then this stays null — UTC instant; show in the site's zone (`timezone`) |
| `alert.en_route_at` | when the responding officer was recorded as on the way, or null. May stay null for good: an officer who ran straight there is recorded on scene only, and nothing is back-filled — UTC instant; show in the site's zone (`timezone`) |
| `alert.on_scene_at` | when the responding officer was recorded as at the scene, or null — UTC instant; show in the site's zone (`timezone`) |
| `alert.resolved_at` | when the alert was closed — resolved, a false alarm or cancelled — or null — UTC instant; show in the site's zone (`timezone`) |
| `alert.responder` | the name of the officer responding (dispatched, or first to say "on my way" when nobody was sent), or null while nobody is |
| `alert.responder_is_you` | true when that officer is this handset's guard |
| `alert.dispatched_to_you` | true when the control room SENT this guard |
| `alert.can_en_route` | whether THIS handset may record "on my way" now. Draw the button only when true |
| `alert.can_on_scene` | whether THIS handset may record "I am there" now. Draw the button only when true |
| `alert.reason` | the sentence saying why "I am there" cannot be recorded from this handset (closed, another officer is responding, already recorded, not on shift), or null when it can. Show it as given |
| `server_time` | the server's time — UTC instant |
| `device_time` | the device time sent, or null — UTC instant |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 401 | `ring_ticket_invalid` | The ticket is not one this server signed, or what it names is no longer true: the push address was revoked or moved, the guard is no longer active here, or the alert is not theirs to answer. The same answer for all of them. Show "This ring can no longer be answered here. Open the Guard App or radio dispatch." |
| 410 | `ring_ticket_expired` | The ticket is more than thirty minutes old (`ring.ticket_minutes`). The same sentence. |
| 403 | `ring_ticket_invalid` | A TEST ring's ticket: "A test ring cannot answer an alert." |
| 409 | `ring_answer_stale` | "I'm responding" tapped more than two minutes before it reached the server. Nothing is recorded. Show the sentence as sent: "…Radio dispatch to say whether you are going." |
| 403 | `own_alert` | The guard's own alert. |
| 403 | `not_on_shift` | This guard is not among the officers the alert reached or rang. |
| 403 | `another_officer_responding` | Another officer is responding; the message names them. Tell dispatch by radio if going too. |
| 409 | `alert_closed` | The alert was resolved, closed as a false alarm or cancelled. The message says which. |
| 409 | `already_en_route` | On the way is already recorded, and not as this guard's claim. (This guard's own claim, replayed, answers 200 with `code: already_yours` instead.) |
| 409 | `already_on_scene` | At the scene is already recorded. |
| 422 | `device_time_invalid` | The device time is not an ISO 8601 timestamp. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Keep the answer on the phone and send it the moment there is signal, every 10 s while the ring screen is shown and on the next start. Drop a `responding` two minutes after the tap, by the monotonic clock, and never send it: say "Not sent: too late to claim by phone. Radio dispatch to say whether you are going." A `cannot` is kept until the ticket expires. A 429 is kept and retried in 10 s.

### `ring.receipt`

What the phone did with a ring, on its ticket — no bearer token. `shown` is the only thing the console counts as "shown on the phone", and its `detail` is the phone's live sound check at that moment. Recorded once per phone, round and event; a replay changes nothing. Never touches the Guard App's session.

| | |
|---|---|
| Method and path | `POST /api/v1/ring/receipt` |
| App | Guard — a ring ticket, no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `202` |
| Rate limit | `api-ring` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `rt` | string · required · the ring ticket of the ring this is about (a test ring's too) |
| `event` | string · required · `shown` (the ring was posted), `full_screen` (the SYSTEM launched the full-screen ring — never a tap), `opened` (a tap, an action or a direct start), `held` (it became the silent notice), `dismissed` (swiped away: neither an answer nor a decline), `stopped`, `suppressed` (the guard is under their own duress), `fallback` (posted on an ordinary channel) |
| `detail` | string · optional, at most 60 · for `shown`, the sound check: `imp=<0-5>;snd=<0\|1>;vol=<n>/<max>;dnd=<pass\|block\|unknown>;fsi=<0\|1>;lk=<0\|1>`. Otherwise a short code |
| `ready` | integer · optional · the readiness bitmask at that moment, bit set = ON: 0 notifications, 1 ring channel, 2 full-screen alerts (or not needed), 3 Do Not Disturb bypass, 4 battery optimisation exempt, 5 background activity allowed, 6 alarm volume OK (0 is read as off), 7 alarm tone set, 8 screen lock. Read leniently: a value the server cannot read is ignored |
| `device_time` | string · optional · ISO 8601 with offset |

**Response** `202`

| Key | Meaning |
|---|---|
| `recorded` | boolean — always true |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 401 | `ring_ticket_invalid` | The ticket is not one this server signed, or what it names is no longer true: the push address was revoked or moved, the guard is no longer active here, or the alert is not theirs to answer. The same answer for all of them. Show "This ring can no longer be answered here. Open the Guard App or radio dispatch." |
| 410 | `ring_ticket_expired` | The ticket is more than thirty minutes old (`ring.ticket_minutes`). The same sentence. |
| 422 | `device_time_invalid` | The device time is not an ISO 8601 timestamp. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Write the receipt down BEFORE sending it, send it on a background thread, and delete it on a 2xx. Flush what is left on the next push and the next start. A receipt never delays a ring.

### `ring.test`

Send a test ring to THIS sign-in's phone, from the Call-style alerts screen. It is a real push — the whole chain from the server to the ring screen — marked DRILL, fifteen seconds long, with no answer buttons. The phone's own `shown` receipt for it is what makes the console say "proven by a test ring".

| | |
|---|---|
| Method and path | `POST /api/v1/ring/test` |
| App | Guard |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-ring-test` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `sent` | boolean — true: the test ring was handed to the push queue. Not proof that the phone rang: its own `shown` receipt is |
| `ring_state` | `ready`, `partial` or `not_ready` — the server's reading of this phone's last readiness report |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `no_push_address` | This sign-in has no push address yet: "This phone has no push address yet: allow notifications, then try again." |
| 409 | `ring_not_supported` | The phone has not reported call-style alerts (an older Guard App, or an iPhone): "This phone has not reported call-style alerts: update the Guard App." |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only, and never queued: a test ring later is not a test of now.

### `payslips.me`

The guard's OWN payslips on approved runs, newest first, as decimal strings. The one route where a guard's handset reads money — their own wage. Board guard-app-05.

| | |
|---|---|
| Method and path | `GET /api/v1/payslips/me` |
| App | Guard |
| Ability | `payslips:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the payslip |
| `items.*.run_reference` | the payroll run |
| `items.*.period_label` | e.g. "September 2026" |
| `items.*.period_start` | YYYY-MM-DD |
| `items.*.period_end` | YYYY-MM-DD |
| `items.*.status` | `approved` or `paid` |
| `items.*.currency` | `JMD` |
| `items.*.gross` | decimal string, e.g. "38450.00" |
| `items.*.deductions.nis` | decimal string |
| `items.*.deductions.nht` | decimal string |
| `items.*.deductions.education_tax` | decimal string |
| `items.*.deductions.paye` | decimal string |
| `items.*.deductions.pension` | decimal string — an approved pension, zero unless one is on file |
| `items.*.net` | decimal string |
| `items.*.paye_note` | why PAYE is what it is, or null |
| `items.*.employee.name` | the officer, as HR holds it |
| `items.*.employee.employee_number` | e.g. GS-1041 |
| `items.*.employee.trn` | the officer's TRN, or null — this platform does not hold it (it is HR's record). Draw "Not on file", never a blank that reads as zero |
| `items.*.employee.nis_number` | the officer's NIS number, or null, for the same reason |
| `items.*.employer.name` | the employer — the security company, never the community |
| `items.*.employer.address` | its postal address |
| `items.*.employer.email` | where a payroll question goes |
| `items.*.hours` | NULL on a slip written before hours were recorded — say "not on record for this period" rather than drawing 0.00 |
| `items.*.hours.regular` | decimal string, two places |
| `items.*.hours.overtime` | decimal string, two places |
| `items.*.hours.regular_rate` | the standard hourly rate |
| `items.*.hours.overtime_rate` | the overtime hourly rate — time and a half of the standard |
| `items.*.hours.overtime_note` | the sentence to print beside it |
| `items.*.earnings.basic` | regular hours at the standard rate |
| `items.*.earnings.overtime` | overtime hours at the overtime rate |
| `items.*.earnings.allowances` | allowances for the period |
| `items.*.earnings.gross` | the three added — the same gross as `gross` above |
| `items.*.earnings.taxable_note` | which earnings are taxable |
| `items.*.lines.*.key` | `nis`, `nht`, `education_tax`, `paye`, `pension` |
| `items.*.lines.*.label` | what to print |
| `items.*.lines.*.amount` | decimal string |
| `items.*.lines.*.rate` | the rate as a percentage, e.g. "2.5%", or null where there is no rate (a pension) |
| `items.*.lines.*.base` | WHAT IT WAS CALCULATED ON — not the gross for any two of them |
| `items.*.lines.*.note` | the base in words |
| `items.*.total_deductions` | decimal string |
| `items.*.employer_contributions.nis` | paid BY the employer — never a deduction from this wage |
| `items.*.employer_contributions.nht` | decimal string |
| `items.*.employer_contributions.education_tax` | decimal string |
| `items.*.employer_contributions.heart` | decimal string |
| `items.*.employer_contributions.total` | decimal string |
| `items.*.employer_contributions.note` | the sentence that keeps it from reading as money taken off |
| `items.*.year_to_date.year` | the tax year — Jamaica's is the calendar year |
| `items.*.year_to_date.periods` | how many approved periods are counted |
| `items.*.year_to_date.gross` | decimal string |
| `items.*.year_to_date.nis` | decimal string |
| `items.*.year_to_date.nht` | decimal string |
| `items.*.year_to_date.education_tax` | decimal string |
| `items.*.year_to_date.paye` | decimal string |
| `items.*.year_to_date.net` | decimal string |
| `items.*.periods_per_year` | how many pay periods a year — 12, 26 or 52; what the thresholds were divided by |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cache from the secure store; never write a payslip to shared storage.

### `payslips.pdf`

The same payslip as a PDF to keep or send on — THE FILE ITSELF, not JSON (`Content-Disposition` carries its name). Only the officer's own, and only on an approved run.

| | |
|---|---|
| Method and path | `GET /api/v1/payslips/{payslip}/pdf` |
| App | Guard |
| Ability | `payslips:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `payslip` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

No success body: this endpoint answers with an error envelope.

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a payslip of yours, or not on an approved run — the two are deliberately the same answer. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Needs a connection. A file already downloaded stays on the phone.

### `messages.index`

Broadcasts to the estate and the guard's own thread with dispatch, newest first. Board guard-app-04.

| | |
|---|---|
| Method and path | `GET /api/v1/messages` |
| App | Guard |
| Ability | `messages:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `since` | ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site's clock · optional (query) · only newer messages |

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the message |
| `items.*.direction` | `broadcast`, `outbound` (dispatch to this guard) or `inbound` (this guard to dispatch) |
| `items.*.from` | `dispatch` or `you` |
| `items.*.body` | text, at most 500 |
| `items.*.sent_at` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.read_at` | when THIS guard read it on THIS handset (never another guard's read), or null — UTC instant; show in the site's zone (`timezone`) |
| `unread` | messages from dispatch not yet read |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cache plus queued outgoing messages.

### `messages.store`

Write to dispatch. Marks the guard's own line with dispatch read for THIS guard — never a broadcast, and never for another guard.

| | |
|---|---|
| Method and path | `POST /api/v1/messages` |
| App | Guard |
| Ability | `messages:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `body` | string · required · at most 500 |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the message |
| `direction` | `broadcast`, `outbound` (dispatch to this guard) or `inbound` (this guard to dispatch) |
| `from` | `dispatch` or `you` |
| `body` | text, at most 500 |
| `sent_at` | UTC instant; show in the site's zone (`timezone`) |
| `read_at` | when THIS guard read it on THIS handset (never another guard's read), or null — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time.

### `messages.read`

Mark messages read for THIS guard on THIS handset — send it when a conversation is shown. Idempotent: the first read is the one kept. Another guard's read state is never touched.

| | |
|---|---|
| Method and path | `POST /api/v1/messages/read` |
| App | Guard |
| Ability | `messages:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `ids` | array of integers · required · 1–100 message ids from `messages.index` |

**Response** `200`

| Key | Meaning |
|---|---|
| `ids.*` | integer — a message now read for this guard. An id the guard may not see (another guard's line, another estate, nobody's) is left out, silently |
| `unread` | integer — messages from dispatch this guard has still not read, over the newest hundred |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue. Show the conversation as read at once; the server learns when signal returns.

### `guards.me`

The guard's OWN details as the office holds them: name, service number, site, standing post, and their security licence with its expiry. Board guard-app-26. The guard is the token's — there is no id to pass. Never pay.

| | |
|---|---|
| Method and path | `GET /api/v1/guards/me` |
| App | Guard |
| Ability | `shifts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `name` | the guard's full name |
| `employee_number` | e.g. "GS-1041" |
| `site.id` | the estate |
| `site.name` | its name |
| `site.timezone` | the IANA zone every time at this site is shown in |
| `site.phases.*.name` | a phase of this estate, e.g. "Phase 1", in the estate's own order |
| `site.phases.*.unit_count` | how many units stand in it |
| `post` | null when the guard has no standing post |
| `post.id` | the post |
| `post.name` | e.g. "Main Gate" |
| `licence.name` | what the licence is called — "PSRA licence" |
| `licence.number` | the licence number as the office holds it, or null when the office has not recorded one |
| `licence.expires_on` | YYYY-MM-DD, or null when the office has not recorded one |
| `licence.state` | `valid`, `expiring`, `expired` or `unknown` |
| `licence.days_left` | integer — calendar days at the site until `expires_on` (0 on the day, negative once expired), or null |
| `licence.warning_days` | integer — `expiring` means this many days or fewer are left (30) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cache with its age. Work the warning out again from `expires_on` and today's date — a cached `state` goes stale.

### `sync.batch`

Upload the offline queue, in order. Each operation runs through its own endpoint with its own Idempotency-Key and device time; a retried batch replays. Stops at the first server failure.

| | |
|---|---|
| Method and path | `POST /api/v1/sync/batch` |
| App | Guard |
| Ability | `sync:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `operations` | array · required · 1–50, in the order they happened |
| `operations.*.id` | string · required · the app's own id for the operation, echoed back |
| `operations.*.endpoint` | string · required · a catalogue name, e.g. `shifts.clock_in`, `gate.entry`, `patrol.scan` |
| `operations.*.params` | object · optional · path parameters, e.g. `{"shift": 812}` |
| `operations.*.body` | object · optional · the endpoint's request body |
| `operations.*.idempotency_key` | string · required · the key chosen when the operation was queued |
| `operations.*.device_time` | ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site's clock · required · when it happened on the handset |

**Response** `200`

| Key | Meaning |
|---|---|
| `results.*.id` | the operation id, as sent |
| `results.*.endpoint` | as sent |
| `results.*.outcome` | `ok`, `refused` (a 4xx answer — show it), `failed` (5xx — resend) or `not_attempted` (after a failure — resend) |
| `results.*.status` | the HTTP status the endpoint answered, or null |
| `results.*.replayed` | true when answered from a previous attempt with the same key |
| `results.*.body` | the endpoint's own response body, exactly as documented for it |
| `results.*.body.**` | opaque here — see the named endpoint |
| `counts.ok` | count |
| `counts.refused` | count |
| `counts.failed` | count |
| `counts.not_attempted` | count |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — This IS the offline path. Batchable: every guard write except `incidents.media` and sync itself; an unbatchable name comes back `refused` with `endpoint_not_batchable`.

### `sync.pull`

What changed since the last pull: shifts, order versions, messages, pending alertness checks, revoked passes and the current pass keys, request decisions, claim decisions, and any resident report of something HAPPENING NOW at this site while this guard is on duty.

| | |
|---|---|
| Method and path | `GET /api/v1/sync/pull` |
| App | Guard |
| Ability | `sync:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `since` | ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site's clock · optional (query) · the previous `next_since`, sent back untouched; default seven days |

**Response** `200`

| Key | Meaning |
|---|---|
| `since` | as applied — UTC instant; show in the site's zone (`timezone`) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `next_since` | send this as `since` next time, untouched. It sits a few seconds behind this pull and, when a long list (`messages`, `resident_reports`, `alerts`) was cut to a page, where that list stopped — so the next pull carries on with no gap, and an item can come twice: keep every list by its id — UTC instant; show in the site's zone (`timezone`) |
| `timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `shifts.*.id` | the shift |
| `shifts.*.status` | `rostered`, `open`, `on_duty`, `completed` or `missed` |
| `shifts.*.post.id` | the post |
| `shifts.*.post.name` | e.g. "Main Gate" |
| `shifts.*.site.id` | the estate |
| `shifts.*.site.name` | its name |
| `shifts.*.site.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `shifts.*.rostered_start` | UTC instant; show in the site's zone (`timezone`) |
| `shifts.*.rostered_end` | UTC instant; show in the site's zone (`timezone`) |
| `shifts.*.actual_start` | the first clock-in the server recorded, or null — UTC instant; show in the site's zone (`timezone`) |
| `shifts.*.actual_end` | the clock-out, or null — UTC instant; show in the site's zone (`timezone`) |
| `orders.*.set_id` | order set |
| `orders.*.version_id` | a version published since |
| `orders.*.version` | number |
| `orders.*.title` | title |
| `orders.*.effective_on` | YYYY-MM-DD |
| `orders.*.requires_acknowledgement` | boolean |
| `messages.*.id` | message |
| `messages.*.direction` | `broadcast` or `outbound` |
| `messages.*.body` | text |
| `messages.*.sent_at` | UTC instant; show in the site's zone (`timezone`) |
| `alertness_checks.*.check_id` | a check to answer now |
| `alertness_checks.*.issued_at` | UTC instant; show in the site's zone (`timezone`) |
| `alertness_checks.*.respond_by` | UTC instant; show in the site's zone (`timezone`) |
| `passes.revoked.*.pass_id` | a still-unexpired pass cancelled or used since — refuse it offline from now on |
| `passes.revoked.*.status` | `cancelled` or `used` |
| `passes.revoked.*.at` | when — UTC instant; show in the site's zone (`timezone`) |
| `passes.keys.*.version` | key version |
| `passes.keys.*.public_key` | base64url Ed25519 public key |
| `passes.keys.*.current` | boolean |
| `requests.*.id` | a request decided since |
| `requests.*.kind` | kind |
| `requests.*.status` | decision |
| `requests.*.decided_at` | UTC instant; show in the site's zone (`timezone`) |
| `requests.*.decision_note` | or null |
| `claims.*.claim_id` | a claim decided since |
| `claims.*.shift_id` | the shift |
| `claims.*.status` | `approved` or `declined` |
| `claims.*.decided_at` | UTC instant; show in the site's zone (`timezone`) |
| `resident_reports.*.report_id` | the report a resident said is happening NOW — read-only; it belongs to the estate office and nothing here changes that |
| `resident_reports.*.number` | the number of the report — what the office and the resident both say out loud, e.g. 1042 |
| `resident_reports.*.title` | what, in the resident's own few words |
| `resident_reports.*.detail` | or null — the rest of what they wrote, clipped |
| `resident_reports.*.location` | where, in words |
| `resident_reports.*.latitude` | degrees north, or null — where the resident was, only if they agreed to attach it |
| `resident_reports.*.longitude` | degrees east, or null |
| `resident_reports.*.accuracy_m` | the fix's accuracy in metres, or null — a wide circle is not a doorstep |
| `resident_reports.*.reported_at` | UTC instant; show in the site's zone (`timezone`) |
| `alerts.*.alert_id` | an alert still open at this site, raised since — read-only; dispatch owns it |
| `alerts.*.kind` | `panic` (a resident) or `duress` (another officer), or medical/fire/intrusion |
| `alerts.*.where` | or null — the unit, e.g. Lot 1; never a person's name |
| `alerts.*.raised_at` | UTC instant; show in the site's zone (`timezone`) |
| `alerts.*.is_drill` | true when the alert is a DRILL testing who a panic reaches (M10 §1.3) — say DRILL first; false for every real alert |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Pull first whenever signal returns, then upload the batch.

### `estates.index`

The active estates a resident can pick from BEFORE signing in — so nobody has to type a community code. No token. Says no more than the sign on the gate: id, name, parish.

| | |
|---|---|
| Method and path | `GET /api/v1/estates` |
| App | Resident, before sign-in — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the estate's id — what `auth.otp.request` / `auth.otp.verify` take as `estate` |
| `items.*.name` | the estate's name |
| `items.*.parish` | its parish, or null — tells two estates of the same name apart |
| `items.*.demo` | true for the DEMONSTRATION community: invented data, nobody lives there. Label it as one, and offer "Explore a demo community" (`demo.enter`) only when this list holds one. |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Ten requests a minute per address. |

**Offline** — Serve the last list; the typed community code always remains as a fallback.

### `auth.otp.request`

Send a six-digit sign-in code by email or text. A sign-in (`intent: sign_in`) is told at once when the register does not hold the address; Create account, and an app that sends no intent, are answered the same whether or not an account exists (M80 §2.1). Board resident-app-01.

| | |
|---|---|
| Method and path | `POST /api/v1/auth/otp/request` |
| App | Resident, before sign-in — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `202` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `estate` | string · required · the estate's id, e.g. `phoenixpark` — the app ships the list or reads it from a QR code at the estate office |
| `channel` | string · required · `email` or `sms` |
| `destination` | string · required · the email address, or the number in any common format |
| `intent` | string · optional · `sign_in` from Log in (the address is looked up first: `422 email_not_found` when no resident and no app account at this estate holds it), `sign_up` from Create account; absent, as an app already installed sends it, the request is answered as before |
| `device_uid` | string · optional · the install's id, as `auth.otp.verify` takes it — counted against for sign-in lookups and for nothing else (the network address when absent); a claim the phone makes, never an identity |

**Response** `202`

| Key | Meaning |
|---|---|
| `sent` | always true — the mail server ACCEPTED the code (the request waits for it); anything else is an error below |
| `channel` | as sent |
| `destination_hint` | masked |
| `destination` | the address or number the code went to, IN FULL, exactly as the server read what was typed — show it on the code screen with "Not your email? Change it" (M18G §4.3). It is the requester's own typing, echoed |
| `expires_at` | ten minutes from issue — UTC instant; show in the site's zone (`timezone`) |
| `resend_after` | a request before this reuses the code already sent — UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `estate_not_found` | No active estate by that id. |
| 422 | `email_domain_unreachable` | The address's domain has nowhere to deliver mail to ("…@gmail.comm") — nothing was sent. The message says so, and names the address it probably meant when there is one (M18G §4.2). |
| 422 | `email_not_found` | A sign-in (`intent: sign_in`) at an address no resident and no app account at this estate holds — no code was made: "We can't find that email. Check the spelling, or ask your estate office to add it." Stay on the sign-in screen and show it beside the email field (M80 §2.1). |
| 422 | `email_not_sent` | Only sample-data people hold this address, and the platform never mails them — no code was sent, and none is left to resend. Show the message: the resident asks the estate office to check the email on their household (M80 §2.1). |
| 503 | `sms_unavailable` | No SMS provider is connected, or the provider refused the code — nothing was texted. Sign in with email. |
| 503 | `mail_unavailable` | The code could not be emailed just now — nothing was sent, and the code is withdrawn. Try again in a minute. |
| 429 | `rate_limited` | Ten requests a minute per address; or five new codes in an hour for this destination at this estate, from any address; or, for a sign-in, thirty lookups an hour from one phone, or ten for one address whether or not the register holds it — nothing was sent, and `Retry-After` says when a new code can be asked for. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only.

### `auth.otp.verify`

Prove the code and receive this install's token. A new account is `pending` until the estate approves a unit claim; signing in again on the same install replaces its token. An address the estate office or the household's primary resident invited, proven by this code, arrives at `await_approval` with a claim already matched to its register entry (`claim.invited_by`), or at `home` when the estate trusts its primary residents' invites. `next` keeps its three values.

| | |
|---|---|
| Method and path | `POST /api/v1/auth/otp/verify` |
| App | Resident, before sign-in — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `estate` | string · required |
| `channel` | string · required |
| `destination` | string · required · as sent to `/auth/otp/request` |
| `code` | string · required · six digits |
| `device_uid` | string · required · a stable id for this install |
| `platform` | string · required · `ios` or `android` |
| `device_name` | string · optional · what the phone calls itself — its model ("SM-S938B", "iPhone 15"), at most 80 — so the account's list of signed-in phones (`me.sessions`) can name it. Never a serial or an IMEI |
| `hardware_id` | string · optional · the handset's own id (Android: the per-app device id, which a reinstall keeps; iOS: the vendor id), stored as a hash: signing in again on the same handset ENDS its earlier sign-ins of this account instead of adding one beside them (M49) |

**Response** `200`

| Key | Meaning |
|---|---|
| `token` | the bearer token — store it in the keychain/keystore |
| `abilities.*` | the Resident App's abilities, from the app matrix |
| `reviewer` | true ONLY for an App Store / Play reviewer's demonstration account, flagged on the SERVER. Draw the demonstration banner from it. False for every real resident. |
| `read_only` | boolean · false here — an ordinary sign-in can write. See `demo.enter`, which cannot. |
| `account.id` | the account |
| `account.status` | `pending` or `active` |
| `account.full_name` | or null |
| `estate.id` | the estate |
| `estate.name` | its name |
| `estate.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `claim` | null, or the account's latest claim |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | UTC instant; show in the site's zone (`timezone`) |
| `claim.invited_by` | who invited this person — the primary resident's name, or "the estate office" — present only on a claim that came from an app invite |
| `claim.invited_at` | UTC instant; show in the site's zone — present only on a claim that came from an app invite |
| `next` | `claim_unit`, `await_approval` or `home` — the screen to open |
| `restored` | boolean · false here; true from `auth.restore` — the account had been deleted and is back (M18H). Say so once: "Your account is back." |
| `fingerprint_sign_in` | boolean · whether THIS install still holds a live fingerprint key (`me.device_key.store`) — what "Face ID / fingerprint" shows once signed in (M49) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `account_deleted` | THE ACCOUNT AT THIS ADDRESS WAS DELETED AND CAN STILL COME BACK (M18H §2.3). No token. Open the restore screen with the message as sent ("This account was deleted on 27 September. You can restore it until 4 October and nothing will be lost.") and `error.details`: `deleted_on`, `restore_until`, `office_until` (estate-calendar dates), `can_restore` (the person's own window is open), `office_email` / `office_phone` (either may be null), and while `can_restore` a `ticket` (with `ticket_expires_at`, fifteen minutes) — "Restore my account" sends it to `auth.restore`. When `can_restore` is false the screen offers only "Contact the estate office". Once the estate's last window has closed nothing is refused: the address signs in as a new account. |
| 404 | `estate_not_found` | No active estate by that id. |
| 422 | `otp_invalid` | Wrong code; the message says how many tries are left. |
| 422 | `otp_expired` | Expired, used, or replaced by a newer code. |
| 423 | `otp_locked` | Five tries spent on this code, however many were in flight at once. Request a new one. |
| 429 | `rate_limited` | Ten requests a minute per address; or ten codes tried in an hour for this destination at this estate, across every code and from any address — even the right code is refused until `Retry-After`. |
| 403 | `account_suspended` | The estate withdrew access. |
| 403 | `account_revoked` | The estate office ended this account's access (Q-M44). Refused HERE, before a token exists — a sign-in that succeeds and an app that then fails at everything would be a fake success. Only the estate office can give it back. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only.

### `auth.restore`

"Restore my account" (M18H §2.3): bring back an account the person deleted, inside their own window, and sign this phone in. The ticket is the one `auth.otp.verify` sent with `409 account_deleted` — proof of the code, for that account on this install, good for fifteen minutes. Everything comes back as it was (unit, household, balance, history) except the visitor passes the deletion cancelled; the estate office is emailed. The answer is `auth.otp.verify`'s, with `restored: true`.

| | |
|---|---|
| Method and path | `POST /api/v1/auth/restore` |
| App | Resident, before sign-in — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `estate` | string · required |
| `ticket` | string · required · `error.details.ticket` from `409 account_deleted` |
| `device_uid` | string · required · the SAME install id the sign-in sent — a ticket works on the phone it was issued to and no other |
| `platform` | string · required · `ios` or `android` |
| `device_name` | string · what the phone calls itself, as `auth.otp.verify` takes it |
| `hardware_id` | string · optional · the handset's own id, as `auth.otp.verify` takes it |

**Response** `200`

| Key | Meaning |
|---|---|
| `token` | the bearer token — store it in the keychain/keystore |
| `abilities.*` | the Resident App's abilities, from the app matrix |
| `reviewer` | boolean · always false here — the demonstration account is never deleted, so it is never restored |
| `read_only` | boolean · false — a restored sign-in can write |
| `account.id` | the account |
| `account.status` | `pending` or `active` |
| `account.full_name` | or null |
| `estate.id` | the estate |
| `estate.name` | its name |
| `estate.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `claim` | null, or the account's latest claim |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | UTC instant; show in the site's zone (`timezone`) |
| `claim.invited_by` | who invited this person — the primary resident's name, or "the estate office" — present only on a claim that came from an app invite |
| `claim.invited_at` | UTC instant; show in the site's zone — present only on a claim that came from an app invite |
| `next` | `claim_unit`, `await_approval` or `home` — the screen to open |
| `restored` | boolean · true |
| `fingerprint_sign_in` | boolean · as `auth.otp.verify` |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `estate_not_found` | No active estate by that id. |
| 422 | `restore_ticket_invalid` | The ticket is more than fifteen minutes old, from another phone, or the account is no longer deleted. Sign in again: the sign-in offers the restore again while it can. |
| 409 | `restore_office_only` | The person's own window closed while the screen was open. `error.details` is `account_deleted`'s, with `can_restore` false: show "Contact the estate office". |
| 429 | `rate_limited` | Ten requests a minute per address. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only.

### `auth.device_key`

"Log in with biometrics" (board 03; M49): the phone's own prompt has opened its secure store, and the key `me.device_key.store` gave this install signs it in with no emailed code — exactly as `auth.otp.verify` does, with its answer. The key works only with the install id it was issued to and only at its estate; it stops working when the switch is turned off, the phone is signed out from another, or the account is deleted, revoked or suspended. Signing in on a handset (`hardware_id`) ends that handset's earlier sign-ins of this account.

| | |
|---|---|
| Method and path | `POST /api/v1/auth/device-key` |
| App | Resident, before sign-in — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `estate` | string · required |
| `key` | string · required · from the secure store |
| `device_uid` | string · required · the SAME install id the key was issued to |
| `platform` | string · required · `ios` or `android` |
| `device_name` | string · optional |
| `hardware_id` | string · optional · the handset's own id, as `me.device_key.store` takes it |

**Response** `200`

| Key | Meaning |
|---|---|
| `token` | the bearer token — store it in the keychain/keystore |
| `abilities.*` | the Resident App's abilities, from the app matrix |
| `reviewer` | boolean · as `auth.otp.verify`: true only for a reviewer's demonstration account |
| `read_only` | boolean · false |
| `account.id` | the account |
| `account.status` | `pending` or `active` |
| `account.full_name` | or null |
| `estate.id` | the estate |
| `estate.name` | its name |
| `estate.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `claim` | null, or the account's latest claim |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | UTC instant; show in the site's zone (`timezone`) |
| `claim.invited_by` | who invited this person — the primary resident's name, or "the estate office" — present only on a claim that came from an app invite |
| `claim.invited_at` | UTC instant; show in the site's zone — present only on a claim that came from an app invite |
| `next` | `claim_unit`, `await_approval` or `home` — the screen to open |
| `restored` | boolean · false |
| `fingerprint_sign_in` | boolean · true here: this install's key is live |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 401 | `device_key_invalid` | The key is not live: turned off, replaced, signed out from another phone, another install's, another estate's, or the account was deleted. Forget it on the phone and log in with the emailed code. |
| 403 | `account_revoked` | The estate office ended this account's access. Every key of the account stops working. |
| 403 | `account_suspended` | The estate withdrew this account's access. Every key of the account stops working. |
| 404 | `estate_not_found` | No active estate by that id. |
| 429 | `rate_limited` | Ten requests a minute. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only.

### `demo.enter`

"Explore a demo community" — a READ-ONLY session inside the demonstration estate, with no code and no account. The token carries the Resident App's abilities with every `:write` removed, so a write is refused at the route's own ability middleware and not merely hidden on screen. Label the whole session as a demonstration: the estate holds invented data and nobody lives there. 404 when this platform has no demonstration community — offer the control only when `estates.index` lists one. Every install gets a session of its own: another phone entering the demo never signs this one out.

| | |
|---|---|
| Method and path | `POST /api/v1/demo/enter` |
| App | Resident, before sign-in — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `device_uid` | string · optional · the same stable id for this install that `auth.otp.verify` takes — asking again with it replaces this install's demo session and nobody else's. Without it every entry is a session of its own, ended by the idle window. |

**Response** `200`

| Key | Meaning |
|---|---|
| `token` | the bearer token — store it in the keychain/keystore, exactly like a real one |
| `abilities.*` | the Resident App's READ abilities only |
| `reviewer` | always true |
| `read_only` | always true — nothing in this session can write to any estate |
| `account.id` | the demonstration account |
| `account.status` | `active` |
| `account.full_name` | its name, which says it is a demonstration account |
| `estate.id` | the demonstration estate |
| `estate.name` | its name, which says it is a demonstration estate |
| `estate.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `claim` | null, or the account's claim |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | UTC instant; show in the site's zone (`timezone`) |
| `claim.invited_by` | who invited this person — the primary resident's name, or "the estate office" — present only on a claim that came from an app invite |
| `claim.invited_at` | UTC instant; show in the site's zone — present only on a claim that came from an app invite |
| `next` | `home` |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `demo_unavailable` | This platform has no demonstration community. |
| 429 | `rate_limited` | Ten requests a minute per address. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only.

### `auth.claim_unit`

Claim a unit. The estate reviews it on its claims screen; once approved, the account is active on its next request. A pending claim is returned as it is (200). Board resident-app-04.

| | |
|---|---|
| Method and path | `POST /api/v1/auth/claim-unit` |
| App | Resident — pending accounts too |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `full_name` | string · required |
| `lot` | string · required · e.g. `47` or `Lot 47` |
| `phase` | string · additional (M18C §3.3) · e.g. `Phase 2` — extra help finding the lot |
| `phone` | string · required (M18C) · a phone number; stored as "+1 876 555 0123" |
| `relationship` | string · required (M18C) · `owner`, `tenant`, `spouse`, `child`, `parent`, `relative`, `other` |

**Response** `201`

| Key | Meaning |
|---|---|
| `account.id` | the account |
| `account.status` | `pending` |
| `account.full_name` | as claimed |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | UTC instant; show in the site's zone (`timezone`) |
| `claim.invited_by` | who invited this person — the primary resident's name, or "the estate office" — present only on a claim that came from an app invite |
| `claim.invited_at` | UTC instant; show in the site's zone — present only on a claim that came from an app invite |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `already_linked` | The account is already active on a unit. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only.

### `me.show`

The account, its claim, and once linked its unit, household and register entry. Poll while pending. Board resident-app-10.

| | |
|---|---|
| Method and path | `GET /api/v1/me` |
| App | Resident — pending accounts too |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `account.id` | the account |
| `account.status` | `pending` or `active` |
| `account.channel` | `email` or `sms` |
| `account.destination_hint` | masked, e.g. "a•••@example.com" |
| `account.full_name` | or null |
| `account.photo` | null, or the profile photo: fetch `account.photo.url` with this token (`me.photo`) |
| `account.photo.url` | a path under /api/v1 carrying the photo's version (`?v=`), so a changed photo is a new address |
| `account.photo.updated_at` | or null — UTC instant |
| `account.shown` | what this ACCOUNT has been shown once (M47 §3) — the record is the account's, so it holds on every phone, after a sign-out and after a reinstall |
| `account.shown.tour` | when the first-run tour was first finished or skipped — UTC instant — or null: show it |
| `estate.id` | the estate |
| `estate.name` | its name |
| `estate.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `claim` | null until a unit is claimed |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | UTC instant; show in the site's zone (`timezone`) |
| `claim.invited_by` | who invited this person — the primary resident's name, or "the estate office" — present only on a claim that came from an app invite |
| `claim.invited_at` | UTC instant; show in the site's zone — present only on a claim that came from an app invite |
| `unit` | null while pending |
| `unit.id` | the unit |
| `unit.reference` | e.g. "Lot 47" |
| `unit.phase` | e.g. "Phase 2" |
| `household` | null while pending |
| `household.id` | the household — the id `/households/{household}/…` takes |
| `household.name` | its name |
| `resident` | the register entry this account signs in as, or null |
| `resident.id` | the resident on the register |
| `resident.full_name` | name |
| `resident.relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `resident.is_primary` | the household's primary resident |
| `resident.status` | `verified`, or `pending` until the estate verifies them |
| `resident.phone` | or null — null on a record saved before every field was required (M18C), and a Helper's for anybody but the primary resident and the Helper (rules table row 20) |
| `resident.email` | or null — null when `alerts_to_primary`, on an older record, and a Helper's for anybody but the primary resident and the Helper (row 20) |
| `resident.alerts_to_primary` | boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach |
| `resident.access_level` | `member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release |
| `resident.access_window` | a Helper's days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release |
| `resident.access_window.days` | the days their e-Pass works, 1 (Monday) to 7 (Sunday) |
| `resident.access_window.days.*` | integer — a day, 1 (Monday) to 7 (Sunday) |
| `resident.access_window.from` | from, "07:00" on the estate's clock |
| `resident.access_window.to` | until, "19:00" — earlier than `from` is an overnight window |
| `resident.access_window.until` | A CALENDAR DATE: the last day it works — or null, no end |
| `resident.access_window.paused` | boolean — the household paused them: their e-Pass is cancelled and none is issued |
| `resident.access_window.own` | boolean — the household set these; false is the estate's default for a helper |
| `resident.access_window.line` | the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household" |
| `resident.invite.status` | `none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed` |
| `resident.invite.email` | the one address it is for, or null |
| `resident.invite.sent_at` | when the mail server last accepted it (UTC), or null — never merely when it was asked |
| `resident.invite.last_attempt_at` | the last try, sent or not (UTC), or null |
| `resident.invite.reason` | why it was not sent, in words to show as they are, or null |
| `resident.invite.expires_at` | when it lapses (UTC), or null |
| `resident.invite.resend_after` | when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now |
| `resident.invite.attempts` | how many times it was tried |
| `missing_details` | array of strings · what is still missing from this person's own register entry — `phone`, `email`, both or neither (M18C §5). Ask ONCE on sign-in, politely ("We need a phone number and an email so we can reach you in an emergency"), let it be dismissed, and ask again next time. Never block anything for it — a panic least of all |
| `missing_details.*` | `phone` or `email` |
| `drill_mode` | object or null · drill mode (M53 §3A.1): while the office has it on for this account, its Panic is a DRILL that wakes nobody. Say so on the Panic screen, with when it ends, so the person pressing it knows; null when it is off. Only the server sets it, and it ends by itself. Absent on a server older than this release |
| `drill_mode.until` | when it switches itself off — UTC instant |
| `drill_mode.until_local` | the same moment on the estate's clock, ready to show, e.g. "11:45 PM" |
| `drill_mode.reason` | why the office switched it on, in its own words, or null |
| `account_deletion` | this estate's deletion windows (M18H §1.2), for what "Delete my account" says BEFORE anything happens |
| `account_deletion.self_days` | integer · days after deleting in which the person restores it themselves, by signing in — 7 unless the estate set otherwise |
| `account_deletion.office_days` | integer · days after deleting in which the estate office can restore it; after that nothing of the person is left on it — 30 unless the estate set otherwise |
| `access` | null while pending, or what this person may do in the app. The SERVER is the rule and refuses the rest with `403 not_for_your_access_level`. Panic and the person's own e-pass are never removed and are not listed. Absent on a server older than this release |
| `access.level` | `member`, `dependent` or `helper` |
| `access.level_label` | "Member", "Dependent" or "Helper" |
| `access.is_primary` | boolean — the household's primary resident, who alone adds, invites, removes and changes the level of members |
| `access.primary_name` | the household's primary resident's name — who to ask to change this person's access — or null when the household has none. Absent on a server older than this release |
| `access.levels_open` | boolean — whether anybody may be given Dependent or Helper access yet. False until the server switches levels on: everybody is a Member, and `members.store`, `members.invite` and `members.level` refuse any other level with `409 access_levels_not_open` — so do not offer the choice. Absent on a server older than this release |
| `access.may.household` | boolean — may read the household's members, vehicles and emergency contacts |
| `access.may.household_edit` | boolean — may add and remove vehicles and emergency contacts |
| `access.may.manage_members` | boolean — may add, invite, remove and change the level of members (the primary resident only) |
| `access.may.visitor_passes` | boolean — may issue, change, share and cancel visitor passes |
| `access.may.approvals` | boolean — may answer a guard's walk-up request |
| `access.may.dues` | boolean — may read invoices, balance, statement and receipts |
| `access.may.payments` | boolean — may start a payment and manage AutoPay |
| `access.may.tickets` | boolean — may report and follow maintenance and security requests |
| `access.may.notices` | boolean — may read notices |
| `access.may.meetings` | boolean — may read meetings and RSVP |
| `access.may.elections` | boolean — may see elections and cast the household's ballot |
| `access.may.bookings` | boolean — may book amenities |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Serve the cache.

### `me.update`

Change the account's display name and the register's phone and email. The name on the register is the estate's.

| | |
|---|---|
| Method and path | `PATCH /api/v1/me` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `full_name` | string · sent only when changed |
| `phone` | string · sent only when changed · never blank (M18C) · a phone number; stored as "+1 876 555 0123" |
| `email` | string · sent only when changed · never blank |

**Response** `200`

| Key | Meaning |
|---|---|
| `account.id` | the account |
| `account.status` | `pending` or `active` |
| `account.channel` | `email` or `sms` |
| `account.destination_hint` | masked, e.g. "a•••@example.com" |
| `account.full_name` | or null |
| `account.photo` | null, or the profile photo: fetch `account.photo.url` with this token (`me.photo`) |
| `account.photo.url` | a path under /api/v1 carrying the photo's version (`?v=`), so a changed photo is a new address |
| `account.photo.updated_at` | or null — UTC instant |
| `account.shown` | what this ACCOUNT has been shown once (M47 §3) — the record is the account's, so it holds on every phone, after a sign-out and after a reinstall |
| `account.shown.tour` | when the first-run tour was first finished or skipped — UTC instant — or null: show it |
| `estate.id` | the estate |
| `estate.name` | its name |
| `estate.timezone` | the site's IANA time zone, e.g. America/Jamaica — every time in this API is a UTC instant; SHOW it in this zone (never the handset's, never a fixed offset) |
| `claim` | null until a unit is claimed |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | UTC instant; show in the site's zone (`timezone`) |
| `claim.invited_by` | who invited this person — the primary resident's name, or "the estate office" — present only on a claim that came from an app invite |
| `claim.invited_at` | UTC instant; show in the site's zone — present only on a claim that came from an app invite |
| `unit` | null while pending |
| `unit.id` | the unit |
| `unit.reference` | e.g. "Lot 47" |
| `unit.phase` | e.g. "Phase 2" |
| `household` | null while pending |
| `household.id` | the household — the id `/households/{household}/…` takes |
| `household.name` | its name |
| `resident` | the register entry this account signs in as, or null |
| `resident.id` | the resident on the register |
| `resident.full_name` | name |
| `resident.relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `resident.is_primary` | the household's primary resident |
| `resident.status` | `verified`, or `pending` until the estate verifies them |
| `resident.phone` | or null — null on a record saved before every field was required (M18C), and a Helper's for anybody but the primary resident and the Helper (rules table row 20) |
| `resident.email` | or null — null when `alerts_to_primary`, on an older record, and a Helper's for anybody but the primary resident and the Helper (row 20) |
| `resident.alerts_to_primary` | boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach |
| `resident.access_level` | `member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release |
| `resident.access_window` | a Helper's days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release |
| `resident.access_window.days` | the days their e-Pass works, 1 (Monday) to 7 (Sunday) |
| `resident.access_window.days.*` | integer — a day, 1 (Monday) to 7 (Sunday) |
| `resident.access_window.from` | from, "07:00" on the estate's clock |
| `resident.access_window.to` | until, "19:00" — earlier than `from` is an overnight window |
| `resident.access_window.until` | A CALENDAR DATE: the last day it works — or null, no end |
| `resident.access_window.paused` | boolean — the household paused them: their e-Pass is cancelled and none is issued |
| `resident.access_window.own` | boolean — the household set these; false is the estate's default for a helper |
| `resident.access_window.line` | the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household" |
| `resident.invite.status` | `none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed` |
| `resident.invite.email` | the one address it is for, or null |
| `resident.invite.sent_at` | when the mail server last accepted it (UTC), or null — never merely when it was asked |
| `resident.invite.last_attempt_at` | the last try, sent or not (UTC), or null |
| `resident.invite.reason` | why it was not sent, in words to show as they are, or null |
| `resident.invite.expires_at` | when it lapses (UTC), or null |
| `resident.invite.resend_after` | when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now |
| `resident.invite.attempts` | how many times it was tried |
| `missing_details` | array of strings · what is still missing from this person's own register entry — `phone`, `email`, both or neither (M18C §5). Ask ONCE on sign-in, politely ("We need a phone number and an email so we can reach you in an emergency"), let it be dismissed, and ask again next time. Never block anything for it — a panic least of all |
| `missing_details.*` | `phone` or `email` |
| `drill_mode` | object or null · drill mode (M53 §3A.1): while the office has it on for this account, its Panic is a DRILL that wakes nobody. Say so on the Panic screen, with when it ends, so the person pressing it knows; null when it is off. Only the server sets it, and it ends by itself. Absent on a server older than this release |
| `drill_mode.until` | when it switches itself off — UTC instant |
| `drill_mode.until_local` | the same moment on the estate's clock, ready to show, e.g. "11:45 PM" |
| `drill_mode.reason` | why the office switched it on, in its own words, or null |
| `account_deletion` | this estate's deletion windows (M18H §1.2), for what "Delete my account" says BEFORE anything happens |
| `account_deletion.self_days` | integer · days after deleting in which the person restores it themselves, by signing in — 7 unless the estate set otherwise |
| `account_deletion.office_days` | integer · days after deleting in which the estate office can restore it; after that nothing of the person is left on it — 30 unless the estate set otherwise |
| `access` | null while pending, or what this person may do in the app. The SERVER is the rule and refuses the rest with `403 not_for_your_access_level`. Panic and the person's own e-pass are never removed and are not listed. Absent on a server older than this release |
| `access.level` | `member`, `dependent` or `helper` |
| `access.level_label` | "Member", "Dependent" or "Helper" |
| `access.is_primary` | boolean — the household's primary resident, who alone adds, invites, removes and changes the level of members |
| `access.primary_name` | the household's primary resident's name — who to ask to change this person's access — or null when the household has none. Absent on a server older than this release |
| `access.levels_open` | boolean — whether anybody may be given Dependent or Helper access yet. False until the server switches levels on: everybody is a Member, and `members.store`, `members.invite` and `members.level` refuse any other level with `409 access_levels_not_open` — so do not offer the choice. Absent on a server older than this release |
| `access.may.household` | boolean — may read the household's members, vehicles and emergency contacts |
| `access.may.household_edit` | boolean — may add and remove vehicles and emergency contacts |
| `access.may.manage_members` | boolean — may add, invite, remove and change the level of members (the primary resident only) |
| `access.may.visitor_passes` | boolean — may issue, change, share and cancel visitor passes |
| `access.may.approvals` | boolean — may answer a guard's walk-up request |
| `access.may.dues` | boolean — may read invoices, balance, statement and receipts |
| `access.may.payments` | boolean — may start a payment and manage AutoPay |
| `access.may.tickets` | boolean — may report and follow maintenance and security requests |
| `access.may.notices` | boolean — may read notices |
| `access.may.meetings` | boolean — may read meetings and RSVP |
| `access.may.elections` | boolean — may see elections and cast the household's ballot |
| `access.may.bookings` | boolean — may book amenities |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `me.shown`

Record that this account has been shown something meant to appear once — the first-run tour, finished or skipped — so it is never shown again on any phone (M47 §3). Idempotent: the first time stands.

| | |
|---|---|
| Method and path | `POST /api/v1/me/shown` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `key` | string · required · `tour` |

**Response** `200`

| Key | Meaning |
|---|---|
| `shown` | what this account has been shown once, as `account.shown` in `me.show` |
| `shown.tour` | when the tour was first finished or skipped — UTC instant |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `validation_failed` | Not something the app shows once. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Send when there is a connection; the phone remembers it meanwhile.

### `me.delete_account`

Delete this account (both app stores require it in the app). DELETED IS A STATE, NOT AN ERASURE (M18H): at once, every sign-in on every phone ends, the e-pass and every unused visitor pass the person sent stop working at the gate, their future bookings are cancelled (a held deposit refunded), every push address goes, and their name leaves the estate's active register. Nothing is erased yet, and what belongs to the home — dues, the ledger, the gate log, incidents, ballots, the audit trail — never is. The person can restore it by signing in until `restore_until`; the estate office can until `permanent_on`, after which nothing of the person is left on it. An email says so at once. Never queued: it happens while the person watches, or not at all.

| | |
|---|---|
| Method and path | `DELETE /api/v1/me` |
| App | Resident — pending accounts too |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `confirm` | string · required · the word the app showed the person on the confirmation screen (`DELETE`), so a mis-tap cannot do this |

**Response** `200`

| Key | Meaning |
|---|---|
| `deleted` | true |
| `reference` | the reference to quote if they ask us about it afterwards |
| `deleted_at` | ISO 8601 |
| `restore_until` | the last estate-calendar date (`YYYY-MM-DD`) the person can restore it by signing in — say it on the screen that follows |
| `permanent_on` | the estate-calendar date after which nothing of the person is left on it; until then the estate office can restore it |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `confirm_required` | The confirmation word was not sent. |
| 409 | `demo_account_kept` | The App Store / Play reviewer's DEMONSTRATION account. It is never erased: deleting it would take the credentials in STORE_REVIEW_NOTES.md and the "Explore a demo community" door away from the next reviewer. Show the message as it is sent — it says what would have happened on a real account. False for every real resident. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only, and never queued.

### `me.photo`

The account's profile photo — THE FILE ITSELF, not JSON. Only with this token: there is no public address for it. Ask with the `?v=` that `me.show` gives, so a phone may cache it until the photo changes.

| | |
|---|---|
| Method and path | `GET /api/v1/me/photo` |
| App | Resident — pending accounts too |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `v` | string · optional (query) · the version from `account.photo.url` |

**Response** `200`

No success body: this endpoint answers with an error envelope.

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `no_photo` | This account has no photo. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Needs a connection. A photo already fetched stays on the phone.

### `me.data_export`

"Download my data" (board resident-app-33, M50 §4.3): everything GeminiSecure holds about the signed-in person, as a PDF — THE FILE ITSELF, not JSON (`Content-Type: application/pdf`; `Content-Disposition` carries its name, "GeminiSecure-my-data-YYYY-MM-DD.pdf"). The account, its sign-ins and phones, the register entry, the household as the person's access reaches it, the passes they sent, the visitors they answered, their requests, bookings, payments, cards and AutoPay, their panic alerts and what was said on them, their claim, their invites, the notices they opened and the meetings they answered — and what the file leaves out, and how to ask for it. Only their own: nothing in the request names anybody. Audited (`resident_account.data_exported`), with how much went in and never what.

| | |
|---|---|
| Method and path | `GET /api/v1/me/data-export` |
| App | Resident — pending accounts too |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-data-export` |

**Request**

No body.

**Response** `200`

No success body: this endpoint answers with an error envelope.

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `too_many_requests` | Three a minute, twelve an hour, from one phone. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Online only. The phone hands the file to the share sheet and keeps no copy.

### `support.contact`

How to reach Gemini Security on Help & support (board resident-app-39): its phone line as the console set it — null while none is, and then show NO "Call Gemini Security" at all (no placeholder, no button that rings nothing) — and its address.

| | |
|---|---|
| Method and path | `GET /api/v1/support/contact` |
| App | Resident — pending accounts too |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `company` | "Gemini Security" |
| `phone` | the number to call, international form ("+18765550123") — null while the console has none |
| `phone_display` | the same number as a person reads it ("+1 876 555 0123") — null while there is none |
| `email` | the company's support address |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Cached: the line as last read.

### `support.chat.show`

"Live chat" on Help & support (board resident-app-39, M50 §4.3): the account's one conversation with Gemini Security, oldest message first (the newest 200). Reading it marks every answer in it delivered. Gemini Security's control room answers from its console; `chat.changed` on the account's own channel says to read this again, and an answer is pushed (`support_reply`).

| | |
|---|---|
| Method and path | `GET /api/v1/support/chat` |
| App | Resident — pending accounts too |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | integer — the conversation, or null before the first message |
| `open` | boolean — false once Gemini Security marked it resolved; the next message opens it again |
| `messages.*.id` | integer — the message |
| `messages.*.from` | `you` or `support` |
| `messages.*.name` | the first name of the member of Gemini Security's staff who answered — null on the account's own messages |
| `messages.*.body` | what was written, as written |
| `messages.*.at` | UTC instant it reached the server; show in the site's zone |
| `messages.*.seen_at` | UTC instant the other side was shown it ("Delivered") — null until then ("Sent") |
| `messages.*.client_key` | the phone's own id for its message, so a queued copy is matched to this one — null on an answer |
| `typical_reply_minutes` | integer — how long Gemini Security typically takes to answer, measured over the last 30 days; null until there are enough answers to measure — then say who answers, never a speed |
| `answered_by` | "Gemini Security" |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Cached: the conversation as last read, with what is waiting in the queue drawn under it.

### `support.chat.messages.store`

Write to Gemini Security on Live chat (M50 §4.3). The first message starts the conversation; a message to a resolved one opens it again. It reaches the control room's Live chat tab and bell at once. Not for emergencies: the screen says so, and points to the panic button.

| | |
|---|---|
| Method and path | `POST /api/v1/support/chat/messages` |
| App | Resident — pending accounts too |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `body` | string · required · what to say, up to 1,000 characters kept (longer is refused) |
| `client_key` | string · optional · the phone's own id for this message (a UUID). The same key again is the same message, never a second one — send it from the queue as often as the signal needs |

**Response** `201`

| Key | Meaning |
|---|---|
| `message.id` | integer — the message |
| `message.from` | `you` |
| `message.body` | what was written |
| `message.at` | UTC instant it reached the server |
| `message.seen_at` | null — until Gemini Security is shown it |
| `message.client_key` | the key it was sent with, or null |
| `chat.id` | integer — the conversation |
| `chat.open` | boolean — true: a message opens a resolved conversation again |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `demonstration_community` | The demonstration community: nobody reads its Live chat. Say the sentence given. |
| 409 | `message_conflict` | The same message is being sent from the queue at the same moment; read the conversation again. |
| 422 | `message_empty` | Nothing to send. |
| 422 | `validation_failed` | More than 1,000 characters. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue it with its `client_key` and send the moment there is a signal; draw it as "Waiting for a connection" until then and never drop it. A refusal is shown on the message with the sentence given.

### `me.photo.store`

Set the account's profile photo (multipart, field `file`): a JPEG, PNG or HEIC of at most 5 MB, made small on the phone and written out afresh with no EXIF. It replaces the one before.

| | |
|---|---|
| Method and path | `POST /api/v1/me/photo` |
| App | Resident — pending accounts too |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `file` | file · required · JPEG, PNG or HEIC, at most 5 MB |

**Response** `201`

| Key | Meaning |
|---|---|
| `photo` | the photo, as `account.photo` in `me.show` |
| `photo.url` | a path under /api/v1 with the new version |
| `photo.updated_at` | UTC instant |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `validation_failed` | Not a picture, or larger than 5 MB. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue the file.

### `me.photo.remove`

Remove the profile photo: the file is deleted and the account shows its initials again.

| | |
|---|---|
| Method and path | `DELETE /api/v1/me/photo` |
| App | Resident — pending accounts too |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `photo` | always null |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `me.sessions`

Every phone signed in to this account, THIS phone first — Security, "Signed-in phones". A sign-in past the idle window is listed too (`timed_out`): it must sign in again to be used, but it still gets this account's notifications until it is signed out here. The demonstration account lists only the phone asking.

| | |
|---|---|
| Method and path | `GET /api/v1/me/sessions` |
| App | Resident — pending accounts too |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the sign-in; send it to `me.sessions.end` |
| `items.*.device` | what the phone called itself when it signed in ("SM-S938B", "iPhone 15"), or null for a sign-in made by an older app — then say "A phone" |
| `items.*.platform` | `ios`, `android`, or null |
| `items.*.signed_in_at` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.last_used_at` | UTC instant; show in the site's zone (`timezone`) — its last request, or when it signed in |
| `items.*.current` | true for the phone making this request |
| `items.*.timed_out` | true when it has been idle past the window: it has to sign in again, and it still gets notifications until signed out |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Serve the cache with its age; signing a phone out needs a connection.

### `me.sessions.end`

Sign one of this account's OTHER phones out: its sign-in is deleted, so its next request is refused, and it gets no more notifications. Not this phone — that is "Sign out of this phone" (`auth.sign_out`), which also clears what the app keeps on it.

| | |
|---|---|
| Method and path | `DELETE /api/v1/me/sessions/{session}` |
| App | Resident — pending accounts too |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `session` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the sign-in that was ended |
| `signed_out` | always true |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such sign-in on this account — already signed out, or never this account's. |
| 409 | `this_phone` | The id is the phone making the request. Use "Sign out of this phone". |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only, and never queued: a phone is signed out while the person watches, or not at all.

### `me.sessions.end_others`

Sign out EVERY other phone of this account at once (the client, 27 Sep) — each exactly as `me.sessions.end` signs one out, and each audited. This phone stays signed in. The demonstration account signs nobody out: its other sign-ins are other people's.

| | |
|---|---|
| Method and path | `DELETE /api/v1/me/sessions` |
| App | Resident — pending accounts too |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `signed_out` | how many phones were signed out — 0 when there were none |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only, and never queued.

### `me.device_key.store`

"Face ID / fingerprint" turned ON on this phone (board 19; M49): a key for THIS install — the token's own install, never one the body names — returned ONCE. Keep it in the secure store behind the phone's own biometric prompt (expo-secure-store `requireAuthentication`); it outlives a sign-out, and `auth.device_key` signs the phone back in with it, no emailed code. Any earlier key of this install, or of this handset (`hardware_id`), stops working. Every access level may: a Helper signs in too. Not from a demonstration session.

| | |
|---|---|
| Method and path | `POST /api/v1/me/device-key` |
| App | Resident — pending accounts too |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `platform` | string · required · `ios` or `android` |
| `device_name` | string · optional · what the phone calls itself, as `auth.otp.verify` takes it |
| `hardware_id` | string · optional · the handset's own id (Android: the per-app device id; iOS: the vendor id) — stored as a hash |

**Response** `201`

| Key | Meaning |
|---|---|
| `key` | the key, in the clear, this once — never sent again, never shown |
| `on` | always true |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_this_phone` | A demonstration session, or a token that is not a Resident App install's own. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Online only, and never queued: the switch is on only when the server has said so.

### `me.device_key.destroy`

"Face ID / fingerprint" turned OFF on this phone (M49): this install's key stops working at once. Delete it from the secure store as well.

| | |
|---|---|
| Method and path | `DELETE /api/v1/me/device-key` |
| App | Resident — pending accounts too |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `on` | always false |
| `revoked` | how many keys stopped working — 0 when there was none |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Best effort when there is no connection: forget the key on the phone, and turn it off on the server the next time this phone is online.

### `me.notifications.email.show`

"Email" under By channel (board 20; M50 §4.2 #4): whether the news a push carries also reaches this account by EMAIL — to the address it signs in with. The server is the truth for this switch (it is the account's, not the phone's). Off until the resident turns it on.

| | |
|---|---|
| Method and path | `GET /api/v1/me/notifications/email` |
| App | Resident — pending accounts too |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `on` | boolean |
| `address` | where the emails go — the address this account signs in with; null for an account that does not sign in by email |
| `muted.*` | the categories switched off, so not emailed — the same names `push.register` takes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Read it when the screen opens; with no connection, show the last answer.

### `me.notifications.email.update`

"Email" switched on or off (M50 §4.2 #4). Send `muted` with it — and again whenever a category switch changes while Email is on — so an email never carries what this resident switched off. Who is emailed and when: every Resident App push this account is sent, once per record inside the push's coalescing window, except "somebody at the gate" (answered in the app within minutes). Emails follow whether or not a phone is registered for push, and quiet hours do not hold them.

| | |
|---|---|
| Method and path | `PUT /api/v1/me/notifications/email` |
| App | Resident — pending accounts too |
| Ability | `alerts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `on` | boolean · required |
| `muted` | array of strings · optional · the categories switched off in the app: `visitors`, `notices`, `maintenance`, `payments`, `meetings`, `voting`, `bookings`. Anything else is dropped. Left out: kept as it was |

**Response** `200`

| Key | Meaning |
|---|---|
| `on` | boolean — as saved |
| `address` | where the emails go |
| `muted.*` | the categories not emailed, as saved |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Online only, and never queued: the switch shows what the server said.

### `me.household`

The home screen's household: members, vehicles, pets, emergency contacts, and any guard waiting on an answer at the gate. Boards resident-app-02, -09, -17. `pending_approvals` is empty for a person whose access level cannot answer the gate.

| | |
|---|---|
| Method and path | `GET /api/v1/me/household` |
| App | Resident |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `household.id` | the household |
| `household.name` | its name |
| `can_manage_members` | boolean — true only for the household's PRIMARY resident, who alone may add or remove members |
| `unit.id` | the unit |
| `unit.reference` | reference |
| `unit.phase` | phase |
| `members.*.id` | the resident on the register |
| `members.*.full_name` | name |
| `members.*.relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `members.*.is_primary` | the household's primary resident |
| `members.*.status` | `verified`, or `pending` until the estate verifies them |
| `members.*.phone` | or null — null on a record saved before every field was required (M18C), and a Helper's for anybody but the primary resident and the Helper (rules table row 20) |
| `members.*.email` | or null — null when `alerts_to_primary`, on an older record, and a Helper's for anybody but the primary resident and the Helper (row 20) |
| `members.*.alerts_to_primary` | boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach |
| `members.*.access_level` | `member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release |
| `members.*.access_window` | a Helper's days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release |
| `members.*.access_window.days` | the days their e-Pass works, 1 (Monday) to 7 (Sunday) |
| `members.*.access_window.days.*` | integer — a day, 1 (Monday) to 7 (Sunday) |
| `members.*.access_window.from` | from, "07:00" on the estate's clock |
| `members.*.access_window.to` | until, "19:00" — earlier than `from` is an overnight window |
| `members.*.access_window.until` | A CALENDAR DATE: the last day it works — or null, no end |
| `members.*.access_window.paused` | boolean — the household paused them: their e-Pass is cancelled and none is issued |
| `members.*.access_window.own` | boolean — the household set these; false is the estate's default for a helper |
| `members.*.access_window.line` | the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household" |
| `members.*.invite.status` | `none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed` |
| `members.*.invite.email` | the one address it is for, or null |
| `members.*.invite.sent_at` | when the mail server last accepted it (UTC), or null — never merely when it was asked |
| `members.*.invite.last_attempt_at` | the last try, sent or not (UTC), or null |
| `members.*.invite.reason` | why it was not sent, in words to show as they are, or null |
| `members.*.invite.expires_at` | when it lapses (UTC), or null |
| `members.*.invite.resend_after` | when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now |
| `members.*.invite.attempts` | how many times it was tried |
| `vehicles.*.id` | the vehicle |
| `vehicles.*.plate` | upper-cased |
| `vehicles.*.make` | or null |
| `vehicles.*.model` | or null |
| `vehicles.*.colour` | or null |
| `pets` | the household's pets, by name (M50 §5.3d) — drawn as pills beside the vehicles. Absent on a server older than this release |
| `pets.*.id` | the pet |
| `pets.*.name` | its name |
| `pets.*.kind` | `dog`, `cat`, `bird`, `fish`, `rabbit` or `other` |
| `pets.*.kind_label` | the kind in words to show, "Dog" |
| `emergency_contacts.*.id` | the contact |
| `emergency_contacts.*.name` | name |
| `emergency_contacts.*.relationship` | or null |
| `emergency_contacts.*.phone` | number |
| `emergency_contacts.*.email` | or null — a panic emails the contact at once (M12 §2.5) |
| `pending_approvals.*.id` | a walk-up request to answer with `/gate/approval/{id}/respond` |
| `pending_approvals.*.visitor_name` | who is at the gate |
| `pending_approvals.*.purpose` | or null |
| `pending_approvals.*.vehicle_plate` | or null |
| `pending_approvals.*.post_name` | which gate |
| `pending_approvals.*.guard_name` | the guard asking |
| `pending_approvals.*.requested_at` | UTC instant; show in the site's zone (`timezone`) |
| `pending_approvals.*.respond_by` | after this the estate's policy applies — UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache. Pending approvals are live only.

### `members.index`

The household's members on the register.

| | |
|---|---|
| Method and path | `GET /api/v1/household-members` |
| App | Resident |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the resident on the register |
| `items.*.full_name` | name |
| `items.*.relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `items.*.is_primary` | the household's primary resident |
| `items.*.status` | `verified`, or `pending` until the estate verifies them |
| `items.*.phone` | or null — null on a record saved before every field was required (M18C), and a Helper's for anybody but the primary resident and the Helper (rules table row 20) |
| `items.*.email` | or null — null when `alerts_to_primary`, on an older record, and a Helper's for anybody but the primary resident and the Helper (row 20) |
| `items.*.alerts_to_primary` | boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach |
| `items.*.access_level` | `member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release |
| `items.*.access_window` | a Helper's days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release |
| `items.*.access_window.days` | the days their e-Pass works, 1 (Monday) to 7 (Sunday) |
| `items.*.access_window.days.*` | integer — a day, 1 (Monday) to 7 (Sunday) |
| `items.*.access_window.from` | from, "07:00" on the estate's clock |
| `items.*.access_window.to` | until, "19:00" — earlier than `from` is an overnight window |
| `items.*.access_window.until` | A CALENDAR DATE: the last day it works — or null, no end |
| `items.*.access_window.paused` | boolean — the household paused them: their e-Pass is cancelled and none is issued |
| `items.*.access_window.own` | boolean — the household set these; false is the estate's default for a helper |
| `items.*.access_window.line` | the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household" |
| `items.*.invite.status` | `none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed` |
| `items.*.invite.email` | the one address it is for, or null |
| `items.*.invite.sent_at` | when the mail server last accepted it (UTC), or null — never merely when it was asked |
| `items.*.invite.last_attempt_at` | the last try, sent or not (UTC), or null |
| `items.*.invite.reason` | why it was not sent, in words to show as they are, or null |
| `items.*.invite.expires_at` | when it lapses (UTC), or null |
| `items.*.invite.resend_after` | when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now |
| `items.*.invite.attempts` | how many times it was tried |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `members.store`

Add a household member — the PRIMARY resident only. They are `pending` until the estate verifies them. With an email, they are INVITED to the Resident App at once, and `invite` says whether it went. When the invite cannot go — this address can't be invited from the app, or the day's limit — the member is still added and `invite` says so (`not_sent`, with the reason to show).

| | |
|---|---|
| Method and path | `POST /api/v1/household-members` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `full_name` | string · required |
| `relationship` | string · required · `spouse`, `child`, `parent`, `relative`, `tenant`, `domestic_staff`, `other` |
| `phone` | string · required (M18C: every field of a new member is required) · a phone number — spaces, dashes, a leading 1 or +1 accepted; stored as "+1 876 555 0123" |
| `email` | string · required unless `no_email` is true, and refused when it is |
| `no_email` | boolean · "This person has no email — alerts go to me instead" (M18C §3.2): a young child, an elderly relative. Records the primary resident as the one their alerts reach |
| `invite` | boolean · optional · send them an invite to the Resident App now — true when this is left out (an app that predates it sends none) |
| `access_level` | string · optional · `member`, `dependent`, `helper` — defaults to `member` |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the resident on the register |
| `full_name` | name |
| `relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `is_primary` | the household's primary resident |
| `status` | `verified`, or `pending` until the estate verifies them |
| `phone` | or null — null on a record saved before every field was required (M18C), and a Helper's for anybody but the primary resident and the Helper (rules table row 20) |
| `email` | or null — null when `alerts_to_primary`, on an older record, and a Helper's for anybody but the primary resident and the Helper (row 20) |
| `alerts_to_primary` | boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach |
| `access_level` | `member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release |
| `access_window` | a Helper's days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release |
| `access_window.days` | the days their e-Pass works, 1 (Monday) to 7 (Sunday) |
| `access_window.days.*` | integer — a day, 1 (Monday) to 7 (Sunday) |
| `access_window.from` | from, "07:00" on the estate's clock |
| `access_window.to` | until, "19:00" — earlier than `from` is an overnight window |
| `access_window.until` | A CALENDAR DATE: the last day it works — or null, no end |
| `access_window.paused` | boolean — the household paused them: their e-Pass is cancelled and none is issued |
| `access_window.own` | boolean — the household set these; false is the estate's default for a helper |
| `access_window.line` | the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household" |
| `invite.status` | `none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed` |
| `invite.email` | the one address it is for, or null |
| `invite.sent_at` | when the mail server last accepted it (UTC), or null — never merely when it was asked |
| `invite.last_attempt_at` | the last try, sent or not (UTC), or null |
| `invite.reason` | why it was not sent, in words to show as they are, or null |
| `invite.expires_at` | when it lapses (UTC), or null |
| `invite.resend_after` | when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now |
| `invite.attempts` | how many times it was tried |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_primary` | Only the household's primary resident can add members. |
| 409 | `member_exists` | A current member of this household has the same email, or the same name and phone (M54 §2). Nobody was added; show the message as it is sent. |
| 409 | `access_levels_not_open` | An `access_level` other than `member` while levels are not open yet (`me.show.access.levels_open` is false). Nobody was added; show the message as it is sent. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue. An add saved while offline is sent with `invite: false`, and the app offers Send invite once the member is on the server: an invite is an online-only write. A server also sends none for an add whose `X-Device-Time` is more than 15 minutes old (an older app's replay); `invite` then reads `not_sent` with the reason.

### `members.invite`

Send — or RESEND — a pending member's invite to the Resident App, by email, now. The PRIMARY resident only. A resend waits 1, then 2, then 5 minutes after each send; `invite.resend_after` says when. Audited.

| | |
|---|---|
| Method and path | `POST /api/v1/household-members/{member}/invite` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `member` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `access_level` | string · optional · `member`, `dependent`, `helper` — set this person's level as the invite goes; left out, their level is unchanged. Nothing changes when the invite is refused |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the resident on the register |
| `full_name` | name |
| `relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `is_primary` | the household's primary resident |
| `status` | `verified`, or `pending` until the estate verifies them |
| `phone` | or null — null on a record saved before every field was required (M18C), and a Helper's for anybody but the primary resident and the Helper (rules table row 20) |
| `email` | or null — null when `alerts_to_primary`, on an older record, and a Helper's for anybody but the primary resident and the Helper (row 20) |
| `alerts_to_primary` | boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach |
| `access_level` | `member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release |
| `access_window` | a Helper's days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release |
| `access_window.days` | the days their e-Pass works, 1 (Monday) to 7 (Sunday) |
| `access_window.days.*` | integer — a day, 1 (Monday) to 7 (Sunday) |
| `access_window.from` | from, "07:00" on the estate's clock |
| `access_window.to` | until, "19:00" — earlier than `from` is an overnight window |
| `access_window.until` | A CALENDAR DATE: the last day it works — or null, no end |
| `access_window.paused` | boolean — the household paused them: their e-Pass is cancelled and none is issued |
| `access_window.own` | boolean — the household set these; false is the estate's default for a helper |
| `access_window.line` | the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household" |
| `invite.status` | `none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed` |
| `invite.email` | the one address it is for, or null |
| `invite.sent_at` | when the mail server last accepted it (UTC), or null — never merely when it was asked |
| `invite.last_attempt_at` | the last try, sent or not (UTC), or null |
| `invite.reason` | why it was not sent, in words to show as they are, or null |
| `invite.expires_at` | when it lapses (UTC), or null |
| `invite.resend_after` | when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now |
| `invite.attempts` | how many times it was tried |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_primary` | Only the household's primary resident can invite members. |
| 404 | `not_found` | Not a member of this household — the same answer as an id nobody has. |
| 409 | `invite_wait` | It was sent moments ago, or this address has had five invites today; the message says when it can go again. For the first, so does `invite.resend_after`. |
| 409 | `invite_address_refused` | This address can't be invited from the app. Ask the estate office. (The reason is recorded for the office only; after five such refusals in a day, every invite this primary resident sends from the app gets this answer until the day is up.) |
| 409 | `invite_not_pending` | They are already confirmed on the register; there is nothing to invite them to. |
| 409 | `primary_is_member` | An `access_level` other than `member` for the primary resident, who always has Member access. Nothing was sent. |
| 409 | `access_levels_not_open` | An `access_level` other than `member` while levels are not open yet (`me.show.access.levels_open` is false). Nothing was sent and nothing changed; show the message as it is sent. |
| 422 | `invite_no_email` | They have no email address to send it to. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only. Whether it went is the answer, and a send queued for later could not give it.

### `members.invite_cancel`

Cancel a pending member's invite. The PRIMARY resident only. Removing the member cancels it as well. Audited.

| | |
|---|---|
| Method and path | `DELETE /api/v1/household-members/{member}/invite` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `member` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the resident on the register |
| `full_name` | name |
| `relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `is_primary` | the household's primary resident |
| `status` | `verified`, or `pending` until the estate verifies them |
| `phone` | or null — null on a record saved before every field was required (M18C), and a Helper's for anybody but the primary resident and the Helper (rules table row 20) |
| `email` | or null — null when `alerts_to_primary`, on an older record, and a Helper's for anybody but the primary resident and the Helper (row 20) |
| `alerts_to_primary` | boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach |
| `access_level` | `member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release |
| `access_window` | a Helper's days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release |
| `access_window.days` | the days their e-Pass works, 1 (Monday) to 7 (Sunday) |
| `access_window.days.*` | integer — a day, 1 (Monday) to 7 (Sunday) |
| `access_window.from` | from, "07:00" on the estate's clock |
| `access_window.to` | until, "19:00" — earlier than `from` is an overnight window |
| `access_window.until` | A CALENDAR DATE: the last day it works — or null, no end |
| `access_window.paused` | boolean — the household paused them: their e-Pass is cancelled and none is issued |
| `access_window.own` | boolean — the household set these; false is the estate's default for a helper |
| `access_window.line` | the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household" |
| `invite.status` | `none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed` |
| `invite.email` | the one address it is for, or null |
| `invite.sent_at` | when the mail server last accepted it (UTC), or null — never merely when it was asked |
| `invite.last_attempt_at` | the last try, sent or not (UTC), or null |
| `invite.reason` | why it was not sent, in words to show as they are, or null |
| `invite.expires_at` | when it lapses (UTC), or null |
| `invite.resend_after` | when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now |
| `invite.attempts` | how many times it was tried |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_primary` | Only the household's primary resident can cancel invites. |
| 404 | `not_found` | Not a member of this household — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only.

### `members.level`

Change a member's access level — Member, Dependent or Helper — the PRIMARY resident only. Takes effect on their next request; nobody is signed out. Audited.

| | |
|---|---|
| Method and path | `PATCH /api/v1/household-members/{member}/access-level` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `member` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `access_level` | string · required · `member`, `dependent`, `helper` |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the resident on the register |
| `full_name` | name |
| `relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `is_primary` | the household's primary resident |
| `status` | `verified`, or `pending` until the estate verifies them |
| `phone` | or null — null on a record saved before every field was required (M18C), and a Helper's for anybody but the primary resident and the Helper (rules table row 20) |
| `email` | or null — null when `alerts_to_primary`, on an older record, and a Helper's for anybody but the primary resident and the Helper (row 20) |
| `alerts_to_primary` | boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach |
| `access_level` | `member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release |
| `access_window` | a Helper's days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release |
| `access_window.days` | the days their e-Pass works, 1 (Monday) to 7 (Sunday) |
| `access_window.days.*` | integer — a day, 1 (Monday) to 7 (Sunday) |
| `access_window.from` | from, "07:00" on the estate's clock |
| `access_window.to` | until, "19:00" — earlier than `from` is an overnight window |
| `access_window.until` | A CALENDAR DATE: the last day it works — or null, no end |
| `access_window.paused` | boolean — the household paused them: their e-Pass is cancelled and none is issued |
| `access_window.own` | boolean — the household set these; false is the estate's default for a helper |
| `access_window.line` | the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household" |
| `invite.status` | `none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed` |
| `invite.email` | the one address it is for, or null |
| `invite.sent_at` | when the mail server last accepted it (UTC), or null — never merely when it was asked |
| `invite.last_attempt_at` | the last try, sent or not (UTC), or null |
| `invite.reason` | why it was not sent, in words to show as they are, or null |
| `invite.expires_at` | when it lapses (UTC), or null |
| `invite.resend_after` | when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now |
| `invite.attempts` | how many times it was tried |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_primary` | Only the household's primary resident can change a member's access. |
| 404 | `not_found` | Not a member of this household — the same answer as an id nobody has. |
| 409 | `primary_is_member` | The primary resident always has Member access. |
| 409 | `access_levels_not_open` | A level other than `member` while levels are not open yet (`me.show.access.levels_open` is false). Nothing changed; show the message as it is sent. Back to `member` is always allowed. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only. It changes what a person may do at once; it is never saved to do later.

### `members.access`

A Helper's days, hours and end date, and "pause now" (8 Oct 2026) — the PRIMARY resident only. Their e-Pass works only inside them, at the gate and on their phone; paused, or past the end date, it is cancelled at once (every gate refuses it) and none is issued until resumed. Audited.

| | |
|---|---|
| Method and path | `PATCH /api/v1/household-members/{member}/access-window` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `member` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `days` | array of integers · required · the days it works, 1 (Monday) to 7 (Sunday), at least one |
| `from` | string · required · "07:00", on the half hour |
| `to` | string · required · "19:00", on the half hour; earlier than `from` is an overnight window |
| `until` | string · optional · the last day it works, YYYY-MM-DD — leave it out for no end |
| `paused` | boolean · required · true blocks them now |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the resident on the register |
| `full_name` | name |
| `relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `is_primary` | the household's primary resident |
| `status` | `verified`, or `pending` until the estate verifies them |
| `phone` | or null — null on a record saved before every field was required (M18C), and a Helper's for anybody but the primary resident and the Helper (rules table row 20) |
| `email` | or null — null when `alerts_to_primary`, on an older record, and a Helper's for anybody but the primary resident and the Helper (row 20) |
| `alerts_to_primary` | boolean — "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach |
| `access_level` | `member`, `dependent` or `helper` — what this person may do in the Resident App; the primary is always `member`. Absent on a server older than this release |
| `access_window` | a Helper's days, hours and end date, and whether the household paused them — null for a Member or a Dependent. Absent on a server older than this release |
| `access_window.days` | the days their e-Pass works, 1 (Monday) to 7 (Sunday) |
| `access_window.days.*` | integer — a day, 1 (Monday) to 7 (Sunday) |
| `access_window.from` | from, "07:00" on the estate's clock |
| `access_window.to` | until, "19:00" — earlier than `from` is an overnight window |
| `access_window.until` | A CALENDAR DATE: the last day it works — or null, no end |
| `access_window.paused` | boolean — the household paused them: their e-Pass is cancelled and none is issued |
| `access_window.own` | boolean — the household set these; false is the estate's default for a helper |
| `access_window.line` | the same, in words to show: "Mon–Sat, 7:00 AM – 7:00 PM, until Oct 31, 2026" or "Paused by the household" |
| `invite.status` | `none`, `sent` (the mail server accepted it), `not_sent` (the reason says why), `expired`, `cancelled` or `claimed` |
| `invite.email` | the one address it is for, or null |
| `invite.sent_at` | when the mail server last accepted it (UTC), or null — never merely when it was asked |
| `invite.last_attempt_at` | the last try, sent or not (UTC), or null |
| `invite.reason` | why it was not sent, in words to show as they are, or null |
| `invite.expires_at` | when it lapses (UTC), or null |
| `invite.resend_after` | when Resend may be pressed again (UTC) — 1, then 2, then 5 minutes after each send — or null for now |
| `invite.attempts` | how many times it was tried |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_primary` | Only the household's primary resident can change a helper's hours. |
| 404 | `not_found` | Not a member of this household — the same answer as an id nobody has. |
| 409 | `not_a_helper` | Days and hours are for a Helper. |
| 422 | `hours_invalid` | Not times on the half hour, or the same time twice. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only: blocking somebody is said to have happened only once it has.

### `members.destroy`

Remove a member from the household — the PRIMARY resident only. Removed, not deleted: they leave every list at once and lose Resident App access; the record stays for the history that points at it. Audited.

| | |
|---|---|
| Method and path | `DELETE /api/v1/household-members/{member}` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `member` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the member removed |
| `removed` | true |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_primary` | Only the household's primary resident can remove members. |
| 404 | `not_found` | Not a member of this household — the same answer as an id nobody has. |
| 409 | `primary_stays` | The primary resident cannot be removed in the app; the estate office hands a household over. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only. Removing someone takes their access away at once; it is never queued to happen later.

### `vehicles.index`

The household's registered vehicles.

| | |
|---|---|
| Method and path | `GET /api/v1/vehicles` |
| App | Resident |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the vehicle |
| `items.*.plate` | upper-cased |
| `items.*.make` | or null |
| `items.*.model` | or null |
| `items.*.colour` | or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `vehicles.store`

Register a vehicle. A plate already registered to the household is returned as it is (200).

| | |
|---|---|
| Method and path | `POST /api/v1/vehicles` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `plate` | string · required · letters, digits, spaces, hyphens |
| `make` | string · required |
| `model` | string · required |
| `colour` | string · required |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the vehicle |
| `plate` | upper-cased |
| `make` | or null |
| `model` | or null |
| `colour` | or null |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `contacts.index`

Who to call for this household. Never shown to a guard.

| | |
|---|---|
| Method and path | `GET /api/v1/emergency-contacts` |
| App | Resident |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the contact |
| `items.*.name` | name |
| `items.*.relationship` | or null |
| `items.*.phone` | number |
| `items.*.email` | or null — a panic emails the contact at once (M12 §2.5) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `contacts.store`

Add an emergency contact. A number already on the household's list (compared as a diallable number) is returned as it is (200), with the existing row — never a second row.

| | |
|---|---|
| Method and path | `POST /api/v1/emergency-contacts` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `name` | string · required |
| `relationship` | string · required (M18C) · in the resident's own words, e.g. "Sister" |
| `phone` | string · required · a phone number — spaces, dashes, a leading 1 or +1, or another country's with its +; stored as "+1 876 555 0123" |
| `email` | string · required (M18C) · a valid address — a panic emails the contact at once; kept onto an already-listed contact that had none |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the contact |
| `name` | name |
| `relationship` | or null |
| `phone` | number |
| `email` | or null — a panic emails the contact at once (M12 §2.5) |
| `already_listed` | boolean — true when that number was already on the household's list and nothing was added; the existing row is returned. Absent on a server older than this release |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `contacts.update`

Change one of the household's emergency contacts (M12 §2.5) — above all, add the email a panic now writes to. The household's own rows only. A number already on the list for another contact is refused. Audited (fields changed, the address masked).

| | |
|---|---|
| Method and path | `PATCH /api/v1/emergency-contacts/{contact}` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `contact` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `name` | string · sent only when changed |
| `relationship` | string · sent only when changed · never blank (M18C) |
| `phone` | string · sent only when changed · never blank |
| `email` | string · sent only when changed · a valid address, never blank |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the contact |
| `name` | name |
| `relationship` | or null |
| `phone` | number |
| `email` | or null — a panic emails the contact at once (M12 §2.5) |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not one of this household's emergency contacts. |
| 409 | `already_listed` | That number is already on the list for another contact. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `contacts.destroy`

Remove one of the household's emergency contacts. The household's own rows only; the app confirms first. Audited (the relationship and a masked number, never the name).

| | |
|---|---|
| Method and path | `DELETE /api/v1/emergency-contacts/{contact}` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `contact` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the contact removed |
| `removed` | true |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not one of this household's emergency contacts — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only. It changes who the estate and a panic can reach at once; it is never saved to do later.

### `vehicles.destroy`

Remove one of the household's registered vehicles. The household's own rows only; the app confirms first. Audited. No gate decision reads these plates, so a removal changes nothing about who is admitted.

| | |
|---|---|
| Method and path | `DELETE /api/v1/vehicles/{vehicle}` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `vehicle` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the vehicle removed |
| `removed` | true |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not one of this household's vehicles — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only. A removal takes effect at once; it is never saved to do later.

### `vehicles.update`

Change one of the household's vehicles in place (M50 §5.3b) — its plate, make, model and colour, all four sent. The household's own rows only. A plate already on the list for ANOTHER vehicle is refused. Audited, before and after.

| | |
|---|---|
| Method and path | `PATCH /api/v1/vehicles/{vehicle}` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `vehicle` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `plate` | string · required · letters, digits, spaces, hyphens; upper-cased |
| `make` | string · required |
| `model` | string · required |
| `colour` | string · required |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the vehicle |
| `plate` | upper-cased |
| `make` | or null |
| `model` | or null |
| `colour` | or null |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not one of this household's vehicles — the same answer as an id nobody has. |
| 409 | `already_listed` | That plate is already on the list for another vehicle. Nothing changed. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `pets.index`

The household's pets (M50 §5.3d), by name. Never read at the gate.

| | |
|---|---|
| Method and path | `GET /api/v1/pets` |
| App | Resident |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the pet |
| `items.*.name` | its name |
| `items.*.kind` | `dog`, `cat`, `bird`, `fish`, `rabbit` or `other` |
| `items.*.kind_label` | the kind in words to show, "Dog" |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `pets.store`

Add a pet: a name and a kind. The same pet again (the name in any capitals, and the kind) is returned as it is (200) — never a second row.

| | |
|---|---|
| Method and path | `POST /api/v1/pets` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `name` | string · required · 40 at most |
| `kind` | string · required · `dog`, `cat`, `bird`, `fish`, `rabbit` or `other` |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the pet |
| `name` | its name |
| `kind` | `dog`, `cat`, `bird`, `fish`, `rabbit` or `other` |
| `kind_label` | the kind in words to show, "Dog" |
| `already_listed` | boolean — true when that pet was already on the list and nothing was added |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `pets.update`

Change one of the household's pets — its name and kind, both sent. The household's own rows only. Audited.

| | |
|---|---|
| Method and path | `PATCH /api/v1/pets/{pet}` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `pet` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `name` | string · required · 40 at most |
| `kind` | string · required · `dog`, `cat`, `bird`, `fish`, `rabbit` or `other` |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the pet |
| `name` | its name |
| `kind` | `dog`, `cat`, `bird`, `fish`, `rabbit` or `other` |
| `kind_label` | the kind in words to show, "Dog" |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not one of this household's pets — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `pets.destroy`

Remove one of the household's pets. The household's own rows only; the app confirms first. Audited.

| | |
|---|---|
| Method and path | `DELETE /api/v1/pets/{pet}` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `pet` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the pet removed |
| `removed` | true |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not one of this household's pets — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only. A removal takes effect at once; it is never saved to do later.

### `visitor_passes.index`

The household's visitor passes from the last 30 days. Board resident-app-06.

| | |
|---|---|
| Method and path | `GET /api/v1/visitor-passes` |
| App | Resident |
| Ability | `passes:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `status` | string · optional (query) · `active`, `used`, `cancelled` or `expired` |

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the pass — the id the pass endpoints take |
| `items.*.pass_id` | UUID, in the signed payload |
| `items.*.category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `items.*.visitor_name` | who it is for |
| `items.*.visitor_phone` | or null |
| `items.*.purpose` | or null |
| `items.*.vehicle_plate` | or null |
| `items.*.no_vehicle` | boolean — the resident chose "no vehicle" (M18C §3.1): Walk in or Ride in. False on a pass issued before the choice existed |
| `items.*.arrival` | `walk`, `drive` or `ride` — how the visitor arrives, as Invite visitor asks it (M50 §6.3) — or null on a pass issued before. Absent on a server older than this release |
| `items.*.valid_from` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.valid_to` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.single_use` | boolean |
| `items.*.status` | `active`, `used`, `cancelled` or `expired` |
| `items.*.code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `items.*.token` | the QR payload: `base64url(payload).base64url(signature)` |
| `items.*.share_count` | times shared |
| `items.*.used_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.last_admitted_at` | or null — the latest time a visitor was let in on this pass, recurring passes too ("Arrived 6:42 PM"); UTC instant; show in the site's zone (`timezone`) |
| `items.*.last_admitted_post` | or null — the gate that admission was at, by the post's own name: "Entered · Main Gate" (M47 §5.4). Null when nobody was let in, or the gate did not say |
| `items.*.last_admitted_by` | or null — the guard who let them in, by name: "by Guard Whyte" (M47 §5.4). Null when nobody was let in on it yet, or it was before the gate recorded who |
| `items.*.host` | the household member who issued the pass, by name — "Guest of <host>" (M47 §5.5); the visitor is THEIR guest, whoever is signed in |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache — the QR codes render offline.

### `visitor_passes.store`

Issue a signed visitor pass. At most 31 days long. Board resident-app-05.

| | |
|---|---|
| Method and path | `POST /api/v1/visitor-passes` |
| App | Resident |
| Ability | `passes:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `category` | string · required · `single`, `recurring`, `contractor` or `delivery` |
| `visitor_name` | string · required |
| `visitor_phone` | string · required (M18C) · a phone number — spaces, dashes, a leading 1 or +1 accepted; stored as "+1 876 555 0123" |
| `purpose` | string · required (M12 §2.13) · 2–160 characters, e.g. "Family visit" — the guard sees it at the gate |
| `vehicle_plate` | string · required unless `no_vehicle` is true, and refused when it is |
| `no_vehicle` | boolean · "no vehicle" (M18C §3.1) — Walk in or Ride in: a plate, or this — never neither |
| `arrival` | string · optional · `drive` with a plate; `walk` or `ride` with `no_vehicle` (M50 §6.3) |
| `valid_from` | ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site's clock · required |
| `valid_to` | ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site's clock · required · after `valid_from` · still ahead |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the pass — the id the pass endpoints take |
| `pass_id` | UUID, in the signed payload |
| `category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `visitor_name` | who it is for |
| `visitor_phone` | or null |
| `purpose` | or null |
| `vehicle_plate` | or null |
| `no_vehicle` | boolean — the resident chose "no vehicle" (M18C §3.1): Walk in or Ride in. False on a pass issued before the choice existed |
| `arrival` | `walk`, `drive` or `ride` — how the visitor arrives, as Invite visitor asks it (M50 §6.3) — or null on a pass issued before. Absent on a server older than this release |
| `valid_from` | UTC instant; show in the site's zone (`timezone`) |
| `valid_to` | UTC instant; show in the site's zone (`timezone`) |
| `single_use` | boolean |
| `status` | `active`, `used`, `cancelled` or `expired` |
| `code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `token` | the QR payload: `base64url(payload).base64url(signature)` |
| `share_count` | times shared |
| `used_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `last_admitted_at` | or null — the latest time a visitor was let in on this pass, recurring passes too ("Arrived 6:42 PM"); UTC instant; show in the site's zone (`timezone`) |
| `last_admitted_post` | or null — the gate that admission was at, by the post's own name: "Entered · Main Gate" (M47 §5.4). Null when nobody was let in, or the gate did not say |
| `last_admitted_by` | or null — the guard who let them in, by name: "by Guard Whyte" (M47 §5.4). Null when nobody was let in on it yet, or it was before the gate recorded who |
| `host` | the household member who issued the pass, by name — "Guest of <host>" (M47 §5.5); the visitor is THEIR guest, whoever is signed in |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `access_restricted` | The household is restricted for this category. The wording is the gate's; the app shows nothing more. |
| 422 | `pass_refused` | Longer than 31 days, a window that has already ended (a draft sent after its time), or otherwise refused; the message says why. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only: a pass is signed by the server. The app keeps one written offline as a draft and sends it itself with a stable Idempotency-Key; a refusal is kept on the draft.

### `visitor_passes.update`

Change what is not signed: the visitor's name, number, purpose or plate. A different window or category is a new pass.

| | |
|---|---|
| Method and path | `PATCH /api/v1/visitor-passes/{pass}` |
| App | Resident |
| Ability | `passes:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `pass` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `visitor_name` | string · sent only when changed |
| `visitor_phone` | string · sent only when changed · never blank (M18C) |
| `purpose` | string · sent only when changed · never blank |
| `vehicle_plate` | string · sent only when changed · a plate clears `no_vehicle` and makes it Drive in |
| `no_vehicle` | boolean · sent only when changed · true clears the plate |
| `arrival` | string · optional, with `no_vehicle` · `walk` or `ride` (M50 §6.3) |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the pass — the id the pass endpoints take |
| `pass_id` | UUID, in the signed payload |
| `category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `visitor_name` | who it is for |
| `visitor_phone` | or null |
| `purpose` | or null |
| `vehicle_plate` | or null |
| `no_vehicle` | boolean — the resident chose "no vehicle" (M18C §3.1): Walk in or Ride in. False on a pass issued before the choice existed |
| `arrival` | `walk`, `drive` or `ride` — how the visitor arrives, as Invite visitor asks it (M50 §6.3) — or null on a pass issued before. Absent on a server older than this release |
| `valid_from` | UTC instant; show in the site's zone (`timezone`) |
| `valid_to` | UTC instant; show in the site's zone (`timezone`) |
| `single_use` | boolean |
| `status` | `active`, `used`, `cancelled` or `expired` |
| `code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `token` | the QR payload: `base64url(payload).base64url(signature)` |
| `share_count` | times shared |
| `used_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `last_admitted_at` | or null — the latest time a visitor was let in on this pass, recurring passes too ("Arrived 6:42 PM"); UTC instant; show in the site's zone (`timezone`) |
| `last_admitted_post` | or null — the gate that admission was at, by the post's own name: "Entered · Main Gate" (M47 §5.4). Null when nobody was let in, or the gate did not say |
| `last_admitted_by` | or null — the guard who let them in, by name: "by Guard Whyte" (M47 §5.4). Null when nobody was let in on it yet, or it was before the gate recorded who |
| `host` | the household member who issued the pass, by name — "Guest of <host>" (M47 §5.5); the visitor is THEIR guest, whoever is signed in |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a pass of this household's. |
| 409 | `pass_not_active` | Used, cancelled or expired. |
| 422 | `validation_failed` | A signed field (`valid_from`, `valid_to`, `category`) was sent. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `visitor_passes.cancel`

Cancel a pass. Guards' handsets learn of it at their next sync; online verification refuses it at once.

| | |
|---|---|
| Method and path | `POST /api/v1/visitor-passes/{pass}/cancel` |
| App | Resident |
| Ability | `passes:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `pass` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the pass — the id the pass endpoints take |
| `pass_id` | UUID, in the signed payload |
| `category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `visitor_name` | who it is for |
| `visitor_phone` | or null |
| `purpose` | or null |
| `vehicle_plate` | or null |
| `no_vehicle` | boolean — the resident chose "no vehicle" (M18C §3.1): Walk in or Ride in. False on a pass issued before the choice existed |
| `arrival` | `walk`, `drive` or `ride` — how the visitor arrives, as Invite visitor asks it (M50 §6.3) — or null on a pass issued before. Absent on a server older than this release |
| `valid_from` | UTC instant; show in the site's zone (`timezone`) |
| `valid_to` | UTC instant; show in the site's zone (`timezone`) |
| `single_use` | boolean |
| `status` | `active`, `used`, `cancelled` or `expired` |
| `code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `token` | the QR payload: `base64url(payload).base64url(signature)` |
| `share_count` | times shared |
| `used_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `last_admitted_at` | or null — the latest time a visitor was let in on this pass, recurring passes too ("Arrived 6:42 PM"); UTC instant; show in the site's zone (`timezone`) |
| `last_admitted_post` | or null — the gate that admission was at, by the post's own name: "Entered · Main Gate" (M47 §5.4). Null when nobody was let in, or the gate did not say |
| `last_admitted_by` | or null — the guard who let them in, by name: "by Guard Whyte" (M47 §5.4). Null when nobody was let in on it yet, or it was before the gate recorded who |
| `host` | the household member who issued the pass, by name — "Guest of <host>" (M47 §5.5); the visitor is THEIR guest, whoever is signed in |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a pass of this household's. |
| 409 | `pass_already_used` | Nothing left to cancel. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue, and tell the resident the gate learns of it when both are online.

### `visitor_passes.share`

Count a share and get the message to send. The app renders the QR image from `token` and hands both to the OS share sheet.

| | |
|---|---|
| Method and path | `POST /api/v1/visitor-passes/{pass}/share` |
| App | Resident |
| Ability | `passes:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `pass` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `channel` | string · optional · `whatsapp`, `sms`, `email` or `copy` |

**Response** `200`

| Key | Meaning |
|---|---|
| `pass_id` | UUID |
| `share_count` | after this share |
| `code` | short code |
| `token` | QR payload |
| `message` | the invitation that goes as the picture's caption (M18F §4): greeted by pass type, the date once in the one form ("27 Sep 2026"), the times on the estate's clock with no clock named (M98), the code as *CODE* (bold in WhatsApp), and "Sent by" the host. Paragraphs are separated by a blank line |
| `sms` | the same facts as words only, for a text message: plain GSM characters (no dashes or curly quotes), so it stays within two segments |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a pass of this household's. |
| 409 | `pass_not_active` | Used, cancelled or expired. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Share from cache; send the count when online.

### `epass.show`

The resident's own signed e-pass, HALF AN HOUR AT A TIME (M47 §6): what the phone shows is good for 30 minutes, so a screenshot of it is dead within the half hour, and `slots` is a day of them (48) to keep for a gate with no signal. `valid_from`, `valid_to` and `token` are the half hour this moment is in. The gate code stays the same for the day. Never restricted for a Member or a Dependent; a Helper's works only inside their days and hours (8 Oct 2026: `hours` says them), and not at all while their household has paused them or after their end date. Board resident-app-08.

| | |
|---|---|
| Method and path | `GET /api/v1/e-pass` |
| App | Resident |
| Ability | `passes:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the pass — the id the pass endpoints take |
| `pass_id` | UUID, in the signed payload |
| `category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `visitor_name` | who it is for |
| `visitor_phone` | or null |
| `purpose` | or null |
| `vehicle_plate` | or null |
| `no_vehicle` | boolean — the resident chose "no vehicle" (M18C §3.1): Walk in or Ride in. False on a pass issued before the choice existed |
| `arrival` | `walk`, `drive` or `ride` — how the visitor arrives, as Invite visitor asks it (M50 §6.3) — or null on a pass issued before. Absent on a server older than this release |
| `valid_from` | UTC instant; show in the site's zone (`timezone`) |
| `valid_to` | UTC instant; show in the site's zone (`timezone`) |
| `single_use` | boolean |
| `status` | `active`, `used`, `cancelled` or `expired` |
| `code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `token` | the QR payload: `base64url(payload).base64url(signature)` |
| `share_count` | times shared |
| `used_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `last_admitted_at` | or null — the latest time a visitor was let in on this pass, recurring passes too ("Arrived 6:42 PM"); UTC instant; show in the site's zone (`timezone`) |
| `last_admitted_post` | or null — the gate that admission was at, by the post's own name: "Entered · Main Gate" (M47 §5.4). Null when nobody was let in, or the gate did not say |
| `last_admitted_by` | or null — the guard who let them in, by name: "by Guard Whyte" (M47 §5.4). Null when nobody was let in on it yet, or it was before the gate recorded who |
| `host` | the household member who issued the pass, by name — "Guest of <host>" (M47 §5.5); the visitor is THEIR guest, whoever is signed in |
| `slots` | the e-pass's half hours from the one this moment is in, up to a day of them (48), oldest first — show the one whose window holds now |
| `slots.*.valid_from` | UTC instant |
| `slots.*.valid_to` | UTC instant — thirty minutes after `valid_from` |
| `slots.*.token` | the signed token for that half hour: draw it as the QR. A guard verifies it offline, in that half hour and no other |
| `refresh_after` | collect the next day's half hours after this, whenever the app is open with a connection |
| `hours` | a Helper's days and hours, in words to show on the e-Pass ("Your e-Pass works Mon–Sat, 7:00 AM – 7:00 PM.") — their `slots` are only those; null for a Member or a Dependent. Absent on a server older than this release |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `account_pending` | No register entry linked yet. |
| 403 | `access_paused` | A Helper the household has paused. Show the sentence: it says who can turn it back on. |
| 403 | `access_ended` | A Helper past the end date the household set. Show the sentence. |
| 403 | `outside_hours` | A Helper with no half hour of their days and hours in the next day. Show the sentence: it says when it works. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |

**Offline** — Show the half hour of `slots` whose window holds now; when none does, the phone has run out — connect once to collect more. A guard verifies each offline.

### `approvals.respond`

Approve or deny a visitor a guard is holding at the gate. Board resident-app-07.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/approval/{approval}/respond` |
| App | Resident |
| Ability | `approvals:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `approval` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `decision` | string · required · `approve` or `deny` |

**Response** `200`

| Key | Meaning |
|---|---|
| `approval_id` | the request |
| `status` | `approved` or `denied` |
| `visitor_name` | who |
| `responded_at` | UTC instant; show in the site's zone (`timezone`) |
| `visitors_held` | boolean — approved, but this household's visitors are held at the gate (the estate's restriction; its own yes does not lift it, D-213). Say so instead of "letting them in"; the Dues page says why |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a request for this household. |
| 409 | `approval_expired` | The three minutes ran out. |
| 409 | `approval_decided` | Already answered. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — a late answer is no answer.

### `invoices.index`

The household's charges, newest first, each with what is still owed on it (payments clear the oldest first). A charge the estate reversed was never owed and is not listed; the statement shows it and its reversal. Board resident-app-11.

| | |
|---|---|
| Method and path | `GET /api/v1/invoices` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `currency` | `JMD` |
| `items.*.id` | the charge — the id `/invoices/{invoice}/pay-intent` takes |
| `items.*.reference` | e.g. `DUES-2026-09-L47` |
| `items.*.type` | `dues`, `special_assessment`, `fine` or `amenity` |
| `items.*.period` | `2026-09`, or null |
| `items.*.description` | text |
| `items.*.amount` | decimal string |
| `items.*.outstanding` | decimal string |
| `items.*.due_on` | YYYY-MM-DD |
| `items.*.status` | `paid`, `part_paid`, `overdue` or `due` |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache with its age; never show a cached balance as current.

### `households.balance`

What the household owes, from the ledger. Board resident-app-11.

| | |
|---|---|
| Method and path | `GET /api/v1/households/{household}/balance` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `household` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `household_id` | the household |
| `unit` | reference |
| `balance` | decimal string; negative is credit |
| `currency` | `JMD` |
| `as_at` | YYYY-MM-DD |
| `oldest_unpaid_due_on` | or null |
| `days_overdue` | integer |
| `on_payment_plan` | boolean |
| `plan` | object or null · the active payment plan, in a resident's terms (M18D §3) — null when there is none |
| `plan.reference` | the plan's reference |
| `plan.instalment` | decimal string · the next instalment (else the first) |
| `plan.instalments` | integer · how many were agreed |
| `plan.made` | integer · how many a treasurer has accepted as met |
| `plan.remaining` | integer · how many are still due |
| `plan.next_due_on` | YYYY-MM-DD or null |
| `plan.next_is_overdue` | boolean · the next instalment is past its date on the estate's calendar (not yet ruled missed) |
| `plan.final_due_on` | YYYY-MM-DD or null |
| `plan.agreed_on` | YYYY-MM-DD or null · the estate's calendar |
| `plan.frequency` | `Monthly` |
| `pay_now` | object or null · WHAT TO PAY NOW, AND BY WHEN (M18D §2.2) — the one action on the dues page, the amount in its button. Null when nothing is owed |
| `pay_now.amount` | decimal string · the plan's next instalment while a plan holds, else the whole balance |
| `pay_now.due_on` | YYYY-MM-DD or null |
| `pay_now.reason` | `instalment`, `overdue` or `due` |
| `access_restricted` | boolean — true while a visitor for this household would be held at the gate (the gate's own decision, payment plan included). Tell the resident in words; never work it out from `days_overdue` |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this account's household — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache with its age.

### `households.statement`

The running statement from the journal, newest first. Board resident-app-12.

| | |
|---|---|
| Method and path | `GET /api/v1/households/{household}/statement` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `household` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `limit` | integer · optional (query) · 1–200, default 50 |

**Response** `200`

| Key | Meaning |
|---|---|
| `unit` | reference |
| `currency` | `JMD` |
| `closing_balance` | decimal string |
| `items.*.date` | YYYY-MM-DD |
| `items.*.description` | the line's memo |
| `items.*.reference` | the journal entry |
| `items.*.charge` | decimal string, or null |
| `items.*.payment` | decimal string, or null |
| `items.*.balance` | running, decimal string |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this account's household — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache with its age.

### `invoices.pay_intent`

How to pay this charge: the amount, the reference to quote and the estate's own instructions for a bank transfer, cash or cheque — and, when the demonstration checkout is on (M12 §2.6), that it can be paid in the app with a TEST card (`payments.card`). Board resident-app-13.

| | |
|---|---|
| Method and path | `POST /api/v1/invoices/{invoice}/pay-intent` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `invoice` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `invoice_id` | the charge |
| `online_payment_available` | boolean — true when the TEST-card checkout is on |
| `card_mode` | `demonstration` when it is on (every card payment is a demonstration: no money moves), or null |
| `reason` | why, in a sentence |
| `amount_due` | decimal string still owed on this charge |
| `currency` | `JMD` |
| `quote_reference` | what to write on the transfer or cheque |
| `instructions` | the estate's own payment instructions |
| `estate` | the estate's name |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a charge of this household's. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cached instructions.

### `payments.card`

Pay some or all of what the household owes with one of Stripe's TEST cards (M12 §2.6, the client's instruction overriding D-077) — a DEMONSTRATION payment: a real receipt from the estate's sequence and a lower balance, posted to 1090 Demonstration Card Payments and never to the bank; no money moves and no card is stored. The phone checks the card form itself and sends only which test card it was. A declined test card changes nothing.

| | |
|---|---|
| Method and path | `POST /api/v1/payments/card` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `amount` | string · required · dollars and cents, e.g. `12400.00` — at most what is owed |
| `brand` | string · required · `visa`, `mastercard`, `amex` or `discover` |
| `last4` | string · required · the test card's last four digits — never the whole number |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the payment |
| `receipt_no` | e.g. `PPV-R-04471` |
| `amount` | decimal string |
| `currency` | `JMD` |
| `method` | `bank`, `cash`, `cheque` or `card` |
| `reference` | the payer's reference, or null |
| `received_at` | A CALENDAR DATE, NOT AN INSTANT: the day the office says the money arrived, carried as midnight UTC (e.g. 2026-07-03T00:00:00+00:00). Read its YYYY-MM-DD and show that day — do NOT move it into the site's zone, which would draw the evening before. Only a value that carries a time of day is a moment |
| `status` | `recorded` or `reversed` |
| `is_demonstration` | true when a TEST card paid it in the demonstration checkout (M12 §2.6): a real receipt and a lower balance, and no money moved — say "Demonstration payment" beside it |
| `estate` | name |
| `unit` | reference |
| `household` | name |
| `received_by_name` | null — the resident paid it in the app |
| `entered_at` | UTC instant; show in the site's zone (`timezone`) |
| `balance` | decimal string — what the household owes now, from the ledger |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `card_declined` | The test card is one Stripe declines (declined, insufficient funds, expired, incorrect security code): Stripe's own sentence and what to do next. Nothing was charged. |
| 409 | `payment_refused` | Card payments are off, it is not a test card, nothing is owed, or the amount is more than is owed — the sentence says which. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — a payment is never queued.

### `payments.intents.store`

Start paying some or all of what the household owes by card, through the estate's card gateway (M16 §2.2). OFF until a card provider is connected — then refused with `payment_refused` and the sentence the rest of the app gives: dues are paid by bank transfer, cash or cheque. The answer names the provider's own page to send the resident to (`next_action_url`); the card is typed there and never reaches this platform. The Idempotency-Key is the payment's own key: a retry or a double tap finds the one payment it already started. Nothing is paid until the provider's signed webhook says so — read `payments.intents.show`, never the return from the provider's page.

| | |
|---|---|
| Method and path | `POST /api/v1/payments/intents` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `amount` | string · required · dollars and cents, e.g. `12400.00` — at most what is owed |

**Response** `201`

| Key | Meaning |
|---|---|
| `reference` | string — the payment's reference, e.g. `PI-3F9A0C12B7E4` |
| `status` | string — `created`, `authorised`, `captured`, `settled`, `declined` or `refunded` |
| `amount` | decimal string |
| `currency` | JMD |
| `next_action_url` | string\|null — the provider's hosted card page to open |
| `decline_reason` | string\|null — a sentence to show, when declined |
| `receipt_no` | string\|null — once captured |
| `refunded` | decimal string |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `payment_refused` | Card payments are not connected, nothing is owed, the amount is more than is owed, or this key was used for a different payment — the sentence says which. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — a payment is never queued.

### `payments.intents.show`

Where one of the household's card payments stands. Only the provider's signed webhook moves it, so this — not the phone's return from the provider's page — is what says a payment went through (M16 §2.2).

| | |
|---|---|
| Method and path | `GET /api/v1/payments/intents/{reference}` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `reference` matches `[A-Za-z0-9-]{1,24}` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `reference` | string |
| `status` | string — `created`, `authorised`, `captured`, `settled`, `declined` or `refunded` |
| `amount` | decimal string |
| `currency` | JMD |
| `next_action_url` | string\|null |
| `decline_reason` | string\|null |
| `receipt_no` | string\|null |
| `refunded` | decimal string |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No card payment of the household's with that reference. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Online only.

### `payments.index`

Payments recorded for the household, newest first. Board resident-app-14.

| | |
|---|---|
| Method and path | `GET /api/v1/payments` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `currency` | `JMD` |
| `items.*.id` | the payment |
| `items.*.receipt_no` | e.g. `PPV-R-04471` |
| `items.*.amount` | decimal string |
| `items.*.currency` | `JMD` |
| `items.*.method` | `bank`, `cash`, `cheque` or `card` |
| `items.*.reference` | the payer's reference, or null |
| `items.*.received_at` | A CALENDAR DATE, NOT AN INSTANT: the day the office says the money arrived, carried as midnight UTC (e.g. 2026-07-03T00:00:00+00:00). Read its YYYY-MM-DD and show that day — do NOT move it into the site's zone, which would draw the evening before. Only a value that carries a time of day is a moment |
| `items.*.status` | `recorded` or `reversed` |
| `items.*.is_demonstration` | true when a TEST card paid it in the demonstration checkout (M12 §2.6): a real receipt and a lower balance, and no money moved — say "Demonstration payment" beside it |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `payments.receipt`

One receipt, as the estate issued it.

| | |
|---|---|
| Method and path | `GET /api/v1/payments/{payment}/receipt` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `payment` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the payment |
| `receipt_no` | e.g. `PPV-R-04471` |
| `amount` | decimal string |
| `currency` | `JMD` |
| `method` | `bank`, `cash`, `cheque` or `card` |
| `reference` | the payer's reference, or null |
| `received_at` | A CALENDAR DATE, NOT AN INSTANT: the day the office says the money arrived, carried as midnight UTC (e.g. 2026-07-03T00:00:00+00:00). Read its YYYY-MM-DD and show that day — do NOT move it into the site's zone, which would draw the evening before. Only a value that carries a time of day is a moment |
| `status` | `recorded` or `reversed` |
| `is_demonstration` | true when a TEST card paid it in the demonstration checkout (M12 §2.6): a real receipt and a lower balance, and no money moved — say "Demonstration payment" beside it |
| `estate` | name |
| `unit` | reference |
| `household` | name |
| `received_by_name` | who took it, or null |
| `entered_at` | when it was keyed — UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a payment of this household's. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `autopay.show`

The household's AutoPay as it stands (boards resident-app-10, -40; M50 §4.2 #3): on or off, its limit, its day, its card, the next day it pays, and what its last run did. `available` false while the demonstration checkout is off (then it cannot be turned on, and an arrangement made before does not charge).

| | |
|---|---|
| Method and path | `GET /api/v1/autopay` |
| App | Resident |
| Ability | `payments:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `available` | boolean — AutoPay can be on: the demonstration checkout is switched on |
| `reason` | why not, when `available` is false — say it as given; else null |
| `on` | boolean |
| `id` | the arrangement, for `autopay.destroy` — null when there is none |
| `limit` | the most it pays in one month, a decimal string — or null |
| `day` | integer — the day of the month it pays, 1–28 — or null |
| `card` | the saved card it pays with (as `cards.index` draws one) — or null |
| `card.id` | the saved card |
| `card.brand` | `visa`, `mastercard`, `amex` or `discover` |
| `card.brand_name` | "Visa", "Mastercard", "American Express" or "Discover" |
| `card.last4` | its last four digits |
| `card.label` | "Visa •••• 4242" — draw it as given |
| `card.expires` | "MM/YY" |
| `card.holder` | the name on it, or null |
| `card.default` | boolean — the caller's default card |
| `card.expired` | boolean — past its month on the estate's calendar; it cannot pay |
| `card.demonstration` | boolean — always true while the checkout takes test cards only (D-179) |
| `next_charge_on` | A CALENDAR DATE (YYYY-MM-DD) on the estate's calendar: the next day it pays — null when off |
| `last_run_on` | A CALENDAR DATE: the day it last ran — or null |
| `last_outcome` | what its last run did, in a sentence to show as given ("Paid $12,400.00 with Visa •••• 4242 — receipt …", "Nothing was owed, so nothing was charged.") — or null |
| `defaults.limit` | the limit offered when turning it on: "15000.00" (the board's) |
| `defaults.day` | integer — the day offered when turning it on: 28 (the board's) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `autopay.store`

AutoPay turned on, or its terms changed (M50 §4.2 #3, §6.6): on `day` (1–28) `dues:autopay` pays what the household owes, up to `limit`, with the saved `card`, through the same demonstration checkout as Pay — a real receipt, posted to 1090, no money moved. One per household: on again changes it. Only while the demonstration checkout is on (`DemoCardPayments::enabled`).

| | |
|---|---|
| Method and path | `POST /api/v1/autopay` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `card` | integer · required · one of the caller's own saved cards (`cards.index`) |
| `limit` | string · optional · the most it pays in one month, in dollars — default "15000.00" (the board's); 100 to 1,000,000 |
| `day` | integer · optional · the day of the month, 1–28 — default 28 (the board's) |

**Response** `201`

| Key | Meaning |
|---|---|
| `available` | boolean — AutoPay can be on: the demonstration checkout is switched on |
| `reason` | why not, when `available` is false — say it as given; else null |
| `on` | boolean |
| `id` | the arrangement, for `autopay.destroy` — null when there is none |
| `limit` | the most it pays in one month, a decimal string — or null |
| `day` | integer — the day of the month it pays, 1–28 — or null |
| `card` | the saved card it pays with (as `cards.index` draws one) — or null |
| `card.id` | the saved card |
| `card.brand` | `visa`, `mastercard`, `amex` or `discover` |
| `card.brand_name` | "Visa", "Mastercard", "American Express" or "Discover" |
| `card.last4` | its last four digits |
| `card.label` | "Visa •••• 4242" — draw it as given |
| `card.expires` | "MM/YY" |
| `card.holder` | the name on it, or null |
| `card.default` | boolean — the caller's default card |
| `card.expired` | boolean — past its month on the estate's calendar; it cannot pay |
| `card.demonstration` | boolean — always true while the checkout takes test cards only (D-179) |
| `next_charge_on` | A CALENDAR DATE (YYYY-MM-DD) on the estate's calendar: the next day it pays — null when off |
| `last_run_on` | A CALENDAR DATE: the day it last ran — or null |
| `last_outcome` | what its last run did, in a sentence to show as given ("Paid $12,400.00 with Visa •••• 4242 — receipt …", "Nothing was owed, so nothing was charged.") — or null |
| `defaults.limit` | the limit offered when turning it on: "15000.00" (the board's) |
| `defaults.day` | integer — the day offered when turning it on: 28 (the board's) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not one of the caller's saved cards. |
| 409 | `autopay_unavailable` | Card payments are off for the estate, the card has expired, or the limit or day is outside its range. Say the sentence given. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Online only: the switch shows what the server said.

### `autopay.destroy`

AutoPay turned off (M50 §4.2 #3). Nothing more is paid by it; what it paid stays paid.

| | |
|---|---|
| Method and path | `DELETE /api/v1/autopay/{autopay}` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `autopay` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `available` | boolean — AutoPay can be on: the demonstration checkout is switched on |
| `reason` | why not, when `available` is false — say it as given; else null |
| `on` | boolean |
| `id` | the arrangement, for `autopay.destroy` — null when there is none |
| `limit` | the most it pays in one month, a decimal string — or null |
| `day` | integer — the day of the month it pays, 1–28 — or null |
| `card` | the saved card it pays with (as `cards.index` draws one) — or null |
| `card.id` | the saved card |
| `card.brand` | `visa`, `mastercard`, `amex` or `discover` |
| `card.brand_name` | "Visa", "Mastercard", "American Express" or "Discover" |
| `card.last4` | its last four digits |
| `card.label` | "Visa •••• 4242" — draw it as given |
| `card.expires` | "MM/YY" |
| `card.holder` | the name on it, or null |
| `card.default` | boolean — the caller's default card |
| `card.expired` | boolean — past its month on the estate's calendar; it cannot pay |
| `card.demonstration` | boolean — always true while the checkout takes test cards only (D-179) |
| `next_charge_on` | A CALENDAR DATE (YYYY-MM-DD) on the estate's calendar: the next day it pays — null when off |
| `last_run_on` | A CALENDAR DATE: the day it last ran — or null |
| `last_outcome` | what its last run did, in a sentence to show as given ("Paid $12,400.00 with Visa •••• 4242 — receipt …", "Nothing was owed, so nothing was charged.") — or null |
| `defaults.limit` | the limit offered when turning it on: "15000.00" (the board's) |
| `defaults.day` | integer — the day offered when turning it on: 28 (the board's) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this household's AutoPay. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Online only.

### `cards.index`

"Saved payment methods" (board resident-app-40) and the saved card on Pay (board resident-app-10): the caller's OWN saved cards in this home, the default first. Test cards only (D-179): the brand, the last four, the expiry and the name — never a number. `available` false while the demonstration checkout is off.

| | |
|---|---|
| Method and path | `GET /api/v1/me/cards` |
| App | Resident |
| Ability | `payments:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `available` | boolean — cards can be saved and paid with |
| `items.*.id` | the saved card |
| `items.*.brand` | `visa`, `mastercard`, `amex` or `discover` |
| `items.*.brand_name` | "Visa", "Mastercard", "American Express" or "Discover" |
| `items.*.last4` | its last four digits |
| `items.*.label` | "Visa •••• 4242" — draw it as given |
| `items.*.expires` | "MM/YY" |
| `items.*.holder` | the name on it, or null |
| `items.*.default` | boolean — the caller's default card |
| `items.*.expired` | boolean — past its month on the estate's calendar; it cannot pay |
| `items.*.demonstration` | boolean — always true while the checkout takes test cards only (D-179) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `cards.store`

"Save card" (board resident-app-35; M50 §4.2 #3): one of Stripe's TEST cards, by what names it — the phone checked the form against the test list and wiped any real number, and sends only the brand, last four, expiry and name. The first card saved, or one sent with `default`, is the default. The same card again is the same card.

| | |
|---|---|
| Method and path | `POST /api/v1/me/cards` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `brand` | string · required · `visa`, `mastercard`, `amex` or `discover` |
| `last4` | string · required · four digits |
| `exp_month` | integer · required · 1–12 |
| `exp_year` | integer · required · four digits, or two |
| `holder` | string · optional · the name on the card |
| `default` | boolean · optional · make it the default ("Set as default payment method") |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the saved card |
| `brand` | `visa`, `mastercard`, `amex` or `discover` |
| `brand_name` | "Visa", "Mastercard", "American Express" or "Discover" |
| `last4` | its last four digits |
| `label` | "Visa •••• 4242" — draw it as given |
| `expires` | "MM/YY" |
| `holder` | the name on it, or null |
| `default` | boolean — the caller's default card |
| `expired` | boolean — past its month on the estate's calendar; it cannot pay |
| `demonstration` | boolean — always true while the checkout takes test cards only (D-179) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `card_refused` | Not a test card, expired, or card payments are off for the estate. Say the sentence given. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Online only, and never queued: nothing about a card waits on a phone.

### `cards.default`

Make one of the caller's saved cards their default (M50 §4.2 #3).

| | |
|---|---|
| Method and path | `POST /api/v1/me/cards/{card}/default` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `card` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the saved card |
| `brand` | `visa`, `mastercard`, `amex` or `discover` |
| `brand_name` | "Visa", "Mastercard", "American Express" or "Discover" |
| `last4` | its last four digits |
| `label` | "Visa •••• 4242" — draw it as given |
| `expires` | "MM/YY" |
| `holder` | the name on it, or null |
| `default` | boolean — the caller's default card |
| `expired` | boolean — past its month on the estate's calendar; it cannot pay |
| `demonstration` | boolean — always true while the checkout takes test cards only (D-179) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not one of the caller's saved cards. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Online only.

### `cards.destroy`

Remove one of the caller's saved cards (M50 §4.2 #3). Their next card becomes the default; an AutoPay that used it is turned off, and says why.

| | |
|---|---|
| Method and path | `DELETE /api/v1/me/cards/{card}` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `card` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `removed` | always true |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not one of the caller's saved cards. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Online only.

### `tickets.index`

The household's maintenance tickets with their timelines, newest first — at most 100 an answer, and `next_before` reaches the older ones, so the whole history can be read. Board resident-app-16.

| | |
|---|---|
| Method and path | `GET /api/v1/tickets` |
| App | Resident |
| Ability | `tickets:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `before` | string · optional (query) · the previous answer's `next_before`; leave it out for the newest |

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the ticket |
| `items.*.number` | e.g. 1042 |
| `items.*.title` | title |
| `items.*.category` | or null |
| `items.*.location` | where |
| `items.*.description` | or null |
| `items.*.access_permitted` | boolean, or null — true where the household said a technician may be let in with nobody home; null where the request did not say, which is not the same as no |
| `items.*.preferred_time` | or null — when the household would like the visit, in their own words. A note to the office, never a booking |
| `items.*.priority` | `low`, `medium` or `high` |
| `items.*.status` | `submitted`, `acknowledged`, `assigned`, `in_progress`, `completed`, `verified` or `cancelled` |
| `items.*.status_label` | the console's own word for it: "Submitted", "Under review", "Assigned", "In progress", "Completed", "Closed" or "Cancelled" |
| `items.*.reported_at` | the SLA runs from here — UTC instant; show in the site's zone (`timezone`) |
| `items.*.technician_name` | or null |
| `items.*.eta_starts_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.eta_ends_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.resolution` | or null |
| `items.*.closed_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.media_count` | attachments |
| `items.*.vendor` | or null — the company the estate gave the job to (M47 §11a) |
| `items.*.vendor.name` | "Island Electric Services" |
| `items.*.vendor.phone` | or null — the number the card's call button rings: the technician's, else the company's |
| `items.*.timeline.*.event` | `reported`, `acknowledged`, `assigned`, `started`, `resolved`, `note` (a note the office — or the household, `tickets.comment` — added), … |
| `items.*.timeline.*.status` | status after the event, or null |
| `items.*.timeline.*.note` | or null |
| `items.*.timeline.*.occurred_at` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.timeline.*.by` | or null — who did it (M47 §11.4, §12.5); null for the system, or an entry that names nobody |
| `items.*.timeline.*.by.kind` | `staff`, `resident` or `vendor` |
| `items.*.timeline.*.by.name` | "Renae Cross" |
| `items.*.timeline.*.by.role` | or null — a member of staff's role: "Property Manager", "Dispatcher" |
| `next_before` | send this as `before` for the next older page, or null when nothing is older |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `cursor_invalid` | `before` is not a `next_before` this list handed out. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `tickets.store`

Report a problem, or file a security report (`category` `security`). It lands in the estate's maintenance queue — with community staff, source resident, whatever else is sent. Boards resident-app-15 and resident-app-14.

| | |
|---|---|
| Method and path | `POST /api/v1/tickets` |
| App | Resident |
| Ability | `tickets:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `title` | string · required |
| `category` | string · required (M18C) · `plumbing`, `electrical`, `security`, `landscaping`, `roads`, `water`, `waste`, `amenity`, `other` |
| `description` | string · required (M18C) · what is wrong, in the resident's words |
| `location` | string · optional · defaults to the unit |
| `priority` | string · optional · `low`, `medium` (default) or `high` |
| `access_permitted` | boolean · optional · the household will let a technician in with nobody home. Left out means the request did not say, which is not the same as false |
| `preferred_time` | string · optional · when the household would like the visit, in their own words (at most 80). A note to the office, never a booking |
| `latitude` | number · optional · with `longitude`; only where the resident agreed to attach where they are. Omit when location is refused or unavailable — a report is never blocked for want of it |
| `longitude` | number · optional |
| `accuracy_m` | integer · optional · the fix's accuracy in metres |
| `happening_now` | boolean · optional · the resident says it is happening as they write. The guards ON DUTY at the site are told, and it appears in their `sync.pull`; the ticket still belongs to community staff with source resident — notification is not ownership |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the ticket |
| `number` | e.g. 1042 |
| `title` | title |
| `category` | or null |
| `location` | where |
| `description` | or null |
| `access_permitted` | boolean, or null — true where the household said a technician may be let in with nobody home; null where the request did not say, which is not the same as no |
| `preferred_time` | or null — when the household would like the visit, in their own words. A note to the office, never a booking |
| `priority` | `low`, `medium` or `high` |
| `status` | `submitted`, `acknowledged`, `assigned`, `in_progress`, `completed`, `verified` or `cancelled` |
| `status_label` | the console's own word for it: "Submitted", "Under review", "Assigned", "In progress", "Completed", "Closed" or "Cancelled" |
| `reported_at` | the SLA runs from here — UTC instant; show in the site's zone (`timezone`) |
| `technician_name` | or null |
| `eta_starts_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `eta_ends_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `resolution` | or null |
| `closed_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `media_count` | attachments |
| `vendor` | or null — the company the estate gave the job to (M47 §11a) |
| `vendor.name` | "Island Electric Services" |
| `vendor.phone` | or null — the number the card's call button rings: the technician's, else the company's |
| `timeline.*.event` | `reported`, `acknowledged`, `assigned`, `started`, `resolved`, `note` (a note the office — or the household, `tickets.comment` — added), … |
| `timeline.*.status` | status after the event, or null |
| `timeline.*.note` | or null |
| `timeline.*.occurred_at` | UTC instant; show in the site's zone (`timezone`) |
| `timeline.*.by` | or null — who did it (M47 §11.4, §12.5); null for the system, or an entry that names nobody |
| `timeline.*.by.kind` | `staff`, `resident` or `vendor` |
| `timeline.*.by.name` | "Renae Cross" |
| `timeline.*.by.role` | or null — a member of staff's role: "Property Manager", "Dispatcher" |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue; attach photos after it syncs.

### `tickets.media`

Attach a photo or video (multipart, field `file`). Stored privately, hashed on arrival.

| | |
|---|---|
| Method and path | `POST /api/v1/tickets/{ticket}/media` |
| App | Resident |
| Ability | `tickets:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `ticket` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `file` | file · required · JPEG, PNG, HEIC, MP4 or MOV, at most 50 MB |

**Response** `201`

| Key | Meaning |
|---|---|
| `media_id` | the attachment |
| `ticket_id` | the ticket |
| `filename` | as uploaded |
| `content_type` | as detected |
| `bytes` | size |
| `sha256` | hash |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a ticket of this household's. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue the file.

### `tickets.confirm_fixed`

The household confirms the job the estate office marked completed is fixed: the ticket becomes `verified`, the resident named on that stage. Only on the household's own request, and only once it is `completed`.

| | |
|---|---|
| Method and path | `POST /api/v1/tickets/{ticket}/fixed` |
| App | Resident |
| Ability | `tickets:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `ticket` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the ticket |
| `number` | e.g. 1042 |
| `title` | title |
| `category` | or null |
| `location` | where |
| `description` | or null |
| `access_permitted` | boolean, or null — true where the household said a technician may be let in with nobody home; null where the request did not say, which is not the same as no |
| `preferred_time` | or null — when the household would like the visit, in their own words. A note to the office, never a booking |
| `priority` | `low`, `medium` or `high` |
| `status` | `submitted`, `acknowledged`, `assigned`, `in_progress`, `completed`, `verified` or `cancelled` |
| `status_label` | the console's own word for it: "Submitted", "Under review", "Assigned", "In progress", "Completed", "Closed" or "Cancelled" |
| `reported_at` | the SLA runs from here — UTC instant; show in the site's zone (`timezone`) |
| `technician_name` | or null |
| `eta_starts_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `eta_ends_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `resolution` | or null |
| `closed_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `media_count` | attachments |
| `vendor` | or null — the company the estate gave the job to (M47 §11a) |
| `vendor.name` | "Island Electric Services" |
| `vendor.phone` | or null — the number the card's call button rings: the technician's, else the company's |
| `timeline.*.event` | `reported`, `acknowledged`, `assigned`, `started`, `resolved`, `note` (a note the office — or the household, `tickets.comment` — added), … |
| `timeline.*.status` | status after the event, or null |
| `timeline.*.note` | or null |
| `timeline.*.occurred_at` | UTC instant; show in the site's zone (`timezone`) |
| `timeline.*.by` | or null — who did it (M47 §11.4, §12.5); null for the system, or an entry that names nobody |
| `timeline.*.by.kind` | `staff`, `resident` or `vendor` |
| `timeline.*.by.name` | "Renae Cross" |
| `timeline.*.by.role` | or null — a member of staff's role: "Property Manager", "Dispatcher" |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a ticket of this household's. |
| 409 | `not_completed` | The estate office has not marked it finished, so there is nothing to confirm. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `tickets.comment`

A message to the estate office about one request ("Add a comment", M47 §11b): it goes on the ticket's history as a note, in the resident's words and under their name, where the office works the job. Any request of the household's, open or closed.

| | |
|---|---|
| Method and path | `POST /api/v1/tickets/{ticket}/comments` |
| App | Resident |
| Ability | `tickets:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `ticket` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `body` | string · required · at most 500 · the message |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the ticket |
| `number` | e.g. 1042 |
| `title` | title |
| `category` | or null |
| `location` | where |
| `description` | or null |
| `access_permitted` | boolean, or null — true where the household said a technician may be let in with nobody home; null where the request did not say, which is not the same as no |
| `preferred_time` | or null — when the household would like the visit, in their own words. A note to the office, never a booking |
| `priority` | `low`, `medium` or `high` |
| `status` | `submitted`, `acknowledged`, `assigned`, `in_progress`, `completed`, `verified` or `cancelled` |
| `status_label` | the console's own word for it: "Submitted", "Under review", "Assigned", "In progress", "Completed", "Closed" or "Cancelled" |
| `reported_at` | the SLA runs from here — UTC instant; show in the site's zone (`timezone`) |
| `technician_name` | or null |
| `eta_starts_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `eta_ends_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `resolution` | or null |
| `closed_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `media_count` | attachments |
| `vendor` | or null — the company the estate gave the job to (M47 §11a) |
| `vendor.name` | "Island Electric Services" |
| `vendor.phone` | or null — the number the card's call button rings: the technician's, else the company's |
| `timeline.*.event` | `reported`, `acknowledged`, `assigned`, `started`, `resolved`, `note` (a note the office — or the household, `tickets.comment` — added), … |
| `timeline.*.status` | status after the event, or null |
| `timeline.*.note` | or null |
| `timeline.*.occurred_at` | UTC instant; show in the site's zone (`timezone`) |
| `timeline.*.by` | or null — who did it (M47 §11.4, §12.5); null for the system, or an entry that names nobody |
| `timeline.*.by.kind` | `staff`, `resident` or `vendor` |
| `timeline.*.by.name` | "Renae Cross" |
| `timeline.*.by.role` | or null — a member of staff's role: "Property Manager", "Dispatcher" |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a ticket of this household's. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `tickets.still_a_problem`

The household says a job marked completed is still a problem: the ticket goes back into the estate's queue with what is still wrong, the resident named on the reopening. The first closure stays in the history, and the deadline still runs from the original report.

| | |
|---|---|
| Method and path | `POST /api/v1/tickets/{ticket}/reopen` |
| App | Resident |
| Ability | `tickets:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `ticket` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `reason` | string · required · 3–500 · what is still wrong, for the office |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the ticket |
| `number` | e.g. 1042 |
| `title` | title |
| `category` | or null |
| `location` | where |
| `description` | or null |
| `access_permitted` | boolean, or null — true where the household said a technician may be let in with nobody home; null where the request did not say, which is not the same as no |
| `preferred_time` | or null — when the household would like the visit, in their own words. A note to the office, never a booking |
| `priority` | `low`, `medium` or `high` |
| `status` | `submitted`, `acknowledged`, `assigned`, `in_progress`, `completed`, `verified` or `cancelled` |
| `status_label` | the console's own word for it: "Submitted", "Under review", "Assigned", "In progress", "Completed", "Closed" or "Cancelled" |
| `reported_at` | the SLA runs from here — UTC instant; show in the site's zone (`timezone`) |
| `technician_name` | or null |
| `eta_starts_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `eta_ends_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `resolution` | or null |
| `closed_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `media_count` | attachments |
| `vendor` | or null — the company the estate gave the job to (M47 §11a) |
| `vendor.name` | "Island Electric Services" |
| `vendor.phone` | or null — the number the card's call button rings: the technician's, else the company's |
| `timeline.*.event` | `reported`, `acknowledged`, `assigned`, `started`, `resolved`, `note` (a note the office — or the household, `tickets.comment` — added), … |
| `timeline.*.status` | status after the event, or null |
| `timeline.*.note` | or null |
| `timeline.*.occurred_at` | UTC instant; show in the site's zone (`timezone`) |
| `timeline.*.by` | or null — who did it (M47 §11.4, §12.5); null for the system, or an entry that names nobody |
| `timeline.*.by.kind` | `staff`, `resident` or `vendor` |
| `timeline.*.by.name` | "Renae Cross" |
| `timeline.*.by.role` | or null — a member of staff's role: "Property Manager", "Dispatcher" |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a ticket of this household's. |
| 409 | `not_completed` | It is still open with the office, so there is nothing to reopen. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `notices.index`

Published notices for the whole estate and the household's phase, newest first — at most 100 an answer, and `next_before` reaches the older ones, so the whole history can be read. Board resident-app-17.

| | |
|---|---|
| Method and path | `GET /api/v1/notices` |
| App | Resident |
| Ability | `notices:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `before` | string · optional (query) · the previous answer's `next_before`; leave it out for the newest |

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the notice |
| `items.*.kind` | `general` or `urgent` |
| `items.*.title` | title |
| `items.*.body` | text |
| `items.*.author_name` | who posted it |
| `items.*.posted_as_role` | e.g. "Secretary", or null |
| `items.*.audience` | "Whole estate" or the phase |
| `items.*.published_at` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.read_at` | when this resident read it, or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.seen_households` | how many of the households it is for have had somebody open it — the board's "seen by 212 of 450 households"; the console's seen bar, counted in households |
| `items.*.audience_households` | how many households it is for — the whole estate or its phase, as they stand today |
| `items.*.attachments.*.id` | the attachment — fetch it from `notices.attachment` |
| `items.*.attachments.*.name` | its file name, e.g. "Gate2_Contractor_Notice.pdf" |
| `items.*.attachments.*.content_type` | e.g. "application/pdf" |
| `items.*.attachments.*.bytes` | integer — its size in bytes |
| `unread` | integer — how many of the notices IN THIS ANSWER this resident has not read; never a figure for the whole history |
| `next_before` | send this as `before` for the next older page, or null when nothing is older |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `cursor_invalid` | `before` is not a `next_before` this list handed out. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache; queue reads.

### `notices.attachment`

One file the office put with a notice — THE FILE ITSELF, not JSON (`Content-Type` is the file's, `Content-Disposition` carries its name). Only for a notice published to this household, and only with this token: there is no public address for it. Board resident-app-36.

| | |
|---|---|
| Method and path | `GET /api/v1/notices/{notice}/attachments/{attachment}` |
| App | Resident |
| Ability | `notices:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `notice` matches `[0-9]+`, `attachment` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

No success body: this endpoint answers with an error envelope.

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a notice for this household, not an attachment of that notice, or not ready — the three are deliberately the same. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Needs a connection. A file already fetched stays on the phone.

### `notices.read`

Mark a notice read — board 30's "seen" count. Idempotent.

| | |
|---|---|
| Method and path | `POST /api/v1/notices/{notice}/read` |
| App | Resident |
| Ability | `notices:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `notice` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `notice_id` | the notice |
| `read_at` | the first read — UTC instant; show in the site's zone (`timezone`) |
| `seen_households` | how many of the households it is for have had somebody open it, this read included |
| `audience_households` | how many households it is for |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a notice for this household. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `meetings.index`

General and phase meetings, newest first — everything ahead, then the past — with agendas and the household's RSVP. At most 50 an answer, and `next_before` reaches the older ones, so the whole history can be read. Board resident-app-18.

| | |
|---|---|
| Method and path | `GET /api/v1/meetings` |
| App | Resident |
| Ability | `meetings:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `before` | string · optional (query) · the previous answer's `next_before`; leave it out for the newest |

**Response** `200`

| Key | Meaning |
|---|---|
| `next_before` | send this as `before` for the next older page, or null when nothing is older |
| `items.*.id` | the meeting |
| `items.*.type` | `agm`, `egm` or `phase` |
| `items.*.title` | title |
| `items.*.starts_at` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.held_as` | `in_person`, `zoom`, `google_meet`, `teams` or `other` — say the mode by name rather than inferring it from the link |
| `items.*.platform` | or null — the platform by name for an online meeting: "Zoom", "Google Meet", "Microsoft Teams", or for `other` the name the estate typed ("Webex"), else "Other online". Null in person |
| `items.*.place` | the one line to show: the venue for an in-person meeting, the mode for an online one, and both when it is hybrid. Composed on the server so the console, the notice and this app cannot word it three ways. |
| `items.*.venue` | or null — present on an in-person meeting, and on a hybrid one as well |
| `items.*.virtual_link` | or null — the joining link, http or https only. Null whenever `held_as` is `in_person`, even if one was set before the meeting moved offline. |
| `items.*.status` | `scheduled`, `held`, `postponed` or `cancelled` — `held` once a published meeting is more than six hours past its start (M21 §1.4); `postponed` is told, then moved with no new date yet (M56 §2.3). A meeting postponed to a new date arrives as `scheduled` at that date, with `my_rsvp` reset (M94 §3.3) |
| `items.*.date_to_be_set` | boolean. True while the meeting is postponed with no new date: show "New date to be set" and never `starts_at`, which is still the day it was first called for. False otherwise |
| `items.*.reason` | or null — why it was postponed or cancelled, in the office's words (M56 §1.2) |
| `items.*.description` | or null — what the meeting is about |
| `items.*.recording_enabled` | boolean — tell the resident before they join |
| `items.*.agenda.*.start_time` | HH:MM:SS, or null — a TIME OF DAY on the site's clock, as the office typed it; not an instant, never converted |
| `items.*.agenda.*.text` | item |
| `items.*.minutes_available` | boolean |
| `items.*.my_rsvp` | `attending`, `apologies`, `not_attending` or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `cursor_invalid` | `before` is not a `next_before` this list handed out. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `meetings.minutes`

A meeting's minutes, once the estate office has published them (M21 §1.4) — the text as published, when, and who recorded it. Nothing about who attended.

| | |
|---|---|
| Method and path | `GET /api/v1/meetings/{meeting}/minutes` |
| App | Resident |
| Ability | `meetings:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `meeting` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `meeting_id` | the meeting |
| `title` | the meeting's title |
| `held_on` | YYYY-MM-DD on the site's clock |
| `body` | the minutes, as the office wrote them — plain text with line breaks |
| `recorded_by` | who recorded them, or null |
| `published_at` | UTC instant; show in the site's zone (`timezone`) |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No published minutes for a meeting this household can see. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `meetings.rsvp`

Say whether the household will attend. Not attendance — quorum is counted from the register taken at the meeting.

| | |
|---|---|
| Method and path | `POST /api/v1/meetings/{meeting}/rsvp` |
| App | Resident |
| Ability | `meetings:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `meeting` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `response` | string · required · `attending`, `apologies` or `not_attending` |

**Response** `200`

| Key | Meaning |
|---|---|
| `meeting_id` | the meeting |
| `response` | as recorded |
| `responded_at` | UTC instant; show in the site's zone (`timezone`) |
| `households_attending` | households that said they will attend |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a meeting for this household. |
| 409 | `meeting_closed` | Started, held, postponed or cancelled. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `elections.index`

Elections and resolutions past draft, with the paper and whether the household has voted — never how. Board resident-app-19.

| | |
|---|---|
| Method and path | `GET /api/v1/elections` |
| App | Resident |
| Ability | `elections:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the ballot |
| `items.*.year` | year |
| `items.*.title` | title |
| `items.*.kind` | `election` or `resolution` |
| `items.*.question` | for a resolution, or null |
| `items.*.stage` | machine stage |
| `items.*.stage_label` | e.g. "Voting open" |
| `items.*.opens_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.closes_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.voting_open` | boolean |
| `items.*.has_voted` | boolean — turnout, not choice |
| `items.*.results_published` | boolean |
| `items.*.positions.*.id` | a position |
| `items.*.positions.*.name` | e.g. "Phase 2 Representative" |
| `items.*.positions.*.seat_count` | how many to mark at most |
| `items.*.positions.*.options.*.id` | an option id to send |
| `items.*.positions.*.options.*.label` | the candidate |
| `items.*.options.*.id` | a resolution's option id |
| `items.*.options.*.label` | e.g. "For" |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache. Voting is online only.

### `elections.eligibility`

Whether the household may vote, under the estate's rules. Board resident-app-20.

| | |
|---|---|
| Method and path | `GET /api/v1/elections/{ballot}/eligibility` |
| App | Resident |
| Ability | `elections:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `ballot` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `ballot_id` | the ballot |
| `eligible` | boolean |
| `reason` | e.g. "arrears >90 days", or null |
| `checked_on` | YYYY-MM-DD |
| `voting_open` | boolean |
| `has_voted` | boolean |
| `voted_on` | YYYY-MM-DD the household's paper went in, or null — a date, never a time, and nothing about the paper |
| `why` | why this person holds the vote — the household, the lot, that they are a Member, and that one paper is cast for the whole household (M21 §1.3), or null when the household is not eligible |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such election. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Online only.

### `elections.ballot`

Cast the household's paper. One per household. The answer names no mark, option or receipt, and the idempotency record keeps no trace of the body. Board resident-app-21.

| | |
|---|---|
| Method and path | `POST /api/v1/elections/{ballot}/ballot` |
| App | Resident |
| Ability | `elections:write` |
| Idempotency-Key | required · opaque (the body is not hashed) |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `ballot` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `option_ids` | array of integers · required · across every position, at most each position's seat count |

**Response** `201`

| Key | Meaning |
|---|---|
| `ballot_id` | the ballot |
| `voted` | true |
| `voted_on` | YYYY-MM-DD — a date, never a time |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_eligible` | The estate's rules exclude the household; the reason is given. |
| 409 | `poll_closed` | Not open. |
| 409 | `already_voted` | The household's paper is in. |
| 422 | `paper_invalid` | Blank, over-marked, or an option from another ballot. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only. Never queue a vote.

### `elections.results`

Certified, published results: counts per option, turnout and quorum.

| | |
|---|---|
| Method and path | `GET /api/v1/elections/{ballot}/results` |
| App | Resident |
| Ability | `elections:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `ballot` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the ballot |
| `year` | year |
| `title` | title |
| `kind` | `election` or `resolution` |
| `question` | for a resolution, or null |
| `stage` | machine stage |
| `stage_label` | e.g. "Voting open" |
| `opens_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `closes_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `certified_at` | UTC instant; show in the site's zone (`timezone`) |
| `published_at` | UTC instant; show in the site's zone (`timezone`) |
| `outcome_statement` | or null |
| `turnout.cast` | households |
| `turnout.eligible` | households |
| `turnout.percent` | integer |
| `quorum.required` | households |
| `quorum.met` | boolean |
| `positions.*.id` | position |
| `positions.*.name` | name |
| `positions.*.seat_count` | seats |
| `positions.*.options.*.id` | option |
| `positions.*.options.*.label` | candidate |
| `positions.*.options.*.votes` | marks |
| `options.*.id` | option |
| `options.*.label` | label |
| `options.*.votes` | marks |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such election. |
| 409 | `results_not_published` | Not yet certified and published. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache once fetched.

### `amenities.index`

Bookable amenities and their terms, and whether the household may book. Board resident-app-22.

| | |
|---|---|
| Method and path | `GET /api/v1/amenities` |
| App | Resident |
| Ability | `bookings:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `may_book` | false while the household is restricted from bookings |
| `card_payments` | boolean — a booking can be paid for in the app with a TEST card, which confirms it at once (`bookings.book`, M47 §10); false: a booking is a request the estate approves (`bookings.store`) |
| `items.*.id` | the amenity |
| `items.*.name` | e.g. "Club House" |
| `items.*.icon_key` | icon |
| `items.*.capacity` | guests |
| `items.*.opens_at` | HH:MM:SS — a TIME OF DAY on the site's clock, not an instant; never converted |
| `items.*.closes_at` | HH:MM:SS — a TIME OF DAY on the site's clock (`24:00:00` is the end of the day), not an instant; never converted |
| `items.*.booking_fee` | decimal string |
| `items.*.deposit` | decimal string |
| `items.*.currency` | `JMD` |
| `items.*.cancellation_hours` | or null |
| `items.*.booking_window_days` | or null |
| `items.*.requires_approval` | boolean — a booking of it is a request the estate office approves (`bookings.store`), its fee charged then; it is never paid for in the app, whatever `card_payments` says (M53 §2). False: as `card_payments` says |
| `items.*.taken.*.starts_at` | UTC instant; a period already booked or closed begins — show in the site's zone |
| `items.*.taken.*.ends_at` | UTC instant; and ends |
| `items.*.description` | or null — "garden setting": what the place is, after its capacity (M47 §8.2) |
| `items.*.place` | or null — "Phase 2": where it is on the estate (M47 §9.4) |
| `items.*.hourly_rate` | decimal string, or null — "$2,000/hr": the fee is this times the hours booked (`bookings.quote` works it out). Null: one flat `booking_fee` per booking |
| `items.*.slot_minutes` | integer, or null — the blocks the day is booked in, from opening: 120 is "9–11 AM · 11–1 PM · …". Null: one hour |
| `items.*.rules_summary` | or null — "noise cut-off at 9:00 PM and no glassware on the lawn", after "I've read and accept the … house rules, including" |
| `items.*.rules` | or null — the house rules themselves, which the rules link opens |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `bookings.quote`

What booking these hours would cost, worked out by the server (M47 §9.5) — the phone shows the figures and adds none of them up: the fee (the hourly rate times the hours, or the flat fee), anything the household already owes that a payment clears first (payments clear the oldest charge first, D-179), money paid ahead that pays the fee first, the deposit, and the total the card would be charged.

| | |
|---|---|
| Method and path | `GET /api/v1/amenities/{amenity}/quote` |
| App | Resident |
| Ability | `bookings:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `amenity` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `starts_at` | ISO 8601 WITH ITS OFFSET · required |
| `ends_at` | ISO 8601 WITH ITS OFFSET · required · after starts_at |

**Response** `200`

| Key | Meaning |
|---|---|
| `minutes` | integer — how long, "2 hrs" |
| `hourly_rate` | decimal string, or null for a flat fee — "× $2,000" |
| `fee` | decimal string — "Gazebo rate — 2 hrs × $2,000 … $4,000" |
| `owed_before` | decimal string — what the household owes from before, cleared first by the payment (0.00 for most) |
| `credit` | decimal string — money paid ahead, which pays for the fee first (0.00 for most) |
| `deposit` | decimal string — "Refundable damage deposit" |
| `total` | decimal string — "Total due today", and what "Confirm & pay" charges |
| `currency` | `JMD` |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such amenity, or it is not open for booking. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Online only — figures for now, never a saved copy.

### `bookings.book`

Book these hours AND pay for them with one of Stripe's TEST cards — CONFIRMED when it returns (M47 §10, the user's "confirmed on payment"). All or nothing: the booking, the estate's yes, the fee raised and paid with a real (demonstration) receipt, the deposit held, a confirmation code, and the guests' pass for its hours (`gate_token`). A declined card, a refusal, or a total that changed since it was shown makes nothing. The estate's own checks all stand: hours, the diary, the arrears rule. Only while `card_payments`; otherwise a booking is a request (`bookings.store`).

| | |
|---|---|
| Method and path | `POST /api/v1/bookings/book` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `amenity_id` | integer · required |
| `starts_at` | ISO 8601 WITH ITS OFFSET · required · in the future, within the booking window |
| `ends_at` | ISO 8601 WITH ITS OFFSET · required |
| `brand` | string · required · `visa`, `mastercard`, `amex` or `discover` |
| `last4` | string · required · the test card's last four digits — never the whole number |
| `total` | decimal string · required · the `bookings.quote` total the phone showed — a different one now is refused (409), never taken |
| `rules_accepted` | boolean · true when the house rules box was ticked — required where the amenity has `rules`, and ignored where it has none |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the booking |
| `reference` | e.g. `BKG-2026-09-0004` |
| `amenity.id` | amenity |
| `amenity.name` | its name |
| `starts_at` | UTC instant; show in the site's zone (`timezone`) |
| `ends_at` | UTC instant; show in the site's zone (`timezone`) |
| `guests` | or null |
| `status` | `pending`, `confirmed`, `declined`, `cancelled`, `completed` or `expired` (a request the estate did not decide before its time came: nobody declined it; M53 §2) |
| `booking_fee` | decimal string |
| `deposit` | decimal string |
| `deposit_state` | `none`, `awaiting`, `held`, `refunded` or `forfeited` (it always moves on: a held deposit is refunded when the booking is cancelled in the app or declined, or three days after it ends with no claim made; the estate may keep all or part of one, with a reason; an unpaid one on a booking that is over becomes none) |
| `deposit_kept` | decimal string, or null — what the estate kept of a forfeited deposit; the rest of the deposit went back to the household |
| `currency` | `JMD` |
| `cancellable_until` | the app may cancel until this moment — UTC instant; show in the site's zone (`timezone`) |
| `declined_reason` | or null |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `fee_charged` | boolean — the fee has been raised as a charge on the unit |
| `fee_outstanding` | decimal string — what is still owed on the fee (the whole fee when not yet charged; 0.00 with no fee, or on a declined, cancelled or expired booking never charged) |
| `fee_to_clear` | decimal string — what a card payment for the fee takes: the fee and every older charge still owing, since payments clear the oldest charge first (0.00 when nothing is owed on the fee) |
| `fee_paid` | boolean — a fee was charged and nothing is owed on it |
| `deposit_demonstration` | boolean — a TEST card paid the deposit (M12 §2.6): held, refunded or kept like any, and no money moved |
| `confirmation_code` | or null — "GZ-2609-A4" (M47 §10): a booking paid for in the app (`bookings.book`), confirmed at once; a short code a guard can read aloud |
| `gate_token` | or null — the guests' pass for the booking's hours, signed like any visitor pass: draw it as the QR. Only while the booking is confirmed and still to come |
| `paid_with` | or null — "Visa •••• 4417": the (test) card it was paid with, where the deposit goes back to. Never more than the brand and last four |
| `receipt` | the fee's receipt, or null when nothing was owed on it |
| `receipt.id` | the payment — `/payments/{payment}/receipt` shows it |
| `receipt.receipt_no` | e.g. `PPV-R-04471` |
| `receipt.amount` | decimal string |
| `receipt.reference` | e.g. "Demonstration card payment · Visa •••• 4242" |
| `receipt.is_demonstration` | always true |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such amenity. |
| 422 | `card_declined` | The test card is one Stripe declines: Stripe's own sentence and what to do next. Nothing was made. |
| 422 | `booking_refused` | Outside hours, the slot is taken, bookings are closed to the household, or too far ahead — the estate's own sentence; nothing was made. |
| 409 | `payment_refused` | Card payments are off, not a test card, or what the booking costs changed since it was shown — the sentence says which. Nothing was charged. |
| 409 | `approval_required` | The amenity's bookings wait for the estate office (`requires_approval`): send a request (`bookings.store`). Nothing was made or charged. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — a payment is never queued, and a slot is first come.

### `bookings.index`

The household's bookings, each with its fee and deposit and whether each is paid. Board resident-app-23.

| | |
|---|---|
| Method and path | `GET /api/v1/bookings` |
| App | Resident |
| Ability | `bookings:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `card_payments` | boolean — a booking's fee and deposit can be paid in the app with a TEST card (`bookings.pay_card`) |
| `items.*.id` | the booking |
| `items.*.reference` | e.g. `BKG-2026-09-0004` |
| `items.*.amenity.id` | amenity |
| `items.*.amenity.name` | its name |
| `items.*.starts_at` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.ends_at` | UTC instant; show in the site's zone (`timezone`) |
| `items.*.guests` | or null |
| `items.*.status` | `pending`, `confirmed`, `declined`, `cancelled`, `completed` or `expired` (a request the estate did not decide before its time came: nobody declined it; M53 §2) |
| `items.*.booking_fee` | decimal string |
| `items.*.deposit` | decimal string |
| `items.*.deposit_state` | `none`, `awaiting`, `held`, `refunded` or `forfeited` (it always moves on: a held deposit is refunded when the booking is cancelled in the app or declined, or three days after it ends with no claim made; the estate may keep all or part of one, with a reason; an unpaid one on a booking that is over becomes none) |
| `items.*.deposit_kept` | decimal string, or null — what the estate kept of a forfeited deposit; the rest of the deposit went back to the household |
| `items.*.currency` | `JMD` |
| `items.*.cancellable_until` | the app may cancel until this moment — UTC instant; show in the site's zone (`timezone`) |
| `items.*.declined_reason` | or null |
| `items.*.cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `items.*.fee_charged` | boolean — the fee has been raised as a charge on the unit |
| `items.*.fee_outstanding` | decimal string — what is still owed on the fee (the whole fee when not yet charged; 0.00 with no fee, or on a declined, cancelled or expired booking never charged) |
| `items.*.fee_to_clear` | decimal string — what a card payment for the fee takes: the fee and every older charge still owing, since payments clear the oldest charge first (0.00 when nothing is owed on the fee) |
| `items.*.fee_paid` | boolean — a fee was charged and nothing is owed on it |
| `items.*.deposit_demonstration` | boolean — a TEST card paid the deposit (M12 §2.6): held, refunded or kept like any, and no money moved |
| `items.*.confirmation_code` | or null — "GZ-2609-A4" (M47 §10): a booking paid for in the app (`bookings.book`), confirmed at once; a short code a guard can read aloud |
| `items.*.gate_token` | or null — the guests' pass for the booking's hours, signed like any visitor pass: draw it as the QR. Only while the booking is confirmed and still to come |
| `items.*.paid_with` | or null — "Visa •••• 4417": the (test) card it was paid with, where the deposit goes back to. Never more than the brand and last four |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |

**Offline** — Serve the cache.

### `bookings.pay_card`

Pay a booking's FEE or DEPOSIT with one of Stripe's TEST cards (M12 §2.6, §2.14) — a demonstration payment: posted to 1090, never the bank; no money moves. The phone checks the card form itself and sends only which test card it was. The fee is raised as a charge first if the office has not raised it (approved bookings only) and paid with a real receipt — for `fee_to_clear`, the fee and every older charge still owing, since payments clear the oldest charge first; the deposit is held. A declined test card changes nothing.

| | |
|---|---|
| Method and path | `POST /api/v1/bookings/{booking}/pay/card` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `booking` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `part` | string · required · `fee` or `deposit` |
| `brand` | string · required · `visa`, `mastercard`, `amex` or `discover` |
| `last4` | string · required · the test card's last four digits — never the whole number |
| `amount` | decimal string · optional · for the fee, the `fee_to_clear` the phone showed — a different figure is refused (409), never taken |

**Response** `200`

| Key | Meaning |
|---|---|
| `booking.id` | the booking |
| `booking.reference` | e.g. `BKG-2026-09-0004` |
| `booking.amenity.id` | amenity |
| `booking.amenity.name` | its name |
| `booking.starts_at` | UTC instant; show in the site's zone (`timezone`) |
| `booking.ends_at` | UTC instant; show in the site's zone (`timezone`) |
| `booking.guests` | or null |
| `booking.status` | `pending`, `confirmed`, `declined`, `cancelled`, `completed` or `expired` (a request the estate did not decide before its time came: nobody declined it; M53 §2) |
| `booking.booking_fee` | decimal string |
| `booking.deposit` | decimal string |
| `booking.deposit_state` | `none`, `awaiting`, `held`, `refunded` or `forfeited` (it always moves on: a held deposit is refunded when the booking is cancelled in the app or declined, or three days after it ends with no claim made; the estate may keep all or part of one, with a reason; an unpaid one on a booking that is over becomes none) |
| `booking.deposit_kept` | decimal string, or null — what the estate kept of a forfeited deposit; the rest of the deposit went back to the household |
| `booking.currency` | `JMD` |
| `booking.cancellable_until` | the app may cancel until this moment — UTC instant; show in the site's zone (`timezone`) |
| `booking.declined_reason` | or null |
| `booking.cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `booking.fee_charged` | boolean — the fee has been raised as a charge on the unit |
| `booking.fee_outstanding` | decimal string — what is still owed on the fee (the whole fee when not yet charged; 0.00 with no fee, or on a declined, cancelled or expired booking never charged) |
| `booking.fee_to_clear` | decimal string — what a card payment for the fee takes: the fee and every older charge still owing, since payments clear the oldest charge first (0.00 when nothing is owed on the fee) |
| `booking.fee_paid` | boolean — a fee was charged and nothing is owed on it |
| `booking.deposit_demonstration` | boolean — a TEST card paid the deposit (M12 §2.6): held, refunded or kept like any, and no money moved |
| `booking.confirmation_code` | or null — "GZ-2609-A4" (M47 §10): a booking paid for in the app (`bookings.book`), confirmed at once; a short code a guard can read aloud |
| `booking.gate_token` | or null — the guests' pass for the booking's hours, signed like any visitor pass: draw it as the QR. Only while the booking is confirmed and still to come |
| `booking.paid_with` | or null — "Visa •••• 4417": the (test) card it was paid with, where the deposit goes back to. Never more than the brand and last four |
| `receipt` | the fee's receipt, or null for a deposit |
| `receipt.id` | the payment — `/payments/{payment}/receipt` shows it |
| `receipt.receipt_no` | e.g. `PPV-R-04471` |
| `receipt.amount` | decimal string |
| `receipt.reference` | e.g. "Demonstration card payment · Visa •••• 4242" |
| `receipt.is_demonstration` | always true |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a booking of this household's. |
| 422 | `card_declined` | The test card is one Stripe declines: Stripe's own sentence and what to do next. Nothing was charged. |
| 409 | `payment_refused` | Card payments are off, not a test card, no fee or deposit, not approved yet, already paid, or what the fee takes has changed since it was shown — the sentence says which. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — a payment is never queued.

### `bookings.store`

Request a booking. It is `pending` and holds the slot until the estate decides.

| | |
|---|---|
| Method and path | `POST /api/v1/bookings` |
| App | Resident |
| Ability | `bookings:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `amenity_id` | integer · required |
| `starts_at` | ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site's clock · required · in the future, within the booking window |
| `ends_at` | ISO 8601 WITH ITS OFFSET (`…T14:00:00-05:00` or `…Z`) — the offset is honoured and UTC is stored; with no offset it is read on the site's clock · required |
| `guests` | integer · optional (M47 §9d: the board asks no guests) · checked against the capacity when sent |
| `notes` | string · additional (M18C §3.3) · a note to the estate office |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the booking |
| `reference` | e.g. `BKG-2026-09-0004` |
| `amenity.id` | amenity |
| `amenity.name` | its name |
| `starts_at` | UTC instant; show in the site's zone (`timezone`) |
| `ends_at` | UTC instant; show in the site's zone (`timezone`) |
| `guests` | or null |
| `status` | `pending`, `confirmed`, `declined`, `cancelled`, `completed` or `expired` (a request the estate did not decide before its time came: nobody declined it; M53 §2) |
| `booking_fee` | decimal string |
| `deposit` | decimal string |
| `deposit_state` | `none`, `awaiting`, `held`, `refunded` or `forfeited` (it always moves on: a held deposit is refunded when the booking is cancelled in the app or declined, or three days after it ends with no claim made; the estate may keep all or part of one, with a reason; an unpaid one on a booking that is over becomes none) |
| `deposit_kept` | decimal string, or null — what the estate kept of a forfeited deposit; the rest of the deposit went back to the household |
| `currency` | `JMD` |
| `cancellable_until` | the app may cancel until this moment — UTC instant; show in the site's zone (`timezone`) |
| `declined_reason` | or null |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `fee_charged` | boolean — the fee has been raised as a charge on the unit |
| `fee_outstanding` | decimal string — what is still owed on the fee (the whole fee when not yet charged; 0.00 with no fee, or on a declined, cancelled or expired booking never charged) |
| `fee_to_clear` | decimal string — what a card payment for the fee takes: the fee and every older charge still owing, since payments clear the oldest charge first (0.00 when nothing is owed on the fee) |
| `fee_paid` | boolean — a fee was charged and nothing is owed on it |
| `deposit_demonstration` | boolean — a TEST card paid the deposit (M12 §2.6): held, refunded or kept like any, and no money moved |
| `confirmation_code` | or null — "GZ-2609-A4" (M47 §10): a booking paid for in the app (`bookings.book`), confirmed at once; a short code a guard can read aloud |
| `gate_token` | or null — the guests' pass for the booking's hours, signed like any visitor pass: draw it as the QR. Only while the booking is confirmed and still to come |
| `paid_with` | or null — "Visa •••• 4417": the (test) card it was paid with, where the deposit goes back to. Never more than the brand and last four |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such amenity. |
| 422 | `booking_refused` | Outside hours, over capacity, the slot is taken, or bookings are closed to the household; the estate's own sentence — except a taken slot, which is said in the household's terms: the hours already booked and what to do, never the other booking's reference (M21 §1.5). |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — a slot is first come.

### `bookings.cancel`

Cancel within the booking's cancellation terms. A deposit already held is refunded in the same act (the answer says `refunded`); one never paid stops being awaited (`none`).

| | |
|---|---|
| Method and path | `POST /api/v1/bookings/{booking}/cancel` |
| App | Resident |
| Ability | `bookings:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `booking` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the booking |
| `reference` | e.g. `BKG-2026-09-0004` |
| `amenity.id` | amenity |
| `amenity.name` | its name |
| `starts_at` | UTC instant; show in the site's zone (`timezone`) |
| `ends_at` | UTC instant; show in the site's zone (`timezone`) |
| `guests` | or null |
| `status` | `pending`, `confirmed`, `declined`, `cancelled`, `completed` or `expired` (a request the estate did not decide before its time came: nobody declined it; M53 §2) |
| `booking_fee` | decimal string |
| `deposit` | decimal string |
| `deposit_state` | `none`, `awaiting`, `held`, `refunded` or `forfeited` (it always moves on: a held deposit is refunded when the booking is cancelled in the app or declined, or three days after it ends with no claim made; the estate may keep all or part of one, with a reason; an unpaid one on a booking that is over becomes none) |
| `deposit_kept` | decimal string, or null — what the estate kept of a forfeited deposit; the rest of the deposit went back to the household |
| `currency` | `JMD` |
| `cancellable_until` | the app may cancel until this moment — UTC instant; show in the site's zone (`timezone`) |
| `declined_reason` | or null |
| `cancelled_at` | or null — UTC instant; show in the site's zone (`timezone`) |
| `fee_charged` | boolean — the fee has been raised as a charge on the unit |
| `fee_outstanding` | decimal string — what is still owed on the fee (the whole fee when not yet charged; 0.00 with no fee, or on a declined, cancelled or expired booking never charged) |
| `fee_to_clear` | decimal string — what a card payment for the fee takes: the fee and every older charge still owing, since payments clear the oldest charge first (0.00 when nothing is owed on the fee) |
| `fee_paid` | boolean — a fee was charged and nothing is owed on it |
| `deposit_demonstration` | boolean — a TEST card paid the deposit (M12 §2.6): held, refunded or kept like any, and no money moved |
| `confirmation_code` | or null — "GZ-2609-A4" (M47 §10): a booking paid for in the app (`bookings.book`), confirmed at once; a short code a guard can read aloud |
| `gate_token` | or null — the guests' pass for the booking's hours, signed like any visitor pass: draw it as the QR. Only while the booking is confirmed and still to come |
| `paid_with` | or null — "Visa •••• 4417": the (test) card it was paid with, where the deposit goes back to. Never more than the brand and last four |
| `server_time` | the server's time — UTC instant; show in the site's zone (`timezone`) |
| `device_time` | the handset's time, as sent in X-Device-Time — UTC instant; show in the site's zone (`timezone`) |
| `clock_skewed` | true when the device time is more than two minutes AHEAD of the server's. A device time in the past is an act queued with no signal, not a wrong clock, and is not flagged |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a booking of this household's. |
| 409 | `cancellation_refused` | Inside the cancellation window, already started, or not cancellable. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_revoked` | THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS — the person has moved out (Q-M44). Different from `account_suspended`, which is the household's own primary resident removing a member: this is the office, it is dated and reasoned, and only the office can undo it. Final until they do: sign the person out, clear the cached copies, and show the message as it is sent. At the gate they are a VISITOR and come in through the Visitor Entrance. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `not_for_your_access_level` | The signed-in person's ACCESS LEVEL in their household (Member, Dependent or Helper) does not include this. The server is the rule; show the message as it is sent, and hide what `me.show.access.may` says is not allowed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. Never for longer than five minutes: past that the first attempt has died, and the answer is final (below). |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

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
