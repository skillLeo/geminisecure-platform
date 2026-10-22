<?php

declare(strict_types=1);

use App\Models\DuressAlert;
use App\Models\Tenant;
use App\Services\Ring\RingPayload;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| THE MESSAGES THE PHONE PARSES, TO THE BYTE — the guard ring §6, §15.1
|--------------------------------------------------------------------------
|
| Each message the ring sends is built by `RingPayload` and must equal its fixture in
| `tests/Fixtures/ring/`. The Guard App's ring module parses the same files, kept
| byte-identical in the mobile repository at `apps/guard/modules/guard-ring/spec/contract/`
| — checked here when that checkout is beside this one (or named by GS_MOBILE_CHECKOUT),
| and skipped, saying so, when it is not.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->forceFill(['timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();

    // Alert 4812, a medical emergency at Lot 47, Phoenix Park, raised at 2:11 AM Kingston time.
    $this->raised = Carbon::parse('2026-09-26T07:11:00Z');
    $this->alert = (new DuressAlert)->forceFill([
        'id' => 4812, 'tenant_id' => FacilitiesFixture::ESTATE, 'kind' => 'medical', 'unit_reference' => 'Lot 47',
        'server_time' => $this->raised, 'is_drill' => false, 'status' => 'open',
    ]);
    $this->alert->setRelation('estate', Tenant::query()->findOrFail(FacilitiesFixture::ESTATE));

    $this->fixture = fn (string $name): array => json_decode((string) file_get_contents(base_path('tests/Fixtures/ring/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->to = fn (array $message): array => ['to' => 'ExponentPushToken[fixture]', ...$message];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

it('builds every message exactly as its fixture', function () {
    expect(($this->to)(RingPayload::siteRing($this->alert, 1, 5, '<ticket>', $this->raised->copy()->addSeconds(61))))->toBe(($this->fixture)('site_ring'))
        ->and(($this->to)(RingPayload::ringStop($this->alert, 'responding', 'Marcus Grant')))->toBe(($this->fixture)('ring_stop'))
        ->and(($this->to)(RingPayload::ringTest('test-9f2c41d07a3b88e1', 'Phoenix Park', '<ticket>')))->toBe(($this->fixture)('ring_test'))
        ->and(($this->to)(RingPayload::visibleRing($this->alert, 're_ring', $this->raised->copy()->addSeconds(180))))->toBe(($this->fixture)('visible_ring'))
        ->and(($this->to)(RingPayload::visibleStop($this->alert, 'responding', 'Marcus Grant')))->toBe(($this->fixture)('visible_stop'));
});

it('never puts a title, a message or a body in a data-only message — Expo would present a banner of its own', function (string $name) {
    $message = ($this->fixture)($name);

    expect(array_intersect(array_keys($message), ['title', 'body', 'sound', 'channelId', 'tag', 'badge', 'interruptionLevel', 'contentAvailable']))->toBe([])
        ->and(array_intersect(array_keys($message['data']), ['title', 'message', 'body']))->toBe([])
        ->and($message['priority'])->toBe('high');
})->with(['site_ring', 'ring_stop', 'ring_test']);

it('keeps the fixtures byte-identical to the Guard App\'s own copy', function () {
    $mobile = (string) (env('GS_MOBILE_CHECKOUT') ?: base_path('../geminisecure-mobile'));
    $theirs = $mobile.'/apps/guard/modules/guard-ring/spec/contract';

    if (! is_dir($theirs)) {
        $this->markTestSkipped('The Guard App\'s ring contract is not beside this checkout ('.$theirs.'). Set GS_MOBILE_CHECKOUT to check it.');
    }

    foreach (['site_ring', 'ring_stop', 'ring_test', 'visible_ring', 'visible_stop'] as $name) {
        expect(is_file($theirs.'/'.$name.'.json'))->toBeTrue($name.'.json is missing from the Guard App\'s contract')
            ->and((string) file_get_contents($theirs.'/'.$name.'.json'))->toBe((string) file_get_contents(base_path('tests/Fixtures/ring/'.$name.'.json')), $name.'.json differs from the Guard App\'s copy');
    }
});
