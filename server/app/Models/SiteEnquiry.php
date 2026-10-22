<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * What the public site's contact form or Apply page sent (M14, D-194). Central; written once, never edited.
 *
 * @property int $id
 * @property string $kind `contact` or `application`
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $message
 * @property array<string, string|null>|null $details
 * @property string $ip_hash
 * @property Carbon $created_at
 */
class SiteEnquiry extends Model
{
    use CentralConnection;

    public const CONTACT = 'contact';

    public const APPLICATION = 'application';

    public const UPDATED_AT = null;

    protected $fillable = ['kind', 'name', 'email', 'phone', 'message', 'details', 'ip_hash'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }
}
