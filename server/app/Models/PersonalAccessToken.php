<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A handset's token, always in the platform database (13 D1).
 *
 * Sanctum's own model reads whatever the default connection is. Once a request
 * has opened an estate, that is the estate's database — which has no tokens —
 * and in a test process or a long-lived worker the next lookup lands there too.
 * Tokens belong to guards and resident accounts, both central, so the model is
 * pinned to the central connection.
 */
class PersonalAccessToken extends SanctumToken
{
    use CentralConnection;
}
