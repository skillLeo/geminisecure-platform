<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Module;
use App\Models\PlatformSmsSetting;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Services\Gemini\PlatformAdmins;
use App\Services\Gemini\PlatformSettings;
use App\Services\InvitationNotSent;
use App\Services\Invitations;
use App\Services\Platform\MailFailures;
use App\Services\Platform\MailLog;
use App\Services\Platform\MailSettings;
use App\Services\Platform\SmsSettings;
use App\Services\Platform\SupportLine;
use App\Services\Rbac\RoleEditor;
use App\Support\NamedActs;
use App\Support\PlatformCalendar;
use App\Support\PlatformMoney;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * Platform settings — board screens super-admin-42 to 45.
 *
 * Four screens behind one tab strip:
 *
 *   42  Pricing & rates       the rate card, and who holds a platform account
 *   43  Package builder       which tier includes which feature
 *   44  Client line items     one client's additions and removals
 *   45  Role access matrix    which role can reach which module
 *
 * `gemini.platform_settings` is screen 42, the module's own landing screen. It
 * used to serve /settings/roles, which put the sidebar's Platform settings link
 * on the role matrix and left the module without a front door — and, because
 * the fidelity harness resolves boards by route name, made every measurement of
 * board 42 a measurement of a different screen.
 *
 * THE PRICING WRITES ARE PRIVILEGED, DATED AND AUDITED, and each is its own
 * action rather than a side effect of the screen that displays it. A tier price
 * re-prices every estate on it; the per-guard add-on re-prices every estate
 * with guards deployed; a package row changes what every current and future
 * client on that tier receives; an override changes one client's invoice. So
 * each is `configure`, each is refused in a sentence when it cannot be done,
 * and a reader without the verb sees the controls inert with the reason on
 * them rather than omitted. (This said "every screen in this module is read
 * only" until M10 §3; that stopped being true in Wave 4.)
 *
 * THE ROLE MATRIX IS EDITABLE TOO, AND IT IS NOT EDITED HERE (M10 §4). Screen
 * 45 prints `RoleEditor::board()` — the console's own roles, each with its
 * machine name and a sentence for every act it refuses — and its six writes
 * (create, copy, rename, re-grant, delete, reset to GeminiSecure default) hand
 * the request straight to `RoleEditor`, which derives the console from the
 * actor and owns every rule: nobody grants what they do not hold, the fourteen
 * seeded roles are never renamed or deleted, and the Director keeps Full ·
 * Approver on this module. The actions below validate shape and turn a refusal
 * into the sentence the screen prints; none of them decides anything. Taking
 * somebody off the console is the same shape over `PlatformAdmins::remove()`.
 * All seven sit behind `gemini.platform_settings.approve`, which the Director
 * alone holds among the seeded roles — the routes file says why.
 */
class PlatformSettingsController extends Controller
{
    /**
     * Why a reader without the permission is refused, on all three screens.
     *
     * These are privileged, audited writes — a tier price re-prices every
     * client on it, a package row changes what every current and future client
     * on that tier receives, and an override changes one client's invoice. The
     * matrix gives `set_prices` to the roles that may make them; everybody else
     * reads. This is one sentence because it is one refusal.
     *
     * `set_prices` AND NOT `configure` (M11 B3, D-150). Configure on Platform
     * settings is three permissions now — prices, mail and SMS delivery, the
     * simulator — so a role may be trusted with one and not the others.
     */
    private const NO_PLATFORM_WRITE = 'Changing what the platform charges or what a tier includes re-prices or '
        .'re-provisions real clients, so it needs the Set platform prices permission on Platform settings. You are able to read these screens.';

    /** The price screens' one permission. */
    private const PRICES = 'gemini.platform_settings.set_prices';

    /** The mail relay's and the SMS provider's one permission. */
    private const MESSAGING = 'gemini.platform_settings.configure_messaging';

    public function index(Request $request, PlatformSettings $settings): Response
    {
        /*
         * A DATED CHANGE THAT HAS COME DUE IS APPLIED ON READ, and deliberately
         * here: a pending change that silently never applied would be a price
         * the platform believes it charges and does not, and reading the rate
         * card is the moment somebody would notice.
         */
        $settings->applyDuePriceChanges();

        $plans = $settings->editablePlans();
        $editableRates = $settings->editableRates();

        /*
         * WHAT THE CHANGE IN THE PANEL WILL AFFECT, AND FROM WHEN (M10 §3).
         *
         * Asked of the server, because the client's rule is that the screen
         * says it clearly and the only honest source is the rule the invoice run
         * applies. The panel names its tier or rate and its date in the query
         * — `?change=tier-3&from=2026-10-01` — and reloads only this prop as
         * they change. Narrowed rather than validated, like `?client=` on the
         * line-items screen: a stale or hand-edited query lands on the first
         * tier and the next billing period rather than bouncing the reader.
         */
        $targets = [
            ...array_map(static fn (array $plan): string => 'tier-'.$plan['id'], $plans),
            ...array_map(static fn (array $rate): string => 'rate-'.$rate['id'], $editableRates),
        ];

        $requested = $request->query('change');
        $target = is_string($requested) && in_array($requested, $targets, true) ? $requested : ($targets[0] ?? null);

        // Gemini's month, the one `PlatformSettings` judges a change by (B082).
        $nextPeriod = PlatformCalendar::today()->startOfMonth()->addMonthNoOverflow()->toDateString();
        $from = $request->query('from');
        $from = is_string($from)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                ? $from
                : $nextPeriod;

        return inertia('Gemini/Settings/Index', [
            'tabs' => $this->tabs('gemini.platform_settings'),
            'rates' => fn () => $settings->rateCard(),
            'plans' => $plans,
            'editableRates' => $editableRates,
            'pending' => fn () => $settings->pendingPriceChanges(),
            'admins' => fn () => $settings->administrators(),

            // The date a change defaults to: the first day of the next billing
            // period, which is the client's own rule for when a change applies.
            'nextPeriodStart' => $nextPeriod,
            'impact' => fn () => $target === null ? null : $settings->changeImpact(
                str_starts_with($target, 'tier-') ? 'tier' : 'rate',
                (int) substr($target, 5),
                $from,
            ),

            // Tells an empty rate card WHY it is empty: everything retired, or
            // nothing ever priced. Two different screens, and a row count of
            // nought cannot tell them apart.
            'hasRetired' => fn () => $settings->hasRetiredPricing(),
            'canWrite' => $request->user()->can(self::PRICES),
            'saveDisabledReason' => self::NO_PLATFORM_WRITE,
        ]);
    }

    /** Change a tier price, from a date, with a reason (12 §2, Wave 4). */
    public function changePrice(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $settings->changePlanPrice(
                (int) $data['plan_id'],
                (string) $data['amount'],
                (string) $data['effective_from'],
                (string) $data['reason'],
                $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to('/settings')
            ->with('success', $this->changedSentence($result, 'per unit', (string) $data['effective_from']));
    }

    /**
     * Change the per-guard add-on, from a date, with a reason (M10 §3).
     *
     * The twin of `changePrice()`. Refusals land on `rate`, the key the panel
     * shows them under for a rate — never silently.
     */
    public function changeRate(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'rate_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $settings->changeRate(
                (int) $data['rate_id'],
                (string) $data['amount'],
                (string) $data['effective_from'],
                (string) $data['reason'],
                $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['rate' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to('/settings')
            ->with('success', $this->changedSentence($result, 'per guard', (string) $data['effective_from']));
    }

    /**
     * The flash after a price or rate change: what happened, then the same
     * words the panel showed before it — who it reaches and from which period.
     *
     * @param  array{applied: bool, clients: int, impact: array<string, mixed>}  $result
     */
    private function changedSentence(array $result, string $per, string $effectiveFrom): string
    {
        $impact = $result['impact'];

        $head = $result['applied']
            ? sprintf('%s: the new price %s is in force from today.', $impact['name'], $per)
            : sprintf('%s: the new price %s is recorded for %s. Nothing has changed yet.', $impact['name'], $per, Carbon::parse($effectiveFrom)->format('j F Y'));

        return $head.' '.$impact['sentence'];
    }

    public function packages(Request $request, PlatformSettings $settings): Response
    {
        return inertia('Gemini/Settings/Packages', [
            'tabs' => $this->tabs('gemini.platform_settings.packages'),
            ...$settings->packageTemplate(),
            'canWrite' => $request->user()->can(self::PRICES),
            'saveDisabledReason' => self::NO_PLATFORM_WRITE,
        ]);
    }

    /**
     * Turn a package feature on or off for a tier.
     *
     * IT TAKES EFFECT AT ONCE and the screen says so. Unlike a price, a
     * capability is not something a client is invoiced for on a date, and
     * giving it an effective date would be a date that meant nothing.
     */
    public function savePackages(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'max:200'],
            'changes.*.plan_id' => ['required', 'integer'],
            'changes.*.feature_id' => ['required', 'integer'],
            'changes.*.included' => ['required', 'boolean'],
        ]);

        try {
            foreach ($data['changes'] as $change) {
                $settings->setPackageFeature(
                    (int) $change['plan_id'],
                    (int) $change['feature_id'],
                    (bool) $change['included'],
                    $request->user(),
                );
            }
        } catch (DomainException $refused) {
            return back()->withErrors(['changes' => $refused->getMessage()]);
        }

        return redirect()
            ->to('/settings/packages')
            ->with('success', count($data['changes']).' change(s) saved. Every current and future client on those tiers is affected from now.');
    }

    /** Add a dated override to one client's subscription. */
    public function addLineItem(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'client' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:300'],
            'type' => ['required', 'string', 'in:addition,removal'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'effective_from' => ['required', 'date'],
        ]);

        try {
            $settings->addLineItem($data['client'], $data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()])->withInput();
        }

        // The same words the panel showed before the press: which invoice it is
        // first on, and that nothing already issued moves (M10 §3).
        $impact = $settings->lineItemImpact($data['client'], (string) $data['effective_from'], ending: false);

        return redirect()
            ->to('/settings/line-items?client='.$data['client'])
            ->with('success', $data['name'].' added from '.Carbon::parse((string) $data['effective_from'])->format('j F Y').'. '.$impact['sentence']);
    }

    /** End an override, from a date. Ended, never deleted. */
    public function endLineItem(Request $request, int $item, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'client' => ['required', 'string', 'max:64'],
            'effective_to' => ['required', 'date'],
        ]);

        try {
            $withdrawn = $settings->endLineItem($item, (string) $data['effective_to'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['effective_to' => $refused->getMessage()]);
        }

        if ($withdrawn) {
            return redirect()
                ->to('/settings/line-items?client='.$data['client'])
                ->with('success', 'Override withdrawn before it started. It is on no invoice, and it stays on the record with who withdrew it.');
        }

        $impact = $settings->lineItemImpact($data['client'], (string) $data['effective_to'], ending: true);

        return redirect()
            ->to('/settings/line-items?client='.$data['client'])
            ->with('success', 'Override ended. It stays on the record, because the invoices it was billed on would otherwise be unexplainable. '.$impact['sentence']);
    }

    public function lineItems(Request $request, PlatformSettings $settings): Response
    {
        $clients = $settings->clients();

        /*
         * Which client is being priced.
         *
         * Narrowed against the clients that exist rather than validated: this
         * parameter selects a view, and a stale or hand-edited `?client=`
         * should land on the first client rather than bounce the reader back
         * with an error over an estate id. The narrowing is also the safety —
         * the value reaches a WHERE clause, so it may only ever be one of a
         * known set.
         */
        $requested = $request->query('client');
        $ids = array_column($clients, 'id');

        $selected = is_string($requested) && in_array($requested, $ids, true)
            ? $requested
            : ($ids[0] ?? null);

        /*
         * WHAT ADDING OR ENDING AN OVERRIDE DOES, AND FROM WHEN (M10 §3), asked
         * of the server for the same reason the price panel's is. The add form
         * names its date in `?from=` and reloads only `addImpact` as it changes;
         * ending is always from today, so its sentence needs no question.
         */
        $today = PlatformCalendar::today()->toDateString();
        $from = $request->query('from');
        $from = is_string($from)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                ? $from
                : $today;

        return inertia('Gemini/Settings/LineItems', [
            'tabs' => $this->tabs('gemini.platform_settings.line_items'),
            'clients' => array_map(
                fn (array $client): array => [
                    ...$client,
                    'href' => route('gemini.platform_settings.line_items', ['client' => $client['id']], absolute: false),
                    'active' => $client['id'] === $selected,
                ],
                $clients,
            ),
            'selected' => $selected,
            'billing' => fn () => $selected === null ? null : $settings->lineItems($selected),
            'addImpact' => fn () => $selected === null ? null : [
                'effective_from' => $from,
                ...$settings->lineItemImpact($selected, $from, ending: false),
            ],
            'endImpact' => fn () => $selected === null ? null : $settings->lineItemImpact($selected, $today, ending: true),

            // The platform's today, not the browser's clock: "from today" must
            // mean the day the server will judge the date by, and the add form's
            // read-back is only fresh when the two dates are the same string.
            'today' => $today,
            'canWrite' => $request->user()->can(self::PRICES),
            'writeDisabledReason' => self::NO_PLATFORM_WRITE,
        ]);
    }

    /**
     * Screen super-admin-45, the Role Access Matrix.
     *
     * The grid is not a report about permissions. It is the permission tables
     * printed: roles, modules and role_module_access, the same three the
     * navigation is generated from and the same three the `can:` middleware
     * resolves against. Nothing here recomputes an access decision, so the
     * screen cannot say one thing while the application does another.
     *
     * The board draws the Gemini Console's six roles; since M10 §4 it draws
     * every role the Director has made after them, and for the Director it is
     * the editor. The Estate Console's matrix is its own board on its own
     * console and is not reproduced here.
     *
     * THE ROLES AND WHAT MAY BE DONE TO THEM ARE `RoleEditor::board()`'s, never
     * this method's. It lists the platform's own roles and nothing an estate
     * owns — so a tenant-scoped row, even a mis-created one on this console,
     * never reaches the grid — and it carries each role's MACHINE NAME, which
     * the screen keys on, and the refusal sentence for every act the viewer may
     * not perform, so a control is drawn disabled with its reason before anybody
     * presses it. `grantable` says which verbs the viewer holds on each module:
     * a convenience that lets the screen offer only what the server will accept,
     * never the enforcement, which is `RoleEditor::setPermissions()` refusing a
     * hand-built post all the same.
     *
     * The pills are still read straight off `role_module_access`, one query for
     * every cell — so a missing row still prints as the "?" `cell()` explains,
     * rather than as the "no access" the board would have to guess.
     */
    public function roleMatrix(Request $request, RoleEditor $editor): Response
    {
        $viewer = $request->user();
        $board = $editor->board($viewer);

        $roleIds = array_column($board['roles'], 'id');

        /*
         * One query for every cell, keyed on the pair.
         *
         * The alternative — a relation load per module — is 9 queries that ask
         * the same table the same question, and the grid has to be walked in
         * role order anyway to line the columns up with the header.
         */
        $cells = RoleModuleAccess::with('module')
            ->whereIn('role_id', $roleIds)
            ->get()
            ->keyBy(fn (RoleModuleAccess $access): string => $access->role_id.':'.$access->module_id);

        /*
         * WHAT A COPY WOULD HOLD, asked of the server before it is made. The
         * Copy panel names its source in `?copy=` and reloads only `copyOffer`
         * as the source changes, the way the price panel asks for `impact`.
         * Narrowed to the roles on this board rather than validated: a stale or
         * hand-edited id simply asks nothing.
         */
        $requested = $request->query('copy');
        $copyFrom = is_string($requested) && ctype_digit($requested) && in_array((int) $requested, $roleIds, true)
            ? (int) $requested
            : null;

        $moduleLabels = array_column($board['modules'], 'label', 'key');

        return inertia('Gemini/Settings/RoleMatrix', [
            'tabs' => $this->tabs('gemini.platform_settings.roles'),
            'roles' => array_map(fn (array $role): array => [
                'id' => $role['id'],
                'name' => $role['name'],
                'label' => $role['label'],
                'scope' => $role['scope_label'],
                'is_system' => $role['is_system'],
                'holders' => $role['holders'],
                'copied_from' => $role['copied_from'],
                'note' => $role['note'],
                'can_rename' => $role['can_rename'],
                'rename_refusal' => $role['rename_refusal'],
                'can_delete' => $role['can_delete'],
                'delete_refusal' => $role['delete_refusal'],
                'can_edit_permissions' => $role['can_edit_permissions'],
                'permissions_refusal' => $role['permissions_refusal'],
                'is_default' => $role['is_default'],
                'permissions_edited_at' => $role['permissions_edited_at'],
                'permissions_edited_by' => $role['permissions_edited_by'],
                ...$this->resetOffer($editor, $viewer, $role, $moduleLabels),
            ], $board['roles']),
            'modules' => array_map(fn (array $module): array => [
                'id' => $module['id'],
                'key' => $module['key'],
                'label' => $module['label'],

                // The named acts one of this module's verbs is split into (M11 B3).
                'acts' => $module['acts'],

                'cells' => array_map(fn (array $role): array => [
                    'role_id' => $role['id'],
                    ...$this->cell($cells->get($role['id'].':'.$module['id'])),

                    // What the editor starts from: the cell as the board reads it.
                    'level' => $role['cells'][$module['key']]['level'],
                    'verbs' => $role['cells'][$module['key']]['verbs'],
                    'can_approve' => $role['cells'][$module['key']]['can_approve'],
                    'acts' => $role['cells'][$module['key']]['acts'],
                    'locked' => $role['cells'][$module['key']]['locked'],
                    'locked_reason' => $role['cells'][$module['key']]['locked_reason'],
                    'minimum' => $role['cells'][$module['key']]['minimum'],
                ], $board['roles']),
            ], $board['modules']),

            'canEdit' => $board['can_edit'],
            'editBlockedReason' => $board['edit_blocked_reason'],

            // Approve is not in the per-action row: it is the Approver tag, its
            // own axis (D-008), and RoleEditor moves it there however it arrives.
            'verbs' => array_values(array_filter($board['verbs'], static fn (array $verb): bool => $verb['key'] !== 'approve')),
            'levels' => $board['levels'],
            'grantable' => $board['grantable'],
            'grantableReason' => $board['grantable_reason'],
            'moneyModules' => $this->moneyModules($moduleLabels),
            'labelMax' => RoleEditor::LABEL_MAX,
            'scopes' => array_map(
                static fn (AccessScope $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
                [AccessScope::All, AccessScope::AssignedSites],
            ),
            'copyOffer' => fn () => $copyFrom === null || ! $board['can_edit'] ? null : $this->copyOffer($editor, $viewer, $copyFrom),
        ]);
    }

    /**
     * Make a role of the Gemini Console's own.
     *
     * An empty grid is the ordinary case: every module at no access, nobody
     * holding it, and the screen opens its column for the permissions next. A
     * grid may be posted with it, and is held to exactly the rules a later
     * re-grant is.
     */
    public function createRole(Request $request, RoleEditor $editor): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:'.RoleEditor::LABEL_MAX],
            'scope' => ['nullable', 'string', Rule::in([AccessScope::All->value, AccessScope::AssignedSites->value])],
            ...self::gridRules(required: false),
        ], [], ['label' => 'role name']);

        try {
            $role = $editor->create($request->user(), (string) $data['label'], $data['grid'] ?? [], $data['scope'] ?? null);
        } catch (DomainException $refused) {
            return back()->withErrors(['roles' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->route('gemini.platform_settings.roles')
            ->with('success', sprintf(
                '%s is made. It reaches %s, nobody holds it yet, and it may do only what its column below says — '.
                'nothing, until you give it something.',
                (string) $role->label,
                $role->scope_default === AccessScope::AssignedSites ? 'only the sites a holder is assigned' : 'every client',
            ));
    }

    /**
     * A new role starting from another's grid.
     *
     * What the Director does not hold is left out of the copy and named — the
     * copy panel said so before the press, from the same `copyableGrid()`.
     */
    public function copyRole(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:'.RoleEditor::LABEL_MAX],
        ], [], ['label' => 'role name']);

        try {
            $source = $editor->find($request->user(), $role);
            ['withheld' => $withheld] = $editor->copyableGrid($request->user(), $source);
            $label = trim((string) ($data['label'] ?? ''));
            $copy = $editor->copy($request->user(), $source, $label === '' ? null : $label);
        } catch (DomainException $refused) {
            return back()->withErrors(['roles' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->route('gemini.platform_settings.roles')
            ->with('success', sprintf(
                '%s is made from %s. %s Nobody holds it yet.',
                (string) $copy->label,
                (string) ($source->label ?? $source->name),
                $withheld === []
                    ? 'It holds everything '.($source->label ?? $source->name).' does.'
                    : 'Left out, because your own role does not hold them: '.implode(', ', $withheld).'.',
            ));
    }

    /** Rename one of the Director's own roles. `label` moves; the machine name never does. */
    public function renameRole(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:'.RoleEditor::LABEL_MAX],
        ], [], ['label' => 'role name']);

        try {
            $target = $editor->find($request->user(), $role);
            $was = (string) ($target->label ?? $target->name);
            $renamed = $editor->rename($request->user(), $target, (string) $data['label']);
        } catch (DomainException $refused) {
            return back()->withErrors(['roles' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->route('gemini.platform_settings.roles')
            ->with('success', $was === $renamed->label
                ? $was.' already has that name. Nothing has changed.'
                : sprintf('%s is now called %s. Everyone who held it still does, and what it may do has not changed.', $was, (string) $renamed->label));
    }

    /**
     * Re-grant the modules posted, and only those.
     *
     * The screen posts the cells that changed in one column, so one save is one
     * audited change to one role. It takes effect on the next page anybody
     * holding the role opens: `RoleEditor` recompiles the role and clears the
     * permission cache after its commit.
     */
    public function updateRolePermissions(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        $data = $request->validate(self::gridRules(required: true));

        try {
            $target = $editor->find($request->user(), $role);
            $editor->setPermissions($request->user(), $target, $data['grid']);
        } catch (DomainException $refused) {
            return back()->withErrors(['roles' => $refused->getMessage()]);
        }

        $holders = $target->users()->count();

        return redirect()
            ->route('gemini.platform_settings.roles')
            ->with('success', sprintf(
                '%s: saved. %s',
                (string) ($target->label ?? $target->name),
                $holders === 0
                    ? 'Nobody holds it yet, so nobody\'s access has changed.'
                    : sprintf('It applies from the next page %s holding it %s.', $holders === 1 ? 'the one person' : 'each of the '.$holders.' people', $holders === 1 ? 'opens' : 'open'),
            ));
    }

    /**
     * "Reset to GeminiSecure default": a seeded role back to the cells the
     * platform shipped with, and following platform updates again.
     *
     * `RoleEditor::resetToDefault()` owns it and can refuse it — a role already
     * at its default, a role made here, and a reset that would give or take
     * away something the Director no longer holds.
     */
    public function resetRole(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        try {
            $target = $editor->find($request->user(), $role);
            $reset = $editor->resetToDefault($request->user(), $target);
        } catch (DomainException $refused) {
            return back()->withErrors(['roles' => $refused->getMessage()]);
        }

        return redirect()
            ->route('gemini.platform_settings.roles')
            ->with('success', sprintf(
                '%s is back at GeminiSecure\'s default and follows platform updates again. Everyone holding it has '.
                'exactly the default from their next page; the audit log keeps what it held before.',
                (string) ($reset->label ?? $reset->name),
            ));
    }

    /**
     * What the reset control on one column shows: whether it is drawn, who
     * changed the role and when, what a reset would add and remove, and — if it
     * cannot be done — why.
     *
     * DRAWN ONLY FOR A SEEDED ROLE THAT IS NOT AT ITS DEFAULT. A role made here
     * has no default, and one at its default has nothing to reset.
     *
     * THE HOLDINGS RULE IS SAID HERE BEFORE THE PRESS. `refusal('reset')` answers
     * "is there anything to reset"; whether the Director holds everything the
     * reset would give or take away is asked only when it runs, by
     * `assertHoldsTheDifference()`. A Director who narrowed their own role can
     * no longer give it back what the default holds, and a button that worked
     * right up until the save was refused would be a control claiming what it
     * cannot do. So the difference is checked against the viewer's holdings
     * here, the same way, and the control drawn disabled with the reason.
     * `RoleEditor` still refuses the post regardless.
     *
     * @param  array<string, mixed>  $role  one row of `RoleEditor::board()`
     * @param  array<string, string>  $moduleLabels  module key => label
     * @return array{can_reset: bool, reset_refusal: string|null, resettable: bool, edited: string|null, reset_difference: array{add: list<string>, remove: list<string>}|null}
     */
    private function resetOffer(RoleEditor $editor, User $viewer, array $role, array $moduleLabels): array
    {
        $offer = [
            'can_reset' => (bool) $role['can_reset'],
            'reset_refusal' => $role['reset_refusal'],
            'resettable' => false,
            'edited' => null,
            'reset_difference' => null,
        ];

        if (! $role['is_system'] || $role['is_default']) {
            return $offer;
        }

        $difference = $editor->defaultDifference(Role::query()->findOrFail($role['id']));

        if ($difference === null) {
            return $offer;
        }

        $label = static function (string $permission) use ($moduleLabels): string {
            $parts = explode('.', $permission);

            // A named act by its label (M11 B3): "Platform settings · Set platform prices".
            $act = NamedActs::find(Console::Gemini, $parts[1] ?? '', $parts[2] ?? '');

            return ($moduleLabels[$parts[1] ?? ''] ?? $permission).' · '.($act['label'] ?? $parts[2] ?? '');
        };

        $on = $role['permissions_edited_at'] === null
            ? null
            : Carbon::parse((string) $role['permissions_edited_at'])->timezone((string) config('app.timezone'))->format('j F Y');

        $offer['resettable'] = true;
        $offer['edited'] = match (true) {
            $on !== null && $role['permissions_edited_by'] !== null => sprintf('Edited %s by %s.', $on, $role['permissions_edited_by']),
            $on !== null => sprintf('Edited %s.', $on),
            default => 'Differs from GeminiSecure\'s default.',
        };
        $offer['reset_difference'] = [
            'add' => array_map($label, $difference['add']),
            'remove' => array_map($label, $difference['remove']),
        ];

        if ($offer['can_reset']) {
            $unheld = array_values(array_filter(
                [...$difference['add'], ...$difference['remove']],
                static fn (string $permission): bool => ! $viewer->checkPermissionTo($permission),
            ));

            if ($unheld !== []) {
                $offer['can_reset'] = false;
                $offer['reset_refusal'] = sprintf(
                    'Resetting %s would give it or take from it %s, which your own role does not hold, so the reset is '.
                    'not yours to make. It can be done on the server with php artisan rbac:reset-role.',
                    (string) $role['label'],
                    implode(', ', array_map($label, $unheld)),
                );
            }
        }

        return $offer;
    }

    /** Delete one of the Director's own roles that nobody holds, nor ever held. */
    public function deleteRole(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        try {
            $target = $editor->find($request->user(), $role);
            $label = (string) ($target->label ?? $target->name);
            $editor->delete($request->user(), $target);
        } catch (DomainException $refused) {
            return back()->withErrors(['roles' => $refused->getMessage()]);
        }

        return redirect()
            ->route('gemini.platform_settings.roles')
            ->with('success', $label.' is deleted. The audit log keeps what it held and who deleted it.');
    }

    /**
     * The shape of a posted grid, and nothing more.
     *
     * Keyed by module key; which keys exist, which levels and verbs are real,
     * and whether the actor may give them are RoleEditor's to answer, in the
     * sentences the screen prints. Forty is room for every module this console
     * has, with a margin; seven is every verb.
     *
     * @return array<string, list<mixed>>
     */
    private static function gridRules(bool $required): array
    {
        return [
            'grid' => $required ? ['required', 'array', 'min:1', 'max:40'] : ['nullable', 'array', 'max:40'],
            'grid.*' => ['array'],
            'grid.*.level' => ['required', 'string', 'max:16'],
            'grid.*.verbs' => ['nullable', 'array', 'max:7'],
            'grid.*.verbs.*' => ['string', 'max:16'],
            'grid.*.can_approve' => ['nullable', 'boolean'],

            // Named acts (M11 B3). Which exist on which module is RoleEditor's to say.
            'grid.*.acts' => ['nullable', 'array', 'max:16'],
            'grid.*.acts.*' => ['string', 'max:40'],
        ];
    }

    /**
     * The two modules whose `view` is what M10 §5 reads as permission to see
     * money, with what the editor must say before a role is given one.
     *
     * DERIVED FROM `PlatformMoney`, not listed here: that class is the one
     * place the platform decides which permission unlocks which figures, and a
     * warning keyed on a second list would stop warning the day the two
     * disagreed. The sentences name the kind of figure and never a figure.
     *
     * `$labels` — module key => label — is the board's own list where the caller already holds it (M11 A2):
     * the roles page read every Gemini module for the board, and asking the table again for their labels was
     * a statement the page paid for twice.
     *
     * @param  array<string, string>|null  $labels
     * @return list<array{key: string, label: string, warning: string}>
     */
    private function moneyModules(?array $labels = null): array
    {
        $specs = [
            PlatformMoney::REVENUE_PERMISSION => 'Under M10 §5 that is what lets a role see what each client is charged, which tier it is on '.
                'and whether it has paid — on the dashboard, the client list and every client record, not only under Billing.',
            PlatformMoney::PAY_PERMISSION => 'Under M10 §5 that is what lets a role see what each officer is paid — on every guard record, '.
                'not only under Payroll.',
        ];

        $labels = collect($labels ?? Module::query()->where('console', Console::Gemini->value)->pluck('label', 'key')->all());
        $out = [];

        foreach ($specs as $permission => $sentence) {
            $key = explode('.', $permission)[1] ?? '';

            if (! $labels->has($key)) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => (string) $labels[$key],
                'warning' => sprintf(
                    'This gives the role %s view. %s Everyone who holds the role sees it from their next page.',
                    (string) $labels[$key],
                    $sentence,
                ),
            ];
        }

        return $out;
    }

    /**
     * What a copy of this role would hold, for the Copy panel to say before the
     * copy is made — the same `copyableGrid()` that `copy()` then uses.
     *
     * @return array{role_id: int, source: string, default_label: string, withheld: list<string>, lines: list<string>}
     */
    private function copyOffer(RoleEditor $editor, User $viewer, int $roleId): array
    {
        $source = $editor->find($viewer, $roleId);
        $label = (string) ($source->label ?? $source->name);
        ['grid' => $grid, 'withheld' => $withheld] = $editor->copyableGrid($viewer, $source);

        $lines = [
            $withheld === []
                ? sprintf('The copy will hold everything %s holds, and reach %s.', $label, mb_strtolower($source->scope_default->label()))
                : sprintf(
                    'The copy will hold what %s holds except what your own role does not: %s. Those are left out, and the audit entry names them.',
                    $label,
                    implode(', ', $withheld),
                ),
        ];

        $levels = collect(AccessLevel::cases())->keyBy(static fn (AccessLevel $level): string => $level->value);

        foreach ($this->moneyModules() as $money) {
            $cell = $grid[$money['key']] ?? null;

            if ($cell === null) {
                continue;
            }

            $verbs = $cell['level'] === AccessLevel::Custom->value
                ? (array) $cell['verbs']
                : array_map(static fn ($verb): string => $verb->value, $levels[$cell['level']]->verbs());

            if (in_array('view', $verbs, true)) {
                $lines[] = $money['warning'];
            }
        }

        $lines[] = 'Nobody will hold it until somebody is moved into it under Platform admins.';

        return [
            'role_id' => $roleId,
            'source' => $label,
            'default_label' => 'Copy of '.$label,
            'withheld' => $withheld,
            'lines' => $lines,
        ];
    }

    /**
     * One pill, read straight off the matrix row.
     *
     * @return array{variant: string, label: string}
     */
    private function cell(?RoleModuleAccess $access): array
    {
        /*
         * A missing row is not "no access".
         *
         * The matrix is seeded complete, so a gap means the seed is wrong.
         * Printing an em dash there would hide a broken seed behind a cell
         * that reads as a deliberate denial; `php artisan rbac:show` prints
         * the same question mark for the same reason.
         */
        if ($access === null) {
            return ['variant' => 'none', 'label' => '?'];
        }

        /*
         * "Scoped" is not a fourth level.
         *
         * AccessScope defines it as Full narrowed by reach (D-007 keeps the
         * two axes orthogonal), and that definition is the only rule applied
         * here. Head of Security is the only Gemini role whose rows carry a
         * narrowed scope, which is why it is the only column that shows it.
         */
        $narrowed = $access->level === AccessLevel::Full && $access->scope !== AccessScope::All;

        /*
         * A CUSTOM CELL NAMES ITS VERBS (M10 §4). "Custom" alone would tell the
         * Director nothing about what the role can do, so the pill is the cell's
         * own `pillLabel()` — "Custom (view, export) · Approver" — which is the
         * one place that phrasing is decided, and which carries the Approver tag
         * itself. Its colour follows its reach the way Full's does: the navy
         * View pill, or the amber one on a role narrowed to assigned sites.
         */
        if ($access->level === AccessLevel::Custom) {
            return [
                'variant' => $access->scope !== AccessScope::All ? 'scoped' : 'view',
                'label' => $access->pillLabel(),
            ];
        }

        $label = $narrowed ? 'Scoped' : $access->level->label();

        // From can_approve, never from the level: Full without the Approver tag
        // must not read as permission to commit the irreversible act (D-008).
        if ($access->can_approve) {
            $label .= ' · Approver';
        }

        // Named acts held one by one (M11 B3) are said on the pill, in the
        // words `pillLabel()` uses: "View · Sets prices".
        $acts = $access->actsLabel();

        if ($acts !== null) {
            $label .= ' · '.$acts;
        }

        /*
         * The board defines four pill variants — full, view, scoped, none.
         *
         * Entry, which only the Estate matrix uses, has no variant on this
         * board, so it shares View's plain navy pill and the word in the pill
         * carries the difference. Inventing a fifth variant would mean
         * authoring CSS the design does not have.
         */
        $variant = match (true) {
            $access->level === AccessLevel::None => 'none',
            $narrowed => 'scoped',
            $access->level === AccessLevel::Full => 'full',
            default => 'view',
        };

        return ['variant' => $variant, 'label' => $label];
    }

    /* -----------------------------------------------------------------
     | Email — where the platform sends from
     |------------------------------------------------------------------ */

    /**
     * The relay, as a form.
     *
     * THE PASSWORD IS NEVER IN THE PAYLOAD. `MailSettings::shape()` reports whether one is set
     * and when it last changed, and nothing more — the field on the screen is write-only, shows
     * dots, and posts nothing unless somebody types a new one.
     */
    public function mail(Request $request, MailSettings $mail, MailFailures $failures): Response
    {
        // Every send of the sixty days kept, a page at a time, in the order its headings ask for (M32 F5).
        $sentTable = MailLog::table($request);
        ['rows' => $sent, 'table' => $sentMeta] = MailLog::page($sentTable);

        return inertia('Gemini/Settings/Email', [
            'tabs' => $this->tabs('gemini.platform_settings.mail'),
            'settings' => $mail->shape(),
            // M12 §5.1: what the platform tried three times to send and could not — here, not only in the log.
            'failures' => $failures->recent(50),

            // M18G §7: EVERY email sent — to whom, what kind, when, and whether the mail server took it — searchable by
            // address. The first place to look when somebody says "I never got the code".
            'sent' => $sent,
            'address' => $sentTable->search,
            'sentTable' => $sentMeta,
            'canConfigure' => $request->user()->can(self::MESSAGING),
            'writeDisabledReason' => 'Changing where the platform sends mail from needs the Mail and SMS delivery permission on Platform settings.',
        ]);
    }

    public function saveMail(Request $request, MailSettings $mail): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:190'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],

            /*
             * `smtps` or nothing. Laravel 11 replaced MAIL_ENCRYPTION with MAIL_SCHEME and the
             * two are not the same field renamed: port 465 is implicit TLS and wants `smtps`,
             * port 587 negotiates after connecting and wants no scheme at all. Accepting the
             * old `tls`/`ssl` words here would let somebody save a setting that connects in the
             * clear and then fails a handshake — which surfaces as a timeout naming nothing.
             */
            'scheme' => ['nullable', 'string', 'in:smtps'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:190'],
            'from_address' => ['required', 'email', 'max:190'],
            'from_name' => ['required', 'string', 'max:120'],
        ], [], [
            'from_address' => 'from-address',
            'from_name' => 'from-name',
        ]);

        $mail->save($data);

        return back()->with('success', 'Saved. Send a test message to prove the relay accepts it.');
    }

    /**
     * Prove it, rather than trusting it.
     *
     * A saved relay is an unproven one until a message goes through it, which is why `save()`
     * clears `verified_at` on every change. The outcome is reported in words an operator can
     * act on — `MailSettings::explain()` — rather than as the relay's own numeric refusal.
     */
    public function testMail(Request $request, MailSettings $mail): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email', 'max:190'],
        ]);

        $result = $mail->sendTest($data['to']);

        return $result['ok']
            ? back()->with('success', $result['message'])
            : back()->withErrors(['mail' => $result['message']]);
    }

    /* -----------------------------------------------------------------
     | SMS — which provider the platform texts through (M10 §1)
     |------------------------------------------------------------------ */

    /**
     * The provider, as a form — the Email screen's shape, deliberately.
     *
     * THE TOKEN IS NEVER IN THE PAYLOAD. `SmsSettings::shape()` reports whether one is saved
     * and nothing more; the field is write-only and posts nothing unless somebody types a new
     * one. The provider list is `PlatformSmsSetting::DRIVERS` printed, so the select cannot
     * offer a name the binding would quietly send to the log.
     */
    public function sms(Request $request, SmsSettings $sms, SupportLine $line): Response
    {
        return inertia('Gemini/Settings/Sms', [
            'tabs' => $this->tabs('gemini.platform_settings.sms'),
            'settings' => $sms->shape(),
            // Gemini Security's own phone line, which the Resident App's "Call Gemini Security" calls — or hides, while empty.
            'supportLine' => $line->shape(),
            'drivers' => array_map(
                static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys(PlatformSmsSetting::DRIVERS),
                array_values(PlatformSmsSetting::DRIVERS),
            ),
            'canConfigure' => $request->user()->can(self::MESSAGING),
            'writeDisabledReason' => 'Changing which provider the platform texts through needs the Mail and SMS delivery permission on Platform settings.',
        ]);
    }

    /**
     * Save it.
     *
     * The token is never flashed back on a refusal — `token` is on the exception handler's
     * `dontFlash` list (bootstrap/app.php) — and the refusals below are redirected WITHOUT
     * input for the same reason: an old-input bag is a plain-text column in `sessions`.
     */
    public function saveSms(Request $request, SmsSettings $sms): RedirectResponse
    {
        $data = $request->validate([
            'driver' => ['required', 'string', Rule::in(array_keys(PlatformSmsSetting::DRIVERS))],
            'account_sid' => ['nullable', 'string', 'max:191'],
            'token' => ['nullable', 'string', 'max:190'],

            // An E.164 number is at most sixteen characters and an alphanumeric sender
            // ID eleven; thirty-two is the column, and room for the spaces people type.
            'from' => ['nullable', 'string', 'max:32'],
        ], [
            'driver.in' => 'Choose one of the providers listed. A name the platform has no connection for would be written to the log and delivered to nobody.',
        ], [
            'account_sid' => 'account SID',
            'token' => 'auth token',
            'from' => 'sender',
        ]);

        try {
            $sms->save($data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['settings' => $refused->getMessage()]);
        }

        return back()->with('success', $sms->canSend()
            ? 'Saved. Send a test message to prove the provider accepts it.'
            : 'Saved. No text message can be sent until a provider is chosen and its credentials are complete.');
    }

    /**
     * Gemini Security's phone line (the user, 8 Oct 2026): saved, it is what every resident's "Call Gemini Security"
     * calls; emptied, the Resident App hides that button again. `configure_messaging`, like the provider above: one
     * setting that changes what every client's residents are offered at once. See `SupportLine`.
     */
    public function saveSupportLine(Request $request, SupportLine $line): RedirectResponse
    {
        $data = $request->validate(['support_phone' => ['nullable', 'string', 'max:32']], [], ['support_phone' => 'phone number']);

        try {
            $number = $line->save($data['support_phone'] ?? null, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['support_phone' => $refused->getMessage()]);
        }

        return back()->with('success', $number === null
            ? 'Cleared. The Resident App no longer shows "Call Gemini Security".'
            : 'Saved as '.SupportLine::spaced($number).'. Residents\' "Call Gemini Security" now calls it — try it from a real phone.');
    }

    /**
     * Prove it, rather than trusting it.
     *
     * `configure` and throttled, like the mail test: it makes the platform text a number
     * somebody typed, at the client's cost per message.
     */
    public function testSms(Request $request, SmsSettings $sms): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:32'],
        ], [], [
            'to' => 'number',
        ]);

        try {
            $message = $sms->sendTest($data['to'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['sms' => $refused->getMessage()]);
        }

        return back()->with('success', $message);
    }

    /**
     * Platform admins — board 42's tab (12 §2, item 43). See `PlatformAdmins`.
     *
     * Three verbs, three flags, because they are three different powers:
     * `create` issues an invitation, `update` moves a person between roles or
     * suspends them, and `approve` — the power to change roles — takes a person
     * off the console (M10 §4). The roles offered are `PlatformAdmins::board()`'s:
     * the six seeded ones and any the Director has made, never a row an estate
     * owns.
     */
    public function admins(Request $request, PlatformAdmins $admins): Response
    {
        return inertia('Gemini/Settings/Admins', [
            'tabs' => $this->tabs('gemini.platform_settings.admins'),
            ...$admins->board($request->user()),
            'canInvite' => $request->user()->can('gemini.platform_settings.create'),
            'canManage' => $request->user()->can('gemini.platform_settings.update'),
            'canRemove' => $request->user()->can(RoleEditor::editPermission(Console::Gemini)),
            'writeDisabledReason' => 'Issuing or changing a platform account needs Platform settings create and update access.',
            'removeDisabledReason' => 'Taking somebody off the Gemini Console is an approve-level act on Platform settings — the power '.
                'to change roles — and your role does not hold it. On the seeded matrix only the Director does.',
        ]);
    }

    public function inviteAdmin(Request $request, Invitations $invitations): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', 'string'],
        ]);

        // The platform's own roles only: `ownedBy(null)` keeps a row an estate
        // owns off this console even if one were ever mis-created on it.
        $role = Role::query()->where('name', $data['role'])->where('console', Console::Gemini->value)->ownedBy(null)->first();

        if ($role === null) {
            return back()->withErrors(['admins' => 'Choose one of the Gemini Console\'s roles.'])->withInput();
        }

        // Whether the email went, in the invitation's own row (DESIGN §11 row 2). Fully qualified: the hunk stays here.
        try {
            $invitation = $invitations->invite($data['email'], $role, null, $request->user());
        } catch (InvitationNotSent $unsent) {
            return back()->with('invitation_outcome', ['id' => $unsent->invitation->id, 'state' => $unsent->state(), 'message' => $unsent->getMessage()]);
        } catch (DomainException $refused) {
            return back()->withErrors(['admins' => $refused->getMessage()])->withInput();
        }

        return back()->with('invitation_outcome', ['id' => $invitation->id, 'state' => 'sent', 'message' => sprintf(
            // "just now" belongs to no clock (M19A §4.1); a date always has its weekday (§4.4).
            'Invitation emailed to %s as %s just now. It stands until %s.',
            $invitation->email,
            (string) ($role->label ?? $role->name),
            SiteTime::local($invitation->expires_at)->format('l, F j'),
        )]);
    }

    /** A fresh link only if the email goes; a refusal leaves the one they hold as it was, and says so in its row. */
    public function resendAdminInvitation(Request $request, Invitation $invitation, Invitations $invitations): RedirectResponse
    {
        abort_unless($invitation->tenant_id === null, 404);

        try {
            $invitations->resend($invitation, $request->user());
        } catch (InvitationNotSent $unsent) {
            return back()->with('invitation_outcome', ['id' => $invitation->id, 'state' => $unsent->state(), 'message' => $unsent->getMessage()]);
        } catch (DomainException $refused) {
            return back()
                ->withErrors(['admins' => $refused->getMessage()])
                ->with('invitation_outcome', ['id' => $invitation->id, 'state' => 'refused', 'message' => $refused->getMessage()]);
        }

        return back()->with('invitation_outcome', ['id' => $invitation->id, 'state' => 'sent', 'message' => sprintf(
            'Invitation emailed again to %s just now. The earlier link no longer works; this one stands until %s.',
            $invitation->email,
            SiteTime::local($invitation->expires_at)->format('l, F j'),
        )]);
    }

    public function revokeAdminInvitation(Request $request, Invitation $invitation, Invitations $invitations): RedirectResponse
    {
        abort_unless($invitation->tenant_id === null, 404);

        try {
            $invitations->revoke($invitation, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['admins' => $refused->getMessage()]);
        }

        return back()->with('success', 'Invitation to '.$invitation->email.' withdrawn.');
    }

    public function manageAdmin(Request $request, User $user, PlatformAdmins $admins): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'in:active,suspended'],
            'sites' => ['nullable', 'array', 'max:500'],
            'sites.*' => ['string', 'max:64'],
        ]);

        try {
            $admins->manage($user, $data['role'], $data['status'], array_values($data['sites'] ?? []), $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['admins' => $refused->getMessage()]);
        }

        return back()->with('success', $user->name.' updated. The change is on the audit log with who made it.');
    }

    /**
     * Take somebody off the Gemini Console (M10 §4, "remove administrators").
     *
     * REMOVED, NOT DELETED. `PlatformAdmins::remove()` suspends the account and
     * leaves it holding no role and no site, so it reaches nothing; the row
     * stays because the audit log names this person against everything they
     * did. Nobody removes themselves and the last active Director is never
     * removed — both refused there, in the sentence printed here.
     */
    public function removeAdmin(Request $request, User $user, PlatformAdmins $admins): RedirectResponse
    {
        try {
            $admins->remove($user, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['admins' => $refused->getMessage()]);
        }

        return back()->with('success', sprintf(
            '%s is off the Gemini Console: suspended, holding no role and no site. The account is kept, so the audit '.
            'log still names them against what they did; choosing a role for them under Change brings them back.',
            (string) $user->name,
        ));
    }

    /**
     * The Platform settings tab strip, in the order the board draws it.
     *
     * Built screens carry a route; a tab that cannot be opened carries the
     * reason. A tab is never dropped — the board draws seven, and a strip that
     * silently loses one tells the reader the module is smaller than it is.
     *
     * @return list<array{label: string, href: string|null, active: bool, reason: string|null}>
     */
    private function tabs(string $active): array
    {
        $readsRates = (bool) request()->user()?->can('gemini.payroll_accounting.view');

        $tabs = [
            ['label' => 'Pricing & rates', 'route' => 'gemini.platform_settings', 'reason' => null],
            ['label' => 'Package builder', 'route' => 'gemini.platform_settings.packages', 'reason' => null],
            ['label' => 'Client line items', 'route' => 'gemini.platform_settings.line_items', 'reason' => null],

            // Built (12 §2, item 43): the platform's own accounts and invitations.
            ['label' => 'Platform admins', 'route' => 'gemini.platform_settings.admins', 'reason' => null],
            ['label' => 'Role access matrix', 'route' => 'gemini.platform_settings.roles', 'reason' => null],

            /*
             * WHERE THE PLATFORM SENDS FROM. Not a notification preference — that is the tab
             * below, and it stays out of scope for the reasons given there. This is the relay
             * itself, and it is here because the alternative was an SSH session, a deploy and a
             * secret pasted into a shell every time it changes. It will change: the account
             * configured on 23 September 2026 is a developer's own, and the client's must
             * replace it before go-live.
             */
            ['label' => 'Email', 'route' => 'gemini.platform_settings.mail', 'reason' => null],

            /*
             * WHICH PROVIDER THE PLATFORM TEXTS THROUGH (M10 §1), beside Email because it is
             * the same kind of thing: a credential the platform presents to somebody else,
             * which the client must be able to change without a deploy. Every panic text and
             * every sign-in code by SMS goes through what is saved here.
             */
            ['label' => 'SMS', 'route' => 'gemini.platform_settings.sms', 'reason' => null],
            [
                'label' => 'Statutory rates',

                /*
                 * The rate table lives under Payroll & Accounting, a different
                 * module with its own permission. So the tab is a link for a
                 * role that holds it and says why for one that does not, rather
                 * than sending some readers to a 403.
                 */
                'route' => $readsRates ? 'gemini.payroll_accounting.rates' : null,
                'reason' => $readsRates ? null : 'Statutory rates are held under Payroll & Accounting, which your role '
                    ."does not hold. They follow TAJ's published tables, as ruled on Q-002.",
            ],
            [
                'label' => 'Notifications',
                'route' => null,

                /*
                 * Out of scope as a SETTINGS section, and the reason says what
                 * exists instead: the console's notification centre derives
                 * every item from its record and has nothing to configure, and
                 * who is emailed or texted about an estate event is set by that
                 * estate on its own board 30.
                 */
                'reason' => 'Nothing to configure here. The console\'s notifications — the dashboard bell — are '
                    .'read from the records themselves, and delivery defaults for an estate\'s events are set by '
                    .'that estate under its own Settings.',
            ],
        ];

        return array_map(
            fn (array $tab): array => [
                'label' => $tab['label'],
                'href' => $tab['route'] === null ? null : route($tab['route'], absolute: false),
                'active' => $tab['route'] === $active,
                'reason' => $tab['reason'],
            ],
            $tabs,
        );
    }
}
