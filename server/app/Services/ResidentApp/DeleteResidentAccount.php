<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Api\ApiError;
use App\Models\ResidentAccount;
use App\Services\Reviewer\ReviewerMode;

/**
 * Deleting a Resident App account, from inside the app (M5 §4; M18H).
 *
 * DELETION IS A STATE, NOT AN ERASURE (M18H §1). This used to empty the account on the day — name, address, device —
 * and a resident who pressed it by accident was gone, owing money the estate could no longer put a name to. Now the
 * account moves to `deleted` and keeps everything (`AccountDeletion::delete()`): signing in, the e-pass, the passes the
 * person sent, their future bookings and every notification stop at once; the ledger, the gate log, incidents, panics,
 * ballots and the audit trail do not. The person restores it themselves by signing in within the estate's first window
 * (7 days), the office within the second (30); after that the daily schedule anonymises it and nothing of them is left.
 *
 * The screen says all of that BEFORE the person types the confirmation — including what they owe, which does not go
 * away.
 */
class DeleteResidentAccount
{
    public function __construct(private readonly AccountDeletion $deletion, private readonly ReviewerMode $reviewer) {}

    /**
     * @return array{reference: string, deleted_at: string, restore_until: string, permanent_on: string}
     */
    public function delete(ResidentAccount $account): array
    {
        /*
         * THE APP-STORE REVIEWER'S DEMONSTRATION ACCOUNT IS NEVER DELETED (M5 §3 and §4 together). Both stores CHECK
         * in-app deletion, so a reviewer taps it; deleted, the static sign-in code in STORE_REVIEW_NOTES.md would match no
         * signed-in account and every later review would find no way in. So it is refused, in a sentence that says what
         * would have happened on a real account, and the refusal is audited under `reviewer.*`.
         */
        if (ReviewerMode::isReviewer($account)) {
            $this->reviewer->record(ReviewerMode::DELETION_DECLINED, $account, ['kept' => 'demonstration_account']);

            throw ApiError::conflict(
                'demo_account_kept',
                'This is the demonstration account used for App Store and Play review, so it is kept for the next '.
                'reviewer and nothing has been deleted. On a real account, confirming here signs you out on every '.
                'phone at once and stops your passes; you could restore it by signing in within 7 days, and after '.
                '30 days nothing of you would be left on it.'
            );
        }

        $done = $this->deletion->delete($account, AccountDeletion::BY_RESIDENT);

        return ['reference' => $done['reference'], 'deleted_at' => $done['deleted_at'], 'restore_until' => $done['self_until'], 'permanent_on' => $done['permanent_on']];
    }
}
