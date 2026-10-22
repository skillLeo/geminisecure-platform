<?php

declare(strict_types=1);

use App\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;

/*
|--------------------------------------------------------------------------
| The Estate Console must load the same compiled bundle as everyone else
|--------------------------------------------------------------------------
|
| stancl/tenancy ships with tenancy.filesystem.asset_helper_tenancy switched
| ON. With it on, an initialized tenant rebinds the asset root so every
| asset() URL becomes /tenancy/assets/..., served from that estate's own
| storage disk.
|
| Laravel's Vite class builds its <script> and <link> URLs through asset().
| So the Estate Console asked for the Vue bundle and both stylesheets from a
| disk that has never held them, got three 404s, and rendered a blank page —
| on the production subdomain exactly as much as on the local path. The Gemini
| Console was fine only because tenancy is never initialized there, which made
| a shared-asset fault look like an estate-routing fault.
|
| These tests exercise the bootstrapper directly rather than booting a whole
| estate, because the bootstrapper is where the decision is made and it needs
| no tenant database to run. They pin both halves of the trade: application
| assets are shared, tenant-owned files stay isolated.
|
*/

/** An unsaved tenant is enough: the bootstrapper reads only the tenant key. */
function assetCheckTenant(): Tenant
{
    return (new Tenant)->forceFill(['id' => 'assetcheck']);
}

/** Run something with filesystem tenancy bootstrapped, then always revert. */
function withFilesystemTenancy(callable $callback): mixed
{
    $bootstrapper = app(FilesystemTenancyBootstrapper::class);
    $bootstrapper->bootstrap(assetCheckTenant());

    try {
        return $callback();
    } finally {
        $bootstrapper->revert();
    }
}

it('serves the compiled bundle from the shared build directory inside an estate', function () {
    $url = withFilesystemTenancy(fn () => asset('build/assets/app.js'));

    // The exact failure, asserted by name. If asset_helper_tenancy is ever
    // switched back on, this is the line that goes red.
    expect($url)->not->toContain('tenancy/assets');
    expect($url)->toContain('/build/assets/app.js');
});

it('produces the same asset URL inside an estate as outside one', function () {
    $outside = asset('build/assets/app.js');
    $inside = withFilesystemTenancy(fn () => asset('build/assets/app.js'));

    // The compiled bundle is byte-identical for every estate. Two estates
    // disagreeing about where it lives is the bug, not a feature.
    expect($inside)->toBe($outside);
});

it('still isolates tenant-owned files', function () {
    // The half we did NOT give up. Resident photos and uploaded documents are
    // estate property and must never be reachable from another estate's URL
    // space, so tenant_asset() stays tenant-scoped.
    $url = withFilesystemTenancy(fn () => tenant_asset('residents/photo.jpg'));

    expect($url)->toContain('tenancy/assets');
    expect($url)->toContain('residents/photo.jpg');
});

it('still gives each estate its own storage path', function () {
    $outside = storage_path('app');
    $inside = withFilesystemTenancy(fn () => storage_path('app'));

    // Disabling asset() tenancy must not have disabled filesystem tenancy.
    expect($inside)->not->toBe($outside);
    expect($inside)->toContain('tenantassetcheck');
});

it('restores the shared asset root when the estate is left', function () {
    $before = asset('build/assets/app.js');
    withFilesystemTenancy(fn () => asset('build/assets/app.js'));
    $after = asset('build/assets/app.js');

    // A leaked asset root would send the next request on this worker — very
    // possibly a Gemini Console request — to an estate's disk.
    expect($after)->toBe($before);
});
