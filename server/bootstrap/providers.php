<?php

use App\Providers\AppServiceProvider;
use App\Providers\LiveServiceProvider;
use App\Providers\PushServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    LiveServiceProvider::class,
    PushServiceProvider::class,
    RateLimitServiceProvider::class,
    TenancyServiceProvider::class,
];
