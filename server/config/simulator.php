<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The event simulator
|--------------------------------------------------------------------------
|
| `/simulator` fires Guard App events through the real /api/v1 endpoints so
| the dispatch screens can be exercised before a handset exists. It is OFF
| unless this flag is set, and when it is off the route is not registered at
| all — not gated, not hidden: absent. A production host with the flag unset
| answers 404, the same as for a URL that was never built.
|
*/

return [
    'enabled' => (bool) env('GS_SIMULATOR_ENABLED', false),
];
