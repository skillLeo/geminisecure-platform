/**
 * Approving a unit claim asks first — `Pages/Estate/Residents/Claims.vue` (DR-072, Part 7's rule).
 *
 *   "Approve claim" bound the claimant to the household and told them so, in the app and by email where they asked
 *   for it, at the first press. A control that starts contact confirms first, naming who is told and by what channel:
 *   the question names the claimant, the lot and the access the approval gives, and only the dialog's own button
 *   approves, with the access chosen on the card.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Claims } = await import('../../resources/js/Pages/Estate/Residents/Claims.vue')

const CLAIM = {
    id: 31,
    title: 'Tanya Simms claims Phase 2 · Lot 14',
    status: 'pending',
    status_label: 'Pending',
    made: 'Oct 9',
    claimant: 'Tanya Simms',
    lot: 'Phase 2 · Lot 14',
    type_label: 'A household member',
    match_label: 'Matched',
    notes: { access: { open: true, locked: false, level: 'member', label: 'Member' } },
    submitted: { label: 'Submitted', mismatch: false, rows: [{ label: 'Name', value: 'Tanya Simms' }] },
    record: { label: 'On record', mismatch: false, rows: [{ label: 'Name', value: 'Tanya Simms' }] },
    record_sample: false,
    actions: [{ key: 'reject', style: 'outline' }, { key: 'approve', style: 'primary' }],
}

const open = async () => {
    resetInertia({ auth: { user: { id: 69101, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/residents/claims')
    const view = await mount(Claims, {
        sourceBadge: { source: 'resident' },
        estate: { name: 'Phoenix Park' },
        title: 'Unit claims',
        pending_count: 1,
        claims: [CLAIM],
        table: { q: '', total: 1, page: 1, per: 25, last: 1, from: 1, to: 1, sort: null, dir: null, default: { sort: 'made', dir: 'desc' }, sortable: ['made', 'claimant', 'lot', 'type', 'match'] },
        canApprove: true,
        canReview: true,
        blockedReason: '',
    })
    await flush(nextTick)

    const approveButton = () => view.one((el) => el.tag === 'button' && byClass('primary')(el) && /Approve claim/.test(textOf(el)))
    const asking = () => view.all((el) => el.attrs?.role === 'dialog').find((d) => /^Approve /.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d).trim()))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, approveButton, asking, inAsk, posts }
}

describe('approving a unit claim asks first (DR-072)', () => {
    it('names the claimant, the lot, the access and both channels, and approves nothing on the first press', async () => {
        const { view, approveButton, asking, inAsk, posts } = await open()
        assert.ok(approveButton(), 'the Approve button is drawn')
        await view.click(approveButton())

        assert.equal(posts().length, 0, 'nothing is approved on the first press')
        assert.ok(asking(), 'a question opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Approve Tanya Simms for Phase 2 · Lot 14?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /Tanya Simms is told in the app that their account is approved, and by email if they asked for it\. Nobody else is told\./)
        assert.match(body, /Access on approval: Member\./)
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Approve claim')
    })

    it('Cancel approves nothing; the dialog\'s own button approves once, with the access chosen on the card', async () => {
        const first = await open()
        await first.view.click(first.approveButton())
        await first.view.click(first.inAsk('cd-cancel'))
        assert.equal(first.asking(), undefined)
        assert.equal(first.posts().length, 0)

        const second = await open()
        const level = second.view.one((el) => el.tag === 'select' && el.attrs?.id === 'claim-level-31')
        level.value = 'helper'
        fire(level, 'change')
        await nextTick()
        await second.view.click(second.approveButton())
        assert.match(textOf(second.inAsk('cd-body')), /Access on approval: Helper\./)
        await second.view.click(second.inAsk('cd-confirm'))

        assert.equal(second.posts().length, 1)
        assert.match(second.posts()[0].url, /\/residents\/claims\/31\/approve$/)
        assert.deepEqual(second.posts()[0].data, { access_level: 'helper' })
    })
})
