/**
 * Manage amenities, after the M97 pass (`Pages/Estate/Facilities/AmenitySettings.vue`).
 *
 *   Add amenity and each card's edit open the console's modal, never a form on the page (M95 §1); each error sits under
 *   its own field; a role that can never add or edit has neither control (M96 level 3); the way back to the diary is
 *   in words, top left (M95 §3). And the read-back (M95 §4: "fee $3,000 and deposit $5,000 read back as free for
 *   residents, no deposit"): the fields showed 3000.00 and 5000.00 only as placeholders, so they were blank; the
 *   placeholders now say what a blank means, and "3,000" or "$5,000" typed are read back, and posted, as the amounts.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const { default: AmenitySettings } = await import('../../resources/js/Pages/Estate/Facilities/AmenitySettings.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const CLUBHOUSE = {
    id: 3,
    name: 'Clubhouse',
    terms_effect: 'Applies to bookings made from now on. The 2 upcoming bookings already made keep the fee, deposit and cancellation rule they were made under.',
    icon: 'clubhouse',
    capacity: 80,
    capacity_label: '80 guests',
    fee_label: '$3,000',
    deposit_label: '$5,000',
    hours_label: '8:00 AM – 10:00 PM',
    is_bookable: true,
    requires_approval: true,
    terms: {
        name: 'Clubhouse',
        icon_key: 'clubhouse',
        capacity: 80,
        fee: '3000.00',
        deposit: '5000.00',
        opens_at: '08:00',
        closes_at: '22:00',
        booking_window_days: 30,
        cancellation_hours: 48,
        is_bookable: true,
        requires_approval: true,
    },
}

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 97002, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/amenities/settings')

    return mount(AmenitySettings, {
        estate: { name: 'Phoenix Park Village 1' },
        rows: [CLUBHOUSE],
        icons: ['gazebo', 'clubhouse', 'pavilion', 'pool'],
        canUpdate: true,
        canCreate: true,
        blockedReason: 'Editing the rate card needs Facilities update.',
        createReason: 'Adding an amenity needs Facilities create.',
        ...props,
    })
}

const button = (view, words) => view.all(byTag('button')).find((el) => textOf(el).includes(words)) ?? null
const field = (view, id) => view.one((el) => el.attrs.id === id)
const confirm = (view) => view.one(byClass('cd-confirm'))

/* Absence as true or false: comparing a drawn element with null makes a failure print the whole tree. */
const absent = (el, message) => assert.ok(el === null, message)

afterEach(() => resetToasts())

describe('Manage amenities, after the M97 pass', () => {
    it('opens Add amenity in the console\'s modal, with placeholders that are words, never amounts', async () => {
        const view = await open()
        try {
            absent(view.one(byClass('am-panel')), 'no form on the page')
            await view.click(button(view, 'Add amenity'))

            assert.equal(textOf(view.one(byClass('cd-title'))), 'Add an amenity')
            assert.ok(view.one(byClass('cd-form')), 'the form size: wider, full screen on a phone')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Name the amenity first.', 'why it is greyed, on the screen')
            for (const id of ['am-fee', 'am-deposit']) {
                assert.doesNotMatch(String(field(view, id).attrs.placeholder ?? ''), /\d/, `${id}'s placeholder is not an amount`)
            }
            assert.match(textOf(view.one(byClass('am-read'))), /free for residents, no deposit/, 'blank is said as what it means')
        } finally {
            view.unmount()
        }
    })

    it('reads back and posts "3,000" and "$5,000" as the amounts they are (M95 §4)', async () => {
        const view = await open()
        try {
            await view.click(button(view, 'Add amenity'))
            await view.type(field(view, 'am-name'), 'Tennis Court')
            await view.type(field(view, 'am-fee'), '3,000')
            await view.type(field(view, 'am-deposit'), '$5,000')

            assert.match(textOf(view.one(byClass('am-read'))), /Tennis Court: .*, fee \$3,000, deposit \$5,000,/)
            absent(view.one(byClass('cd-why')), 'nothing stands in the way')

            await view.click(confirm(view))
            await nextTick()
            const post = visits.at(-1)
            assert.equal(post.url, '/facilities/amenities')
            assert.equal(post.data.fee, '3000')
            assert.equal(post.data.deposit, '5000')
        } finally {
            view.unmount()
        }
    })

    it('says why an amount it cannot read cannot be saved, under the button', async () => {
        const view = await open()
        try {
            await view.click(button(view, 'Add amenity'))
            await view.type(field(view, 'am-name'), 'Tennis Court')
            await view.type(field(view, 'am-fee'), 'three thousand')

            assert.equal(textOf(view.one(byClass('cd-why'))), 'Write the booking fee as an amount, like 3000 or 3,000.50.')
            assert.match(textOf(view.one(byClass('am-read'))), /fee not an amount yet/)
        } finally {
            view.unmount()
        }
    })

    it('opens an edit in the modal, reading back the stored fee and deposit and what the change reaches', async () => {
        const view = await open()
        try {
            await view.click(view.one((el) => el.tag === 'button' && el.attrs['aria-label'] === 'Edit Clubhouse'))

            assert.equal(textOf(view.one(byClass('cd-title'))), 'Edit the Clubhouse')
            assert.match(textOf(view.one(byClass('am-read'))), /fee \$3,000, deposit \$5,000/)
            assert.match(textOf(view.one(byClass('am-effect'))), /The 2 upcoming bookings already made keep/)
            assert.equal(textOf(confirm(view)), 'Save terms')
        } finally {
            view.unmount()
        }
    })

    it('puts each refusal under its own field', async () => {
        const view = await open()
        try {
            await view.click(button(view, 'Add amenity'))
            await view.type(field(view, 'am-name'), 'Tennis Court')
            await view.click(confirm(view))
            visits.at(-1).options.onError({ deposit: 'A deposit runs to $1,000,000.00.' })
            await nextTick()

            const error = view.one((el) => byClass('am-error')(el) && textOf(el) === 'A deposit runs to $1,000,000.00.')
            assert.ok(error, 'the refusal is drawn')
            assert.ok(error.parent.children.some((el) => el.attrs?.id === 'am-deposit'), 'under the deposit field')
        } finally {
            view.unmount()
        }
    })

    it('has no Add amenity and no edit control for a role that can do neither', async () => {
        const view = await open({ canCreate: false, canUpdate: false })
        try {
            absent(button(view, 'Add amenity'), 'no Add amenity')
            absent(view.one((el) => el.tag === 'button' && el.attrs['aria-label'] === 'Edit Clubhouse'), 'no edit control')
        } finally {
            view.unmount()
        }
    })

    it('goes back to the diary in words, top left', async () => {
        const view = await open()
        try {
            const back = view.one((el) => el.tag === 'a' && textOf(el) === 'Back to bookings')
            assert.ok(back, 'a back control in words')
            assert.equal(back.attrs.href, '/facilities/amenities/bookings')
        } finally {
            view.unmount()
        }
    })
})
