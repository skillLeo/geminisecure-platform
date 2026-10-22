<?php

declare(strict_types=1);

use App\Console\Commands\PhoenixClassifyTestAlerts;
use App\Jobs\Push\SendExpoPush;
use App\Models\DuressAlert;
use App\Models\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| phoenix:classify-test-alerts: the test presses from one household, classified as tests and kept (DR-017; M73 §2.3)
|--------------------------------------------------------------------------
|
| A dry run unless told --confirm; each open alert at the household closed as a dispatcher closes one, "Test or drill",
| with a note; nothing deleted; nobody told; an alert at another household, and one already closed, not touched.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    Bus::fake([SendExpoPush::class]);
    Notification::fake();

    FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $alert = static fn (string $unit, string $status = 'open', bool $closed = false): DuressAlert => DuressAlert::withoutEvents(static fn (): DuressAlert => DuressAlert::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'kind' => 'panic', 'raised_by_name' => 'Test household', 'unit_reference' => $unit,
        'status' => $status, 'resolved_at' => $closed ? now()->subHour() : null, 'server_time' => now()->subHours(3), 'idempotency_key' => 'classify-test-'.uniqid('', true),
    ]));

    $this->tests = [$alert('Lot 9861'), $alert('Lot 9861', 'acknowledged'), $alert('Lot 9861')];
    $this->other = $alert('Lot 9862');
    $this->done = $alert('Lot 9861', 'resolved', true);
    $this->total = DuressAlert::query()->count();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

it('classifies nothing without --confirm, and names each alert it would', function (): void {
    expect(Artisan::call('phoenix:classify-test-alerts', ['--estate' => FacilitiesFixture::ESTATE, '--unit' => 'Lot 9861']))->toBe(0);
    $said = Artisan::output();

    foreach ($this->tests as $test) {
        expect($said)->toContain('#'.$test->id)
            ->and($test->fresh()->resolved_at)->toBeNull();
    }

    expect($said)->toContain('Nothing is written without --confirm')->not->toContain('#'.$this->other->id);
});

it('closes the open presses at the household as "Test or drill", keeps every row, tells nobody, and touches nothing else', function (): void {
    expect(Artisan::call('phoenix:classify-test-alerts', ['--estate' => FacilitiesFixture::ESTATE, '--unit' => 'Lot 9861', '--confirm' => true]))->toBe(0);

    foreach ($this->tests as $test) {
        $row = $test->fresh();

        expect($row->status)->toBe('resolved')
            ->and($row->resolution_classification)->toBe(PhoenixClassifyTestAlerts::CLASSIFICATION)
            ->and($row->resolution_note)->toBe(PhoenixClassifyTestAlerts::NOTE)
            ->and($row->acknowledged_by)->not->toBeNull();
    }

    expect($this->other->fresh()->resolved_at)->toBeNull()
        ->and($this->done->fresh()->resolution_classification)->toBeNull()
        ->and(DuressAlert::query()->count())->toBe($this->total);

    Bus::assertNotDispatched(SendExpoPush::class);
    Notification::assertNothingSent();

    expect(Artisan::call('phoenix:classify-test-alerts', ['--estate' => FacilitiesFixture::ESTATE, '--unit' => 'Lot 9861', '--confirm' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing open to classify');
});

it('refuses an estate it does not know, and changes nothing', function (): void {
    expect(Artisan::call('phoenix:classify-test-alerts', ['--estate' => 'no-such-estate', '--confirm' => true]))->not->toBe(0)
        ->and(Artisan::output())->toContain('Nothing was changed');
});
