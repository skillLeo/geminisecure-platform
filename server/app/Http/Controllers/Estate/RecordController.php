<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read endpoints for estate records, addressed by id.
 *
 * These exist primarily so the Phase 1 gate has something to probe: an
 * authenticated committee member of one estate requesting a known record id
 * belonging to another. Any 200 fails the build.
 *
 * The lookups are deliberately naive — no ownership filter, no scope clause.
 * That is the point. If tenant isolation depended on a WHERE clause here, an
 * engineer would eventually forget one. Isolation comes from the connection
 * these models resolve through, which authenticates as the current estate's
 * own MySQL user and holds no grant on any other estate.
 *
 * TWO OF THEM ARE MONEY, AND THOSE TWO ASK WHO IS READING (M10 §5). A charge is
 * what a household was billed and a journal is the ledger itself; each returns
 * the raw row, figures and all. The route carries the module's gate; this
 * class adds the estate's-position gate, `Dues::maySeeEstatePosition()`,
 * because a record addressed by id is the register read one row at a time —
 * counting ids from one is how a role refused the arrears register would read
 * it anyway. Refused BEFORE the lookup, so the answer is the same for an id
 * that exists and one that does not, and says so in a sentence.
 *
 * THE OTHER THREE ARE THE RESIDENTS REGISTER, AND ASK ITS QUESTION (bug hunt
 * B072). The route gives each `can:estate.residents.view`, which every seeded
 * estate role holds and no Gemini role does, so a platform-wide Gemini account
 * that reaches the estate cannot collect its residents' contact details by id,
 * and nor can a role a community made without Residents access (M10 §4). And
 * the household's `access_restricted` flag is money by D-139's own rule — a
 * list of who is behind is about what they pay — so walking households by id
 * would rebuild the arrears register the position rule withholds. It is omitted
 * for a viewer who fails `Dues::maySeeEstatePosition()`, the way D-139 omits
 * every withheld key rather than blanking it.
 */
class RecordController extends Controller
{
    private const NO_CHARGE_RECORD = 'A charge record carries what a household was billed. Reading charges by id is reading the estate\'s whole ledger a row at a time, so it needs Reports access as well as Dues & ledger, and your role does not hold both.';

    private const NO_JOURNAL_RECORD = 'A journal record is the estate\'s ledger itself, figures and all. Reading journals by id needs Reports access as well as Accounting, and your role does not hold both.';

    /*
     * Route parameters always arrive as strings. Under strict_types an `int`
     * hint here raises a TypeError and surfaces as a 500 — which in an
     * isolation test reads as a denial and quietly passes. The routes
     * constrain {id} to digits, so the cast is safe.
     */
    public function resident(string $id): JsonResponse
    {
        return response()->json(Resident::findOrFail((int) $id));
    }

    public function household(Request $request, string $id): JsonResponse
    {
        $household = Household::findOrFail((int) $id);

        if (! Dues::maySeeEstatePosition($request->user(), 'dues_ledger')) {
            $household->makeHidden('access_restricted');
        }

        return response()->json($household);
    }

    public function unit(string $id): JsonResponse
    {
        return response()->json(Unit::findOrFail((int) $id));
    }

    public function charge(Request $request, string $id): JsonResponse
    {
        abort_unless(Dues::maySeeEstatePosition($request->user(), 'dues_ledger'), 403, self::NO_CHARGE_RECORD);

        return response()->json(Charge::findOrFail((int) $id));
    }

    public function journal(Request $request, string $id): JsonResponse
    {
        abort_unless(Dues::maySeeEstatePosition($request->user(), 'accounting_posting'), 403, self::NO_JOURNAL_RECORD);

        return response()->json(Journal::findOrFail((int) $id));
    }
}
