/**
 * The estate console's search field — `Layouts/EstateConsole.vue`.
 *
 *   B109  what the reader is typing is never overwritten by the server's answer to an earlier
 *         keystroke: Laravel trims the query, so the answer to "Mary " is "Mary", and the field
 *         was set back to it — the space and every letter typed since were lost, and the
 *         pending search then sent the clobbered value. A filter changed from elsewhere (a
 *         phase chip, the back button) still shows in the field.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { byTag, mount, nextTick } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, RAISED } from './support/alerts.mjs'

const { default: EstateConsole } = await import('../../resources/js/Layouts/EstateConsole.vue')

let user = 12000

const residents = async (searchValue = '') => {
    // A Treasurer: no panic watch, so nothing but the search is on the move.
    resetInertia({ auth: { user: { id: ++user, name: 'Tara Treasurer', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null }, '/estate/phoenixpark/residents')

    const view = await mount(EstateConsole, {
        title: 'Residents',
        estateName: 'Phoenix Park',
        searchPlaceholder: 'Search households, residents, lots',
        searchRoute: '/estate/phoenixpark/residents',
        searchValue,
        searchParams: { phase: 'Phase 2' },
    })

    await flush(nextTick)

    return { view, field: view.one((el) => el.tag === 'input' && el.attrs.type === 'search') }
}

const searches = () => visits.filter((visit) => visit.method === 'get').map((visit) => visit.data.q)

beforeEach(() => {
    socket.reset()
    visits.length = 0
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED })
})

afterEach(() => {
    mock.timers.reset()
})

describe('B109 — the field keeps what the reader typed', () => {
    it('keeps typing that went on while an earlier search was answered', async () => {
        const { view, field } = await residents()

        // "Mary " and a pause: the search goes out.
        await view.type(field, 'Mary ')
        mock.timers.tick(250)

        assert.deepEqual(searches(), ['Mary '])

        // The reader carries on typing, and the answer to "Mary " lands, trimmed by the server.
        await view.type(field, 'Mary B')
        view.props.searchValue = 'Mary'
        await nextTick()

        assert.equal(field.value, 'Mary B', 'the answer to an earlier keystroke does not overwrite the field')

        // And the search that goes next is what the reader typed.
        mock.timers.tick(250)
        assert.deepEqual(searches(), ['Mary ', 'Mary B'])

        view.unmount()
    })

    it('keeps the trailing space when the answer is the same search, trimmed', async () => {
        const { view, field } = await residents()

        await view.type(field, 'Mary ')
        mock.timers.tick(250)

        view.props.searchValue = 'Mary'
        await nextTick()

        assert.equal(field.value, 'Mary ', 'the space the reader typed is still there')

        await view.type(field, 'Mary Brown')
        mock.timers.tick(250)

        assert.equal(searches().at(-1), 'Mary Brown', 'two-word names can be searched')

        view.unmount()
    })

    it('still follows a filter changed from elsewhere — a phase chip, the back button', async () => {
        const { view, field } = await residents('Mary')

        assert.equal(field.value, 'Mary')

        view.props.searchValue = 'Jones'
        await nextTick()

        assert.equal(field.value, 'Jones', 'the server changed the filter, and the field agrees')

        view.props.searchValue = ''
        await nextTick()

        assert.equal(field.value, '')

        view.unmount()
    })

    it('carries the screen\'s other filters with the query', async () => {
        const { view, field } = await residents()

        await view.type(field, 'Lot 4')
        mock.timers.tick(250)

        assert.deepEqual(visits.at(-1).data, { phase: 'Phase 2', q: 'Lot 4' })

        view.unmount()
    })
})

void byTag
