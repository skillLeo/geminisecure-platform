<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\Governance;
use App\Services\Estate\Notices;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Estate notices — board community-admin-32.
 *
 * POSTING IS `create`, NOT `approve`, AND THAT IS DELIBERATE. Publishing a
 * meeting is `approve` because it commits four hundred and fifty households to a
 * date they will arrange their Saturday around; a notice tells them something
 * and asks nothing of them. The two are not the same weight, and gating a gate
 * closure behind the President would mean the Property Manager who closed the
 * gate could not say so.
 *
 * IT SITS UNDER GOVERNANCE BECAUSE THE BOARD PUTS IT THERE. Board 32's own
 * sub-navigation is Notices / Meetings / Elections and its URL is
 * /governance/notices, so a notice is a governance act in this estate's own
 * language even though its author is often the Property Manager.
 */
class NoticesController extends Controller
{
    public function index(Request $request, Notices $notices, Governance $governance): Response
    {
        return inertia('Estate/Governance/Notices', [
            'estate' => ['name' => (string) tenant()->name],
            // M32 F5: newest first unless the headings ask otherwise, a page at a time, with the notices' search.
            ...$notices->board(Notices::table($request)),
            'canPost' => $request->user()->can('estate.governance.create'),

            // Board 32's "Elections" tab leads where board 36's does — the latest
            // election this estate has held — by the same rule, asked once.
            'electionYear' => $governance->electionYear(),
            'reasons' => [
                'post' => 'Posting a notice tells every household on the estate something, so it needs '.
                    'Governance create access, which this role does not hold.',
            ],
        ]);
    }

    /**
     * Post a notice — board 32's one button.
     *
     * WHAT IT DID, UNDER THE KEYS A PAGE IS SENT (B048). This flashed its news as
     * `flash`, and `HandleInertiaRequests` shares `success` and `error` and nothing
     * else — so a notice that went out was reported to nobody, and the composer
     * looked like a button that did nothing. Its refusals were never drawn either:
     * the composer read no error at all. Both are said now, the refusals in the
     * office's words rather than the validator's kilobytes and MIME types.
     */
    public function store(Request $request, Notices $notices): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:general,urgent'],
            'title' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:4000'],
            'audience_scope' => ['required', 'string', 'in:estate,phase'],
            'audience_phase' => ['nullable', 'string', 'max:40'],

            // OPTIONAL (M4 §3.5): a file to put with the notice — the contractor's letter, a map. Kept through the
            // documents path (`Notices::attach`); the Resident App lists it and fetches it through its own token.
            'attachment' => ['nullable', 'file', 'max:'.Notices::ATTACHMENT_MAX_KB, 'mimetypes:'.implode(',', Notices::ATTACHMENT_TYPES)],
        ], [
            'attachment.max' => sprintf(
                'That file is larger than %d MB, which is the most a notice can carry. Nothing was posted.',
                intdiv(Notices::ATTACHMENT_MAX_KB, 1024),
            ),
            'attachment.mimetypes' => 'A notice can carry a PDF, a Word document, or a JPEG or PNG picture, and this file was not read as any of them. Nothing was posted.',
            'attachment.file' => 'The file did not arrive. Choose it again; nothing was posted.',

            // PHP refused the upload before the rules saw it — most often a file past what this server takes in.
            'attachment.uploaded' => 'The file did not arrive whole; it may be larger than this server accepts. Nothing was posted; choose a smaller file, or post the notice without it.',
        ]);

        try {
            $notice = $notices->post($data, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['notice' => $e->getMessage()]);
        }

        $file = $request->file('attachment');

        if ($file === null) {
            return back()->with('success', 'Notice posted.');
        }

        /*
         * THE NOTICE HAS GONE OUT BY HERE, so a file that could not be kept is not a
         * failed post and must not be reported as one: four hundred and fifty
         * households have already been told, and "could not post" would have the
         * office press the button a second time. Said as what it is instead.
         *
         * AND NOT AS A VALIDATION ERROR (B048). An error in the error bag is how
         * Inertia knows a post FAILED: the composer keeps its text and Post stays
         * live, in front of an office that has just told the estate something. So
         * the news is two notes — it went out; its file did not — and the composer
         * clears as it does for any notice that went out.
         */
        try {
            $notices->attach(
                $notice,
                (string) $file->getClientOriginalName(),
                (string) file_get_contents((string) $file->getRealPath()),
                (string) $file->getMimeType(),
                $request->user(),
            );
        } catch (DomainException $e) {
            return back()
                ->with('success', 'Notice posted, without its file.')
                ->with('error', 'The file did not go with the notice. '.$e->getMessage().' The notice itself went out, so do not post it again.');
        }

        return back()->with('success', 'Notice posted.');
    }
}
