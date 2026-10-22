/**
 * No single word sets the maintenance queue's width — `Pages/Estate/Facilities/Maintenance.vue` (DR-116, Sunday step 6).
 *
 *   One ticket on live was titled with a long run of letters and no space, and the Ticket column grew to hold it, so
 *   Status, Assigned and Age sat past the window's right edge at 1440, 1280 and both at 125%. The title is drawn in a
 *   span that may break anywhere, which also lets the column shrink to fit. The layout check on the release is the
 *   browser's proof; this holds the page to drawing it that way.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const PAGE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/Pages/Estate/Facilities/Maintenance.vue')
const { default: Maintenance } = await import('../../resources/js/Pages/Estate/Facilities/Maintenance.vue')

const LONG = `#1073 · QA-20261009 long title ${'x'.repeat(200)}`

const openQueue = async () => {
    resetInertia({ auth: { user: { id: 68401, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance')
    const view = await mount(Maintenance, {
        sourceBadge: { source: 'resident', simulated: false },
        estate: { name: 'Phoenix Park' },
        kpis: [{ key: 'open', value: 1, label: 'Open tickets' }],
        filters: [{ key: 'all', label: 'All' }],
        filter: 'all',
        overdue: 0,
        rows: [{ id: 73, number: 1073, ticket: LONG, title: LONG, location: 'Lot 12', priority: 'medium', priority_label: 'Medium', status: 'submitted', status_label: 'Submitted', is_overdue: false, assignee: 'Unassigned', age: '1 day', unit: 'Lot 12' }],
        table: { q: '', total: 1, page: 1, per: 20, last_page: 1, sort: 'number', dir: 'desc' },
        priorities: { high: 'High', medium: 'Medium', low: 'Low' },
        canUpdate: true,
        canCreate: true,
        canViewVendors: false,
        blockedReason: '',
        createReason: '',
        reasons: {},
        units: [],
        categories: ['Electrical'],
    })
    await flush(nextTick)
    return view
}

describe('the queue lets a ticket title break anywhere (DR-116)', () => {
    it('draws the title in the span that may break', async () => {
        const view = await openQueue()
        try {
            const span = view.one((el) => byClass('mq-ticket')(el))
            assert.equal(textOf(span).trim(), LONG)
        } finally {
            view.unmount()
        }
    })

    it('gives that span overflow-wrap: anywhere, the value that also lets the column shrink', () => {
        const css = fs.readFileSync(PAGE, 'utf8').split('<style')[1] || ''
        assert.match(css, /\.mq-ticket\s*\{[^}]*overflow-wrap:\s*anywhere/)
    })
})
