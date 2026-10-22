<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Guard;
use App\Models\ResidentAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Who is speaking on this request, resolved from the token and nothing else (13 D1).
 *
 * THE TOKEN DECIDES THE GUARD, THE RESIDENT AND THE ESTATE. Before this, three
 * endpoints took `tenant_id` and `guard_id` from the request body, so a guard's
 * handset could raise an alert in another estate's name or clock on a shift
 * that was not theirs. An endpoint now reads these from here; a body field that
 * names a different estate or guard is refused, not obeyed.
 */
final class DeviceContext
{
    public function __construct(
        public readonly string $app,
        public readonly string $tenantId,
        public readonly ?Guard $guard,
        public readonly ?ResidentAccount $resident,
        public readonly Carbon $serverTime,
        public readonly ?Carbon $deviceTime = null,
    ) {}

    public function withDeviceTime(?Carbon $deviceTime): self
    {
        return new self($this->app, $this->tenantId, $this->guard, $this->resident, $this->serverTime, $deviceTime);
    }

    /** Whether this request's device time says the handset's clock is wrong. See `skewed()`. */
    public function clockSkewed(): bool
    {
        return self::skewed($this->deviceTime, $this->serverTime);
    }

    /**
     * Whether a device time says the handset's CLOCK is wrong — the one rule every write
     * answers `clock_skewed` by (bug hunt B066).
     *
     * AHEAD, NOT BEHIND. X-Device-Time is, by contract, when the act HAPPENED. For anything
     * the phone queued with no signal that is minutes or hours before it reaches the server,
     * so "the two differ by more than two minutes" called every late sync a wrong clock: it
     * was stored as clock-skewed evidence, and the Guard App told a guard whose phone was
     * right that it was wrong. A device time in the past cannot be told apart from a queued
     * act by anything the phones send today, so it is no longer called a wrong clock. A
     * device time AHEAD of the server's by more than the tolerance is: no queue can put an
     * act in the future. Both times are still kept on every record, exactly as sent.
     */
    public static function skewed(?CarbonInterface $deviceTime, CarbonInterface $serverTime): bool
    {
        return $deviceTime !== null
            && $deviceTime->getTimestamp() - $serverTime->getTimestamp() > Catalogue::CLOCK_SKEW_SECONDS;
    }

    public function guardOrFail(): Guard
    {
        if ($this->guard === null) {
            throw ApiError::forbidden('guard_app_only', 'This is a Guard App endpoint.');
        }

        return $this->guard;
    }

    public function residentOrFail(): ResidentAccount
    {
        if ($this->resident === null) {
            throw ApiError::forbidden('resident_app_only', 'This is a Resident App endpoint.');
        }

        return $this->resident;
    }
}
