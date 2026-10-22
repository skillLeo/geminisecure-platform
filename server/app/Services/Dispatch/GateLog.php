<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Api\DeviceContext;
use App\Live\Events\GateLive;
use App\Live\LiveBus;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every arrival and departure a guard records at a gate.
 *
 * `record()` is the single entry point for /api/v1 and for the simulator alike,
 * exactly as `AlertIntake::record()` is — so a simulated admission travels the
 * path a real one will, and nothing built against the simulator has to be
 * rewritten when the Guard App arrives.
 *
 * NOT ONE FIELD ON A GATE EVENT IS A MONETARY AMOUNT, and that is invariant 2
 * rather than an oversight. A guard decides who comes in; what a household owes
 * is not their business and must not reach a handset. The table has no column
 * that could carry a figure and this class assembles none — the decision is
 * recorded as a verdict and a BASIS, which is what the guard acted on.
 *
 * THE BASIS IS THE POINT OF THE ROW. "QR pass", "pre-approved", "tag read" and
 * "guard decision" are not decoration: `AdoptionRollup` counts the first three
 * against the fourth to say how much of the pass system a client is actually
 * using, and an account manager reads that before a renewal. A guard admitting
 * on their own judgement is a gate the client bought a pass system for and is
 * not getting.
 *
 * AN OVERRIDE IS A VERDICT, NOT A FLAG. Board guard-app-07 draws "Override
 * admit" as its own act, and it is recorded as its own verdict for the same
 * reason a reversal is its own journal entry: somebody let a person in against
 * the system's advice, and that is a different fact from an ordinary admission.
 * It carries a reason, and the endpoint refuses one without.
 */
class GateLog
{
    /** Bases that mean the platform issued or pre-approved the arrival. */
    public const PASS_BASES = ['qr pass', 'pre-approved', 'tag read'];

    /** And the one that means the guard decided at the gate. */
    public const GUARD_DECISION = 'guard decision';

    /**
     * Device and server clocks are considered to disagree beyond this.
     *
     * The same tolerance an alert uses, and deliberately the same constant's
     * value: two devices at the same gate should not disagree about what counts
     * as a skewed clock.
     */
    public const CLOCK_SKEW_TOLERANCE_SECONDS = AlertIntake::CLOCK_SKEW_TOLERANCE_SECONDS;

    public function record(
        string $tenantId,
        string $verdict,
        string $category,
        string $subject,
        string $basis,
        ?int $guardId = null,
        ?int $postId = null,
        ?CarbonInterface $deviceTime = null,
        ?string $idempotencyKey = null,
        bool $isSimulated = false,
    ): GateEvent {
        /*
         * Idempotency first, for the reason an alert has it: a handset at a
         * gate loses signal constantly, and a retry that duplicated an
         * admission would inflate the very count `AdoptionRollup` reports to an
         * account manager. The key is client-generated, so a retry is safe by
         * construction rather than by the server guessing which rows look alike.
         */
        if ($idempotencyKey !== null) {
            $existing = GateEvent::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        /*
         * The guard's and the post's NAMES are copied onto the row, not joined.
         *
         * A gate log is a record of what happened at a moment, and it has to
         * still read correctly after a guard leaves the company or a post is
         * renamed. Board 26 prints "Marcus Reid · Main Gate" against an event
         * from three months ago; resolving that through a join would silently
         * rewrite history every time the workforce changed.
         */
        $guard = $guardId === null ? null : Guard::find($guardId);
        $post = $postId === null ? null : Post::find($postId);

        $occurredAt = Carbon::now();

        /*
         * WITH ITS LIVE EVENT, IN ONE TRANSACTION (M11 Part C, DESIGN.md §2.9 I10–I13): the estate's Security page,
         * the Gemini console's Gate activity and every guard's gate list hear of the entry the moment it is on the
         * log, and a write that fails tells nobody. Never on the idempotent return above: a retry adds nothing.
         */
        return DB::connection((new GateEvent)->getConnectionName())->transaction(function () use ($tenantId, $guard, $post, $verdict, $category, $subject, $basis, $occurredAt, $deviceTime, $idempotencyKey, $isSimulated): GateEvent {
            $event = $this->write($tenantId, $guard, $post, $verdict, $category, $subject, $basis, $occurredAt, $deviceTime, $idempotencyKey, $isSimulated);

            LiveBus::record(GateLive::for($event));

            return $event;
        });
    }

    /** The visitor log's row itself (see record()). */
    private function write(string $tenantId, ?Guard $guard, ?Post $post, string $verdict, string $category, string $subject, string $basis, Carbon $occurredAt, ?CarbonInterface $deviceTime, ?string $idempotencyKey, bool $isSimulated): GateEvent
    {
        return GateEvent::create([
            'tenant_id' => $tenantId,
            'guard_id' => $guard?->id,
            'guard_name' => $guard?->full_name,
            'post_id' => $post?->id,
            // The post as the estate names it, "Back Gate (Gate 5, Phase 5 Gate)": a snapshot, like the guard's name (M26).
            'post_name' => $post?->label(),
            'verdict' => $verdict,
            'category' => $category,
            'subject' => $subject,
            'basis' => $basis,
            'occurred_at' => $occurredAt,

            /*
             * Kept as the device reported it and never rewritten to match the
             * server. A disagreement is evidence about a handset, and silently
             * correcting it destroys the only record that it happened — the
             * same rule `AlertIntake` follows.
             */
            'device_time' => $deviceTime,
            'idempotency_key' => $idempotencyKey,
            'is_simulated' => $isSimulated,
        ]);
    }

    /** Whether this basis means the platform issued the arrival. */
    public function isPassBased(string $basis): bool
    {
        $basis = mb_strtolower($basis);

        foreach (self::PASS_BASES as $pass) {
            if (str_contains($basis, $pass)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a device time says the handset's clock is wrong — the API's one rule (bug hunt B066): ahead, not behind. */
    public function clockSkewed(?CarbonInterface $deviceTime, ?CarbonInterface $serverTime = null): bool
    {
        return DeviceContext::skewed($deviceTime, $serverTime ?? Carbon::now());
    }
}
