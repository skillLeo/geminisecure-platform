<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Dispatch\PanicWatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THE ONE QUESTION A SOUNDING SIREN ASKS (B107): "is anybody still waiting on these?"
 *
 * `usePanicAlarm` promised to repeat "until the dispatcher silences it or the alert leaves the
 * unacknowledged state", and only the click was built. An acknowledgement made on another
 * dispatcher's console is not broadcast, so the screen sounding the klaxon never learnt of it.
 * While it sounds, it asks this every few seconds about the alerts it is sounding for, and stops
 * once none of them is still owed an answer (`PanicWatch::stillAwaiting`).
 *
 * JSON, not a partial reload: the siren sounds on five different screens with five different
 * sets of props, and this answers only the question. `no-store`: an answer about who has
 * responded to a panic is stale the moment it is read.
 */
class PanicAlarmController extends Controller
{
    public function __invoke(Request $request, PanicWatch $watch): JsonResponse
    {
        // Required: an empty question must not come back as an empty answer the siren reads as "acknowledged".
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:'.PanicWatch::MAX_ASKED],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return response()
            ->json([
                'awaiting' => $watch->stillAwaiting($request->user(), array_map('intval', $data['ids'])),
                'checked_at' => now()->toIso8601String(),
            ])
            ->header('Cache-Control', 'no-store');
    }
}
