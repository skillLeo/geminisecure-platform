/**
 * The Guard workforce tab bar's items (M30 §1.3), in one place so it can be tested without a browser.
 *
 * Two views of the same module live side by side until the client chooses one:
 *   - the NEW view: the Schedule and Places;
 *   - the CLASSIC view: the Roster and Sites & geofences pages, exactly as they were.
 * Since M35 §1 the tabs are named as people know them — "Roster" and "Sites & geofences" — and open whichever view
 * `TABS_OPEN` says, on every page. The link at the end of the bar crosses to the other view: "Classic view" from the
 * new pages, "New view" from the two classic pages, and from the rest of the module the view the tabs do not open.
 */

/**
 * WHICH VIEW THE TABS OPEN (M35 §1). 'new': the Roster tab opens the Schedule, Sites & geofences opens Places.
 * TO GIVE THE CLIENT THE CLASSIC SCREENS BACK, CHANGE THIS ONE LINE TO 'classic'. Nothing else changes: both views
 * stay at their own addresses, on the same data, each with the link across to the other.
 */
export const TABS_OPEN = 'new'

/** The two pages that make up the classic view, and the two that make up the new one. */
export const CLASSIC_PAGES = ['roster', 'sites']
export const NEW_PAGES = ['schedule', 'places']

/** Where "Roster" opens — the tab, and every "on the Roster" link elsewhere in the console. */
export const ROSTER_HREF = TABS_OPEN === 'classic' ? '/guards/roster' : '/guards/schedule'

/** Where "Sites & geofences" opens. */
export const SITES_HREF = TABS_OPEN === 'classic' ? '/guards/sites' : '/guards/places'

/**
 * @param {string} active the key of the page the bar is drawn on
 * @returns {{ items: Array<{key: string, label: string, href: string, also?: string[]}>, switchLink: {label: string, href: string} }}
 */
export function workforceTabs(active) {
    const items = [
        { key: 'directory', label: 'Directory', href: '/guards' },
        // 26 Sep (C4): who is on duty now, when each shift ends, who is next and who is away.
        { key: 'on-duty', label: 'On duty', href: '/guards/on-duty' },
        // The current tab on either view's page: both are the roster.
        { key: TABS_OPEN === 'classic' ? 'roster' : 'schedule', also: ['roster', 'schedule'], label: 'Roster', href: ROSTER_HREF },
        { key: 'standing-orders', label: 'Standing orders', href: '/guards/standing-orders' },
        { key: 'gate-activity', label: 'Gate activity', href: '/guards/activity' },
        { key: 'compliance', label: 'Compliance', href: '/guards/compliance' },
        // Q-M28, D-M129: who may vouch for a guard whose phone cannot. A register nothing links to is not visible.
        { key: 'supervisors', label: 'Supervisors', href: '/guards/supervisors' },
        { key: 'incidents', label: 'Incidents', href: '/guards/incidents' },
        // M11 §6: where the posts and checkpoints are, and the geofences guards clock in within.
        { key: TABS_OPEN === 'classic' ? 'sites' : 'places', also: ['sites', 'places'], label: 'Sites & geofences', href: SITES_HREF },
    ]

    // Each view links to its counterpart (the roster and the schedule, the sites page and places), and every other page
    // to the view the tabs do not open.
    const toClassic = NEW_PAGES.includes(active) || (!CLASSIC_PAGES.includes(active) && TABS_OPEN !== 'classic')
    const switchLink = toClassic
        ? { label: 'Classic view', href: active === 'places' ? '/guards/sites' : '/guards/roster' }
        : { label: 'New view', href: active === 'sites' ? '/guards/places' : '/guards/schedule' }

    return { items, switchLink }
}

/** Is this item the page the bar is drawn on? */
export function isCurrent(item, active) {
    return item.key === active || (item.also ?? []).includes(active)
}
