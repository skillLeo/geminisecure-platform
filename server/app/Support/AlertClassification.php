<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How a dispatcher classifies a panic or duress alert when resolving it.
 *
 * A CHOICE, NOT A SENTENCE. "Resolve & classify" used to take a free-text
 * browser prompt, and the text was the classification — so the record held
 * "asdf" as often as it held "medical". A fixed list makes the outcome
 * countable, and the note stays for what a list cannot say.
 *
 * TWO OF THEM ARE FALSE ALARMS. A resident who pressed by mistake, or who is
 * confirmed safe, closes the alert as `false_alarm` — the first-class outcome
 * `AlertIntake::resolve()` already distinguishes — rather than as a generic
 * "resolved" that would hide which alerts are worth reviewing.
 *
 * A NOTE IS ALWAYS REQUIRED (M18C: nothing is optional). It was once asked only
 * for "Genuine — other", so as not to hold up a dispatcher under pressure; but
 * closing an alert is the moment after the pressure, and the note is the record
 * of what happened that the resident is later told. A few words is enough.
 */
final class AlertClassification
{
    /** The longest note the resolve form accepts — the column is text, the form is not a report. */
    public const NOTE_MAX = 500;

    /**
     * @var array<string, array{label: string, group: string, false_alarm: bool, note_required: bool}>
     */
    public const OPTIONS = [
        'genuine_medical' => ['label' => 'Medical emergency', 'group' => 'Genuine emergency', 'false_alarm' => false, 'note_required' => true],
        'genuine_fire' => ['label' => 'Fire', 'group' => 'Genuine emergency', 'false_alarm' => false, 'note_required' => true],
        'genuine_security' => ['label' => 'Security incident', 'group' => 'Genuine emergency', 'false_alarm' => false, 'note_required' => true],
        'genuine_other' => ['label' => 'Other emergency', 'group' => 'Genuine emergency', 'false_alarm' => false, 'note_required' => true],
        'false_alarm' => ['label' => 'Pressed by mistake', 'group' => 'False alarm', 'false_alarm' => true, 'note_required' => true],
        'resident_safe' => ['label' => 'Resident confirmed safe — no help needed', 'group' => 'False alarm', 'false_alarm' => true, 'note_required' => true],
        'drill' => ['label' => 'Test or drill', 'group' => 'Not an incident', 'false_alarm' => false, 'note_required' => true],
        'duplicate' => ['label' => 'Duplicate of another alert', 'group' => 'Not an incident', 'false_alarm' => false, 'note_required' => true],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function isFalseAlarm(string $key): bool
    {
        return self::OPTIONS[$key]['false_alarm'] ?? false;
    }

    public static function noteRequired(string $key): bool
    {
        return self::OPTIONS[$key]['note_required'] ?? false;
    }

    /** "Genuine emergency — Medical emergency", or null for an alert resolved before classifications existed. */
    public static function label(?string $key): ?string
    {
        if ($key === null || ! isset(self::OPTIONS[$key])) {
            return null;
        }

        return self::OPTIONS[$key]['group'].' — '.self::OPTIONS[$key]['label'];
    }

    /**
     * The list the resolve dialog draws, grouped in this order.
     *
     * @return list<array{value: string, label: string, group: string, note_required: bool}>
     */
    public static function forForm(): array
    {
        $out = [];

        foreach (self::OPTIONS as $value => $option) {
            $out[] = [
                'value' => $value,
                'label' => $option['label'],
                'group' => $option['group'],
                'note_required' => $option['note_required'],
            ];
        }

        return $out;
    }
}
