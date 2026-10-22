<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use DomainException;

/**
 * A step of an alert's response that may not be recorded, and the sentence that says why (M10 §1.3).
 *
 * ONE REFUSAL, TWO AUDIENCES. The same rule refuses a dispatcher clicking "Mark en route" on a
 * closed alert and a guard tapping "En route" on it, and the two must read the same fact — so the
 * rule lives in `AlertProgress` and the words travel with it. The console shows `getMessage()`
 * under the button; the API answers `{"error": {"code": $errorCode, "message": …}}` with
 * `$status`. Nothing downstream composes its own reason.
 *
 * `$errorCode` IS STABLE and is what the Guard App branches on; the sentence may be reworded.
 * Every code is listed on its endpoint in `App\Api\Catalogue`, and so in MOBILE_HANDOFF.md.
 */
final class AlertProgressRefused extends DomainException
{
    /** The response is over: the alert was resolved, called a false alarm or cancelled. */
    public const CLOSED = 'alert_closed';

    /** A guard's own alert: help is coming TO them, and they cannot be the one sent. */
    public const OWN_ALERT = 'own_alert';

    /** The guard is not among the officers this alert reaches at that estate. */
    public const NOT_ON_SHIFT = 'not_on_shift';

    /** Another officer was sent, or took it first; only their handset speaks for the response. */
    public const ANOTHER_OFFICER = 'another_officer_responding';

    /** En route and on scene are recorded FOR the officer sent — and nobody has been. */
    public const NOBODY_SENT = 'nobody_dispatched';

    public const ALREADY_DISPATCHED = 'already_dispatched';

    /** An officer is already on the way or there, so dispatching now would be a step backwards. */
    public const ALREADY_RESPONDING = 'already_responding';

    public const ALREADY_EN_ROUTE = 'already_en_route';

    public const ALREADY_ON_SCENE = 'already_on_scene';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
