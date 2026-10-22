/**
 * WHERE AN ALERT STANDS, READ THE SAME WAY BY EVERY SCREEN THAT SHOWS ONE.
 *
 * The console's bar, the estate's bar and the pop-up card each have to answer one question —
 * does the control room still owe this alert an answer? — and three copies of the answer would
 * drift. This is the browser's side of `PanicWatch::awaitingControlRoom()`, so the server's list
 * of what is outstanding and the screen's reading of a row cannot disagree.
 */

/** The statuses that close an alert — `DispatchController::SETTLED`. */
export const SETTLED = ['resolved', 'false_alarm']

/** Closed: resolved, or resolved as a false alarm (a raiser's own cancel among them). */
export const isSettled = (alert) => SETTLED.includes(alert?.status)

/**
 * WHETHER THE CONTROL ROOM STILL OWES THIS ALERT AN ANSWER.
 *
 * NOT `status === 'open'` (B090). An officer the panic reached may tap "On my way" before anybody
 * in the control room has looked, and `AlertProgress` then makes the alert `responding` with no
 * acknowledgement. Counting only `open` stopped the Director's bar the moment that happened, and
 * the card read "Acknowledged; an officer is on the way" about an alert nobody in the control room
 * had acknowledged. An alert is outstanding until the server reports an acknowledgement, or closes it.
 *
 * @param {{ status: string|null, acknowledgedAt: string|null }} alert
 */
export const awaitingControlRoom = (alert) => !isSettled(alert) && !alert.acknowledgedAt
