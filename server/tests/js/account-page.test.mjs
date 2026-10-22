/**
 * My account, `Pages/Account/Show.vue` (M32 F9). The browser's half of what M32AccountTest proves on the server:
 *
 *   a role that requires two-step verification is shown "Off" disabled and no "Turn it off" at all, with why (M96
 *   level 3); turning it off, replacing the app and new recovery codes each ask for the password and a current code,
 *   in the console's modal (M95 §1); nothing typed into a password or code field stays in the page once its request is
 *   answered; the email is never sent with the details, and changes in its own two steps (M34 §1), each in the modal:
 *   the new address and the password, then the code sent to the new address; a demonstration account is told why it
 *   cannot; the recovery codes are shown only on the view the server sends them with; another session can be signed
 *   out, this one cannot.
 *
 * REWRITTEN FOR THE MODALS ON 12 OCTOBER 2026 AND NOT RUN: the JS test runner is banned on this PC (Hassam, 12 Oct).
 * The modal renders in place here (its Teleport is off without a document), so its fields are found by their labels as
 * before, and its primary button (`.cd-confirm`) is clicked where the old inline form was submitted.
 * tests/Unit/AccountPageStandardTest.php holds the structure in PHP, run.
 */
import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { findAll, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, geminiShared } from './support/alerts.mjs'

const { default: Account } = await import('../../resources/js/Pages/Account/Show.vue')

const TWO_FACTOR = (over = {}) => ({
    on: true,
    since: 'Set up 14 September 2026',
    codes_left: 3,
    codes_total: 8,
    required_because: null,
    setup: null,
    new_codes: null,
    ...over,
})

const NOTE = 'The address you sign in with. Changing it needs your password and a code we email to the new address.'

const PROPS = (over = {}) => ({
    console: 'gemini',
    estate: null,
    root: '',
    details: {
        name: 'Dana Dispatcher',
        email: 'dana@geminisecure.example',
        email_note: NOTE,
        role: 'Dispatcher',
        role_note: 'Set by GeminiSecure. Not changeable here.',
        phone: '876 555 0142',
        photo_version: null,
    },
    signInEmail: { locked: null, pending: null, minutes: 10 },
    password: { changed: 'Last changed 14 September 2026', code_to: 'dana@geminisecure.example' },
    twoFactor: TWO_FACTOR(),
    sessions: [
        { id: null, device: 'Chrome on Windows', address: '203.0.113.7', seen: 'now', current: true },
        { id: 12, device: 'Safari on iPhone or iPad', address: '198.51.100.4', seen: '2 hours ago', current: false },
    ],
    ...over,
})

const ESTATE = (over = {}) => PROPS({
    console: 'estate',
    estate: { name: 'Phoenix Park' },
    root: '/estate/phoenixpark',
    details: { ...PROPS().details, name: 'Paula Manager', email: 'paula@phoenixpark.example', role: 'Property Manager', email_note: NOTE, role_note: 'Set by your estate. Not changeable here.' },
    ...over,
})

const open = async (props, url = '/account') => {
    const shared = props.console === 'estate'
        ? { auth: { user: { id: 501, name: 'Paula Manager', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, flash: {}, errors: {} }
        : geminiShared({ errors: {} })
    resetInertia({ ...shared, phoneExample: { local: '876 555 0123', international: '+1 876 555 0123' } }, url)
    const view = await mount(Account, props)
    await flush(nextTick)

    return view
}

const button = (view, label) => view.all((el) => el.tag === 'button' && textOf(el) === label)[0] ?? null
const confirmButton = (view) => view.all((el) => el.tag === 'button' && String(el.className ?? '').includes('cd-confirm'))[0] ?? null
const cancelButton = (view) => view.all((el) => el.tag === 'button' && String(el.className ?? '').includes('cd-cancel'))[0] ?? null
const inputIn = (label) => (label ? findAll(label, (el) => el.tag === 'input')[0] : undefined)
const radio = (view, label) => inputIn(view.all((el) => el.tag === 'label' && el.className.includes('ma-radio')).find((l) => textOf(l).startsWith(label)))
const field = (view, label) => inputIn(view.all((el) => el.tag === 'label').find((l) => textOf(l).startsWith(label)))
const formHolding = (el) => {
    let at = el

    while (at && at.tag !== 'form') {
        at = at.parent
    }

    return at
}

beforeEach(() => {
    socket.reset()
    visits.length = 0
})

describe('two-step verification on My account', () => {
    it('shows "Off" disabled and no "Turn it off" for a role that requires it, and says why', async () => {
        const because = 'Every Gemini console account uses two-step verification at every sign-in, so it cannot be turned off.'
        const view = await open(PROPS({ twoFactor: TWO_FACTOR({ required_because: because }) }))

        assert.equal(radio(view, 'Off').attrs.disabled, true)
        assert.equal(button(view, 'Turn it off') === null, true)
        assert.match(view.text(), /so it cannot be turned off\./)

        // Choosing "Off" anyway opens nothing and sends nothing.
        fire(radio(view, 'Off'), 'change')
        await nextTick()
        assert.equal(field(view, 'Your password') === undefined, true)
        assert.equal(visits.length, 0)
        view.unmount()
    })

    it('turns it off only with the password and a current code, and clears both whatever the answer', async () => {
        const view = await open(ESTATE(), '/estate/phoenixpark/account')

        assert.equal(radio(view, 'Off').attrs.disabled, undefined)
        await view.click(button(view, 'Turn it off'))

        assert.match(view.text(), /Turn off two-step verification/)
        assert.match(view.text(), /Enter your password and the code your app shows now/)
        await view.type(field(view, 'Your password'), 'the-password-typed')
        await view.type(field(view, 'Code from your app'), '123 456')
        await view.click(confirmButton(view))
        await nextTick()

        const sent = visits.at(-1)
        assert.equal(sent.method, 'post')
        assert.equal(sent.url, '/estate/phoenixpark/account/two-factor/off')
        assert.deepEqual(sent.data, { current_password: 'the-password-typed', code: '123 456' })

        sent.options.onError({ code: 'That code is not right. The code changes every 30 seconds: enter the one your app shows now.' })
        sent.options.onFinish()
        await nextTick()

        assert.match(view.text(), /That code is not right/)
        assert.equal(field(view, 'Your password').value, '')
        assert.equal(field(view, 'Code from your app').value, '')
        view.unmount()
    })

    it('asks for the password and a code before new recovery codes, and shows codes only on the view that has them', async () => {
        const view = await open(PROPS())

        assert.match(view.text(), /3 of 8 left/)
        assert.doesNotMatch(view.text(), /Your recovery codes/)

        await view.click(button(view, 'Make new codes'))
        await view.type(field(view, 'Your password'), 'pw')
        await view.type(field(view, 'Code from your app'), '654321')
        await view.click(button(view, 'Replace my codes'))
        await nextTick()
        assert.equal(visits.at(-1).url, '/account/two-factor/codes')

        // The server's answer carries them once…
        view.props.twoFactor = TWO_FACTOR({ codes_left: 8, new_codes: ['7KQM-3XRW', 'ABCD-EFGH'] })
        await nextTick()
        assert.match(view.text(), /Your recovery codes/)
        assert.match(view.text(), /7KQM-3XRW/)

        // …and the next view does not.
        view.props.twoFactor = TWO_FACTOR({ codes_left: 8, new_codes: null })
        await nextTick()
        assert.doesNotMatch(view.text(), /7KQM-3XRW/)
        view.unmount()
    })

    it('while setting up, shows the key and does not say it is on until a code is typed back', async () => {
        const view = await open(ESTATE({
            twoFactor: TWO_FACTOR({ on: false, since: null, setup: { mode: 'on', qr: 'data:image/svg+xml;base64,AAAA', secret: 'ABCD EFGH IJKL MNOP' } }),
        }), '/estate/phoenixpark/account')

        assert.equal(radio(view, 'Off').attrs.checked, true)
        assert.match(view.text(), /Set up your authenticator app/)
        assert.match(view.text(), /ABCD EFGH IJKL MNOP/)
        assert.match(view.text(), /Two-step verification is not on until you do\./)
        assert.equal(button(view, 'Turn it off') === null, true)

        await view.type(field(view, 'Code from your app'), '111222')
        await view.click(button(view, 'Finish'))
        await nextTick()

        const sent = visits.at(-1)
        assert.equal(sent.url, '/estate/phoenixpark/account/two-factor/confirm')
        assert.deepEqual(sent.data, { code: '111222' })
        view.unmount()
    })
})

describe('your details and your password', () => {
    it('shows the email and never sends it with the details: Save posts the name and the phone only', async () => {
        const view = await open(PROPS())

        assert.match(view.text(), /dana@geminisecure\.example/)
        assert.match(view.text(), /Changing it needs your password and a code we email to the new address\./)
        assert.match(view.text(), /Set by GeminiSecure\. Not changeable here\./)
        assert.equal(field(view, 'Phone').attrs.placeholder, '876 555 0123')

        await view.type(field(view, 'Name'), 'Dana R. Dispatcher')
        fire(formHolding(field(view, 'Name')), 'submit')
        await nextTick()

        const sent = visits.at(-1)
        assert.equal(sent.url, '/account/details')
        assert.deepEqual(Object.keys(sent.data).sort(), ['name', 'phone'])
        assert.equal(sent.data.name, 'Dana R. Dispatcher')
        view.unmount()
    })

    it('changes the password with the old one and the emailed code, and clears all four fields whatever the answer', async () => {
        const view = await open(PROPS())

        assert.match(view.text(), /Last changed 14 September 2026/)
        await view.click(button(view, 'Change my password'))
        assert.match(view.text(), /every other place you are signed in is signed out\. This one stays\./)

        await view.click(button(view, 'Email me a code'))
        assert.equal(visits.at(-1).url, '/account/password/code')

        await view.type(field(view, 'Current password'), 'old-one')
        await view.type(field(view, 'Code from the email'), '000111')
        await view.type(field(view, 'New password'), 'a-long-new-passphrase')
        await view.type(field(view, 'New password again'), 'a-long-new-passphrase')
        await view.click(button(view, 'Save the new password'))
        await nextTick()

        const sent = visits.at(-1)
        assert.equal(sent.url, '/account/password')
        assert.deepEqual(sent.data, { current_password: 'old-one', code: '000111', password: 'a-long-new-passphrase', password_confirmation: 'a-long-new-passphrase' })

        sent.options.onError({ current_password: 'That is not your current password.' })
        sent.options.onFinish()
        await nextTick()

        assert.match(view.text(), /That is not your current password\./)
        for (const label of ['Current password', 'Code from the email', 'New password', 'New password again']) {
            assert.equal(field(view, label).value, '', label)
        }
        view.unmount()
    })
})

describe('the sign-in email (M34 §1)', () => {
    it('step one sends the new address and the password, and the password does not stay in the page', async () => {
        const view = await open(PROPS())

        assert.match(view.text(), /You sign in with dana@geminisecure\.example\./)
        assert.equal(field(view, 'New email') === undefined, true)

        await view.click(button(view, 'Change my sign-in email'))
        assert.match(view.text(), /nothing\s+changes\s+until you type that code here/)
        await view.type(field(view, 'New email'), 'dana.new@example.com')
        await view.type(field(view, 'Current password'), 'my-password')
        await view.click(button(view, 'Email a code to the new address'))
        await nextTick()

        const sent = visits.at(-1)
        assert.equal(sent.method, 'post')
        assert.equal(sent.url, '/account/sign-in-email/code')
        assert.deepEqual(sent.data, { new_email: 'dana.new@example.com', current_password: 'my-password' })

        sent.options.onError({ current_password: 'That is not your current password.' })
        sent.options.onFinish()
        await nextTick()

        assert.match(view.text(), /That is not your current password\./)
        assert.equal(field(view, 'Current password').value, '')
        assert.equal(field(view, 'New email').value, 'dana.new@example.com')
        view.unmount()
    })

    it('step two says where the code went, sends the code alone, and Cancel forgets the address', async () => {
        const view = await open(ESTATE({ signInEmail: { locked: null, pending: 'paula.new@example.com', minutes: 10 } }), '/estate/phoenixpark/account')

        assert.match(view.text(), /A code went to paula\.new@example\.com\. It works for 10\s+minutes\./)
        // The modal's own primary button: the page's "Change my sign-in email" is not offered while a code waits.
        // Booleans, never rendered elements, in an assertion (a failing one serialises the whole tree: 11 Oct, 67 GB).
        assert.equal(confirmButton(view) !== null && confirmButton(view) === button(view, 'Change my sign-in email'), true)

        await view.type(field(view, 'Code from the email'), '135 791')
        await view.click(confirmButton(view))
        await nextTick()

        const sent = visits.at(-1)
        assert.equal(sent.url, '/estate/phoenixpark/account/sign-in-email')
        assert.deepEqual(sent.data, { code: '135 791' })

        sent.options.onFinish()
        await nextTick()
        assert.equal(field(view, 'Code from the email').value, '')

        await view.click(cancelButton(view))
        assert.equal(visits.at(-1).url, '/estate/phoenixpark/account/sign-in-email/cancel')
        view.unmount()
    })

    it('tells a demonstration account why its address is not changed here, and offers nothing', async () => {
        const locked = 'This is a demonstration account. The demonstration signs it in by this address, so it is not changed here.'
        const view = await open(PROPS({ signInEmail: { locked, pending: null, minutes: 10 } }))

        assert.match(view.text(), /This is a demonstration account\./)
        assert.equal(button(view, 'Change my sign-in email') === null, true)
        assert.equal(field(view, 'New email') === undefined, true)
        view.unmount()
    })
})

describe('where you are signed in', () => {
    it('signs out another session, never this one, and offers everywhere else', async () => {
        const view = await open(ESTATE(), '/estate/phoenixpark/account')

        const rows = view.all((el) => el.tag === 'li' && el.className.includes('ma-session'))
        assert.equal(rows.length, 2)
        assert.match(textOf(rows[0]), /^This device\s*Chrome on Windows · 203\.0\.113\.7 · now$/)
        assert.equal(rows[0].children.some((c) => c.tag === 'button'), false)
        assert.match(textOf(rows[1]), /^Another\s*Safari on iPhone or iPad · 198\.51\.100\.4 · 2 hours ago\s*Sign it out$/)

        await view.click(button(view, 'Sign it out'))
        assert.equal(visits.at(-1).url, '/estate/phoenixpark/account/sessions/12/sign-out')

        visits.at(-1).options.onFinish()
        await view.click(button(view, 'Sign out everywhere else'))
        assert.equal(visits.at(-1).url, '/estate/phoenixpark/account/sessions/sign-out-others')
        view.unmount()
    })
})
