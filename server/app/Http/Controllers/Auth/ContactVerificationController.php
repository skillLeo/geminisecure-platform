<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureContactVerified;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Rules\ReceivesMail;
use App\Services\ContactVerification;
use App\Services\Platform\SmsSettings;
use App\Support\ConsoleHome;
use App\Support\EstatePhone;
use App\Support\JamaicanNumber;
use App\Support\SiteTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The contact gate's one page — M10 §1.4 — on whichever door it was reached from.
 *
 * ONE PAGE, BOTH CONSOLES. Registered on the central host and on every estate's
 * own door, with `auth` and without `EnsureContactVerified` — a gate that guarded
 * its own way through would be a wall. It is drawn on the SIGN-IN card of the door
 * it was reached from (`LoginController::doorFor()`), because it is still part of
 * getting in: the console has not opened yet, so there is no sidebar to put it in.
 *
 * WHAT IT PROMISES, AND ONLY THAT:
 *   - the email field, prefilled with the sign-in address (or the address already
 *     on file), which the reader may change;
 *   - an optional phone, stored as typed once it can be dialled, and worded from
 *     `SmsSettings::canSend()` — no provider is connected today, so the screen says
 *     texts are not sent yet rather than implying they are;
 *   - Send code, a six-digit field and Verify; the ten-minute expiry, the five
 *     tries and the one-code-a-minute cooldown are the SERVICE's numbers, passed
 *     down so the screen cannot state a rule the server does not enforce;
 *   - what to do when the code does not arrive, ending with the administrator —
 *     who cannot verify an address for anybody, and the page says so;
 *   - Sign out.
 *
 * REACHABLE ONCE VERIFIED, deliberately. Somebody whose alert address changes needs
 * a way to change it, and the page shows the proven address and when it was proven.
 * Sending a code to a different address clears that proof at once — the page says
 * so beside the button, before it is pressed.
 */
final class ContactVerificationController extends Controller
{
    public function __construct(
        private readonly ContactVerification $verification,
        private readonly SmsSettings $sms,
    ) {}

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $root = EnsureContactVerified::doorRoot($request);
        $canText = $this->sms->canSend();

        return inertia('Auth/VerifyContact', [
            'door' => LoginController::doorFor(tenant()),
            'root' => $root,

            'account' => [
                'name' => $user->name,
                'login_email' => $user->email,
            ],

            'contact' => [
                // Prefilled with the sign-in address until the reader has typed one.
                'email' => $user->contact_email ?? mb_strtolower((string) $user->email),
                'phone' => $user->contact_phone,
                'verified' => $user->hasVerifiedContact(),
                'verified_at' => $user->contact_email_verified_at?->toIso8601String(),
                'verified_at_label' => $user->contact_email_verified_at === null ? null : SiteTime::fmt(SiteTime::local($user->contact_email_verified_at), 'M j, Y g:i A'),
            ],

            'code' => $this->verification->pending($user),

            'rules' => [
                'ttl_minutes' => ContactVerification::TTL_MINUTES,
                'max_attempts' => ContactVerification::MAX_ATTEMPTS,
                'resend_seconds' => ContactVerification::RESEND_SECONDS,
                'code_length' => ContactVerification::CODE_LENGTH,
            ],

            /*
             * THE PHONE'S WORDING COMES FROM THE ONE QUESTION THAT DECIDES IT. With
             * no provider connected the `log` driver writes a message down and
             * delivers it to nobody, so "we will text you" would be a claim the
             * platform cannot keep.
             */
            'sms' => [
                'can_send' => $canText,
                'note' => $canText
                    ? 'Alerts are also texted to this number.'
                    : 'Stored ready for text messages. No text-message provider is connected yet, so texts are not sent. Alerts reach you by email only until one is.',
            ],

            'required' => $user->isSubjectToContactGate(),
            'continue_href' => $user->hasVerifiedContact() ? $this->home($request, $user) : null,
            'administrator' => $this->administrator($user),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Nothing is optional (M18C §2): every console user has a verified email AND a phone the control room can call.
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:191', new ReceivesMail],
            'phone' => ['required', 'string', 'max:32', new PhoneNumber],
        ], [
            'email.required' => 'Enter the email address alerts should reach you at.',
            'email.email' => 'That is not an email address. Check it for a missing @ or a typing slip.',
            'email.max' => 'That address is longer than the platform can store (191 characters).',
            // M32 F7: the estate's own example, or the platform's home's in the Gemini console.
            'phone.required' => 'Enter your mobile number: the control room calls it in an emergency. Example: '.EstatePhone::forEstate()['local'].'.',
            'phone.max' => 'That phone number is longer than any real one (32 characters at most).',
        ]);

        $this->verification->send($user, (string) $data['email'], JamaicanNumber::normalise((string) $data['phone']), $this->consoleName());

        return redirect()
            ->to(EnsureContactVerified::pageUrl($request))
            ->with('status', sprintf(
                'A %d-digit code is on its way to %s. It works for %d minutes.',
                ContactVerification::CODE_LENGTH,
                mb_strtolower(trim((string) $data['email'])),
                ContactVerification::TTL_MINUTES,
            ));
    }

    public function verify(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'verification_code' => ['required', 'string', 'max:16'],
        ], [
            'verification_code.required' => 'Enter the six digits from the email.',
        ]);

        $this->verification->verify($user, (string) $data['verification_code']);

        return redirect()
            ->intended($this->home($request, $user))
            ->with('success', 'Your alert address is verified. Emergency alerts will reach you at '.$user->fresh()?->contact_email.'.');
    }

    public function phone(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Changed, never removed (M18C): a console user nobody can call is one the control room cannot reach.
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32', new PhoneNumber],
        ], [
            'phone.required' => 'Enter your mobile number: the control room calls it in an emergency. Example: '.EstatePhone::forEstate()['local'].'.',
            'phone.max' => 'That phone number is longer than any real one (32 characters at most).',
        ]);

        $this->verification->savePhone($user, JamaicanNumber::normalise((string) $data['phone']));

        return redirect()
            ->to(EnsureContactVerified::pageUrl($request))
            ->with('status', 'The phone number was saved.');
    }

    /**
     * Where "continue" goes: this door's own console.
     *
     * NOT `ConsoleHome::for()` on an estate door. A Gemini Director may open an
     * estate's console on that estate's hostname, and their home is the Gemini
     * dashboard on another host — where this session's cookie does not travel. The
     * console they verified from is the console they continue into.
     */
    private function home(Request $request, User $user): string
    {
        if (tenancy()->initialized) {
            return url(EnsureContactVerified::doorRoot($request) ?: '/');
        }

        return ConsoleHome::for($user);
    }

    /** How the code's email names the console it is for. */
    private function consoleName(): string
    {
        $tenant = tenant();

        return $tenant === null
            ? 'Gemini Console'
            : (string) $tenant->name.' Estate Console';
    }

    /**
     * Who "ask your administrator" means for this reader.
     *
     * Named by role rather than by person: the page must not become a directory of
     * who holds which office, and the reader knows who their administrator is.
     */
    private function administrator(User $user): string
    {
        return $user->console === Console::Estate
            ? "your estate's Community Super Admin, or the estate office"
            : 'the Gemini Director';
    }
}
