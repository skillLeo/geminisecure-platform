<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Estate\Household;
use App\Services\Restriction\RestrictionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/passes/verify - the Guard App's scan verdict.
 *
 * INVARIANT 2 IS THIS FILE'S WHOLE JOB.
 *
 * The response carries a verdict, a household name and a unit. It carries no
 * balance, no ageing bucket, no payment history, and no wording that implies
 * money. `access_restricted` is a boolean and nothing more.
 *
 * That is enforced by construction rather than by remembering to omit fields:
 * the payload is assembled from Household::guardVisibleStanding() and the
 * policy's verdict, neither of which can express an amount. There is no code
 * path here that reads a charge.
 *
 * THREE VERDICTS, and the middle one is the point (D-025):
 *   admit       green   the pass is valid and the household is clear
 *   restricted  AMBER   valid pass, household restricted - call management
 *   deny        red     the pass itself is invalid or expired
 *
 * Amber, not red, because the pass IS valid and the person at the gate is
 * known. The guard's next action is to call management, not to turn someone
 * away, and a red verdict would tell them the wrong thing.
 */
class PassVerificationController extends Controller
{
    public function verify(Request $request, RestrictionPolicy $policy): JsonResponse
    {
        $data = $request->validate([
            'household_id' => ['required', 'integer'],
            'pass_category' => ['required', 'string', 'max:32'],
            'pass_reference' => ['nullable', 'string', 'max:64'],
        ]);

        $household = Household::find($data['household_id']);

        if ($household === null) {
            // Deliberately vague. A guard needs to know the scan failed, not
            // which household ids exist in this estate.
            return response()->json([
                'verdict' => 'deny',
                'tone' => 'red',
                'headline' => 'Pass not recognised',
                'detail' => 'This pass does not match any household at this estate.',
            ], 404);
        }

        $decision = $policy->decide($household, $data['pass_category']);

        if ($decision['admitted']) {
            return response()->json([
                'verdict' => 'admit',
                'tone' => 'green',
                'headline' => 'Admit',
                'detail' => $decision['category_exempt']
                    ? 'This category is never restricted.'
                    : null,
                'household' => $household->name,
                'unit' => $household->unit?->reference,
                ...$household->guardVisibleStanding(),
            ]);
        }

        return response()->json([
            'verdict' => 'restricted',
            'tone' => 'amber',
            'headline' => 'Access restricted',
            'detail' => 'Contact management.',
            'household' => $household->name,
            'unit' => $household->unit?->reference,
            ...$household->guardVisibleStanding(),
        ]);
    }
}
