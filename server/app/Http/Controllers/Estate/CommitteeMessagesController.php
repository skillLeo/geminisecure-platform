<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\CommitteeInbox;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Messages from Gemini Security, for the committee member they were addressed to
 * (Q-M40, D-M128). No board draws this screen.
 *
 * ONE SCREEN, ONE READ, NO IDS IN THE URL. There is no show page and no message
 * id in any path: the list renders each message in full, because a message that
 * has to be clicked to be read is one more place delivery can be claimed and not
 * happen. It also means there is nothing here a viewer could point at somebody
 * else's correspondence — the only two things the query is keyed on are the estate
 * this console is open on and the person signed into it.
 *
 * NO MODULE PERMISSION, ON PURPOSE. Every other estate route carries a `can:` for
 * its module. This one does not, because the thing being read is addressed to the
 * reader by name: gating it behind Governance or Settings would mean a Treasurer
 * could not read a letter written to the Treasurer. The gate is the address on the
 * message, and it is applied in SQL rather than in the render.
 *
 * OPENING IT IS WHAT MARKS IT READ, and that is the whole reason this screen
 * exists rather than an email. The stamp is written for the rows this response
 * actually rendered, the first time it renders them, so what the sender is later
 * shown — "opened Sep 21, 3:14 PM" — is a fact about the named recipient having
 * had the message in front of them, not a delivery receipt from a mail server.
 */
class CommitteeMessagesController extends Controller
{
    public function __invoke(Request $request, CommitteeInbox $inbox): Response
    {
        $viewer = $request->user();
        $tenantId = (string) tenant()->getTenantKey();

        /*
         * READ FIRST, STAMP SECOND, RENDER WHAT WAS READ. The rows are shaped
         * before anything is written, so the screen can say "opened just now"
         * about the ones it is showing for the first time without a second query —
         * and, more importantly, so a failure to write the stamp cannot cost the
         * reader the message. The correspondence is the point; the receipt is not.
         */
        $messages = $inbox->forViewer($viewer, $tenantId);

        $inbox->markOpened($viewer, $tenantId, array_column($messages, 'id'));

        return inertia('Estate/Messages/Index', [
            'estate' => ['name' => (string) tenant()->name],
            'messages' => $messages,
            // The way back is to the notifications the bell opened, where a message from Gemini Security links here (M98).
            'notificationsHref' => $this->path('/notifications'),
        ]);
    }

    /**
     * The estate path, in whichever shape this environment serves — the dashboard's
     * own rule, copied rather than shared for the two lines it is.
     *
     * Production gives each estate its own hostname, so the path is bare. Local
     * serves them all from one host with the estate in the path, because
     * *.localhost does not resolve on Windows (routes/tenant.php).
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
