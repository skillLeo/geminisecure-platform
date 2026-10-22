<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\DuressAlert;
use App\Models\GateEvent;
use App\Models\Role;
use App\Models\SecurityIncident;
use App\Services\Estate\EstateSecurity;
use App\Support\NamedActs;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The estate's own Head of Security — M10 §2
|--------------------------------------------------------------------------
|
| TWO PEOPLE, TWO ORGANISATIONS. Gemini's Head of Security is a `gemini.*` role
| on the company console: it manages guards, standing orders and shift oversight
| across the sites it is assigned. This one is the COMMUNITY's — the committee
| member responsible for security at their own estate.
|
| WHAT IT SEES: panic alerts, incidents, gate activity, the visitor log, notices,
| and the guard presence the estate pays for.
|
| WHAT IT MUST NOT SEE, and what these tests are mostly about: money of any kind,
| and anything about a guard beyond their name and post. Guards are Gemini
| Security Limited's employees — an estate is entitled to "guards on site: N" and
| the alert states, and to nothing about their pay, their licence or their
| employment record.
|
| AND NEVER ANOTHER ESTATE. `EstateSecurity` filters every query on the tenant
| key, and the last test asks for a second estate's rows through the same methods
| to prove none comes back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->security = app(EstateSecurity::class);
    $this->estate = FacilitiesFixture::ESTATE;

    // A second estate, so "scoped to one" is a claim with a counter-example.
    $this->other = 'othersec'.random_int(1000, 9999);

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->other,
        'name' => 'Other Estate',
        'status' => 'active',
        'parish' => 'St. Ann',
        'data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([$this->estate, $this->other] as $tenant) {
        DuressAlert::create([
            'tenant_id' => $tenant,
            'kind' => 'panic',
            'raised_by_name' => 'Resident of '.$tenant,
            'unit_reference' => 'Lot 47',
            'status' => 'open',
            'server_time' => now()->subMinutes(3),
            'is_simulated' => false,
        ]);

        SecurityIncident::create([
            'tenant_id' => $tenant,
            'kind' => 'Attempted entry',
            'detail' => 'Someone tried the back gate at '.$tenant,
            'severity' => 'medium',
            'status' => 'open',
            'occurred_at' => now()->subHours(2),
        ]);

        // The gate API's own word (`GateLog::record()`), not the demonstration seed's old one.
        GateEvent::create([
            'tenant_id' => $tenant,
            'subject' => 'Visitor for '.$tenant,
            'verdict' => GateEvent::ADMIT,
            'occurred_at' => now()->subHour(),
            'is_simulated' => false,
        ]);
    }
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('exists as an estate role, distinct from Gemini\'s Head of Security', function () {
    $estateRole = Role::query()->where('name', Role::ESTATE_HEAD_OF_SECURITY)->first();
    $geminiRole = Role::query()->where('name', Role::HEAD_OF_SECURITY)->first();

    expect($estateRole)->not->toBeNull()
        ->and($geminiRole)->not->toBeNull()
        ->and($estateRole->id)->not->toBe($geminiRole->id)

        // One is a community role, the other the security company's.
        ->and(Role::ESTATE_HEAD_OF_SECURITY)->toStartWith('estate.')
        ->and(Role::HEAD_OF_SECURITY)->toStartWith('gemini.');
});

it('holds security and holds no money at all', function () {
    $role = Role::query()->where('name', Role::ESTATE_HEAD_OF_SECURITY)->firstOrFail();

    expect($role->hasPermissionTo('estate.security.view'))->toBeTrue();

    /*
     * The heart of the role. A person responsible for the gate has no business
     * in what a household owes, so every money module refuses them — checked as
     * a set rather than one at a time, because a new money module added later
     * should fail this test until somebody decides about it.
     */
    foreach (['dues_ledger', 'payments', 'accounting_posting', 'vendor_costs', 'maintenance_budget', 'payroll'] as $module) {
        foreach (['view', 'create', 'update', 'approve', 'export'] as $verb) {
            /*
             * A verb split into named acts (M11 B3) is its acts: the dues,
             * accounting and payroll approvals are several permissions each now,
             * and every one of them is refused here.
             */
            $names = NamedActs::under(Console::Estate, $module, $verb);

            foreach ($names === [] ? [$verb] : $names as $name) {
                expect($role->hasPermissionTo("estate.{$module}.{$name}"))
                    ->toBeFalse("estate.{$module}.{$name} must not be held by the estate Head of Security");
            }
        }
    }
});

it('may not administer an election or change settings', function () {
    $role = Role::query()->where('name', Role::ESTATE_HEAD_OF_SECURITY)->firstOrFail();

    // Notices yes — §2 names them. Running a ballot, no — nor either of
    // Governance's two approvals (M11 B3).
    expect($role->hasPermissionTo('estate.governance.view'))->toBeTrue()
        ->and($role->hasPermissionTo('estate.governance.update'))->toBeFalse()
        ->and($role->hasPermissionTo('estate.governance.certify_elections'))->toBeFalse()
        ->and($role->hasPermissionTo('estate.governance.publish_meetings'))->toBeFalse()
        ->and($role->hasPermissionTo('estate.settings.view'))->toBeFalse();
});

it('reads its own estate\'s alerts, incidents and gate events', function () {
    $board = $this->security->board($this->estate);

    expect($board['alerts'])->not->toBeEmpty()
        ->and($board['incidents'])->not->toBeEmpty()
        ->and($board['gate'])->not->toBeEmpty()

        // The lot is the point — the client's own instruction.
        ->and($board['alerts'][0]['unit'])->toBe('Lot 47')
        ->and($board['counts']['open_alerts'])->toBeGreaterThan(0);
});

it('labels every verdict the gate records for what it was, and counts only a denial as a refusal', function () {
    /*
     * B088. The gate API writes `admit`, `override`, `exit` and `deny` (`GateLog::record()`,
     * `GateEventController`). This screen compared every row to `admitted` — a word only the
     * demonstration seed ever wrote — so every real admission read "Refused", in red, and
     * "REFUSED AT THE GATE · 30 DAYS" counted every event at the gate.
     */
    foreach ([
        [GateEvent::ADMIT, 'Visitor on a pass'],
        [GateEvent::OVERRIDE, 'Contractor let in on the officer\'s word'],
        ['exit', 'Visitor leaving'],
        [GateEvent::DENY, 'Unnamed caller'],
    ] as $i => [$verdict, $subject]) {
        GateEvent::create([
            'tenant_id' => $this->estate,
            'subject' => $subject,
            'verdict' => $verdict,
            'occurred_at' => now()->subMinutes(10 + $i),
            'is_simulated' => false,
        ]);
    }

    // Admitted: the two admissions and the override. Refused: the one denial — not the exit.
    expect($this->security->counts($this->estate))->toMatchArray(['admitted_30d' => 3, 'refused_30d' => 1]);

    $rows = collect($this->security->gateActivity($this->estate))->keyBy('subject');

    expect($rows['Visitor on a pass'])->toMatchArray(['outcome' => 'admitted', 'label' => 'Admitted', 'admitted' => true])
        ->and($rows['Contractor let in on the officer\'s word'])->toMatchArray(['outcome' => 'admitted', 'label' => 'Admitted on override', 'admitted' => true])
        ->and($rows['Visitor leaving'])->toMatchArray(['outcome' => 'exit', 'label' => 'Left', 'admitted' => false])
        ->and($rows['Unnamed caller'])->toMatchArray(['outcome' => 'refused', 'label' => 'Refused', 'admitted' => false])

        // beforeEach's admission is a real one too.
        ->and($rows['Visitor for '.$this->estate])->toMatchArray(['outcome' => 'admitted', 'label' => 'Admitted']);

    // And the screen draws those labels, not "Refused" for everything.
    $this->actingAs(FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY))
        ->get(FacilitiesFixture::url('/security'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.refused_30d', 1)
            ->where('gate', fn ($gate): bool => collect($gate)->where('label', 'Refused')->count() === 1));
});

it('still reads the demonstration seed\'s old words, which an append-only log keeps for ever', function () {
    /*
     * PhoenixDemoSeed wrote `admitted` and `denied` until B088, and `gate_events` refuses every
     * update and delete — so those rows cannot be corrected, only read correctly.
     */
    foreach ([['admitted', 'Kimberly Douglas'], ['denied', 'Unnamed walk-up']] as [$verdict, $subject]) {
        GateEvent::create([
            'tenant_id' => $this->estate,
            'subject' => $subject,
            'verdict' => $verdict,
            'occurred_at' => now()->subMinutes(5),
            'is_simulated' => true,
        ]);
    }

    expect($this->security->counts($this->estate))->toMatchArray(['admitted_30d' => 2, 'refused_30d' => 1]);

    $rows = collect($this->security->gateActivity($this->estate))->keyBy('subject');

    expect($rows['Kimberly Douglas'])->toMatchArray(['outcome' => 'admitted', 'label' => 'Admitted'])
        ->and($rows['Unnamed walk-up'])->toMatchArray(['outcome' => 'refused', 'label' => 'Refused']);
});

it('sends each alert\'s own instant, so the screen can count its age on while it is open', function () {
    /*
     * B108. `ago` is the server's diffForHumans() at the moment the page was drawn; the screen
     * now keeps itself current and counts an alert's age from the instant it was raised.
     */
    $row = collect($this->security->alerts($this->estate))->firstWhere('unit', 'Lot 47');
    $alert = DuressAlert::query()->findOrFail($row['id']);

    expect($row['raised_at'])->toBe($alert->server_time->toIso8601String())
        ->and($row['ago'])->not->toBeEmpty();
});

it('never returns another estate\'s security records', function () {
    /*
     * The isolation proof. Both estates have an alert, an incident and a gate
     * event; asking for one must return one estate's and nothing of the other's.
     */
    $mine = $this->security->board($this->estate);
    $theirs = $this->security->board($this->other);

    $subjects = static fn (array $board): array => array_merge(
        array_column($board['alerts'], 'who'),
        array_column($board['incidents'], 'detail'),
        array_column($board['gate'], 'subject'),
    );

    foreach ($subjects($mine) as $text) {
        expect($text)->not->toContain($this->other);
    }

    foreach ($subjects($theirs) as $text) {
        expect($text)->not->toContain($this->estate);
    }

    expect($subjects($theirs))->not->toBeEmpty();
});

it('gives the estate presence and nothing about a guard\'s employment', function () {
    /*
     * The data-ownership matrix gives the community "guards on site: N" and the
     * alert states. Everything else about an officer belongs to Gemini, so the
     * shape of what comes back is asserted rather than the absence of one field:
     * a key added later would fail this.
     */
    $presence = $this->security->guardsOnSite($this->estate);

    expect(array_keys($presence))->toBe(['count', 'officers']);

    // Who and where they are posted, never when the shift began (Hassam, 12 Oct).
    foreach ($presence['officers'] as $officer) {
        expect(array_keys($officer))->toBe(['name', 'post']);
    }

    $json = json_encode($presence);

    foreach (['psra', 'employee_number', 'rate', 'salary', 'device', 'licence'] as $forbidden) {
        expect(strtolower((string) $json))->not->toContain($forbidden);
    }
});

it('opens the security screen for the Head of Security and refuses the Treasurer', function () {
    $hos = FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($hos)->get(FacilitiesFixture::url('/security'))->assertOk();

    // Money is the Treasurer's; the gate is not.
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/security'))->assertForbidden();
});

it('shows the Security item in the sidebar for the role, and not for the Treasurer', function () {
    $nav = static fn (string $role): array => array_column(
        app(App\Support\EstateNavigation::class)->forViewer(FacilitiesFixture::viewer($role)),
        'key',
    );

    expect($nav(Role::ESTATE_HEAD_OF_SECURITY))->toContain('security')
        ->and($nav(Role::TREASURER))->not->toContain('security');
});
