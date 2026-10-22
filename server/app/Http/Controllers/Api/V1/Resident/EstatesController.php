<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\DemoMode;
use Illuminate\Http\JsonResponse;

/**
 * The estates a resident can pick from before they have an account.
 *
 * A typed community code is the easiest thing in the product for a homeowner
 * to get wrong, so the Resident App shows a list and keeps the typed code only
 * as a fallback. This is the list.
 *
 * It is asked BEFORE sign-in, so it carries no token and says as little as a
 * sign on the estate's own gate does: the id the sign-in endpoints take, the
 * estate's name, and its parish to tell two "Phoenix Park"s apart. Only ACTIVE
 * estates — one still being set up, suspended or closed is not offered, which
 * is the same rule `auth.otp.request` applies when it is handed an id — AND only
 * estates marked `listed` (ESTATES-01): an internal or test tenant never reaches
 * a resident's screen, while a real estate is listed by default and, if it were
 * ever wrongly left off, is still reachable by its community code. No address, no
 * counts, no phases, nothing about anybody who lives there.
 */
class EstatesController extends Controller
{
    public function index(): JsonResponse
    {
        // `Tenant::estates()`, typed as Tenants: stancl's own collection erases the element type.
        $items = Tenant::estates(static function ($query): void {
            $query->select(['id', 'name', 'parish', 'demo'])
                ->where('status', 'active')
                ->where('listed', true)
                // AND, on a live system, no demonstration estate at all (M16 §1.2). `listed`
                // already hides the internal tenants; this makes "demonstration estates are
                // invisible everywhere" literally true — with the switch off the demo estate
                // is gone from the pick list, so the app never draws a demo community it could
                // not enter (`demo.enter` refuses one too, ReviewerMode::estate()).
                //
                // THE SAME QUESTION `ReviewerMode::estate()` ASKS (found M17): whether a reviewer
                // may reach one. It asked only the demonstration switch, which is OFF on a
                // launched system, so a store review there — the review window open and nothing
                // else — found neither the estate the notes name nor the demo door.
                ->when(! DemoMode::reviewerAllowed(), static fn ($q) => $q->where('demo', false))
                ->orderBy('name');
        })
            ->map(fn (Tenant $estate): array => [
                'id' => (string) $estate->id,
                'name' => (string) $estate->name,
                'parish' => $estate->parish === null ? null : (string) $estate->parish,

                /*
                 * The DEMONSTRATION community (M5 §3). Said here so the app can label it as
                 * one wherever it appears and can offer "Explore a demo community" only when
                 * this platform actually has one — a control that would otherwise be dead.
                 */
                'demo' => (bool) $estate->demo,
            ])
            ->values();

        return response()->json(['items' => $items]);
    }
}
