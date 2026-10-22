<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Models\User;
use App\Services\Tracking\GuardTracking;
use App\Services\Tracking\SiteTrackingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| How each estate's officers are sampled, and its no signal tolerance (Hassam, 12 October 2026)
|--------------------------------------------------------------------------
|
| One store and one reader for the phone, the no signal watch, the Gemini map and the estate's Settings page: an estate
| that never chose has the platform's defaults (config/tracking.php); many estates are read in ONE query; a choice is
| kept within bounds that keep the officers' consent words true ("about once a minute while you move, less often while
| you stand still or your battery is low"); every save that changes something is audited, and nothing is written when
| it is refused.
|
*/

function siteTrackingAdmin(): User
{
    return User::query()->create([
        'name' => 'Dahlia Reid',
        'email' => 'site-tracking-'.uniqid().'@example.test',
        'password' => 'password',
        'console' => Console::Estate->value,
        'status' => 'active',
    ]);
}

it('answers the platform defaults for an estate that never chose, its own values where it did, many in one query', function () {
    config([
        'tracking.sampling.interval_s' => 45,
        'tracking.sampling.stationary_interval_s' => 180,
        'tracking.sampling.low_battery_pct' => 20,
        'tracking.sampling.low_battery_interval_s' => 180,
        'tracking.no_signal_after_seconds' => 300,
    ]);

    SiteTrackingSettings::save('estatea', ['moving_interval_s' => 60, 'no_signal_after_s' => 600], siteTrackingAdmin());

    DB::connection('mysql')->enableQueryLog();
    DB::connection('mysql')->flushQueryLog();

    $many = SiteTrackingSettings::forMany(['estatea', 'estateb', 'estatea']);

    expect(DB::connection('mysql')->getQueryLog())->toHaveCount(1)
        ->and(array_keys($many))->toBe(['estatea', 'estateb'])
        ->and($many['estatea'])->toBe([
            'moving_interval_s' => 60,
            'stationary_interval_s' => 180,
            'low_battery_pct' => 20,
            'low_battery_interval_s' => 180,
            'no_signal_after_s' => 600,
        ])
        ->and($many['estateb'])->toBe(SiteTrackingSettings::defaults())
        ->and(SiteTrackingSettings::for('estatea'))->toBe($many['estatea'])
        ->and(SiteTrackingSettings::forMany([]))->toBe([]);

    // What the estate chose itself, and null where it follows the default: a default moved later reaches it.
    expect(SiteTrackingSettings::chosen('estatea'))->toBe([
        'moving_interval_s' => 60,
        'stationary_interval_s' => null,
        'low_battery_pct' => null,
        'low_battery_interval_s' => null,
        'no_signal_after_s' => 600,
    ]);

    config(['tracking.sampling.stationary_interval_s' => 240]);

    expect(SiteTrackingSettings::for('estatea')['stationary_interval_s'])->toBe(240);
});

it('records who changed what, once, and nothing for a save that changes nothing', function () {
    $admin = siteTrackingAdmin();

    SiteTrackingSettings::save('estatec', ['low_battery_pct' => 15], $admin);
    SiteTrackingSettings::save('estatec', ['low_battery_pct' => 15], $admin);

    $entries = AuditEntry::query()->where('action', 'estate.settings.guard_tracking_updated')->where('entity_id', 'estatec')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_id)->toBe($admin->id)
        ->and($entries[0]->before['low_battery_pct'])->toBeNull()
        ->and($entries[0]->after['low_battery_pct'])->toBe(15);

    // Back to the platform's default: null again, and audited.
    SiteTrackingSettings::save('estatec', ['low_battery_pct' => null], $admin);

    expect(SiteTrackingSettings::chosen('estatec')['low_battery_pct'])->toBeNull()
        ->and(AuditEntry::query()->where('action', 'estate.settings.guard_tracking_updated')->where('entity_id', 'estatec')->count())->toBe(2);
});

it('refuses what would make the consent words untrue, before anything is written', function (array $input, string $words) {
    $admin = siteTrackingAdmin();

    expect(fn () => SiteTrackingSettings::save('estated', $input, $admin))->toThrow(DomainException::class, $words);

    expect(DB::connection('mysql')->table('site_tracking_settings')->where('tenant_id', 'estated')->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('entity_id', 'estated')->exists())->toBeFalse();
})->with([
    // In words true under both versions of the consent ("about once a minute", then "every 45 to 90 seconds").
    'faster than about once a minute' => [['moving_interval_s' => 30], 'Choose between 45 and 90 seconds while moving: that keeps to what the officers were told when they agreed to share their position.'],
    'slower than about once a minute' => [['moving_interval_s' => 120], 'Choose between 45 and 90 seconds while moving'],
    'a low battery line out of reach' => [['low_battery_pct' => 50], 'Choose a low battery line between 10 and 30 percent'],
    'a tolerance too short' => [['no_signal_after_s' => 60], 'Choose between 2 and 30 minutes before "No signal"'],
    'standing still sampled too often' => [['stationary_interval_s' => 100], 'Choose between 2 and 15 minutes'],
]);

it('tells an officer\'s phone its estate\'s sampling: the shift in progress, else the next within a day, else the defaults', function () {
    foreach (['samplea', 'sampleb'] as $estate) {
        DB::table('tenants')->updateOrInsert(['id' => $estate], ['name' => 'Sample '.$estate, 'data' => json_encode(['name' => 'Sample '.$estate]), 'created_at' => now(), 'updated_at' => now()]);
    }

    $guard = Guard::create([
        'full_name' => 'Sampled Guard', 'employee_number' => 'GS-SMP-1', 'psra_number' => 'PSRA-SMP-1',
        'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => 'samplea',
    ]);
    $postA = Post::create(['tenant_id' => 'samplea', 'name' => 'Gate A', 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0179, 'longitude' => -76.8099, 'geofence_radius_m' => 60]);
    $postB = Post::create(['tenant_id' => 'sampleb', 'name' => 'Gate B', 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0179, 'longitude' => -76.8099, 'geofence_radius_m' => 60]);
    $tracking = app(GuardTracking::class);
    $defaults = SiteTrackingSettings::defaults();

    // No shift at all: the platform's defaults, under the keys the Guard App has always read.
    expect($tracking->status($guard)['sampling'])->toMatchArray([
        'interval_s' => $defaults['moving_interval_s'],
        'stationary_interval_s' => $defaults['stationary_interval_s'],
        'low_battery_pct' => $defaults['low_battery_pct'],
        'low_battery_interval_s' => $defaults['low_battery_interval_s'],
    ]);

    // Still every 5 minutes, so "No signal" no sooner than 7 (Hassam's rule).
    SiteTrackingSettings::save('samplea', ['moving_interval_s' => 75, 'stationary_interval_s' => 300, 'no_signal_after_s' => 420], siteTrackingAdmin());

    // The next rostered shift, within a day, at estate A: estate A's own.
    Shift::create(['tenant_id' => 'samplea', 'guard_id' => $guard->id, 'post_id' => $postA->id, 'rostered_start' => now()->addHours(3), 'rostered_end' => now()->addHours(15), 'status' => Shift::ROSTERED]);

    expect($tracking->status($guard)['sampling'])->toMatchArray(['interval_s' => 75, 'stationary_interval_s' => 300]);

    // Clocked in at estate B, which never chose: the shift in progress wins, and B follows the defaults.
    Shift::create(['tenant_id' => 'sampleb', 'guard_id' => $guard->id, 'post_id' => $postB->id, 'rostered_start' => now()->subHour(), 'rostered_end' => now()->addHours(11), 'actual_start' => now()->subMinutes(50), 'status' => 'on_duty']);

    expect($tracking->status($guard)['sampling']['interval_s'])->toBe($defaults['moving_interval_s']);
});

it('refuses a "No signal" time sooner than the slowest reading plus two minutes, saying the consequence', function (array $input, string $words) {
    config(['tracking.sampling.stationary_interval_s' => 180, 'tracking.sampling.low_battery_interval_s' => 180, 'tracking.no_signal_after_seconds' => 300]);

    expect(fn () => SiteTrackingSettings::save('estatef', $input, siteTrackingAdmin()))->toThrow(DomainException::class, $words);

    expect(DB::connection('mysql')->table('site_tracking_settings')->where('tenant_id', 'estatef')->exists())->toBeFalse();
})->with([
    // Payroll's two cases: a 15 minute reading against a 2 minute tolerance, and a 5 minute one against the 5 minute default.
    'still every 15 minutes against 2' => [['stationary_interval_s' => 900, 'no_signal_after_s' => 120], 'Readings every 15 minutes, so “No signal” cannot be raised before 17 minutes of silence. Choose 17 minutes or longer'],
    'low battery every 5 minutes against the 5 minute default' => [['low_battery_interval_s' => 300], 'Readings every 5 minutes, so “No signal” cannot be raised before 7 minutes of silence. Choose 7 minutes or longer'],
]);

it('takes a "No signal" time of exactly the slowest reading plus two minutes', function () {
    config(['tracking.sampling.stationary_interval_s' => 180, 'tracking.no_signal_after_seconds' => 300]);

    SiteTrackingSettings::save('estateg', ['low_battery_interval_s' => 300, 'no_signal_after_s' => 420], siteTrackingAdmin());

    expect(SiteTrackingSettings::for('estateg')['no_signal_after_s'])->toBe(420)
        ->and(SiteTrackingSettings::slowestReading(SiteTrackingSettings::for('estateg')))->toBe(300);
});

it('never reads a "No signal" time sooner than the slowest reading plus two minutes, whatever a default later says', function () {
    config(['tracking.sampling.stationary_interval_s' => 180, 'tracking.sampling.low_battery_interval_s' => 180, 'tracking.no_signal_after_seconds' => 300]);

    SiteTrackingSettings::save('estateh', ['no_signal_after_s' => 300], siteTrackingAdmin());

    // A default moved later: standing still every 10 minutes. The stored 5 minutes is lifted to 12 on every read.
    config(['tracking.sampling.stationary_interval_s' => 600]);

    expect(SiteTrackingSettings::for('estateh')['no_signal_after_s'])->toBe(720)
        ->and(SiteTrackingSettings::forMany(['estateh', 'nobody'])['nobody']['no_signal_after_s'])->toBe(720);
});

it('refuses a moving rate no faster than standing still would be, even where standing still is the platform default', function () {
    // Within the bounds the two can never cross; a default set outside them can, so the rule is asked of what applies.
    config(['tracking.sampling.stationary_interval_s' => 60]);

    expect(fn () => SiteTrackingSettings::save('estatee', ['moving_interval_s' => 90], siteTrackingAdmin()))
        ->toThrow(DomainException::class, 'Standing still and low battery must each send less often than moving');

    expect(DB::connection('mysql')->table('site_tracking_settings')->where('tenant_id', 'estatee')->exists())->toBeFalse();
});
