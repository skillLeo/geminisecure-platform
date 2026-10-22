/**
 * Alerts as the pop-up and the bars hold them, and as the server sends them — one place, so
 * every test reads the same shapes the scripts do.
 */

/** 22:40 UTC on the day the bug hunt ran: every test's "raised at". */
export const RAISED = Date.parse('2026-09-24T22:40:00Z')

/** An alert as `PanicOverlay` is handed it: `panicFromPayload()` plus the console's standing. */
export const overlayAlert = (overrides = {}) => ({
    id: 7,
    kind: 'panic',
    kindLabel: 'Panic button',
    unit: 'Lot 47',
    who: 'Jane Brown',
    at: new Date(RAISED).toISOString(),
    urgent: true,
    simulated: false,
    drill: false,
    handsets: 2,
    via: 'on_duty',
    reachLate: false,
    controlRoom: true,
    call: null,
    estate: 'phoenixpark',
    estateName: 'Phoenix Park',
    status: 'open',
    statusFrom: 'server',
    statusAt: new Date(RAISED + 30_000).toISOString(),
    acknowledgedAt: null,
    dispatchedAt: null,
    enRouteAt: null,
    onSceneAt: null,
    resolvedAt: null,
    cancelled: false,
    href: '/dispatch/alerts/7',
    ...overrides,
})

/** A row as `PanicWatch::shape()` sends it. */
export const watchRow = (overrides = {}) => ({
    id: 11,
    estate: 'phoenixpark',
    estate_name: 'Phoenix Park',
    kind: 'panic',
    kind_label: 'Panic button',
    is_urgent: true,
    is_drill: false,
    server_time: new Date(RAISED - 60_000).toISOString(),
    unit: 'Lot 47',
    who: 'Jane Brown',
    alerted_handsets: 2,
    alerted_via: 'on_duty',
    call: null,
    status: 'open',
    acknowledged_at: null,
    dispatched_at: null,
    en_route_at: null,
    on_scene_at: null,
    resolved_at: null,
    cancelled: false,
    href: '/dispatch/alerts/11',
    ...overrides,
})

/** The props `DispatchController::alert()` draws an alert's own screen with, trimmed to one alert. */
export const alertScreenProps = (alert = {}, actions = {}) => ({
    alert: {
        headline: 'Panic button', is_drill: false, who: 'Jane Brown', subtitle: 'Jane Brown · Phoenix Park · Lot 47',
        status_label: 'Unacknowledged', fired: '2 minutes ago', fired_at: new Date(RAISED).toISOString(),
        responder: 'None on post', location_source: 'Unit on file', message_target: 'the responding guard', unit: 'Lot 47',
        household: 'In the estate database', medical_notes: 'Never held centrally', emergency_contact: 'In the estate database',
        household_contacts: null, heads_of_security_texts: null,
        ...alert,
    },
    timeline: [],
    actions: {
        acknowledge_url: '/dispatch/alerts/4/acknowledge', resolve_url: '/dispatch/alerts/4/resolve',
        dispatch_url: '/dispatch/alerts/4/dispatch', en_route_url: '/dispatch/alerts/4/en-route',
        on_scene_url: '/dispatch/alerts/4/on-scene', message_url: '/dispatch/alerts/4/message',
        can_acknowledge: true, acknowledge_label: 'Acknowledge', acknowledge_title: null,
        can_resolve: true, resolve_title: null, can_dispatch: false, dispatch_label: 'Dispatch', dispatch_candidates: [],
        can_en_route: false, en_route_label: 'Mark on the way', can_on_scene: false, on_scene_label: 'Mark on scene',
        can_message: false, message_title: null,
        ...actions,
    },
    onDuty: [],
    deliveries: null,
    estateIds: ['phoenixpark'],
    sourceBadge: { source: 'devices', simulated: false },
})

/** The shared props a Gemini console page is drawn with. */
export const geminiShared = (overrides = {}) => ({
    auth: { user: { id: 77, name: 'Dana Dispatcher', role_label: 'Dispatcher' } },
    nav: [],
    geminiPanicWatch: null,
    estateIds: ['phoenixpark'],
    flash: {},
    ...overrides,
})

/** Let every settled promise's callbacks run, then Vue's own queue. */
export async function flush(nextTick) {
    for (let i = 0; i < 8; i++) {
        await Promise.resolve()
    }

    await nextTick()
}
