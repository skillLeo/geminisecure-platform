<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Models\DuressAlert;
use App\Services\Push\PushMessage;
use App\Support\SiteTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * EVERY MESSAGE THE RING SENDS, BUILT IN ONE PLACE FROM AN ALLOWLIST (the guard ring §6).
 *
 * THE CALL-STYLE RING IS DATA-ONLY. No `title`, `body`, `sound` or `channelId` at the top
 * — Expo would then present an ordinary banner itself — and no key named `title`,
 * `message` or `body` inside `data`, for the same reason: expo-notifications falls back
 * to `data.title` and `data.message` and would present one anyway. The phone's own ring
 * module wakes on it and puts up the full-screen ring. `priority: high` is mandatory:
 * Expo's Android default is `normal`, which a dozing phone may hold for minutes.
 *
 * THE VISIBLE MESSAGES ARE TODAY'S SHAPE PLUS `tag` AND `collapseId`, so an older Guard
 * App, an iPhone and a phone whose ring never reported shown all get an ordinary alert
 * that routes as today's does (`site_alert` → `/alert/{id}`), and the next round or the
 * stop replaces it rather than stacking beside it.
 *
 * WHAT A LOCK SCREEN MAY READ. Where (the lot), what kind, which estate, when. Never who
 * pressed it: the rule every push a guard receives already keeps (`PushMessage`). The one
 * name a stop can carry is the responding OFFICER's, which `alerts.show` already gives a
 * rung guard.
 *
 * `PushMessage::data()` STAYS `{type, id, kind}`. None of this goes through it.
 */
final class RingPayload
{
    public const SITE_RING = 'site_ring';

    public const RING_STOP = 'ring_stop';

    public const RING_TEST = 'ring_test';

    /** The contract version the phone's parser checks. */
    public const VERSION = 1;

    /** The tag the visible messages share, so each replaces the last on the phone. */
    public static function tag(int $alertId): string
    {
        return 'ring-'.$alertId;
    }

    /**
     * The call-style ring, data-only.
     *
     * @return array<string, mixed>
     */
    public static function siteRing(DuressAlert $alert, int $round, int $lastRound, string $ticket, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();

        return [
            'priority' => 'high',
            'ttl' => (int) config($round === 0 ? 'ring.first_ring_ttl' : 'ring.ring_ttl', $round === 0 ? 55 : 110),
            'data' => [
                'type' => self::SITE_RING,
                'v' => self::VERSION,
                'id' => (string) $alert->id,
                'kind' => self::kind($alert),
                'r' => $round,
                'of' => $lastRound,
                'esc' => $round >= 1 ? 1 : 0,
                'drill' => $alert->is_drill ? 1 : 0,
                'estate' => self::estate($alert),
                'where' => self::where($alert),
                'age' => self::age($alert, $now),
                'at' => SiteTime::local($alert->server_time, (string) $alert->tenant_id)->format('g:i A'),
                'hold' => (int) config('ring.hold_seconds', 45),
                'rt' => $ticket,
            ],
        ];
    }

    /**
     * The stop, data-only: the phone's module cancels the ring and puts up its own notice.
     *
     * @return array<string, mixed>
     */
    public static function ringStop(DuressAlert $alert, string $why, ?string $officer): array
    {
        $data = [
            'type' => self::RING_STOP,
            'v' => self::VERSION,
            'id' => (string) $alert->id,
            'why' => $why,
        ];

        if ($why === RingRule::RESPONDING && $officer !== null && trim($officer) !== '') {
            $data['who'] = mb_substr(trim($officer), 0, 40);
        }

        return ['priority' => 'high', 'ttl' => (int) config('ring.stop_ttl', 600), 'data' => $data];
    }

    /**
     * A test ring, asked for by the guard from the Call-style screen. Always a drill.
     *
     * @return array<string, mixed>
     */
    public static function ringTest(string $testId, string $estate, string $ticket): array
    {
        return [
            'priority' => 'high',
            'ttl' => (int) config('ring.test_ttl', 60),
            'data' => [
                'type' => self::RING_TEST,
                'v' => self::VERSION,
                'id' => $testId,
                'drill' => 1,
                'estate' => mb_substr($estate, 0, 60),
                'hold' => (int) config('ring.test_hold_seconds', 15),
                'rt' => $ticket,
            ],
        ];
    }

    /**
     * The visible re-ring: today's `site_alert`, tagged, for every phone that cannot yet be
     * trusted to ring on its own (§6.1).
     *
     * @return array<string, mixed>
     */
    public static function visibleRing(DuressAlert $alert, string $reason, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $what = self::title($alert);

        $title = match ($reason) {
            'nobody_on_duty', 'no_handset' => 'NO OFFICER REACHED · '.$what,
            default => 'STILL UNANSWERED · '.$what,
        };

        $age = self::age($alert, $now);
        $when = $age < 45 ? 'Raised just now.' : max(1, (int) round($age / 60)).' min since it was raised.';

        return [
            'title' => PushMessage::clip($alert->drillPrefix().$title, 80),
            'body' => PushMessage::clip(self::lead($alert).$when.' Open it to respond.', 170),
            'data' => ['type' => 'site_alert', 'id' => (string) $alert->id, 'kind' => self::kind($alert)],
            'sound' => 'default',
            'channelId' => 'safety',
            'priority' => 'high',
            'interruptionLevel' => 'time-sensitive',
            'ttl' => (int) config('ring.ring_ttl', 110),
            'tag' => self::tag((int) $alert->id),
            'collapseId' => self::tag((int) $alert->id),
        ];
    }

    /**
     * The visible stop, replacing the visible re-ring by its tag. Its words follow WHY
     * (§5.4, G3): only a closed or cancelled alert is a stand-down.
     *
     * @return array<string, mixed>
     */
    public static function visibleStop(DuressAlert $alert, string $why, ?string $officer): array
    {
        [$title, $body] = self::stopWords($alert, $why, $officer);

        return [
            'title' => PushMessage::clip($title, 80),
            'body' => PushMessage::clip($body, 170),
            'data' => ['type' => self::RING_STOP, 'id' => (string) $alert->id],
            'sound' => 'default',
            'channelId' => 'general',
            'priority' => 'default',
            'ttl' => (int) config('ring.stop_ttl', 600),
            'tag' => self::tag((int) $alert->id),
            'collapseId' => self::tag((int) $alert->id),
        ];
    }

    /**
     * What a stop says, by why — the §5.4 table.
     *
     * @return array{0: string, 1: string}
     */
    public static function stopWords(DuressAlert $alert, string $why, ?string $officer): array
    {
        $what = self::title($alert);
        $at = self::where($alert) === '' ? '' : 'At '.self::where($alert).': ';
        $sentence = static fn (string $text): string => $at === '' ? ucfirst($text) : $at.$text;

        return match ($why) {
            RingRule::CLOSED => [$alert->drillPrefix().'Stood down · '.$what, $sentence('the alert was closed. Nothing more for you to do.')],
            RingRule::CANCELLED => [$alert->drillPrefix().'Stood down · '.$what, $sentence('the alert was cancelled. Nothing more for you to do.')],
            RingRule::RESPONDING => [$alert->drillPrefix().'Ringing stopped · '.$what, $at.($officer === null || trim($officer) === '' ? 'An officer' : mb_substr(trim($officer), 0, 40)).' is responding. Follow dispatch\'s instructions.'],
            default => [$alert->drillPrefix().'Ringing stopped · '.$what, $sentence('the control room has this alert. Follow dispatch\'s instructions.')],
        };
    }

    /** The kind the phone draws its SECURITY / MEDICAL / FIRE line from. Always a slug. */
    public static function kind(DuressAlert $alert): string
    {
        $kind = (string) $alert->kind;

        return preg_match('/^[A-Za-z0-9_-]{1,40}$/', $kind) === 1 ? $kind : 'panic';
    }

    /** What today's push calls it — so the re-ring and the raise read as one alert. */
    public static function title(DuressAlert $alert): string
    {
        return match ((string) $alert->kind) {
            'panic' => 'Panic alert',
            'duress' => 'Officer duress alert',
            'medical' => 'Medical emergency',
            'fire' => 'Fire reported',
            'intrusion' => 'Intrusion reported',
            default => 'Emergency alert',
        };
    }

    public static function where(DuressAlert $alert): string
    {
        return mb_substr(trim((string) ($alert->unit_reference ?? '')), 0, 40);
    }

    public static function estate(DuressAlert $alert): string
    {
        return mb_substr(trim((string) ($alert->estate->name ?? $alert->tenant_id)), 0, 60);
    }

    /** Whole seconds since the server received it. The phone adds its own time since receipt (B066). */
    public static function age(DuressAlert $alert, CarbonInterface $now): int
    {
        return max(0, $now->getTimestamp() - $alert->server_time->getTimestamp());
    }

    private static function lead(DuressAlert $alert): string
    {
        return match (true) {
            self::where($alert) !== '' => 'At '.self::where($alert).'. ',
            $alert->kind === 'duress' => 'An officer at your site needs help. ',
            default => 'At your site. ',
        };
    }
}
