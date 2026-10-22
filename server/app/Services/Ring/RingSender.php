<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Models\DevicePushToken;
use App\Models\DuressAlertRingEvent as Event;
use Illuminate\Support\Collection;

/**
 * WHICH MESSAGE EACH PHONE IS SENT THIS ROUND (the guard ring §6.1).
 *
 *   an Android phone whose Guard App has the ring module (`ring_version >= 1`)
 *        the call-style ring, always; PLUS the ordinary visible alert, tagged, when the
 *        phone cannot yet be trusted to ring on its own:
 *          - its readiness is `not_ready`;
 *          - it never said it showed the ring it was sent last time; or
 *          - this is its first ring for this alert and it has had no visible push for it
 *            yet — an off-duty officer first rung at the escalation had no `site_alert`
 *            at the raise, and a native path that failed on that phone would otherwise
 *            leave them with nothing until the next round (G9)
 *   any other phone — an older Guard App, an iPhone
 *        the ordinary visible alert, tagged, every round after the raise. At the raise it
 *        already had today's `site_alert`, untouched.
 *
 * A phone that gets both shows ONE tray entry: its ring module drops a visible push for an
 * alert it is already ringing, and cancels the visible one when it starts to ring (D19).
 */
final class RingSender
{
    /**
     * @param  Collection<int, DevicePushToken>  $tokens  entitled, live, this estate's
     * @param  list<int>  $raisedTo  the guards the raise's own push went to
     * @return array{ring: list<int>, visible: list<int>}
     */
    public static function plan(Collection $tokens, int $alertId, int $round, array $raisedTo): array
    {
        $ids = $tokens->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $history = $ids === [] ? collect() : Event::query()
            ->where('duress_alert_id', $alertId)
            ->whereIn('push_token_id', $ids)
            ->whereIn('event', [Event::SENT_RING, Event::SENT_VISIBLE, Event::SHOWN])
            ->where('round', '<', $round)
            ->get(['push_token_id', 'round', 'event'])
            ->groupBy('push_token_id');

        $ring = [];
        $visible = [];

        foreach ($tokens as $token) {
            $id = (int) $token->id;

            if (! self::capable($token)) {
                if ($round >= 1) {
                    $visible[] = $id;
                }

                continue;
            }

            $ring[] = $id;

            if ($round === 0) {
                continue;
            }

            /** @var Collection<int, Event> $mine */
            $mine = $history->get($id, collect());

            $lastRing = $mine->where('event', Event::SENT_RING)->max('round');
            $shownLast = $lastRing !== null && $mine->where('event', Event::SHOWN)->where('round', $lastRing)->isNotEmpty();
            $hadVisible = in_array((int) $token->principal_id, $raisedTo, true) || $mine->where('event', Event::SENT_VISIBLE)->isNotEmpty();

            $unsure = ($token->ring_state ?? RingReadiness::NOT_READY) === RingReadiness::NOT_READY
                || ($lastRing !== null && ! $shownLast)
                || ($lastRing === null && ! $hadVisible);

            if ($unsure) {
                $visible[] = $id;
            }
        }

        return ['ring' => $ring, 'visible' => $visible];
    }

    /** Whether this phone's Guard App has the ring module: Android, and a ring contract reported. */
    public static function capable(DevicePushToken $token): bool
    {
        return $token->platform === 'android' && (int) ($token->ring_version ?? 0) >= 1;
    }
}
