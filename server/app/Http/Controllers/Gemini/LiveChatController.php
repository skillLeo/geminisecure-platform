<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Support\LiveChat;
use App\Support\SourceBadge;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Dispatch → Live chat (M50 §4.3): the control room answers residents who wrote on the Resident App's Help & support.
 *
 * READING IS `view`, ANSWERING IS `update` — the split every dispatch screen makes: an answer puts words on a resident's
 * phone in Gemini Security's name, and a role that may watch the queue is not thereby a role that may speak for the
 * company. Only the estates the viewer covers, never the demonstration one (`OperatorEstates::narrow`), and a
 * conversation on another estate is a 404, not a page.
 */
class LiveChatController extends Controller
{
    public function __construct(private readonly LiveChat $chat) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $wanted = $request->integer('chat') ?: null;
        $selected = $wanted === null ? null : $this->chat->find($viewer, $wanted);

        return inertia('Gemini/Dispatch/Chat', [
            // Category B: every row on this screen was written on a resident's phone. Live chat has no simulator.
            'sourceBadge' => SourceBadge::resident(false),
            'sections' => DispatchController::sectionTabs('chat'),
            'chats' => $this->chat->queue($viewer),
            // Drawing it is the control room being shown the resident's messages: they read "Delivered" from here.
            'thread' => $selected === null ? null : $this->chat->forConsole($selected),
            'missing' => $wanted !== null && $selected === null,
            'typicalReplyMinutes' => $this->chat->typicalReplyMinutes(),
            'canReply' => (bool) $viewer->can('gemini.dispatch.update'),
            'replyBlockedReason' => 'Answering a resident puts words on their phone in Gemini Security\'s name, so it needs Dispatch update access — the same as answering an alert. You can read these conversations.',
        ]);
    }

    public function reply(Request $request, int $chat): RedirectResponse
    {
        $found = $this->chat->find($request->user(), $chat);
        abort_if($found === null, 404);

        $data = $request->validate(
            ['body' => ['required', 'string', 'max:'.LiveChat::MAX_BODY]],
            ['body.required' => 'Write the answer first.', 'body.max' => 'Keep an answer under 1,000 characters, or send it in two.'],
        );

        try {
            $this->chat->fromStaff($found, $request->user(), (string) $data['body']);
        } catch (DomainException $refused) {
            return back()->withErrors(['body' => $refused->getMessage()]);
        }

        return redirect()->to('/dispatch/chat?chat='.$found->id);
    }

    public function resolve(Request $request, int $chat): RedirectResponse
    {
        $found = $this->chat->find($request->user(), $chat);
        abort_if($found === null, 404);

        $this->chat->resolve($found, $request->user());

        return redirect()->to('/dispatch/chat?chat='.$found->id)
            ->with('status', 'Marked resolved. If '.$this->chat->residentName($found).' writes again, it opens again at the top of the list.');
    }
}
