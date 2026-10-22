/**
 * The notice's attachment says what was chosen, refuses what will be refused, and can be taken off (GV-7, DR-092).
 *
 *   `Pages/Estate/Governance/Notices.vue`: the input took a .exe and an 11 MB file (the server refused both later), named
 *   nothing and offered no way to take a file off. A file of a kind the server will not keep, or over its 10 MB, is now
 *   refused under the field at once and not kept; a file that will do is named with its size, beside Remove.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Notices } = await import('../../resources/js/Pages/Estate/Governance/Notices.vue')

const open = async () => {
    resetInertia({ auth: { user: { id: 91113, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/notices')
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

    const input = () => view.one((el) => el.attrs?.id === 'notice-attachment')
    const field = () => input().parent.parent
    const choose = async (file) => {
        input().files = [file]
        fire(input(), 'change')
        await nextTick()
    }

    return { view, input, field, choose }
}

describe('the notice attachment says what (GV-7)', () => {
    it('refuses a kind the server will not keep, under the field, and keeps nothing', async () => {
        const { view, field, choose } = await open()
        try {
            await choose({ name: 'setup.exe', size: 20480, type: 'application/x-msdownload' })

            assert.match(textOf(field()), /That file is not one a notice can carry: a PDF, a Word document, or a JPEG or PNG picture\./)
            assert.doesNotMatch(textOf(field()), /setup\.exe/, 'nothing is kept')
        } finally {
            view.unmount()
        }
    })

    it('refuses a file over 10 MB, saying how large it is', async () => {
        const { view, field, choose } = await open()
        try {
            await choose({ name: 'site-plan.pdf', size: 11 * 1024 * 1024, type: 'application/pdf' })

            assert.match(textOf(field()), /That file is 11\.0 MB\. A notice carries one of up to 10 MB\./)
        } finally {
            view.unmount()
        }
    })

    it('names a file that will do, with its size, and Remove takes it off', async () => {
        const { view, field, choose } = await open()
        try {
            await choose({ name: 'water-schedule.pdf', size: 245760, type: 'application/pdf' })

            assert.match(textOf(field()), /water-schedule\.pdf · 240 KB/)
            const remove = view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Remove')
            assert.ok(remove, 'Remove is offered')

            await view.click(remove)

            assert.doesNotMatch(textOf(field()), /water-schedule\.pdf/, 'the file is off')
        } finally {
            view.unmount()
        }
    })
})
