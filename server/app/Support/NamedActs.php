<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Console;
use App\Enums\PermissionVerb;

/**
 * NAMED ACTS: a verb of one module split into one permission per power (M11 B3).
 *
 * THE FAULT THIS CLOSES. The matrix is module × verb, and a verb is one
 * permission. For most modules that is right. For seven it was not, because one
 * verb gated powers with different consequences, and so one grant carried all of
 * them. PRICE-02 is the one the client named: to let the Community Super Admin
 * change a dues rate, its Dues & ledger cell became an Approver, and that same
 * permission also reversed posted months, activated payment plans (which lifts a
 * household's gate restriction), flagged households and put reminder wording in
 * force. "Being able to change a dues rate must not also grant reversing a posted
 * charge run or activating payment plans."
 *
 * A NAMED ACT IS A PERMISSION UNDER ITS MODULE, NOT A NEW MODULE. It is
 * `<console>.<module>.<act>`, three segments like every other permission, so the
 * sidebar, `can:` and the audit log read it the way they read a verb. It hangs off
 * the verb it was split from (its "parent"):
 *
 *   - a cell that grants the parent grants every act under it. The Approver tag
 *     on Dues & ledger is all five dues approvals; Full on Platform settings is
 *     all three of its configure acts. So every cell seeded or saved before this
 *     split grants exactly what it granted before, and nothing had to be rewritten.
 *   - a cell may instead grant some of the acts, one by one, without the parent.
 *     That is the only new thing a cell can say (`role_module_access.acts`).
 *   - the parent's own permission (`estate.dues_ledger.approve`) no longer exists.
 *     Its whole power is the acts, so a check that still named it would be asking
 *     about a permission nobody can hold, and fails closed.
 *
 * This class is the only list of them. `RoleModuleAccess` compiles a cell through
 * it, `RbacMatrixSeeder` creates the permissions from it, and `RoleEditor` offers
 * and checks each act from it. A route names an act by `permission()`'s string.
 *
 * WHY THESE SEVEN AND NOT OTHERS — DECISIONS D-150, with the whole permission set
 * audited verb by verb. In short: each split verb gated acts whose consequences
 * fall on different things (what households are billed, the ledger's history, who
 * is let through the gate, who is chased, what they are told; money owed to a
 * supplier and a period signed off; staff paid and a liability remitted; who
 * governs and when members meet; what clients pay and where every alert is
 * delivered; who on the committee holds which role, and what the estate's letters
 * say about it; what a client is charged, billed, sent and written off), and a
 * real role needs one without the other.
 */
final class NamedActs
{
    /**
     * console => module => parent verb => act => [label, short, description]
     *
     * `label` is the editor's checkbox and the refusal's name for it; `short` is
     * the matrix pill's ("Full · Sets dues rates"); `description` is what the act
     * actually does, in the words a committee reads. Registry order is the order
     * every screen lists them in.
     */
    private const ACTS = [
        'estate' => [
            'dues_ledger' => [
                'approve' => [
                    'set_rates' => [
                        'Set dues rates',
                        'sets dues rates',
                        'Define a recurring charge, change its rate from a month not yet billed, or stop it. Months already posted keep the rate they were raised at.',
                    ],
                    /*
                     * A MONTH, OR ONE CHARGE (M53 §2, 9 October 2026): reversing one household's single charge from its
                     * ledger is the same act on one line, so it is the same permission, and the label names both.
                     */
                    'reverse_runs' => [
                        'Reverse posted charges',
                        'reverses posted charges',
                        'Take a posted month of a charge schedule back out of every household it billed, or one charge out of one household\'s ledger, each with a mirror journal entry.',
                    ],
                    /*
                     * ACTIVATING A PLAN AND RULING ITS INSTALMENTS ARE ONE POWER.
                     * Bug hunt B019 gave a plan its ending: an approver rules each
                     * instalment met or missed. Both acts decide the one thing —
                     * whether this household's plan shields it at the gate and from
                     * the reminder run — so they are one permission, and the label
                     * says both, because a box must name everything it grants.
                     */
                    'activate_plans' => [
                        'Activate and rule payment plans',
                        'activates and rules plans',
                        'Put a payment plan the household agreed to in force, which pauses automated reminders and lifts the arrears restriction on its guest passes, and rule each instalment met or missed. A missed instalment defaults the plan and puts both back.',
                    ],
                    'flag_units' => [
                        'Hardship and dispute flags',
                        'flags households',
                        'Raise or lift a hardship or dispute flag on one household, which stops or restarts automated reminders to it. What it owes does not change.',
                    ],
                    'adopt_dunning' => [
                        'Put reminder wording in force',
                        'adopts reminder wording',
                        'Adopt a drafted reminder against a committee resolution. Every automated notice after it goes out in the new wording.',
                    ],
                ],
            ],
            'accounting_posting' => [
                'approve' => [
                    'approve_bills' => [
                        'Approve bills',
                        'approves bills',
                        'Approve a recorded bill, which posts it to Accounts Payable as money the estate owes the supplier.',
                    ],
                    'complete_reconciliations' => [
                        'Sign off reconciliations',
                        'signs off reconciliations',
                        'Complete a bank reconciliation, which signs the period off so that nobody re-opens it.',
                    ],
                ],
            ],
            'payroll' => [
                'approve' => [
                    'approve_runs' => [
                        'Approve pay runs',
                        'approves pay runs',
                        'Release a calculated pay run, which posts it and pays every line on it. Never a run you prepared yourself.',
                    ],
                    'file_returns' => [
                        'File statutory returns',
                        'files returns',
                        'Record a statutory return as filed, which remits what approved runs withheld and clears it from the statutory payable.',
                    ],
                ],
            ],
            'governance' => [
                'approve' => [
                    'certify_elections' => [
                        'Certify election results',
                        'certifies elections',
                        'Declare a closed ballot\'s result final. A certified ballot cannot be reopened.',
                    ],
                    'publish_meetings' => [
                        'Publish meetings',
                        'publishes meetings',
                        'Send a meeting\'s notice to every household in its audience. It cannot be taken back.',
                    ],
                ],
            ],

            /*
             * SETTINGS · UPDATE was two powers of different kinds. Moving somebody
             * between roles — or suspending them, or taking them off the committee
             * — changes what a person may do on this estate: a role given it can
             * seat somebody as Treasurer, with every money approval that carries.
             * Keeping the enquiries contacts, the logo and the notification
             * defaults current changes what the estate's paperwork and messages
             * say. A role that keeps the letterhead need not be one that decides
             * who sits on the committee.
             */
            'settings' => [
                'update' => [
                    'manage_people' => [
                        'Manage people',
                        'manages people',
                        'Change which role somebody on this estate holds, suspend or restore their access, or take them off the committee. Never into or out of an administrator\'s role, which only an administrator hands out.',
                    ],
                    'edit_profile' => [
                        'Edit the estate profile',
                        'edits the profile',
                        'Change the estate\'s enquiries email and telephone, the security provider it names, its logo (printed on every statement, receipt and certificate) and the notification defaults every household starts from.',
                    ],
                ],
            ],
        ],
        'gemini' => [
            /*
             * BILLING & SUBSCRIPTIONS · UPDATE was four powers of three kinds.
             * Choosing what a client pays, raising the invoice at that price and
             * writing some of it off each change what a client owes, and each is
             * a different decision: the commercial one, the monthly run that
             * chooses no figure, and a correction somebody has to be able to
             * justify a year later. Resending an invoice changes nothing owed at
             * all. A clerk who chases a client for an unread invoice need not be
             * one who can credit it, or re-price the client.
             */
            'billing_subscriptions' => [
                'update' => [
                    'price_clients' => [
                        'Price clients',
                        'prices clients',
                        'Put a client on a tier, a unit count, a guard count and a contract term, when taking one on or re-pricing one. That sets what it is invoiced from its next full billing cycle. Needs Clients as well.',
                    ],
                    'raise_invoices' => [
                        'Raise invoices',
                        'raises invoices',
                        'Raise a client\'s invoice for the period its preview shows, at the prices in force for that period, and post it to the client\'s account. It chooses no price, and it is not sent until somebody resends it.',
                    ],
                    'resend_invoices' => [
                        'Resend invoices',
                        'resends invoices',
                        'Email a raised invoice, the first time or again, to the estate accounts that may open it under Settings → Billing, and nobody else. Nothing the client owes changes.',
                    ],
                    'issue_credit_notes' => [
                        'Issue credit notes',
                        'issues credit notes',
                        'Reduce what a client owes on a raised invoice with a credit note, a posted record of its own. Never for more than the invoice charged, less what is already credited. The invoice keeps its total.',
                    ],
                ],
            ],
            'platform_settings' => [
                'configure' => [
                    'set_prices' => [
                        'Set platform prices',
                        'sets prices',
                        'Change tier prices, the per-guard rate, what each package includes and a client\'s line items, from a date. Invoices already issued keep their prices.',
                    ],
                    'configure_messaging' => [
                        'Mail and SMS delivery',
                        'mail and SMS',
                        'Change the mail relay and the SMS provider the whole platform sends through, panic alerts included, and send a test from either.',
                    ],
                    'run_simulator' => [
                        'Run the event simulator',
                        'simulator',
                        'Put simulated alerts, gate events and shifts into a client\'s live dispatch queue. Only on a server where the simulator is switched on.',
                    ],
                ],
            ],
        ],
    ];

    /**
     * Every act of one module, in registry order, keyed by act.
     *
     * @return array<string, array{key: string, verb: string, label: string, short: string, description: string, permission: string}>
     */
    public static function of(Console|string $console, string $module): array
    {
        $console = self::consoleKey($console);
        $out = [];

        foreach (self::ACTS[$console][$module] ?? [] as $verb => $acts) {
            foreach ($acts as $act => [$label, $short, $description]) {
                $out[$act] = [
                    'key' => $act,
                    'verb' => $verb,
                    'label' => $label,
                    'short' => $short,
                    'description' => $description,
                    'permission' => "{$console}.{$module}.{$act}",
                ];
            }
        }

        return $out;
    }

    /**
     * The acts one verb of one module is split into, in order — or none, when
     * that verb is still one permission.
     *
     * @return list<string>
     */
    public static function under(Console|string $console, string $module, PermissionVerb|string $verb): array
    {
        $verb = $verb instanceof PermissionVerb ? $verb->value : $verb;

        return array_keys(self::ACTS[self::consoleKey($console)][$module][$verb] ?? []);
    }

    /**
     * One act, or null if the module has no act by that key.
     *
     * @return array{key: string, verb: string, label: string, short: string, description: string, permission: string}|null
     */
    public static function find(Console|string $console, string $module, string $act): ?array
    {
        return self::of($console, $module)[$act] ?? null;
    }

    /** `estate.dues_ledger.set_rates` */
    public static function permission(Console|string $console, string $module, string $act): string
    {
        $console = self::consoleKey($console);

        if (! isset(self::of($console, $module)[$act])) {
            throw new \LogicException("There is no named act [{$act}] on {$console}.{$module}.");
        }

        return "{$console}.{$module}.{$act}";
    }

    /**
     * Every verb that has been split, as the permission it used to be and the
     * permissions that replaced it.
     *
     * @return array<string, list<string>> keyed by the retired permission (Dues & ledger's approve, for one), each with the acts it became
     */
    public static function replacements(): array
    {
        $out = [];

        foreach (self::ACTS as $console => $modules) {
            foreach ($modules as $module => $verbs) {
                foreach ($verbs as $verb => $acts) {
                    $out["{$console}.{$module}.{$verb}"] = array_map(
                        static fn (string $act): string => "{$console}.{$module}.{$act}",
                        array_keys($acts),
                    );
                }
            }
        }

        return $out;
    }

    private static function consoleKey(Console|string $console): string
    {
        return $console instanceof Console ? $console->value : $console;
    }
}
