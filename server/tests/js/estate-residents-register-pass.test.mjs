/**
 * The residents register, after the M98 pass (`Pages/Estate/Residents/Index.vue`).
 *
 *   A role that can never add a resident has no Add resident, and one that can never decide a claim has no Review now
 *   (M96 level 3; both were greyed buttons saying why only on hover); Missing contact details and Deleted accounts wear
 *   the board's outline button, where they wore a class no stylesheet defines; every state reads in the vocabulary's
 *   words (M96 §2); Resend is absent without create, greyed with its reason on the screen while it waits, and a refused
 *   Resend is said inside its dialog, which stays open (Hassam, 12 Oct), where it was drawn nowhere.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const { default: Index } = await import('../../resources/js/Pages/Estate/Residents/Index.vue')
const { resetToasts, toasts } = await import('../../resources/js/lib/toasts.js')

const TABLE = { q: '', total: 2, page: 1, per: 25, last: 1, from: 1, to: 2, sort: null, dir: null, default: { sort: 'lot', dir: 'asc' }, sortable: [] }

const PERSON = (over = {}) => ({
    id: 1, name: 'Marcia Bennett', initials: 'MB', email: 'marcia@example.com', unit: 'Phase 1 · Lot 12', unit_slug: 'lot-12', household_param: null,
    household: 'Bennett', role: 'primary', role_label: 'Primary', state: 'active', state_label: 'Active', added: 'Oct 1, 2026', sample: false, ...over,
})

const HOUSEHOLD = (over = {}) => ({
    id: 12, initials: 'MB', name: 'Marcia Bennett', sample: false, email: 'marcia@example.com', standing: { tone: null, label: 'Balance not shown' },
    verification: 'pending', verification_label: 'Pending review', last_active: null, unit_slug: 'lot-12', household_param: null, unit: 'Phase 1 · Lot 12', ...over,
})

const open = async (props = {}) => {
    const households = props.view === 'households'

    resetInertia({ auth: { user: { id: 98401, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, households ? '/residents?view=households' : '/residents')

    return mount(Index, {
        estate: { name: 'Phoenix Park Village 1' },
        view: 'people',
        pending: { count: 2, title: '2 unit claims are waiting for a decision', body: 'Each binds a person to a household.' },
        phase_chips: [],
        filters: { phase: '', search: '' },
        rows: [PERSON()],
        total: 1,
        shown: 1,
        table: TABLE,
        money_visible: false,
        money_reason: null,
        canCreate: true,
        canReview: true,
        blockedReason: 'Adding a resident puts a person on the estate\'s register and needs Residents create access. You are able to read this screen.',
        inviteStates: {},
        deletedAccounts: 3,
        ...props,
    })
}

const control = (view, words) => view.all((el) => ['button', 'a'].includes(el.tag) && textOf(el).includes(words))[0] ?? null

afterEach(() => resetToasts())

describe('The residents register, after the M98 pass', () => {
    it('has no Add resident and no Review now for a role that can do neither, and the banner still says what waits', async () => {
        const view = await open({ canCreate: false, canReview: false })

        try {
            // Compared as booleans: a failing assertion would otherwise print the whole rendered tree.
            assert.ok(control(view, 'Add resident') === null, 'no greyed Add resident')
            assert.ok(control(view, 'Review now') === null, 'no greyed Review now')
            assert.ok(view.text().includes('2 unit claims are waiting for a decision'), 'the banner still says what waits')
        } finally {
            view.unmount()
        }
    })

    it('draws Missing contact details and Deleted accounts as the board\'s outline buttons', async () => {
        const view = await open()

        try {
            for (const words of ['Missing contact details', 'Deleted accounts']) {
                const link = control(view, words)
                assert.ok(link?.className.split(' ').includes('btn-outline-sm'), `${words} wears btn-outline-sm`)
            }

            assert.equal(view.all(byClass('btn-secondary-sm')).length, 0, 'no class that no stylesheet defines')
            assert.equal(textOf(view.one(byClass('deleted-badge'))), '3')
        } finally {
            view.unmount()
        }
    })

    it('reads every state in the vocabulary\'s words, on both tabs', async () => {
        const people = await open({
            rows: [PERSON(), PERSON({ id: 2, name: 'Owen Clarke', state: 'invited', state_label: 'Invited' }), PERSON({ id: 3, name: 'Ruth Hall', state: 'pending', state_label: 'Pending' })],
        })

        try {
            const words = people.all(byClass('status-badge')).map(textOf)
            assert.deepEqual(words, ['Active', 'Pending', 'Pending'])
            assert.ok(!people.text().includes('Invited'), 'no word outside the vocabulary')
        } finally {
            people.unmount()
        }

        const households = await open({
            view: 'households',
            rows: [HOUSEHOLD(), HOUSEHOLD({ id: 13, name: 'Owen Clarke', verification: 'verified', verification_label: 'Verified', sample: true })],
        })

        try {
            const words = households.all((el) => byClass('status-badge')(el) && !byClass('draft')(el)).map(textOf)
            assert.deepEqual(words, ['Pending', 'Active'])
            assert.ok(!households.text().includes('Pending review'))
            assert.ok(!households.text().includes('Verified'))
            assert.equal(households.one(byClass('draft')).attrs.title, 'Sample data.', 'the sample tooltip in one line, no machinery')
        } finally {
            households.unmount()
        }
    })

    it('Resend: absent without create, greyed with its reason on the screen while it waits, a refusal said in its open dialog', async () => {
        const invite = (over = {}) => ({ 12: { resident_id: 9, status: 'expired', line: 'The invite lapsed without being used.', can_resend: true, resend_after: null, ...over } })

        const noCreate = await open({ view: 'households', rows: [HOUSEHOLD()], inviteStates: invite(), canCreate: false })

        try {
            assert.ok(noCreate.one(byClass('reg-resend')) === null, 'no Resend for a role that can never send one')
        } finally {
            noCreate.unmount()
        }

        const waiting = await open({ view: 'households', rows: [HOUSEHOLD()], inviteStates: invite({ resend_after: new Date(Date.now() + 120000).toISOString() }) })

        try {
            assert.equal(waiting.one(byClass('reg-resend'))?.attrs.disabled, true)
            assert.equal(textOf(waiting.one(byClass('reg-why')) ?? { children: [] }), 'It was just sent. Try again in a few minutes.', 'why, on the screen')
        } finally {
            waiting.unmount()
        }

        const view = await open({ view: 'households', rows: [HOUSEHOLD()], inviteStates: invite() })

        try {
            assert.ok(view.one(byClass('reg-why')) === null)
            await view.click(view.one(byClass('reg-resend')))
            const dialog = view.all((el) => el.attrs?.role === 'dialog')[0]
            await view.click(findAll(dialog, byClass('cd-confirm'))[0])

            const post = visits.filter((each) => each.method === 'post').at(-1)
            assert.match(post.url, /\/residents\/lot-12\/people\/9\/invite$/)

            assert.equal(typeof post.options.onError, 'function', 'a refusal has somewhere to go')
            post.options.onError({ invite: 'That address refused the last invite. Correct it on the household page first.' })
            post.options.onFinish()
            await nextTick()

            // Hassam, 12 Oct: said inside the dialog, which stays open; never a toast behind it.
            const still = view.all((el) => el.attrs?.role === 'dialog')[0]
            assert.ok(still !== undefined, 'the dialog is still open')
            assert.equal(textOf(findAll(still, byClass('cd-error'))[0] ?? { children: [] }).trim(), 'That address refused the last invite. Correct it on the household page first.')
            assert.equal(toasts.length, 0, 'no toast while the dialog is open')
        } finally {
            view.unmount()
        }
    })
})
