/**
 * The front door: sign in, forgot password, verify contact, and the three other door cards (M97 item 2; Hassam 11 Oct:
 * "The giant icon, the double message, the brand changing between screens").
 *
 *   - No notice, and so no icon, until there is something to report.
 *   - A refusal sits under the field it is about, small: never above the form, never a banner.
 *   - Sign in says how accounts come to exist at the card's foot, always, not inside the error.
 *   - Forgot password says one thing at a time: what the card does, or that a link was sent.
 *   - Every door card names the company, "Gemini Security Limited".
 *   - No card is a fixed 900px tall (it pushed the button below a laptop's fold), and none carries an em dash.
 *   - Verify contact tells a verified account where alerts go without "The code has not arrived", and says its rules
 *     in sentences.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const AUTH = path.resolve(HERE, '../../resources/js/Pages/Auth')

const { default: Login } = await import('../../resources/js/Pages/Auth/Login.vue')
const { default: ForgotPassword } = await import('../../resources/js/Pages/Auth/ForgotPassword.vue')
const { default: VerifyContact } = await import('../../resources/js/Pages/Auth/VerifyContact.vue')
const { default: ResetPassword } = await import('../../resources/js/Pages/Auth/ResetPassword.vue')
const { default: AcceptInvitation } = await import('../../resources/js/Pages/Auth/AcceptInvitation.vue')
const { default: TwoFactor } = await import('../../resources/js/Pages/Auth/TwoFactor.vue')

/** The estate door as `LoginController::doorFor()` draws it. */
const DOOR = {
    key: 'estate',
    mark: 'STAFF LOG IN',
    colourway: 'estate',
    email_label: 'Email address',
    submit_label: 'Log in',
    submitting_label: 'Logging in…',
    foot: 'Phoenix Park Village 1 · Powered by Gemini Security Limited',
    mfa_note: null,
    forgot_password: true,
    forgot_href: '/forgot-password',
    login_href: '/login',
    forgot_placement: 'row',
}

const POLICY = 'Accounts are issued by invitation. If you cannot get in, ask your administrator.'

const open = async (page, props, flash = {}) => {
    resetInertia({ errors: {}, flash }, '/')
    const view = await mount(page, props)
    await nextTick()

    return view
}

const notices = (view) => view.all((el) => byClass('door-notice')(el))

/** The `.field` an element sits in, and that field's label. */
const fieldOf = (el) => {
    let at = el.parent
    while (at && !byClass('field')(at)) at = at.parent

    return at
}

const labelOf = (field) => textOf(field.children.find((el) => el.tag === 'label') ?? { children: [] })

/** Answer the last post the way the server refuses it. */
const refuse = async (errors) => {
    visits.at(-1).options.onError(errors)
    await nextTick()
}

const VERIFY = {
    door: DOOR,
    root: '',
    account: { name: 'Owen Grant', login_email: 'owen.grant@example.com' },
    contact: { email: 'owen.grant@example.com', phone: '876 555 0101', verified: false, verified_at: null, verified_at_label: null },
    code: null,
    rules: { ttl_minutes: 10, max_attempts: 5, resend_seconds: 60, code_length: 6 },
    sms: { can_send: false, note: 'Stored ready for text messages.' },
    required: true,
    continue_href: null,
    administrator: 'the estate office',
}

describe('the front door (M97 item 2)', () => {
    it('draws no notice on an ordinary visit to sign in, and says how accounts come to exist at the foot', async () => {
        const view = await open(Login, { door: DOOR })
        try {
            assert.equal(notices(view).length, 0, 'no icon until there is something to report')
            const policy = view.one((el) => byClass('l-policy')(el))
            assert.ok(policy, 'the policy line is on the card without an error')
            assert.equal(textOf(policy), POLICY)
        } finally {
            view.unmount()
        }
    })

    it('puts a refused sign-in under the password, small, and the error alone', async () => {
        const view = await open(Login, { door: DOOR })
        try {
            view.one((el) => el.tag === 'form').handlers.submit({ preventDefault() {} })
            await refuse({ email: 'Those credentials do not match our records.' })

            const [notice] = notices(view)
            assert.ok(byClass('door-notice--error')(notice), 'an inline refusal, not a banner')
            assert.equal(notice.attrs.role, 'alert')
            assert.equal(textOf(notice), 'Those credentials do not match our records.')
            assert.equal(labelOf(fieldOf(notice)), 'Password', 'under the fields it is about')
            assert.equal(view.all((el) => byClass('l-policy')(el)).length, 1, 'the policy is said once, at the foot')
        } finally {
            view.unmount()
        }
    })

    it('says one thing at a time on forgot password: what the card does, then that a link was sent', async () => {
        const before = await open(ForgotPassword, { door: DOOR })
        try {
            assert.equal(notices(before).length, 0, 'no tick before anything has happened')
            assert.match(textOf(before.one((el) => byClass('fp-lead')(el))), /The link works once, for thirty minutes\./)
        } finally {
            before.unmount()
        }

        const sent = 'If that address holds an account, a link to choose a new password has been sent to it. It works once, for thirty minutes.'
        const after = await open(ForgotPassword, { door: DOOR }, { status: sent })
        try {
            const all = notices(after)
            assert.equal(all.length, 1)
            assert.ok(byClass('door-notice--ok')(all[0]))
            assert.equal(textOf(all[0]), sent)
            assert.equal(after.one((el) => byClass('fp-lead')(el)), null, 'the lead gives way: one message, not two')
        } finally {
            after.unmount()
        }
    })

    it('puts a refused address under the address field on forgot password', async () => {
        const view = await open(ForgotPassword, { door: DOOR })
        try {
            view.one((el) => el.tag === 'form').handlers.submit({ preventDefault() {} })
            await refuse({ email: 'Enter a valid email address.' })

            const [notice] = notices(view)
            assert.ok(byClass('door-notice--error')(notice))
            assert.equal(labelOf(fieldOf(notice)), 'Email address')
        } finally {
            view.unmount()
        }
    })

    it('tells a verified account where alerts go, without "The code has not arrived"', async () => {
        const view = await open(VerifyContact, {
            ...VERIFY,
            contact: { ...VERIFY.contact, verified: true, verified_at_label: 'Oct 6, 2026 9:15 AM' },
            continue_href: '/dashboard',
        })
        try {
            assert.doesNotMatch(view.text(), /The code has not arrived/)
            assert.match(view.text(), /Where emergency alerts reach you/)
            const [notice] = notices(view)
            assert.ok(byClass('door-notice--ok')(notice))
            assert.match(textOf(notice), /Alerts reach you at owen\.grant@example\.com, verified Oct 6, 2026 9:15 AM\./)
        } finally {
            view.unmount()
        }
    })

    it('offers help with a code only once one has been sent, and says its rules in sentences', async () => {
        const fresh = await open(VerifyContact, VERIFY)
        try {
            assert.doesNotMatch(fresh.text(), /The code has not arrived/, 'nothing has been sent yet')
            assert.match(
                fresh.text(),
                /A new code can be sent once a minute\. Each code works once, for 10 minutes, and stops working after 5 wrong tries\./
            )
            const [notice] = notices(fresh)
            assert.ok(byClass('door-notice--warn')(notice), 'something to do, not a failure')
        } finally {
            fresh.unmount()
        }

        const waiting = await open(VerifyContact, {
            ...VERIFY,
            code: { state: 'waiting', sent_to: 'owen.grant@example.com', expires_in: 540, resend_in: 0, attempts_left: 5 },
        })
        try {
            assert.match(waiting.text(), /The code has not arrived/)
        } finally {
            waiting.unmount()
        }
    })

    it('names the company on every door card', async () => {
        const cards = [
            [Login, { door: DOOR }],
            [ForgotPassword, { door: DOOR }],
            [VerifyContact, VERIFY],
            [ResetPassword, { door: DOOR, token: 't', email: 'owen.grant@example.com' }],
            [AcceptInvitation, { door: DOOR, token: 't', invitation: null, refusal: 'This invitation has expired.' }],
            [TwoFactor, { door: { ...DOOR, key: 'gemini' }, mode: 'challenge', email: 'owen.grant@example.com' }],
        ]

        for (const [page, props] of cards) {
            const view = await open(page, props)
            try {
                assert.equal(textOf(view.one((el) => byClass('lm1')(el))), 'Gemini Security Limited', page.__name ?? 'a door card')
            } finally {
                view.unmount()
            }
        }
    })

    it('keeps no door card at a fixed 900px and no em dash in what a door card says', () => {
        for (const file of fs.readdirSync(AUTH).filter((name) => name.endsWith('.vue'))) {
            const source = fs.readFileSync(path.join(AUTH, file), 'utf8')
            const template = source.slice(source.indexOf('<template>'), source.lastIndexOf('</template>'))
            const words = template.replace(/<!--[\s\S]*?-->/g, '')

            assert.doesNotMatch(source, /height:\s*900px/, `${file} is still a fixed 900px`)
            assert.doesNotMatch(words, /—/, `${file} still says an em dash`)
        }
    })
})
