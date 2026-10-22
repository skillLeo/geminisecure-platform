<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\ResidentApp\DeleteResidentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deleting your own Resident App account, from inside the app (M5 §4 — both stores require it).
 *
 * The person is shown, itemised, what goes and what stays BEFORE they get here; the app sends the
 * one word it showed them so a mis-tap cannot do this. Nothing is queued: an account deletion that
 * happened "later, when there is a signal" would be a lie on the confirmation screen.
 *
 * The account is deleted for whoever the TOKEN says this is — never an id from the request.
 */
class AccountController extends Controller
{
    /** The word the app puts in front of the person, in the language of the screen. */
    public const CONFIRMATION = 'DELETE';

    public function destroy(Request $request, DeviceContext $context, DeleteResidentAccount $deleting): JsonResponse
    {
        $data = $request->validate(['confirm' => ['required', 'string', 'max:16']]);

        if (mb_strtoupper(trim($data['confirm'])) !== self::CONFIRMATION) {
            throw ApiError::unprocessable('confirm_required', 'To delete the account the app must send the word it showed you.');
        }

        $account = $context->residentOrFail();
        $done = $deleting->delete($account);

        return response()->json([
            'deleted' => true,
            'reference' => $done['reference'],
            'deleted_at' => $done['deleted_at'],
            'restore_until' => $done['restore_until'],
            'permanent_on' => $done['permanent_on'],
        ]);
    }
}
