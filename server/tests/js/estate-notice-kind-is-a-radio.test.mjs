/**
 * General and Urgent are one choice, said as one (GV-16, register DR-095).
 *
 *   `Pages/Estate/Governance/Notices.vue`: the two kinds were two plain buttons, so a screen reader heard two unrelated
 *   buttons and no choice; the meeting type's toggle beside them is a radio group. The kinds are a radio group too: the
 *   group named, each kind a radio, the chosen one checked.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Notices } = await import('../../resources/js/Pages/Estate/Governance/Notices.vue')

describe('the notice kind is a radio group (GV-16)', () => {
    it('names the group, makes each kind a radio, and checks the one chosen', async () => {
        resetInertia({ auth: { user: { id: 91110, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/notices')
        const view = await mount(Notices, {
            estate: { name: 'Phoenix Park' },
            rows: [],
            table: { q: '', total: 0, page: 1, per: 20, last_page: 1, sort: 'posted', dir: 'desc' },
            audiences: [{ key: 'estate', phase: null, label: 'Everybody on the estate', size: 1234 }],
            kinds: [{ key: 'general', label: 'General' }, { key: 'urgent', label: 'Urgent' }],
            canPost: true,
            electionYear: 2026,
            reasons: { post: '' },
        })
        await flush(nextTick)
        try {
            const group = view.one((el) => byClass('seg')(el))
            assert.equal(group.attrs.role, 'radiogroup')
            assert.equal(group.attrs['aria-label'], 'Kind of notice')

            const radios = findAll(group, (el) => el.attrs?.role === 'radio')
            assert.deepEqual(radios.map((radio) => [textOf(radio).trim(), String(radio.attrs['aria-checked'])]), [['General', 'true'], ['Urgent', 'false']])

            await view.click(radios[1])
            assert.deepEqual(findAll(group, (el) => el.attrs?.role === 'radio').map((radio) => String(radio.attrs['aria-checked'])), ['false', 'true'])
        } finally {
            view.unmount()
        }
    })
})
