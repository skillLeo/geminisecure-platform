<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;

/**
 * A test's own person as its household's ONE primary resident — the way "Make primary" does it.
 *
 * `Resident` now refuses a second current primary on a household (DESIGN §13, INV-13), and the estate
 * database these suites share is committed: several files stand on the same lot (the fixture's five, and a
 * scratch lot two files happen to share), and each used to `firstOrCreate` its own person "as primary" beside
 * whoever the previous file left there — two primaries, the very state the user's screenshot showed. Each file
 * now takes the household over for its own run, clearing whoever held it first, and puts it back the next time
 * it runs; nothing in any of them depends on another file's person staying primary.
 */
final class HouseholdHolder
{
    /**
     * Found by name on the household, or created; then the household's one primary.
     *
     * @param  array<string, mixed>  $attributes  written only when the person is created, never `is_primary`
     */
    public static function of(Household $household, string $name, array $attributes = []): Resident
    {
        unset($attributes['is_primary']);

        $resident = Resident::query()->firstOrCreate(
            ['household_id' => $household->id, 'full_name' => $name],
            ['relationship' => 'owner', ...$attributes, 'is_primary' => false],
        );

        Resident::query()->where('household_id', $household->id)->whereKeyNot($resident->id)->update(['is_primary' => false]);
        $resident->forceFill(['is_primary' => true, 'removed_at' => null])->save();

        return $resident;
    }
}
