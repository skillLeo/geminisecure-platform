/**
 * Payroll, after the M97 pass (`Pages/Estate/Payroll/Run.vue`, `Exceptions.vue`, `Runs.vue`).
 *
 *   A pay run and its exceptions say the way back, "Back to pay runs" (M95 §3); Request changes opens the console's
 *   modal (M95 §1); an approver barred from this run sees Approve greyed with the way out beside it, and a role that
 *   never approves sees neither Approve nor Request changes (M96, M94 §1.1); the exceptions page warns a sole approver
 *   before Calculate and has no Calculate for a role that cannot; the run list says why the next month cannot start.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'

const { default: Run } = await import('../../resources/js/Pages/Estate/Payroll/Run.vue')
const { default: Exceptions } = await import('../../resources/js/Pages/Estate/Payroll/Exceptions.vue')
const { default: Runs } = await import('../../resources/js/Pages/Estate/Payroll/Runs.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const TABLE = { sort: null, dir: null, default: { sort: 'gross', dir: 'desc' }, sortable: [], page: 1, last: 1, per: 25, sizes: [25], from: 1, to: 1, total: 1, q: null }
const WAY_OUT = 'You calculated this run and nobody else can approve it. Send it back with Request changes so Norman Case can recalculate it, then you can approve it.'

const shell = (url) => resetInertia({ auth: { user: { id: 97005, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, url)

const openRun = async (props = {}) => {
    shell('/payroll/runs/sep-2026')

    return mount(Run, {
        estate: { name: 'Phoenix Park Village 1' },
        run: { id: 9, slug: 'sep-2026', period: 'September 2026', status: 'calculated', prepared_by: 'Norman Case', calculated_by: 'Dahlia Reid', approved_by: null, approved_at: null },
        summary: [],
        lines: [{ id: 1, name: 'Wayne Thomas', initials: 'WT', role: 'Groundsman', gross_minor: 9000000, nis_minor: 0, nht_minor: 0, education_tax_minor: 0, paye_minor: 0, net_minor: 9000000, paye_note: null }],
        table: TABLE,
        canApprove: false,
        approvalReason: 'You calculated this run, so someone else approves it.',
        holdsApproval: true,
        approvalNext: WAY_OUT,
        canRequestChanges: true,
        acknowledgement: { required: false, statement: '' },
        exportFormats: [],
        canExport: true,
        exportBlockedReason: '',
        ...props,
    })
}

const button = (view, words) => view.all(byTag('button')).find((el) => textOf(el).includes(words)) ?? null

afterEach(() => resetToasts())

describe('payroll, after the M97 pass', () => {
    it('says the way back from a pay run, and sends it back in the console\'s modal', async () => {
        const view = await openRun()

        try {
            const back = view.one(byClass('back-link'))
            assert.equal(textOf(back), 'Back to pay runs')
            assert.equal(back.attrs.href, '/payroll')

            await view.click(button(view, 'Request changes'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Send September 2026 back')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Say what is wrong first.')
        } finally {
            view.unmount()
        }
    })

    it('shows a barred approver Approve greyed with the way out beside it, on the screen', async () => {
        const view = await openRun()

        try {
            assert.ok(button(view, 'Approve & submit for payment').attrs.disabled)
            assert.equal(textOf(view.one(byClass('approve-why'))), WAY_OUT)
        } finally {
            view.unmount()
        }
    })

    it('gives a role that never approves no Approve and no Request changes, and says what happens next', async () => {
        const next = 'Dahlia Reid calculated this run and nobody else can approve it. Send it back with Request changes so Norman Case can recalculate it, then Dahlia Reid can approve it.'
        const view = await openRun({ holdsApproval: false, canRequestChanges: false, approvalNext: next, approvalReason: 'Approving a pay run needs the Approve pay runs permission on Payroll & HR.' })

        try {
            assert.equal(button(view, 'Approve & submit for payment'), null)
            assert.equal(button(view, 'Request changes'), null)
            assert.ok(view.text().includes(next))
        } finally {
            view.unmount()
        }
    })

    it('says the way back from the exceptions, warns before Calculate, and has no Calculate for a role that cannot', async () => {
        shell('/payroll/runs/sep-2026/exceptions')
        const props = {
            estate: { name: 'Phoenix Park Village 1' },
            run: { id: 9, slug: 'sep-2026', period: 'September 2026', status: 'calculated' },
            blocking: 0,
            rows: [{ id: 4, title: 'Wayne Thomas · overtime anomaly', detail: '22 hours', status: 'resolved', resolved: true, resolution_note: 'Hours accepted as logged.', actions: [] }],
            canCalculate: true,
            calculateReason: null,
            canResolve: true,
            reasons: { resolve: '' },
            canCalculateRuns: true,
            calculateWarning: 'If you calculate this run, you cannot approve it, and nobody else here can. Ask Norman Case to calculate it instead.',
        }
        const view = await mount(Exceptions, props)

        try {
            assert.equal(textOf(view.one(byClass('back-link'))), 'Back to pay runs')
            assert.equal(textOf(view.one(byClass('exc-warn'))), props.calculateWarning)
            assert.ok(view.text().includes('Resolved'), 'a settled exception shows its state, not buttons')
        } finally {
            view.unmount()
        }

        shell('/payroll/runs/sep-2026/exceptions')
        const president = await mount(Exceptions, { ...props, canCalculateRuns: false, calculateWarning: null })

        try {
            assert.equal(button(president, 'Continue to calculation'), null)
        } finally {
            president.unmount()
        }
    })

    it('says on the run list why the next month cannot start', async () => {
        shell('/payroll')
        const blocked = 'September 2026 is still open, so October 2026 cannot start yet. Waiting for Dahlia Reid to approve September 2026.'
        const view = await mount(Runs, {
            estate: { name: 'Phoenix Park Village 1' },
            kpis: [],
            rows: [{ id: 9, slug: 'sep-2026', period: 'September 2026', status: 'calculated', status_label: 'Pending approval', employees: 3, gross_minor: 0, net_minor: 0, action_label: 'Review & approve' }],
            table: TABLE,
            tabs: [],
            calendar: { slug: 'oct-2026', label: 'October 2026', period_start: '2026-10-01', period_end: '2026-10-31', pay_date: 'Oct 31, 2026', closes_on: 'Oct 31, 2026', rate_card: '2026-04', blocked_by: blocked },
            canCreate: true,
            reasons: { create: '' },
        })

        try {
            assert.equal(textOf(view.one(byClass('runs-why'))), blocked)
        } finally {
            view.unmount()
        }
    })
})
