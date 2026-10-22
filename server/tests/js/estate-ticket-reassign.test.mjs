/**
 * One vendor on a ticket, and no reassignment to the same one — `Pages/Estate/Facilities/Ticket.vue` (M68 §2.2, FAC-8).
 *
 *   Choosing the vendor that already has the job is not a reassignment: the panel says it changes that vendor's
 *   visit, fills in the visit as it stands, and Assign is off, with the reason, until the technician, the phone or the
 *   time is changed. Choosing another vendor is a reassignment, as before.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Ticket } = await import('../../resources/js/Pages/Estate/Facilities/Ticket.vue')

const TICKET = {
    id: 41, number: 1041, heading: 'Ticket #1041', summary_title: 'Gate light out Lot 47', title: 'Gate light out', location: 'Lot 47',
    category: 'Electrical', description: null, priority: 'medium', priority_label: 'Medium', status: 'assigned', status_label: 'Assigned',
    meta: 'Submitted by Marcia Bennett · Lot 47', access: null, reported_at: 'Oct 8, 9:00 AM JA', age: '2 days', is_overdue: false,
    sla_due: 'Oct 11, 9:00 AM JA', resolution: null, closed_at: null, is_closed: false,
}

// The vendor panel, with the visit as it stands (the fields the form is filled from).
const VENDOR = {
    id: 2, name: 'AquaTech Pool Services', initials: 'AP', detail: 'Tech: Owen Grant', technician: 'Owen Grant', phone: '876 555 0110',
    technician_phone: '876 555 0110', eta_starts_local: '2026-10-12T09:00', eta_ends_local: '2026-10-12T11:00',
}

const open = async () => {
    resetInertia({ auth: { user: { id: 68201, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance/1041')
    const view = await mount(Ticket, {
        sourceBadge: { source: 'resident', simulated: false },
        estateNow: '9:00 AM, Sat 10 Oct',
        estate: { name: 'Phoenix Park' },
        ticket: TICKET,
        timeline: [],
        vendor: VENDOR,
        history: [],
        vendors: [{ id: 1, name: 'Island Electric Services' }, { id: 2, name: 'AquaTech Pool Services' }],
        priorities: { high: 'High', medium: 'Medium', low: 'Low' },
        canUpdate: true,
        blockedReason: '',
        reasons: {},
        photos: [],
    })
    await flush(nextTick)

    await view.click(view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Reassign vendor'))

    const select = () => view.one((el) => el.tag === 'select' && el.attrs?.id === 'act-vendor')
    const field = (id) => view.one((el) => el.tag === 'input' && el.attrs?.id === id)
    // Assign is the console's modal's own button since the M97 pass (M95 §1).
    const submit = () => view.one((el) => el.tag === 'button' && byClass('cd-confirm')(el))
    const choose = async (id) => {
        for (const option of select().options) {
            option.selected = String(option.value) === String(id)
        }

        fire(select(), 'change')
        await nextTick()
    }

    return { view, select, field, submit, choose }
}

describe('the vendor that already has the job (FAC-8)', () => {
    it('fills in the visit as it stands, and Assign is off with the reason until something changes', async () => {
        const { view, field, submit, choose } = await open()
        await choose(2)

        assert.equal(field('act-technician').value, 'Owen Grant', 'the visit as it stands')
        assert.equal(field('act-eta-from').value, '2026-10-12T09:00')
        assert.ok(submit().attrs.disabled, 'nothing to save yet')
        assert.equal(submit().attrs.title, 'AquaTech Pool Services already has this job. Change the technician, the phone or the visit time, or choose another supplier.')
        assert.match(textOf(view.root), /Change AquaTech Pool Services's visit/)

        await view.type(field('act-technician'), 'Paul Reid')

        assert.ok(!submit().attrs.disabled, 'a changed technician can be saved')
        assert.equal(textOf(submit()).trim(), 'Save the visit')
    })

    it('another vendor is a reassignment, and Assign is on', async () => {
        const { submit, choose, field } = await open()
        await choose(1)

        assert.ok(!submit().attrs.disabled)
        assert.equal(textOf(submit()).trim(), 'Assign')
        assert.equal(field('act-technician').value, '', "the other vendor's technician is not carried over")
    })

    it('pressing Assign on an unchanged visit posts nothing', async () => {
        const { view, submit, choose } = await open()
        await choose(2)

        await view.click(submit())
        await nextTick()

        assert.equal(visits.filter((v) => v.method === 'post').length, 0)
    })
})
