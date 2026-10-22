<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| /api/v1 - the mobile client API
|--------------------------------------------------------------------------
|
| REGISTERED FROM THE CATALOGUE, AND ONLY FROM IT (13 D1). Every route's path,
| method, middleware and ability comes from its entry in `App\Api\Catalogue`,
| and the ability is COMPUTED — `AppMatrix::ability(capability, access)` — never
| written here. So an endpoint cannot exist without being described, cannot ask
| for an ability no app holds, and cannot drift from MOBILE_HANDOFF.md, which is
| written from the same entries.
|
| The middleware each authenticated route carries, in order:
|
|   auth:sanctum            a handset token
|   ability:{cap}:{access}  the token carries the ability the matrix gives this app
|   device:{apps}           the principal is the right app's, active, and its
|                           estate is opened — the estate comes from the token
|   level                   Resident App routes: the household member's access
|                           level (Member, Dependent, Helper) includes this one
|   throttle:{limiter}      per device, not per address
|   idempotent              writes: a retry is answered, never repeated
|   device.time             writes: the handset's clock beside the server's
|
| INVARIANT 2 still holds and is now checked, not only argued: no endpoint a
| guard's token reaches returns a household's financial position. Each route's
| `response` in the catalogue is its allowlist, and the guard contract test
| fails on any key not listed.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    foreach (Catalogue::endpoints() as $name => $endpoint) {
        $middleware = [];

        if ($endpoint['capability'] !== null) {
            $middleware[] = 'auth:sanctum';
            $middleware[] = 'ability:'.AppMatrix::ability($endpoint['capability'], $endpoint['access']);
            $middleware[] = 'device:'.implode(',', [...$endpoint['apps'], ...(($endpoint['pending'] ?? false) ? ['pending'] : [])]);

            // The household member's access level (`AppMatrix::LEVELS`), on every route a Resident App token reaches.
            if (in_array(AppMatrix::RESIDENT, $endpoint['apps'], true)) {
                $middleware[] = 'level';
            }
        }

        $middleware[] = 'throttle:'.$endpoint['throttle'];

        if ($endpoint['write']) {
            $mode = ($endpoint['legacy'] ?? false) ? ':optional' : '';
            $middleware[] = 'idempotent'.(($endpoint['opaque'] ?? false) ? ':opaque' : $mode);
            $middleware[] = 'device.time'.$mode;
        }

        Route::match([$endpoint['method']], $endpoint['uri'], $endpoint['action'])
            ->name($name)
            ->where($endpoint['where'] ?? [])
            ->middleware($middleware);
    }
});

/*
|--------------------------------------------------------------------------
| /api/webhooks - what a third party posts to us (M16 §2.2)
|--------------------------------------------------------------------------
|
| NOT the mobile client API, so not in the catalogue: no handset calls these and
| no token reaches them. The card provider's webhook carries no session and no
| token — its SIGNATURE is its credential, and PaymentWebhookController checks it
| over the raw body before reading anything else. Limited per address, generously:
| a provider sends bursts on dues day and retries whatever is not answered.
|
*/

Route::post('webhooks/payments/{provider}/{estate}', PaymentWebhookController::class)
    ->where(['provider' => '[a-z0-9_-]{1,32}', 'estate' => '[a-z][a-z0-9]{2,29}'])
    ->middleware('throttle:payment-webhooks')
    ->name('api.webhooks.payments');
