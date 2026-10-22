<?php

declare(strict_types=1);

use App\Console\Commands\GateLiveHttp;
use App\Models\Tenant;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Live\Support\LiveAuthFixture;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| gate:live-http: the doors over HTTP, with throwaway principals that never stay (DESIGN.md §10.2)
|--------------------------------------------------------------------------
|
| Against a running server its principals must be real committed rows, so the one thing that must
| never go wrong is the clean-up: every `live-probe-*` row goes, even after a run that was killed,
| and the gate says so. Here the "server" is this application, reached through the HTTP client and
| answered in process, so the whole gate runs end to end, cookie, session store and all.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    tenancy()->end();

    LiveAuthFixture::credentials();

    $this->other = LiveAuthFixture::twinEstate('httptwin');

});

/** Every call the command makes over HTTP is handled here, by the application itself. */
function liveHttpServer(): void
{
    Http::fake(function (ClientRequest $request) {
        $cookies = [];

        foreach (explode(';', (string) ($request->header('Cookie')[0] ?? '')) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($name !== '') {
                $cookies[$name] = urldecode($value);
            }
        }

        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

        foreach (['Authorization', 'Sec-Fetch-Site', 'Origin'] as $header) {
            if ($request->hasHeader($header)) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $header))] = $request->header($header)[0];
            }
        }

        app('auth')->forgetGuards();

        $response = app(HttpKernel::class)->handle(Request::create($request->url(), $request->method(), [], $cookies, [], $server, $request->body()));

        app('auth')->forgetGuards();
        tenancy()->end();

        return Http::response((string) $response->getContent(), $response->getStatusCode());
    });
}

afterEach(function () {
    DB::connection('mysql')->table('tenants')->where('id', $this->other)->delete();
    tenancy()->end();
});

function liveHttpLeft(): int
{
    $db = DB::connection('mysql');

    return $db->table('users')->where('name', 'like', GateLiveHttp::MARK.'%')->count()
        + $db->table('resident_accounts')->where('full_name', 'like', GateLiveHttp::MARK.'%')->count()
        + $db->table('guards')->where('full_name', 'like', GateLiveHttp::MARK.'%')->count()
        + $db->table('personal_access_tokens')->where('name', 'like', GateLiveHttp::MARK.'%')->count();
}

it('passes end to end over HTTP against two estates, and leaves no live-probe row behind', function () {
    liveHttpServer();

    $exit = Artisan::call('gate:live-http', ['--estate' => FacilitiesFixture::ESTATE, '--other' => $this->other, '--url' => config('app.url')]);
    $output = Artisan::output();

    expect($output)->not->toContain('FAIL')
        ->and($output)->toContain('CONTROL - an active user is granted their own channel over HTTP')
        ->and($output)->toContain('LIVE HTTP GATE PASSED')
        ->and($exit)->toBe(0)
        ->and(liveHttpLeft())->toBe(0);
});

it('removes a leftover live-probe row from a run that was killed, first, and says so', function () {
    liveHttpServer();

    DB::connection('mysql')->table('guards')->insert([
        'full_name' => GateLiveHttp::MARK.'killed', 'employee_number' => 'LIVE-PROBE-KILLED', 'psra_number' => 'LIVE-PROBE-KILLED',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('gate:live-http', ['--estate' => FacilitiesFixture::ESTATE, '--other' => $this->other]);

    expect(Artisan::output())->toContain('Removed 1 live-probe row(s) left by an earlier run')
        ->and(liveHttpLeft())->toBe(0);
});

it('fails, and still cleans up, when the server refuses its positive control', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'nope']], 403)]);

    $exit = Artisan::call('gate:live-http', ['--estate' => FacilitiesFixture::ESTATE, '--other' => $this->other]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('FAIL CONTROL')
        ->and(liveHttpLeft())->toBe(0);
});
