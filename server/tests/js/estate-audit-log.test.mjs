/**
 * The estate's audit log page — `Pages/Estate/Settings/Audit.vue` (M84 §3.2).
 *
 *   Read only: who did what, in which role, to what, before and after, newest first; filtered by person, module and
 *   date range; exported with the same filters. No control on the page writes anything.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byTag, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Audit } = await import('../../resources/js/Pages/Estate/Settings/Audit.vue')

const ROW = {
    id: 9, when: 'Oct 9, 2026 3:15 PM JA', date: '2026-10-09', who: 'Everton Salmon', role: 'President',
    what: 'Residents edited', module: 'Residents', target: 'Resident 41', before: ['email: old@example.org'], after: ['email: new@example.org'],
}

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 84001, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/settings/audit')
    const view = await mount(Audit, {
        estate: { name: 'Phoenix Park' },
        sections: [{ key: 'profile', label: 'Estate profile', href: '/settings/profile', active: false, pending: false }, { key: 'audit', label: 'Audit log', href: '/settings/audit', active: true, pending: false }],
        rows: [ROW],
        table: { prefix: '', sort: null, dir: null, default: { sort: 'timestamp', dir: 'desc', per: 25 }, sortable: [], page: 1, last: 1, per: 25, sizes: [25, 50, 100], from: 1, to: 1, total: 1, q: null },
        filters: { actor: null, module: null, from: null, to: null },
        people: ['Dahlia Reid', 'Everton Salmon'],
        modules: [{ key: 'residents', label: 'Residents' }, { key: 'dues_ledger', label: 'Dues & ledger' }],
        ...props,
    })
    await flush(nextTick)
    return view
}

describe('the audit log page', () => {
    it('draws who, their role, what they did, to what, before and after', async () => {
        const view = await open()
        try {
            const text = view.text()
            for (const words of ['Everton Salmon', 'President', 'Residents edited', 'Resident 41', 'email: old@example.org', 'email: new@example.org']) {
                assert.ok(text.includes(words), `"${words}" is on the page`)
            }
        } finally {
            view.unmount()
        }
    })

    it('filters by person, module and dates, and exports with the same filters', async () => {
        // Opened on a filtered address (person and module chosen, as the page reads them back); the dates typed here.
        const view = await open({ filters: { actor: 'Everton Salmon', module: 'residents', from: null, to: null } })
        try {
            // The module list is the server's, by the console's own names, and sends the module's key.
            const options = view.all((el) => el.tag === 'option').map((el) => [el.attrs.value, textOf(el).trim()])
            assert.deepEqual(options.filter(([value]) => value === 'residents' || value === 'dues_ledger'), [['residents', 'Residents'], ['dues_ledger', 'Dues & ledger']])

            const [from, to] = view.all((el) => el.tag === 'input' && el.attrs.type === 'date')
            await view.type(from, '2026-10-01')
            await view.type(to, '2026-10-09')
            await nextTick()

            fire(view.one((el) => el.tag === 'form'), 'submit')
            await nextTick()
            const shown = visits.filter((v) => v.method === 'get').at(-1)
            assert.ok(shown, 'Show asks for the filtered log')
            assert.deepEqual(shown.data, { actor: 'Everton Salmon', module: 'residents', from: '2026-10-01', to: '2026-10-09' })

            const exported = view.one((el) => el.tag === 'a' && textOf(el).trim() === 'Export')
            assert.match(exported.attrs.href, /^\/settings\/audit\/export\?/)
            assert.match(exported.attrs.href, /actor=Everton\+Salmon/)
            assert.match(exported.attrs.href, /from=2026-10-01/)
        } finally {
            view.unmount()
        }
    })

    it('offers nothing that writes: no form posts, no delete, no edit', async () => {
        const view = await open()
        try {
            const words = view.all(byTag('button')).map((b) => textOf(b).trim().toLowerCase())
            for (const banned of ['delete', 'edit', 'remove', 'save']) {
                assert.ok(!words.some((w) => w.includes(banned)), `no "${banned}" button`)
            }
            fire(view.one((el) => el.tag === 'form'), 'submit')
            await nextTick()
            assert.equal(visits.filter((v) => ['post', 'put', 'patch', 'delete'].includes(v.method)).length, 0)
        } finally {
            view.unmount()
        }
    })

    it('says when nothing matches the filters, differently from an empty log', async () => {
        const filtered = await open({ rows: [], filters: { actor: 'Nobody', module: null, from: null, to: null }, table: { prefix: '', sort: null, dir: null, default: { sort: 'timestamp', dir: 'desc', per: 25 }, sortable: [], page: 1, last: 1, per: 25, sizes: [25], from: null, to: null, total: 0, q: null } })
        try {
            assert.ok(filtered.text().includes('Nothing matches these filters'), filtered.text().slice(0, 300))
        } finally {
            filtered.unmount()
        }
        const empty = await open({ rows: [], table: { prefix: '', sort: null, dir: null, default: { sort: 'timestamp', dir: 'desc', per: 25 }, sortable: [], page: 1, last: 1, per: 25, sizes: [25], from: null, to: null, total: 0, q: null } })
        try {
            assert.ok(empty.text().includes('Nothing on the log yet'), empty.text().slice(0, 300))
        } finally {
            empty.unmount()
        }
    })
})
