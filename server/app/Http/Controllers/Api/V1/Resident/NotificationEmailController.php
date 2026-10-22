<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\ResidentAccount;
use App\Services\Audit\AuditLogger;
use App\Services\Push\PushTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Email" under By channel on board 20 (M50 §4.2 #4): whether the news a push carries reaches this ACCOUNT by email too.
 *
 *   GET /me/notifications/email   where it stands — the server is the truth, unlike the per-phone switches
 *   PUT /me/notifications/email   turned on or off, with the categories switched off (so an email never says what a
 *                                 push would not)
 *
 * The address is the one the account signs in with, proven by its one-time code; this never takes another. Who is
 * emailed, and when, is `ResidentEmails`.
 */
class NotificationEmailController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(DeviceContext $context): JsonResponse
    {
        return response()->json(self::state($context->residentOrFail()));
    }

    public function update(Request $request, DeviceContext $context): JsonResponse
    {
        $data = $request->validate([
            'on' => ['required', 'boolean'],
            'muted' => ['sometimes', 'nullable', 'array', 'max:32'],
            'muted.*' => ['string', 'max:40'],
        ]);

        $account = $context->residentOrFail();
        $was = (bool) $account->notify_email;
        $on = (bool) $data['on'];

        $account->forceFill([
            'notify_email' => $on,
            // A name that is not one of the Resident App's switchable categories is dropped, as `push.register` drops it.
            'notify_email_muted' => array_values(array_intersect(PushTypes::mutable(AppMatrix::RESIDENT), $data['muted'] ?? $account->notify_email_muted ?? [])),
        ])->save();

        if ($was !== $on) {
            rescue(fn () => $this->audit->record(
                action: $on ? 'resident_app.email_notifications_on' : 'resident_app.email_notifications_off',
                entityType: 'ResidentAccount',
                entityId: (string) $account->id,
                before: ['notify_email' => $was],
                after: ['notify_email' => $on],
                tenantId: (string) $account->tenant_id,
            ), report: false);
        }

        return response()->json(self::state($account));
    }

    /** @return array{on: bool, address: string|null, muted: list<string>} */
    private static function state(ResidentAccount $account): array
    {
        return [
            'on' => (bool) $account->notify_email,
            'address' => $account->channel === 'email' ? (string) $account->destination : null,
            'muted' => array_values($account->notify_email_muted ?? []),
        ];
    }
}
