<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Console;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\Unit;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use App\Services\Estate\PrimaryResidentRepair;
use App\Support\DemonstrationAccounts;
use App\Support\Seeded\ReservedContacts;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\Estate\SettingsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demonstration data for both estates, plus the accounts the Phase 1 gate
 * authenticates as.
 *
 * Deliberately seeds TWO estates with overlapping record ids. The gate asks
 * for Ocean View's resident #1 while authenticated as a Phoenix Park
 * committee member; if both estates have a resident #1, a leak returns a
 * plausible record rather than a 404, which is the failure mode worth
 * catching.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Where these clients are, as the approved boards describe them.
     *
     * An estate is created by `estate:provision <subdomain> <name>`, which
     * knows nothing about a site beyond its name. The boards do: "Waterloo
     * Road, St. Andrew · 5 phases · Client since Mar 2024". Those are facts
     * about a physical place a security company guards, so they are seeded
     * here and stored as real columns rather than left implied by a screen.
     *
     * The name matters for a second reason: the client detail hero sizes to
     * its content and everything beside it follows, so "Phoenix Park" against
     * the board's "Phoenix Park Village 1" moved the whole action column.
     *
     * `status` is here for the same reason the address is: it is a fact the
     * boards state about these two clients, not an accident of when the
     * database was built. Four boards draw Ocean View mid-onboarding — the
     * directory badges it "Onboarding" and shows no MRR, its detail draws an
     * onboarding checklist, and both the plan and the new-client boards refer
     * to it in those words — while Phoenix Park is drawn live throughout.
     * Provisioning leaves every estate in whichever state it was created in,
     * so without this the demo platform has no onboarding client at all and
     * that half of the client detail screen can never be reached.
     *
     * The receipt prefixes are the client's, as ruled (13 A1).
     *
     * @var array<string, array{name: string, receipt_prefix: string, address_line: string, parish: string, gate_count: int, phases: list<string>, status: string}>
     */
    private const BOARD_NAMES = [
        'phoenixpark' => [
            'name' => 'Phoenix Park Village 1',
            'receipt_prefix' => 'PPV',
            'address_line' => 'Waterloo Road',
            'parish' => 'St. Andrew',
            'gate_count' => 3,
            'phases' => ['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5'],
            'status' => 'active',
        ],
        // Portmore, St. Catherine — not Norman Manley Boulevard, St. James,
        // which is a Montego Bay address and belongs to no board.
        'oceanview' => [
            'name' => 'Ocean View Gardens',
            'receipt_prefix' => 'OVG',
            'address_line' => 'Portmore',
            'parish' => 'St. Catherine',
            'gate_count' => 2,
            'phases' => ['Phase 1', 'Phase 2'],
            'status' => 'onboarding',
        ],
    ];

    public function run(): void
    {
        $this->seedGeminiStaff();

        foreach (self::BOARD_NAMES as $subdomain => $site) {
            Tenant::find($subdomain)?->forceFill($site)->save();
        }

        foreach (Tenant::estates() as $tenant) {
            $this->seedEstateCommittee($tenant);

            $tenant->run(function () use ($tenant) {
                $this->seedEstateRecords($tenant);
            });
        }
    }

    /**
     * PRE-VERIFIED, EVERY ONE OF THEM (M10 §1.4). The contact gate stops an account
     * whose alert address is not proven, and these addresses are on `.test`, which
     * can never receive mail — so a code could never reach them, and a demonstration
     * would stop at the door. `DemonstrationAccounts::verify()` proves the sign-in
     * address as the alert address, and refuses any address a person could own.
     */
    private function seedGeminiStaff(): void
    {
        $director = User::updateOrCreate(
            ['email' => 'director@geminisecurity.test'],
            [
                'name' => 'Andrea Case',
                'password' => Hash::make('password'),
                'console' => Console::Gemini->value,
                'status' => 'active',
            ],
        );
        $director->syncRoles([Role::DIRECTOR]);
        DemonstrationAccounts::verify($director);

        // Scoped to assigned sites only — the one Gemini role that is.
        $hos = User::updateOrCreate(
            ['email' => 'security@geminisecurity.test'],
            [
                'name' => 'Marlon Bailey',
                'password' => Hash::make('password'),
                'console' => Console::Gemini->value,
                'status' => 'active',
            ],
        );
        $hos->syncRoles([Role::HEAD_OF_SECURITY]);
        DemonstrationAccounts::verify($hos);

        // Assigned to Phoenix Park only. Ocean View must be invisible to them
        // despite their being platform staff.
        if (Tenant::find('phoenixpark')) {
            EstateAssignment::updateOrCreate(
                ['user_id' => $hos->id, 'tenant_id' => 'phoenixpark'],
                ['role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true],
            );
        }
    }

    /**
     * The committee the Client Detail board draws.
     *
     * Three contacts, not two: President, Treasurer, Property Manager. The
     * Treasurer was missing, so the "Estate contacts" panel came up a row
     * short against its board — and a Treasurer is also the role the arrears
     * and payment-plan screens are written for, so an estate without one
     * cannot demonstrate them.
     *
     * NAMES ARE THE BOARD'S, and that is not decoration. These were seeded as
     * "Phoenix Park Village 1 President", which is not a name any person has:
     * it wraps to two lines where the board's does not, it reads as a
     * placeholder in a client review, and it makes the contacts panel useless
     * for judging whether the screen is right. The boards name real people, so
     * the seed does.
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     *                                                                  subdomain => [mailbox, role constant, person's name]
     */
    private const COMMITTEE = [
        'phoenixpark' => [
            ['president', Role::PRESIDENT, 'Patrice Campbell'],
            ['treasurer', Role::TREASURER, 'Tracey Reid'],
            ['manager', Role::PROPERTY_MANAGER, 'Patricia Morgan'],
        ],
        'oceanview' => [
            ['president', Role::PRESIDENT, 'Lloyd Bennett'],
            ['treasurer', Role::TREASURER, 'Simone Grant'],
            ['manager', Role::PROPERTY_MANAGER, 'Errol Chin'],
        ],
    ];

    private function seedEstateCommittee(Tenant $tenant): void
    {
        $key = (string) $tenant->getTenantKey();

        // An estate nobody drew a board for still gets a full committee; only
        // the names fall back.
        $committee = self::COMMITTEE[$key] ?? [
            ['president', Role::PRESIDENT, $tenant->name.' President'],
            ['treasurer', Role::TREASURER, $tenant->name.' Treasurer'],
            ['manager', Role::PROPERTY_MANAGER, $tenant->name.' Property Manager'],
        ];

        foreach ($committee as [$mailbox, $roleName, $personName]) {
            $role = Role::named($roleName);

            $member = User::updateOrCreate(
                ['email' => "{$mailbox}@{$key}.test"],
                [
                    'name' => $personName,
                    'password' => Hash::make('password'),
                    'console' => Console::Estate->value,
                    'status' => 'active',
                ],
            );

            $member->syncRoles([$roleName]);

            // Pre-verified, for the reason `seedGeminiStaff()` gives (M10 §1.4).
            DemonstrationAccounts::verify($member);

            EstateAssignment::updateOrCreate(
                ['user_id' => $member->id, 'tenant_id' => $key],
                ['role_id' => $role->id, 'is_active' => true],
            );
        }
    }

    /** Runs inside tenancy — every model here resolves to the estate database. */
    private function seedEstateRecords(Tenant $tenant): void
    {
        $prefix = strtoupper(substr((string) $tenant->getTenantKey(), 0, 2));

        // The chart first: nothing can be posted until there are accounts to
        // post against, and every charge below raises a real journal entry.
        $this->call(ChartOfAccountsSeeder::class);

        /*
         * The estate's own settings row — boards 21 and 23.
         *
         * BOTH ESTATES, and above the Phoenix Park branch deliberately. Every
         * estate has thresholds and a contact block whatever its billing
         * history, and Ocean View is the one that proves the settings screens
         * render an estate that has published no enquiries address yet.
         */
        $this->call(SettingsSeeder::class);

        /*
         * Phoenix Park's real estate — 450 units and six months of dues, fitted
         * to the arrears every board states.
         *
         * PHOENIX PARK ONLY, and the choice is made here rather than inside the
         * seeder. Ocean View is mid-onboarding on every board that draws it, and
         * an estate with six months of billing behind it is not onboarding —
         * seeding one would make that state unreachable on four Gemini screens.
         */
        if ($tenant->getTenantKey() === 'phoenixpark') {
            $this->call(EstateFinanceSeeder::class);

            return;
        }

        foreach (range(1, 4) as $n) {
            $unit = Unit::updateOrCreate(
                ['reference' => "{$prefix}-{$n}A"],
                ['block' => 'Block '.chr(64 + $n), 'street' => "{$tenant->name} Drive", 'status' => 'occupied'],
            );

            $household = Household::updateOrCreate(
                ['unit_id' => $unit->id],
                ['name' => "{$tenant->name} Household {$n}", 'access_restricted' => false],
            );

            // Primary only where nobody holds the household: a re-run never hands back what the office moved (DESIGN §13).
            PrimaryResidentRepair::seededHolder($household, "resident{$n}@{$tenant->getTenantKey()}.test", [
                'full_name' => "{$tenant->name} Resident {$n}",
                'phone' => ReservedContacts::seededPhone(1000 + $n),
                'relationship' => 'owner',
            ]);

            $charge = Charge::updateOrCreate(
                ['reference' => "{$prefix}-CHG-{$n}"],
                [
                    'unit_id' => $unit->id,
                    'description' => 'Monthly maintenance',
                    'amount_minor' => 25_000_00,
                    'currency' => 'JMD',
                    'due_on' => now()->startOfMonth()->toDateString(),
                    'status' => 'outstanding',
                ],
            );

            /*
             * A CHARGE POSTS A JOURNAL, and now it posts a real one.
             *
             * This used to write a single-sided `journals` row with an amount
             * and no account, which was all a journal could be before the
             * ledger existed. Every entry is now two-sided and the database
             * refuses anything else: dues owed by a household are DEBITED to
             * Dues Receivable and CREDITED to Maintenance Fee Income, and the
             * household is named on the receivable line so the sub-ledger ties
             * to its control account.
             *
             * Posted once. Journals are append-only, so a re-seed must not
             * raise the same charge a second time — the guard is the charge's
             * own reference, which is unique.
             */
            $alreadyPosted = Journal::query()
                ->where('source', Ledger::SOURCE_CHARGE)
                ->where('source_id', $charge->id)
                ->exists();

            if (! $alreadyPosted) {
                app(Ledger::class)->post(
                    memo: 'Monthly maintenance — '.$unit->reference,
                    postings: [
                        Posting::debit('1200', $charge->amount_minor, 'Monthly maintenance', unitId: $unit->id),
                        Posting::credit('4000', $charge->amount_minor, 'Monthly maintenance'),
                    ],
                    on: (string) $charge->due_on,
                    source: Ledger::SOURCE_CHARGE,
                    sourceId: $charge->id,
                    prefix: 'CHG',
                );
            }
        }

        $this->correctOneChargeInError();
    }

    /**
     * One charge raised against the wrong unit, and the reversal that corrects
     * it.
     *
     * DELIBERATELY IMPERFECT, for the same reason the dispatch seed leaves a
     * gate uncovered. A correction is the one thing an append-only ledger has to
     * be able to express, and an estate where nothing was ever posted in error
     * leaves the reversing path — and the pair of entries a unit statement must
     * show for it — permanently unreachable, which is to say untested.
     *
     * Runs inside tenancy.
     */
    private function correctOneChargeInError(): void
    {
        $household = Household::query()->orderByDesc('id')->first();

        if ($household === null) {
            return;
        }

        $memo = 'Special assessment — raised against the wrong unit';

        if (Journal::where('memo', $memo)->exists()) {
            return;
        }

        $mistake = app(Ledger::class)->post(
            memo: $memo,
            postings: [
                Posting::debit('1200', 15_000_00, 'Special assessment', unitId: $household->unit_id),
                Posting::credit('4000', 15_000_00, 'Special assessment'),
            ],
            on: now()->startOfMonth()->addDays(3)->toDateString(),
            source: Ledger::SOURCE_CHARGE,
            prefix: 'CHG',
        );

        /*
         * The entry stays. Both halves remain visible and net to nothing, which
         * is what lets somebody reading this account in a year see that a
         * mistake was made AND that it was put right — neither of which a
         * deleted row can show them.
         */
        app(Ledger::class)->reverse($mistake, 'Reversal — assessment belonged to another unit');
    }
}
