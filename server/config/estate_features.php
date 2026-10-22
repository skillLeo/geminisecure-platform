<?php

/*
|--------------------------------------------------------------------------
| The estate console's new features, each behind its own switch (M84 §0.1)
|--------------------------------------------------------------------------
|
| Off by default, and off means ABSENT: no link anywhere, and the feature's addresses answer 404. Turned on only after
| the feature passes its own check. On the server one line turns one off, with no deploy:
|
|   sudo bash /opt/gs-deploy/set-env.sh FEATURE_AUDIT_LOG false
|   sudo bash /opt/gs-deploy/set-env.sh FEATURE_REMINDER_DELIVERY false
|
| The QA session's switches. The m56 session keeps its own in config/features.php, so the two never edit one file.
|
*/

return [

    // The estate's audit log page, /settings/audit (M84 §3.2): read only, this estate's entries only.
    'audit_log' => (bool) env('FEATURE_AUDIT_LOG', false),

    // Reminder delivery's screen on Dunning & reminders (M84 §3.5): what would go out, to whom, and each reminder's
    // state. Showing the screen sends nothing: whether anything is ever sent is `reminders.delivery_enabled`.
    'reminder_delivery' => (bool) env('FEATURE_REMINDER_DELIVERY', false),

    // Closing a module the estate's plan leaves out (Hassam's closing order, 12 Oct; `App\Services\Estate\PlanGate`):
    // on, Accounting or Payroll & HR left out of an estate's plan is absent from its sidebar and its addresses say "not
    // included in your plan". OFF UNTIL EVERY LIVE ESTATE'S PLAN IS CHECKED: estates are provisioned on Standard, and
    // Standard leaves Payroll & HR out, so turning it on would close Payroll & HR on every estate still on Standard.
    'plan_gate' => (bool) env('FEATURE_PLAN_GATE', false),

];
