<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Enums\AccessLevel;
use App\Enums\Console;
use App\Enums\PermissionVerb;
use App\Models\AuditEntry;
use App\Models\Estate\EstateFeature;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\NotificationDefault;
use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Gemini\BillingOverview;
use App\Services\Gemini\InvoiceActions;
use App\Services\Invitations;
use App\Services\Platform\SmsSettings;
use App\Services\Rbac\RoleEditor;
use App\Support\MoneyFormatter;
use App\Support\TableQuery;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The estate's own settings — boards 21 through 24, 30, 33 and 40.
 *
 * SEVEN SCREENS, AND STILL ONLY THREE OF THEM OWN ANY DATA OF THEIR OWN.
 *
 *   21  Estate profile      the client record, READ from `tenants` and from
 *                           this estate's own units; the contact details, owned
 *                           here and written here
 *   22  Users & roles        entirely central — users, roles, assignments
 *   23  Feature toggles      catalogue central, the estate's overrides local
 *   24  Role access matrix   entirely central; this estate's OWN roles are
 *                           edited through `App\Services\Rbac\RoleEditor`
 *   30  Notification defaults the estate's own eighteen switches, owned here
 *                           and written here
 *   33  Data & privacy       FOUR POLICY STATEMENTS AND NO STORAGE AT ALL. Every
 *                           value is either fixed copy this screen carries or a
 *                           fact read back from the permission model; none of it
 *                           is a column, because nobody has been asked to make
 *                           any of it configurable
 *   40  Billing & subscription entirely CENTRAL, and READ-ONLY — see the class
 *                           docblock on `billingBoard()` for why this one is not
 *                           like the other six
 *
 * THE MATRIX IS EDITABLE BY EXACTLY ONE ROLE, AND ONLY WHERE IT IS THIS
 * ESTATE'S OWN (M10 §4).
 * ======================================================================
 *
 * Until M10 this matrix was read and never written: changing what a role may do
 * is an `approve`-level act on the Settings module (D-013), and the seeded
 * matrix gave `estate.settings.approve` to nobody. The client has asked for it
 * to be editable by the Community Super Admin, so that one cell now carries the
 * Approver tag and no other estate cell does. The escalation route that three
 * independent refusals used to keep shut is now a door with four locks on it,
 * all of them in `App\Services\Rbac\RoleEditor`, where both consoles share
 * them:
 *
 *   1. only the holder of `estate.settings.approve` may change a role at all
 *   2. only a role carrying THIS estate's tenant id may be changed. The eight
 *      seeded estate roles belong to every estate at once, so an estate copies
 *      one into a role of its own and changes the copy
 *   3. nobody grants — or takes away — a permission they do not hold
 *      themselves, and nothing names a module outside this console
 *   4. Ruling 1 still refuses a Property Manager, and any copy of one, a money
 *      module
 *
 * And the tenant fence that makes (2) true is applied to every role this class
 * lists or resolves: `Role::ownedBy()` on the invite list, the matrix and a role
 * change, so another estate's role is neither shown here nor assignable from
 * here. `gate:isolation` STEP 10 proves it against real rows.
 *
 * THIS CLASS STILL WRITES NO ROLE. Board 24's editor posts to
 * `SettingsController`, which hands every create, copy, rename, permission
 * change and delete straight to `RoleEditor` behind `can:estate.settings.approve`
 * — one set of guard rails for both consoles, and none of them restated here.
 * What this class owns is the PEOPLE half: who holds which role
 * (`manageUser()`), who is taken off the committee (`removeUser()`) and brought
 * back onto it (`reinstateUser()`), who may be invited into what
 * (`invitableRoles()`), and the sentences board 22 prints beside a row before
 * anybody presses anything (`usersBoard()`). All of it acts on this estate's
 * committee and on nobody else — never on the assignment row Gemini's own Head
 * of Security holds here (`committee()`).
 *
 * THE MODEL WINS OVER THE DRAWING, ALWAYS (D-044). Board 24 draws the Property
 * Manager with View on Dues & ledger and on Accounting; Ruling 1 (D-010) locks
 * that role out of both, and the seeder refuses to grant them. The matrix this
 * screen renders is read from `role_module_access` — every module and every
 * role this estate can see, not the drawn ten and six — so what a committee
 * reads here is what the console actually enforces. The discrepancies are
 * recorded in DECISIONS.md and resolved in neither direction here.
 *
 * NOTHING ON BOARD 21 THAT ALREADY EXISTS IS COPIED. The estate name, its
 * address, its unit count and its phase count are the central client record and
 * this estate's own units, read at the boundary and shown read-only with the
 * reason on each field. A form that wrote them would give the community a
 * second name, free to diverge from the one on Gemini's invoices and on the
 * dispatch board that sends a supervisor to the site.
 */
class Settings
{
    /**
     * Board 21's seven-item sub-navigation, in the order every settings board
     * draws it.
     *
     * FOUR OF THE SEVEN ARE BUILT. The other three are shown and inert for the
     * same reason `EstateNavigation` shows an unbuilt module: the point of a
     * settings index is that an administrator can see what this console holds,
     * and a link into a 404 is worse than a caption saying "not yet".
     *
     * @var list<array{key: string, label: string, href: string|null}>
     */
    /*
     * All seven are built and all seven link. A section added ahead of its
     * route turned this strip into three 404s on every settings screen once --
     * the same fault EstateNavigation warns about, in the opposite direction --
     * so a new one goes live in the same change that registers its route.
     */
    private const SECTIONS = [
        ['key' => 'profile', 'label' => 'Estate profile', 'href' => '/settings/profile'],
        ['key' => 'users', 'label' => 'Users & roles', 'href' => '/settings/users'],
        ['key' => 'features', 'label' => 'Feature toggles', 'href' => '/settings/features'],
        ['key' => 'roles', 'label' => 'Role access matrix', 'href' => '/settings/roles'],
        ['key' => 'notifications', 'label' => 'Notification defaults', 'href' => '/settings/notifications'],
        ['key' => 'billing', 'label' => 'Billing & subscription', 'href' => '/settings/billing'],
        ['key' => 'privacy', 'label' => 'Data & privacy', 'href' => '/settings/privacy'],
        // No board draws it (the user, 8 Oct 2026): what Dependents and Helpers may do in the Resident App.
        ['key' => 'resident_access', 'label' => 'Resident app access', 'href' => '/settings/resident-access'],
        // No board draws it (Hassam, 12 Oct 2026, guard live tracking): how often officers' phones send, and "No signal".
        ['key' => 'guard_tracking', 'label' => 'Guard tracking', 'href' => '/settings/guard-tracking'],
    ];

    /**
     * Board 23's own copy, verbatim, keyed by the catalogue's feature key.
     *
     * THE SECOND LINE ON THIS SCREEN IS NOT THE SECOND LINE ON BOARD 43. The
     * central catalogue's `sub_label` is a column caption in a package-builder
     * grid — "GL, AP/AR, bank import" — and this is a sentence for a committee
     * member deciding whether to switch something off: "General ledger, AP/AR,
     * bank reconciliation". They describe one feature to two audiences, and
     * collapsing them into one string would make one of the two screens read
     * wrongly.
     *
     * It lives here rather than on `package_features` for the same reason board
     * 28's scope banner lives in its controller: it is copy belonging to this
     * screen in this release, and adding a column to the central catalogue to
     * hold it would put a second description in front of the package builder
     * that nothing there draws.
     *
     * `estate_payroll` is absent deliberately — its line states the two
     * routings, so it is computed rather than written down.
     *
     * @var array<string, string>
     */
    private const FEATURE_COPY = [
        'guard_app' => "Patrol and incident visibility from Gemini Security's guards",
        'evoting' => 'The Election Control Room and resident ballots',
        'gated_meetings' => 'AGM/EGM scheduling visible only to owners in good standing',
        'accounting_core' => 'General ledger, AP/AR, bank reconciliation',
        'ai_drafting' => 'Draft notices, minutes, and summaries, always reviewed before sending',
        'custom_branding' => 'Logo, hero image, and colour theme on resident-facing screens',
    ];

    /** Board 23's line under all three locked rows, without its em dash. */
    private const CORE_COPY = 'Always on, at every tier';

    /**
     * Which plan key a tier badge is drawn from, and what the badge reads.
     *
     * A feature's tier is the LOWEST plan that includes it, computed from
     * `plan_features` rather than stored on the feature. Storing it would be a
     * second answer to "is this in Standard?", and the package builder — which
     * edits the grid — would be free to disagree with it.
     *
     * @var array<string, string>
     */
    private const TIER_LABELS = [
        'essential' => 'Core',
        'standard' => 'Standard+',
        'premium' => 'Premium',
    ];

    /** @var array<string, string> tier key => the group heading board 23 draws */
    private const TIER_GROUPS = [
        'essential' => 'Core · every tier',
        'standard' => 'Standard & above',
        'premium' => 'Premium only',
    ];

    /**
     * Board 23's audit note, verbatim.
     *
     * It is a statement about how this platform behaves rather than a
     * description of the screen, so it is carried with the release rather than
     * written into a template — the same reason board 28's scope banner is.
     */
    /*
     * Made true (the Settings pass, 12 Oct): the features are shown read only with the plan (Hassam's closing order),
     * so "what your community controls itself" no longer describes this page.
     */
    public const FEATURES_AUDIT_NOTE = 'What this estate can use is set by its plan. Gemini Security changes a plan '.
        'against the subscription, and every change is written to the audit log.';

    /**
     * Board 24's audit note, made true (the Settings pass, 12 Oct). The board's first sentence said the Community Super
     * Admin "isn't shown here", over a matrix that shows that role's column, and carried an em dash; what stays is
     * the part that is so.
     */
    public const MATRIX_AUDIT_NOTE = 'Every role assignment and every permission change is written to the estate\'s '.
        'audit log, which nobody can edit.';

    /**
     * Why somebody reading the matrix cannot change it, in the words a committee
     * member needs.
     *
     * Named rather than assembled at the call site: it is the answer to the
     * single most likely question this console will be asked, and it must read
     * the same wherever it is shown. Since M10 §4 it is shown only to a viewer
     * who cannot edit — the Community Super Admin can.
     */
    public const MATRIX_READ_ONLY = 'Changing what a role may do is an approve-level act on Settings. On this estate '.
        'the Community Super Admin holds it, and your role does not, so you can read this matrix but not change it.';

    /**
     * What happens when this community changes one of the eight shared roles (M11 B4): it makes its
     * own version, and no other community's role moves.
     */
    public const SHARED_ROLE_NOTE = 'The first eight roles start as GeminiSecure\'s, shared by every community. Change '.
        'one and this community gets its own version of it, with the same name and your people on it, while every '.
        'other community keeps GeminiSecure\'s. "Reset to GeminiSecure default" puts it back.';

    /**
     * The last Community Super Admin, who cannot be moved off the role or have
     * their access withdrawn. Named because both `manageUser()` and
     * `removeUser()` refuse with it, and the screen prints it beside the row.
     */
    public const LAST_SUPER_ADMIN = 'This is the last Community Super Admin on this estate. Make somebody else one '.
        'first, or nobody here could change a role or a permission again.';

    /**
     * The estate's seniormost officer, who is not managed from here at all.
     * Named for the same reason as `LAST_SUPER_ADMIN`: `manageUser()` and
     * `removeUser()` refuse with it, and board 22 prints it beside the row
     * BEFORE anybody presses anything, so the two must be one string.
     */
    public const SENIORMOST_OFFICER = 'This is the estate\'s seniormost officer. Changing their role or access from '.
        'here would leave the estate with nobody who could undo it, so make somebody else senior first.';

    /** Why nobody removes themselves — printed on their own row, refused by `removeUser()`. */
    public const NOT_YOURSELF = 'You cannot remove yourself. Another administrator does that, so a slip cannot lock '.
        'you out.';

    /**
     * Why nobody changes their own role or suspends themselves — printed on their
     * own row, refused by `manageUser()`. The Gemini console's Platform admins has
     * refused the same from the start (`PlatformAdmins::manage()`).
     */
    public const NOT_YOUR_OWN_ROLE = 'You cannot change your own role or suspend yourself. Somebody else who may manage '.
        'you does that, so nobody decides for themselves what they may do.';

    /**
     * An assignment that is not an active one on this estate's committee. ONE
     * SENTENCE FOR "ANOTHER ESTATE'S", "NO SUCH ROW" AND "GEMINI'S OWN STAFF", so
     * an id typed into the URL tells a committee nothing about anybody else's —
     * not even that the Gemini Head of Security's site assignment is a row here.
     */
    public const NOT_ON_COMMITTEE = 'That person is not on this estate\'s committee.';

    /**
     * An assignment that is not a removed member of this estate's committee —
     * one still on it, another estate's, Gemini's, or no row at all. One
     * sentence for all of them, for the reason `NOT_ON_COMMITTEE` gives.
     */
    public const NOT_REMOVED_HERE = 'Nobody by that record was taken off this estate\'s committee, so there is nobody to reinstate.';

    /**
     * THE POSITION RULE, AS BOARD 24'S EDITOR WARNS ABOUT IT (M10 §4 meeting §5).
     *
     * `Dues::maySeeEstatePosition()` lets a viewer read the estate's money about
     * itself — what it is owed, what it took, its balances — when they hold
     * `view` on the money module in question AND `estate.reports.view`. Neither
     * cell reads as a grant of the totals on its own, so a committee building a
     * role could hand them out without meaning to. The editor says so in plain
     * words the moment a draft role combines the two; the modules are listed
     * here, beside the sentence, so the screen tests the same three the rule is
     * asked about and no template carries a list of its own.
     *
     * @var array{reports: string, money: list<string>, warning: string}
     */
    public const POSITION_RULE = [
        'reports' => 'reports',
        'money' => ['dues_ledger', 'payments', 'accounting_posting'],
        // Colons and commas where em dashes were (M53 law 3; the Settings pass, 12 Oct).
        'warning' => 'Reports view together with view on a money module lets whoever holds this role read the '.
            'estate\'s own money totals for that module: what the estate is owed, what it has taken and what it '.
            'holds, on the dashboard, the registers and the reports. Neither permission does that alone. Leave '.
            'one of the two off if this role should not see those figures.',
    ];

    /** Board 24's legend, and the four descriptions it prints beside the pills. */
    private const LEGEND = [
        ['key' => 'full', 'label' => 'Full', 'description' => 'Create, edit, approve'],
        ['key' => 'view', 'label' => 'View', 'description' => 'Read-only'],
        ['key' => 'entry', 'label' => 'Entry', 'description' => 'Data entry, no approval'],
        ['key' => 'none', 'label' => '—', 'description' => 'No access'],
    ];

    /**
     * Board 30's six events, three groups, verbatim.
     *
     * A CONSTANT AND NOT A TABLE, on the same reasoning `FEATURE_COPY` already
     * carries for board 23: the six events, their grouping and their copy are
     * this SCREEN's, not a catalogue any estate administers. Nothing on this
     * platform lets a community invent a seventh event or rename "Dues
     * reminders" — the events are what THIS PRODUCT sends, and only the
     * eighteen (event, channel) answers are the estate's own to decide. See
     * the `notification_defaults` migration for the fuller argument.
     *
     * `group_sort` and `sort` are carried explicitly rather than trusted to
     * array order, because `notificationsBoard()` groups this list by
     * `group` before it iterates it and a `foreach` over a grouped collection
     * does not promise to preserve the order the array was written in.
     *
     * @var list<array{key: string, group: string, group_sort: int, sort: int, label: string, description: string}>
     */
    private const NOTIFICATION_EVENTS = [
        [
            'key' => 'dues_reminders', 'group' => 'Financial', 'group_sort' => 1, 'sort' => 1,
            'label' => 'Dues reminders', 'description' => 'Sent to residents before and after a due date',
        ],
        [
            'key' => 'payment_plan_updates', 'group' => 'Financial', 'group_sort' => 1, 'sort' => 2,
            'label' => 'Payment plan updates', 'description' => 'Instalment due dates and confirmations',
        ],
        [
            'key' => 'new_notices_posted', 'group' => 'Community', 'group_sort' => 2, 'sort' => 1,
            'label' => 'New notices posted', 'description' => 'Estate-wide announcements from Governance',
        ],
        [
            'key' => 'meetings_elections', 'group' => 'Community', 'group_sort' => 2, 'sort' => 2,
            'label' => 'Meetings & elections', 'description' => 'AGM/EGM scheduling and ballot openings',
        ],
        [
            'key' => 'ticket_status_changes', 'group' => 'Maintenance & facilities', 'group_sort' => 3, 'sort' => 1,
            'label' => 'Ticket status changes',
            'description' => 'When a maintenance ticket is assigned, in progress, or closed',
        ],
        [
            'key' => 'amenity_booking_confirmations', 'group' => 'Maintenance & facilities', 'group_sort' => 3, 'sort' => 2,
            'label' => 'Amenity booking confirmations', 'description' => 'Deposit received, booking confirmed or cancelled',
        ],
    ];

    /**
     * Board 30's own starting values, seeded once by `SettingsSeeder` and never
     * again — see that seeder for why a second run must not touch a committee's
     * own choice.
     *
     * `[event_key][channel] => bool`, thirteen on and five off, transcribed from
     * the board rather than chosen: this is a picture of a real estate's
     * defaults, not a platform recommendation this file is entitled to revise.
     *
     * @var array<string, array<string, bool>>
     */
    public const NOTIFICATION_SEED = [
        'dues_reminders' => ['email' => true, 'sms' => true, 'push' => true],
        'payment_plan_updates' => ['email' => true, 'sms' => false, 'push' => true],
        'new_notices_posted' => ['email' => true, 'sms' => false, 'push' => true],
        'meetings_elections' => ['email' => true, 'sms' => true, 'push' => true],
        'ticket_status_changes' => ['email' => false, 'sms' => false, 'push' => true],
        'amenity_booking_confirmations' => ['email' => true, 'sms' => false, 'push' => true],
    ];

    /* ------------------------------------------------------------------ */
    /* the shell every settings screen shares */
    /* ------------------------------------------------------------------ */

    /**
     * The sub-navigation, with one item marked current.
     *
     * ALL SEVEN SECTIONS ARE BUILT, so every one of these carries a real href
     * and `pending` is false throughout. The key is kept on the payload rather
     * than dropped because the pages still branch on it: an eighth section added
     * before its screen exists must arrive here as pending and be drawn inert,
     * never as a link to a route that answers 404. That is the fault this strip
     * already had once, when three sections were set live ahead of their routes.
     *
     * @return list<array{key: string, label: string, href: string, active: bool, pending: bool}>
     */
    public function sections(string $active, string $tenantKey = ''): array
    {
        $items = [];

        // The audit log is a section only while its switch is on (M84 §0.1: off means absent, never a dead link).
        $sections = self::SECTIONS;
        if ((bool) config('estate_features.audit_log', false)) {
            $sections[] = ['key' => 'audit', 'label' => 'Audit log', 'href' => '/settings/audit'];
        }

        foreach ($sections as $section) {
            $items[] = [
                'key' => $section['key'],
                'label' => $section['label'],
                'href' => $this->path($section['href'], $tenantKey),
                'active' => $section['key'] === $active,
                'pending' => false,
            ];
        }

        return $items;
    }

    /* ------------------------------------------------------------------ */
    /* board 21 — estate profile */
    /* ------------------------------------------------------------------ */

    /**
     * The profile form, every field already resolved and already formatted.
     *
     * TWO KINDS OF FIELD, AND THE DIFFERENCE IS STATED ON EACH. The client
     * record — name, address, units, phases — is read from where it already
     * lives and carries `editable: false` with the reason; the estate's own
     * contact details are editable and carry none. A page built from this draws
     * the same form the board draws and simply disables four inputs.
     *
     * @return array<string, mixed>
     */
    public function profileBoard(): array
    {
        $setting = EstateSetting::current();
        $estate = tenant();

        $name = $estate === null ? '' : (string) $estate->name;
        $address = $estate === null ? null : $estate->site_address;

        /*
         * "Waterloo Road, St. Andrew, Jamaica" — the board's third part.
         *
         * The country is appended rather than stored: every estate on this
         * platform is Jamaican, the currency ruling says so (D-020), and a
         * `country` column populated with one value on every row would be a
         * column that tells nobody anything.
         */
        if ($address !== null) {
            $address .= ', Jamaica';
        }

        /*
         * THE LOTS AND THE PHASES IN ONE READ (the QA session's budget run, 10 October). The phase count joined this
         * page as a second COUNT of its own and took it one query over its budget whenever it was the first page in its
         * process to count lots; asked as a subquery of the lots' count, the two are one statement.
         */
        $counts = Unit::lots()->toBase()
            ->selectRaw('count(*) as lots')
            ->selectSub(Phase::query()->toBase()->selectRaw('count(*)'), 'phases')
            ->first();
        $lots = (int) data_get($counts, 'lots', 0);
        $phases = (int) data_get($counts, 'phases', 0);

        return [
            'estate' => [
                'name' => $name,
                'address' => $address,
            ],
            'logo' => [
                'path' => $setting->logo_path,
                /*
                 * WHERE THE PAGE DRAWS THE ESTATE'S OWN MARK (the Settings pass, 12 Oct): the page showed the board's
                 * stand-in even after a logo was uploaded, so nobody could see what their paperwork carries. The served
                 * route, with the stored file's own fingerprint on it, so a new upload is drawn at once and not from a
                 * cache. Null until one is set, and the stand-in is drawn.
                 */
                'url' => $setting->logo_path === null
                    ? null
                    : $this->path('/settings/profile/logo', (string) $estate?->getTenantKey()).'?v='.substr(sha1($setting->logo_path), 0, 12),
                'caption' => 'Estate logo, printed on resident notices and receipts',
                'action' => 'Upload new logo',
            ],
            'groups' => [
                [
                    'title' => 'Estate details',
                    'fields' => [
                        [
                            'key' => 'name',
                            'label' => 'Estate name',
                            'value' => $name,
                            'type' => 'text',
                            'span' => 'full',
                            'editable' => false,
                            'reason' => self::NO_RENAME_HERE,
                        ],
                        /*
                         * NO ADDRESS LINE WHEN THE CLIENT RECORD HOLDS NONE (Hassam, 10 October): no empty label, no
                         * placeholder, and never a guess. It is shown here and typed on the client record, so a blank
                         * one is a line with nothing to read. The email and phone below stay: they are where the estate
                         * types its own.
                         */
                        ...($address === null ? [] : [[
                            'key' => 'address',
                            'label' => 'Address',
                            'value' => $address,
                            'type' => 'text',
                            'span' => 'full',
                            'editable' => false,
                            'reason' => self::NO_RENAME_HERE,
                        ]]),
                        [
                            /*
                             * COUNTED, not stored: the estate's lots
                             * (`Unit::lots()`), the same figure board 2's phase
                             * table adds up to. On a site-plan estate a unit off
                             * the plan is not one (M60 §C2: Phoenix Park is
                             * 1,999, its contract's 2,000 is said apart). A
                             * stored copy would be one more number free to
                             * disagree.
                             */
                            'key' => 'total_units',
                            'label' => 'Total units',
                            'value' => (string) $lots,
                            'type' => 'number',
                            'span' => 'half',
                            'editable' => false,
                            'reason' => self::NO_COUNT_HERE,
                        ],
                        [
                            // Derived from the phase STRUCTURE, as D-033 ruled:
                            // phases have names residents use, and the count
                            // follows from them. The estate's own structure
                            // first, the phases Estate structure draws (the
                            // client record's copy read 0 on live beside five,
                            // M69 SET-1); the client record's for an estate
                            // that keeps none of its own.
                            'key' => 'phase_count',
                            'label' => 'Number of phases',
                            'value' => (string) ($phases ?: ($estate === null ? 0 : $estate->phase_count)),
                            'type' => 'number',
                            'span' => 'half',
                            'editable' => false,
                            'reason' => self::NO_COUNT_HERE,
                        ],
                    ],
                ],
                [
                    'title' => 'Contact',
                    'fields' => [
                        [
                            'key' => 'enquiries_email',
                            'label' => 'General enquiries email',
                            'value' => (string) ($setting->enquiries_email ?? ''),
                            'type' => 'email',
                            'span' => 'half',
                            'editable' => true,
                            'reason' => null,
                        ],
                        [
                            'key' => 'enquiries_phone',
                            'label' => 'Phone',
                            'value' => (string) ($setting->enquiries_phone ?? ''),
                            'type' => 'tel',
                            'span' => 'half',
                            'editable' => true,
                            'reason' => null,
                        ],
                    ],
                ],
                [
                    'title' => 'Security provider',
                    'fields' => [
                        [
                            'key' => 'security_provider',
                            'label' => 'Provider',
                            'value' => $setting->security_provider,
                            'type' => 'text',
                            'span' => 'full',
                            'editable' => true,
                            'reason' => null,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Why the four client-record fields are shown and not edited: one line each, said under the fields on the page
     * (the Settings pass, 12 Oct: they were paragraphs with an em dash, shown only on hover).
     */
    private const NO_RENAME_HERE = 'The name and address are Gemini Security\'s client record, printed on its '.
        'invoices. Ask Gemini Security to change them.';

    private const NO_COUNT_HERE = 'The units and phases are counted from the estate\'s own records, never typed, so '.
        'they always match the rest of the console.';

    /**
     * Save the estate's own contact details.
     *
     * ONLY THREE FIELDS REACH THE DATABASE, and the allowlist is here rather
     * than in the request so that a second caller — the /api/v1 endpoint the
     * mobile apps will use — cannot widen it by validating differently. Every
     * held-back flag is unreachable twice over: it is not in this list and it is
     * not in the model's `$fillable` either.
     *
     * @param  array<string, string|null>  $input
     */
    public function updateProfile(array $input, User $by): EstateSetting
    {
        $setting = EstateSetting::current();

        $before = [
            'enquiries_email' => $setting->enquiries_email,
            'enquiries_phone' => $setting->enquiries_phone,
            'security_provider' => $setting->security_provider,
        ];

        $provider = trim((string) ($input['security_provider'] ?? ''));

        if ($provider === '') {
            throw new DomainException(
                'An estate has a security provider. Leaving the field empty would leave a resident notice with '.
                'nobody named on it as the company standing at the gate.'
            );
        }

        $setting->fill([
            // Empty means "not published", which is a real answer for an estate
            // that takes enquiries by phone only — so it is stored as null
            // rather than as an empty string nobody can distinguish from unset.
            'enquiries_email' => $this->nullIfBlank($input['enquiries_email'] ?? null),
            'enquiries_phone' => $this->nullIfBlank($input['enquiries_phone'] ?? null),
            'security_provider' => $provider,
        ])->save();

        $this->audit(
            action: 'estate.settings.profile_updated',
            by: $by,
            entityType: 'estate_setting',
            entityId: (string) $setting->getKey(),
            before: $before,
            after: [
                'enquiries_email' => $setting->enquiries_email,
                'enquiries_phone' => $setting->enquiries_phone,
                'security_provider' => $setting->security_provider,
            ],
        );

        return $setting;
    }

    /* ------------------------------------------------------------------ */
    /* board 22 — users & roles */
    /* ------------------------------------------------------------------ */

    /**
     * Everybody who may reach this estate, and what each of them may reach.
     *
     * THE MODULE LIST IS THE MATRIX, READ BACK. Board 22 prints "Dues & ledger,
     * Accounting, Payroll" against the Treasurer and "All modules" against the
     * President. The first is a summary somebody wrote and the second is a
     * derivation; only one of the two can be kept true as the matrix changes,
     * so both are derived here — a role reaches a module when its cell is above
     * `none`, and "All modules" is what it reads when that is true of every
     * module. D-044: where the drawn cells and the model disagree, the model
     * wins, and the difference is recorded rather than reconciled.
     *
     * WHAT EACH ROW MAY HAVE DONE TO IT, SAID BEFORE THE PRESS (M10 §4). Given
     * the viewer, every row carries the sentence that would refuse a role change
     * and the one that would refuse a removal, asked in the same order
     * `manageUser()` and `removeUser()` ask them and worded by the same
     * constants and the same `RoleEditor` guards — so a disabled control and
     * the refusal it stands in for can never say two different things. And
     * which rows are ADMINISTRATORS: the holders of a role that can change
     * roles, which is what "add and remove other administrators" is about.
     *
     * @return array<string, mixed>
     */
    public function usersBoard(string $tenantKey = '', ?User $viewer = null): array
    {
        $moduleCount = Module::query()->where('console', Console::Estate->value)->count();
        $power = RoleEditor::editPermission(Console::Estate);
        $editor = app(RoleEditor::class);

        /*
         * ESTATE ROLES ONLY, AND THIS FILTER IS NOT COSMETIC.
         *
         * Gemini's own Head of Security holds an active assignment to Phoenix
         * Park — that is how a platform role is narrowed to assigned sites, and
         * it is what makes them visible on the estate's own dispatch. They are
         * not a member of this community's committee, they hold no estate role,
         * and listing them on a screen headed "Users & roles" beside a Manage
         * link would invite a committee to try to change the access of somebody
         * who does not work for them. Their module list would also be Gemini's
         * — Clients, Dispatch, Guard workforce — which are not this console's
         * modules at all.
         *
         * THE SAME FENCE GUARDS EVERY WRITE, which it once did not: the list
         * left them out while `manageUser()` and `removeUser()` would act on
         * their row by id, so a committee could suspend Gemini's officer
         * platform-wide or hand a Gemini account an estate role whose scope is
         * every estate. `committee()` is the one definition all of them read.
         */
        $assignments = self::committee($tenantKey)
            ->with(['user', 'role.moduleAccess.module', 'role.permissions'])
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (EstateAssignment $a): string => self::seniority($a->role))
            ->values();

        /*
         * The amber "Owner" badge on exactly one row, and it is DERIVED.
         *
         * The estate's owner is whoever holds the seniormost estate role here —
         * the Community Super Admin where one has been designated, and the
         * President where none has. There is no `is_owner` column, because a
         * stored flag would be a second answer to a question the role already
         * answers, and the two would disagree the first time a committee
         * changed hands. Null on an estate with nobody assigned at all, which
         * draws no badge rather than an arbitrary one.
         */
        $owner = $assignments->first();

        /*
         * WHO ELSE COULD PUT THINGS BACK, READ ONCE (M11 A2). `isLastSuperAdmin()` asks
         * each Community Super Admin's row whether another active one exists — up to
         * three times a row, once for its badge and once for each refusal. The same
         * question for every row is one count per role of the committee's active
         * assignments held by active accounts — `committee()`'s fence, as the question
         * asked row by row has it; a row is the last exactly when that count, less the
         * row itself, is nought.
         */
        $activeHolders = self::committee($tenantKey)
            ->where('is_active', true)
            ->whereHas('user', fn ($user) => $user->where('status', 'active'))
            ->selectRaw('role_id, COUNT(*) AS holders')
            ->groupBy('role_id')
            ->pluck('holders', 'role_id')
            ->map(static fn ($n): int => (int) $n)
            ->all();

        /*
         * AND EVERY ROW'S REFUSALS IN ONE READING PASS of the role editor, so the
         * question it asks of each row's role — does it carry the power to change
         * roles — is answered from the permissions loaded with the row rather than
         * asked of the database again. The board writes nothing. The removed
         * members' list is drawn in the same pass, for the same question.
         */
        $draw = fn (): array => [
            'rows' => $this->userRows($assignments, $owner, $viewer, $editor, $moduleCount, $power, $activeHolders),
            'removed' => $this->removedRows($tenantKey, $viewer, $editor, $power),
        ];

        $board = $viewer === null ? $draw() : $editor->whileReading($viewer, $draw);

        return [
            'rows' => $board['rows'],
            'removed' => $board['removed'],
            'owner_role' => $owner === null ? null : $owner->role->name,
        ];
    }

    /**
     * Board 22's rows — `usersBoard()`, row by row.
     *
     * @param  Collection<int, EstateAssignment>  $assignments
     * @param  array<int, int>  $activeHolders  role id => active assignments to active accounts
     * @return list<array<string, mixed>>
     */
    private function userRows($assignments, ?EstateAssignment $owner, ?User $viewer, RoleEditor $editor, int $moduleCount, string $power, array $activeHolders): array
    {
        $rows = [];

        foreach ($assignments as $assignment) {
            $user = $assignment->user;
            $role = $assignment->role;

            $reachable = $role->moduleAccess
                ->filter(fn (RoleModuleAccess $cell): bool => $cell->grantsView())
                ->sortBy(fn (RoleModuleAccess $cell): int => $cell->module->sort)
                ->map(fn (RoleModuleAccess $cell): string => $cell->module->label)
                ->values()
                ->all();

            $rows[] = [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $this->initials($user->name),
                'role' => $role->name,
                'role_label' => (string) ($role->label ?? $role->name),

                // "President · Owner" — the middot is literal on the board, and
                // it is assembled here so no template has to know the rule.
                'role_badge' => $assignment->is($owner)
                    ? (string) ($role->label ?? $role->name).' · Owner'
                    : (string) ($role->label ?? $role->name),
                'is_owner' => $assignment->is($owner),

                'modules' => count($reachable) === $moduleCount && $moduleCount > 0
                    ? 'All modules'
                    : implode(', ', $reachable),
                'module_list' => $reachable,

                'status' => $user->status,
                // The list's words (M99): an account is Active or Inactive. It read "Suspended", a word on no list.
                'status_label' => $user->status === 'active' ? 'Active' : 'Inactive',

                'assignment_id' => $assignment->getKey(),

                // Which of the three kinds of role this is, so the screen can
                // say "this community's own" beside a role nobody else has.
                'role_is_system' => $role->is_system,
                'role_is_own' => $role->tenant_id !== null,

                /*
                 * THE OWNER'S ROW CANNOT BE MANAGED FROM HERE, and that is not
                 * a permission — it is the same rule every access system needs:
                 * the seniormost officer changing their own role, or being
                 * suspended, would leave an estate with nobody who can restore
                 * it. The reason says which of the two applies.
                 */
                'manageable' => ! $assignment->is($owner),

                // Holds a role that can change what every role here may do.
                'is_administrator' => $role->permissions->contains('name', $power),
                'is_last_super_admin' => $this->isLastSuperAdmin($assignment, $activeHolders),
                'is_self' => $viewer !== null && $assignment->user_id === $viewer->id,

                'manage_refusal' => $viewer === null ? null : $this->manageRefusal($assignment, $owner, $viewer, $editor, $activeHolders),
                'remove_refusal' => $viewer === null ? null : (
                    $assignment->user_id === $viewer->id
                        ? self::NOT_YOURSELF
                        : $this->manageRefusal($assignment, $owner, $viewer, $editor, $activeHolders)
                ),
            ];
        }

        return $rows;
    }

    /**
     * Board 22's removed members — `usersBoard()`'s second list.
     *
     * @return list<array<string, mixed>>
     */
    private function removedRows(string $tenantKey, ?User $viewer, RoleEditor $editor, string $power): array
    {
        /*
         * WHO WAS TAKEN OFF THE COMMITTEE, AND THE WAY BACK (M10 §4).
         *
         * A removal is a decision somebody can get wrong, or one a returning
         * Treasurer outlives, and until this list existed nobody on the estate
         * could undo it: the person left the table above, and inviting their
         * address again is refused because the account still exists. So the
         * committee's former members are listed here with Reinstate beside
         * them — the same fence as the rows above, so a Gemini officer whose site
         * assignment lapsed is never offered to a committee to bring back — and
         * each carries the sentence `reinstateUser()` would refuse with.
         *
         * AN ACCOUNT THAT NOW BELONGS TO ANOTHER ESTATE IS NOT HERE. A person
         * belongs to one estate, and bringing them back would hand them a role
         * that replaces the one they hold there.
         */
        $removed = [];

        $former = self::committee($tenantKey)
            ->with(['user', 'role.permissions'])
            ->where('is_active', false)
            ->whereDoesntHave('user.assignments', fn ($other) => $other->where('is_active', true))
            ->get()
            ->sortBy(fn (EstateAssignment $a): string => mb_strtolower($a->user->name))
            ->values();

        foreach ($former as $assignment) {
            $user = $assignment->user;
            $role = $assignment->role;

            $removed[] = [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $this->initials($user->name),
                'role' => $role->name,
                'role_label' => (string) ($role->label ?? $role->name),
                'is_administrator' => $role->permissions->contains('name', $power),
                'assignment_id' => $assignment->getKey(),
                'reinstate_refusal' => $viewer === null ? null : $this->reinstateRefusal($assignment, $tenantKey, $viewer, $editor),
            ];
        }

        return $removed;
    }

    /**
     * The estate's committee: its assignments of an ESTATE account to an ESTATE
     * role — active and removed alike, so a caller says which it wants.
     *
     * THIS IS THE FENCE BOARD 22'S READS AND WRITES SHARE. Gemini's Head of
     * Security holds an assignment row at every site they cover, carrying this
     * estate's tenant id and a Gemini role; it is how a platform role is
     * narrowed to its sites, and it is Gemini's to grant and withdraw from
     * Platform admins. Nothing a committee does may reach it, and a query that
     * filtered on the tenant alone would.
     *
     * @return Builder<EstateAssignment>
     */
    private static function committee(string $tenantKey): Builder
    {
        return EstateAssignment::query()
            ->where('tenant_id', $tenantKey)
            ->whereHas('role', fn ($role) => $role->where('console', Console::Estate->value))
            ->whereHas('user', fn ($user) => $user->where('console', Console::Estate->value));
    }

    /**
     * How many people sit on the estate's committee now: the active assignments
     * inside `committee()`'s fence, one a person. What a committee meeting's
     * quorum is a head count of (M60 §C2).
     */
    public static function committeeSize(string $tenantKey): int
    {
        return self::committee($tenantKey)->where('is_active', true)->distinct()->count('user_id');
    }

    /**
     * The console emails of the people on the estate's committee now, inside `committee()`'s fence, lowercased: how a
     * committee meeting finds its members' own households by phone (M73 §1, step 6; `PushAnnouncer::committeeHouseholds`).
     *
     * @return list<string>
     */
    public static function committeeEmails(string $tenantKey): array
    {
        return self::committee($tenantKey)
            ->where('is_active', true)
            ->with('user:id,email')
            ->get()
            ->map(static fn (EstateAssignment $assignment): string => mb_strtolower(trim((string) $assignment->user->email)))
            ->filter(static fn (string $email): bool => $email !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The same fence as `committee()`, asked of one row already in hand — this
     * estate's, an estate account, an estate role.
     */
    private static function onCommittee(EstateAssignment $assignment, string $tenantKey): bool
    {
        $assignment->loadMissing(['role', 'user']);

        return $assignment->tenant_id === $tenantKey
            && $assignment->role->console === Console::Estate
            && $assignment->user->console === Console::Estate;
    }

    /**
     * Change what somebody may do here, or withdraw their access (12 §2).
     *
     * TWO ACTS ON ONE PANEL, and they are different in kind. A role change
     * changes what they may do; a suspension stops them doing anything. Both
     * are recorded, because both are decisions about a person's standing in a
     * community and neither should be explicable only by somebody's memory.
     *
     * THE ESTATE'S OWNER IS NOT MANAGEABLE FROM HERE. The seniormost officer
     * demoting themselves, or being suspended, leaves an estate with nobody who
     * can undo it — and the screen says so rather than failing on the press.
     *
     * NOTHING IS DELETED. An assignment is deactivated and the user's status
     * changes; the row stays, because who held what and when is the history an
     * audit of a decision reads.
     *
     * ANY ROLE THIS ESTATE CAN SEE, AND NO OTHER (M10 §4). The eight shared
     * estate roles and this estate's own, resolved through the same tenant fence
     * as the invite list — so a role another estate made is refused by name here
     * exactly as a Gemini role is, in words that do not disclose it exists.
     *
     * FIVE GUARDS STAND IN FRONT OF THE CHANGE, and each says why:
     *
     *   - nobody changes their own role or suspends themselves (M11 B3). Whoever
     *     holds Manage people could otherwise seat THEMSELVES as Treasurer: a
     *     copy of the Property Manager given Settings' Manage people — Settings is
     *     not a locked module — could commission work and then take the money
     *     approvals that pay for it, which is exactly the separation Ruling 1
     *     (D-010) exists to keep, arrived at through the committee screen. The
     *     Gemini console's Platform admins has refused this from the start.
     *   - the last active Community Super Admin keeps the role and their access,
     *     keyed on the role's machine name and not on a derived rank, because
     *     it is the one role here that can change permissions (§4's own words)
     *   - the estate's seniormost officer — derived, see `seniority()` — is not
     *     managed from here at all
     *   - a role that can change roles is handed out only by somebody who holds
     *     that power (`RoleEditor::assertMayHandOut()`)
     *   - somebody holding such a role is managed only by somebody who does too
     *     (`RoleEditor::assertMayManageHolderOf()`)
     *
     * AND IN FRONT OF ALL FIVE, WHETHER THIS IS A COMMITTEE MEMBER AT ALL:
     * `committee()`'s fence, asked of the row. Gemini's Head of Security holds
     * an active assignment here, and without it one POST re-roled that account
     * into an estate role — whose scope is every estate — or suspended the
     * officer this site's panics escalate to. A removed member is refused too:
     * a role handed to somebody still off the committee reaches nothing, and
     * bringing them back is `reinstateUser()`'s act. Asked FIRST, in the one
     * sentence another estate's row and a missing row get, so no id answers
     * differently from any other before the role name is even read.
     *
     * NO CACHE FLUSH, AND THAT IS NOT AN OVERSIGHT. spatie caches what each ROLE
     * may do, which this does not change; which role a PERSON holds is read
     * fresh on every request, so the new role and the suspension both apply to
     * that person's very next request with nothing to clear.
     */
    public function manageUser(EstateAssignment $assignment, string $roleName, string $status, User $by): EstateAssignment
    {
        $tenantKey = (string) tenant()->getTenantKey();

        if (! $assignment->is_active || ! self::onCommittee($assignment, $tenantKey)) {
            throw new DomainException(self::NOT_ON_COMMITTEE);
        }

        if ($assignment->user_id === $by->id) {
            throw new DomainException(self::NOT_YOUR_OWN_ROLE);
        }

        if (! in_array($status, ['active', 'suspended'], true)) {
            throw new DomainException('An account here is active or suspended. Nothing deletes a person from an estate\'s history.');
        }

        // The role THIS estate means by the name: its own version of a shared role where it has one (M11 B4).
        $role = Role::forEstate($roleName, $tenantKey);

        if ($role !== null && $role->console !== Console::Estate) {
            $role = null;
        }

        if ($role === null) {
            throw new DomainException('That is not one of this estate\'s roles. An estate assigns the roles every community shares and the ones it made itself, and never a Gemini one.');
        }

        $assignment->loadMissing(['role', 'user']);

        if ($this->isLastSuperAdmin($assignment) && ($role->standsFor() !== Role::COMMUNITY_SUPER_ADMIN || $status !== 'active')) {
            throw new DomainException(self::LAST_SUPER_ADMIN);
        }

        $this->assertNotOwner($assignment, $tenantKey);

        $roles = app(RoleEditor::class);
        $roles->assertMayManageHolderOf($by, $assignment->role);

        if ($assignment->role_id !== $role->id) {
            $roles->assertMayHandOut($by, $role);
        }

        $before = [
            'role' => $assignment->role->name,
            'status' => $assignment->user->status,
        ];

        DB::connection('mysql')->transaction(function () use ($assignment, $role, $status): void {
            $user = $assignment->user;

            if ($assignment->role_id !== $role->id) {
                $assignment->forceFill(['role_id' => $role->id])->save();

                // The platform role and the estate assignment are two records of
                // one fact, and a screen that changed only one would give
                // somebody a title here and the old permissions everywhere.
                $user->syncRoles([$role->name]);
            }

            if ($user->status !== $status) {
                $user->forceFill(['status' => $status])->save();
            }
        });

        $this->audit(
            action: 'estate.user_managed',
            by: $by,
            entityType: 'User',
            entityId: (string) $assignment->user_id,
            before: $before,
            after: ['role' => $role->name, 'status' => $status],
        );

        return $assignment->fresh(['user', 'role']);
    }

    /**
     * Take somebody off this estate's committee (M10 §4, "remove users").
     *
     * REMOVED, NOT DELETED. The assignment is deactivated, the account is
     * suspended and it is left holding no role, so it reaches nothing anywhere;
     * the user row and the assignment row both stay, because who sat on a
     * committee, in what role and until when, is what an audit of any decision
     * they took reads. An estate account belongs to one estate
     * (`User::canAccessEstate`), so there is no second community this could
     * strand them in.
     *
     * The same guards as `manageUser()` — the committee fence first, then the
     * last Community Super Admin, the seniormost officer, and an administrator
     * removed only by an administrator — and one of its own: nobody removes
     * themselves, because a slip would lock them out with nobody watching.
     *
     * NOT FINAL. `reinstateUser()` puts back exactly what this takes away, and
     * board 22 lists whoever was removed with the button that does it.
     */
    public function removeUser(EstateAssignment $assignment, User $by): EstateAssignment
    {
        $tenantKey = (string) tenant()->getTenantKey();

        if (! $assignment->is_active || ! self::onCommittee($assignment, $tenantKey)) {
            throw new DomainException(self::NOT_ON_COMMITTEE);
        }

        if ($assignment->user_id === $by->id) {
            throw new DomainException(self::NOT_YOURSELF);
        }

        if ($this->isLastSuperAdmin($assignment)) {
            throw new DomainException(self::LAST_SUPER_ADMIN);
        }

        $this->assertNotOwner($assignment, $tenantKey);

        app(RoleEditor::class)->assertMayManageHolderOf($by, $assignment->role);

        $before = [
            'role' => $assignment->role->name,
            'status' => $assignment->user->status,
            'is_active' => true,
        ];

        DB::connection('mysql')->transaction(function () use ($assignment): void {
            $assignment->forceFill(['is_active' => false])->save();

            $assignment->user->forceFill(['status' => 'suspended'])->save();
            $assignment->user->syncRoles([]);
        });

        $this->audit(
            action: 'estate.user_removed',
            by: $by,
            entityType: 'User',
            entityId: (string) $assignment->user_id,
            before: $before,
            after: ['role' => null, 'status' => 'suspended', 'is_active' => false],
        );

        return $assignment->fresh(['user', 'role']);
    }

    /**
     * Bring somebody taken off this estate's committee back onto it (M10 §4).
     *
     * THE EXACT INVERSE OF `removeUser()`, AND NOTHING MORE. Their assignment is
     * active again, their account is active again, and they hold again the role
     * the assignment still names — the one they held when they were removed,
     * which the removal kept for exactly this and for the audit. Anything else
     * about them is the Manage panel's, afterwards, like anybody's.
     *
     * WITHOUT THIS A REMOVAL WAS FOR EVER. The person left board 22, inviting
     * their address again is refused because the account exists, and the only
     * way back was an operator's artisan command — for a Treasurer removed by
     * mistake, or re-elected next term.
     *
     * THREE REFUSALS, each the one `reinstateRefusal()` prints on the row first:
     *
     *   - somebody who is not a removed member of THIS committee — still on it,
     *     another estate's, Gemini's own staff, or no row at all — in one
     *     sentence, so an id says nothing about anybody else's
     *   - an account that has since been given access to another estate, which
     *     the same one sentence refuses: a person belongs to one estate, and the
     *     role this hands back would replace the one they hold there
     *   - a role that can change roles, handed back only by somebody who holds
     *     that power (`RoleEditor::assertMayHandOut()`), exactly as handing it
     *     out the first time is
     */
    public function reinstateUser(EstateAssignment $assignment, User $by): EstateAssignment
    {
        $tenantKey = (string) tenant()->getTenantKey();
        $editor = app(RoleEditor::class);

        $refusal = $this->reinstateRefusal($assignment, $tenantKey, $by, $editor);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        $before = [
            'role' => null,
            'status' => $assignment->user->status,
            'is_active' => false,
        ];

        DB::connection('mysql')->transaction(function () use ($assignment): void {
            $assignment->forceFill(['is_active' => true])->save();

            $assignment->user->forceFill(['status' => 'active'])->save();

            // The platform role and the estate assignment are two records of one
            // fact, and `removeUser()` cleared the first while keeping the second.
            $assignment->user->syncRoles([$assignment->role->name]);
        });

        $this->audit(
            action: 'estate.user_reinstated',
            by: $by,
            entityType: 'User',
            entityId: (string) $assignment->user_id,
            before: $before,
            after: ['role' => $assignment->role->name, 'status' => 'active', 'is_active' => true],
        );

        return $assignment->fresh(['user', 'role']);
    }

    /**
     * The sentence that would refuse reinstating this person, or null if this
     * viewer may — asked in `reinstateUser()`'s order, for board 22 to print
     * beside the row before anybody presses anything.
     */
    private function reinstateRefusal(EstateAssignment $assignment, string $tenantKey, User $viewer, RoleEditor $editor): ?string
    {
        if ($assignment->is_active || ! self::onCommittee($assignment, $tenantKey)) {
            return self::NOT_REMOVED_HERE;
        }

        // One estate per person: an account active anywhere now is not ours to
        // bring back, and its role is not ours to replace.
        $elsewhere = EstateAssignment::query()
            ->where('user_id', $assignment->user_id)
            ->where('is_active', true)
            ->exists();

        // And the role they held must still be one this estate may hand out —
        // never another estate's, whatever a row somewhere says.
        $roleHere = Role::query()
            ->whereKey($assignment->role_id)
            ->where('console', Console::Estate->value)
            ->ownedBy($tenantKey)
            ->exists();

        if ($elsewhere || ! $roleHere) {
            return self::NOT_REMOVED_HERE;
        }

        try {
            $editor->assertMayHandOut($viewer, $assignment->role);
        } catch (DomainException $refused) {
            return $refused->getMessage();
        }

        return null;
    }

    /**
     * How senior a role is, for deciding who owns an estate: "0-00005" sorts
     * before "1-00001".
     *
     * THE SEEDED ROLES FIRST, THEN SORT — AND THIS IS A SECURITY RULE, NOT A
     * DISPLAY ONE. The Owner badge, and with it who cannot be managed from here,
     * is the lowest-ranked active assignment. Ranked on `sort` alone, a role an
     * estate made at runtime with a low sort would take ownership from the
     * Community Super Admin, and the real senior officer would become demotable
     * by whoever held the new role. A role made here can never outrank one the
     * platform seeded.
     */
    private static function seniority(Role $role): string
    {
        return sprintf('%d-%05d', $role->is_system ? 0 : 1, $role->sort);
    }

    /**
     * The estate's seniormost active officer — see `seniority()` — refused by
     * name. Found among the same committee board 22 lists and badges, so the
     * row drawn as the Owner and the row refused as one are always the same.
     */
    private function assertNotOwner(EstateAssignment $assignment, string $tenantKey): void
    {
        $owner = self::committee($tenantKey)
            ->with('role')
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (EstateAssignment $a): string => self::seniority($a->role))
            ->first();

        if ($owner !== null && $assignment->is($owner)) {
            throw new DomainException(self::SENIORMOST_OFFICER);
        }
    }

    /**
     * The sentence that would refuse changing this person's role or standing,
     * or null if this viewer may — for board 22 to print beside the row.
     *
     * THE SAME FOUR QUESTIONS IN THE SAME ORDER as `manageUser()` asks them, and
     * the fourth is asked of `RoleEditor` itself and its own words kept: the
     * viewer's own row, the last Community Super Admin, then the seniormost
     * officer, then whether an administrator is being managed by somebody who is
     * not one. A row the screen draws as manageable is a row the server will let
     * this viewer manage, and a row it draws as refused says exactly what the
     * server would.
     *
     * @param  array<int, int>|null  $activeHolders  as `usersBoard()` reads them, or null to ask
     */
    private function manageRefusal(EstateAssignment $assignment, ?EstateAssignment $owner, User $viewer, RoleEditor $editor, ?array $activeHolders = null): ?string
    {
        if ($assignment->user_id === $viewer->id) {
            return self::NOT_YOUR_OWN_ROLE;
        }

        if ($this->isLastSuperAdmin($assignment, $activeHolders)) {
            return self::LAST_SUPER_ADMIN;
        }

        if ($owner !== null && $assignment->is($owner)) {
            return self::SENIORMOST_OFFICER;
        }

        try {
            $editor->assertMayManageHolderOf($viewer, $assignment->role);
        } catch (DomainException $refused) {
            return $refused->getMessage();
        }

        return null;
    }

    /**
     * Is this assignment the last active Community Super Admin on its estate?
     *
     * Keyed on the role's machine name and counted over active assignments to
     * active accounts, so a suspended second Super Admin does not count as the
     * one who could put things back — and over this estate's own committee, so
     * nothing but an estate account ever counts as that second one.
     *
     * @param  array<int, int>|null  $activeHolders  as `usersBoard()` reads them, or null to ask
     */
    private function isLastSuperAdmin(EstateAssignment $assignment, ?array $activeHolders = null): bool
    {
        if (! $assignment->is_active || $assignment->role->standsFor() !== Role::COMMUNITY_SUPER_ADMIN) {
            return false;
        }

        /*
         * The board's own count of active holders per role (M11 A2): another active
         * holder exists exactly when that count, less this assignment if it is one of
         * them, is above nought — the question the query below asks, answered once for
         * every row. The count is taken over `committee()`, the same fence as the
         * query, and only rows the board read from that committee are handed it.
         * Every write path passes nothing and asks the database.
         */
        if ($activeHolders !== null) {
            $counted = $assignment->user->status === 'active' ? 1 : 0;

            return (($activeHolders[(int) $assignment->role_id] ?? 0) - $counted) <= 0;
        }

        $others = self::committee($assignment->tenant_id)
            ->where('is_active', true)
            ->where('role_id', $assignment->role_id)
            ->whereKeyNot($assignment->getKey())
            ->whereHas('user', fn ($user) => $user->where('status', 'active'))
            ->exists();

        return ! $others;
    }

    /**
     * The roles an estate may invite somebody into: the eight every community
     * shares and this estate's own, seeded first and then in the matrix's order,
     * each with the modules it reaches so the inviter can see what they are
     * handing out before they hand it out.
     *
     * THE TENANT FENCE IS HERE AND IT IS THE WHOLE POINT (M10 §4). Filtered on
     * console alone, this listed every estate's custom roles to every estate
     * from the day the first one was made. `gate:isolation` STEP 10 runs it
     * inside a second estate and fails if the first estate's role appears.
     *
     * GIVEN THE VIEWER, EACH ROLE SAYS WHETHER THEY MAY HAND IT OUT, and if not,
     * why — `RoleEditor::assertMayHandOut()`'s own sentence, caught rather than
     * re-worded. A role that can change roles is an ADMINISTRATOR'S role and is
     * handed out only by an administrator, so the screen can mark it and say so
     * before the press rather than after.
     *
     * @return list<array{name: string, label: string, modules: string, is_system: bool, is_own: bool, is_administrator: bool, hand_out_refusal: string|null}>
     */
    public function invitableRoles(?User $viewer = null): array
    {
        $moduleCount = Module::query()->where('console', Console::Estate->value)->count();
        $power = RoleEditor::editPermission(Console::Estate);
        $editor = app(RoleEditor::class);

        $roles = Role::query()
            ->with(['moduleAccess.module', 'permissions'])
            ->where('console', Console::Estate->value)
            ->visibleTo((string) tenant()?->getTenantKey())
            ->orderByRaw('CASE WHEN is_system = 1 OR version_of_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        /*
         * EVERY ROLE'S REFUSAL IN ONE READING PASS (M11 A2), as `usersBoard()` draws its
         * rows: `assertMayHandOut()` asks each role whether it carries the power to change
         * roles, and inside a pass that is answered from the permissions loaded above
         * rather than asked of the database once a role. The list writes nothing; the
         * invitation it leads to asks afresh, outside any pass.
         */
        return $viewer === null
            ? $this->invitableRows($roles, $moduleCount, $power, $editor, null)
            : $editor->whileReading($viewer, fn (): array => $this->invitableRows($roles, $moduleCount, $power, $editor, $viewer));
    }

    /**
     * `invitableRoles()`, role by role.
     *
     * @param  Collection<int, Role>  $roles
     * @return list<array{name: string, label: string, modules: string, is_system: bool, is_own: bool, is_administrator: bool, hand_out_refusal: string|null}>
     */
    private function invitableRows($roles, int $moduleCount, string $power, RoleEditor $editor, ?User $viewer): array
    {
        return $roles
            ->map(function (Role $role) use ($moduleCount, $power, $editor, $viewer): array {
                $refusal = null;

                if ($viewer !== null) {
                    try {
                        $editor->assertMayHandOut($viewer, $role);
                    } catch (DomainException $refused) {
                        $refusal = $refused->getMessage();
                    }
                }

                $reachable = $role->moduleAccess
                    ->filter(fn (RoleModuleAccess $cell): bool => $cell->grantsView())
                    ->sortBy(fn (RoleModuleAccess $cell): int => $cell->module->sort)
                    ->map(fn (RoleModuleAccess $cell): string => $cell->module->label)
                    ->values()
                    ->all();

                return [
                    'name' => $role->name,
                    'label' => (string) ($role->label ?? $role->name),
                    'modules' => count($reachable) === $moduleCount && $moduleCount > 0
                        ? 'All modules'
                        : implode(', ', $reachable),
                    'is_system' => $role->is_system,
                    'is_own' => $role->tenant_id !== null,
                    'is_administrator' => $role->permissions->contains('name', $power),
                    'hand_out_refusal' => $refusal,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Invitations to this estate still open, or lapsed and not yet withdrawn.
     *
     * NEVER THE TOKEN. It is the credential until it is accepted, and the
     * screen that lists who was invited has no business holding the link.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingInvitations(string $tenantKey): array
    {
        return Invitation::query()
            ->with(['inviter', 'role'])
            ->where('tenant_id', $tenantKey)
            ->whereNull('accepted_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_label' => (string) ($invitation->role->label ?? $invitation->role->name),
                'invited_by' => $invitation->inviter->name,
                // When it was MADE — whether and when its email went is `delivery`, below.
                'invited_on' => $invitation->created_at?->format('M j'),
                'expires_on' => $invitation->expires_at->format('M j'),
                'expired' => $invitation->isExpired(),
                // Whether its email went, in the row (DESIGN §11 row 1): `delivery`, `delivery_state`, `last_try`, `sent_at`, `resend_after`.
                ...Invitations::deliveryShape($invitation),
            ])
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* board 23 — feature toggles */
    /* ------------------------------------------------------------------ */

    /**
     * Every feature this estate's plan offers it, and what the estate decided.
     *
     * THE EFFECTIVE ANSWER IS RESOLVED, NEVER READ FROM ONE PLACE:
     *
     *     locked core   → on, and not switchable at any tier
     *     override      → whatever this community decided, with who and why
     *     otherwise     → whether the estate's plan includes it
     *
     * That ordering is why `estate_features` holds overrides only. An estate
     * with a row per feature would look decided when nothing had been decided,
     * and the day the plan changed, eleven stale copies of its old answer would
     * outvote it.
     *
     * @return array<string, mixed>
     */
    public function featuresBoard(string $tenantKey = ''): array
    {
        $setting = EstateSetting::current();
        $subscription = Subscription::query()->with('plan')->where('tenant_id', $tenantKey)->first();
        $planKey = $subscription === null ? null : $subscription->plan->key;

        $overrides = $this->overrides();
        $catalogue = $this->catalogue();

        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];

        foreach ($catalogue as $feature) {
            $tier = $feature['tier'];

            // A feature no plan includes at all has no tier and no home on this
            // screen. It is a catalogue row somebody is still drafting, and
            // drawing it would offer a community something nobody sells.
            if ($tier === null) {
                continue;
            }

            $override = $overrides[$feature['key']] ?? null;

            /*
             * NO PLAN ON RECORD SWITCHES NOTHING OFF (the Settings pass, 12 Oct). An estate still being set up has no
             * subscription, and `PlanGate` closes no module for it; this page says the same rather than greying every
             * row as "not included" in a plan that does not exist.
             */
            $available = $feature['is_core'] || $planKey === null || $this->planIncludes($planKey, $feature['key']);
            $planName = $subscription === null ? null : (string) $subscription->plan->name;

            $grouped[$tier][] = $feature['key'] === 'estate_payroll'
                ? $this->payrollRoutingRow($feature, $setting, $available, $planName)
                : $this->featureRow(
                    $feature,
                    $override,
                    $available,
                    $feature['kind'] === 'tier_value' ? $this->tierValueFor($planKey, $feature['key']) : null,
                    $planName,
                );

            /*
             * BOARD 23 DRAWS PAYROLL AS TWO ROWS AND THIS NOW EMITS ONE (M10 §9).
             *
             * The second was the guards', emitted here rather than left to a
             * template to remember, and drawn locked with a paragraph saying why
             * it could not be moved. The client has removed it: guards are Gemini
             * Security Limited's employees, the estate has no say in how they are
             * paid, so the choice does not belong on their settings screen at all
             * — not even as a control that refuses.
             *
             * A locked control is still a question. It puts the choice in front
             * of a committee, invites them to ask why they cannot make it, and
             * has to be answered on every estate's screen for ever. Nothing
             * replaces it; the fact is already where it is useful, on the Payroll
             * & HR screen that lists the estate's own employees and not the
             * guards.
             *
             * `Settings::setRouting()` still refuses the key — a control nobody
             * draws is still one somebody can post to — but with the generic
             * refusal now, because there is no longer a specific thing to refuse.
             */
        }

        $groups = [];

        foreach (self::TIER_GROUPS as $tier => $heading) {
            if (! isset($grouped[$tier])) {
                continue;
            }

            $groups[] = ['key' => $tier, 'heading' => $heading, 'rows' => $grouped[$tier]];
        }

        // What the plan leaves out, read from the rows themselves before the
        // held-back group joins them — the note below is built from exactly
        // what the screen will grey out, so the two cannot disagree.
        $greyed = [];

        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                if (! $row['available']) {
                    $greyed[] = (string) $row['label'];
                }
            }
        }

        $heldBack = $this->heldBackRows($setting);

        $groups[] = [
            'key' => 'held_back',
            'heading' => 'Held back in this release',
            'rows' => $heldBack,
        ];

        return [
            'plan' => [
                'key' => $planKey,
                'name' => $subscription === null ? null : $subscription->plan->name,

                // "Phoenix Park Village 1 is on the Premium plan" — the estate's
                // name and the plan's, joined here so the page never has to.
                'banner' => $subscription === null
                    ? 'This estate has no subscription on record.'
                    : sprintf('%s is on the %s plan', (string) tenant()?->name, $subscription->plan->name),
                'note' => $this->planNote($subscription?->plan->name, $greyed, count($heldBack)),
            ],
            'groups' => $groups,
            'audit_note' => self::FEATURES_AUDIT_NOTE,
        ];
    }

    /**
     * The sentence under board 23's plan banner, and it is built, not fixed.
     *
     * THE BOARD'S OWN COPY WAS TRUE OF THE BOARD'S OWN ESTATE. "Every module
     * below is included" is what Phoenix Park, on Premium, is shown — and it was
     * printed on every plan, above rows saying "Not included in this estate's
     * plan" against everything Standard leaves out. So it is said only when it is
     * so, and otherwise the note names what is greyed and why, in the words the
     * rows themselves use. The held-back group is never counted as included: it
     * is held for every estate, whatever it pays, and the note says that too.
     *
     * @param  list<string>  $greyed  the labels of the plan rows this estate's plan leaves out
     */
    private function planNote(?string $planName, array $greyed, int $heldBack): string
    {
        $keeps = 'Downgrading greys out features but never deletes their data.';

        if ($planName === null) {
            return 'This estate has no plan on record yet, so nothing has been switched off. A plan is set by '.
                'Gemini Security against the subscription.';
        }

        if ($greyed === []) {
            return sprintf(
                'Every feature the %s plan lists below is included%s. %s',
                $planName,
                $heldBack > 0 ? '; the ones held back in this release are held back for every estate' : '',
                $keeps,
            );
        }

        $count = count($greyed);
        $names = $count === 1
            ? $greyed[0]
            : implode(', ', array_slice($greyed, 0, -1)).' and '.$greyed[$count - 1];

        return sprintf(
            '%s not included in the %s plan and %s greyed out below: %s. Adding one is a commercial change made by '.
            'Gemini Security. %s',
            $count === 1 ? 'One feature is' : $count.' features are',
            $planName,
            $count === 1 ? 'is' : 'are',
            $names,
            $keeps,
        );
    }

    /**
     * Switch one feature on or off for this estate.
     *
     * FOUR REFUSALS, AND NONE OF THEM IS VALIDATION. A core feature is not
     * optional at any tier; a feature the plan does not include cannot be
     * switched on by the community that is not paying for it; a feature the
     * catalogue does not offer is not a feature; and a change with no stated
     * reason is one nobody can question a year from now. Each throws
     * `DomainException` naming the rule, because the person who hits it has to
     * be told what to do instead.
     */
    public function setFeature(string $key, bool $enabled, string $reason, User $by, string $tenantKey = ''): EstateFeature
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'A feature change needs a stated reason. Turning a module off changes what several hundred '.
                'households can do, and an unexplained change to that is exactly what an audit has to be able '.
                'to question.'
            );
        }

        $feature = null;

        foreach ($this->catalogue() as $row) {
            if ($row['key'] === $key) {
                $feature = $row;

                break;
            }
        }

        if ($feature === null) {
            throw new DomainException(
                "No feature [{$key}] in the platform catalogue. A switch for something that is not on offer would ".
                'record a decision about nothing.'
            );
        }

        if ($feature['is_core']) {
            throw new DomainException(sprintf(
                '"%s" is a core feature and is on at every tier. The panic button, visitor passes and amenity '.
                'booking are not behind a price tier and are not an estate setting.',
                $feature['label'],
            ));
        }

        if ($feature['kind'] === 'tier_value') {
            throw new DomainException(sprintf(
                '"%s" is not a switch. Every tier has it and the tiers differ in how much of it: how much this '.
                'estate gets is what its plan grants, so there is nothing here to turn off.',
                $feature['label'],
            ));
        }

        $subscription = Subscription::query()->with('plan')->where('tenant_id', $tenantKey)->first();

        if ($enabled && ! $this->planIncludes($subscription?->plan->key, $key)) {
            throw new DomainException(sprintf(
                '"%s" is not included in this estate\'s plan, so it cannot be switched on here. Adding it is a '.
                'commercial change and is made by Gemini Security against the subscription.',
                $feature['label'],
            ));
        }

        $existing = EstateFeature::query()->where('feature_key', $key)->first();
        $was = $existing?->enabled;

        $record = EstateFeature::updateOrCreate(
            ['feature_key' => $key],
            [
                'enabled' => $enabled,
                'changed_by' => $by->getKey(),
                'changed_by_name' => $by->name,
                'reason' => $reason,
            ],
        );

        $this->audit(
            action: 'estate.settings.feature_toggled',
            by: $by,
            entityType: 'estate_feature',
            entityId: $key,
            before: ['enabled' => $was],
            after: ['enabled' => $enabled, 'reason' => $reason],
        );

        return $record;
    }

    /**
     * Choose which system runs the estate's own staff payroll.
     *
     * THERE IS EXACTLY ONE ROUTING AN ESTATE MAY SET, AND IT IS ITS OWN STAFF'S.
     * Guards are Gemini Security Limited's employees, paid by Gemini, filed
     * under Gemini's TRN — an estate that routed guard pay in-house would be
     * claiming to employ people it does not employ and posting a payroll
     * liability it has no obligation to settle.
     *
     * That used to be a named refusal here beneath a locked control on the
     * settings screen. Both are gone (M10 §9): the client has removed the guards'
     * routing entirely rather than showing a choice that only ever says no, and
     * `security_payroll` now falls to the same refusal as any other key nobody
     * offers. The guard rail is unchanged in force; it is one sentence shorter.
     */
    public function setRouting(string $key, string $option, string $reason, User $by): EstateSetting
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A routing change needs a stated reason.');
        }

        if ($key !== 'estate_payroll') {
            throw new DomainException("No payroll routing [{$key}]. The estate routes its own staff payroll and nothing else.");
        }

        if (! in_array($option, [EstateSetting::IN_HOUSE, EstateSetting::GEMINI_MANAGED], true)) {
            throw new DomainException(
                "No routing option [{$option}]. Staff payroll is run in-house or managed in the Gemini Console."
            );
        }

        $setting = EstateSetting::current();
        $was = $setting->staff_payroll_routing;

        $setting->fill(['staff_payroll_routing' => $option])->save();

        $this->audit(
            action: 'estate.settings.payroll_routing_changed',
            by: $by,
            entityType: 'estate_setting',
            entityId: (string) $setting->getKey(),
            before: ['staff_payroll_routing' => $was],
            after: ['staff_payroll_routing' => $option, 'reason' => $reason],
        );

        return $setting;
    }

    /* ------------------------------------------------------------------ */
    /* board 24 — the role access matrix, read from the permission model */
    /* ------------------------------------------------------------------ */

    /**
     * The matrix as this console actually enforces it.
     *
     * FOURTEEN MODULES AND EIGHT SHARED ROLES, where the board draws ten and
     * six — and then however many of its own this estate has made. The extra
     * modules are the Ruling 1 split (D-010/D-014) — `payments`, `vendor_costs`
     * and `maintenance_budget` were separated out so a Property Manager could
     * see the costs they commission without ever seeing a resident's financial
     * position — and M10 §2's `security`. The two extra roles are the Community
     * Super Admin, which board 24's own audit note says it deliberately omits,
     * and the estate's own Head of Security (M10 §2).
     *
     * Showing them is the more honest screen. A matrix that hid four modules
     * and two roles would be a permission screen that does not describe the
     * permissions, which is the one thing a permission screen must not be.
     *
     * EVERY ROLE THIS ESTATE CAN SEE, AND NO OTHER ESTATE'S (M10 §4): the eight
     * shared roles, then this estate's own, through `Role::ownedBy()`.
     *
     * EDITABLE BY ONE ROLE, AND THE PAYLOAD SAYS WHICH COLUMNS. `can_edit` is
     * `estate.settings.approve`, which only the Community Super Admin holds.
     * `uneditable_roles` names every column even that viewer cannot change, with
     * the sentence the screen prints: the eight shared roles are Gemini's to
     * set. The editing itself is `App\Services\Rbac\RoleEditor`, whose `board()`
     * carries the full per-role refusals for rename and delete as well.
     *
     * @return array<string, mixed>
     */
    public function matrixBoard(User $viewer): array
    {
        $roles = Role::query()
            ->where('console', Console::Estate->value)
            ->visibleTo((string) tenant()?->getTenantKey())
            ->orderByRaw('CASE WHEN is_system = 1 OR version_of_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $modules = Module::query()
            ->where('console', Console::Estate->value)
            ->orderBy('sort')
            ->get();

        /** @var array<int, array<int, RoleModuleAccess>> $cells role id => module id => cell */
        $cells = [];

        foreach (RoleModuleAccess::query()->whereIn('role_id', $roles->modelKeys())->get() as $cell) {
            $cells[$cell->role_id][$cell->module_id] = $cell;
        }

        $rows = [];

        foreach ($modules as $module) {
            $line = [];

            foreach ($roles as $role) {
                $cell = $cells[$role->getKey()][$module->getKey()] ?? null;

                /*
                 * A missing cell is NO ACCESS, and it is not a defect to
                 * repair on read. `role_module_access` is written by the
                 * matrix seeder for every pair it knows about; a pair it has
                 * not written is a role that has never been granted the module,
                 * and the safe reading of "nobody has said" is "no".
                 */
                /*
                 * `->` and not `?->`, on the left of `??`. The null coalescing
                 * operator already suppresses the whole left-hand expression
                 * when any part of it is null, so a nullsafe here would be
                 * belt-and-braces that reads as though the two operators did
                 * different jobs. `pillLabel()` below keeps its `?->` because
                 * that rule covers property reads and not method calls.
                 */
                $level = $cell->level ?? AccessLevel::None;
                $canApprove = $cell->can_approve ?? false;

                // The module is already in hand; a cell asked for its named
                // acts should not go and fetch it again.
                $cell?->setRelation('module', $module);

                $line[] = [
                    'role' => $role->name,
                    'level' => $level->value,
                    'label' => $level->label(),
                    'can_approve' => $canApprove,

                    /*
                     * THE NAMED ACTS THIS CELL HOLDS ONE BY ONE (M11 B3) —
                     * "Sets dues rates" on the Community Super Admin's Dues &
                     * ledger — or null. Printed on the pill, because "Full" alone
                     * would say the role approves nothing there and the Approver
                     * tag would say it approves everything.
                     */
                    'acts_label' => $cell?->actsLabel(),

                    // "Full · Approver" as one string, for a page that draws the
                    // pill without the nested tag.
                    'pill' => $cell?->pillLabel() ?? $level->label(),
                    'scope' => $cell?->scope->value ?? 'all',
                ];
            }

            $rows[] = [
                'key' => $module->key,
                'label' => $module->label,
                'section' => $module->section,

                /*
                 * Which rows Ruling 1 locks. Carried so the screen can say WHY
                 * the Property Manager's cell reads "—" where board 24 drew
                 * "View": it is a platform invariant and not this estate's
                 * preference, and a committee reading the matrix is exactly who
                 * needs to be told the difference.
                 */
                'locked_financial' => $module->is_locked_financial,
                'cells' => $line,
            ];
        }

        $canEdit = $viewer->can(RoleEditor::editPermission(Console::Estate));

        /*
         * NO ROLE HERE IS UNEDITABLE FOR BEING SHARED ANY MORE (M11 B4): changing a shared role makes
         * this community's own version of it. The key stays, empty, so a screen that reads it is told
         * nothing is refused on that ground — every other refusal is the role editor's, on its board.
         */
        $uneditable = [];

        return [
            'legend' => self::LEGEND,
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => (string) ($role->label ?? $role->name),
                'scope' => $role->scope_default->value,
                'scope_label' => $role->scope_default->label(),

                // The role board 24 does not draw, flagged rather than dropped.
                'is_super_admin' => $role->standsFor() === Role::COMMUNITY_SUPER_ADMIN,

                'is_system' => $role->is_system,
                'is_own' => $role->tenant_id !== null,
            ])->values()->all(),
            'rows' => $rows,
            'audit_note' => self::MATRIX_AUDIT_NOTE,

            'can_edit' => $canEdit,
            // Always the sentence, and printed only when `can_edit` is false:
            // Roles.vue declares it a required string.
            'read_only_reason' => self::MATRIX_READ_ONLY,
            'uneditable_roles' => $uneditable,
            'shared_role_note' => self::SHARED_ROLE_NOTE,

            /*
             * The Property Manager lock, named on the screen. Board 24 draws
             * those two cells as View; D-010 refuses them, the seeder throws
             * rather than granting them, and `EstateNavigationTest` proves the
             * sidebar honours it. Saying so here is what stops somebody reading
             * the difference as a bug.
             */
            'invariant_note' => self::INVARIANT_NOTE,
        ];
    }

    /** The Property Manager lock in words, on the matrix and on a role's own permissions page. */
    public const INVARIANT_NOTE = 'Dues & ledger, Payments, Accounting and Payroll & HR are closed to the Property '.
        'Manager by platform invariant, not by this estate\'s choice: whoever commissions work must never be able to '.
        'pay for it, nor see a resident\'s financial position.';

    /**
     * Who holds one role on this estate now, by name: its active assignments inside `committee()`'s fence, the people
     * `RoleEditor`'s holder count counts. A role's permissions page names them under its heading (Hassam, 12 Oct: "the
     * role's name as the heading, and who holds it"), so whoever changes the role sees whom the change reaches.
     *
     * @return list<string>
     */
    public static function roleHolders(string $tenantKey, int $roleId): array
    {
        return self::committee($tenantKey)
            ->where('is_active', true)
            ->where('role_id', $roleId)
            ->with('user:id,name')
            ->get()
            ->map(static fn (EstateAssignment $assignment): string => trim((string) $assignment->user->name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* board 30 — notification defaults */
    /* ------------------------------------------------------------------ */

    /**
     * Board 30's eighteen switches, grouped exactly as the board draws them.
     *
     * ONE ROW PER (EVENT, CHANNEL), READ BACK INTO A GRID. The six events are
     * `NOTIFICATION_EVENTS`, a constant; `notification_defaults` holds only
     * whether this estate's committee has each of the eighteen on. A pair the
     * table has no row for is not reachable from this screen — `SettingsSeeder`
     * writes all eighteen once, so the only way a row is missing here is a
     * fresh estate that has not been seeded yet, and it is treated as OFF
     * rather than guessed at: a switch this screen cannot prove is on must
     * not be drawn on.
     *
     * @return array<string, mixed>
     */
    public function notificationsBoard(): array
    {
        /** @var array<string, array<string, bool>> $stored [event_key][channel] => enabled */
        $stored = [];

        foreach (NotificationDefault::query()->get() as $row) {
            $stored[$row->event_key][$row->channel] = $row->enabled;
        }

        /*
         * THE SMS COLUMN ASKS THE ONE QUESTION (M10 §1). Until a provider is
         * connected, a switch that turns SMS on does nothing at all — the log
         * driver writes a message down and delivers it to nobody — and a
         * committee that switched it on would believe households were being
         * texted. So the column is still drawn, still shows what was saved, and
         * says on the screen why it cannot be changed.
         */
        $smsAvailable = $this->smsAvailable();
        $smsNote = $smsAvailable ? null : $this->smsNote();

        $byGroup = [];

        foreach (self::NOTIFICATION_EVENTS as $event) {
            $byGroup[$event['group']]['sort'] = $event['group_sort'];
            $byGroup[$event['group']]['rows'][] = [
                'key' => $event['key'],
                'label' => $event['label'],
                'description' => $event['description'],
                'channels' => array_map(
                    fn (string $channel): array => [
                        'key' => $channel,
                        'label' => $channel === 'sms' ? 'SMS' : ucfirst($channel),

                        // WHAT WAS SAVED, untouched. See `saveNotifications()`.
                        'enabled' => $stored[$event['key']][$channel] ?? false,

                        'available' => $channel !== 'sms' || $smsAvailable,
                        'unavailable_reason' => $channel === 'sms' ? $smsNote : null,
                    ],
                    NotificationDefault::CHANNELS,
                ),
            ];
        }

        uasort($byGroup, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $groups = [];

        foreach ($byGroup as $heading => $group) {
            $groups[] = ['heading' => $heading, 'rows' => $group['rows']];
        }

        return [
            'groups' => $groups,
            'sms_available' => $smsAvailable,
            'sms_note' => $smsNote,
        ];
    }

    /**
     * Whether a text could actually be sent — `SmsSettings::canSend()`, and
     * nothing of this class's own. Resolved on demand so a settings screen that
     * never mentions SMS does not pay for the question.
     */
    private function smsAvailable(): bool
    {
        return app(SmsSettings::class)->canSend();
    }

    /**
     * The one sentence every surface shows while no provider is connected,
     * worded once in `SmsSettings::shape()` so this screen cannot word it a
     * second way.
     */
    private function smsNote(): string
    {
        try {
            $note = app(SmsSettings::class)->shape()['note'] ?? null;
        } catch (Throwable) {
            $note = null;
        }

        return is_string($note) && $note !== ''
            ? $note
            : 'No SMS provider is connected, so the platform cannot send a text message.';
    }

    /**
     * Save all eighteen of board 30's switches in one submit.
     *
     * ONE SUBMIT, ONE AUDIT ENTRY, exactly as the board draws it — a single
     * topbar "Save changes" rather than Features' per-row arm-and-confirm,
     * because nothing here disables a module or changes what a household may
     * do; it decides who is emailed, texted or pushed about something that
     * already happened. That is a contact preference in the same register as
     * the estate's own enquiries mailbox on board 21, not a `configure`-level
     * act, so this is gated the same way `updateProfile()` is.
     *
     * THE ALLOWLIST IS `NOTIFICATION_EVENTS`' OWN KEYS, not whatever arrives in
     * the request. An unrecognised event key is dropped rather than stored,
     * the same refusal `setFeature()` gives a feature the catalogue does not
     * offer — a switch for an event this product does not send would record a
     * decision about nothing.
     *
     * SMS CANNOT BE SWITCHED ON WHILE NOTHING CAN SEND ONE (M10 §1). The
     * refusal is here and not only on the screen, because a disabled switch
     * is a suggestion and a request body is not. Only the move from off to on
     * is refused: switching SMS OFF is always allowed — it asks for less, and
     * a committee must be able to say so before a provider exists — and an
     * SMS value the request does not carry, or carries unchanged, is left
     * exactly as saved. That is what lets the choice a committee made before
     * this rule take effect the day a provider is connected, rather than
     * being wiped by an unrelated save. Checked before anything is written,
     * so a refused save changes nothing at all.
     *
     * @param  array<string, array<string, bool>>  $input  [event_key][channel] => enabled
     */
    public function saveNotifications(array $input, User $by): void
    {
        $before = $this->notificationSnapshot();

        if (! $this->smsAvailable()) {
            foreach (self::NOTIFICATION_EVENTS as $event) {
                if (! isset($input[$event['key']]['sms']) || ! (bool) $input[$event['key']]['sms']) {
                    continue;
                }

                if ($before[$event['key'].'.sms'] ?? false) {
                    continue;
                }

                throw new DomainException(sprintf(
                    'SMS was not switched on for "%s", and nothing was saved. %s Switching SMS off is allowed; '
                    .'switching it on waits until a provider is connected.',
                    $event['label'],
                    $this->smsNote(),
                ));
            }
        }

        foreach (self::NOTIFICATION_EVENTS as $event) {
            $key = $event['key'];

            foreach (NotificationDefault::CHANNELS as $channel) {
                if (! isset($input[$key][$channel])) {
                    continue;
                }

                NotificationDefault::updateOrCreate(
                    ['event_key' => $key, 'channel' => $channel],
                    ['enabled' => (bool) $input[$key][$channel]],
                );
            }
        }

        $after = $this->notificationSnapshot();

        // A change nobody made writes no entry. Pressing Save on a form
        // nothing was typed into is not an event an audit has to explain.
        if ($before === $after) {
            return;
        }

        $this->audit(
            action: 'estate.settings.notifications_updated',
            by: $by,
            entityType: 'notification_default',
            entityId: 'grid',
            before: $before,
            after: $after,
        );
    }

    /**
     * Every stored (event, channel) answer, flattened for the audit log and
     * for the before/after comparison `saveNotifications()` uses to decide
     * whether anything actually changed.
     *
     * @return array<string, bool>
     */
    private function notificationSnapshot(): array
    {
        return NotificationDefault::query()
            ->get()
            ->mapWithKeys(fn (NotificationDefault $row): array => ["{$row->event_key}.{$row->channel}" => $row->enabled])
            ->sortKeys()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* board 33 — data & privacy */
    /* ------------------------------------------------------------------ */

    /**
     * Board 33's four fields, in its own three sections — and every one of
     * them is a POLICY STATEMENT, not a form.
     *
     * NO `estate_privacy_setting` TABLE EXISTS, AND NONE IS ADDED. The board
     * draws four `.m-input span` values and a "Save changes" button with no
     * editable input anywhere on the screen — D-045's own lesson runs in the
     * other direction here: a control that is not drawn live must not be BUILT
     * live, whatever a generic settings-page instinct suggests. Two of the
     * four are fixed copy this screen carries, because retention and sharing
     * with Gemini for the security grant are legal and contractual facts
     * nobody on this console is authorised to change from a form; a resident's
     * request channel is the same kind of fact, naming the Secretary as the
     * role. Making any of the three "editable" would be inventing an edit path
     * for a policy nobody has been asked to let an estate set.
     *
     * ONE OF THE FOUR IS DERIVED, AND IT DISAGREES WITH THE BOARD (D-044). The
     * board's own text reads "President, Property Manager, Secretary" for who
     * may export the resident list. The permission matrix says otherwise:
     * `export` is one of the seven verbs and only `AccessLevel::Full` grants
     * it (`AccessLevel::View`, which is all the President holds on Residents,
     * does not). Full-on-Residents belongs to the Community Super Admin, the
     * Secretary and the Property Manager (D-053's `A · V · V · F · A · V · E`)
     * — the President is not among them, and the Community Super Admin, which
     * board 24's own audit note admits it omits, is. Printing the board's own
     * sentence here would tell a committee a role could export a list of
     * every household in the estate when the platform would 403 that role the
     * moment it tried. THE MODEL WINS, exactly as it does on board 24, and the
     * discrepancy is recorded rather than reconciled — see DECISIONS.md.
     *
     * @return array<string, mixed>
     */
    public function privacyBoard(): array
    {
        return [
            'groups' => [
                [
                    'title' => 'Resident data',
                    'fields' => [
                        [
                            'key' => 'retention',
                            'label' => 'Data retention after a resident moves out',
                            'value' => self::PRIVACY_RETENTION,
                        ],
                        [
                            'key' => 'export_roles',
                            'label' => 'Who can export resident lists',
                            'value' => $this->residentExportRoles(),
                        ],
                    ],
                ],
                [
                    'title' => 'Sharing with Gemini Security',
                    'fields' => [
                        [
                            'key' => 'security_grant',
                            'label' => 'Data shared for the security service grant',
                            'value' => self::PRIVACY_SECURITY_GRANT,
                        ],
                    ],
                ],
                [
                    'title' => 'Resident rights',
                    'fields' => [
                        [
                            'key' => 'access_requests',
                            'label' => 'Data access requests',
                            'value' => self::PRIVACY_ACCESS_REQUESTS,
                        ],
                    ],
                ],
            ],

            /*
             * NO SAVE (M96 level 3; the Settings pass, 12 Oct 2026). The board drew one, and nothing on the page is a
             * setting: a control nobody can ever use is not drawn at all, and the page says in one line what it is.
             */
            'policy_line' => self::PRIVACY_POLICY_LINE,
        ];
    }

    /** Board 33's first field, its words kept and its em dash gone (M53 Law 3). */
    private const PRIVACY_RETENTION = '7 years, matching statutory record-keeping requirements';

    /** Board 33's third field, its words kept and its em dash gone (M53 Law 3). */
    private const PRIVACY_SECURITY_GRANT = 'Unit occupancy status and registered vehicles: read only, and revocable';

    /** Board 33's fourth field, verbatim. */
    private const PRIVACY_ACCESS_REQUESTS = 'Residents can request their own data via a notice to the Secretary';

    /** What this page is, in one line, where the board drew a Save that could never save. */
    public const PRIVACY_POLICY_LINE = 'Nothing on this page is set here: it states the policy this estate has been told.';

    /**
     * Who may actually export the resident register, read from the matrix
     * rather than transcribed from the board — see this method's caller for
     * why the two disagree.
     *
     * "Community Super Admin, Secretary, Property Manager", in the matrix's
     * own role order rather than the board's, because that order is the one
     * fact about this list the model gets to assert.
     */
    private function residentExportRoles(): string
    {
        /*
         * THIS ESTATE'S ROLES ONLY, AND READ FROM THE CELL'S VERBS (M10 §4).
         * Unfenced, this privacy statement would name another community's own
         * roles as people who can export this community's residents. And a
         * custom cell can grant `export` without being Full, which a query on the
         * level would miss — the one thing this sentence must not do is
         * understate who can take the list away.
         */
        $labels = Role::query()
            ->with('moduleAccess.module')
            ->where('console', Console::Estate->value)
            ->visibleTo((string) tenant()?->getTenantKey())
            ->orderByRaw('CASE WHEN is_system = 1 OR version_of_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->filter(fn (Role $role): bool => $role->moduleAccess->contains(
                fn (RoleModuleAccess $cell): bool => $cell->module->key === 'residents'
                    && in_array(PermissionVerb::Export, $cell->grantedVerbs(), true),
            ))
            ->map(fn (Role $role): string => (string) ($role->label ?? $role->name));

        return $labels->implode(', ');
    }

    /* ------------------------------------------------------------------ */
    /* board 40 — billing & subscription */
    /* ------------------------------------------------------------------ */

    /**
     * The estate's own subscription, hero card and invoice history —
     * READ FROM THE CENTRAL RECORD AND NEVER COPIED INTO THIS DATABASE.
     *
     * THIS IS THE ONE SCREEN IN THIS MODULE THAT DOES NOT BELONG TO THIS
     * ESTATE'S DATABASE AT ALL. `App\Models\Subscription`, `Plan`, `Invoice`
     * and `InvoiceLine` all carry spatie's sibling trick — `CentralConnection`
     * — for the same reason `Role` and `Permission` do (D-012): a figure two
     * databases could each hold a copy of is a figure free to disagree with
     * itself, and the one Gemini bills against has to be the one a treasurer
     * reading this screen sees. So this method opens no tenant table, writes
     * nothing anywhere, and reads `tenant_id = $tenantKey` off the same rows
     * `App\Http\Controllers\Gemini\BillingController` and `BillingOverview`
     * already read for the platform's own billing screens. Two consoles, one
     * ledger, never two.
     *
     * THE AMOUNT SHOWN IS THE WHOLE INVOICE (BIL-B-05, M79). An invoice carries
     * the software subscription and the "Security Provider add-on" for the
     * guards, and the estate is emailed one PDF for both. This page used to sum
     * the subscription lines alone, so it listed $680,000 against an emailed
     * $770,000 for the same invoice. Each row is now the posted `total_minor`,
     * the figure the email and the PDF carry; the hero card above still says
     * what the subscription alone costs a month, under its own label, and the
     * itemised view marks which lines are the subscription.
     *
     * ONE INVOICE NUMBER, THE STORED `reference` (BIL-B-03, M79). Board 40 drew
     * "INV-2026-09", derived from the period, beside the reference the invoice
     * was issued with (`{estate}-INV-{YYYYMM}`), which the PDF and the email
     * carry: two numbers for one invoice. The reference is generated once, when
     * the invoice is raised, and is the only number shown.
     *
     * EVERY INVOICE, A PAGE AT A TIME (M32 F5). Board 40 draws three, and the fourth month an estate was billed was
     * on no screen it could open; the history is now the whole of it, newest period first.
     *
     * @return array<string, mixed>
     */
    public function billingBoard(string $tenantKey, ?TableQuery $table = null): array
    {
        $subscription = Subscription::query()->with('plan')->where('tenant_id', $tenantKey)->first();

        if ($subscription === null) {
            return ['subscription' => null, 'invoices' => [], 'table' => null];
        }

        $table ??= self::billingTable();
        $plan = $subscription->plan;
        $monthlyMinor = $subscription->unit_count * $plan->price_per_unit_minor;

        // Each row carries its credited total, so its one status word (BIL-B-04) asks nothing more of the database.
        $query = Invoice::query()->with('lines')->withCredited()->where('tenant_id', $tenantKey);
        $table->order($query, 'invoices.id');
        $page = $table->paginate($query);

        $invoices = $page->getCollection()
            ->map(fn (Invoice $invoice): array => $this->billingInvoiceRow($invoice, $tenantKey))
            ->values()
            ->all();

        return [
            'subscription' => [
                'plan_name' => (string) $plan->name,
                'unit_count' => $subscription->unit_count,
                'billing_cadence' => 'Billed monthly in advance',
                'price_per_unit' => MoneyFormatter::whole((int) $plan->price_per_unit_minor, $plan->currency),
                'monthly_total' => MoneyFormatter::whole($monthlyMinor, $plan->currency),
                'next_invoice' => $subscription->renews_on?->format('M j') ?? '—',
                'contract_renewal' => $subscription->contract_renewal_on?->format('M Y') ?? '—',
                'included_blurb' => self::PLAN_INCLUDED_BLURB,
                'security_services_note' => self::SECURITY_SERVICES_NOTE,
            ],
            'invoices' => $invoices,
            'table' => $table->meta($page),
        ];
    }

    /**
     * What board 40's invoice history orders by (M32 F5). The number is derived from the period, so the two order
     * alike; the amount is the whole invoice's posted total, the figure the row prints (BIL-B-05).
     *
     * @return array<string, string|\Closure>
     */
    public static function billingSorts(): array
    {
        return [
            'number' => 'invoices.period_start',
            'period' => 'invoices.period_start',
            // The whole invoice's posted total, the figure the row prints (BIL-B-05).
            'amount' => 'invoices.total_minor',
            'status' => 'invoices.status',
        ];
    }

    /** Board 40's invoice history as the address asks for it (M32 F5): the newest period first. */
    public static function billingTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::billingSorts(), 'period', 'desc');
    }

    /** Board 40's first info panel, verbatim. */
    private const PLAN_INCLUDED_BLURB = 'Guard App integration, e-Voting & elections, resident-gated meetings, '.
        "Accounting core, Payroll & HR for the estate's own staff, AI drafting assistant, and full custom ".
        'branding. See Feature toggles for the complete list.';

    /**
     * Board 40's second info panel. It said guard staffing was invoiced elsewhere ("in Accounting, not here"), and it
     * is not: it is the add-on line on the same monthly invoice, which the page now shows whole (BIL-B-05).
     */
    private const SECURITY_SERVICES_NOTE = 'Guard staffing from Gemini Security Limited is billed on the same invoice, as its own line.';

    /**
     * One of this estate's own invoices, itemised — board 40's "View" (12 §2,
     * Wave 4).
     *
     * OWNERSHIP IS PART OF THE QUERY, NOT A CHECK AFTER IT. The invoice is read
     * only where its `tenant_id` is this estate's key, so another client's
     * invoice id and an id that does not exist are the same thing here: both
     * are null, both 404, and guessing ids tells an estate nothing about what
     * anybody else is billed.
     *
     * THE WHOLE INVOICE, AS ISSUED, the same figure board 40's table now shows
     * (BIL-B-05). Every line is here, the subscription lines are marked, and the
     * guard add-on is labelled for what it is.
     *
     * Read through the Gemini console's own services: one reading of an
     * invoice, so the estate and Gemini cannot come to see different ones.
     *
     * @return array<string, mixed>|null
     */
    public function billingInvoice(string $tenantKey, int $invoiceId, BillingOverview $overview, InvoiceActions $actions): ?array
    {
        $invoice = Invoice::query()->with('lines')->where('tenant_id', $tenantKey)->whereKey($invoiceId)->first();

        if ($invoice === null) {
            return null;
        }

        $detail = $overview->invoice($invoiceId);

        if ($detail === null) {
            return null;
        }

        $credits = $actions->creditsFor($invoiceId);
        $currency = (string) $invoice->currency;

        return [
            'id' => $invoice->getKey(),
            // One number (BIL-B-03): the issued reference, as the billing page, the PDF and the email print it.
            'number' => $detail['reference'],
            'reference' => $detail['reference'],
            'period' => $invoice->period_start->format('F Y'),
            'due_on' => $detail['due_on'],
            'settlement' => $detail['settlement'],
            'status' => $invoice->status,
            'status_label' => $detail['status_label'],
            'status_tone' => $detail['status_tone'],
            'lines' => array_map(
                static fn (array $line): array => [
                    ...$line,
                    'is_subscription' => self::isSubscriptionLine($line['description']),
                ],
                $detail['lines'],
            ),
            'total' => $detail['total'],
            'subscription_total' => MoneyFormatter::fromMinor($this->subscriptionMinor($invoice), $currency),
            'reconciliation' => $detail['reconciliation'],
            'notes' => array_map(
                static fn (array $note): array => [
                    ...$note,
                    'amount' => '−'.MoneyFormatter::fromMinor($note['amount_minor'], $currency),
                ],
                $credits['notes'],
            ),
            'credited' => MoneyFormatter::fromMinor($credits['credited_minor'], $currency),
            'payable' => MoneyFormatter::fromMinor($detail['total_minor'] - $credits['credited_minor'], $currency),
            'has_credits' => $credits['notes'] !== [],
        ];
    }

    /** The same ownership read as billingInvoice(), for the PDF that needs no payload. */
    public function ownsInvoice(string $tenantKey, int $invoiceId): bool
    {
        return Invoice::query()->where('tenant_id', $tenantKey)->whereKey($invoiceId)->exists();
    }

    /**
     * One row of board 40's three-invoice table.
     *
     * "View" IS BUILT FROM THE ESTATE'S ROOT, like every other link these
     * screens carry (bug hunt C04). Written as a root path it was right on an
     * estate's own hostname and a 404 in the local path shape, where the estate
     * lives at /estate/{tenant} — the one link on the page that skipped `path()`.
     *
     * @return array<string, mixed>
     */
    private function billingInvoiceRow(Invoice $invoice, string $tenantKey): array
    {
        return [
            // The number it was issued with, once (BIL-B-03): the one the PDF, the email and Gemini's console carry,
            // never a second "INV-2026-09" derived from the period beside it.
            'number' => (string) $invoice->reference,
            'period' => $invoice->period_start->format('F Y'),
            // The whole invoice as posted and emailed, the subscription and the guard add-on (BIL-B-05), to the cent.
            'amount' => MoneyFormatter::fromMinor((int) $invoice->total_minor, $invoice->currency),
            'status' => $invoice->status,
            // The invoice's one word and badge (BIL-B-04), as the itemised view, the PDF and Gemini's console say them.
            'status_label' => $invoice->statusLabel(),
            'status_tone' => $invoice->statusBadge(),
            'view_href' => $this->path('/settings/billing/invoices/'.$invoice->getKey(), $tenantKey),
        ];
    }

    /**
     * Every line whose description names the subscription — "Premium
     * subscription", "Standard subscription" — and never the guard add-on
     * line beside it. See billingBoard()'s docblock.
     */
    private function subscriptionMinor(Invoice $invoice): int
    {
        return (int) $invoice->lines
            ->filter(fn ($line): bool => self::isSubscriptionLine((string) $line->description))
            ->sum('total_minor');
    }

    private static function isSubscriptionLine(string $description): bool
    {
        return str_contains(mb_strtolower($description), 'subscription');
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * The central feature catalogue, with each feature's tier computed.
     *
     * @return list<array{key: string, label: string, sub_label: string|null, kind: string, is_core: bool, tier: string|null}>
     */
    private function catalogue(): array
    {
        $plans = DB::connection('mysql')->table('plans')->orderBy('sort')->get();
        $included = DB::connection('mysql')->table('plan_features')->where('included', true)->get();

        /** @var array<int, string> $planKeys plan id => key, in ascending price order */
        $planKeys = [];
        /** @var array<int, int> $planOrder plan id => sort */
        $planOrder = [];

        foreach ($plans as $plan) {
            $planKeys[(int) $plan->id] = (string) $plan->key;
            $planOrder[(int) $plan->id] = (int) $plan->sort;
        }

        /** @var array<int, array{tier: string, sort: int}> $lowest feature id => cheapest plan that includes it */
        $lowest = [];

        /** @var array<int, array{tier: string, sort: int}> $highest feature id => dearest plan that includes it */
        $highest = [];

        foreach ($included as $row) {
            $featureId = (int) $row->package_feature_id;
            $planId = (int) $row->plan_id;

            if (! isset($planKeys[$planId])) {
                continue;
            }

            $sort = $planOrder[$planId];

            // The cheapest plan that includes it is the tier badge, which is
            // what "Standard+" means: this and everything above it.
            if (! isset($lowest[$featureId]) || $lowest[$featureId]['sort'] > $sort) {
                $lowest[$featureId] = ['tier' => $planKeys[$planId], 'sort' => $sort];
            }

            if (! isset($highest[$featureId]) || $highest[$featureId]['sort'] < $sort) {
                $highest[$featureId] = ['tier' => $planKeys[$planId], 'sort' => $sort];
            }
        }

        $catalogue = [];

        foreach (DB::connection('mysql')->table('package_features')->orderBy('sort')->get() as $feature) {
            $id = (int) $feature->id;
            $isCore = (bool) $feature->is_core;
            $kind = (string) $feature->kind;

            /*
             * WHERE A ROW IS DRAWN, AND WHY THE TWO KINDS DISAGREE.
             *
             * A toggle belongs to the CHEAPEST tier that includes it: "Standard+"
             * means this tier and every one above, and drawing it under Premium
             * would tell a Standard estate it cannot have something it has.
             *
             * A `tier_value` feature belongs to the DEAREST. Every tier has
             * custom branding — the grid marks it included in all three — so the
             * cheapest tier is meaningless as a badge and would put it under
             * "Core, every tier" beside the panic button. What differs is HOW
             * MUCH, and the tier that grants all of it is the one the row is
             * about. Board 23 draws it under "Premium only" for exactly that
             * reason.
             *
             * A core feature is on at every tier by definition, whatever the
             * grid happens to say.
             */
            $tier = match (true) {
                $isCore => 'essential',
                $kind === 'tier_value' => $highest[$id]['tier'] ?? null,
                default => $lowest[$id]['tier'] ?? null,
            };

            $catalogue[] = [
                'key' => (string) $feature->key,
                'label' => (string) $feature->label,
                'sub_label' => $feature->sub_label === null ? null : (string) $feature->sub_label,
                'kind' => $kind,
                'is_core' => $isCore,
                'tier' => $tier,
            ];
        }

        return $catalogue;
    }

    /**
     * How much of a `tier_value` feature THIS estate's plan grants.
     *
     * "Logo only", "Logo + hero", "Full theme" — read for the estate's own
     * plan, never for the catalogue's cheapest. Board 23 fills all three
     * branding dots because Phoenix Park is on Premium; a Standard estate fills
     * two, and reading the same figure for both would tell one of them
     * something untrue about what it is paying for.
     */
    private function tierValueFor(?string $planKey, string $featureKey): ?string
    {
        if ($planKey === null) {
            return null;
        }

        $value = DB::connection('mysql')
            ->table('plan_features')
            ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
            ->join('package_features', 'package_features.id', '=', 'plan_features.package_feature_id')
            ->where('plans.key', $planKey)
            ->where('package_features.key', $featureKey)
            ->value('plan_features.tier_value');

        return $value === null ? null : (string) $value;
    }

    /** Does the estate's plan include this feature? */
    private function planIncludes(?string $planKey, string $featureKey): bool
    {
        if ($planKey === null) {
            return false;
        }

        return DB::connection('mysql')
            ->table('plan_features')
            ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
            ->join('package_features', 'package_features.id', '=', 'plan_features.package_feature_id')
            ->where('plans.key', $planKey)
            ->where('package_features.key', $featureKey)
            ->where('plan_features.included', true)
            ->exists();
    }

    /**
     * This estate's own decisions, keyed by feature.
     *
     * @return array<string, EstateFeature>
     */
    private function overrides(): array
    {
        $overrides = [];

        foreach (EstateFeature::query()->get() as $override) {
            $overrides[$override->feature_key] = $override;
        }

        return $overrides;
    }

    /**
     * One switch row.
     *
     * @param  array{key: string, label: string, sub_label: string|null, kind: string, is_core: bool, tier: string|null}  $feature
     * @return array<string, mixed>
     */
    private function featureRow(array $feature, ?EstateFeature $override, bool $available, ?string $tierValue, ?string $planName = null): array
    {
        $enabled = $feature['is_core']
            ? true
            : ($override->enabled ?? $available);

        // A module of its own (Accounting) is open whatever the plan says while `PlanGate` is off, and the row says so.
        $stillOpen = in_array($feature['key'], PlanGate::MODULES, true) && ! PlanGate::enforced();

        return [
            /*
             * READ ONLY, WITH THE PLAN (Hassam's closing order, 12 Oct): the row's state in the list's words and one
             * line saying where it comes from. The switch that was here changed nothing anywhere: nothing outside this
             * page read an estate's choice, so the page offered a control that lied.
             */
            'state_label' => $enabled || $stillOpen ? 'Active' : 'Inactive',
            'plan_line' => $stillOpen && ! $available && $planName !== null
                ? 'Not in the '.$planName.' plan, but still open to this estate'
                : $this->planLine($feature['is_core'], $override, $available, $planName),

            'key' => $feature['key'],
            'label' => $feature['label'],
            'description' => $feature['is_core']
                ? self::CORE_COPY
                : (self::FEATURE_COPY[$feature['key']] ?? (string) $feature['sub_label']),
            'tier' => $feature['tier'],
            'tier_label' => self::TIER_LABELS[(string) $feature['tier']] ?? '',

            // A `tier_value` feature is not a switch: no tier is without custom
            // branding, and the tiers differ in how much of it. Rendering that
            // as a tick would sell Essential a feature it does not have.
            'control' => $feature['kind'] === 'tier_value' ? 'scale' : 'switch',
            'enabled' => $enabled,

            /*
             * A scale is locked for the same reason a core row is, and for a
             * different cause: there is nothing here to switch. How much
             * branding an estate gets is what its tier grants, so the control
             * reports a commercial fact rather than offering a choice.
             */
            'locked' => $feature['is_core'] || $feature['kind'] === 'tier_value',
            'available' => $available,

            'scale' => $feature['kind'] === 'tier_value'
                ? $this->brandingScale($tierValue)
                : null,

            // Who decided, and why — the caption under a switch somebody moved.
            // Null where the plan's own answer still stands, which is not the
            // same as nobody having decided anything for a bad reason.
            'provenance' => $override?->provenance(),
            'reason' => $override?->reason,
            'blocked_reason' => $available
                ? null
                : 'Not included in this estate\'s plan. Adding it is a commercial change made by Gemini Security.',
        ];
    }

    /**
     * Where a feature row's state comes from, in one line: always on, an estate's own recorded decision, or the plan.
     */
    private function planLine(bool $isCore, ?EstateFeature $override, bool $available, ?string $planName): string
    {
        return match (true) {
            // Not CORE_COPY again: that is the row's description, and the line under the state said it a second time.
            $isCore => 'Included in every plan',
            $override !== null => $override->provenance().($override->reason ? ': “'.$override->reason.'”' : ''),
            $planName === null => 'No plan on record yet, so nothing is switched off',
            $available => 'In the '.$planName.' plan',
            default => 'Not in the '.$planName.' plan',
        };
    }

    /**
     * Board 23's "Payroll & HR routing" — a segmented control, not a switch.
     *
     * @param  array{key: string, label: string, sub_label: string|null, kind: string, is_core: bool, tier: string|null}  $feature
     * @return array<string, mixed>
     */
    private function payrollRoutingRow(array $feature, EstateSetting $setting, bool $available, ?string $planName = null): array
    {
        // Payroll & HR is a module of its own: open whatever the plan says while `PlanGate` is off, and said so.
        $stillOpen = ! PlanGate::enforced();

        return [
            'state_label' => $available || $stillOpen ? 'Active' : 'Inactive',
            'plan_line' => $stillOpen && ! $available && $planName !== null
                ? 'Not in the '.$planName.' plan, but still open to this estate'
                : $this->planLine(false, null, $available, $planName),
            'key' => 'estate_payroll',
            'label' => 'Payroll & HR routing',

            /*
             * THE ESTATE'S OWN STAFF, AND NOTHING ELSE (M10 §9).
             *
             * This line read "Staff payroll: in-house · Security payroll:
             * Gemini-managed". The second half went with the guards' row: the
             * estate has no say in how Gemini's employees are paid, so a
             * sentence on their settings screen reporting it is answering a
             * question they were never entitled to ask.
             *
             * What is left is a statement about the people this estate actually
             * employs — gardeners, office help, a caretaker — which is the only
             * payroll this screen governs.
             */
            'description' => sprintf(
                'Staff payroll: %s',
                $setting->routingSentenceLabel($setting->staff_payroll_routing),
            ),
            'tier' => $feature['tier'],
            'tier_label' => self::TIER_LABELS[(string) $feature['tier']] ?? '',
            'control' => 'segmented',
            'enabled' => $available,
            'locked' => false,
            'available' => $available,
            'options' => $this->routingOptions($setting, $setting->staff_payroll_routing),
            'selected' => $setting->staff_payroll_routing,
            'caption' => $setting->routingCaption($setting->staff_payroll_routing),

            /*
             * Q-002, ruled (D-082), stated where it bites. An in-house run is
             * calculated on TAJ's published tables and approved in the estate's
             * own console, and its first live approval carries the reconciliation
             * acknowledgement the ruling asks for.
             */
            'note' => "Statutory rates follow TAJ's published tables, as ruled on Q-002. The first live run is "
                .'approved with a recorded acknowledgement that it was reconciled against them.',
            'blocked_reason' => $available
                ? null
                : 'Not included in this estate\'s plan. Adding it is a commercial change made by Gemini Security.',
        ];
    }

    /**
     * The two options both routing rows offer, with the chosen one marked.
     *
     * @return list<array{value: string, label: string, active: bool}>
     */
    private function routingOptions(EstateSetting $setting, string $selected): array
    {
        $options = [];

        foreach ([EstateSetting::IN_HOUSE, EstateSetting::GEMINI_MANAGED] as $value) {
            $options[] = [
                'value' => $value,
                'label' => $setting->routingLabel($value),
                'active' => $value === $selected,
            ];
        }

        return $options;
    }

    /**
     * Board 23's three branding dots — L, H, C — filled to the tier.
     *
     * DERIVED FROM THE TIER VALUE, not stored per dot. "Full theme" fills all
     * three, "Logo + hero" fills two and "Logo only" fills one, which is the
     * same fact the package grid already holds and is why no `branding_option`
     * table exists to disagree with it.
     *
     * @return list<array{code: string, label: string, filled: bool}>
     */
    private function brandingScale(?string $tierValue): array
    {
        $levels = match ($tierValue) {
            'Full theme' => 3,
            'Logo + hero' => 2,
            'Logo only' => 1,
            default => 0,
        };

        $dots = [];

        foreach ([['L', 'Logo'], ['H', 'Hero image'], ['C', 'Colour theme']] as $i => [$code, $label]) {
            $dots[] = ['code' => $code, 'label' => $label, 'filled' => $i < $levels];
        }

        return $dots;
    }

    /**
     * The three this release holds back, each with the ruling that holds it.
     *
     * NONE OF THESE IS A CATALOGUE FEATURE, and none was added to the central
     * catalogue to make this group possible: `package_features` is board 43's
     * package builder, and putting a legally-blocked feature into it would
     * offer it for sale. They are estate flags with rulings behind them, drawn
     * here so the screen states a decision rather than silently omitting it —
     * which is the difference between a decision and an oversight.
     *
     * @return list<array<string, mixed>>
     */
    private function heldBackRows(EstateSetting $setting): array
    {
        return [
            [
                'key' => 'biometric_consent',
                'state_label' => 'Inactive',
                'plan_line' => 'Held back for every estate in this release',
                'label' => 'Biometric check-in consent',
                'description' => 'Consent copy is in legal review and marked draft, so the feature does not run',
                'tier' => null,
                'tier_label' => 'Held',
                'control' => 'switch',
                'enabled' => $setting->biometric_consent_enabled,
                'locked' => true,
                'available' => false,
                'blocked_reason' => 'Off until the consent copy clears legal review (D-022). No template, no '.
                    'landmark set and no image exists anywhere in this system, so what the flag governs is '.
                    'whether the feature runs at all, never what is retained.',
            ],
            [
                'key' => 'payment_gateway',
                'state_label' => 'Inactive',
                'plan_line' => 'Held back for every estate in this release',
                'label' => 'Card payments for dues',
                'description' => 'Dues are recorded manually; card capture sits behind a gateway adapter',
                'tier' => null,
                'tier_label' => 'Held',
                'control' => 'switch',
                'enabled' => $setting->payment_gateway_mode !== EstateSetting::PAYMENT_MANUAL,
                'locked' => true,
                'available' => false,
                'blocked_reason' => 'Manual recording day one, card capture behind the payment gateway interface '.
                    '(D-023), whose only implementation is a null one. Switched on today it would offer residents '.
                    'a card form that talks to nothing, and a resident who believes they have paid is a resident '.
                    'about to be restricted for arrears they thought were settled.',
            ],
            [
                'key' => 'geofencing',
                'state_label' => 'Inactive',
                'plan_line' => 'Held back for every estate in this release',
                'label' => 'Geofenced clock-in',
                'description' => 'Deferred: the estate boundary needs surveyed coordinates',
                'tier' => null,
                'tier_label' => 'Held',
                'control' => 'switch',
                'enabled' => $setting->geofencing_enabled,
                'locked' => true,
                'available' => false,
                'blocked_reason' => 'Deferred (D-033). The boundary is the Guard App\'s clock-in check and needs a '.
                    'surveyed polygon rather than a nullable column nobody populates; nothing reads it before '.
                    'Phase 3.',
            ],
        ];
    }

    /**
     * One entry in the central, append-only audit log.
     *
     * CENTRAL AND NOT PER ESTATE, and that is not an accident of where the
     * table happens to be. Board 24 states that every permission change writes
     * to an immutable log, and a log inside the estate database would be a log
     * the estate's own administrator could ask for the deletion of. `audit_log`
     * is append-only twice over — a withheld grant and a trigger — and it
     * refuses an edit from platform staff as readily as from a committee.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(
        string $action,
        User $by,
        string $entityType,
        string $entityId,
        array $before,
        array $after,
    ): void {
        AuditEntry::create([
            'tenant_id' => tenant()?->getTenantKey(),
            'actor_id' => $by->getKey(),
            'actor_name' => $by->name,

            // The role, not the permission. "Who was allowed to do this" is
            // answered by the matrix on the day it was done, and the role is
            // the shortest true version of it.
            'actor_role' => $by->roles->pluck('name')->implode(', '),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /** "" for a blank field, stored as null so unset and empty are one answer. */
    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * "Patrice Campbell" -> "PC".
     *
     * PUNCTUATION IS STRIPPED FIRST, and that is not fussiness. Local
     * development creates accounts named "Treasurer (demo)", which take their
     * second initial from an opening bracket and render "T(" in a 32-pixel
     * avatar. First and LAST rather than first two, because a middle name is
     * not what anybody is known by.
     */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        $letters = [];

        foreach ($parts as $part) {
            $clean = (string) preg_replace('/[^\p{L}]/u', '', (string) $part);

            if ($clean !== '') {
                $letters[] = $clean;
            }
        }

        if ($letters === []) {
            return '?';
        }

        $first = mb_substr($letters[0], 0, 1);
        $last = count($letters) > 1 ? mb_substr($letters[count($letters) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Where a settings screen lives, in whichever shape this environment
     * serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path, string $tenantKey): string
    {
        return app()->isLocal() && $tenantKey !== ''
            ? '/estate/'.$tenantKey.$path
            : $path;
    }
}
