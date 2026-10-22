<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Api\AccessLevels;
use App\Api\AccessRules;
use App\Api\AppMatrix;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Services\Passes\VisitorPasses;
use App\Services\Push\PushMessage;
use App\Services\Push\PushNotifier;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A HELPER — OR A DEPENDENT — CAME IN OR WENT OUT ON THEIR OWN E-PASS, AND THE HOUSEHOLD IS TOLD (the user, 8 Oct 2026).
 *
 * Who is told is the estate's rule (`AccessRules::entryRecipients`): the primary resident (a Helper's default), every
 * Member of the household, or nobody (a Dependent's default). Never the person themselves. One push per phone, with
 * their name, the gate and the time on the estate's clock — what the household's own lock screen already shows of a
 * visitor at the gate, and no more.
 *
 * IN, always: the gate records every admission on a pass. OUT, when the guard records the departure on the pass —
 * a gate does not always log who leaves, and nothing here pretends it does.
 *
 * NEVER IN THE WAY OF THE GATE: whatever goes wrong here is reported, and the entry stands.
 */
final class HouseholdEntries
{
    public function __construct(private readonly PushNotifier $push) {}

    public function came(VisitorPass $pass, Carbon $at, ?string $post, string $direction = 'in'): void
    {
        try {
            $this->tell($pass, $at, $post, $direction);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function tell(VisitorPass $pass, Carbon $at, ?string $post, string $direction): void
    {
        if ($pass->category !== VisitorPasses::RESIDENT || $pass->issued_by_resident_id === null) {
            return;
        }

        $person = Resident::withRemoved()->find((int) $pass->issued_by_resident_id);
        if ($person === null || $person->household_id === null) {
            return;
        }

        $level = AccessLevels::of($person);
        if ($level === AppMatrix::MEMBER) {
            return;
        }

        $who = AccessRules::entryRecipients($level);
        if ($who === 'nobody') {
            return;
        }

        $recipients = Resident::query()->where('household_id', $person->household_id)->whereKeyNot($person->id)->get()
            ->filter(static fn (Resident $r): bool => $who === 'primary' ? (bool) $r->is_primary : AccessLevels::of($r) === AppMatrix::MEMBER)
            ->pluck('id')->all();

        if ($recipients === []) {
            return;
        }

        $tenant = (string) (tenant()?->getTenantKey() ?? '');
        $accounts = ResidentAccount::query()->where('tenant_id', $tenant)->whereIn('resident_id', $recipients)->where('status', ResidentAccount::ACTIVE)->pluck('id')->all();

        $time = SiteTime::local($at, $tenant)->format('g:i A');
        $where = $post !== null && $post !== '' ? $post.' · '.$time : $time;
        $title = (string) $person->full_name.($direction === 'out' ? ' went out' : ' came in');

        foreach ($accounts as $accountId) {
            $this->push->toAccount($tenant, (int) $accountId, new PushMessage(
                type: 'household_entry',
                title: $title,
                body: $where,
                id: (int) $person->id,
                kind: $direction,
            ));
        }
    }
}
