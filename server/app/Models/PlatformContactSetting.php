<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Gemini Security's own contact details as the console sets them — one row. See the
 * `gemini_security_s_phone_line_is_a_console_setting` migration and App\Services\Platform\SupportLine.
 *
 * @property int $id
 * @property string|null $support_phone international form (E.164), or null while none is set
 * @property int|null $updated_by
 * @property string|null $updated_by_name
 * @property Carbon|null $updated_at
 */
class PlatformContactSetting extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /** The one row, or an unsaved empty one. */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }
}
