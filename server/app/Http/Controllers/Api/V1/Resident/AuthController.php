<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\UnitClaim;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Services\ResidentApp\AccountDeletion;
use App\Services\ResidentApp\ResidentAccounts;
use App\Services\ResidentApp\ResidentDeviceKeys;
use App\Services\ResidentApp\ResidentInviteAcceptance;
use App\Services\ResidentApp\ResidentSessions;
use App\Services\ResidentApp\ResidentSignIn;
use App\Services\Reviewer\ReviewerMode;
use App\Support\JamaicanNumber;
use App\Support\SessionWindow;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Signing in to the Resident App, and claiming a unit (13 D3). Boards resident-app-01, -04.
 *
 * Request a code, prove it, receive a token. The token's account is PENDING until
 * the estate approves a unit claim; a pending token reaches its claim and `GET /me`
 * and nothing else. See `ResidentSignIn` and `ResidentAccounts`.
 */
class AuthController extends Controller
{
    /** What every "Explore a demo community" token is named, before the install's own id. */
    private const DEMO_TOKEN = 'resident-app-demo';

    /**
     * How long past the idle window an abandoned demo session is kept before it is swept.
     *
     * Not swept the moment the window closes: `SessionWindow` keeps a timed-out token so
     * every later request with it is told `session_expired` — "sign in again" — rather than
     * `unauthenticated`, which the app reads as "signed in somewhere else". A day is long
     * enough for a phone put down overnight to be told the truth when it is picked up.
     */
    private const DEMO_SWEEP_GRACE_HOURS = 24;

    public function __construct(
        private readonly ResidentSignIn $signIn,
        private readonly ResidentAccounts $accounts,
        private readonly ResidentInviteAcceptance $acceptance,
        private readonly AccountDeletion $deletion,
        private readonly ResidentSessions $sessions,
        private readonly ResidentDeviceKeys $keys,
    ) {}

    /**
     * "LOG IN WITH BIOMETRICS" (M49): the phone's own prompt has opened its secure store, and the key in it — issued to
     * this install when "Face ID / fingerprint" was turned on (me.device_key.store) — stands in for the emailed code. The
     * account's own checks come first (ResidentDeviceKeys::account), then the phone is signed in exactly as a code signs
     * it in: one token for the install, the same answer.
     */
    public function signInWithDeviceKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'estate' => ['required', 'string', 'max:64'],
            'key' => ['required', 'string', 'max:200'],
            'device_uid' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'hardware_id' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $estate = $this->estate($data['estate']);
        $account = $this->keys->account($estate, $data['device_uid'], $data['key']);

        return $this->signedIn($estate, $account, $data, false);
    }

    public function requestCode(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            // M80 §2.1: a sign-in is told at once whether the register holds the address; Create account is not.
            'intent' => ['sometimes', 'nullable', 'string', 'in:'.ResidentSignIn::SIGN_IN.','.ResidentSignIn::SIGN_UP],
            'device_uid' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $estate = $this->estate($data['estate']);

        $sent = $this->signIn->requestCode(
            $estate,
            $data['channel'],
            $data['destination'],
            intent: $data['intent'] ?? null,
            device: ($data['device_uid'] ?? null) ?: $request->ip(),
        );

        return response()->json([
            'sent' => true,
            'channel' => $data['channel'],
            'destination_hint' => ResidentSignIn::mask(ResidentSignIn::normalise($data['channel'], $data['destination'])),
            // In full (M18G §4.3): the person has just typed it, and a mask only hides the typo that is the problem.
            'destination' => ResidentSignIn::normalise($data['channel'], $data['destination']),
            'expires_at' => SiteTime::iso($sent['expires_at']),
            'resend_after' => SiteTime::iso($sent['resend_after']),
        ], 202);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'device_uid' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'hardware_id' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $estate = $this->estate($data['estate']);
        $account = $this->signIn->verify($estate, $data['channel'], $data['destination'], $data['code']);

        /*
         * A DELETED ACCOUNT (M18H §1.2, §2.3). Proven by the code, so the person holds the address: they are told when it
         * was deleted and until when it can come back, and — inside their own window — handed a ticket the "Restore my
         * account" button takes to `auth.restore`. No token: until they choose, the account stays deleted. Once the
         * estate's last window has closed it is anonymised here, as the nightly run would have, and this address signs in
         * as anybody new would.
         */
        if ($account->isDeleted()) {
            if (! $this->deletion->standing($account)['can_office_restore']) {
                $this->deletion->anonymise($account, AccountDeletion::WINDOW_CLOSED);
                $account = $this->signIn->accountFor($estate, $data['channel'], $data['destination']);
            } else {
                throw new ApiError(409, 'account_deleted', $this->deletion->signInSentence($account), details: $this->deletion->signInDetails($account, $data['device_uid']));
            }
        }

        /*
         * THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS (Q-M44), and the refusal is here,
         * BEFORE a token is made. `ResolveDevice` would refuse every request the token
         * went on to make, so issuing one would hand somebody a sign-in that succeeds and
         * an app that then fails at everything — which is the "never fake a success" rule
         * broken at the front door. The sentence says the same thing the guard at the gate
         * is told, and names the one way back: the estate office.
         */
        if ($account->isRevoked()) {
            throw ApiError::forbidden(
                'account_revoked',
                'The estate office has ended this account\'s access, so you cannot sign in to '.$estate->name.'. '.
                'You are no longer a resident of this unit in the app, and at the gate you come in through the Visitor Entrance. '.
                'If this is wrong, contact the estate office — they can give the access back.'
            );
        }

        /*
         * A MEMBER THE HOUSEHOLD REMOVED, INVITED BACK (stage 2, DESIGN §5.3): a suspended account with exactly
         * one live invite for its address is reopened, pending and unlinked, so the invite below can be matched.
         * Never a revoked one — that was refused above, and only the office's restore undoes it.
         */
        $reopened = $account->status === ResidentAccount::SUSPENDED
            && (bool) $estate->run(fn (): bool => $this->acceptance->reopenSuspended($account));

        if ($account->status === 'suspended') {
            throw ApiError::forbidden('account_suspended', 'The estate has withdrawn this account\'s access. Contact the estate office.');
        }

        return $this->signedIn($estate, $account, $data, $reopened);
    }

    /**
     * "RESTORE MY ACCOUNT" (M18H §2.3) — the button on the screen `account_deleted` opened. The ticket is the sign-in's
     * proof of the code, for this account on this phone, fifteen minutes old at most; the account comes back as it was —
     * unit, household, balance, history — the estate office is told, and the phone is signed in exactly as a sign-in would.
     */
    public function restore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'estate' => ['required', 'string', 'max:64'],
            'ticket' => ['required', 'string', 'max:2000'],
            'device_uid' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'hardware_id' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $estate = $this->estate($data['estate']);
        $account = $this->deletion->ticketAccount($data['ticket'], (string) $estate->getTenantKey(), $data['device_uid']);

        if ($account === null) {
            throw ApiError::unprocessable('restore_ticket_invalid', 'That sign-in has run out. Sign in again with your email address and the app will offer to restore the account.');
        }

        if (! $this->deletion->standing($account)['can_self_restore']) {
            throw new ApiError(409, 'restore_office_only', $this->deletion->signInSentence($account), details: $this->deletion->signInDetails($account, $data['device_uid']));
        }

        $this->deletion->restore($account, AccountDeletion::BY_RESIDENT);

        return $this->signedIn($estate, $account->refresh(), $data, false, restored: true);
    }

    /**
     * A proven sign-in's token and the answer that carries it — the same for a code and for a restore.
     *
     * @param  array<string, mixed>  $data  the validated request: `device_uid`, `platform`, `device_name`
     */
    private function signedIn(Tenant $estate, ResidentAccount $account, array $data, bool $reopened, bool $restored = false): JsonResponse
    {
        // One token per install: signing in again on the same phone replaces its token.
        $name = ResidentSessions::TOKEN_PREFIX.$data['device_uid'];
        $account->tokens()->where('name', $name)->delete();
        $abilities = AppMatrix::abilitiesFor(AppMatrix::RESIDENT);
        $issued = $account->createToken($name, $abilities);
        $token = $issued->plainTextToken;
        $hardware = ResidentDeviceKeys::hardwareHash($data['hardware_id'] ?? null);

        // What the phone calls itself, so the account's list of signed-in phones names each one (me.sessions) — and the
        // handset it is (M49), so the same phone signing in again is known as the same phone.
        $issued->accessToken->forceFill([
            'device_label' => ResidentSessions::label($data['device_name'] ?? null),
            'device_platform' => $data['platform'],
            'device_hardware' => $hardware,
        ])->save();

        // And one sign-in per HANDSET (M49): an earlier install's sign-in on this same phone is ended, not kept beside.
        $this->sessions->retireHandset($account, $hardware, (int) $issued->accessToken->getKey());
        // A phone handed to somebody else never opens the last person's account with a fingerprint.
        $this->keys->revokeOthersOnInstall($account, (string) $data['device_uid']);

        $account->forceFill(['device_uid' => $data['device_uid'], 'device_platform' => $data['platform']])->save();

        $claim = null;

        $estate->run(function () use ($account, $reopened, &$claim): void {
            /*
             * THE INVITED ADDRESS IS THE CLAIM (stage 2, W-ACCEPT): proven by this code and matched to exactly one
             * live invite here, it makes the claim — no form. Before `reconcile()`, which then activates an
             * approval the estate's trust made in this same request.
             */
            $this->acceptance->accept($account, $reopened);
            $this->accounts->reconcile($account);
            $claim = $account->claim_id === null ? null : ResidentAccounts::claimShape(UnitClaim::query()->find($account->claim_id));
        });

        return response()->json([
            'token' => $token,
            'abilities' => $abilities,

            /*
             * REVIEWER MODE (M5 §3). True only for an account the SERVER has flagged. The
             * Resident App shows the demonstration banner from it, and it is `false` for
             * every real resident — there is no build flag and nothing on the phone decides.
             */
            'reviewer' => ReviewerMode::isReviewer($account),
            'read_only' => false,

            'account' => [
                'id' => $account->id,
                'status' => $account->status,
                'full_name' => $account->full_name,
            ],
            // `timezone`: every time this API sends is a UTC instant; this IANA zone is the one to SHOW them in (M4 §3.1).
            'estate' => ['id' => $estate->getTenantKey(), 'name' => (string) $estate->name, 'timezone' => SiteTime::zone((string) $estate->getTenantKey())],
            'claim' => $claim,
            'next' => $account->isActive() ? 'home' : ($claim === null ? 'claim_unit' : 'await_approval'),
            'restored' => $restored,
            // Whether THIS install still holds a live fingerprint key (M49): what "Face ID / fingerprint" shows once
            // signed in, so Settings and the log-in screen read the same fact.
            'fingerprint_sign_in' => $this->keys->liveFor($account, (string) $data['device_uid']),
        ]);
    }

    /**
     * "Explore a demo community" — the Resident App's demonstration path (M5 §3).
     *
     * WHY IT EXISTS. Signing in to the Resident App needs an estate and an address the estate
     * knows. A reviewer at Apple or Google has neither, and an app they cannot get past the
     * first screen of is rejected under Guideline 4.2 for minimum functionality. This hands
     * them a session inside the DEMONSTRATION estate, which holds nothing real.
     *
     * READ-ONLY IN THE TOKEN, NOT IN THE SCREENS. The token carries the Resident App's
     * abilities with every `:write` removed, so a write is refused by the route's own
     * ability middleware before any controller runs. There is nothing to remember to disable.
     *
     * AND IT IS NOT A HOLE IN TENANT ISOLATION. The account it signs in as is an ordinary
     * `resident_accounts` row inside one estate; everything afterwards resolves the estate
     * from the token exactly as it does for a real resident, so this session reaches the
     * demonstration estate's database and no other's.
     */
    public function demo(Request $request, ReviewerMode $reviewer): JsonResponse
    {
        $data = $request->validate([
            'device_uid' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $estate = ReviewerMode::estate();
        $account = $estate === null ? null : ReviewerMode::residentOf($estate);

        if ($estate === null || $account === null) {
            throw ApiError::notFound('demo_unavailable', 'There is no demonstration community on this platform. Sign in with your own estate and email address.');
        }

        /*
         * ONE SESSION PER INSTALL, AND NEVER ANOTHER INSTALL'S (B060).
         *
         * Every caller is handed the same demonstration account, and the token used to be
         * named from that account alone — so "asking again on the same phone" deleted every
         * OTHER phone's demo session too. An app-store reviewer on an iPad was signed out
         * the moment a reviewer on an iPhone, or any member of the public, tapped "Explore a
         * demo community"; the app reads that 401 as "signed in somewhere else".
         *
         * Now an install that names itself (`device_uid`, as `auth.otp.verify` takes it)
         * replaces its own session and only its own, and a caller that names no install
         * simply gets a session of its own. Nothing is left to accumulate: every demo
         * session dies on the idle window like any other, and is swept away a day after
         * that (`sweepAbandonedDemoSessions()`).
         */
        $deviceUid = is_string($data['device_uid'] ?? null) ? trim($data['device_uid']) : '';
        $name = $deviceUid === '' ? self::DEMO_TOKEN : self::DEMO_TOKEN.':'.$deviceUid;

        if ($deviceUid !== '') {
            $account->tokens()->where('name', $name)->delete();
        }

        $this->sweepAbandonedDemoSessions($account);

        $abilities = AppMatrix::readOnlyAbilitiesFor(AppMatrix::RESIDENT);
        $token = $account->createToken($name, $abilities)->plainTextToken;

        $reviewer->record(ReviewerMode::DEMO_SESSION, $account, ['estate' => $estate->getTenantKey()]);

        $claim = null;

        $estate->run(function () use ($account, &$claim): void {
            $this->accounts->reconcile($account);
            $claim = $account->claim_id === null ? null : ResidentAccounts::claimShape(UnitClaim::query()->find($account->claim_id));
        });

        return response()->json([
            'token' => $token,
            'abilities' => $abilities,
            'reviewer' => true,
            'read_only' => true,
            'account' => [
                'id' => $account->id,
                'status' => $account->status,
                'full_name' => $account->full_name,
            ],
            'estate' => ['id' => $estate->getTenantKey(), 'name' => (string) $estate->name, 'timezone' => SiteTime::zone((string) $estate->getTenantKey())],
            'claim' => $claim,
            'next' => $account->isActive() ? 'home' : ($claim === null ? 'claim_unit' : 'await_approval'),
        ]);
    }

    public function claimUnit(Request $request, DeviceContext $context): JsonResponse
    {
        $account = $context->residentOrFail();

        /*
         * NOTHING IS OPTIONAL (M18C): the phone the office reaches them on, and their link to the home. The phase or block
         * is ADDITIONAL (§3.3) — extra help finding the lot, asked under "Anything else the office should know".
         */
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            'lot' => ['required', 'string', 'max:32'],
            'phase' => ['nullable', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:40', new PhoneNumber],
            'relationship' => ['required', 'string', 'in:owner,tenant,spouse,child,parent,relative,other'],
        ], [
            'relationship.required' => 'Choose your link to this home.',
        ]);

        $data['phone'] = JamaicanNumber::normalise($data['phone']);

        $result = $this->accounts->claim($account, $data, $request->boolean('simulated'));

        return response()->json([
            'account' => ['id' => $account->id, 'status' => $account->status, 'full_name' => $account->full_name],
            'claim' => ResidentAccounts::claimShape($result['claim']),
        ], $result['created'] ? 201 : 200);
    }

    /**
     * @param  array<string, list<string>>  $extra
     * @return array<string, string>
     */
    private function validated(Request $request, array $extra = []): array
    {
        $channel = (string) $request->input('channel');

        return $request->validate([
            'estate' => ['required', 'string', 'max:64'],
            'channel' => ['required', 'string', 'in:email,sms'],
            'destination' => $channel === 'email'
                ? ['required', 'string', 'email', 'max:190']
                : ['required', 'string', 'regex:/^\+?[\d\s\-()]{7,20}$/'],
            ...$extra,
        ]);
    }

    /**
     * Delete the demonstration account's demo sessions nobody has touched for a day past
     * the idle window — by their age, never by a name another install shares (B060).
     *
     * Only demo sessions: the same flagged account also signs in with its static code
     * through `auth.otp.verify` (tokens named `resident-app:<device>`), and those are the
     * reviewer's own, ended by the idle window and by signing out like anybody's.
     */
    private function sweepAbandonedDemoSessions(ResidentAccount $account): void
    {
        $before = Carbon::now()
            ->subMinutes(SessionWindow::minutes())
            ->subHours(self::DEMO_SWEEP_GRACE_HOURS);

        $account->tokens()
            ->where(fn ($demo) => $demo->where('name', self::DEMO_TOKEN)->orWhere('name', 'like', self::DEMO_TOKEN.':%'))
            ->where(fn ($idle) => $idle
                ->where('last_used_at', '<', $before)
                ->orWhere(fn ($neverUsed) => $neverUsed->whereNull('last_used_at')->where('created_at', '<', $before)))
            ->delete();
    }

    private function estate(string $id): Tenant
    {
        $estate = Tenant::query()->find($id);

        if (! $estate instanceof Tenant || $estate->status !== 'active') {
            throw ApiError::notFound('estate_not_found', 'No estate on this platform by that name is taking sign-ins.');
        }

        return $estate;
    }
}
