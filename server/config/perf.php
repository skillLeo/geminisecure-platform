<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Performance guard rails (M11 A2)
|--------------------------------------------------------------------------
|
| A REQUEST THAT RUNS MORE QUERIES THAN ITS BUDGET FAILS, LOUDLY, IN LOCAL AND
| IN TESTING. The classic defect this exists for is a list of fifty lots that
| fires fifty-one queries: invisible on a five-lot fixture, fatal on an estate
| of two thousand. Nothing about such a page looks wrong on a developer's
| screen, so the page is made to say so — `App\Http\Middleware\EnforceQueryBudget`
| counts every statement the request runs, on every connection (the platform,
| the estate, the schema owner), and a request over its budget becomes an
| exception naming the route, the count, the budget and the statements it
| repeated most.
|
| OFF IN PRODUCTION, AND NOT ONLY BY THIS FILE. The middleware refuses to count
| outside `local` and `testing` whatever `enabled` says, because a guard that
| turned a slow page into an error page in front of a committee would be a
| worse defect than the slow page.
|
| THE BUDGET IS PER REQUEST, and it is a ceiling rather than a target. A page
| whose count grows with the estate has no budget that is right for it: the fix
| is to eager-load, aggregate in SQL or paginate, and to prove the page against
| an estate of Phoenix Park's size as production was seeded — 2,000 lots, 441
| households, 12,015 charges — with `assertQueryBudget()` (tests/Pest.php).
| (M26: 441 is the demonstration's households; the real community has 2,000 homes.
| A budget is a count per request, so it holds at either size.)
|
*/

return [

    'query_budget' => [

        /*
         * On for local and testing, off everywhere else. `QUERY_BUDGET_ENABLED`
         * can switch it off locally; it cannot switch it on in production.
         */
        'enabled' => (bool) env('QUERY_BUDGET_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

        /*
         * `throw` makes an over-budget request an error (the default: loud).
         * `log` writes a warning instead, for somebody who needs to click through
         * a page they know is over while they fix it.
         */
        'mode' => env('QUERY_BUDGET_MODE', 'throw'),

        /*
         * Every request's ceiling unless a route below says otherwise.
         *
         * CHOSEN FROM A MEASUREMENT, NOT A GUESS. The whole suite was run with the
         * guard in `log` mode and a report file (24 Sep 2026: 1,057 tests making
         * 2,253 requests to 343 named routes and 7 unnamed paths). All but seven
         * ran 57 queries or fewer at their worst; those seven, and two close to
         * the line whose count follows the data, are listed below. Sixty is room for what every Inertia
         * page shares — the signed-in user, their roles and permissions, the
         * estate, its features — and a page's own handful of reads, and it is
         * nowhere near what a page that walks 2,000 lots one by one runs.
         */
        'default' => (int) env('QUERY_BUDGET_DEFAULT', 60),

        /*
         * Per-route ceilings, by route name. A key may be a pattern (`gemini.*`),
         * matched with `Str::is` after the exact names, in the order written.
         *
         * `null` exempts a route. Only ever with a comment saying why: an
         * exemption is a place the guard has been told not to look.
         *
         * Each ceiling below is the suite's measured maximum for that route with
         * room above it, and says whose it is to bring down. None is a target.
         *
         * @var array<string, int|null>
         */
        'routes' => [
            // THE ESTATE CONSOLE HAS NONE. The five it had while M11 A2 was under way —
            // posting and reversing a charge run (478 and 937 on the facilities fixture,
            // one journal line per statement), a bulk charge (103), the roles screen and
            // its copy (234, 92) — now run within the default whatever the estate's size;
            // `EstateQueryBudgetsAtScaleTest` and `EstateChargeRunAtScaleTest` hold them
            // there at 2,000 lots.

            // THE GEMINI CONSOLE'S AND THE API'S — M11 unit m11-n1-gemini. Measured
            // 155, 74, 54 and 57. The panic route's count follows how many officers
            // and contacts it reaches, so it is given room rather than a tight line.
            // 9 Oct 2026 (M57): 325 on the test platform, whose estates now hold more officers (one device lookup and
            // one live event per officer reached, 36 of each); the room is kept.
            'gemini.simulator.ambient' => 360,
            'gemini.platform_settings.roles' => 150,
            'gemini.platform_settings.roles.copy' => 100,
            'api.v1.duress.store' => 100,

            // M30 §5.1, §4.6: the Schedule's two writes of many shifts at once, a repeat (at most 62) and a week's
            // copy. Each shift goes through `Roster::postShift`, which checks it on its own (the gate, the guard's
            // other shifts, his leave) and audits it: about seven statements a shift. Measured 442 for a two-month
            // repeat and 120 for a seventeen-shift copy. The reads that decide them (`/guards/schedule/check`,
            // `/guards/schedule/copy`) stay in the default whatever the count. Bringing these down is a batched
            // Roster write (UX_V2.md, Next), not a higher number.
            'gemini.guard_workforce.schedule.store' => 480,
            'gemini.guard_workforce.schedule.copy' => 480,

            // M47 §10: "Confirm & pay" is one act made of six, each through the service that owns it — the booking,
            // the estate's yes, the fee charged and paid with its receipt, the deposit held, the guests' pass — with
            // their own ledger reads and live events (twelve of those). Measured 107; it reads one household's ledger,
            // so it does not grow with the estate. Bringing it down is batching one act's live events, not a higher number.
            'api.v1.bookings.book' => 150,
        ],

        /*
         * Where to append one JSON line per request — route, status, query count,
         * time in the database and time in total. Unset, nothing is written. Used
         * to measure a whole suite run; never set in production.
         */
        'report' => env('QUERY_BUDGET_REPORT'),
    ],

];
