<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One decision this estate made about one catalogue feature — board 23.
 *
 * A ROW IS AN OVERRIDE, NOT A STATE. The absence of a row is not "off": it is
 * "this community has not decided, so the plan's answer stands". That is why
 * nothing provisions a row per feature, and why `App\Services\Estate\Settings`
 * resolves the effective answer rather than reading it.
 *
 * `feature_key` is `package_features.key` from the CENTRAL catalogue, carried
 * as a string because no foreign key can cross from an estate database to
 * gs_platform — the estate's MySQL user holds no grant there at all, which is
 * the isolation guarantee rather than an inconvenience. A key the catalogue no
 * longer offers is dropped on read, so a withdrawn feature cannot leave a
 * switch on screen for something that no longer exists.
 *
 * @property int $id
 * @property string $feature_key
 * @property bool $enabled
 * @property int|null $changed_by
 * @property string|null $changed_by_name
 * @property string $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EstateFeature extends Model
{
    protected $fillable = [
        'feature_key',
        'enabled',
        'changed_by',
        'changed_by_name',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /** "Turned off by Tracey Reid on Sep 4, 2026" — the caption under a switch. */
    public function provenance(): string
    {
        $verb = $this->enabled ? 'Turned on' : 'Turned off';
        $who = $this->changed_by_name ?? 'an administrator';
        $when = $this->updated_at ?? $this->created_at;

        return $when === null
            ? "{$verb} by {$who}"
            : "{$verb} by {$who} on ".$when->format('M j, Y');
    }
}
