<?php

declare(strict_types=1);

namespace App\Live\Events;

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveEvent;
use App\Live\Stream;
use Illuminate\Support\Facades\DB;

/**
 * AN OFFICER ON DUTY HAS A NEWER POSITION (M11 §3.4; D-174). Central stream: positions are the security company's.
 *
 *   GD    the Gemini dispatch feed (the live map): the fan-in and the estate's own copy — Channels::gemini, which
 *         leaves the demonstration estate out
 *   ES    the estate console's Security feed, ONLY when the estate's contract lets its console see guard pins
 *         (`subscriptions.estate_sees_guard_positions`, default off; QUESTIONS TRACK-01). Otherwise the estate
 *         hears nothing of it: its page shows "guards on site: N", which a position does not change
 *
 * A REFERENCE. It says which officer moved and nothing about where: each map asks for its pins again through its
 * own read, which applies that viewer's scope and the contract setting. So no position rides a channel, and the
 * live log keeps none (§4.1). One event per batch the phone sends, not one per fix.
 */
final class GuardPositionLive extends LiveEvent
{
    public const TYPE = 'guard.moved';

    private function __construct(
        private readonly int $guardId,
        private readonly string $tenantId,
        private readonly bool $isSimulated,
    ) {}

    public static function moved(int $guardId, string $tenantId, bool $simulated = false): self
    {
        return new self($guardId, $tenantId, $simulated);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function entity(): string
    {
        return 'guard';
    }

    public function entityId(): string
    {
        return (string) $this->guardId;
    }

    public function stream(): Stream
    {
        return Stream::central($this->tenantId);
    }

    public function audiences(): array
    {
        $channels = Channels::gemini('dispatch', $this->tenantId);

        if (self::estateSees($this->tenantId)) {
            $channels[] = Channels::estateFeed($this->tenantId, 'security');
        }

        return [new Audience($channels)];
    }

    public function simulated(): bool
    {
        return $this->isSimulated;
    }

    /**
     * The contract setting: may this estate's console see its guards' pins? Off unless Gemini switched it on, and off
     * whatever it says while `tracking.estate_positions_held` holds (M57 §9.1: not on the estate console until Omar
     * has confirmed it).
     */
    public static function estateSees(string $tenantId): bool
    {
        if ((bool) config('tracking.estate_positions_held', true)) {
            return false;
        }

        return (bool) DB::connection('mysql')->table('subscriptions')->where('tenant_id', $tenantId)->value('estate_sees_guard_positions');
    }
}
