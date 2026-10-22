<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Services\Dispatch\AlertProgress;
use App\Services\Dispatch\AlertProgressRefused;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;

/**
 * An officer responding to an alert at their own site, from their own handset (M10 §1.3).
 *
 *   GET  /alerts/{alert}           where the response stands, and whether THIS handset may add to it
 *   POST /alerts/{alert}/en-route  "I am on my way"
 *   POST /alerts/{alert}/on-scene  "I am there"
 *
 * The dispatcher can record both on the officer's behalf after a radio call; this is the officer
 * saying it themselves, which is faster and is different evidence — the timeline says which.
 *
 * WHO MAY: THE OFFICER SENT, OR — WHEN NOBODY WAS — ANY OFFICER THE ALERT REACHED. Nothing in a
 * panic waits on the dispatcher: every officer on shift is pushed it at once, and one who hears it
 * and runs must be able to say so without being picked first. Once an officer IS named — sent by a
 * dispatcher, or first to say "on my way" — only their handset speaks for the response, because a
 * second guard's "on scene" would tell the resident an officer has arrived while the one sent is
 * still three streets away. The rule is `AlertProgress`'s; this only speaks it.
 *
 * THE ESTATE IS THE TOKEN'S. An alert at another estate is the same 404 as an id nobody has: this
 * handset must not learn that an emergency exists at a client its officer does not work.
 *
 * WHAT IT NEVER SAYS IS WHO PRESSED IT. Where (the lot), what kind, and how far the response has
 * got — the same rule as every push a guard receives. A resident's name stays off a guard's screen.
 */
class ResponseController extends Controller
{
    public function show(int $alert, DeviceContext $context, AlertProgress $progress): JsonResponse
    {
        $guard = $context->guardOrFail();
        $record = $this->alertAt($alert, $context);

        if ((int) $record->guard_id === $guard->id) {
            throw ApiError::forbidden(AlertProgressRefused::OWN_ALERT, 'This is your own alert. Follow it with GET /duress/'.$record->id.'.');
        }

        if (! $progress->mayFollow($record, $guard)) {
            throw ApiError::forbidden(AlertProgressRefused::NOT_ON_SHIFT, 'You are not on shift at this estate, so this alert was not sent to you.');
        }

        return response()->json(self::shape($record, $guard, $progress));
    }

    public function enRoute(int $alert, DeviceContext $context, AlertProgress $progress): JsonResponse
    {
        $guard = $context->guardOrFail();
        $record = $this->alertAt($alert, $context);

        try {
            $record = $progress->enRoute($record, $guard, $context->deviceTime);
        } catch (AlertProgressRefused $refused) {
            throw new ApiError($refused->status, $refused->errorCode, $refused->getMessage());
        }

        return response()->json(self::shape($record, $guard, $progress));
    }

    public function onScene(int $alert, DeviceContext $context, AlertProgress $progress): JsonResponse
    {
        $guard = $context->guardOrFail();
        $record = $this->alertAt($alert, $context);

        try {
            $record = $progress->onScene($record, $guard, $context->deviceTime);
        } catch (AlertProgressRefused $refused) {
            throw new ApiError($refused->status, $refused->errorCode, $refused->getMessage());
        }

        return response()->json(self::shape($record, $guard, $progress));
    }

    /** An alert at the token's own estate, or the 404 an id nobody has gets. */
    private function alertAt(int $alert, DeviceContext $context): DuressAlert
    {
        $record = DuressAlert::query()->with(['respondingGuard', 'estate'])
            ->whereKey($alert)
            ->where('tenant_id', $context->tenantId)
            ->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No alert at your site with that id.');
        }

        return $record;
    }

    /**
     * The alert as a responding officer sees it. Public so `ring.answer` hands back exactly
     * these keys — the phone opens the same screen from a ring as from this endpoint.
     *
     * @return array<string, mixed>
     */
    public static function shape(DuressAlert $alert, Guard $guard, AlertProgress $progress): array
    {
        $enRoute = $progress->refusalFor($alert, $guard, AlertProgress::EN_ROUTE);
        $onScene = $progress->refusalFor($alert, $guard, AlertProgress::ON_SCENE);
        $responder = $alert->responding_guard_id === null ? null : $alert->respondingGuard;

        return [
            'alert_id' => (int) $alert->id,
            'kind' => (string) $alert->kind,

            // A DRILL SAYS SO (M10 §1.3), here too: an officer opening this from the
            // "You are dispatched" push has no other way to learn it before they run.
            'is_drill' => (bool) $alert->is_drill,
            'status' => (string) $alert->status,
            'where' => $alert->unit_reference === null || $alert->unit_reference === '' ? null : (string) $alert->unit_reference,
            'raised_at' => SiteTime::iso($alert->server_time),
            'acknowledged_at' => SiteTime::iso($alert->acknowledged_at),
            'dispatched_at' => SiteTime::iso($alert->dispatched_at),
            'en_route_at' => SiteTime::iso($alert->en_route_at),
            'on_scene_at' => SiteTime::iso($alert->on_scene_at),
            'resolved_at' => SiteTime::iso($alert->resolved_at),
            'responder' => $responder?->full_name,
            'responder_is_you' => $responder !== null && (int) $responder->id === $guard->id,
            'dispatched_to_you' => $alert->dispatched_at !== null && $responder !== null && (int) $responder->id === $guard->id,
            'can_en_route' => $enRoute === null,
            'can_on_scene' => $onScene === null,

            // Why the LAST step cannot be recorded from here — the one sentence worth showing.
            'reason' => $onScene?->getMessage(),
        ];
    }
}
