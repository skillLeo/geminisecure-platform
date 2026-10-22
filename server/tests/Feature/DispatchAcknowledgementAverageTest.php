<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\DuressAlert;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\Dispatch\LiveMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| The live map's "Avg. acknowledgement time, 30d", summed by the database — M11 A2
|--------------------------------------------------------------------------
|
| The live map polls every five seconds for every dispatcher watching it, and its fourth
| card loaded every acknowledged alert of the last thirty days as a model to average two
| columns. It now asks the database for the count and the summed seconds. The figure must
| be the one the old reading gave: whole seconds from firing to acknowledgement, averaged
| and rounded, drills and older alerts left out, an em dash — never a zero — when nothing
| has been acknowledged.
|
*/

beforeEach(function (): void {
    DB::connection('mysql')->beginTransaction();

    $this->suffix = Str::lower(Str::random(5));
    $this->estate = 'ackavg'.$this->suffix;

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->estate, 'name' => 'Ack Estate '.$this->suffix, 'parish' => 'St. Andrew', 'status' => 'active',
        'demo' => 0, 'created_at' => now(), 'updated_at' => now(), 'data' => '{}',
    ]);

    // A viewer who watches this estate alone, so nothing else on the platform moves the figure.
    $role = Role::findOrCreate('test.ack_average_scoped', 'web');
    $role->forceFill(['scope_default' => AccessScope::AssignedSites->value])->save();

    $this->viewer = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $this->viewer->syncRoles([$role->name]);
    EstateAssignment::create(['user_id' => $this->viewer->id, 'tenant_id' => $this->estate, 'role_id' => $role->id, 'is_active' => true]);
    $this->viewer = $this->viewer->fresh();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

function ackAlert(string $estate, int $minutesAgo, ?int $ackAfterSeconds, bool $drill = false): void
{
    $fired = now()->startOfSecond()->subMinutes($minutesAgo);

    DuressAlert::query()->forceCreate([
        'tenant_id' => $estate, 'kind' => 'panic', 'mode' => 'audible', 'raised_by_name' => 'Lot 1 household',
        'status' => $ackAfterSeconds === null ? 'open' : 'acknowledged', 'device_time' => $fired, 'server_time' => $fired,
        'acknowledged_at' => $ackAfterSeconds === null ? null : $fired->copy()->addSeconds($ackAfterSeconds),
        'clock_skewed' => false, 'captured_offline' => false, 'idempotency_key' => 'ack-'.Str::random(12),
        'is_simulated' => false, 'is_drill' => $drill,
    ]);
}

function ackCard(User $viewer): string
{
    return collect((new LiveMap)->forViewer($viewer)['kpis'])->firstWhere('label', 'Avg. acknowledgement time, 30d')['value'];
}

it('reads an em dash, never a zero, when nothing in the window was acknowledged', function (): void {
    ackAlert($this->estate, 10, null);
    ackAlert($this->estate, 60 * 24 * 40, 30);   // forty days ago: outside the window
    ackAlert($this->estate, 5, 12, drill: true);  // a drill says nothing about response

    expect(ackCard($this->viewer))->toBe('—');
});

it('averages whole seconds to acknowledgement over the window, as the model-by-model reading did', function (): void {
    foreach ([40, 100, 185, 61] as $i => $seconds) {
        ackAlert($this->estate, 30 + $i, $seconds);
    }

    ackAlert($this->estate, 12, 3600, drill: true);
    ackAlert($this->estate, 60 * 24 * 31, 7200);
    ackAlert($this->estate, 3, null);

    // (40 + 100 + 185 + 61) / 4 = 96.5, rounded to 97: 1m 37s.
    expect(ackCard($this->viewer))->toBe('1m 37s');

    // The oracle: the models, loaded and averaged the way the card used to.
    $old = DuressAlert::query()
        ->where('tenant_id', $this->estate)->where('is_drill', false)->whereNotNull('acknowledged_at')
        ->where('server_time', '>=', now()->subDays(30))->get()
        ->map(fn (DuressAlert $a): int => (int) $a->server_time->diffInSeconds($a->acknowledged_at));
    $average = (int) round($old->avg());

    expect(ackCard($this->viewer))->toBe(intdiv($average, 60).'m '.($average % 60).'s');
});

it('says seconds alone under a minute', function (): void {
    ackAlert($this->estate, 20, 38);
    ackAlert($this->estate, 25, 41);

    expect(ackCard($this->viewer))->toBe('40s');
});

it('asks one aggregate of the alerts table for the card, however many alerts the month held', function (): void {
    foreach (range(1, 30) as $i) {
        ackAlert($this->estate, 10 + $i, 20 + $i);
    }

    $map = QueryBudget::measure(fn () => (new LiveMap)->forViewer($this->viewer));

    $alertReads = collect($map['statements'])->filter(static fn (array $s): bool => str_contains($s['sql'], 'from `duress_alerts`'));

    // Open alerts for the banner and pins, and the one aggregate for the card.
    expect($alertReads->count())->toBe(2)
        ->and($alertReads->contains(static fn (array $s): bool => str_contains($s['sql'], 'SUM(UNIX_TIMESTAMP(acknowledged_at)')))->toBeTrue();
});
