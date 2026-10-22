<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use App\Models\PublicRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * What an app store requires at a PUBLIC address (M5 §4, §10), and nothing else (M24, D-223): the Resident App's
 * privacy policy and terms, and the account-deletion request page Google Play checks for. The pages that described
 * the product (`/apps`, `/support`) are gone; the Guard App's policy lives inside the Guard App.
 *
 * Linked from nowhere on the website and marked `noindex` by the shared layout: a resident reaches them from the
 * app, and a store from the listing.
 *
 * Plain server-rendered pages on purpose: they must load for a store's automated checker and for a person on a
 * slow phone, with no script, no sign-in and no console chrome. Nothing here reads an estate's database.
 */
class PublicPagesController extends Controller
{
    /** `/` before the client's own site is built (M24): the company's name and Log in, and nothing about the product. */
    public function holding(): View
    {
        return view('public.holding', ['title' => 'Gemini Security Limited']);
    }

    /**
     * THE INVITE'S "OPEN THE APP" LINK (M18G §5): it opens the Resident App at sign-in with the INVITED address already
     * filled in, so the one path most people take into the product cannot be mistyped ("…@gmail.comm", 27 Sep).
     *
     * A SIGNED link (`URL::temporarySignedRoute`), valid until the invite lapses: the estate and the address ride in it,
     * tamper-proof, and nothing is read from any estate — this page opens none. It signs nobody in: the code is still
     * emailed to that address and proven there, so a forwarded email is a forwarded address and nothing more. A lapsed
     * link says so and says what to do.
     */
    public function openInvite(Request $request): View
    {
        $estate = (string) $request->query('estate', '');
        $email = (string) $request->query('email', '');
        $signed = URL::hasCorrectSignature($request) && $estate !== '' && $email !== '';
        $valid = $signed && URL::signatureHasNotExpired($request);
        $lapsed = $signed && ! $valid;
        $query = http_build_query(['estate' => $estate, 'email' => $email]);

        return view('public.open-invite', [
            ...$this->shared('Your invitation'),
            'valid' => $valid,
            'lapsed' => $lapsed,
            'estateName' => (string) $request->query('estate_name', 'your estate'),
            'invitedBy' => (string) $request->query('by', ''),
            'email' => $email,
            'lapsesOn' => (string) $request->query('lapses', ''),
            // Android: an intent that opens the app, or the store when it is not installed. Elsewhere: the app's scheme.
            'androidLink' => 'intent://log-in?'.$query.'#Intent;scheme=gs;package=jm.geminisecure.resident;end',
            'appLink' => 'gs://log-in?'.$query,
            'getApp' => config('services.resident_app.url'),
        ]);
    }

    public function privacy(): View
    {
        return $this->document('privacy', 'Privacy Policy');
    }

    public function terms(): View
    {
        return $this->document('terms', 'Terms of Use');
    }

    /**
     * For residents, and Google Play's own checker (M24, D-223). It names no other app, and it lists NO community:
     * the list it used to offer (M5 §3 kept the demonstration estate out of it) was the client's customer list on
     * the open web, which is exactly what a competitor would read first. The person types their community's name.
     */
    public function deleteAccount(): View
    {
        return view('public.delete-account', $this->shared('Delete your account'));
    }

    /** A message to support, or a request to delete an account — from somebody with no sign-in. */
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            // A support message is still accepted (the Gemini console reads both kinds); nothing on the site posts one now.
            'kind' => ['required', 'string', 'in:'.PublicRequest::ACCOUNT_DELETION.','.PublicRequest::SUPPORT],
            'app' => ['nullable', 'string', 'in:resident,guard'],
            'estate' => ['nullable', 'string', 'max:160'],
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            'contact' => ['required', 'string', 'min:5', 'max:190'],
            'message' => [$request->input('kind') === PublicRequest::SUPPORT ? 'required' : 'nullable', 'string', 'max:2000'],
            // A field a person never sees and a program fills in.
            'website' => ['prohibited'],
        ], [
            'full_name.required' => 'Enter your name as your community knows it.',
            'contact.required' => 'Enter the email address or phone number your account uses, so we can confirm it is you.',
            'message.required' => 'Tell us what you need help with.',
        ]);

        $record = PublicRequest::query()->create([
            'kind' => $data['kind'],
            'app' => $data['app'] ?? null,
            'estate' => $data['estate'] ?? null,
            'full_name' => $data['full_name'],
            'contact' => $data['contact'],
            'message' => $data['message'] ?? null,
            'status' => PublicRequest::RECEIVED,
            'reference' => PublicRequest::newReference(),
        ]);

        // The log says THAT a request arrived and its reference — never who from: the audit log is kept for years,
        // and a person who asked to be deleted must not live on in it.
        $audit->record(action: 'public.'.$record->kind.'_requested', entityType: 'PublicRequest', entityId: (string) $record->id, before: [], after: ['reference' => $record->reference, 'app' => $record->app]);

        return redirect()->route('public.received', ['reference' => $record->reference]);
    }

    public function received(string $reference): View
    {
        $record = PublicRequest::query()->where('reference', $reference)->firstOrFail();

        return view('public.received', [...$this->shared('We have your request'), 'kind' => $record->kind, 'reference' => $record->reference]);
    }

    private function document(string $name, string $title): View
    {
        $path = resource_path('legal/'.$name.'.md');
        $text = is_file($path) ? (string) file_get_contents($path) : '';
        $text = strtr($text, [
            '{{company}}' => (string) config('legal.company'),
            '{{product}}' => (string) config('legal.product'),
            '{{country}}' => (string) config('legal.country'),
            '{{support_email}}' => (string) (config('legal.support_email') ?: 'the support page'),
            // The postal address of the company answerable for the policy. A data-protection
            // regulator and a person exercising a right both need somewhere to write to, so the
            // Contact section of each document names it. Absent, the sentence reads without it
            // rather than printing an empty clause.
            '{{address}}' => (string) (config('legal.address') ?: 'the address on our support page'),
            '{{retention_days}}' => (string) config('legal.public_request_retention_days'),
        ]);

        return view('public.document', [
            ...$this->shared($title),
            'html' => Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }

    /** @return array<string, mixed> */
    private function shared(string $title): array
    {
        return [
            'title' => $title,
            'company' => (string) config('legal.company'),
            'product' => (string) config('legal.product'),
            'address' => config('legal.address'),
            'supportEmail' => config('legal.support_email'),
            'draft' => config('legal.status') !== 'final',
            'retentionDays' => (int) config('legal.public_request_retention_days'),
        ];
    }
}
