/**
 * Publish asks first — `Pages/Estate/Governance/Meeting.vue` (M62 B1, DR-007).
 *
 *   Publishing tells households at once and cannot be taken back. So Publish opens a confirmation naming how many
 *   households are told, the server's own count (`meeting.households_told`, the same number the meeting's panel reads
 *   as "This tells N households"; never the 1,999 lots), the meeting and when it is, and posts nothing. A committee's own
 *   meeting tells no household and says so. Cancel posts nothing. Only the dialog's own "Publish" posts, once.
 *   A publication is a push, and ResidentEmails sends the same news by email to whoever turned Email on (off by
 *   default), so the dialog names both channels and no more.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Meeting } = await import('../../resources/js/Pages/Estate/Governance/Meeting.vue')

const MEETING = {
    id: 9, title: 'General Meeting', type: 'agm', type_label: 'Annual general meeting', stage: 'draft', stage_label: 'Draft',
    when: '18 Oct 2026, 6:00 PM', starts_local: '2026-10-18T18:00', earliest_local: '2026-10-17T18:00', duration_minutes: 120,
    description: '', held_as: 'in_person', venue: 'Community Centre', virtual_link: null, platform_name: null,
    recording_enabled: false, recording: null, audience: 'Every household', phase: null, phases: [],
    households_told: 443, counts_households: true, publish_refusal: null, move_label: null, acts: [], register: [],
    agenda_items: [], agenda: [], notice: null, notice_line: null, cancelled: null, postponed: null, archived: null,
    quorum: { state: 'upcoming', label: 'Counted at the meeting', basis: 'households', required: 111, present: 0, apologies: 0, absent: 0 },
    rsvps: { attending: 0, apologies: 0, not_attending: 0, rows: [] },
    minutes: null, has_minutes: false, can_minute: false,
}

let user = 63000

// The meeting's panel reads `?act=` off the address (a deep link to one of its dialogs); none here.
globalThis.window.location ??= { search: '', pathname: '/governance/meetings/9' }

const open = async (meeting = {}, publishReach = { committee: false, members: null }) => {
    resetInertia({ auth: { user: { id: ++user, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null }, '/governance/meetings/9')
    const view = await mount(Meeting, {
        estate: { name: 'Phoenix Park' },
        meeting: { ...MEETING, ...meeting },
        documents: [],
        canPublish: true,
        publishReason: '',
        publishReach,
    })
    await flush(nextTick)

    const asking = () => view.all((el) => el.attrs?.role === 'dialog').find((d) => /^Publish/.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d).trim()))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')
    const press = async () => view.click(view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Publish' && byClass('btn-primary-sm')(el)))

    return { view, asking, inAsk, posts, press }
}

describe('Publish asks first (DR-007)', () => {
    it('names how many households are told, the meeting and when, and posts nothing', async () => {
        const { asking, inAsk, posts, press } = await open()
        await press()

        assert.equal(posts().length, 0, 'nothing is posted on the first press')
        assert.ok(asking(), 'a confirmation opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Publish to 443 households?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /General Meeting · 18 Oct 2026, 6:00 PM\./)
        // ResidentEmails sends every Resident App push by email too, to whoever turned Email on (off by default).
        assert.match(body, /They are told in the app, and by email where they asked for it\. You cannot unsend this\./)
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Publish')
    })

    // M65 §3: the proof that the eligibility rule is real lives here. The committee case resolves to the server's count of
    // the committee (7), the general case to its count of households (443), both sent by the server, never counted here.
    it("a committee's own meeting resolves to the committee's 7 members and tells no household", async () => {
        const { inAsk, press } = await open({ households_told: 0, audience: 'Committee members' }, { committee: true, members: 7 })
        await press()

        assert.equal(textOf(inAsk('cd-title')).trim(), 'Publish to the committee?')
        assert.match(textOf(inAsk('cd-body')), /The 7 committee members see it in the console\. No household is told\. You cannot unsend this\./)
    })

    it('a phase with nobody living on it says that no household is told', async () => {
        const { inAsk, press } = await open({ households_told: 0, audience: 'Phase 5 only' })
        await press()

        assert.equal(textOf(inAsk('cd-title')).trim(), 'Publish to 0 households?')
        assert.match(textOf(inAsk('cd-body')), /No household is told\. You cannot unsend this\./)
    })

    it('Cancel closes it and posts nothing', async () => {
        const { view, asking, inAsk, posts, press } = await open()
        await press()
        await view.click(inAsk('cd-cancel'))

        assert.equal(asking(), undefined, 'the confirmation is gone')
        assert.equal(posts().length, 0, 'nothing was posted')
    })

    it("the dialog's Publish posts once, and it is the meeting's publish", async () => {
        const { view, inAsk, posts, press } = await open()
        await press()
        assert.equal(posts().length, 0)
        assert.ok(inAsk('cd-confirm'), 'the dialog asks')
        await view.click(inAsk('cd-confirm'))

        assert.equal(posts().length, 1, 'one post')
        assert.match(posts()[0].url, /\/governance\/meetings\/9\/publish$/)
    })
})

describe('arriving from the register to publish (DR-056)', () => {
    // The register's own Publish now opens the meeting with ?act=publish, the house pattern for a row's act: the question
    // is asked here, with the server's count, and nowhere does a first press tell anybody.
    it('opens the question on arrival, and nothing is posted', async () => {
        globalThis.window.location.search = '?act=publish'
        try {
            const { asking, inAsk, posts } = await open()

            assert.ok(asking(), 'the question is open on arrival')
            assert.equal(textOf(inAsk('cd-title')).trim(), 'Publish to 443 households?')
            assert.equal(posts().length, 0)
        } finally {
            globalThis.window.location.search = ''
        }
    })
})

describe('Publish minutes asks first (DR-054)', () => {
    // The same fault as DR-007 (M65 1.2): "Minutes are available" goes to the meeting's households at the first press.
    // The question names the server's own count: the households told, or, for a committee's meeting, the committee's 7.
    const HELD = {
        stage: 'held', stage_label: 'Held', can_minute: true, has_minutes: true,
        minutes: { body: 'The meeting resolved to repair the north gate.', adopted: 'Recorded 18 Oct 2026', recorded_by: 'Kayla Morrison', published: null },
    }
    const pressMinutes = async (view) => view.click(view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Publish minutes'))

    it('names how many households are told, and posts nothing', async () => {
        const { view, asking, inAsk, posts } = await open(HELD)
        await pressMinutes(view)

        assert.equal(posts().length, 0, 'nothing is posted on the first press')
        assert.ok(asking(), 'a confirmation opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Publish the minutes to 443 households?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /The minutes of General Meeting, held 18 Oct 2026, 6:00 PM\./)
        assert.match(body, /They are told in the app, and by email where they asked for it\. Once published, the minutes are the record\./)
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Publish minutes')
    })

    it("a committee's own meeting resolves to the committee's 7 and tells no household", async () => {
        const { view, inAsk } = await open({ ...HELD, households_told: 0, audience: 'Committee members' }, { committee: true, members: 7 })
        await pressMinutes(view)

        assert.equal(textOf(inAsk('cd-title')).trim(), 'Publish the minutes to the committee?')
        assert.match(textOf(inAsk('cd-body')), /The 7 committee members see them in the console\. No household is told\./)
    })

    it('Cancel closes it and posts nothing', async () => {
        const { view, asking, inAsk, posts } = await open(HELD)
        await pressMinutes(view)
        await view.click(inAsk('cd-cancel'))

        assert.equal(asking(), undefined)
        assert.equal(posts().length, 0)
    })

    it("the dialog's Publish minutes posts once, to the minutes", async () => {
        const { view, inAsk, posts } = await open(HELD)
        await pressMinutes(view)
        assert.equal(posts().length, 0)
        assert.ok(inAsk('cd-confirm'))
        await view.click(inAsk('cd-confirm'))

        assert.equal(posts().length, 1)
        assert.match(posts()[0].url, /\/governance\/meetings\/9\/minutes\/publish$/)
    })
})
