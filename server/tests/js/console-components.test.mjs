/**
 * The three components every console page now shares (M95, M97 item 1): the modal, the toast, the back control.
 *
 *   The modal (`ConsoleDialog`): opens over the page; Escape, the backdrop and the corner close it; with something
 *   typed it asks before throwing it away; the buttons stay in view while a long form scrolls inside it; a form takes
 *   the whole screen on a phone; focus goes back to the button that opened it.
 *   The toast (`lib/toasts.js`, `ToastStack`): every message top right, newest first, each with a close control; a
 *   success closes itself and a failure stays; the same words never twice; the shell says a page's flash itself, and a
 *   document being prepared once, in place.
 *   The back control (`BackLink`): "Back to <list>", top left before the title, to the list itself.
 */
import { afterEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { byClass, byTag, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'

const { default: ConsoleDialog } = await import('../../resources/js/Components/ConsoleDialog.vue')
const { default: BackLink } = await import('../../resources/js/Components/BackLink.vue')
const { default: EstateConsole } = await import('../../resources/js/Layouts/EstateConsole.vue')
const { toast, toasts, dismissToast, resetToasts } = await import('../../resources/js/lib/toasts.js')

const source = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8')

/** The rule's own block in a component's style, from its selector to the closing brace. */
const block = (text, selector) => {
    const at = text.indexOf(`${selector} {`)

    return at < 0 ? '' : text.slice(at, text.indexOf('}', at) + 1)
}

const occurrences = (haystack, needle) => haystack.split(needle).length - 1

const shell = (flash = {}) => {
    resetInertia({ auth: { user: { id: 95001, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {}, flash }, '/payroll/runs/sep-2026')
}

afterEach(() => {
    resetToasts()
    mock.timers.reset()
    globalThis.document.activeElement = null
})

describe('M95 §1: one modal, for every form that used to open on its page', () => {
    const open = async (props = {}) => {
        const record = { cancel: 0, confirm: 0 }
        const view = await mount(ConsoleDialog, {
            open: false,
            title: 'Record a bill',
            confirmLabel: 'Record bill',
            size: 'form',
            discardTitle: 'Discard this bill?',
            onCancel: () => record.cancel++,
            onConfirm: () => record.confirm++,
            ...props,
        })

        view.props.open = true
        await nextTick()
        await nextTick()

        return { view, record }
    }

    it('says what it does in its title, with a close control in the corner', async () => {
        const { view, record } = await open()

        try {
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Record a bill')

            const close = view.one(byClass('cd-close'))
            assert.equal(close.attrs['aria-label'], 'Close')

            await view.click(close)
            assert.equal(record.cancel, 1, 'the corner closes it, and closing never confirms')
            assert.equal(record.confirm, 0)
        } finally {
            view.unmount()
        }
    })

    it('closes on Escape and on the backdrop, and never on a press inside it', async () => {
        const { view, record } = await open()

        try {
            globalThis.document.dispatchEvent('keydown', { key: 'Escape', preventDefault() {} })
            assert.equal(record.cancel, 1, 'Escape closes it')

            fire(view.one(byClass('cd-panel')), 'mousedown')
            assert.equal(record.cancel, 1, 'a press on the panel is not a press on the backdrop')

            fire(view.one(byClass('cd-backdrop')), 'mousedown')
            assert.equal(record.cancel, 2, 'the backdrop closes it')
        } finally {
            view.unmount()
        }
    })

    it('asks before throwing away what was typed, and Escape or Keep editing go back to the form', async () => {
        const { view, record } = await open({ dirty: true })

        try {
            await view.click(view.one(byClass('cd-close')))
            assert.equal(record.cancel, 0, 'nothing typed is thrown away silently')
            assert.ok(view.text().includes('Discard this bill?'), 'it asks, in the words the page gave it')

            globalThis.document.dispatchEvent('keydown', { key: 'Escape', preventDefault() {} })
            await nextTick()
            assert.ok(!view.text().includes('Discard this bill?'), 'Escape while it asks goes back to the form')
            assert.equal(record.cancel, 0)

            fire(view.one(byClass('cd-backdrop')), 'mousedown')
            await nextTick()
            const question = view.one(byClass('cd-discard'))
            assert.ok(question, 'the backdrop asks too')

            await view.click(view.all(byTag('button')).find((el) => textOf(el) === 'Keep editing'))
            assert.equal(view.one(byClass('cd-discard')), null, 'Keep editing goes back to the form')

            await view.click(view.all(byTag('button')).find((el) => textOf(el) === 'Cancel'))
            await view.click(view.all(byTag('button')).find((el) => textOf(el) === 'Discard'))
            assert.equal(record.cancel, 1, 'only Discard throws it away')
        } finally {
            view.unmount()
        }
    })

    it('gives focus back to the button that opened it', async () => {
        let returned = false
        globalThis.document.activeElement = { focus: () => { returned = true } }

        const { view } = await open()

        try {
            view.props.open = false
            await nextTick()
            assert.ok(returned, 'focus goes back to the opener when it closes')
        } finally {
            view.unmount()
        }
    })

    it('scrolls a long form inside itself with the buttons always in view, and takes the whole screen on a phone', () => {
        const text = source('Components/ConsoleDialog.vue')

        assert.match(block(text, '.cd-body'), /overflow-y: auto/, 'the body scrolls, not the page behind it')
        assert.match(block(text, '.cd-panel'), /overflow: hidden/, 'the panel itself never scrolls its buttons away')
        assert.match(block(text, '.cd-actions'), /flex: 0 0 auto/, 'the buttons keep their place at the bottom')

        const phone = text.slice(text.indexOf('@media (max-width: 640px)'))
        assert.match(phone, /\.cd-form \.cd-panel \{[^}]*height: 100%[^}]*border-radius: 0/, 'a form is full screen on a phone')
    })
})

describe('M95 §2: every message a toast, top right, with a close control', () => {
    it('closes a success by itself, keeps a failure until it is closed, and puts the newest at the top', () => {
        mock.timers.enable({ apis: ['setTimeout'] })

        toast('Bill recorded.')
        toast('That bill is already approved.', { tone: 'error' })

        assert.deepEqual(toasts.map((each) => each.text), ['That bill is already approved.', 'Bill recorded.'], 'newest first')

        mock.timers.tick(5000)
        assert.deepEqual(toasts.map((each) => each.text), ['That bill is already approved.'], 'a success closes itself')

        mock.timers.tick(10 * 60 * 1000)
        assert.equal(toasts.length, 1, 'a failure stays until the person closes it')

        dismissToast(toasts[0].id)
        assert.equal(toasts.length, 0)
    })

    it('never shows the same words twice, and changes a keyed message in place', () => {
        toast('Notice posted.')
        toast('Notice posted.')
        assert.equal(toasts.length, 1, 'the same words are one message')

        toast('Preparing the agenda. It will download in a moment.', { tone: 'info', key: 'document', sticky: true })
        toast('The agenda could not be produced: the worker stopped.', { tone: 'error', key: 'document' })
        assert.deepEqual(toasts.map((each) => each.text), ['The agenda could not be produced: the worker stopped.', 'Notice posted.'])
    })

    it('says a page\'s flash in the shell, once, with an icon, its words and a close control, and no banner in the page', async () => {
        shell({ success: 'Run approved and posted.', error: null })
        const view = await mount(EstateConsole, { title: 'September 2026 Pay Run', estateName: 'Phoenix Park Village 1' })

        try {
            assert.equal(occurrences(view.text(), 'Run approved and posted.'), 1, 'said once')

            const item = view.one(byClass('toast'))
            assert.ok(item.className.includes('success'))
            assert.equal(item.attrs.role, 'status')
            assert.ok(view.one(byClass('toast-icon')), 'colour is never the only signal: there is an icon')

            await view.click(view.one(byClass('toast-close')))
            assert.ok(!view.text().includes('Run approved and posted.'), 'the close control closes it')
        } finally {
            view.unmount()
        }
    })

    it('says a refusal as a failure that stays, read out at once', async () => {
        shell({ error: 'You calculated this run, so someone else approves it.' })
        const view = await mount(EstateConsole, { title: 'September 2026 Pay Run', estateName: 'Phoenix Park Village 1' })

        try {
            const item = view.one(byClass('toast'))
            assert.ok(item.className.includes('error'))
            assert.equal(item.attrs.role, 'alert')
        } finally {
            view.unmount()
        }
    })

    it('says a document being prepared once, not as a banner and a toast', async () => {
        mock.timers.enable({ apis: ['setTimeout'] })
        shell({
            success: 'Preparing the remittance advice. It will download in a moment.',
            document: { id: 7, label: 'Remittance advice', status_url: '/documents/7/status', download_url: '/documents/7' },
        })
        const view = await mount(EstateConsole, { title: 'Bills & payments', estateName: 'Phoenix Park Village 1' })

        try {
            assert.equal(occurrences(view.text(), 'Preparing the remittance advice. It will download in a moment.'), 1)
        } finally {
            view.unmount()
        }
    })

    it('draws no page banner for a flash anywhere in the estate console any more', () => {
        // Every estate page that drew `flash.success` or `flash.error` into itself (36 of them) leaves it to the shell.
        const pages = ['Estate/Accounting/Bills.vue', 'Estate/Accounting/ChartOfAccounts.vue', 'Estate/Dues/ChargeSchedule.vue', 'Estate/Payroll/Run.vue', 'Estate/Governance/Notices.vue', 'Estate/Residents/DeletedAccounts.vue']

        for (const page of pages) {
            assert.doesNotMatch(source(`Pages/${page}`), /flash\??\.(success|error)/, `${page} draws no flash of its own`)
        }
    })
})

describe('M95 §3: a back control on every nested page', () => {
    it('says where it goes and goes to that list', async () => {
        const view = await mount(BackLink, { href: '/payroll', label: 'Back to pay runs' })

        try {
            const link = view.one(byTag('a'))
            assert.equal(link.attrs.href, '/payroll', 'to the list itself, never the browser\'s history')
            assert.equal(textOf(link), 'Back to pay runs', 'in words, not only an arrow')
        } finally {
            view.unmount()
        }
    })

    it('sits top left, before the title, when a page passes one', async () => {
        shell()
        const view = await mount(EstateConsole, { title: 'September 2026 Pay Run', estateName: 'Phoenix Park Village 1', back: { href: '/payroll', label: 'Back to pay runs' } })

        try {
            const bar = view.one(byClass('topbar'))
            const children = bar.children.filter((child) => child.tag)
            assert.ok(children[0].className.includes('back-link'), 'the first thing in the bar')
            assert.ok(children.findIndex((child) => child.tag === 'h1') > 0, 'and the title after it')
        } finally {
            view.unmount()
        }

        shell()
        const top = await mount(EstateConsole, { title: 'Payroll & HR', estateName: 'Phoenix Park Village 1' })

        try {
            assert.equal(top.one(byClass('back-link')), null, 'a page the sidebar opens has none')
        } finally {
            top.unmount()
        }
    })
})
