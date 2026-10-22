<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\RoleEditor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| The audit log page (M84 §3.2), behind its switch (M84 §0.1)
|--------------------------------------------------------------------------
|
| Settings promised an immutable audit log and there was no page to read it (SET-2): /settings/audit was a 404 and
| /audit went to the dashboard. The page reads the log for THIS estate only: when, who, their role, what they did,
| what it was done to, before and after; filtered by person, date range and module; paged; exported. Only entries
| under an estate module the viewer can open: Gemini's own work at the estate stays on the Gemini console's log. Read
| only for every role: the table is append only and nothing here implies otherwise. With its switch off it is absent:
| no section in Settings, and its addresses answer 404. Gated by the estate's existing Settings permission (no
| permission added: the role grid is the committee's). An estate of its own, `auditpagetest`.
|
*/

function auditPageEstate(): OwnEstate
{
    static $estate = null;

    return $estate ??= new OwnEstate('auditpagetest', 'APT', 'Audit Page Test Estate');
}

beforeEach(function () {
    auditPageEstate()->boot();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    config(['estate_features.audit_log' => false]);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();

    // A role made in a test is rolled back with the rest; the permission cache must not outlive it.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** An entry on this estate's log, as the one sanctioned writer records it. */
function auditPageRecord(string $action, ?User $actor, array $before = [], array $after = [], ?string $tenantId = 'own'): void
{
    app(AuditLogger::class)->record(
        action: $action,
        entityType: 'Resident',
        entityId: '41',
        before: $before ?: null,
        after: $after ?: null,
        tenantId: $tenantId === 'own' ? (string) auditPageEstate()->tenant()->getTenantKey() : $tenantId,
        actor: $actor,
    );
}

it('is absent with its switch off: no section in Settings, and the page, its export and /audit say it is switched off', function () {
    $csa = auditPageEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);

    // A clean page saying so, never the bare 404 (Hassam, 12 Oct: a switched-off module's deep link says what it is).
    foreach (['/settings/audit', '/settings/audit/export', '/audit'] as $path) {
        $this->actingAs($csa)->get(auditPageEstate()->url($path))
            ->assertStatus(403)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Estate/NotInPlan')
                ->where('module', 'The audit log')
                ->where('switchedOff', true));
    }

    $this->actingAs($csa)->get(auditPageEstate()->url('/settings/profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sections', fn ($sections) => collect($sections)->doesntContain('key', 'audit')));
});

it('with its switch on: a section in Settings, and /audit goes to the page', function () {
    config(['estate_features.audit_log' => true]);
    $csa = auditPageEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);

    $this->actingAs($csa)->get(auditPageEstate()->url('/settings/profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sections', fn ($sections) => collect($sections)->contains(fn ($s) => $s['key'] === 'audit' && str_ends_with((string) $s['href'], '/settings/audit'))));

    $this->actingAs($csa)->get(auditPageEstate()->url('/audit'))->assertRedirect(auditPageEstate()->url('/settings/audit'));
});

it('shows a change made elsewhere at once, with the right person, their role, what was done, to what, before and after', function () {
    config(['estate_features.audit_log' => true]);
    $csa = auditPageEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);
    $president = auditPageEstate()->viewer(Role::PRESIDENT);

    // Made "in another tab": the President edits a record, and the log is read afterwards by somebody else.
    auditPageRecord('residents.edited', $president, ['email' => 'old@example.org'], ['email' => 'new@example.org']);

    // Another estate's entry and a platform-wide one never appear on this estate's page.
    $other = Tenant::query()->where('id', '!=', auditPageEstate()->tenant()->getTenantKey())->value('id');
    if ($other !== null) {
        auditPageRecord('residents.edited', $president, [], ['email' => 'elsewhere@example.org'], (string) $other);
    }
    auditPageRecord('platform.settings_changed', null, [], ['who' => 'platform'], null);

    $this->actingAs($csa)->get(auditPageEstate()->url('/settings/audit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Settings/Audit')
            ->has('rows', 1)
            ->where('rows.0.who', $president->name)
            ->where('rows.0.role', 'President')
            ->where('rows.0.target', 'Resident 41')
            ->where('rows.0.before', ['email: old@example.org'])
            ->where('rows.0.after', ['email: new@example.org'])
            ->where('rows.0.what', fn ($what) => is_string($what) && $what !== '')
            ->where('rows.0.when', fn ($when) => is_string($when) && $when !== ''));
});

it('filters by person and by date range, and pages twenty-five at a time', function () {
    config(['estate_features.audit_log' => true]);
    $csa = auditPageEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);
    $president = auditPageEstate()->viewer(Role::PRESIDENT);

    Carbon::setTestNow(Carbon::parse('2026-10-01 15:00:00'));
    auditPageRecord('residents.edited', $president);
    Carbon::setTestNow(Carbon::parse('2026-10-09 15:00:00'));
    foreach (range(1, 30) as $n) {
        auditPageRecord('residents.edited', $csa);
    }
    Carbon::setTestNow();

    $this->actingAs($csa)->get(auditPageEstate()->url('/settings/audit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 25)->where('table.total', 31));

    $this->actingAs($csa)->get(auditPageEstate()->url('/settings/audit?actor='.urlencode($president->name)))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.who', $president->name));

    $this->actingAs($csa)->get(auditPageEstate()->url('/settings/audit?from=2026-10-01&to=2026-10-02'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1));

    $this->actingAs($csa)->get(auditPageEstate()->url('/settings/audit?module=residents'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('table.total', 31)
            ->where('modules', [['key' => 'residents', 'label' => 'Residents']]));
    $this->actingAs($csa)->get(auditPageEstate()->url('/settings/audit?module=governance'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('table.total', 0));
});

it('keeps Gemini\'s own work at the estate off the page and its export, and any kind of entry not yet placed', function () {
    config(['estate_features.audit_log' => true]);
    $csa = auditPageEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);

    auditPageRecord('residents.edited', $csa, [], ['email' => 'shown@example.org']);
    foreach (['guard.created', 'roster.shift_posted', 'billing.invoice_raised', 'platform.rate_changed', 'panic.message_from_resident', 'device.enrolled', 'account.password_changed', 'something_new.done'] as $action) {
        auditPageRecord($action, null, [], ['note' => 'gemini-only '.$action]);
    }

    $this->actingAs($csa)->get(auditPageEstate()->url('/settings/audit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('table.total', 1)->where('rows.0.module', 'Residents'));

    $csv = $this->actingAs($csa)->get(auditPageEstate()->url('/settings/audit/export'))->streamedContent();
    expect(str_contains($csv, 'gemini-only'))->toBeFalse()
        ->and(str_contains($csv, 'shown@example.org'))->toBeTrue();
});

it('shows an entry only to a viewer who can open its module: a role with Settings and no money sees no charge run', function () {
    config(['estate_features.audit_log' => true]);
    $csa = auditPageEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);

    // A role the committee can build today (roles are the Community Super Admin's to make): Settings and Residents,
    // and no money module at all. No seeded role holds Settings without Dues & ledger, so this is the only way to ask.
    tenancy()->initialize(auditPageEstate()->tenant());
    try {
        $role = app(RoleEditor::class)->create($csa, 'Audit page reader', [
            'dashboard' => ['level' => 'view'],
            'settings' => ['level' => 'view'],
            'residents' => ['level' => 'view'],
        ]);
    } finally {
        tenancy()->end();
        auditPageEstate()->boot();
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $reader = auditPageEstate()->viewer($role->name);
    $vp = auditPageEstate()->viewer(Role::VICE_PRESIDENT);

    auditPageRecord('estate.charge_run_posted', $csa, [], ['total' => 'JMD 1,250,000.00']);
    auditPageRecord('residents.edited', $csa, [], ['email' => 'x@example.org']);

    $modulesOf = static fn ($rows): array => array_values(array_unique(array_column(collect($rows)->map(static fn ($row): array => collect($row)->all())->all(), 'module')));

    // A positive control: the Vice President, as the role grid draws the role, holds Dues & ledger and is shown it.
    $this->actingAs($vp)->get(auditPageEstate()->url('/settings/audit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', fn ($rows) => in_array('Dues & ledger', $modulesOf($rows), true)));

    // The reader: the charge run is gone from the page, the module filter, an address asking for it, and the export.
    $this->actingAs($reader)->get(auditPageEstate()->url('/settings/audit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows', fn ($rows) => ! in_array('Dues & ledger', $modulesOf($rows), true) && in_array('Residents', $modulesOf($rows), true))
            ->where('modules', fn ($modules) => collect($modules)->doesntContain('key', 'dues_ledger')));
    $this->actingAs($reader)->get(auditPageEstate()->url('/settings/audit?module=dues_ledger'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0)->where('table.total', 0));

    $csv = $this->actingAs($reader)->get(auditPageEstate()->url('/settings/audit/export'))->streamedContent();
    expect(str_contains($csv, '1,250,000'))->toBeFalse()
        ->and(str_contains($csv, 'x@example.org'))->toBeTrue();
});

it('exports this estate\'s log as a CSV with a header row, and nothing from elsewhere', function () {
    config(['estate_features.audit_log' => true]);
    $csa = auditPageEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);

    auditPageRecord('residents.edited', $csa, ['email' => 'a@example.org'], ['email' => 'b@example.org']);
    auditPageRecord('platform.settings_changed', null, [], ['who' => 'platform'], null);

    $response = $this->actingAs($csa)->get(auditPageEstate()->url('/settings/audit/export'))->assertOk();
    expect((string) $response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('.csv');

    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    // Read as a spreadsheet reads it: fields with spaces come quoted, as CSV quotes them.
    expect(str_getcsv($lines[0], ',', '"', ''))->toBe(['When', 'Who', 'Role', 'What they did', 'Done to', 'Before', 'After'])
        ->and(count($lines))->toBe(2)
        ->and($lines[1])->toContain($csa->name)
        ->and(implode("\n", $lines))->not->toContain('platform');
});

it('is read only for every role, and refused to a role without Settings', function () {
    config(['estate_features.audit_log' => true]);

    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'estate.settings.audit'))
        ->flatMap(fn ($route) => array_diff($route->methods(), ['GET', 'HEAD']));
    expect($writes->all())->toBe([]);

    $treasurer = auditPageEstate()->viewer(Role::TREASURER);
    $this->actingAs($treasurer)->get(auditPageEstate()->url('/settings/audit'))->assertForbidden();
});
