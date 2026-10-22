<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Api\ApiError;
use App\Http\Controllers\Controller;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Services\ResidentApp\AccountDeletion;
use App\Services\ResidentApp\ResidentSignIn;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "LOG IN TO YOUR COMMUNITY AS A RESIDENT" ON THE WEB (M24 §4.2, §4.4, D-223).
 *
 * The client's words: when the apps are down, a resident logs in on the website "to a web version of the software".
 * That web version does not exist yet: the resident's platform is the app. So this is the honest minimum the order
 * asks for, and nothing more: the resident proves their address with the same six-digit code the app uses, and lands
 * on a page that says to use the app for now and gives their estate office's contact. The real web version is a
 * decision for the client (CLIENT_ACTIONS.md, decision 6).
 *
 * NOTHING HERE SAYS WHO IS A RESIDENT.
 *   the email step    every address goes on to the code step, which says the same sentence for all of them
 *   the code          sent only to an address with an open resident account, AFTER the response, so an address
 *                     with no account answers no faster than one with an account; an address with no account is
 *                     never sent a code (the app's own endpoint would create a pending account for it on proof)
 *   a wrong code      the same three sentences for both kinds of address: not right, expired, tried too often
 *
 * THE CODE IS THE APP'S (ResidentSignIn): six digits, ten minutes, five tries, and the same hourly limits for the
 * address. Proving it proves control of the address, so the page then shows every community that address is a
 * resident of. It opens no estate, mints no app token and signs nobody in to anything else.
 */
final class ResidentDoorController extends Controller
{
    /** The code step in progress: the address, the estate its code is for (null: no open account), and misses. */
    private const STEP_KEY = 'front_door.resident';

    /** A resident who proved a code here: their open accounts, until when. */
    public const SESSION_KEY = 'community.resident';

    /** As long as an idle phone stays signed in to the app. */
    public const SESSION_MINUTES = 30;

    /** For an address with no account: the same count of tries a real code gets. */
    private const MISSES = ResidentSignIn::MAX_ATTEMPTS;

    public const WRONG = 'That code is not right. Check the newest email and try again.';

    public const EXPIRED = 'That code has expired. Send a new code and try again.';

    public const LOCKED = 'That code was tried too many times. Send a new code and try again.';

    public function __construct(
        private readonly ResidentSignIn $signIn,
        private readonly AccountDeletion $accounts,
    ) {}

    public function show(Request $request): View
    {
        $state = $this->state($request);

        return view('public.front-door', [
            'title' => 'Log in as a resident',
            'step' => $state === null ? 'resident-email' : 'resident-code',
            'email' => $state['email'] ?? null,
        ]);
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:190']], [
            'email.required' => 'Enter the email address you signed up with.',
            'email.email' => 'That is not an email address. Check it and try again.',
        ]);

        $email = ResidentSignIn::normalise('email', (string) $data['email']);
        $account = $this->openAccounts($email)->first();

        $request->session()->put(self::STEP_KEY, ['email' => $email, 'estate' => $account?->tenant_id, 'misses' => 0, 'at' => Carbon::now()->getTimestamp()]);
        $this->sendAfterResponse($account?->tenant_id, $email);

        return redirect()->route('front-door.resident');
    }

    public function again(Request $request): RedirectResponse
    {
        $state = $this->state($request);

        if ($state === null) {
            return redirect()->route('front-door.resident');
        }

        $request->session()->put(self::STEP_KEY, [...$state, 'misses' => 0, 'at' => Carbon::now()->getTimestamp()]);
        $this->sendAfterResponse($state['estate'], $state['email']);

        return redirect()->route('front-door.resident')->with('status', 'If that address belongs to a resident, a code is on its way. A new code can be sent once a minute.');
    }

    public function code(Request $request): RedirectResponse
    {
        $state = $this->state($request);

        if ($state === null) {
            return redirect()->route('front-door.resident');
        }

        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], [
            'code.required' => 'Enter the 6 digit code from the email.',
            'code.regex' => 'Enter the 6 digit code from the email.',
        ]);

        $estate = $state['estate'] === null ? null : Tenant::query()->find($state['estate']);

        if ($estate === null) {
            // No open account at this address: answered as a real code is, by the same count and the same clock.
            $misses = (int) $state['misses'] + 1;
            $request->session()->put(self::STEP_KEY, [...$state, 'misses' => $misses]);

            $expired = Carbon::now()->getTimestamp() - (int) $state['at'] > ResidentSignIn::TTL_MINUTES * 60;

            throw ValidationException::withMessages(['code' => $expired ? self::EXPIRED : ($misses >= self::MISSES ? self::LOCKED : self::WRONG)]);
        }

        try {
            $this->signIn->verify($estate, 'email', $state['email'], $data['code']);
        } catch (ApiError $e) {
            throw ValidationException::withMessages(['code' => $this->said($e)]);
        }

        $open = $this->openAccounts($state['email']);

        if ($open->isEmpty()) {
            // Proven, but closed between the two steps (deleted, or ended by the office).
            $request->session()->forget(self::STEP_KEY);

            return redirect()->route('front-door.resident')->withErrors(['email' => 'This account is not open. Please contact your estate office.']);
        }

        $request->session()->forget(self::STEP_KEY);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'accounts' => $open->modelKeys(),
            'until' => Carbon::now()->addMinutes(self::SESSION_MINUTES)->getTimestamp(),
        ]);

        return redirect()->route('community');
    }

    public function restart(Request $request): RedirectResponse
    {
        $request->session()->forget(self::STEP_KEY);

        return redirect()->route('front-door.resident');
    }

    /** The signed-in page: use the app for now, and the estate office's contact. */
    public function community(Request $request): View|RedirectResponse
    {
        $held = $request->session()->get(self::SESSION_KEY);

        if (! is_array($held) || ! isset($held['accounts'], $held['until']) || (int) $held['until'] < Carbon::now()->getTimestamp()) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('front-door.resident');
        }

        $open = $this->open(ResidentAccount::query()->whereIn('id', (array) $held['accounts']))->get();

        if ($open->isEmpty()) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('front-door.resident');
        }

        // Idle for thirty minutes signs out, as the app does; every visit is use.
        $request->session()->put(self::SESSION_KEY, [...$held, 'until' => Carbon::now()->addMinutes(self::SESSION_MINUTES)->getTimestamp()]);

        // Not empty: the empty case returned above.
        $first = trim((string) strtok(trim((string) $open->first()->full_name), ' '));

        return view('public.community', [
            'title' => 'Your community platform',
            'name' => $first === '' ? null : $first,
            'communities' => $open->map(fn (ResidentAccount $account): array => [
                'name' => (string) $account->estate?->name,
                ...$this->accounts->officeContact($account),
            ])->unique('name')->values()->all(),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerateToken();

        return redirect()->route('front-door.show');
    }

    /* ------------------------------------------------------------------ */

    /** @return array{email: string, estate: ?string, misses: int, at: int}|null */
    private function state(Request $request): ?array
    {
        $state = $request->session()->get(self::STEP_KEY);

        return is_array($state) && isset($state['email'], $state['at']) && is_string($state['email'])
            ? ['email' => $state['email'], 'estate' => isset($state['estate']) ? (string) $state['estate'] : null, 'misses' => (int) ($state['misses'] ?? 0), 'at' => (int) $state['at']]
            : null;
    }

    /**
     * The open resident accounts at an email address, the most recently used first.
     *
     * @return Collection<int, ResidentAccount>
     */
    private function openAccounts(string $email): Collection
    {
        return $this->open(ResidentAccount::query()->where('channel', 'email')->where('destination', $email))
            ->orderByDesc('verified_at')
            ->get();
    }

    /**
     * @param  Builder<ResidentAccount>  $query
     * @return Builder<ResidentAccount>
     */
    private function open($query)
    {
        return $query->where('status', ResidentAccount::ACTIVE)
            ->whereNotNull('resident_id')->whereNotNull('unit_id')
            ->whereNull('deleted_at')->whereNull('revoked_at')
            ->whereHas('estate')
            ->with('estate');
    }

    /** The code, once the page has answered, so an address with an account answers no slower than one without. */
    private function sendAfterResponse(?string $tenantId, string $email): void
    {
        if ($tenantId === null) {
            return;
        }

        dispatch(static function () use ($tenantId, $email): void {
            $estate = Tenant::query()->find($tenantId);

            if ($estate === null) {
                return;
            }

            try {
                app(ResidentSignIn::class)->requestCode($estate, 'email', $email, onWeb: true);
            } catch (ApiError) {
                // Refused (the hour's codes spent, a domain that takes no mail): said nowhere, as for an address with no account.
            } catch (Throwable $e) {
                report($e);
            }
        })->afterResponse();
    }

    /** ResidentSignIn's refusal, in the page's own three sentences (the hour's limit says its own). */
    private function said(ApiError $e): string
    {
        return match ($e->errorCode) {
            'otp_expired' => self::EXPIRED,
            'otp_locked' => self::LOCKED,
            'rate_limited' => $e->getMessage(),
            default => self::WRONG,
        };
    }
}
