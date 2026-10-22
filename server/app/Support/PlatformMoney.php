<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Who on the Gemini Console may read MONEY — M10 §5, decided in one place.
 *
 * THE CLIENT'S WORDS: "The Admin Assistant must not see anything about what anyone pays or what
 * the company earns", in the interface AND refused by the API. The matrix already said half of it.
 * The Admin Assistant holds no cell on Billing & subscriptions or Payroll & Accounting, so /billing
 * and /payroll have always answered 403. The leak was everywhere else — on the four modules they DO
 * hold: the Platform MRR card and the tier panel on a Dashboard every platform role holds in full,
 * the invoice-paid rows in its activity feed, the Tier and MRR columns and sort keys on the Clients
 * directory, the tier pill, the MRR figure, the "Overdue" badge and the invoice list on a client
 * record, the plan name on every dispatch map site card, and the gross on a guard's latest payslip
 * on a workforce record. Each of those is a figure owned by a module the reader does not hold,
 * shown on a screen of a module they do.
 *
 * A PERMISSION, NEVER A ROLE NAME. Every one of those screens asks the same question — does this
 * viewer hold the module that OWNS this figure — and it is asked of the permission rather than of
 * `Role::ADMIN_ASSISTANT`, so that a role the Director builds under §4 inherits the answer from the
 * cells it is given instead of from a list somebody has to remember to extend. A role named
 * "Front Desk" with Clients and no Billing gets exactly what the Admin Assistant gets, and nobody
 * edits this file to make that true.
 *
 * TWO QUESTIONS, BECAUSE THE PLATFORM KEEPS TWO LEDGERS:
 *
 *   revenue  what a client is charged, what it is on and whether it has paid — Billing & subscriptions
 *   pay      what an officer is paid — Payroll & Accounting
 *
 * Against the seeded matrix (`RbacMatrixSeeder::GEMINI_GRID`), with no cell changed:
 *
 *   Director            billing F · payroll A  → both
 *   Operations Manager  billing V · payroll V  → both
 *   Head of Security    billing - · payroll -  → neither
 *   Dispatcher          billing - · payroll -  → neither
 *   Admin Assistant     billing - · payroll -  → neither   (M10 §5)
 *   Accountant          billing F · payroll F  → both
 *
 * The Head of Security and the Dispatcher lose the same figures the Admin Assistant does. That is
 * the rule applied rather than a side effect of it: neither holds the module that owns them either,
 * and a figure a role may not open the module for is not one a dashboard should hand it instead.
 *
 * ASKED BY THE CONTROLLER, HANDED TO THE SERVICE. The division `Residents::standingOf()` keeps on
 * the estate side: a service is given the answer as a boolean and builds a payload with the figure
 * OMITTED — never computed and blanked, because a figure that is never read cannot be leaked by a
 * later edit to the array around it. It also means a service never consults the container about
 * who is looking, and a test can call it with either answer.
 *
 * THE TWO REASONS BELOW NAME NO FIGURE, and none of the words a payload scan looks for. They ship
 * to the browser beside what they explain, so a reason that quoted what it was withholding would be
 * the leak describing itself.
 */
final class PlatformMoney
{
    /** The module that owns what clients are charged and what the platform takes in. */
    public const REVENUE_PERMISSION = 'gemini.billing_subscriptions.view';

    /** The module that owns what an officer is paid. */
    public const PAY_PERMISSION = 'gemini.payroll_accounting.view';

    public const REVENUE_WITHHELD = 'What a client is charged, what it is on and whether it has paid belong to Billing & subscriptions, which your role does not hold. Everything else on this screen is yours to read.';

    public const PAY_WITHHELD = 'What an officer is paid belongs to Payroll & Accounting, which your role does not hold. Everything else on this record is yours to read.';

    /**
     * May this viewer read what clients pay and what the platform earns?
     *
     * Null is no. A screen rendered with nobody signed in has already been refused by `auth`; this
     * is the second answer, and the safe one.
     */
    public static function maySeeRevenue(?User $viewer): bool
    {
        return $viewer !== null && $viewer->can(self::REVENUE_PERMISSION);
    }

    /** May this viewer read what an officer is paid? */
    public static function maySeePay(?User $viewer): bool
    {
        return $viewer !== null && $viewer->can(self::PAY_PERMISSION);
    }
}
