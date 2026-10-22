/**
 * The elections page: Open voting on an empty paper, and the committee ballot's card —
 * `Pages/Estate/Governance/ControlRoom.vue` (M70 §1.2; E2E-M7, step 7).
 *
 *   E2E-M7: with nobody approved for any seat, Open voting stayed live and the server refused it with a red line.
 *   Hassam: "That sentence is well written. Use it. Disable the button and make that its reason." The server sends the
 *   names on the paper (`onPaper`) and its own sentence (`emptyPaperReason`), and the button is off with it.
 *
 *   The committee ballot (M62 A9, the server's 4dc63878): a motion put to a roll of seven households, from its draft
 *   straight to the vote with no nominations stage. Its card reads `committee_ballots`: the motion, the households it is
 *   put to (the server's count, 7), the papers in, the choices; Open voting and Close voting each ask first, naming the
 *   count and the channel.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: ControlRoom } = await import('../../resources/js/Pages/Estate/Governance/ControlRoom.vue')

const EMPTY = 'Nobody is on the paper for 2031 Election: no candidate has been approved for any of its seats. A poll opened now would give every household nothing to mark.'

const PROPS = {
    sourceBadge: { source: 'resident', simulated: false },
    estate: { name: 'Phoenix Park' },
    year: 2031,
    heading: '2031 Election',
    subtitle: '2031 Election',
    stage: 'candidate_vetting',
    stage_label: 'Candidate Vetting',
    stepper: [],
    stats: [],
    positions: [{ id: 1, ballot_id: 4, name: 'Chair', edit_name: 'Chair', seats: 1, seat_label: '1 seat', nominated: 0, nominated_label: '0 nominated' }],
    pending: 0,
    undecided: 0,
    ballotId: 4,
    canCloseEarly: false,
    certified: false,
    canRun: true,
    canCertify: false,
    blockedReason: '',
    reasons: { minutes: null },
    minutedMeetings: [],
    defaultClosesAt: '2026-10-20T18:00',
    eligibleHouseholds: 443,
    onPaper: 0,
    emptyPaperReason: EMPTY,
}

const MOTION = {
    id: 41, code: 'C', title: 'Committee motion: gate contract renewal', question: 'Should the committee renew the gate contract with Island Security for 2027?',
    stage: 'draft', stage_label: 'Draft', certified: false, published: false, eligible_households: 7, papers_in: 0, choices: ['For', 'Against', 'Abstain'],
}

let user = 71000

const open = async (overrides = {}) => {
    resetInertia({ auth: { user: { id: ++user, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/elections/2031')
    const view = await mount(ControlRoom, { ...PROPS, ...overrides })
    await flush(nextTick)

    const button = (label, within = view.root) => findAll(within, (el) => el.tag === 'button' && textOf(el).trim() === label)[0]
    const card = () => view.one(byClass('cb-card'))
    const asking = () => view.all((el) => el.attrs?.role === 'dialog')[0]
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, button, card, asking, inAsk, posts }
}

describe('Open voting on an empty paper (E2E-M7)', () => {
    it('is off, and its reason is the server\'s own sentence', async () => {
        const { view, button } = await open()
        const primary = button('Open voting')

        assert.ok(primary, 'the stage\'s button')
        assert.ok(primary.attrs.disabled, 'off with nobody on the paper')
        assert.equal(primary.attrs.title, EMPTY)
        assert.match(textOf(view.one(byClass('act-why'))), /Nobody is on the paper for 2031 Election/)
    })

    it('is on once somebody is on the paper', async () => {
        const { button } = await open({ onPaper: 2, emptyPaperReason: null })

        assert.ok(!button('Open voting').attrs.disabled)
    })
})

describe('the committee ballot card (M62 A9, step 7)', () => {
    it('draws the motion, the 7 households it is put to, the papers in and its choices', async () => {
        const { card } = await open({ stage: 'certified', stage_label: 'Certified', committee_ballots: [MOTION] })

        assert.ok(card(), 'a card for the motion')
        const text = textOf(card())
        assert.match(text, /Committee motion: gate contract renewal/)
        assert.match(text, /Should the committee renew the gate contract/)
        assert.match(text, /Put to 7 households/)
        assert.match(text, /0 of 7 papers in/)
        assert.match(text, /For · Against · Abstain/)
    })

    it('goes from its draft straight to the vote: Open voting asks first, naming the 7 and the channel', async () => {
        const { card, button, asking, inAsk, posts, view } = await open({ stage: 'certified', stage_label: 'Certified', committee_ballots: [MOTION] })

        assert.equal(button('Open nominations', card()), undefined, 'no nominations stage')
        await view.click(button('Open voting', card()))

        assert.equal(posts().length, 0, 'nothing is posted on the first press')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Open voting to 7 households?')
        assert.match(textOf(inAsk('cd-body')), /They are told in the app, and by email where they asked for it\. No other household sees it\./)

        await view.click(inAsk('cd-confirm'))
        assert.equal(posts().length, 1)
        assert.match(posts()[0].url, /\/governance\/ballots\/41\/open$/)
        assert.equal(posts()[0].data.closes_at, '2026-10-20T18:00')
        assert.ok(asking() === undefined || posts().length === 1)
    })

    it('while voting is open, Close voting asks first, with the papers in', async () => {
        const { card, button, inAsk, posts, view } = await open({ stage: 'certified', stage_label: 'Certified', committee_ballots: [{ ...MOTION, stage: 'voting_open', stage_label: 'Voting Open', papers_in: 1 }] })

        assert.match(textOf(card()), /1 of 7 papers in/)
        await view.click(button('Close voting', card()))

        assert.equal(posts().length, 0)
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Close voting on Committee motion: gate contract renewal?')
        await view.click(inAsk('cd-confirm'))
        assert.match(posts()[0].url, /\/governance\/ballots\/41\/close$/)
    })

    it('draws no card in a year with no motion', async () => {
        const { card } = await open({ committee_ballots: [] })

        assert.equal(card(), null)
    })
})

/*
 * PUBLISH RESULTS (M72 §1.3 step 7). After Close voting a motion sits at the tally, and the card had no act there: the
 * results page shows the year's lead ballot, not the motion. Its card now publishes it (the certify route, which
 * certifies and publishes in one press), asking first: the count (`tally`, the server's, once the poll has closed),
 * the 7 households on its roll, and the channels. Nobody else is told (`PushAnnouncer::electionResults`).
 */
describe('Publish results on the committee ballot card (step 7)', () => {
    const CLOSED = { ...MOTION, stage: 'tally', stage_label: 'Tally', papers_in: 3, tally: [{ label: 'For', votes: 2 }, { label: 'Against', votes: 1 }, { label: 'Abstain', votes: 0 }] }
    const WHOSE = 'Publishing a result needs the Super Admin, the President or the Vice President.'

    it('shows the count, and Publish results asks first, naming the count, the 7 and the channels', async () => {
        const { card, button, inAsk, posts, view } = await open({ stage: 'certified', stage_label: 'Certified', canCertify: true, certifyBlockedReason: WHOSE, committee_ballots: [CLOSED] })

        assert.match(textOf(card()), /For 2 · Against 1 · Abstain 0/)
        await view.click(button('Publish results', card()))

        assert.equal(posts().length, 0, 'nothing is published on the first press')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Publish the result of Committee motion: gate contract renewal?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /The count: For 2, Against 1, Abstain 0\./)
        assert.match(body, /The 7 households on the committee's roll are told in the app, and by email where they asked for it\. Nobody else is told\./)
        assert.match(body, /It is certified as it stands and cannot be changed after this\./)

        await view.click(inAsk('cd-confirm'))
        assert.equal(posts().length, 1)
        assert.match(posts()[0].url, /\/governance\/ballots\/41\/certify$/)
    })

    it('still names who is told before the server sends the count', async () => {
        const { card, button, inAsk, view } = await open({ stage: 'certified', stage_label: 'Certified', canCertify: true, certifyBlockedReason: WHOSE, committee_ballots: [{ ...CLOSED, tally: null }] })

        await view.click(button('Publish results', card()))
        const body = textOf(inAsk('cd-body'))
        assert.doesNotMatch(body, /The count/)
        assert.match(body, /The 7 households on the committee's roll are told in the app/)
    })

    // M96 level 3 (Hassam, 11 Oct): an act a role can never take is not drawn at all; the card's stage says where it is.
    it('is not drawn for a role that can never certify, and the card says where the motion stands', async () => {
        const { card, button, asking, posts } = await open({ stage: 'certified', stage_label: 'Certified', canCertify: false, certifyBlockedReason: WHOSE, committee_ballots: [CLOSED] })

        assert.equal(button('Publish results', card()), undefined, 'no button for a role that can never publish a result')
        assert.match(textOf(card()), /Under review/)
        assert.equal(asking(), undefined)
        assert.equal(posts().length, 0)
    })

    it('has no act once the result is published', async () => {
        const { card, button } = await open({ stage: 'certified', stage_label: 'Certified', canCertify: true, certifyBlockedReason: WHOSE, committee_ballots: [{ ...CLOSED, stage: 'certified', stage_label: 'Certified & Published', certified: true, published: true }] })

        assert.equal(button('Publish results', card()), undefined)
        // The stage in the vocabulary's words (M96 §2): a certified result reads Completed.
        assert.match(textOf(card()), /Completed/)
        assert.match(textOf(card()), /For 2 · Against 1 · Abstain 0/)
    })
})
