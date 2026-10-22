<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The features built on 10 October 2026, each behind its own switch (M84 §0.1)
|--------------------------------------------------------------------------
|
| OFF BY DEFAULT. A switch that is off means the feature is ABSENT: no link, no menu item, and its addresses answer
| 404. Never visible and refusing. On the server, one line turns one on or off, and re-caches the configuration:
|
|     sudo bash /opt/gs-deploy/set-env.sh FEATURE_ACCOUNT_DRILLDOWN false
|
*/

return [
    // M84 §3.1: an account in the chart of accounts opens to its entries, and an entry to its journal. Read only.
    'account_drilldown' => (bool) env('FEATURE_ACCOUNT_DRILLDOWN', false),

    // M84 §3.3: a month's budget per income or expense account (posts nothing), and the Budget vs Actual report.
    'budgets' => (bool) env('FEATURE_BUDGETS', false),

    // M84 §3.4: manual journal entry. Drafts save at any time; posting (Treasurer or Community Super Admin, a zero
    // difference, a confirm naming the total and the lines) goes through Ledger::post and is final.
    'manual_journals' => (bool) env('FEATURE_MANUAL_JOURNALS', false),
];
