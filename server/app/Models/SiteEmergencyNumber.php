<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A number a panic at this estate is texted to (Q-M09).
 *
 * Four rows for Phoenix Park Village 1 — the President, the Property Office, the
 * PPV1 Head of Security and Gemini Dispatch — seeded from the client's own ruling and
 * editable afterwards without a developer. Another estate has different people, which
 * is the whole reason these are rows and not a constant.
 *
 * CENTRAL, beside `duress_alerts` and `estate_assignments`: the alert is central, and
 * the queued job that does the texting runs with no estate open.
 *
 * `number` IS WHAT SOMEBODY TYPED and is never rewritten. `e164` is the diallable
 * form worked out from it by `Support\JamaicanNumber` and kept beside it, so the
 * platform's reading of a number is visible rather than silent. A null `e164` is a row
 * the platform cannot dial, and it is texted to nobody and recorded as `unroutable`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $role
 * @property string $label
 * @property string $number what the office typed, character for character
 * @property string|null $e164 the diallable form, or null when it cannot be worked out
 * @property bool $is_active
 * @property-read string|null $active_e164 generated: `e164` while the row is active, else null — unique per site, so one phone is texted once (bug hunt B101). Never written.
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 *
 * @mixin \Eloquent
 */
class SiteEmergencyNumber extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id',
        'role',
        'label',
        'number',
        'e164',
        'is_active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Whether this row can actually be texted. */
    public function isRoutable(): bool
    {
        return $this->e164 !== null && $this->e164 !== '';
    }
}
