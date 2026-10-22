<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Dispatch\PanicWatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THE ONE QUESTION THE CONSOLE'S PANIC POP-UP ASKS THE SERVER (M10 §1.2 items 3 and 4).
 *
 * "Is it still outstanding, and has anything arrived that I missed?" — asked every fifteen
 * seconds while an alert is outstanding or the live channel is down, and never otherwise.
 * The socket carries an alert the moment it is raised; it carries nothing when somebody
 * ACKNOWLEDGES one, so without this the blinking bar could not stop when it should, and a
 * panic raised while the socket was down would reach the Director only on their next page.
 *
 * JSON, NOT AN INERTIA PARTIAL RELOAD. A partial reload re-runs the controller of whatever
 * page the Director happens to be on — the live map, a cross-tenant report — to answer a
 * question about two rows, four times a minute. This answers only the question.
 *
 * `no-store`: an answer about who has responded to a panic is stale the moment it is read.
 */
class PanicWatchController extends Controller
{
    public function __invoke(Request $request, PanicWatch $watch): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['sometimes', 'array', 'max:'.PanicWatch::MAX_ASKED],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $answer = $watch->poll($request->user(), array_map('intval', $data['ids'] ?? []));

        // The route already refuses anyone without both dispatch permissions; this is the
        // second place, for a suspended account or a console that is not Gemini's.
        abort_if($answer === null, 403);

        return response()->json($answer)->header('Cache-Control', 'no-store');
    }
}
