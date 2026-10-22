<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\ResidentAccount;
use App\Services\Audit\AuditLogger;
use App\Support\SiteTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * DRILL MODE: ONE RESIDENT APP ACCOUNT, FOR A SET TIME, SWITCHED ON BY THE SERVER (M53 §3A.1, M54 §2.3).
 *
 * The user's words: "only the server may raise a drill, switched on for an account for a set time from the office. A
 * phone that could mark its own panic a drill could hide a real one. Keep that exactly as proposed." And M54's test of
 * it: "panic with drill mode wakes nobody".
 *
 * So the switch is on the ACCOUNT, and only `panic:drill-mode` sets it. While it is on, a Panic from that account is
 * recorded as a DRILL raised in drill mode (`DuressAlert::wakesNobody()`): on every console, labelled DRILL, out of
 * every figure, never deleted, and nobody's phone rings, nobody is mailed or texted, no siren sounds. The phone is told
 * (`GET /me` → `drill_mode`), so the person pressing it knows, and its panic screen says nobody was alerted.
 *
 * IT ENDS BY ITSELF, AT MOST FOUR HOURS ON: a switch nobody remembers to turn off is the one that silences a real
 * emergency. And a press that waited on the phone for a signal is never a drill (`appliesTo()`): nobody can say the
 * switch was on when it was pressed.
 */
final class PanicDrillMode
{
    /** The longest it may run at once. */
    public const MAX_MINUTES = 240;

    public const DEFAULT_MINUTES = 120;

    public function __construct(private readonly AuditLogger $audit) {}

    /** Whether this account's press, received now, is a drill that wakes nobody. */
    public static function appliesTo(?ResidentAccount $account, bool $capturedOffline, ?CarbonInterface $at = null): bool
    {
        return ! $capturedOffline && self::isOn($account, $at);
    }

    /** Whether drill mode is on for this account at that moment. */
    public static function isOn(?ResidentAccount $account, ?CarbonInterface $at = null): bool
    {
        $until = $account?->drill_mode_until;

        return $until !== null && $until->isAfter($at ?? Carbon::now());
    }

    /**
     * What the phone is told: when it ends, at the estate's clock as well as the instant, and why. Null when it is off.
     *
     * @return array{until: string, until_local: string, reason: string|null}|null
     */
    public static function shape(ResidentAccount $account): ?array
    {
        if (! self::isOn($account)) {
            return null;
        }

        $until = Carbon::parse($account->drill_mode_until);

        return [
            'until' => (string) SiteTime::iso($until),
            'until_local' => SiteTime::local($until, (string) $account->tenant_id)->format('g:i A'),
            'reason' => $account->drill_mode_reason,
        ];
    }

    /** Switch it on until `$minutes` from now (1 to MAX_MINUTES), audited. */
    public function switchOn(ResidentAccount $account, int $minutes, string $reason, string $by): void
    {
        $minutes = max(1, min(self::MAX_MINUTES, $minutes));
        $before = self::auditShape($account);

        $account->forceFill([
            'drill_mode_until' => Carbon::now()->addMinutes($minutes),
            'drill_mode_reason' => mb_substr(trim($reason), 0, 160),
            'drill_mode_by' => mb_substr(trim($by), 0, 120),
        ])->save();

        $this->audit->record(
            action: 'panic.drill_mode_on',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: $before,
            after: self::auditShape($account) + ['minutes' => $minutes],
            tenantId: (string) $account->tenant_id,
            actorName: $by,
        );
    }

    /** Switch it off now, audited. Nothing to do when it is already off. */
    public function switchOff(ResidentAccount $account, string $by): bool
    {
        if (! self::isOn($account)) {
            return false;
        }

        $before = self::auditShape($account);

        $account->forceFill(['drill_mode_until' => Carbon::now()])->save();

        $this->audit->record(
            action: 'panic.drill_mode_off',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: $before,
            after: self::auditShape($account),
            tenantId: (string) $account->tenant_id,
            actorName: $by,
        );

        return true;
    }

    /** @return array{drill_mode_until: string|null, drill_mode_reason: string|null, drill_mode_by: string|null} */
    private static function auditShape(ResidentAccount $account): array
    {
        $until = $account->drill_mode_until;

        return [
            'drill_mode_until' => $until === null ? null : Carbon::parse($until)->toIso8601String(),
            'drill_mode_reason' => $account->drill_mode_reason,
            'drill_mode_by' => $account->drill_mode_by,
        ];
    }
}
