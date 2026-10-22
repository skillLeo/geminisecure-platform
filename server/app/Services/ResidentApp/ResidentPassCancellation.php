<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Models\Estate\VisitorPass;
use App\Services\Passes\VisitorPasses;
use Illuminate\Support\Carbon;

/**
 * A PERSON'S OWN E-PASSES, CANCELLED — the credential a guard admits a RESIDENT on (stage 2, W-ACCEPT).
 *
 * `RevokeResidentAccess` has cancelled them when the office ends somebody's app access since Q-M44. A
 * household removing a member left them working: the removal suspended the account but no pass, and the
 * gate refuses only a REVOKED resident's e-pass (`VisitorPasses`). So a removed member walked in on
 * their own pass. Both acts now cancel through here.
 *
 * Cancelled rather than deleted: `VisitorPass` rows are what the gate log points at, and `cancelled_at`
 * is what `/sync/pull` reads to tell a handset that has been out of signal to stop honouring it.
 *
 * Run inside the estate's tenancy.
 */
final class ResidentPassCancellation
{
    /** Returns how many were cancelled. */
    public function cancelFor(?int $residentId, ?Carbon $at = null): int
    {
        if ($residentId === null) {
            return 0;
        }

        $at ??= Carbon::now();
        $cancelled = 0;

        VisitorPass::query()
            ->where('category', VisitorPasses::RESIDENT)
            ->where('issued_by_resident_id', $residentId)
            ->where('status', VisitorPass::ACTIVE)
            ->get()
            ->each(static function (VisitorPass $pass) use ($at, &$cancelled): void {
                $pass->forceFill(['status' => VisitorPass::CANCELLED, 'cancelled_at' => $at])->save();
                $cancelled++;
            });

        return $cancelled;
    }
}
