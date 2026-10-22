<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery as Leg;
use Illuminate\Support\Collection;

/**
 * "WHO WAS TOLD, AND HOW FAST" — one alert's hand-offs, read back for a person (M10 §1.3).
 *
 * The dispatcher's alert screen and `panic:walk` both draw from this, so the console and
 * the command a verifier runs cannot disagree about what a panic reached. It reads
 * `duress_alert_deliveries` and nothing else; it never recomputes a leg.
 *
 * ALL SEVEN DESTINATIONS, ALWAYS, IN §1.2's ORDER. A destination with no row is not left
 * out — it is listed as not reached, with the reason (`Leg::UNADDRESSED`), because a list
 * that silently skipped the Director would be read as "the Director was told".
 *
 * EVERY TIME IS A HAND-OFF, AND EVERY LABEL SAYS SO. "Handed off in 212 ms" — never
 * "delivered", never "received". The caption below is shown with the list wherever it
 * appears, because the number is going to be quoted and the sentence has to travel with
 * it.
 */
final class DeliveryReport
{
    public const CAPTION = 'Each time is how long the platform took to hand the alert off — to the live channel, the push queue, the mail queue, the text queue — counted from the moment it began telling anyone. It is not when anybody\'s phone received it: nothing downstream reports that back.';

    /**
     * @return array{
     *     recorded: bool,
     *     empty_reason: string|null,
     *     reached: int,
     *     of: int,
     *     unverified: int,
     *     unverified_label: string|null,
     *     fanout: string|null,
     *     caption: string,
     *     destinations: list<array{key: string, label: string, reached: bool, reason: string|null, unverified: int, legs: list<array<string, mixed>>, rings: list<array<string, mixed>>}>
     * }
     */
    public function forAlert(DuressAlert $alert): array
    {
        /** @var Collection<int, Leg> $all */
        $all = Leg::query()->where('duress_alert_id', (int) $alert->getKey())->orderBy('id')->get();

        /*
         * THE RAISE'S LEGS DECIDE "REACHED", AND ONLY THEY DO (the guard ring, G13). A ring round
         * is a leg of its own — "Ring 2 (escalation): 14 handsets of 11 officers" — filed under
         * the destination it went to; counted in, round 1's off-duty officers would turn "Guards
         * on shift" green on an alert that reached no guard on shift, and change `reached` and
         * `of` for every alert that ever escalated. So everything below reads the raise alone,
         * exactly as before the ring, and each destination lists its rounds under it.
         *
         * @var Collection<int, Leg> $rows
         */
        $rows = $all->whereNull('round')->values();
        $rounds = $all->whereNotNull('round')->values();

        $destinations = [];

        foreach (Leg::DESTINATIONS as $key => $label) {
            $legs = $rows->where('destination', $key)->values();
            $reached = $legs->contains(static fn (Leg $leg): bool => $leg->reached());

            // People this destination's alert mail skipped for want of a proven address (M10 §1.4).
            $unverified = (int) $legs->filter(static fn (Leg $leg): bool => $leg->isUnverifiedSkip())->sum('recipients');

            $destinations[] = [
                'key' => $key,
                'label' => $label,
                'reached' => $reached,

                /*
                 * Why not, when not: the leg's own sentences, or the reason no leg exists.
                 *
                 * AND WHO WAS MISSED, WHEN IT WAS REACHED BUT NOT BY EVERYBODY (M10 §1.4).
                 * "Reached" means somebody there was handed the alert; beside a proven
                 * President mailed, an unproven Treasurer is not told at all, and a green
                 * row with nothing under it reads as the whole committee knowing. So a
                 * reached destination whose mail skipped anybody carries that one sentence
                 * — "1 could not be emailed: address not verified." — under it on the
                 * dispatcher's screen, and nothing else. `panic:walk` prints this only for
                 * a destination NOT reached, where the leg's own note already says it.
                 */
                'reason' => match (true) {
                    $reached && $unverified > 0 => self::unverifiedLabel($unverified),
                    $reached => null,
                    $legs->isEmpty() => Leg::UNADDRESSED[$key] ?? Leg::NOT_ADDRESSED,
                    default => $legs->pluck('note')->filter()->unique()->implode(' '),
                },

                'unverified' => $unverified,

                'legs' => $legs->map(static fn (Leg $leg): array => self::leg($leg))->all(),

                // The ring's rounds to this destination, under it — never counted as its reach.
                'rings' => $rounds->where('destination', $key)->map(static fn (Leg $leg): array => self::leg($leg))->values()->all(),
            ];
        }

        $fanout = $alert->getAttribute('fanout_ms');

        /*
         * HOW MANY THE ALERT MAIL COULD NOT REACH, FOR THE WHOLE ALERT (M10 §1.4): the
         * number a dispatcher needs to know how many people to ring instead.
         */
        $unverified = (int) array_sum(array_column($destinations, 'unverified'));

        return [
            'recorded' => $rows->isNotEmpty(),
            'empty_reason' => $rows->isNotEmpty() ? null : ($alert->is_simulated
                ? 'A simulated alert is sent to nobody, so there is no hand-off to time.'
                : 'No hand-off was recorded for this alert. It was raised before hand-offs were timed, or the record could not be written — the alert itself was not affected.'),
            'reached' => count(array_filter($destinations, static fn (array $d): bool => $d['reached'])),
            'of' => count($destinations),
            'unverified' => $unverified,
            'unverified_label' => $unverified === 0 ? null : self::unverifiedLabel($unverified),

            // What `fanout_ms` has always measured, said exactly: the push and mail legs,
            // on the fan-out's own clock — which starts after the live channel has gone.
            'fanout' => $fanout === null ? null : self::fanoutSentence($rows, (int) $fanout),

            'caption' => self::CAPTION,
            'destinations' => $destinations,
        ];
    }

    /**
     * The fan-out's own time, in words that claim only what the legs record.
     *
     * It used to be one fixed sentence — "Guards pushed and alert mail queued within 121 ms …;
     * the texts follow." — and production's first walk printed it beside "Guards on shift: none,
     * not handed off" and four texts to nobody (25 September). Nothing may say a phone was
     * pushed, or a text is on its way, when none was: each clause is there only when a leg of
     * the raise was actually handed off on that channel.
     *
     * @param  Collection<int, Leg>  $rows  the raise's legs (never a ring round's)
     */
    private static function fanoutSentence(Collection $rows, int $fanoutMs): string
    {
        $handedOff = static fn (string $channel): bool => $rows->contains(static fn (Leg $leg): bool => $leg->channel === $channel && $leg->reached());

        $done = array_values(array_filter([
            $handedOff(Leg::PUSH) ? 'guards pushed' : null,
            $handedOff(Leg::EMAIL) ? 'alert mail queued' : null,
        ]));

        return implode(' ', array_filter([
            $handedOff(Leg::BROADCAST) ? 'The live channel went first.' : 'The live channel was not reached.',
            $done === []
                ? 'Nothing went to the push or mail queue.'
                : ucfirst(implode(' and ', $done)).' within '.$fanoutMs.' ms of the fan-out starting.',
            $done !== [] && ! $handedOff(Leg::PUSH) ? 'No guard was pushed.' : null,
            $handedOff(Leg::SMS) ? 'The texts follow.' : 'No text was handed off.',
        ]));
    }

    /** "2 could not be emailed: address not verified." — a count, never who. */
    public static function unverifiedLabel(int $count): string
    {
        return $count.' '.Leg::ADDRESS_NOT_VERIFIED.'.';
    }

    /** @return array<string, mixed> */
    private static function leg(Leg $leg): array
    {
        $reached = $leg->reached();

        $round = $leg->round;

        return [
            'round' => $round,

            // A ring round's own hand-off is timed from the alert, and says which ring it was.
            'round_label' => $round === null ? null : 'Ring '.($round + 1).' · '.($reached ? 'handed off '.number_format($leg->elapsed_ms / 1000, 1).' s after the alert' : 'not handed off'),
            'channel' => $leg->channel,
            'channel_label' => Leg::CHANNELS[$leg->channel] ?? ucfirst($leg->channel),
            'recipients' => $leg->recipients,
            'recipients_label' => self::recipients($leg),
            'outcome' => $leg->outcome,
            'outcome_label' => Leg::OUTCOMES[$leg->outcome] ?? ucfirst($leg->outcome),
            'reached' => $reached,
            'elapsed_ms' => $leg->elapsed_ms,

            // "Handed off in", only for a hand-off that happened.
            'elapsed_label' => match (true) {
                $reached => 'handed off in '.$leg->elapsed_ms.' ms',
                $leg->outcome === Leg::FAILED => 'failed at '.$leg->elapsed_ms.' ms',
                default => 'not handed off',
            },
            'note' => $leg->note,
        ];
    }

    /** Handsets, people, numbers — or the channel, which is not a count of anybody watching. */
    private static function recipients(Leg $leg): string
    {
        if ($leg->channel === Leg::BROADCAST) {
            return 'the estate\'s live channel';
        }

        $n = $leg->recipients;

        if ($n === 0) {
            return 'nobody';
        }

        return match ($leg->channel) {
            Leg::PUSH => $n.' handset'.($n === 1 ? '' : 's'),
            Leg::EMAIL => $n.' '.($n === 1 ? 'person' : 'people'),
            Leg::SMS => $n.' number'.($n === 1 ? '' : 's'),
            default => (string) $n,
        };
    }
}
