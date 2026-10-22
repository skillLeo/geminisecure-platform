<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\DispatchMessage;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Dispatch writing to one officer's handset, from the console.
 *
 * WHAT WAS MISSING, AND WHAT WAS NOT. The Guard App has served this since it was
 * built: `MessagesController::index()` returns every broadcast plus anything
 * carrying that guard's id, counts it unread, and the handset caches it for
 * offline. `dispatch_messages.direction` has had `outbound` in the column since
 * the same migration. The only absent piece was a way for a dispatcher to WRITE
 * one — so the panic screen drew a disabled box reading "Messaging opens when
 * the Guard App ships", and beside it a disabled "Dispatch a second guard"
 * saying the same. Both sentences were false by then: the app had shipped, and
 * the message model they said did not exist was already in the schema.
 *
 * SO THIS IS THE WRITE, AND ONLY THE WRITE. It does not invent a thread, a
 * delivery receipt or a read state — the handset already owns all three.
 *
 * ONE NAMED GUARD, NEVER A BROADCAST. A broadcast is a different act with a
 * different audience and a different screen; conflating them here would let a
 * message meant for the officer at the gate reach every officer on the estate,
 * which during an incident is how two people both stand down believing the other
 * went.
 *
 * AUDITED, because after an incident the question is not only what happened but
 * who was told to do what, and when. The body goes in the audit entry: unlike a
 * ballot, a dispatch instruction is evidence and is meant to be readable
 * afterwards.
 */
class DispatchMessaging
{
    /** The column is 500; the Guard App's own write validates to the same. */
    public const MAX_BODY = 500;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Send one instruction to one officer.
     *
     * @param  DuressAlert|null  $about  the incident it concerns, for the audit trail
     */
    public function toGuard(Guard $guard, string $body, User $by, ?DuressAlert $about = null): DispatchMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw new DomainException('A message needs something in it.');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            throw new DomainException('A message is at most '.self::MAX_BODY.' characters. This one is '.mb_strlen($body).'.');
        }

        if ($guard->tenant_id === null) {
            throw new DomainException(
                $guard->full_name.' is not posted to a client, so there is no estate thread to send this on. '
                .'Post them to a client first.'
            );
        }

        /*
         * THE ESTATE IS THE GUARD'S, NOT THE ALERT'S, and they must agree.
         *
         * The handset reads its thread by tenant AND guard id, so a message
         * written against the wrong estate would be addressed to a real officer
         * and delivered to nobody — silently, during an incident. Refusing is
         * the only safe answer; the two can only differ if a caller passed an
         * officer from another client, which is a bug rather than a choice.
         */
        if ($about !== null && (string) $about->tenant_id !== (string) $guard->tenant_id) {
            throw new DomainException(
                $guard->full_name.' is posted to a different client from the one this alert was raised at.'
            );
        }

        $message = DispatchMessage::create([
            'tenant_id' => (string) $guard->tenant_id,
            'direction' => DispatchMessage::OUTBOUND,
            'guard_id' => $guard->id,
            'sent_by' => $by->getKey(),
            'body' => $body,
            'sent_at' => Carbon::now(),
        ]);

        $this->audit->record(
            action: 'dispatch.message_sent',
            entityType: 'Guard',
            entityId: (string) $guard->id,
            after: [
                'message_id' => $message->id,
                'to' => (string) $guard->full_name,
                'alert_id' => $about?->id,

                // The instruction itself. Evidence, and meant to be read back.
                'body' => $body,
                'by' => (string) $by->name,
            ],
            tenantId: (string) $guard->tenant_id,
        );

        return $message;
    }
}
