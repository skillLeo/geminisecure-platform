<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Api\AppMatrix;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\DuressAlertRing;
use App\Models\DuressAlertRingEvent as Event;
use App\Models\DuressAlertRingRound as Round;
use App\Models\Guard;
use App\Support\SiteTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WHAT A PANIC'S RING REACHED, READ BACK FOR A PERSON — per alert and per guard (the guard ring §9).
 *
 * THE WORD "RANG" NEVER APPEARS (G11). The platform knows four different things and says
 * each by its own name:
 *
 *   sent                    Expo accepted the message for that phone. Not that it arrived.
 *   call-style ready /      the readiness the phone last reported, at the moment it was
 *   partly ready            sent — counted APART, never added into one "can ring" figure,
 *                           because "partly" includes Do Not Disturb bypass off and
 *                           battery optimised, either of which can keep a phone quiet
 *   shown on the phone      the phone ITSELF said it put the ring on screen
 *   with the alarm sound on …and that at that moment its ring channel was high enough to
 *                           sound, had a sound, the alarm volume was above zero and Do Not
 *                           Disturb let it through. Anything else — a silent channel,
 *                           volume 0, total silence, an unreadable check — is said, with
 *                           the reason, never counted as sound
 *
 * "Full screen" counts only the system's own full-screen launches, never a tap on a
 * heads-up (G12). Every readiness claim says when the phone reported it (G15).
 */
final class RingReport
{
    public const CAPTION = 'Sent means the platform handed it to the push service. Shown on the phone means the phone itself reported it put the ring on screen, and "with the alarm sound on" means its alarm sound was switched on at that moment. A phone that never reported may still have shown it, or may be off, in a drawer, or held back by battery saving.';

    /** A readiness report older than this is shown as possibly out of date. */
    private const STALE_HOURS = 24;

    /**
     * The alert's ring, round by round, for the dispatcher's "Ringing" panel.
     *
     * @return array{active: bool, caption: string, rounds: list<array<string, mixed>>, answers: list<array{who: string, what: string, at: string}>, stopped: array{at: string, why: string, phones: int}|null, next: string|null}
     */
    public function forAlert(DuressAlert $alert): array
    {
        $header = DuressAlertRing::query()->where('duress_alert_id', (int) $alert->id)->first();

        if ($header === null) {
            return ['active' => false, 'caption' => self::CAPTION, 'rounds' => [], 'answers' => [], 'stopped' => null, 'next' => null];
        }

        $tenantId = (string) $alert->tenant_id;
        // On the estate's clock, tagged — or the dispatcher's own, in "my time" (M19A §7).
        $clock = static fn (?CarbonInterface $at): ?string => $at === null ? null : SiteTime::stamp($at, $tenantId, 'g:i:s A');

        $rounds = Round::query()->where('duress_alert_id', (int) $alert->id)->orderBy('round')->get();
        $events = Event::query()->where('duress_alert_id', (int) $alert->id)->orderBy('id')->get();
        $tokens = DevicePushToken::query()->whereKey($events->pluck('push_token_id')->unique()->all())->get()->keyBy('id');
        $names = Guard::query()->whereKey($events->pluck('guard_id')->filter()->unique()->all())->pluck('full_name', 'id');

        $estate = (string) ($alert->estate->name ?? $tenantId);
        $out = [];

        foreach ($rounds as $round) {
            $number = (int) $round->round;
            $mine = $events->where('round', $number);
            $settled = $round->finished_at !== null;
            $skipped = in_array((string) $round->outcome, Round::SKIPPED, true);

            $out[] = [
                'round' => $number,
                'label' => self::label($round, $alert, $estate),
                'state' => match (true) {
                    ! $settled && $round->claimed_at !== null => 'sending',
                    ! $settled => 'pending',
                    $skipped => 'skipped',
                    in_array((string) $round->outcome, [Round::NONE, Round::FAILED], true) => 'none',
                    default => 'sent',
                },
                'summary' => match (true) {
                    ! $settled && $round->claimed_at !== null => 'Going out now.',
                    ! $settled => 'Due '.$clock($round->due_at).' unless someone answers.',
                    $skipped, in_array((string) $round->outcome, [Round::NONE, Round::FAILED], true) => (string) $round->note,
                    default => $this->summary($round, $mine, $tokens),
                },
                'safety_net' => $round->claimed_by === Round::BY_SWEEP,
            ];
        }

        $answers = $events->whereIn('event', [Event::ANSWERED_RESPONDING, Event::ANSWERED_CANNOT])->map(static fn (Event $e): array => [
            'who' => (string) ($names[(int) $e->guard_id] ?? 'An officer'),
            'what' => $e->event === Event::ANSWERED_RESPONDING
                ? 'responding'
                : ($e->detail === RingAnswers::SCOPE_ROUND ? 'can\'t respond to this ring (from the lock screen)' : 'can\'t respond'),
            'at' => (string) $clock($e->created_at),
        ])->values()->all();

        $stopPhones = $events->whereIn('event', [Event::SENT_STOP, Event::SENT_VISIBLE_STOP])->pluck('push_token_id')->unique()->count();

        $next = $rounds->first(static fn (Round $r): bool => $r->claimed_at === null && $r->finished_at === null);

        return [
            'active' => true,
            'caption' => self::CAPTION,
            'rounds' => $out,
            'answers' => $answers,
            'stopped' => $header->answered_at === null ? null : [
                'at' => (string) $clock($header->answered_at),
                'why' => ucfirst(self::whyWords($alert, (string) $header->answered_why)),
                'phones' => $stopPhones,
            ],
            'next' => $next === null || $header->answered_at !== null ? null : 'Ring '.($next->round + 1).' due '.$clock($next->due_at).' unless someone answers.',
        ];
    }

    /**
     * The ring's steps for the alert's timeline: each round that went, the next one due, the answers and the stop.
     *
     * @return list<array{title: string, meta: string, pending: bool}>
     */
    public function timeline(DuressAlert $alert): array
    {
        $report = $this->forAlert($alert);

        if (! $report['active']) {
            return [];
        }

        $steps = [];

        foreach ($report['rounds'] as $round) {
            if ($round['state'] === 'sent' || $round['state'] === 'sending') {
                $steps[] = ['title' => $round['label'], 'meta' => (string) $round['summary'], 'pending' => false];
            }
        }

        foreach ($report['answers'] as $answer) {
            $steps[] = ['title' => $answer['who'].' — '.$answer['what'], 'meta' => 'Answered on the ring · '.$answer['at'], 'pending' => false];
        }

        if ($report['stopped'] !== null) {
            $steps[] = ['title' => 'Ringing stopped', 'meta' => $report['stopped']['why'].' · stop sent to '.$report['stopped']['phones'].' phone'.($report['stopped']['phones'] === 1 ? '' : 's').' · '.$report['stopped']['at'], 'pending' => false];
        } elseif ($report['next'] !== null) {
            $steps[] = ['title' => 'Still ringing', 'meta' => $report['next'], 'pending' => true];
        }

        return $steps;
    }

    /**
     * Whether this officer's phone can ring like a call, for their record.
     *
     * @return array{state: string, label: string, detail: string|null, rows: list<array{key: string, label: string, on: bool|null, value: string, problem: string|null}>}
     */
    public function forGuard(Guard $guard): array
    {
        $token = DevicePushToken::query()->live()
            ->ownedBy((string) $guard->tenant_id, DevicePushToken::GUARD, (int) $guard->id)
            ->where('app', AppMatrix::GUARD)
            ->latest('last_registered_at')
            ->first();

        return self::describe($token, (string) $guard->tenant_id);
    }

    /**
     * The short word for each officer's phone, for the directory.
     *
     * @param  list<int>  $guardIds
     * @return array<int, array{state: string, short: string}>
     */
    public function forGuards(array $guardIds): array
    {
        if ($guardIds === []) {
            return [];
        }

        $tokens = DevicePushToken::query()->live()
            ->where('principal_type', DevicePushToken::GUARD)
            ->where('app', AppMatrix::GUARD)
            ->whereIn('principal_id', $guardIds)
            ->orderBy('last_registered_at')
            ->get()
            ->keyBy('principal_id');

        $out = [];

        foreach ($guardIds as $id) {
            $state = self::stateOf($tokens->get($id));
            $out[$id] = ['state' => $state, 'short' => self::SHORT[$state]];
        }

        return $out;
    }

    private const SHORT = [
        'none' => 'No phone',
        'ios' => 'iPhone — ordinary alerts',
        'older' => 'Older app — ordinary alerts',
        RingReadiness::READY => 'Ready to ring like a call',
        RingReadiness::PARTIAL => 'Partly ready',
        RingReadiness::NOT_READY => 'Not ready',
    ];

    private static function stateOf(?DevicePushToken $token): string
    {
        return match (true) {
            $token === null => 'none',
            $token->platform === 'ios' => 'ios',
            ! RingSender::capable($token) => 'older',
            default => (string) ($token->ring_state ?? RingReadiness::NOT_READY),
        };
    }

    /**
     * @return array{state: string, label: string, detail: string|null, rows: list<array{key: string, label: string, on: bool|null, value: string, problem: string|null}>}
     */
    private static function describe(?DevicePushToken $token, string $tenantId): array
    {
        $state = self::stateOf($token);

        if ($token === null || in_array($state, ['none', 'ios', 'older'], true)) {
            return ['state' => $state, 'label' => match ($state) {
                'none' => 'No phone registered',
                'ios' => 'iPhone — ordinary time-sensitive alerts',
                default => 'Older app — ordinary alerts only',
            }, 'detail' => null, 'rows' => []];
        }

        $report = is_array($token->ring_readiness) ? $token->ring_readiness : null;
        $rows = RingReadiness::rows($report);
        $off = array_values(array_filter($rows, static fn (array $row): bool => $row['on'] !== true));

        $reported = $token->ring_checked_at === null
            ? 'readiness never reported'
            : ($token->ring_checked_at->lt(Carbon::now()->subHours(self::STALE_HOURS))
                ? 'last reported '.$token->ring_checked_at->diffForHumans().' — may have changed'
                : 'reported '.$token->ring_checked_at->diffForHumans());

        $proven = $token->ring_proven_at === null
            ? 'no test ring shown yet'
            : 'proven by a test ring '.SiteTime::stamp($token->ring_proven_at, $tenantId, 'D j M g:i A');

        $offWords = implode(' and ', array_map(static fn (array $row): string => (string) $row['problem'], $off));

        $label = match ($state) {
            RingReadiness::READY => 'Ready to ring like a call',
            RingReadiness::PARTIAL => 'Partly — rings, but '.($offWords === '' ? 'not everything is right' : $offWords),
            default => 'No — '.($offWords === '' ? 'the phone could not say it can' : $offWords),
        };

        return ['state' => $state, 'label' => $label, 'detail' => 'Android, '.$reported.' · '.$proven, 'rows' => $rows];
    }

    /**
     * What a round that went reached, in the words the class note allows.
     *
     * @param  Collection<int, Event>  $events
     * @param  Collection<int|string, DevicePushToken>  $tokens
     */
    private function summary(Round $round, Collection $events, Collection $tokens): string
    {
        $sent = $events->whereIn('event', [Event::SENT_RING, Event::SENT_VISIBLE]);
        $phones = $sent->pluck('push_token_id')->unique();
        $ringIds = $events->where('event', Event::SENT_RING)->pluck('push_token_id')->unique();
        $ordinary = $phones->diff($ringIds)->count();

        $ready = $events->where('event', Event::SENT_RING)->where('detail', RingReadiness::READY)->count();
        $partial = $events->where('event', Event::SENT_RING)->where('detail', RingReadiness::PARTIAL)->count();
        $notReady = $ringIds->count() - $ready - $partial;

        $parts = [self::plural((int) ($round->officers ?? 0), 'officer')];

        $sentence = self::plural($phones->count(), 'phone').' sent the ring';
        $kinds = array_filter([
            $ready > 0 ? $ready.' call-style ready' : null,
            $partial > 0 ? $partial.' partly ready' : null,
            $notReady > 0 ? $notReady.' call-style not ready' : null,
            $ordinary > 0 ? $ordinary.' ordinary alert' : null,
        ]);

        // WHEN EACH PHONE LAST SAID SO (G15), relative to this round.
        $checked = $ringIds->map(static fn ($id) => $tokens->get($id)?->ring_checked_at)->filter();
        $at = $round->claimed_at ?? $round->due_at;

        if ($checked->isNotEmpty()) {
            $oldest = $checked->min();
            $newest = $checked->max();
            $span = self::ago($newest, $at).($oldest->equalTo($newest) ? '' : ' to '.self::ago($oldest, $at));
            $kinds[] = 'readiness reported '.$span.' before';
        }

        $parts[] = $sentence.($kinds === [] ? '' : ' ('.implode(', ', $kinds).')');

        $shown = $events->where('event', Event::SHOWN);

        if ($shown->isNotEmpty()) {
            $withSound = 0;
            $without = [];

            foreach ($shown as $receipt) {
                $why = self::soundProblem($receipt->detail);

                if ($why === null) {
                    $withSound++;
                } else {
                    $without[$why] = ($without[$why] ?? 0) + 1;
                }
            }

            $line = $shown->count().' shown on the phone: '.$withSound.' with the alarm sound on';

            if ($without !== []) {
                $line .= ', '.array_sum($without).' without it ('.implode('; ', array_map(static fn (string $why, int $n): string => $n.' '.$why, array_keys($without), $without)).')';
            }

            $parts[] = $line;
        } else {
            $parts[] = 'none shown on the phone yet';
        }

        $full = $events->where('event', Event::FULL_SCREEN)->count();

        if ($full > 0) {
            $parts[] = $full.' of them full screen';
        }

        foreach ([Event::DISMISSED => 'dismissed on the phone', Event::SUPPRESSED => 'held silent: the officer is under their own alert', Event::FALLBACK => 'shown as an ordinary notice instead', Event::FAILED => 'refused by the push service'] as $event => $words) {
            $n = $events->where('event', $event)->count();

            if ($n > 0) {
                $parts[] = $n.' '.$words;
            }
        }

        $moved = $events->where('event', Event::REFUSED)->where('detail', 'entitlement')->count();

        if ($moved > 0) {
            $parts[] = $moved.' not sent: the phone is no longer this officer\'s at this estate';
        }

        if ($round->heads_texted !== null) {
            $parts[] = $round->heads_texted > 0 ? 'the estate\'s Head of Security texted and mailed' : 'no Head of Security of the estate\'s own with a phone to text';
        }

        if ($round->claimed_by === Round::BY_SWEEP) {
            $parts[] = 'sent by the safety net '.max(0, (int) $round->due_at->diffInSeconds($round->claimed_at ?? $round->due_at, true)).' s late';
        }

        return implode(' · ', $parts).'.';
    }

    /**
     * Why a `shown` receipt does not count as "with the alarm sound on" — or null when it does.
     *
     * The phone's check is `imp=<0-5>;snd=<0|1>;vol=<n>/<max>;dnd=<pass|block|unknown>;…`. Only
     * a channel at HIGH or above, with a sound, an alarm volume above zero and Do Not
     * Disturb letting it through is sound. Anything unreadable is "sound not confirmed".
     */
    public static function soundProblem(?string $detail): ?string
    {
        if ($detail === null || $detail === '') {
            return 'sound not confirmed';
        }

        $facts = [];

        foreach (explode(';', $detail) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $facts[trim((string) $key)] = trim((string) $value);
        }

        if (! isset($facts['imp'], $facts['snd'], $facts['vol'], $facts['dnd']) || preg_match('/^(\d+)\/(\d+)$/', $facts['vol'], $volume) !== 1 || ! ctype_digit($facts['imp'])) {
            return 'sound not confirmed';
        }

        return match (true) {
            (int) $facts['imp'] < 4 => 'ring channel set below high',
            $facts['snd'] !== '1' => 'no sound on the ring channel',
            (int) $volume[1] === 0 => 'alarm volume 0',
            $facts['dnd'] === 'block' => 'Do Not Disturb on total silence',
            $facts['dnd'] !== 'pass' => 'Do Not Disturb not confirmed',
            default => null,
        };
    }

    private static function label(Round $round, DuressAlert $alert, string $estate): string
    {
        $number = (int) $round->round;
        $offset = max(0, (int) round($alert->server_time->diffInSeconds($round->due_at, true)));

        return 'Ring '.($number + 1).' · '.$offset.' s · '.match (true) {
            $number === 0 => 'call-style, at the raise',
            $number === 1 => match ((string) $round->reason) {
                Round::NOBODY_ON_DUTY => 'escalation at once, nobody on duty — every officer of '.$estate,
                Round::NO_HANDSET => 'escalation at once, no officer\'s phone reached — every officer of '.$estate,
                Round::DECLINED => 'escalation early, every officer on shift can\'t respond — every officer of '.$estate,
                default => 'escalation, every officer of '.$estate,
            },
            default => 're-ring',
        };
    }

    private static function whyWords(DuressAlert $alert, string $why): string
    {
        return match ($why) {
            RingRule::CANCELLED => 'the alert was cancelled from the phone that raised it',
            RingRule::CLOSED => 'the alert was closed',
            RingRule::RESPONDING => ($alert->respondingGuard->full_name ?? 'an officer').' is responding',
            default => 'the control room has the alert',
        };
    }

    private static function ago(CarbonInterface $then, CarbonInterface $at): string
    {
        $seconds = max(0, (int) $then->diffInSeconds($at, true));

        if ($seconds < 5400) {
            return max(1, (int) round($seconds / 60)).' min';
        }

        if ($seconds < 64800) {
            return (int) round($seconds / 3600).' h';
        }

        $days = max(1, (int) round($seconds / 86400));

        return $days.' day'.($days === 1 ? '' : 's');
    }

    private static function plural(int $count, string $one): string
    {
        return $count.' '.$one.($count === 1 ? '' : 's');
    }
}
