<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\PlatformContactSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\JamaicanNumber;
use DomainException;
use Throwable;

/**
 * GEMINI SECURITY'S PHONE LINE — the number the Resident App's "Call Gemini Security" calls (the user, 8 Oct 2026).
 *
 * EMPTY UNTIL SOMEBODY SAVES ONE, AND THEN THE APP HIDES THE BUTTON. The company has given no number (Q-M33). Nothing
 * here guesses one or keeps a placeholder: with no number, `number()` is null, `support.contact` says so, and the app
 * draws no "Call Gemini Security" at all.
 *
 * KEPT IN INTERNATIONAL FORM. Whatever is typed — "876-555-0123", "(876) 555 0123", "+1 876 555 0123" — is checked
 * the way every person's number is (JamaicanNumber::normalise: a seven-digit local number is refused rather than given
 * a guessed area code, and "123 456 7890" is nobody's phone) and kept diallable, "+18765550123" (JamaicanNumber::e164).
 * Shown in the platform's one form for a number, "+1 876 555 0123".
 *
 * Read from the console's row; `LEGAL_SUPPORT_PHONE` in the environment is used only while no row has been saved, so a
 * server configured the old way keeps its number. Every change is audited with who and both numbers.
 */
final class SupportLine
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** "+18765550123", or null while there is none. */
    public function number(): ?string
    {
        try {
            $row = PlatformContactSetting::current();
        } catch (Throwable) {
            $row = null;
        }

        if ($row !== null && $row->exists) {
            return $row->support_phone ?: null;
        }

        $typed = (string) config('legal.support_phone');

        return JamaicanNumber::normalise($typed) === null ? null : JamaicanNumber::e164($typed);
    }

    /** "+1 876 555 0123" — or null. */
    public function display(): ?string
    {
        return self::spaced($this->number());
    }

    /**
     * What the console shows of it.
     *
     * @return array{number: string|null, display: string|null, updated_by: string|null, updated_at: string|null}
     */
    public function shape(): array
    {
        $row = PlatformContactSetting::current();

        return [
            'number' => $this->number(),
            'display' => $this->display(),
            'updated_by' => $row->exists ? $row->updated_by_name : null,
            'updated_at' => $row->exists ? $row->updated_at?->toIso8601String() : null,
        ];
    }

    /**
     * Save it — or, empty, take it away (the app hides the button again).
     *
     * @throws DomainException in words the console shows, when it cannot be made into a number without guessing
     */
    public function save(?string $typed, User $by): ?string
    {
        $typed = trim((string) $typed);
        $number = $typed === '' || JamaicanNumber::normalise($typed) === null ? null : JamaicanNumber::e164($typed);

        if ($typed !== '' && $number === null) {
            throw new DomainException('That is not a number that can be called from a phone. Write it with its area code — 876 555 0123 — or in international form, +1 876 555 0123.');
        }

        $row = PlatformContactSetting::current();
        $before = $row->exists ? $row->support_phone : null;

        $row->forceFill(['support_phone' => $number, 'updated_by' => $by->id, 'updated_by_name' => mb_substr((string) $by->name, 0, 160)])->save();

        $this->audit->record(
            action: 'platform.support_line_changed',
            entityType: 'PlatformContactSetting',
            entityId: (string) $row->id,
            before: ['support_phone' => $before],
            after: ['support_phone' => $number],
            actor: $by,
        );

        return $number;
    }

    /** "+18765550123" → "+1 876 555 0123", the platform's one form for a number; another country's as kept; null stays null. */
    public static function spaced(?string $e164): ?string
    {
        if ($e164 === null || $e164 === '') {
            return null;
        }

        return JamaicanNumber::normalise($e164) ?? $e164;
    }
}
