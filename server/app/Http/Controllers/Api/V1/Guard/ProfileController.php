<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\Guard;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/guards/me — the guard's OWN details. Board guard-app-26, My Profile.
 *
 * "A guard must be able to see their own licence expiry — an expiry surprise costs a
 * shift. Send it and show it." (M4 §3.4.) The licence is the guard's Private Security
 * Regulation Authority registration, which is what the workforce record holds
 * (`guards.psra_number`, `guards.psra_expires_on`) and what the compliance register and
 * the console bell already watch.
 *
 * ONLY THE CALLER'S OWN. The guard comes from the token; there is no id to pass, so
 * there is nobody else's licence to ask for.
 *
 * INVARIANT 2, BY ALLOWLIST. A guard's row also carries their rate of pay and an
 * approved pension. Neither is read here: the response is built field by field from
 * the names below, and the contract test fails on any key the catalogue does not list.
 */
class ProfileController extends Controller
{
    public const LICENCE_NAME = 'PSRA licence';

    public function show(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();
        $guard->loadMissing(['post', 'estate']);

        return response()->json([
            'name' => (string) $guard->full_name,
            'employee_number' => (string) $guard->employee_number,
            'site' => [
                'id' => (string) $context->tenantId,
                'name' => (string) ($guard->estate->name ?? $context->tenantId),
                'timezone' => SiteTime::zone($context->tenantId),
                'phases' => $this->phases(),
            ],
            'post' => $guard->post === null ? null : ['id' => $guard->post->id, 'name' => $guard->post->label()],
            'licence' => $this->licence($guard, $context->tenantId),
        ]);
    }

    /**
     * THE ESTATE'S OWN PHASES, IN THE ESTATE'S OWN ORDER.
     *
     * The Guard App's search filter is drawn from this and nothing else. It used to be a
     * `[1, 2, 3]` written into the screen, taken from a board that drew three chips because
     * three chips fitted the drawing — so Phoenix Park's fourth and fifth phases could not be
     * filtered at all, and any estate that is not three phases was wrong from the first day.
     *
     * `estate_phases` is the estate's structure as the console keeps it. Where an estate has
     * never recorded one — an older tenant, a tenant mid-import — the units themselves still
     * know which block they stand in, so the list is derived from them rather than left empty.
     * An estate with no phases at all returns none, and the app then draws no filter, which is
     * the truth about that estate.
     *
     * @return list<array{name: string, unit_count: int}>
     */
    private function phases(): array
    {
        $recorded = Phase::query()->withCount('units')->orderBy('sequence')->orderBy('name')->get();

        if ($recorded->isNotEmpty()) {
            return $recorded->map(static fn (Phase $p): array => [
                'name' => (string) $p->name,
                'unit_count' => (int) $p->units_count,
            ])->all();
        }

        // Plain rows, not units: a count per block is not a Unit, and reading it as one hid that.
        return Unit::query()->toBase()
            ->selectRaw('block as name, COUNT(*) as unit_count')
            ->whereNotNull('block')
            ->where('block', '!=', '')
            ->groupBy('block')
            ->orderBy('block')
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'unit_count' => (int) $row->unit_count,
            ])->all();
    }

    /**
     * The licence as the office holds it. `number` and `expires_on` are null when the
     * office has not recorded them — the app then says "held by the office" rather than
     * inventing a row. `state` is derived from the date on every read, never stored.
     *
     * `days_left` counts CALENDAR DAYS AT THE SITE: a licence that expires today has 0
     * left and is still good today; yesterday's is -1 and has expired.
     *
     * @return array{name: string, number: string|null, expires_on: string|null, state: string, days_left: int|null, warning_days: int}
     */
    private function licence(Guard $guard, string $tenantId): array
    {
        $number = trim((string) $guard->psra_number);
        $expires = $guard->psra_expires_on;

        $daysLeft = $expires === null
            ? null
            : (int) SiteTime::today($tenantId)->diffInDays(Carbon::parse($expires->toDateString(), SiteTime::zone($tenantId))->startOfDay(), false);

        return [
            'name' => self::LICENCE_NAME,
            'number' => $number === '' ? null : $number,
            'expires_on' => $expires?->toDateString(),
            'state' => match (true) {
                $daysLeft === null => 'unknown',
                $daysLeft < 0 => 'expired',
                $daysLeft <= Guard::LICENCE_WARNING_DAYS => 'expiring',
                default => 'valid',
            },
            'days_left' => $daysLeft,
            'warning_days' => Guard::LICENCE_WARNING_DAYS,
        ];
    }
}
