<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use App\Models\SiteEnquiry;
use App\Notifications\SiteEnquiryReceived;
use App\Support\CentralQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * THE CLIENT'S OWN SITE, SERVED AS THE CLIENT BUILT IT (M14 §3.1, D-194).
 *
 * `/`, `/contact` and `/privacy-policy` on the root host answer with the page the client's own code builds
 * (landing/ → public/landing/index.html): the same React, the same Tailwind, the same pictures and fonts, so there
 * is nothing to drift. Laravel adds only what every page of this host has: the session and its CSRF cookie, which
 * the page's two forms send back.
 *
 * ONLY ON THE ROOT HOST. An estate's host keeps its own `/` (its console), and `/contact` or `/privacy-policy` there
 * is 404, as it was.
 *
 * NOT BUILT? `/` falls back to a plain page with the company's name and Log in (M24: it used to be the M11 page that
 * described the product), and the other two are 404: never a blank page on the front door.
 *
 * THE FORMS. The contact form and the Apply page used to post to the client's old backend, which no longer answers
 * (its certificate does not match its name). Each is now written to `site_enquiries` and then emailed to the
 * address the page gives, and answered in the shapes the pages read: `{value: [...]}` for the community types, and
 * `{message}` for a refusal, which the pages show as they are.
 */
final class ClientSiteController extends Controller
{
    public function home(Request $request): Response|RedirectResponse|View
    {
        // As `/` always has: a signed-in Gemini account goes to its console (an estate one never gets this far).
        if ($request->user() !== null) {
            return redirect()->route('gemini.dashboard');
        }

        return $this->page() ?? app(PublicPagesController::class)->holding();
    }

    public function page(): ?Response
    {
        $index = (string) config('landing.site.index');

        if (! is_file($index)) {
            return null;
        }

        return response((string) file_get_contents($index), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // The page names its hashed assets; a new release's names must be read at once.
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /** `/contact` and `/privacy-policy`: the same page, which routes itself. */
    public function subpage(): Response
    {
        $page = $this->page();

        if ($page === null) {
            abort(404);
        }

        return $page;
    }

    public function communityTypes(): JsonResponse
    {
        return response()->json(['value' => array_values((array) config('landing.site.community_types'))]);
    }

    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fullName' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        return $this->receive($request, [
            'kind' => SiteEnquiry::CONTACT,
            'name' => trim($data['fullName']),
            'email' => trim($data['email']),
            'message' => trim($data['description']),
        ], 'Thank you! Our team will reach out to you via email.');
    }

    public function application(Request $request): JsonResponse
    {
        $types = array_column((array) config('landing.site.community_types'), 'name', 'id');

        $data = $request->validate([
            'communityTypeId' => ['required', 'string', Rule::in(array_keys($types))],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'address' => ['required', 'string', 'max:300'],
            'city' => ['required', 'string', 'max:120'],
            'zipCode' => ['required', 'string', 'max:40'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:120'],
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->receive($request, [
            'kind' => SiteEnquiry::APPLICATION,
            'name' => trim($data['firstName'].' '.$data['lastName']),
            'email' => trim($data['email']),
            'phone' => isset($data['phone']) ? trim((string) $data['phone']) : null,
            'message' => trim($data['description']),
            'details' => [
                'community_name' => trim($data['name']),
                'community_type' => $types[$data['communityTypeId']],
                'address' => trim($data['address']),
                'city' => trim($data['city']),
                'zip_code' => trim($data['zipCode']),
                'state' => isset($data['state']) ? trim((string) $data['state']) : null,
                'country' => trim($data['country']),
            ],
        ], 'Thank you! We received your application and will reach out shortly.');
    }

    /**
     * Kept first, then sent. A message the relay refuses is still here (and the refusal in `mail_failures`); only a
     * message that could not even be KEPT is refused to the sender, with a sentence the page shows.
     *
     * @param  array<string, mixed>  $row
     */
    private function receive(Request $request, array $row, string $thanks): JsonResponse
    {
        try {
            $enquiry = SiteEnquiry::query()->create([
                ...$row,
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'We could not send your message. Please try again.'], 503);
        }

        try {
            Notification::route('mail', (string) config('landing.site.enquiries_to'))
                ->notify(CentralQueue::pin(new SiteEnquiryReceived($enquiry)));
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['message' => $thanks, 'value' => ['id' => $enquiry->id]]);
    }
}
