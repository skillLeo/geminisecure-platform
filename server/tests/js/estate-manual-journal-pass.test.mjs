/**
 * Journal entry, after the M98 pass (`Pages/Estate/Accounting/ManualJournal.vue`).
 *
 *   The way back says where it goes, "Back to manual journals" (M95 §3); posting asks in the console's modal, naming the
 *   lines and the total, where a box opened under the form (M95 §1); a refused post is said inside that question, in its
 *   amber band, and the question stays open (Hassam, 12 October), never a red line on the page and never a message behind
 *   the open question; a refused save, pressed with no question open, is a message top right that stays (M95 §2); a role
 *   that can never write a journal has no Save draft and no Add line (M96
 *   level 3); a greyed Remove and a greyed Post say why on the page (level 2); a refusal of the date or of the lines as a
 *   whole is drawn under them, where it was drawn nowhere; and the totals are the sum of the lines.
 *
 * EVERY ASSERTION COMPARES A WORD, A NUMBER OR A YES/NO, NEVER A RENDERED ELEMENT. A failing assert prints its values,
 * and an element drags the whole rendered tree with it: on 11 Oct a red run of this file reached 67 GB that way.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const { default: ManualJournal } = await import('../../resources/js/Pages/Estate/Accounting/ManualJournal.vue')
const { toasts, resetToasts } = await import('../../resources/js/lib/toasts.js')

const line = (account, debit, credit) => ({ account, debit_minor: debit, credit_minor: credit, unit: null })

const DRAFT = (over = {}) => ({
    id: 5,
    status: 'draft',
    journal_reference: null,
    date: '2026-10-11',
    reference: 'ADJ-7',
    memo: 'Bank charges for October',
    lines: [line('5900', 150000, 0), line('1010', 0, 100000), line('1020', 0, 50000)],
    bank_line_id: null,
    ...over,
})

const open = async (props = {}, url = '/accounting/manual-journals/5') => {
    resetInertia({ auth: { user: { id: 98101, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, url)

    return mount(ManualJournal, {
        estate: { name: 'Phoenix Park Village 1' },
        draft: DRAFT(),
        accounts: [{ code: '5900', label: '5900 · Bank charges' }, { code: '1010', label: '1010 · Operating account' }],
        canEdit: true,
        canPost: true,
        editReason: 'Writing a journal needs Accounting create access.',
        ...props,
    })
}

const button = (view, words) => view.all(byTag('button')).find((el) => textOf(el) === words) ?? null
const disabled = (el) => el !== null && el.attrs.disabled !== undefined && el.attrs.disabled !== false
const absent = (view, cls) => view.one(byClass(cls)) === null
const lastVisit = () => visits.at(-1) ?? null

afterEach(() => resetToasts())

describe('journal entry, after the M98 pass', () => {
    it('says the way back in words, to the list of manual journals', async () => {
        const view = await open()

        try {
            const back = view.one(byClass('back-link'))
            assert.equal(back !== null, true, 'a nested page has the back control')
            assert.equal(textOf(back), 'Back to manual journals')
            assert.equal(back?.attrs.href ?? null, '/accounting/manual-journals')
            assert.equal(absent(view, 'mj-back'), true, 'not the old bare link above the title')
        } finally {
            view.unmount()
        }
    })

    it('asks in the console\'s modal before posting, naming the lines and the total, and posts only when confirmed', async () => {
        const view = await open()

        try {
            await view.click(button(view, 'Post'))
            assert.equal(visits.length, 0, 'one press no longer posts')
            assert.equal(absent(view, 'mj-confirm'), true, 'not a box opened under the form')
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Post 3 lines totalling $1,500.00?')

            await view.click(button(view, 'Post the journal'))
            await nextTick()
            assert.equal(lastVisit()?.url ?? null, '/accounting/manual-journals/5/post')
            assert.equal(JSON.stringify(lastVisit()?.data ?? null), JSON.stringify({ confirm_total: '1500.00', confirm_lines: 3 }))
        } finally {
            view.unmount()
        }
    })

    it('says a refused post inside the question, in its amber band, and keeps the question open with no message behind it', async () => {
        const view = await open()
        const refusal = 'The bank line this journal started from is already matched, or its reconciliation is completed. Nothing was posted.'

        try {
            await view.click(button(view, 'Post'))
            await view.click(button(view, 'Post the journal'))
            assert.equal(lastVisit()?.url ?? null, '/accounting/manual-journals/5/post', 'the post was sent from the question')
            lastVisit().options.onError({ post: refusal })
            await nextTick()

            const band = view.one(byClass('cd-error'))
            assert.equal(band === null ? null : textOf(band), refusal, 'the refusal, inside the question')
            assert.equal(absent(view, 'cd-title'), false, 'the question stays open')
            assert.equal(toasts.length, 0, 'no message behind the open question')
            assert.equal(absent(view, 'mj-refusal'), true, 'no red line on the page')
        } finally {
            view.unmount()
        }
    })

    it('has no Save draft and no Add line for a role that can never write a journal, only why the form is read only', async () => {
        const view = await open({ canEdit: false, canPost: false })

        try {
            assert.equal(button(view, 'Save draft') === null, true, 'Save draft is drawn for a role that can never save')
            assert.equal(button(view, 'Add line') === null, true, 'Add line is drawn for a role that can never add')
            assert.equal(button(view, 'Post') === null, true, 'Post is drawn for a role that never posts')
            assert.equal(view.all(byClass('mj-why')).some((el) => textOf(el) === 'Writing a journal needs Accounting create access.'), true)
        } finally {
            view.unmount()
        }
    })

    it('has no Post for a role that writes journals but never posts them, and says who does', async () => {
        const view = await open({ canEdit: true, canPost: false })

        try {
            assert.equal(button(view, 'Post') === null, true, 'Post is drawn for a role that never posts')
            assert.equal(button(view, 'Save draft') !== null, true, 'the draft is still theirs to save')
            assert.equal(view.all(byClass('mj-why')).some((el) => textOf(el) === 'Posting a journal is for the Treasurer or the Community Super Admin.'), true, 'who posts it, on the page')
        } finally {
            view.unmount()
        }
    })

    it('greys Remove at two lines and says why on the page, and greys Post with its reason while the lines differ', async () => {
        const view = await open({ draft: DRAFT({ lines: [line('5900', 150000, 0), line('1010', 0, 149900)] }) })

        try {
            const removes = view.all(byTag('button')).filter((el) => textOf(el) === 'Remove')
            assert.equal(removes.length, 2)
            assert.equal(removes.every(disabled), true, 'a journal keeps two lines')
            assert.equal(textOf(view.one(byClass('mj-note'))), 'Remove works once the journal has more than two lines.')

            assert.equal(disabled(button(view, 'Post')), true)
            assert.equal(view.all(byClass('mj-why')).some((el) => textOf(el) === 'Debits and credits differ by $1.00.'), true, 'the reason on the page')

            await view.click(button(view, 'Add line'))
            assert.equal(view.all(byTag('button')).filter((el) => textOf(el) === 'Remove').every((el) => !disabled(el)), true, 'three lines: Remove works')
            assert.equal(absent(view, 'mj-note'), true)
        } finally {
            view.unmount()
        }
    })

    it('draws a refusal of the date or of the lines as a whole under them, and a refused save of the journal as a message', async () => {
        const view = await open({ draft: DRAFT({ id: null }) }, '/accounting/manual-journals/new')

        try {
            fire(view.one(byTag('form')), 'submit')
            await nextTick()
            assert.equal(lastVisit()?.url ?? null, '/accounting/manual-journals')

            lastVisit().options.onError({ date: 'Choose a date for the journal.', lines: 'A journal needs its lines.' })
            await nextTick()
            const errors = view.all(byClass('mj-error')).map(textOf)
            assert.equal(errors.includes('Choose a date for the journal.'), true, 'the date refusal is drawn')
            assert.equal(errors.includes('A journal needs its lines.'), true, 'the lines refusal is drawn')

            lastVisit().options.onError({ post: 'This journal is already posted and cannot be changed. Post a reversal to correct it.' })
            await nextTick()
            assert.equal(toasts.some((each) => each.tone === 'error' && each.text.startsWith('This journal is already posted')), true)
        } finally {
            view.unmount()
        }
    })

    it('totals the debits and the credits from the lines themselves', async () => {
        const view = await open()

        try {
            const totals = (view.one(byClass('mj-totals'))?.children ?? []).filter((child) => child.tag === 'td').map(textOf)
            assert.equal(totals.slice(0, 4).join(' | '), 'Totals | $1,500.00 | $1,500.00 | Balanced')

            const amounts = view.all(byClass('mj-amount')).map((input) => Number(String(input.value ?? '').replace(/,/g, '')) || 0)
            assert.equal(amounts.filter((_, i) => i % 2 === 0).reduce((a, b) => a + b, 0), 1500, 'the debit column adds up to its total')
            assert.equal(amounts.filter((_, i) => i % 2 === 1).reduce((a, b) => a + b, 0), 1500, 'the credit column adds up to its total')
        } finally {
            view.unmount()
        }
    })
})
