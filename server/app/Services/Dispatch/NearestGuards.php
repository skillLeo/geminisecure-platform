<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\DuressAlert;
use App\Models\Shift;
use App\Models\User;
use App\Services\Tracking\EstateBounds;
use App\Services\Tracking\GuardPins;
use App\Support\Geo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE NEAREST GUARDS TO AN OPEN ALERT (Hassam, 12 Oct 2026: "Given an open alert and the clocked-in guards' stored
 * positions, return the closest three with their distances. ... The system NEVER assigns, it only shows who is nearest
 * and a person sends them.")
 *
 * A SUGGESTION FOR A PERSON TO ACT ON. Nothing here writes: no officer is assigned, no alert changes. A dispatcher reads
 * the list and sends somebody with the act that already exists (Dispatch, on the alert's page).
 *
 * ONE RULE, TWO READERS. The estate's own Security map (`EstateSecurityMap::alertEntry()`) and the Gemini dispatcher rank
 * with `rank()`: by where each officer actually is, their newest stored reading, never by their post; an officer with no
 * reading this shift is left out, never guessed.
 */
final class NearestGuards
{
    /** How many officers are named as nearest an open alert (the estate map's own number). */
    public const COUNT = 3;

    /**
     * The nearest of these officers to a point: each officer row as given, with `distance_m` (whole metres) added,
     * nearest first, at most $count. An officer with no position (no `lat` and `lng`) is left out.
     *
     * @param  iterable<array<string, mixed>>  $officers  rows that carry `lat` and `lng` when they have a reading
     * @return list<array<string, mixed>>
     */
    public static function rank(iterable $officers, float $lat, float $lng, int $count = self::COUNT): array
    {
        return collect($officers)
            ->filter(static fn (array $officer): bool => isset($officer['lat'], $officer['lng']))
            ->map(static fn (array $officer): array => [
                ...$officer,
                'distance_m' => (int) round(Geo::metresBetween((float) $officer['lat'], (float) $officer['lng'], $lat, $lng)),
            ])
            ->sortBy('distance_m')
            ->take($count)
            ->values()
            ->all();
    }

    /**
     * For the Gemini dispatcher: the officers clocked in now, at the estates this viewer reaches, nearest to one alert.
     *
     * The estate map's rules hold here too: only an officer who shares their position has one (`GuardPins`); an alert
     * whose position is not confirmed inside its estate is named, never measured from (`EstateBounds`); and the officer
     * who raised a duress alert is the one in trouble, so never one of the nearest.
     *
     * @return array{alert_id: int, guards: list<array{key: string, guard_id: int, name: string, post: string|null, estate: string|null, distance_m: int, reading_at: string|null, freshness: string, freshness_label: string, href: string}>, note: string|null}
     */
    public function forAlert(DuressAlert $alert, User $viewer, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $answer = static fn (array $guards, ?string $note): array => ['alert_id' => (int) $alert->id, 'guards' => $guards, 'note' => $note];

        if (in_array($alert->status, ['resolved', 'false_alarm'], true)) {
            return $answer([], 'This alert is closed, so nobody needs sending.');
        }

        if ($alert->latitude === null || $alert->longitude === null) {
            return $answer([], 'This alert carries no position, so the nearest guards cannot be worked out.');
        }

        $lat = (float) $alert->latitude;
        $lng = (float) $alert->longitude;
        $estateId = (string) $alert->tenant_id;
        $outside = EstateBounds::notConfirmed(EstateBounds::of([$estateId])[$estateId] ?? null, $lat, $lng);

        if ($outside !== null) {
            return $answer([], 'This alert\'s position is '.$outside.', so nobody is measured from it.');
        }

        // On duty now, as the live map counts it: a shift whose roster covers now, started and not ended, by an active officer.
        $shifts = Shift::query()->with(['officer', 'post'])
            ->covering($now)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->whereHas('officer', static fn ($query) => $query->where('status', 'active'))
            ->get();

        // Only the estates this viewer reaches: a site-scoped dispatcher learns nothing of another estate's officers.
        $reach = $viewer->canAccessEstates($shifts->pluck('tenant_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all());
        $shifts = $shifts
            ->filter(static fn (Shift $shift): bool => $reach[(string) $shift->tenant_id] ?? false)
            ->reject(static fn (Shift $shift): bool => $alert->guard_id !== null && (int) $shift->guard_id === (int) $alert->guard_id)
            ->values();

        /** @var array<string, string> $names */
        $names = DB::connection('mysql')->table('tenants')
            ->whereIn('id', $shifts->pluck('tenant_id')->unique()->values()->all())
            ->pluck('name', 'id')->map(static fn ($name): string => (string) $name)->all();

        $guards = array_map(static fn (array $officer): array => [
            'key' => (string) $officer['key'],
            'guard_id' => (int) $officer['guard_id'],
            'name' => (string) $officer['name'],
            'post' => $officer['post'] ?? null,
            'estate' => $officer['estate'] ?? null,
            'distance_m' => (int) $officer['distance_m'],
            'reading_at' => $officer['reading_at'] ?? null,
            'freshness' => (string) $officer['freshness'],
            'freshness_label' => (string) $officer['freshness_label'],
            'href' => '/guards/'.$officer['guard_id'],
        ], self::rank(GuardPins::for($shifts, $names, $now)['officers'], $lat, $lng));

        return $answer($guards, $guards === [] ? 'No officer on duty has a position to measure from yet.' : null);
    }
}
