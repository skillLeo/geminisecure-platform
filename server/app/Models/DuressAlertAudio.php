<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One piece of a panic's live audio (M50 §4.2 #1). See the `a_panic_can_carry_live_audio_from_the_residents_phone`
 * migration. The file is on the central private `panic_audio` disk at `path`; nothing serves it but the dispatch console.
 *
 * @property int $id
 * @property int $duress_alert_id
 * @property string $tenant_id
 * @property int $seq
 * @property string $path
 * @property string $mime
 * @property int $bytes
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
class DuressAlertAudio extends Model
{
    use CentralConnection;

    protected $table = 'duress_alert_audio';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['seq' => 'integer', 'bytes' => 'integer', 'duration_ms' => 'integer'];
    }
}
