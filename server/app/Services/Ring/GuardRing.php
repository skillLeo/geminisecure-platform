<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Jobs\Alerts\SendRingEscalationSms;
use App\Jobs\Ring\RingRound;
use App\Jobs\Ring\SendRingPush;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery as Leg;
use App\Models\DuressAlertRing;
use App\Models\DuressAlertRingEvent as Event;
use App\Models\DuressAlertRingRound as Round;
use App\Models\DuressAlertRingTarget as Target;
use App\Models\User;
use App\Notifications\PanicStillUnanswered;
use App\Services\Audit\AuditLogger;
use App\Services\Dispatch\SiteEscalation;
use App\Services\Platform\OperatorEstates;
use App\Services\Platform\SmsSettings;
use App\Services\Push\ExpoPush;
use App\Services\Push\PanicDeliveries;
use App\Services\Push\PushNotifier;
use App\Support\CentralQueue;
use App\Support\SiteTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * A PANIC RINGS EVERY GUARD'S PHONE LIKE A CALL, UNTIL SOMEBODY ANSWERS (the guard ring).
 *
 *   R0  0 s          the raise. Today's visible `site_alert` has ALREADY gone, byte for byte
 *                    and on its own job (`PanicFanOut`); this adds the call-style ring to the
 *                    phones that can take one
 *   R1  60 s         the escalation: every active officer of the estate, off duty too, and the
 *                    estate's own Head of Security by text and mail. At once, not at 60 s,
 *                    when the raise reached nobody — no officer on the list, or no phone of
 *                    theirs pushed — and pulled forward when every officer rung at the raise
 *                    said "can't respond"
 *   R2+ every 120 s  everybody rung so far, again, until the alert is answered (`RingRule`)
 *
 * NOTHING HERE MAY MAKE THE FIRST ALERT SLOWER, QUIETER OR LESS RELIABLE.
 * ======================================================================
 *   - `begin()` runs inside the `/api/v1/duress` request, AFTER every existing leg has been
 *     handed off — the push, the mail, all three texts — and it makes no network call and
 *     never sleeps: one header row, one insert of the rounds, one of the targets, two job
 *     rows. It is rescued by its caller.
 *   - Today's push is not changed in any way: no tag, no new field, the same job class, the
 *     same queue, the same order. `SendExpoPush` is not edited (G2).
 *   - `alerted_handsets` and `alerted_via` are never touched by a ring. They stay the truth
 *     at the raise, which the resident's own screen is built on (ruling R5).
 *
 * EVERY ROUND IS CLAIMED BEFORE IT SENDS, by one statement, so the queued job and the
 * safety-net sweep (`ring:sweep`) can never both send it; and every send is idempotent per
 * phone, so a round taken over from a dead worker rings nobody twice.
 *
 * EVERY QUERY NAMES THE CENTRAL DATABASE (G5), through the four ring models, `DuressAlert`
 * and `DevicePushToken`. A round queued from inside the POST runs with that estate open,
 * and the default connection there is the estate's, which has none of these tables.
 *
 * EVERY TIME IS PHP'S (G8). Nothing here asks MySQL for the time: thresholds are bound
 * Carbon values, so the fake clock the tests travel is the clock the ring keeps.
 */
final class GuardRing
{
    public function __construct(
        private readonly PushNotifier $push,
        private readonly RingAudience $audience,
        private readonly PanicDeliveries $deliveries,
        private readonly AuditLogger $audit,
        private readonly SiteEscalation $escalation,
    ) {}

    /* ================================================================ the raise */

    /**
     * Start the ring for a panic whose ordinary push has just gone. No network, no sleep.
     *
     * @param  list<int>  $officers  the guards today's push went to: on duty and rostered today
     * @param  int  $handsets  how many of their phones it was queued to
     */
    public function begin(DuressAlert $alert, array $officers, int $handsets): void
    {
        if (! (bool) config('ring.enabled', true) || $alert->is_simulated) {
            return;
        }

        $tenantId = (string) $alert->tenant_id;
        $now = Carbon::now();
        $raised = Carbon::instance($alert->server_time);

        $configured = max(0, (int) config('ring.max_round', 5));
        $demonstration = OperatorEstates::isDemonstration($tenantId);

        $maxRound = match (true) {
            $demonstration => min($configured, max(0, (int) config('ring.demonstration_max_round', 0))),
            $alert->is_drill => min($configured, max(0, (int) config('ring.drill_max_round', 1))),
            default => $configured,
        };

        $nobody = $officers === [];
        $noHandset = ! $nobody && $handsets === 0;

        $escalateAfter = max(0, (int) config('ring.escalate_after', 60));
        $interval = max(1, (int) config('ring.interval', 120));
        $escalationDue = $nobody || $noHandset ? $now->copy() : $raised->copy()->addSeconds($escalateAfter);

        $begun = DuressAlertRing::query()->insertOrIgnore([
            'duress_alert_id' => (int) $alert->id,
            'tenant_id' => $tenantId,
            'is_drill' => (bool) $alert->is_drill,
            'max_round' => $maxRound,
            'officers_at_raise' => count($officers),
            'handsets_at_raise' => max(0, $handsets),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // An alert that has already begun ringing does not begin again.
        if ($begun !== 1) {
            return;
        }

        $rows = [];

        for ($round = 0; $round <= $configured; $round++) {
            $due = match ($round) {
                0 => $now->copy(),
                1 => $escalationDue->copy(),
                default => $raised->copy()->addSeconds($escalateAfter + ($round - 1) * $interval),
            };

            $row = [
                'duress_alert_id' => (int) $alert->id,
                'tenant_id' => $tenantId,
                'round' => $round,
                'reason' => match ($round) {
                    0 => Round::INITIAL,
                    1 => $nobody ? Round::NOBODY_ON_DUTY : ($noHandset ? Round::NO_HANDSET : Round::UNANSWERED),
                    default => Round::RE_RING,
                },
                'due_at' => $due,
                'claimed_at' => null,
                'claimed_by' => null,
                'finished_at' => null,
                'outcome' => null,
                'note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            /*
             * SETTLED NOW, AND SAID SO. A round past the cap is written as not sent, with the
             * reason, so the console never waits on it; and a raise that reached no phone has
             * no call-style ring to add — the escalation goes at once instead.
             */
            if ($round > $maxRound) {
                $row = [...$row, 'claimed_at' => $now, 'finished_at' => $now,
                    'outcome' => $demonstration ? Round::SKIPPED_DEMO : Round::SKIPPED_DRILL,
                    'note' => $demonstration
                        ? 'Not sent: the demonstration estate rings once, at the raise, and never escalates.'
                        : 'Not sent: a drill rings at the raise and at the escalation, and is never re-rung.'];
            } elseif ($round === 0 && $handsets === 0) {
                $row = [...$row, 'claimed_at' => $now, 'finished_at' => $now, 'outcome' => Round::NONE, 'officers' => count($officers), 'phones' => 0,
                    'note' => $nobody
                        ? 'Nobody was rung at the raise: no officer is clocked on or rostered here today.'.($maxRound >= 1 ? ' The escalation rang at once.' : '')
                        : 'Nobody was rung at the raise: '.self::plural(count($officers), 'officer').' on shift or rostered today, but no phone of theirs could be pushed.'.($maxRound >= 1 ? ' The escalation rang at once.' : '')];
            }

            $rows[] = $row;
        }

        // One statement: every row in the batch needs the same columns.
        $columns = ['officers' => null, 'phones' => null];
        Round::query()->insert(array_map(static fn (array $row): array => [...$columns, ...$row], $rows));

        RingTargets::add($alert, $officers, Target::ON_SHIFT, 0);

        if ($handsets > 0) {
            $this->queue(new RingRound((int) $alert->id, 0));
        }

        if ($maxRound >= 1) {
            $this->queue((new RingRound((int) $alert->id, 1))->delay($escalationDue));
        }
    }

    /* ================================================================ a round */

    /**
     * Send one round of the ring — from its queued job or from the sweep.
     *
     * Claimed first; re-checked; the audience worked out and the phones rung; then recorded,
     * and the next round booked.
     */
    public function runRound(int $alertId, int $round, string $by): void
    {
        $now = Carbon::now();
        $header = DuressAlertRing::query()->where('duress_alert_id', $alertId)->first();

        if ($header === null) {
            return;
        }

        $target = $this->collapse($alertId, $round, $by, $now);

        if (! $this->claim($alertId, $target, $by, $now)) {
            return;
        }

        /** @var Round $row */
        $row = Round::query()->where('duress_alert_id', $alertId)->where('round', $target)->firstOrFail();

        try {
            $this->ring($header, $row, $by, $now);
        } catch (Throwable $e) {
            /*
             * LEFT CLAIMED AND UNFINISHED on purpose. The sweep takes a claim over once it is
             * `ring.stale_claim` seconds old, and every send inside it is idempotent per phone.
             */
            rescue(static fn () => report($e), report: false);
        }
    }

    /** The round, once it is this caller's. */
    private function ring(DuressAlertRing $header, Round $row, string $by, Carbon $claimedAt): void
    {
        $round = (int) $row->round;
        $alert = DuressAlert::query()->with(['estate', 'respondingGuard'])->find((int) $header->duress_alert_id);

        if ($alert === null) {
            $this->finish($row, Round::NONE, 'Not sent: the alert no longer exists.');

            return;
        }

        if (! (bool) config('ring.enabled', true)) {
            $this->finish($row, Round::SKIPPED_OFF, 'Not sent: call-style ringing was switched off on this server (RING_ENABLED).');

            return;
        }

        if (RingRule::stops($alert, $round)) {
            $this->finish($row, Round::SKIPPED_ANSWERED, 'Not sent: '.self::answeredSentence($alert).'.');
            $this->stop($alert);

            return;
        }

        if ($round > (int) $header->max_round) {
            $this->finish($row, $header->is_drill ? Round::SKIPPED_DRILL : Round::SKIPPED_DEMO, 'Not sent: past the last round this alert may ring.');

            return;
        }

        $tenantId = (string) $alert->tenant_id;

        ['guards' => $guards, 'new' => $new] = $this->audience->for($alert, $round);

        foreach ([Target::ON_SHIFT, Target::ESTATE] as $audience) {
            RingTargets::add($alert, array_keys(array_filter($new, static fn (string $a): bool => $a === $audience)), $audience, $round);
        }

        $tokens = $this->push->entitledTokens($tenantId, DevicePushToken::GUARD, $guards);

        $raisedTo = Target::query()->where('duress_alert_id', (int) $alert->id)->where('first_round', 0)
            ->pluck('guard_id')->map(static fn ($id): int => (int) $id)->all();

        $plan = RingSender::plan($tokens, (int) $alert->id, $round, $raisedTo);

        /** @var list<SendRingPush> $batches */
        $batches = [];

        foreach ([SendRingPush::RING => $plan['ring'], SendRingPush::VISIBLE => $plan['visible']] as $kind => $ids) {
            foreach (array_chunk($ids, self::batchSize()) as $chunk) {
                $batches[] = new SendRingPush($chunk, (int) $alert->id, $round, $kind);
            }
        }

        $inline = max(0, (int) config('ring.inline_batches', 3));
        $sent = 0;
        $failed = 0;
        $queued = 0;
        $cutShort = false;

        foreach ($batches as $i => $batch) {
            // BEFORE EVERY BATCH BUT THE FIRST, THE ALERT IS READ AGAIN (G6).
            if ($i > 0 && $this->stopsNow((int) $alert->id, $round)) {
                $cutShort = true;

                break;
            }

            if ($i >= $inline) {
                $this->queue($batch);
                $queued++;

                continue;
            }

            try {
                $batch->handle(app(ExpoPush::class), $this->push);
                $sent++;
            } catch (Throwable $e) {
                // Expo slow or broken: the batch goes to the queue, which retries it.
                rescue(static fn () => report($e), report: false);
                $this->queue($batch);
                $failed++;
            }
        }

        $counts = $this->counts((int) $alert->id, $round);

        /*
         * THE ESCALATION TELLS THE ESTATE'S HEAD OF SECURITY — on round 1, or on the round a
         * held-up round 1 was folded into (overdue rounds collapse), so a dead worker can never
         * cost them the follow-up.
         */
        $rounds = Round::query()->where('duress_alert_id', (int) $alert->id)->get(['round', 'reason', 'outcome', 'heads_texted']);
        $first = $rounds->firstWhere('round', 1);
        $folded = $round > 1 && $first?->outcome === Round::SKIPPED_LATE && $rounds->whereNotNull('heads_texted')->isEmpty();
        $heads = null;

        if (($round === 1 || $folded) && ! $cutShort) {
            $heads = $this->escalate($alert, (string) ($first->reason ?? $row->reason), $counts['phones']);
        }

        // AFTER THE LAST BATCH, ONCE MORE (G6, step 7a): an answer that landed mid-round.
        $alert->refresh();

        if (RingRule::stops($alert, $round)) {
            $this->stop($alert);
        }

        $pushOff = ! (bool) config('push.enabled', true);

        $outcome = match (true) {
            $pushOff, $batches === [] => Round::NONE,
            $cutShort => Round::PARTLY_SENT,
            $failed > 0 && $sent === 0 => Round::FAILED,
            $failed > 0 => Round::PARTLY_FAILED,
            default => Round::SENT,
        };

        $late = $by === Round::BY_SWEEP ? max(0, $claimedAt->getTimestamp() - $row->due_at->getTimestamp()) : null;

        $note = $this->note($alert, $row, count($guards), $tokens->count(), $counts, $pushOff, $queued + $failed, $cutShort, $late, $folded);

        $this->finish($row, $outcome, $note, [
            'officers' => count($guards),
            'phones' => $counts['phones'],
            'phones_ring' => $counts['ring'],
            'phones_visible' => $counts['visible'],
            'phones_ready' => $counts['ready'],
            'phones_partial' => $counts['partial'],
            'heads_texted' => $heads,
        ]);

        $this->deliveries->round(
            $alert,
            $round,
            Leg::GUARDS_ON_SHIFT,
            Leg::PUSH,
            $counts['phones'],
            match (true) {
                $pushOff => Leg::SKIPPED,
                $counts['phones'] > 0 => Leg::SENT,
                $queued + $failed > 0 => Leg::QUEUED,
                $outcome === Round::FAILED => Leg::FAILED,
                default => Leg::NONE,
            },
            $note,
        );

        rescue(fn () => $this->audit->record(
            action: 'alert.ring_round',
            entityType: 'DuressAlert',
            entityId: (string) $alert->id,
            after: ['round' => $round, 'reason' => (string) $row->reason, 'outcome' => $outcome, 'officers' => count($guards), 'phones' => $counts['phones'], 'by' => $by, 'late_seconds' => $late],
            tenantId: $tenantId,
        ), report: false);

        if ($late !== null) {
            rescue(static fn () => report(new RuntimeException('ring round '.$round.' for alert '.$alert->id.' sent by the safety net '.$late.' s late: is gs-queue-panic running?')), report: false);
        }

        $this->bookNext($header, $round);
    }

    /* ================================================================ the stop */

    /**
     * EVERY RUNG PHONE IS TOLD TO STOP — idempotent, and safe to call from anywhere: the
     * answer's own hook, a round that finds the alert answered, a batch that does, the sweep.
     *
     * `$inline` sends the stops in this process (the sweep, when the workers may be dead);
     * otherwise they are queued on the panic queue, so the request that answered the alert —
     * a dispatcher's click, a guard's tap — never waits on Expo.
     */
    public function stop(DuressAlert $alert, bool $inline = false): int
    {
        $why = RingRule::answered($alert);

        if ($why === null) {
            return 0;
        }

        $header = DuressAlertRing::query()->where('duress_alert_id', (int) $alert->id)->first();

        if ($header === null) {
            return 0;
        }

        $now = Carbon::now();

        // Only the first answer is the answer: two racing answers record one why.
        $first = DuressAlertRing::query()->whereKey($header->id)->whereNull('answered_at')
            ->update(['answered_at' => $now, 'answered_why' => $why, 'updated_at' => $now]) === 1;

        foreach (Round::query()->where('duress_alert_id', (int) $alert->id)->whereNull('claimed_at')->whereNull('finished_at')->get() as $pending) {
            if (RingRule::stops($alert, (int) $pending->round)) {
                Round::query()->whereKey($pending->id)->whereNull('claimed_at')->update([
                    'claimed_at' => $now, 'finished_at' => $now, 'outcome' => Round::SKIPPED_ANSWERED,
                    'note' => mb_substr('Not sent: '.self::answeredSentence($alert).'.', 0, Round::NOTE_LENGTH),
                    'updated_at' => $now,
                ]);
            }
        }

        $phones = [];

        foreach ($this->unstopped($alert) as [$kind, $round, $ids]) {
            foreach (array_chunk($ids, self::batchSize()) as $chunk) {
                $job = new SendRingPush($chunk, (int) $alert->id, $round, $kind);

                if ($inline) {
                    try {
                        $job->handle(app(ExpoPush::class), $this->push);
                    } catch (Throwable $e) {
                        rescue(static fn () => report($e), report: false);
                        $this->queue($job);
                    }
                } else {
                    $this->queue($job);
                }

                foreach ($chunk as $id) {
                    $phones[$id] = true;
                }
            }
        }

        if ($first) {
            DuressAlertRing::query()->whereKey($header->id)->update(['stop_sent_at' => $now, 'stop_handsets' => count($phones), 'updated_at' => $now]);

            rescue(fn () => $this->audit->record(
                action: 'alert.ring_stopped',
                entityType: 'DuressAlert',
                entityId: (string) $alert->id,
                after: ['why' => $why, 'phones' => count($phones)],
                tenantId: (string) $alert->tenant_id,
            ), report: false);
        }

        return count($phones);
    }

    /**
     * The phones still owed a stop, grouped for sending: for each, the highest round it was
     * rung in, and no stop of that kind at that round or later (G6).
     *
     *   stop          every phone sent the call-style ring — its module cancels the ring,
     *                 the held notice and the visible re-ring of the same alert
     *   visible_stop  every phone sent a visible re-ring — an iPhone, an older app, and a
     *                 phone whose ring never said shown — replacing it by its tag
     *
     * @return list<array{0: string, 1: int, 2: list<int>}>
     */
    private function unstopped(DuressAlert $alert): array
    {
        $rows = Event::query()
            ->where('duress_alert_id', (int) $alert->id)
            ->whereIn('event', [Event::SENT_RING, Event::SENT_VISIBLE, Event::SENT_STOP, Event::SENT_VISIBLE_STOP])
            ->selectRaw('push_token_id, event, MAX(`round`) as top')
            ->groupBy('push_token_id', 'event')
            ->get();

        $top = [];

        foreach ($rows as $row) {
            $top[(int) $row->push_token_id][(string) $row->event] = (int) $row->getAttribute('top');
        }

        $groups = [];

        foreach ($top as $token => $events) {
            foreach ([[Event::SENT_RING, Event::SENT_STOP, SendRingPush::STOP], [Event::SENT_VISIBLE, Event::SENT_VISIBLE_STOP, SendRingPush::VISIBLE_STOP]] as [$rung, $stopped, $kind]) {
                if (! isset($events[$rung])) {
                    continue;
                }

                $round = $events[$rung];

                if (isset($events[$stopped]) && $events[$stopped] >= $round) {
                    continue;
                }

                if (! RingRule::stops($alert, $round)) {
                    continue;
                }

                $groups[$kind.':'.$round] ??= [$kind, $round, []];
                $groups[$kind.':'.$round][2][] = $token;
            }
        }

        return array_values($groups);
    }

    /* ================================================================ the safety net */

    /**
     * The sweep: rounds nobody sent, claims that died, stops nobody sent, answers no hook
     * caught — each sent INLINE, so the ring keeps going with the queue workers dead.
     *
     * @return array{rounds: int, stale: int, stops: int, missed: int}
     */
    public function sweep(): array
    {
        $report = ['rounds' => 0, 'stale' => 0, 'stops' => 0, 'missed' => 0];

        if (! (bool) config('ring.enabled', true)) {
            return $report;
        }

        $now = Carbon::now();
        $grace = max(0, (int) config('ring.sweep_grace', 10));
        $recent = DuressAlertRing::query()
            ->where('started_at', '>=', $now->copy()->subMinutes(max(1, (int) config('ring.sweep_window_minutes', 30))))
            ->get();

        if ($recent->isEmpty()) {
            return $report;
        }

        $ids = $recent->pluck('duress_alert_id')->map(static fn ($id): int => (int) $id)->all();

        // 1 · ROUNDS THAT FELL DUE AND NOBODY CLAIMED, the grace past due so a healthy job wins.
        $due = Round::query()->whereIn('duress_alert_id', $ids)->whereNull('claimed_at')
            ->where('due_at', '<=', $now->copy()->subSeconds($grace))
            ->orderBy('round')->get()->groupBy('duress_alert_id');

        foreach ($due as $alertId => $rounds) {
            $this->runRound((int) $alertId, (int) $rounds->first()->round, Round::BY_SWEEP);
            $report['rounds']++;
        }

        // 2 · CLAIMS THAT DIED: claimed, never finished, older than a round can take.
        $stale = Round::query()->whereIn('duress_alert_id', $ids)->whereNotNull('claimed_at')->whereNull('finished_at')
            ->where('claimed_at', '<', $now->copy()->subSeconds(max(1, (int) config('ring.stale_claim', 90))))
            ->orderBy('round')->get();

        foreach ($stale as $round) {
            $this->runRound((int) $round->duress_alert_id, (int) $round->round, Round::BY_SWEEP);
            $report['stale']++;

            rescue(static fn () => report(new RuntimeException('ring round '.$round->round.' for alert '.$round->duress_alert_id.' was claimed and never finished; the safety net took it over.')), report: false);
        }

        foreach ($recent as $header) {
            $alert = DuressAlert::query()->with(['estate', 'respondingGuard'])->find((int) $header->duress_alert_id);

            if ($alert === null) {
                continue;
            }

            // 3 · STOPS NOBODY SENT, per phone (G6) — only once the queued stops have had their chance.
            if ($header->answered_at !== null && $header->answered_at->lte($now->copy()->subSeconds($grace))) {
                $sent = $this->stop($alert, inline: true);

                if ($sent > 0) {
                    $report['stops'] += $sent;
                    rescue(static fn () => report(new RuntimeException('ring stop for alert '.$alert->id.' sent to '.$sent.' phone(s) by the safety net: is gs-queue-panic running?')), report: false);
                }

                continue;
            }

            // 4 · AN ANSWER NO HOOK CAUGHT.
            if ($header->answered_at === null && RingRule::answered($alert) !== null) {
                $report['missed']++;
                $this->stop($alert, inline: true);
            }
        }

        return $report;
    }

    /**
     * EVERY OFFICER RUNG AT THE RAISE SAID THEY CANNOT RESPOND: the escalation goes now,
     * not at 60 s. Only while it has not been claimed.
     */
    public function escalateNow(DuressAlert $alert): void
    {
        $now = Carbon::now();

        $moved = Round::query()->where('duress_alert_id', (int) $alert->id)->where('round', 1)
            ->whereNull('claimed_at')->whereNull('finished_at')
            ->update(['due_at' => $now, 'reason' => Round::DECLINED, 'updated_at' => $now]);

        if ($moved === 1) {
            $this->queue(new RingRound((int) $alert->id, 1));
        }
    }

    /* ================================================================ helpers */

    /**
     * OVERDUE ROUNDS COLLAPSE into the highest one due, so a ring is never fired back to
     * back: the lower ones are settled as late, and the caller takes the highest.
     */
    private function collapse(int $alertId, int $round, string $by, Carbon $now): int
    {
        $due = Round::query()->where('duress_alert_id', $alertId)->where('round', '>=', $round)
            ->whereNull('claimed_at')->where('due_at', '<=', $now->copy()->addSecond())
            ->orderBy('round')->get();

        if ($due->count() < 2) {
            return $round;
        }

        $highest = (int) $due->last()->round;

        foreach ($due as $lower) {
            if ((int) $lower->round < $highest) {
                Round::query()->whereKey($lower->id)->whereNull('claimed_at')->update([
                    'claimed_at' => $now, 'claimed_by' => $by, 'finished_at' => $now, 'outcome' => Round::SKIPPED_LATE,
                    'note' => 'Not sent on its own: it fell due while the ring was held up, and ring '.($highest + 1).' was sent in its place.',
                    'updated_at' => $now,
                ]);
            }
        }

        return $highest;
    }

    /** One statement decides who sends the round (the `firstInWindow` idea). Every time bound from PHP. */
    private function claim(int $alertId, int $round, string $by, Carbon $now): bool
    {
        $stale = $now->copy()->subSeconds(max(1, (int) config('ring.stale_claim', 90)));

        return Round::query()
            ->where('duress_alert_id', $alertId)
            ->where('round', $round)
            ->where('due_at', '<=', $now->copy()->addSecond())
            ->where(static function ($q) use ($stale): void {
                $q->whereNull('claimed_at')->orWhere(static function ($s) use ($stale): void {
                    $s->whereNull('finished_at')->where('claimed_at', '<', $stale);
                });
            })
            ->update(['claimed_at' => $now, 'claimed_by' => $by, 'updated_at' => $now]) === 1;
    }

    /**
     * @param  array<string, int|null>  $counts
     */
    private function finish(Round $row, string $outcome, string $note, array $counts = []): void
    {
        $now = Carbon::now();

        Round::query()->whereKey($row->id)->update([
            ...$counts,
            'outcome' => $outcome,
            'note' => mb_substr($note, 0, Round::NOTE_LENGTH),
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Book the next round's job for when it falls due — unless this was the last. */
    private function bookNext(DuressAlertRing $header, int $round): void
    {
        $next = $round + 1;

        // Round 1's job was booked at the raise.
        if ($round < 1 || $next > (int) $header->max_round) {
            return;
        }

        $row = Round::query()->where('duress_alert_id', (int) $header->duress_alert_id)->where('round', $next)->whereNull('claimed_at')->first();

        if ($row !== null) {
            $this->queue((new RingRound((int) $header->duress_alert_id, $next))->delay($row->due_at));
        }
    }

    /** Whether the alert has been answered since this round began — read from the row, now. */
    private function stopsNow(int $alertId, int $round): bool
    {
        $alert = DuressAlert::query()->find($alertId);

        return $alert === null || RingRule::stops($alert, $round);
    }

    /**
     * What this round's phones were sent, from the ledger: accepted by Expo, never "rang".
     *
     * @return array{phones: int, ring: int, visible: int, ready: int, partial: int}
     */
    private function counts(int $alertId, int $round): array
    {
        $events = Event::query()->where('duress_alert_id', $alertId)->where('round', $round)
            ->whereIn('event', [Event::SENT_RING, Event::SENT_VISIBLE])
            ->get(['push_token_id', 'event', 'detail']);

        $ring = $events->where('event', Event::SENT_RING);

        return [
            'phones' => $events->pluck('push_token_id')->unique()->count(),
            'ring' => $ring->count(),
            'visible' => $events->where('event', Event::SENT_VISIBLE)->count(),
            'ready' => $ring->where('detail', RingReadiness::READY)->count(),
            'partial' => $ring->where('detail', RingReadiness::PARTIAL)->count(),
        ];
    }

    /**
     * ROUND 1 ALSO TELLS THE ESTATE'S OWN HEAD OF SECURITY — a text and a mail, after the
     * round's rings, so the number of phones they are told is real (§3.4).
     *
     * @return int|null how many Heads of Security were planned a text, or null when none could be
     */
    private function escalate(DuressAlert $alert, string $reason, int $phones): ?int
    {
        $tenantId = (string) $alert->tenant_id;

        if ($alert->is_simulated || OperatorEstates::isDemonstration($tenantId)) {
            return null;
        }

        $texting = 0;

        try {
            $recipients = SendRingEscalationSms::recipients($this->escalation, $tenantId);
            $texting = count(array_filter($recipients, static fn (array $head): bool => ! $head['duplicate']));

            if ($recipients !== []) {
                // A text goes to a provider over the network: on the panic queue, never the ring's own (below).
                $this->queue(new SendRingEscalationSms((int) $alert->id, $reason, $phones), ring: false);
            }

            $this->deliveries->round($alert, 1, Leg::ESTATE_HEAD_OF_SECURITY, Leg::SMS, $texting, match (true) {
                $texting === 0 => Leg::NONE,
                ! app(SmsSettings::class)->canSend() => Leg::SKIPPED,
                default => Leg::QUEUED,
            }, match (true) {
                $texting === 0 => 'Ring 2: the estate has no Head of Security of its own with a phone on file to text.',
                ! app(SmsSettings::class)->canSend() => 'Ring 2: no SMS provider is connected, so the follow-up to the estate\'s Head of Security ('.$texting.') is written down and delivered to nobody.',
                default => 'Ring 2: the estate\'s Head of Security texted '.self::escalationLead($reason).' ('.$texting.'), through the panic queue.',
            });
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
            $this->deliveries->round($alert, 1, Leg::ESTATE_HEAD_OF_SECURITY, Leg::SMS, 0, Leg::FAILED, 'Ring 2: the follow-up text to the estate\'s Head of Security could not be queued; the error was reported.');
        }

        try {
            /** @var Collection<int, User> $mail */
            $mail = $this->escalation->estateHeadsOfSecurity($tenantId, limit: null)
                ->filter(static fn (User $user): bool => $user->alertEmail() !== null)->values();

            if ($mail->isNotEmpty()) {
                Notification::send($mail, CentralQueue::pin(new PanicStillUnanswered(
                    alertId: (int) $alert->id,
                    kind: (string) $alert->kind,
                    estate: (string) ($alert->estate->name ?? $tenantId),
                    where: $alert->unit_reference === null || $alert->unit_reference === '' ? null : (string) $alert->unit_reference,
                    raisedAt: SiteTime::named($alert->server_time, $tenantId, 'D, M j, Y g:i A'),
                    reason: $reason,
                    phones: $phones,
                    isDrill: (bool) $alert->is_drill,
                    tenantId: $tenantId,
                )));
            }

            $this->deliveries->round($alert, 1, Leg::ESTATE_HEAD_OF_SECURITY, Leg::EMAIL, $mail->count(), $mail->isEmpty() ? Leg::NONE : Leg::QUEUED, $mail->isEmpty()
                ? 'Ring 2: the estate has no Head of Security of its own with a proven alert address to mail.'
                : 'Ring 2: the follow-up mail to the estate\'s Head of Security ('.$mail->count().'), on the panic queue.');
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
        }

        return $texting;
    }

    /**
     * The sentence the round writes on its row and its hand-off leg. Never "rang".
     *
     * @param  array{phones: int, ring: int, visible: int, ready: int, partial: int}  $counts
     */
    private function note(DuressAlert $alert, Round $row, int $officers, int $entitled, array $counts, bool $pushOff, int $queued, bool $cutShort, ?int $late, bool $folded = false): string
    {
        $round = (int) $row->round;
        $estate = (string) ($alert->estate->name ?? $alert->tenant_id);

        $what = match (true) {
            $folded => 'Ring '.($round + 1).' (re-ring, with the escalation that was held up: every active officer of '.$estate.')',
            $round === 0 => 'Ring 1 (call-style, at the raise)',
            $round === 1 => 'Ring 2 (escalation'.match ((string) $row->reason) {
                Round::NOBODY_ON_DUTY => ' at once, nobody on duty',
                Round::NO_HANDSET => ' at once, no officer\'s phone reached at the raise',
                Round::DECLINED => ' early, every officer on shift said they cannot respond',
                default => '',
            }.': every active officer of '.$estate.')',
            default => 'Ring '.($round + 1).' (re-ring)',
        };

        if ($pushOff) {
            return $what.': push is switched off on this server, so no handset was sent anything.';
        }

        if ($officers === 0) {
            return $what.': there was no officer to ring.';
        }

        if ($counts['phones'] === 0 && $queued === 0) {
            return $round === 0 && $entitled > 0
                ? $what.': none of the '.self::plural($entitled, 'handset').' is set up to ring like a call yet; they had the ordinary alert above.'
                : $what.': '.self::plural($officers, 'officer').', but no handset of theirs could be pushed.';
        }

        $sentence = $round === 0
            ? $what.': '.$counts['ring'].' of '.self::plural($entitled, 'handset').' set up to ring like a call ('.$counts['ready'].' call-style ready, '.$counts['partial'].' partly ready); the other '.max(0, $entitled - $counts['ring']).' had the ordinary alert above.'
            : $what.': '.self::plural($counts['phones'], 'handset').' of '.self::plural($officers, 'officer').' sent the ring — '.$counts['ring'].' call-style ('.$counts['ready'].' ready, '.$counts['partial'].' partly ready), '.$counts['visible'].' the ordinary alert.';

        if ($queued > 0) {
            $sentence .= ' '.self::plural($queued, 'more batch', 'more batches').' went to the panic queue.';
        }

        if ($cutShort) {
            $sentence .= ' The alert was answered while it was going, so the rest was not sent.';
        }

        if ($late !== null) {
            $sentence .= ' Sent by the safety net '.$late.' s late.';
        }

        return $sentence;
    }

    /** "the control room acknowledged it", "Marcus Grant is responding" — why a round did not go. */
    public static function answeredSentence(DuressAlert $alert): string
    {
        return match (RingRule::answered($alert)) {
            RingRule::CANCELLED => 'the alert was cancelled from the phone that raised it',
            RingRule::CLOSED => 'the alert was closed',
            RingRule::RESPONDING => ($alert->respondingGuard->full_name ?? 'an officer').' is responding',
            RingRule::ACKNOWLEDGED => 'the control room has the alert',
            default => 'the alert was answered',
        };
    }

    private static function escalationLead(string $reason): string
    {
        return match ($reason) {
            Round::NOBODY_ON_DUTY => 'NOBODY ON DUTY',
            Round::NO_HANDSET => 'NO OFFICER PHONE REACHED',
            Round::DECLINED => 'NO OFFICER ON DUTY CAN RESPOND',
            default => 'UNANSWERED',
        };
    }

    /**
     * On the central connection, and on THE RING'S OWN QUEUE (`push.ring_queue`, found M18), which a worker of its own
     * reads and every panic worker reads first. It was the panic queue, shared with the alert's mail and texts: a panic
     * queues about ten of those a moment before its first ring round, and on production (alerts 18 and 19) the round
     * due at the raise was picked up five seconds late, behind them — a guard's phone rang 6–8 s after the press. A
     * text for the ring (`ring: false`) still goes on the panic queue: it is network work, and it must not stand in
     * front of the next round.
     */
    private function queue(ShouldQueue $job, bool $ring = true): void
    {
        $job = CentralQueue::pin($job);
        $queue = $ring ? RingQueue::name() : config('push.urgent_queue');

        if (is_string($queue) && $queue !== '' && method_exists($job, 'onQueue')) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    /** Phones per Expo request: `push.batch`, the figure today's push uses, never above Expo's 100. */
    private static function batchSize(): int
    {
        return max(1, min(ExpoPush::MAX_BATCH, (int) config('push.batch', ExpoPush::MAX_BATCH)));
    }

    private static function plural(int $count, string $one, ?string $many = null): string
    {
        return $count.' '.($count === 1 ? $one : ($many ?? $one.'s'));
    }
}
