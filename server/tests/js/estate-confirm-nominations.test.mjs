/**
 * Open nominations asks first — `Pages/Estate/Governance/ControlRoom.vue` (M62 B1, DR-008).
 *
 *   The panel's "Open nominations" moved a drafted election into nominations at the first press, and nothing can be
 *   put right afterwards: its positions lock. So the press opens a confirmation naming how many households the election
 *   counts, the number the server sends (`eligibleHouseholds`, from Governance::eligibleHouseholdCount; 7 on the
 *   committee-only election), the election and when nominations close, and posts nothing. Cancel posts nothing. Only the
 *   dialog's own "Open nominations" posts, once.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: ControlRoom } = await import('../../resources/js/Pages/Estate/Governance/ControlRoom.vue')

const PROPS = {
    sourceBadge: { source: 'resident', simulated: false },
    estate: { name: 'Phoenix Park' },
    year: 2026,
    heading: 'Committee Election 2026',
    subtitle: 'Draft',
    stage: 'draft',
    stepper: [],
    stats: [],
    positions: [{ id: 1, ballot_id: 12, name: 'Chair', edit_name: 'Chair', seats: 1, seat_label: '1 seat', nominated: 0, nominated_label: 'No nominations yet' }],
    pending: 0,
    ballotId: 12,
    canCloseEarly: false,
    certified: false,
    canRun: true,
    canCertify: false,
    blockedReason: '',
    reasons: { minutes: null },
    minutedMeetings: [],
    defaultClosesAt: '2026-10-20T18:00',
    eligibleHouseholds: 443,
}

let user = 62000

const open = async (overrides = {}) => {
    resetInertia({ auth: { user: { id: ++user, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null }, '/governance/elections/2026')
    const view = await mount(ControlRoom, { ...PROPS, ...overrides })
    await flush(nextTick)

    const dialogs = () => view.all((el) => el.attrs?.role === 'dialog')
    const asking = () => dialogs().find((d) => /Open nominations/.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d)))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    // The stage's own button opens the dialog for when nominations close (M95 §2: a form that opens is the shared
    // dialog); its confirm is what used to post at once.
    const start = async () => {
        const primary = view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Open nominations' && !el.attrs?.type?.includes('submit'))
        await view.click(primary)
        const panel = dialogs().find((d) => textOf(findAll(d, byClass('cd-title'))[0] ?? d).trim() === 'Open nominations')
        assert.ok(panel, 'the dialog for when nominations close opens')
        await view.click(findAll(panel, byClass('cd-confirm'))[0])
        await nextTick()
    }

    return { view, asking, inAsk, posts, start }
}

describe('Open nominations asks first (DR-008)', () => {
    // Hassam, 10 Oct (M65 §2): say what actually happens, including the part that is missing. Opening nominations tells
    // nobody (no push) and the Resident App has no way to nominate, so the dialog says so and names no count.
    it('says what opening nominations changes and what it does not, and posts nothing', async () => {
        const { asking, inAsk, posts, start } = await open()
        await start()

        assert.equal(posts().length, 0, 'nothing is posted by the panel')
        assert.ok(asking(), 'a confirmation opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Open nominations for Committee Election 2026?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /Nominations can be recorded until 20 Oct 2026, 6:00 PM, and the positions lock\./)
        assert.match(body, /Residents are not notified and cannot nominate from the app\./)
        assert.doesNotMatch(body, /443/, 'no count: nobody is told, so a number would imply an audience')
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Open nominations')
    })

    it('Cancel closes it and posts nothing', async () => {
        const { view, asking, inAsk, posts, start } = await open()
        await start()
        await view.click(inAsk('cd-cancel'))

        assert.equal(asking(), undefined, 'the confirmation is gone')
        assert.equal(posts().length, 0, 'nothing was posted')
    })

    it("the dialog's Open nominations posts once, with the closing time", async () => {
        const { view, inAsk, posts, start } = await open()
        await start()
        assert.equal(posts().length, 0)
        assert.ok(inAsk('cd-confirm'), 'the dialog asks')
        await view.click(inAsk('cd-confirm'))

        assert.equal(posts().length, 1, 'one post')
        assert.match(posts()[0].url, /\/governance\/ballots\/12\/open-nominations$/)
        assert.equal(posts()[0].data.closes_at, '2026-10-20T18:00')
    })
})

describe('Open voting names the households it tells, from the server (M65 §3)', () => {
    // Opening the poll tells households in the app and freezes the count, so its panel names the server's own number:
    // whatever Governance::eligibleHouseholdCount returns, 443 on a general election, 7 on the committee-only one.
    const openVoting = async (overrides = {}) => {
        const { view } = await open({ stage: 'campaign_period', undecided: 0, ...overrides })
        const primary = view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Open voting')
        await view.click(primary)
        const panel = view.all((el) => el.attrs?.role === 'dialog').find((d) => textOf(findAll(d, byClass('cd-title'))[0] ?? d).trim() === 'Open voting')
        assert.ok(panel, 'the dialog for when voting closes opens')

        return textOf(findAll(panel, byClass('cr-ask'))[0]).trim()
    }

    // "Voting is open" is a push (`election_open`), and ResidentEmails sends it by email to whoever turned Email on.
    it('the general election: 443 households', async () => {
        assert.match(await openVoting(), /^Voting opens to 443 households, who are told in the app, and by email where they asked for it\./)
    })

    it('the committee-only election: 7', async () => {
        assert.match(await openVoting({ eligibleHouseholds: 7 }), /^Voting opens to 7 households, who are told in the app, and by email where they asked for it\./)
    })
})
