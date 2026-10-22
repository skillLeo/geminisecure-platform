/**
 * Approving a pay run asks first — `Pages/Gemini/Payroll/Show.vue` (DR-073, Part 7's rule).
 *
 *   "Approve & disburse" approved the run and pushed "Payslip ready" to every guard on it at the first press. A control
 *   that starts contact confirms first, naming how many are told, counted from the server: the question names the run
 *   and its net (`run.net`), how many guards are told (`run.told_count`, the push's own count, PushAnnouncer::
 *   payslipAudience), and only the dialog's own button approves, with the reconciliation tick as it was given.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush, geminiShared } from './support/alerts.mjs'

const { default: PayRun } = await import('../../resources/js/Pages/Gemini/Payroll/Show.vue')

const mounted = []

afterEach(() => {
    mounted.splice(0).forEach((view) => view.unmount())
    visits.length = 0
})

const RUN = { id: 41, reference: 'PR-2026-09', period: 'September 2026', status: 'calculated', blocked_reason: null, net: '$1,234,567.00', told_count: 3 }

const open = async (over = {}) => {
    resetInertia(geminiShared({ auth: { user: { id: 78, name: 'Dora Director', role_label: 'Director' } }, errors: {}, flash: {} }), '/payroll/41')
    const view = await mount(PayRun, {
        run: RUN,
        payslips: [{ id: 1, guard_id: 7, initials: 'KB', employee: 'Kemar Blake', estate: 'Phoenix Park Village 1', gross: '$90,000.00', nis: '$2,700.00', nht: '$1,800.00', education_tax: '$1,887.50', paye: '$0.00', paye_note: null, net: '$83,612.50', exception: false, exception_reason: null }],
        filters: { q: '' },
        approval: { reason: null, acknowledgement: { required: false, statement: '' } },
        canOpenGuards: false,
        table: { q: '', total: 1, page: 1, per: 25, last: 1, from: 1, to: 1, sort: null, dir: null, default: { sort: 'employee', dir: 'asc' }, sortable: [] },
        ...over,
    })
    mounted.push(view)
    await flush(nextTick)

    const approveButton = () => view.one((el) => el.tag === 'button' && /Approve & disburse/.test(textOf(el)) && !byClass('cd-confirm')(el))
    const asking = () => view.all((el) => el.attrs?.role === 'dialog').find((d) => /^Approve the /.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d).trim()))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, approveButton, asking, inAsk, posts }
}

describe('approving a pay run asks first (DR-073)', () => {
    it('names the run, its net and how many guards are told, and approves nothing on the first press', async () => {
        const { view, approveButton, asking, inAsk, posts } = await open()
        await view.click(approveButton())

        assert.equal(posts().length, 0, 'nothing is approved on the first press')
        assert.ok(asking(), 'a question opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Approve the September 2026 pay run, $1,234,567.00 net?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /3 guards are told in the Guard App that a payslip is ready\./)
        assert.match(body, /The transfer itself is made from Gemini's bank; this records the decision\./)
        assert.match(body, /Nobody else is told\./)

        await view.click(inAsk('cd-confirm'))
        assert.equal(posts().length, 1)
        assert.equal(posts()[0].url, '/payroll/41/approve')
        assert.deepEqual(posts()[0].data, { reconciled: false })
    })

    it('one guard is one guard; Cancel approves nothing; the reconciliation tick travels with the dialog\'s button', async () => {
        const { view, approveButton, asking, inAsk, posts } = await open({
            run: { ...RUN, told_count: 1 },
            approval: { reason: null, acknowledgement: { required: true, statement: 'I confirm this run was reconciled against the current TAJ tables.' } },
        })
        const tick = view.one((el) => el.tag === 'input' && el.attrs?.type === 'checkbox')
        tick.checked = true
        tick.value = 'on'
        const { fire } = await import('./support/render.mjs')
        fire(tick, 'change')
        await nextTick()

        await view.click(approveButton())
        assert.match(textOf(inAsk('cd-body')), /1 guard is told in the Guard App that a payslip is ready\./)
        await view.click(inAsk('cd-cancel'))
        assert.equal(asking(), undefined)
        assert.equal(posts().length, 0)

        await view.click(approveButton())
        await view.click(inAsk('cd-confirm'))
        assert.equal(posts().length, 1)
        assert.deepEqual(posts()[0].data, { reconciled: true })
    })
})
