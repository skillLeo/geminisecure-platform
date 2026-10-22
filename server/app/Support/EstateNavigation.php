<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Services\Estate\PlanGate;

/**
 * The Estate Console sidebar, exactly as every board draws it.
 *
 * ELEVEN ITEMS IN FOUR GROUPS, and the boards are the contract: Dashboard on its
 * own, then Estate, Finance, Community and System. The boards draw ten; the
 * eleventh is Community → Security, added by M10 §2 for the estate's own Head of
 * Security, which no board draws. That list is not the same as
 * the permission module list and was never meant to be — D-010 split
 * `accounting` into `accounting_posting` and `vendor_costs`, and `dues_ledger`
 * away from `maintenance_budget`, precisely so a Property Manager could be
 * refused the money and still see what they commission. Those splits are about
 * WHO MAY DO WHAT. This is about where a click goes, and an item is drawn on
 * exactly the permission its destination checks (B138).
 *
 * A ROLE THAT HOLDS NONE OF AN ITEM'S MODULES DOES NOT SEE IT. Not disabled,
 * not greyed — absent. The Build Spec is explicit: "A role without a module
 * permission does not see that module at all." A Property Manager opening this
 * console finds no Dues & ledger and no Accounting, which is the separation
 * working rather than a fault to explain.
 */
class EstateNavigation
{
    /**
     * The sidebar, in the board's own order.
     *
     * `modules` IS THE DESTINATION'S OWN GATE, AND NOTHING WIDER (B138). It was
     * an any-of, and three items listed a module their page does not check:
     * Accounting on `vendor_costs`, Dues & ledger on `payments` and Facilities
     * on `maintenance_budget`. Every accounting screen — the supplier register
     * included — sits behind `accounting_posting.view`, every dues screen
     * behind `dues_ledger.view` and every facilities screen behind
     * `facilities.view`, and no route anywhere is gated on the other three. So
     * the seeded Property Manager, who holds Vendor costs and is refused
     * Accounting by Ruling 1, was drawn an Accounting item whose every click
     * was "Not available to your role" — and the error page then told them
     * they had arrived by URL. A role a Community Super Admin builds on
     * Payments or Maintenance budget alone (M10 §4) met the same dead link.
     *
     * A role holding only one of those three modules therefore has no sidebar
     * item for it, because there is no screen for it to open: that is the
     * item being absent, as the class docblock requires, rather than a gap in
     * the navigation. A screen for vendor costs on their own would be a new
     * screen, not a sidebar edit. `EstateSidebarReachesItsPagesTest` follows
     * every item for every seeded role and for a role built on each module.
     *
     * EVERY ITEM NOW HAS A DESTINATION, AND THAT IS AN INVARIANT RATHER THAN A
     * COINCIDENCE. This list carried a nullable href through the build — a
     * module with no screens yet drew greyed and inert, so a role could still
     * check what it would reach — and the one thing that had to be kept honest
     * was flipping the href in the commit that landed the module's first screen.
     * It was missed twice, for Accounting and for Facilities, and the console
     * told a manager a module was missing while nine of its pages sat one click
     * away.
     *
     * Reports was the tenth and last, so the nullable case is now unreachable
     * and is gone rather than kept as dead code somebody would have to reason
     * about. A new module arrives with its first screen or it does not arrive:
     * `EstateNavigationTest` asserts every item a role can see has somewhere to
     * go, which is a live assertion where `pending` had become a vestige.
     * D-070.
     *
     * @var list<array{key: string, label: string, icon: string, section: string|null, href: string, modules: list<string>}>
     */
    private const ITEMS = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'section' => null, 'href' => '/', 'modules' => ['dashboard']],

        ['key' => 'estate_structure', 'label' => 'Estate structure', 'icon' => 'estate', 'section' => 'Estate', 'href' => '/estate', 'modules' => ['estate_structure']],
        ['key' => 'residents', 'label' => 'Residents', 'icon' => 'residents', 'section' => 'Estate', 'href' => '/residents', 'modules' => ['residents']],

        ['key' => 'dues_ledger', 'label' => 'Dues & ledger', 'icon' => 'dues', 'section' => 'Finance', 'href' => '/finance/arrears', 'modules' => ['dues_ledger']],
        ['key' => 'accounting', 'label' => 'Accounting', 'icon' => 'accounting', 'section' => 'Finance', 'href' => '/accounting/chart-of-accounts', 'modules' => ['accounting_posting']],
        ['key' => 'payroll', 'label' => 'Payroll & HR', 'icon' => 'payroll', 'section' => 'Finance', 'href' => '/payroll', 'modules' => ['payroll']],

        ['key' => 'facilities', 'label' => 'Facilities', 'icon' => 'facilities', 'section' => 'Community', 'href' => '/facilities/maintenance', 'modules' => ['facilities']],
        /*
         * THE MEETING REGISTER, NOT THE ELECTION, and the boards themselves are
         * why. Board 9's caption reads "Sidebar → Governance → Elections", but an
         * election is addressed by YEAR — /governance/elections/2026 — and a
         * constant here would still be pointing at 2026 in 2028, when there is no
         * such ballot to draw. The register is the one governance screen with a
         * static address, it is never empty for an estate that has ever met, and
         * its own sub-navigation reaches Elections in one more click.
         */
        ['key' => 'governance', 'label' => 'Governance', 'icon' => 'governance', 'section' => 'Community', 'href' => '/governance/meetings', 'modules' => ['governance']],

        /*
         * SECURITY — the estate's own picture of its gate (M10 §2).
         *
         * Panic alerts, incidents, gate activity and the visitor log all live in
         * the central database and were readable only from the Gemini console,
         * so the community whose gate it is could not see its own security at
         * all. This is the item that gets them there, and the new estate Head of
         * Security is the role it was added for.
         *
         * Between Governance and Reports because the boards group by what a
         * person does: the three Community items are the estate's own life, and
         * Reports and Settings are the system's.
         */
        ['key' => 'security', 'label' => 'Security', 'icon' => 'governance', 'section' => 'Community', 'href' => '/security', 'modules' => ['security']],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'reports', 'section' => 'Community', 'href' => '/reports', 'modules' => ['reports']],

        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'section' => 'System', 'href' => '/settings/profile', 'modules' => ['settings']],
    ];

    /**
     * What this viewer's sidebar holds.
     *
     * @param  string  $active  the key of the item the current screen sits under
     * @return list<array{key: string, label: string, icon: string, section: string|null, href: string, active: bool}>
     */
    public function forViewer(User $viewer, string $active = '', string $tenantKey = ''): array
    {
        $items = [];

        foreach (self::ITEMS as $item) {
            if (! $this->mayReach($viewer, $item['modules'])) {
                continue;
            }

            // A MODULE THE ESTATE'S PLAN LEAVES OUT IS ABSENT TOO (Hassam's closing order, 12 Oct): asked only of an
            // item the viewer could otherwise reach, and only of the two modules a plan can leave out (`PlanGate`).
            if (isset(PlanGate::MODULES[$item['key']]) && PlanGate::leavesOut(PlanGate::MODULES[$item['key']], $tenantKey)) {
                continue;
            }

            $items[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'icon' => $item['icon'],
                'section' => $item['section'],
                'href' => $this->path($item['href'], $tenantKey),
                'active' => $item['key'] === $active,
            ];
        }

        return $items;
    }

    /**
     * The estate path, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname, so the path is bare.
     * Local serves them all from one host with the estate in the path, because
     * *.localhost does not resolve on Windows — see routes/tenant.php.
     */
    private function path(string $href, string $tenantKey): string
    {
        if (! app()->isLocal() || $tenantKey === '') {
            return $href;
        }

        return rtrim('/estate/'.$tenantKey.rtrim($href, '/'), '/') ?: '/estate/'.$tenantKey;
    }

    /**
     * @param  list<string>  $modules
     */
    private function mayReach(User $viewer, array $modules): bool
    {
        foreach ($modules as $module) {
            if ($viewer->can('estate.'.$module.'.view')) {
                return true;
            }
        }

        return false;
    }
}
