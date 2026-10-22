<?php

/*
|--------------------------------------------------------------------------
| Arrears reminders: whether any is ever sent (M84 §3.5)
|--------------------------------------------------------------------------
|
| SHIPS FALSE. While false the delivery pipeline only previews: the screen shows exactly what would go out and to
| whom, and nothing leaves the building. It is not turned on until EST-B-01 (the amount owed counting charges not yet
| due) is fixed and Hassam says so: a wrong demand sent to a household is the worst thing this product could do.
|
| TURNING IT ON NEVER FLUSHES THE QUEUE. Delivery refuses to run without `deliver_from`, the moment it starts, and
| marks every reminder queued before that moment expired, never sent. The queue that has stood since 29 September is
| never sent in one burst. A reminder older than a day when its turn comes is expired too: its amount may be stale.
|
|   On:   sudo bash /opt/gs-deploy/set-env.sh REMINDERS_DELIVER_FROM <the moment, e.g. 2026-10-14T14:00:00Z>
|         sudo bash /opt/gs-deploy/set-env.sh REMINDERS_DELIVERY_ENABLED true
|   Off:  sudo bash /opt/gs-deploy/set-env.sh REMINDERS_DELIVERY_ENABLED false
|
*/

return [

    'delivery_enabled' => (bool) env('REMINDERS_DELIVERY_ENABLED', false),

    'deliver_from' => env('REMINDERS_DELIVER_FROM'),

];
