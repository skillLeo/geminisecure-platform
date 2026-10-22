<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import ElectionLife from '../../../Components/ElectionLife.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'
import { ballotStageWord, otherPapers, stepWord } from '../../../lib/electionWords.js'

/**
 * The election control room — board screen community-admin-09.
 *
 * ONE ELECTION, TWO PAPERS, ONE LIFECYCLE BADGE. Board 9's own subtitle says an
 * election is "Ballot A (Community Executive) & Ballot B (Phase Leadership)",
 * and the stepper above it draws a single stage. `Governance::leadBallot()`
 * resolves that by speaking for the LEAST advanced paper: an election is not at
 * "Voting Open" while one of its ballots is still taking nominations. So this
 * screen runs Ballot B while board 11 tallies Ballot A, which is exactly the
 * pair of moments D-051 seeded.
 *
 * NOT ONE FIGURE HERE IS ARITHMETIC THIS FILE DOES. The four stat boxes, the
 * nine stepper states, the seat labels and the per-position nomination counts
 * all arrive formatted from `Governance::controlRoom()`, which counts them from
 * rows. A screen that recomputed any of them would be a second opinion about an
 * election, and the two would disagree the first time somebody changed one.
 *
 * THE PRIMARY BUTTON IS WHATEVER THIS STAGE'S NEXT ACT IS, and there is only
 * ever one of it. Board 9 catches the election at "Nominations Open", so it
 * draws "Close nominations early" — but the same slot carries "Open voting"
 * once candidates are vetted and "Close voting" once the poll is running,
 * because those are the acts a returning officer takes from this screen and the
 * service will refuse every other one. Where a stage has no wired act, the
 * button says so rather than disappearing: a control room with no control on it
 * reads as a screen that failed to load.
 *
 * THERE IS NO CONTROL HERE THAT CASTS A VOTE, and there is no route that could
 * back one. Voting is a resident act in the resident app; this console runs the
 * election and never marks a paper. Nothing on this screen reads
 * `ballot_receipts` or `ballot_marks` at all — the closest it comes is
 * "ELIGIBLE HOUSEHOLDS", which is a denominator and not a record of anybody
 * having voted.
 *
 * CERTIFICATION IS NOT ON THIS SCREEN even though the payload says whether the
 * viewer holds it. It is board 11's single button, taken beside the tally it
 * declares final, and a second door to an irreversible act — on a screen with
 * no tally on it — is the kind of button somebody presses by mistake once.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    estate: { type: Object, required: true },
    year: { type: Number, required: true },

    /** "2026-10-15T18:00": where "Voting closes" starts, a week out on the estate's clock (M53 §4.11). */
    defaultClosesAt: { type: String, default: '' },

    /** Nominations on this ballot neither approved nor rejected — what Open voting waits on (M53 §13.1). */
    undecided: { type: Number, default: 0 },

    /** The names on the lead ballot's paper (M70 §1.2): none, and Open voting is off with `emptyPaperReason`. */
    onPaper: { type: Number, default: null },
    /** The sentence `Governance::open()` refuses an empty paper with, word for word, from the server. */
    emptyPaperReason: { type: String, default: null },

    /** The committee's motions this year (M62 A9): each card's motion, households, papers in and choices. */
    committee_ballots: { type: Array, default: () => [] },

    /** "2026 Election" — the estate's name is prefixed onto it below. */
    heading: { type: String, required: true },
    subtitle: { type: String, required: true },
    stage: { type: String, required: true },
    stage_label: { type: String, required: true },

    /** Nine steps, each done / current / upcoming. */
    stepper: { type: Array, required: true },
    /** The year's election papers, each with its stage, and which one the stage above is (`Governance::papersOf()`). */
    papers: { type: Array, default: () => [] },
    stats: { type: Array, required: true },
    positions: { type: Array, required: true },
    pending: { type: Number, required: true },

    /**
     * The ballot this screen speaks for — and the one every configuring write is
     * posted to (B043). Null for a year with no ballots at all, which is what
     * `empty` draws; a drafted ballot with no position yet is NOT empty, it is
     * the first thing the editor below is for (B042).
     */
    ballotId: { type: Number, default: null },

    /** How many households this election counts, from Governance::eligibleHouseholdCount (M62 B1). Null with no ballot. */
    eligibleHouseholds: { type: Number, default: null },

    /** Its own title and description, for the rename form. Null with no ballot. */
    ballot: { type: Object, default: null },
    canCloseEarly: { type: Boolean, required: true },
    certified: { type: Boolean, required: true },

    /** Every year with an election, newest first — so none is reachable only by URL. */
    years: { type: Array, default: () => [] },

    /** Whether this viewer may draft an election (Governance create), and why not. */
    canCreate: { type: Boolean, default: false },
    createReason: { type: String, default: '' },

    /** Adding a position is `create`; editing, removing and reordering are `update`. */
    canAddPosition: { type: Boolean, default: false },
    canEditPositions: { type: Boolean, default: false },

    canRun: { type: Boolean, required: true },

    /*
     * Declared and deliberately not rendered — see the note above. An
     * undeclared prop would fall through as an HTML attribute rather than be
     * ignored, so it is named here with its reason instead of dropped.
     */
    canCertify: { type: Boolean, required: true },
    /** Whose act publishing a result is, said on a motion's Publish results when this viewer cannot (M72 step 7). */
    certifyBlockedReason: { type: String, default: '' },

    /** M56 §3: the acts beside the stage's own (cancel, pause and reopen, extend, delete a draft), and who may. */
    life: { type: Object, default: null },
    canDelete: { type: Boolean, default: false },

    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },

    /**
     * Whether the positions may be configured (M10 §7).
     *
     * Permission AND draft. The permission decides who may; the stage decides
     * whether anybody may at all, because once nominations open somebody has
     * stood for a named seat.
     */
    canConfigure: { type: Boolean, default: false },
    configureBlockedReason: { type: String, default: '' },

    /** M56 §3: a draft, or nominations open or paused. A seat can still be added, and one nobody stood for removed. */
    positionsOpen: { type: Boolean, default: false },

    /** The meetings minuted in this year — what "Export minutes" offers. */
    minutedMeetings: { type: Array, required: true },
})

/* ------------------------------------------------------------------ */
/* the positions — typed, not chosen from a list (M10 §7)              */
/* ------------------------------------------------------------------ */

/*
 * EVERY WRITE BELOW IS POSTED TO THE BALLOT THIS SCREEN IS SHOWING (B043), by
 * `ballotPath()`. They used to go to the year, which the server read as the
 * year's OLDEST ballot while this screen drew its least advanced — so the editor
 * on a new draft saved onto another paper, or was refused in that paper's name.
 */

/** The rows on the ballot being configured. A year's other paper is drawn and never edited here. */
const ownPositions = computed(() => props.positions.filter((position) => position.ballot_id === props.ballotId))

const editable = (position) => props.canConfigure && position.ballot_id === props.ballotId

const newPosition = useForm({ name: '', seats: 1, scope: 'estate', phase: null })

const addPosition = () =>
    newPosition.post(ballotPath('/positions'), {
        preserveScroll: true,
        onSuccess: () => newPosition.reset(),
    })

/*
 * REORDERING (M10 §7, B042). One step up or down, and the whole set is sent —
 * the service takes every position on the ballot or none, so the paper's order
 * is always somebody's decision and never a tie-break.
 */
const reorder = useForm({ order: [] })

const move = (position, step) => {
    const ids = ownPositions.value.map((row) => row.id)
    const from = ids.indexOf(position.id)
    const to = from + step

    if (from === -1 || to < 0 || to >= ids.length) {
        return
    }

    ;[ids[from], ids[to]] = [ids[to], ids[from]]

    reorder.order = ids
    reorder.post(ballotPath('/positions/order'), { preserveScroll: true })
}

/*
 * RENAMING THE ELECTION (M10 §7, B042) — its title and the description a
 * household reads, while it is a draft.
 */
const renaming = ref(false)
const renameForm = useForm({ title: '', description: '' })

const startRename = () => {
    renameForm.title = props.ballot?.title ?? ''
    renameForm.description = props.ballot?.description ?? ''
    renameForm.clearErrors()
    renaming.value = true
}

const saveRename = () =>
    renameForm.post(ballotPath('/rename'), {
        preserveScroll: true,
        onSuccess: () => (renaming.value = false),
    })

/*
 * DRAFTING AN ELECTION (M10 §7, B042). There was no form for it at all: the
 * route existed and nothing posted to it, and this screen told a Secretary the
 * act was "not one this console takes yet". A year holds one election, and the server refuses a second.
 *
 * NOTHING IS TYPED IN FOR THE SECRETARY (Hassam, 12 Oct, batch 1 item 7): the dialog opened filled with "2031" and
 * "2031 Election", values nobody chose. It opens empty; the likely year (the one on screen when it has no election, the
 * year after the latest otherwise) is offered as an example in the placeholders, and Draft waits for both.
 */
const creating = ref(false)
const electionForm = useForm({ year: '', title: '', description: '' })

const suggestedYear = computed(() => (props.ballotId === null ? props.year : Math.max(props.year, ...props.years) + 1))

const openCreate = () => {
    electionForm.year = ''
    electionForm.title = ''
    electionForm.description = ''
    electionForm.clearErrors()
    creating.value = true
}

/** Why Draft election is greyed: the year and the name are asked for (Law 1). */
const draftBlockedBy = computed(() => {
    if (String(electionForm.year ?? '').trim() === '') {
        return 'Enter the year the election is for.'
    }

    return electionForm.title.trim() === '' ? 'Give the election a name.' : null
})

const submitElection = () => {
    if (!props.canCreate) {
        return
    }

    electionForm.post(governance('/elections'), {
        onSuccess: () => (creating.value = false),
    })
}

/** Which row is open in the editor, by id. Null when none is. */
const editing = ref(null)

const editForm = useForm({ name: '', seats: 1 })

const startEdit = (position) => {
    editing.value = position.id
    // `edit_name` and not `name`: the latter is displayName(), which prefixes a
    // phase-scoped seat with its phase, and saving that back would write the
    // prefix into the title.
    editForm.name = position.edit_name ?? position.name
    editForm.seats = position.seats ?? 1
    editForm.clearErrors()
}

const saveEdit = () =>
    editForm.post(`${root.value}/governance/positions/${editing.value}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    })

/*
 * Removal is a POST rather than a DELETE because the whole console posts, and
 * it is confirmed first because it is not reversible from this screen. The
 * service refuses it outright once anybody has been nominated, and that
 * sentence (`errors.position`) is shown inside the dialog.
 *
 * The console's own dialog, not the browser's window.confirm (24 September 2026).
 */
const removing = ref(null)
const removeForm = useForm({})

const remove = (position) => {
    removeForm.clearErrors()
    removing.value = position
}

const confirmRemove = () => {
    if (!removing.value || removeForm.processing) {
        return
    }

    removeForm.post(`${root.value}/governance/positions/${removing.value.id}/remove`, {
        preserveScroll: true,
        onSuccess: () => (removing.value = null),
    })
}

/*
 * "Export minutes" (12 §2, item 33). A ballot records no meeting, so the
 * reader chooses which meeting's minutes; each is issued as the same queued,
 * seven-year PDF the meeting register issues. Closed on a fresh GET.
 */
const minutesOpen = ref(false)
const minutesForm = useForm({})

const issueMinutes = (meeting) =>
    minutesForm.post(`${root.value}/governance/meetings/${meeting.id}/minutes`, {
        preserveScroll: true,
        onSuccess: () => (minutesOpen.value = false),
    })

/** The meeting chosen in the dialog (M95 §2: a form that opens is the shared dialog), the latest minuted one first. */
const minutesChoice = ref(null)

const openMinutes = () => {
    minutesChoice.value = props.minutedMeetings[0]?.id ?? null
    minutesForm.clearErrors()
    minutesOpen.value = true
}

const issueChosenMinutes = () => {
    const meeting = props.minutedMeetings.find((each) => each.id === minutesChoice.value)

    if (meeting) {
        issueMinutes(meeting)
    }
}

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so a page that names
 * the wrong one renders with no lifecycle card, no stepper and no stat row at
 * all. Boards 9 to 12 live on this sheet; board 36 lives on another.
 */
useWireframe('community-admin-03-elections-nominations-results-and-meetings')

const page = usePage()

/*
 * Five of the six. The board draws no search box and no filter chips — the
 * position list is the whole position list — so `empty-filtered` cannot occur
 * here, and rendering a "clear the filter" panel on a screen with no filter
 * would invent a control in order to explain a state that does not exist. The
 * composable still carries it and `?_state=` still forces the other five.
 *
 * `empty` is a real state and not a hypothetical: a year nobody has drafted a
 * ballot for returns no positions, no lead ballot and a stepper stuck at Draft.
 *
 * EMPTY MEANS NO BALLOT, NOT NO POSITIONS (B042). This used to count positions,
 * so a ballot drafted a moment ago — which has none until somebody adds one —
 * drew "No ballot has been drafted", hid the very editor that adds the first
 * position, and hid the flash telling the Secretary to add it. Removing a
 * draft's last position did the same. A ballot is what makes this screen
 * populated.
 */
const state = useScreenState({
    rows: () => (props.ballotId === null ? 0 : 1),
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/phoenixpark/governance/elections/2026. The URL that served this page
 * already carries whichever shape this environment uses, so cutting it at
 * /governance is correct in both — a hard-coded root is correct in exactly one,
 * and cutting the current URL cannot address an estate other than the one
 * already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

const nominationsHref = computed(() => governance(`/elections/${props.year}/nominations`))
// The nominations Open voting waits on, and only those (M56 §3.4).
const undecidedHref = computed(() => governance(`/elections/${props.year}/nominations?status=undecided`))
const resultsHref = computed(() => governance(`/elections/${props.year}/results`))
const meetingsHref = computed(() => governance('/meetings'))

const ballotPath = (suffix) => governance(`/ballots/${props.ballotId}${suffix}`)

/**
 * The board's own card title: the estate, then the election.
 *
 * "Phoenix Park Village 1 — 2026 Election". Composed here rather than on the
 * server because `heading` is the election's name and the estate's name is the
 * shell's — joining them is this card's own presentation of two facts it is
 * already given, not a third fact.
 */
const cardTitle = computed(() => `${props.estate.name} · ${props.heading}`)

/** The paper the stage and stepper above speak for, when the year has more than one (Security's note, 12 Oct). */
const shownPaper = computed(() => (props.papers.length > 1 ? props.papers.find((paper) => paper.shown) ?? null : null))

/**
 * The board's outline button, with the count inside its label.
 *
 * The parenthetical goes when nothing is pending, rather than reading "(0
 * pending)". A queue with nothing in it is still worth opening — every decided
 * nomination is there — but advertising a nought is how a screen tells somebody
 * there is work waiting when there is not.
 */
const reviewLabel = computed(() =>
    props.pending > 0 ? `Review nominations (${props.pending} pending)` : 'Review nominations'
)

/* ------------------------------------------------------------------ */
/* the one act this stage allows */
/* ------------------------------------------------------------------ */

/*
 * The transitions this console takes, one per stage.
 *
 * `Governance::TRANSITIONS` is the whole lifecycle and this console wires six
 * of its steps: open nominations, close them, start vetting, open the poll,
 * close it, extend it. Opening nominations and starting vetting had a service
 * method and no route, so a drafted ballot could never leave draft and one whose
 * nominations were closed early could never reach a poll (B042); both are wired.
 *
 * The remaining two are not omissions at all. Certification is board 11's, and
 * "Tally" advances to it from there; "Certified & Published" is the end.
 */
const NO_POSITION_YET =
    'Add at least one position first. Nominations are lodged for a named seat, and no seat can be added once they open.'

const CERTIFIED_IS_FINAL =
    'This election is certified, and that cannot be undone: a result that turns out to be wrong is put right by running another ballot, not by reopening this one. There is no step left to take here.'

/**
 * What the primary button does here, and why it cannot when it cannot.
 *
 * ONE ACT PER STAGE, taken from the lifecycle rather than from a preference.
 * The service refuses everything else with a sentence; this reads the same map
 * a step earlier so the refusal is legible before anything is pressed rather
 * than after.
 */
const act = computed(() => {
    if (props.ballotId === null) {
        return { key: 'none', label: 'Pause nominations', blocked: 'There is no ballot for this year yet.' }
    }

    if (props.certified) {
        return { key: 'none', label: 'Certified & published', blocked: CERTIFIED_IS_FINAL }
    }

    switch (props.stage) {
        case 'draft':
            return ownPositions.value.length > 0
                ? { key: 'open_nominations', label: 'Open nominations' }
                : { key: 'none', label: 'Open nominations', blocked: NO_POSITION_YET }

        case 'nominations_open':
            /*
             * The board's own button. `canCloseEarly` is the service's answer
             * to the same question — the ballot is at Nominations Open and is
             * not certified — and it is asked rather than assumed, because it
             * is the flag `controlRoom()` computes for exactly this control.
             */
            return props.canCloseEarly
                ? { key: 'close_nominations', label: 'Pause nominations' }
                : { key: 'none', label: 'Pause nominations', blocked: CERTIFIED_IS_FINAL }

        case 'nominations_closed':
            return { key: 'vetting', label: 'Start candidate vetting' }

        case 'candidate_vetting':
        case 'campaign_period':
            // Law 1 (M53 §13.1): disabled, with the one line that says why, while any nomination is undecided.
            if (props.undecided > 0) {
                return { key: 'open', label: 'Open voting', blocked: `${props.undecided} ${props.undecided === 1 ? 'nomination still needs' : 'nominations still need'} a decision.` }
            }

            // E2E-M7 (M70 §1.2): nobody on the paper. Off, with the server's own sentence, before anybody presses it.
            if (props.onPaper === 0 && props.emptyPaperReason) {
                return { key: 'open', label: 'Open voting', blocked: props.emptyPaperReason }
            }

            return { key: 'open', label: 'Open voting' }

        case 'voting_open':
            return { key: 'close', label: 'Close voting' }

        default:
            /*
             * Voting Closed and Tally. Neither is an act taken here: closing
             * the poll already advanced the ballot to the tally in one step —
             * "there is nothing a human does between shutting the poll and
             * counting it" — and what comes next is certification, which is a
             * second pair of hands on a different screen.
             */
            return {
                key: 'results',
                label: 'Review the tally',
                blocked: null,
            }
    }
})

/** The viewer's own access, which is true of every act on this screen. */
const accessBlockedBy = computed(() => (props.canRun ? null : props.blockedReason))

/**
 * Why the primary cannot be pressed, or null when it can.
 *
 * ACCESS IS ASKED FIRST, because it is the refusal that will not change
 * whatever anybody does here. Telling a committee member which stage the ballot
 * has to reach, and only then telling them they were never able to move it,
 * wastes their afternoon.
 */
const primaryBlockedBy = computed(() => {
    if (act.value.key === 'results') {
        return null
    }

    if (accessBlockedBy.value !== null) {
        return accessBlockedBy.value
    }

    return act.value.blocked ?? null
})

/*
 * THE COMMITTEE'S MOTIONS (M62 A9, the server's 4dc63878; M70 §1.2, step 7). A motion is put to a recorded roll of
 * households and to nobody else, and goes from its draft straight to the vote: there is no nominations stage. Its card
 * reads `committee_ballots`, and its count is the server's (`eligible_households`, the number every question names).
 * Opening and closing each ask first; opening tells the roll's households, by the app and by email where they asked.
 */
const households = (n) => `${Number(n ?? 0).toLocaleString('en-US')} household${n === 1 ? '' : 's'}`

/** The first sentence of a reason, for the line beside a greyed control (M96 level 2): the rest is on its title. */
const firstSentence = (text) => `${String(text ?? '').split('. ')[0].replace(/\.$/, '')}.`

const motionPath = (motion, suffix) => governance(`/ballots/${motion.id}${suffix}`)

const motionForm = useForm({ closes_at: '' })
const openingMotion = ref(null)
const closingMotion = ref(null)

const askOpenMotion = (motion) => {
    if (accessBlockedBy.value !== null) {
        return
    }

    motionForm.clearErrors()
    motionForm.closes_at = props.defaultClosesAt
    openingMotion.value = motion
}

const postOpenMotion = () => {
    if (openingMotion.value === null) {
        return
    }

    motionForm.post(motionPath(openingMotion.value, '/open'), {
        preserveScroll: true,
        onSuccess: () => {
            openingMotion.value = null
        },
    })
}

const askCloseMotion = (motion) => {
    if (accessBlockedBy.value !== null) {
        return
    }

    closeMotionRefusal.value = null
    closingMotion.value = motion
}

/*
 * A REFUSED CLOSE OR PUBLISH STAYS IN ITS QUESTION, IN THE AMBER BAND (Hassam, 12 Oct, the final refusal rule, D-235):
 * the question was the thing doing it. Both used to close and leave the refusal to a toast.
 */
const closeMotionRefusal = ref(null)
const publishMotionRefusal = ref(null)

const postCloseMotion = () => {
    if (closingMotion.value === null) {
        return
    }

    closeMotionRefusal.value = null
    router.post(motionPath(closingMotion.value, '/close'), {}, {
        preserveScroll: true,
        onSuccess: () => {
            closingMotion.value = null
        },
        onError: (errors) => {
            closeMotionRefusal.value = Object.values(errors ?? {})[0] ?? null
        },
    })
}

/*
 * PUBLISH RESULTS (M72 §1.3 step 7). Closing a motion leaves it at the tally, and the results page shows the year's
 * lead ballot, not the motion; so its own card publishes it, through the certify route, which certifies and publishes
 * in one press. Only the households on its roll are told (`PushAnnouncer::electionResults`). It asks first: the count
 * (`tally`, the server's, sent once the poll has closed), the roll and the channels.
 */
const tallyLine = (motion, separator) => (motion.tally ?? []).map((row) => `${row.label} ${row.votes}`).join(separator)

const publishingMotion = ref(null)
const publishingBusy = ref(false)

const askPublishMotion = (motion) => {
    if (!props.canCertify || publishingBusy.value) {
        return
    }

    publishMotionRefusal.value = null
    publishingMotion.value = motion
}

const postPublishMotion = () => {
    if (publishingMotion.value === null || !props.canCertify || publishingBusy.value) {
        return
    }

    publishingBusy.value = true
    publishMotionRefusal.value = null
    router.post(motionPath(publishingMotion.value, '/certify'), {}, {
        preserveScroll: true,
        onSuccess: () => {
            publishingMotion.value = null
        },
        onError: (errors) => {
            publishMotionRefusal.value = Object.values(errors ?? {})[0] ?? null
        },
        onFinish: () => {
            publishingBusy.value = false
        },
    })
}

/** Which authored panel is open: 'nominations', 'open', 'extend', or none. */
const panel = ref(null)

/*
 * ONE FORM FOR THE THREE DATE ACTS, because they take the same field for the
 * same reason: when a window shuts. `nominations` sets when nominations close,
 * `open` when the poll does, and `extend` may only move the poll's close later —
 * never earlier, because bringing a closing time forward takes the vote away
 * from every household relying on the date it was given, and the service says
 * so in as many words.
 */
const closing = useForm({ closes_at: '' })

const CLOSING_ROUTES = { nominations: '/open-nominations', open: '/open', extend: '/extend' }

const openPanel = (which) => {
    panel.value = panel.value === which ? null : which
    closing.clearErrors()

    // A week from today at 6:00 PM, estate time (M53 §4.11): the box never starts at "now", which shut the poll on opening.
    if (panel.value !== null && panel.value !== 'extend' && closing.closes_at === '' && props.defaultClosesAt) {
        closing.closes_at = props.defaultClosesAt
    }
}

const closePanel = () => {
    panel.value = null
}

const closeNominations = () => {
    if (primaryBlockedBy.value !== null) {
        return
    }

    router.post(ballotPath('/close-nominations'), {}, { preserveScroll: true })
}

const closeVoting = () => {
    if (primaryBlockedBy.value !== null) {
        return
    }

    router.post(ballotPath('/close'), {}, { preserveScroll: true })
}

const startVetting = () => {
    if (primaryBlockedBy.value !== null) {
        return
    }

    router.post(ballotPath('/vetting'), {}, { preserveScroll: true })
}

/** Press the primary: post outright, or open the panel the act needs a date in. */
const primary = () => {
    if (primaryBlockedBy.value !== null) {
        return
    }

    if (act.value.key === 'open_nominations') {
        openPanel('nominations')

        return
    }

    if (act.value.key === 'close_nominations') {
        closeNominations()

        return
    }

    if (act.value.key === 'vetting') {
        startVetting()

        return
    }

    if (act.value.key === 'open') {
        openPanel('open')

        return
    }

    if (act.value.key === 'close') {
        closeVoting()
    }
}

/**
 * Send the closing time, to whichever of the three routes the panel is for.
 *
 * A form submits on Enter from any field and a disabled button does not stop
 * it. Every route is gated on `estate.governance.update` and the service
 * refuses a close in the past and an extension that moves the closing time
 * earlier, so this is only about not sending a press that is going to bounce.
 */
/*
 * OPEN NOMINATIONS ASKS FIRST (M62 B1, DR-008). Leaving draft is a one-way door: the positions lock. So the panel's
 * press opens a confirmation naming how many households this election counts, the server's own number
 * (`eligibleHouseholds`, from Governance::eligibleHouseholdCount; 7 on the committee-only election), the election and
 * when nominations close, and only the dialog's own "Open nominations" posts. Cancel sends nothing.
 */
const askingNominations = ref(false)

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/** "2026-10-20T18:00" as the console writes it, "20 Oct 2026, 6:00 PM" (Law 5), read off the field: no clock shifts it. */
const asDateTime = (value) => {
    const [date, time = ''] = String(value).split('T')
    const [year, month, day] = date.split('-').map(Number)
    const [hours, minutes] = time.split(':').map(Number)

    if (!year || !month || !day) {
        return ''
    }

    const clock = Number.isFinite(hours) && Number.isFinite(minutes)
        ? `, ${((hours + 11) % 12) + 1}:${String(minutes).padStart(2, '0')} ${hours < 12 ? 'AM' : 'PM'}`
        : ''

    return `${day} ${MONTHS[month - 1]} ${year}${clock}`
}

/*
 * WHAT OPENING NOMINATIONS DOES, AND WHAT IT DOES NOT (Hassam, M65 §2). It lets nominations be recorded until the close
 * and locks the positions. It tells nobody: no push goes out, and the Resident App has no way to nominate. The dialog
 * says so, and names no count, because a number would imply an audience that is not there.
 */
const nominationsTitle = computed(() => `Open nominations for ${props.heading}?`)

const nominationsWhen = computed(() => `Nominations can be recorded until ${asDateTime(closing.closes_at)}, and the positions lock.`)

/*
 * OPENING THE POLL tells households in the app and freezes the count, so its panel names the server's own number
 * (`eligibleHouseholds`, Governance::eligibleHouseholdCount): 443 on a general election, 7 on the committee-only one.
 */
const panelHead = computed(() => {
    if (panel.value !== 'open') {
        return panelCopy.value.head
    }

    const count = props.eligibleHouseholds ?? 0

    // "Voting is open" is a push, and ResidentEmails sends it by email too, to whoever turned Email on.
    return `Voting opens to ${count.toLocaleString('en-US')} household${count === 1 ? '' : 's'}, who are told in the app, and by email where they asked for it. Candidates and that count lock once it opens.`
})

const postClosing = () => {
    closing.post(ballotPath(CLOSING_ROUTES[panel.value]), {
        preserveScroll: true,
        onSuccess: () => {
            askingNominations.value = false
            closePanel()
        },
        // A refusal stays in this question, in its band (D-235); Cancel goes back to the panel, its field's fault under it.
    })
}

const submitClosing = () => {
    if (accessBlockedBy.value !== null || props.ballotId === null || !(panel.value in CLOSING_ROUTES)) {
        return
    }

    if (panel.value === 'nominations') {
        askingNominations.value = true

        return
    }

    postClosing()
}

/** The panel's own sentence, field label and button, by which window it sets. */
const PANEL_COPY = {
    // One line each (M53 Law 2): what is true and what to do. The reasoning lives in the governance help.
    nominations: {
        head: 'Set when nominations close. The positions lock once you open them.',
        label: 'Nominations close',
        submit: 'Open nominations',
        hint: 'Open nominations.',
    },
    open: {
        head: 'Set when voting closes. Candidates and the eligible-household count lock once you open it.',
        label: 'Voting closes',
        // The panel's button confirms; the button that opened the panel says Open voting (M53 §4.11: not two alike).
        submit: 'Confirm and open',
        hint: 'Open the poll. Households vote in the Resident App.',
    },
    extend: {
        head: 'Give the poll longer. The closing time can only move later.',
        label: 'Voting closes',
        submit: 'Extend',
        hint: 'Move the closing time later and tell every household that has not voted yet.',
    },
}

const panelCopy = computed(() => PANEL_COPY[panel.value] ?? PANEL_COPY.open)

/*
 * WHILE ONE OF THIS PAGE'S DIALOGS IS OPEN, A REFUSAL OF ITS ACT IS IN ITS AMBER BAND, AND NEVER A TOAST AS WELL
 * (Hassam, 12 Oct, the final refusal rule, D-235): the person is looking at the dialog and the fix is in it. A field's
 * own fault stays under that field, so the band says the first refusal that is not one of those. The election's own
 * acts (ElectionLife) say when one of theirs is open. Every dialog here stays open on a refusal, the questions with no
 * field of their own (Open nominations' second question, a motion's Close voting and Publish results) included: never
 * closed and then toasted.
 */
const refusalBesides = (errors, fields = []) => Object.entries(errors ?? {}).find(([key]) => !fields.includes(key))?.[1] ?? null

const lifeDialog = ref(false)

const refusalInADialog = computed(() =>
    creating.value
    || minutesOpen.value
    || (props.canConfigure && props.canEditPositions && (renaming.value || editing.value !== null))
    || panel.value !== null
    || removing.value !== null
    || openingMotion.value !== null
    || closingMotion.value !== null
    || publishingMotion.value !== null
    || lifeDialog.value
)

/*
 * EVERY OTHER REFUSAL THIS PAGE IS HANDED IS A TOAST, TOP RIGHT (M95 §2), where each was a line pushed into the page: a
 * paper, a stage's act, a position's reorder, a motion closed or published. Amber, because red is kept for panic and
 * life safety (Hassam, 11 Oct).
 */
watch(
    () => ['document', 'stage', 'position', 'order', 'election'].map((key) => page.props.errors?.[key]),
    (refusals) => {
        if (refusalInADialog.value) {
            return
        }

        for (const text of refusals) {
            if (text) {
                toast(text, { tone: 'warning' })
            }
        }
    },
    { immediate: true },
)

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['election','meeting','notice'] })
</script>

<template>
    <Head :title="`${heading} · control room`" />

    <EstateConsole title="Governance" :estate-name="estate.name" active="governance">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <template #actions>
            <!--
              The board's topbar action. A ballot records no meeting, so this
              does not guess whose minutes: it opens the meetings minuted in the
              election's year and the reader chooses. Guessing would make the
              estate's formal record of a meeting a record of the wrong one.
            -->
            <!-- M96 level 2: greyed while no meeting this year has minutes, and why beside it. -->
            <span class="cr-act">
                <button
                    type="button"
                    class="btn-outline-sm"
                    :disabled="reasons.minutes !== null"
                    :title="reasons.minutes ?? 'Choose which meeting\'s minutes to issue as a PDF. It is rendered by a worker and kept for seven years.'"
                    @click="openMinutes"
                >
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M12 3v13m0 0l-4-4m4 4l4-4M5 21h14"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <span>Export minutes</span>
                </button>
                <span v-if="reasons.minutes" class="cr-why">{{ firstSentence(reasons.minutes) }}</span>
            </span>

            <!--
              AUTHORED (M10 §7, B042): drafting an election. The board catches an election already running and draws
              no such control. M96 level 3: a role that can never draft one has no button at all.
            -->
            <button
                v-if="canCreate"
                type="button"
                class="btn-outline-sm"
                title="Draft an election for a year that has none. It starts in draft, where its positions are added."
                @click="openCreate"
            >
                <span>New election</span>
            </button>
        </template>

        <!--
          Three tabs, one module. The tab for the screen the reader is already on
          is text rather than a control, because there is nowhere for it to lead.
          Notices is board 32 — built since this tab was drawn inert — and behind
          the same governance gate.
        -->
        <div class="subnav">
            <Link :href="governance('/notices')" class="subnav-item">Notices</Link>
            <Link :href="meetingsHref" class="subnav-item">Meetings</Link>
            <div class="subnav-item active" aria-current="page">Elections</div>
        </div>

        <!--
          AUTHORED (B042): every year with an election. The Elections tab goes to
          the latest year, so an election drafted for next year would otherwise
          leave this one — its results, its certificate — reachable only by
          typing the address.
        -->
        <nav v-if="years.length > 1 || (years.length === 1 && years[0] !== year)" class="cr-years" aria-label="Elections by year">
            <span class="cr-years-lbl">Elections</span>
            <template v-for="y in years" :key="y">
                <span v-if="y === year" class="cr-year current" aria-current="page">{{ y }}</span>
                <Link v-else :href="governance(`/elections/${y}`)" class="cr-year">{{ y }}</Link>
            </template>
        </nav>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Elections are not part of your role’s access"
            body="Governance covers the estate’s elections, its meetings and the record of both, so it opens only to roles that hold it. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The election could not be read"
            body="The estate database did not answer. No nomination has been decided, no poll has moved and no ballot has been certified. This is a read that failed, and trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <!--
          A first-use empty: a year with NO BALLOT AT ALL. It is not a broken
          election, it is one nobody has drafted yet — and drafting it is the
          act this panel offers (B042). It used to say that act was "not one
          this console takes yet", and it was also what a ballot drafted a
          moment ago drew, because it counted positions rather than ballots.
        -->
        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            :title="`No election has been drafted for ${year}`"
            :body="
                canCreate
                    ? 'An election is one or more ballot papers for a year, and this year has none, so there are no positions, no nominations and nothing to run yet. Drafting it is the first step.'
                    : 'An election is one or more ballot papers for a year, and this year has none, so there are no positions, no nominations and nothing to run yet.'
            "
            :action-label="canCreate && !creating ? `Draft the ${year} election` : null"
            @action="openCreate"
        />

        <template v-else>
            <div class="lifecycle-card">
                <div class="lc-top">
                    <div>
                        <div class="lc-title">{{ cardTitle }}</div>
                        <!-- "Ballot A (Community Executive) & Ballot B (Phase
                             Leadership) · Returning Officer: Delroy Samuels",
                             composed on the server out of only the parts this
                             election has. -->
                        <div class="lc-sub">
                            {{ subtitle }}
                            <!-- AUTHORED (M10 §7, B042): renaming a draft. -->
                            <button
                                v-if="canConfigure && canEditPositions && !renaming"
                                type="button"
                                class="pos-link cr-rename-link"
                                @click="startRename"
                            >
                                Rename
                            </button>
                        </div>
                    </div>

                    <!-- The stage in the vocabulary's words (M96 §2); the stepper below names the step. -->
                    <div class="stage-badge">{{ ballotStageWord(stage, stage_label) }}</div>
                </div>

                <!--
                  Nine steps, always, in lifecycle order and never in the order
                  things happened. A stage nobody has reached still occupies its
                  place — the point of the row is that a committee can see what
                  is left, and a stepper that grew as the election ran would hide
                  exactly that.
                -->
                <div class="stepper">
                    <div
                        v-for="step in stepper"
                        :key="step.key"
                        class="step"
                        :class="{ done: step.state === 'done', current: step.state === 'current' }"
                    >
                        <div class="step-dot" :class="step.state">
                            <svg v-if="step.state === 'done'" viewBox="0 0 24 24" fill="none">
                                <polyline
                                    points="20 6 9 17 4 12"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <svg v-else-if="step.state === 'current'" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="5" fill="currentColor" />
                            </svg>
                        </div>
                        <div class="step-lbl">{{ stepWord(step.key, step.label) }}</div>
                    </div>
                </div>

                <!-- Of a year with more than one paper, which one the stage above is, and where the rest stand. -->
                <p v-if="shownPaper" class="cr-papers">
                    The stage above is {{ shownPaper.title }}, the paper furthest behind. Also in this election:
                    {{ otherPapers(papers) }}.
                </p>

                <div class="stat-row">
                    <div v-for="stat in stats" :key="stat.label" class="stat-box" :class="{ warn: stat.tone === 'warning' }">
                        <div class="sv">{{ stat.value }}</div>
                        <div class="sl">{{ stat.label }}</div>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 12px; margin-bottom: 16px">
                <!-- The board draws this one with no glyph. -->
                <Link :href="nominationsHref" class="btn-outline-sm">
                    <span>{{ reviewLabel }}</span>
                </Link>

                <!--
                  Offered only while the poll is running, because it is the one
                  stage that has two acts: shut it now, or give it longer. The
                  board catches the election three stages earlier and draws
                  neither, so this control is absent from the measured screen.
                -->
                <!-- M96 level 3: the acts below are not drawn for a role that can never run the election. -->
                <button
                    v-if="act.key === 'close' && accessBlockedBy === null"
                    type="button"
                    class="btn-outline-sm"
                    title="Move the closing time later. It can never move earlier."
                    @click="openPanel('extend')"
                >
                    <span>Extend voting</span>
                </button>

                <!--
                  The tally is read on board 11 and certified there. This is a
                  link and not the primary act, because the primary act at that
                  stage belongs to a different officer.
                -->
                <Link
                    v-if="act.key === 'results'"
                    :href="resultsHref"
                    class="btn-primary-sm"
                    style="margin-left: auto"
                    title="Read the tally, then certify and publish the result."
                >
                    <span>{{ act.label }}</span>
                </Link>

                <button
                    v-else-if="accessBlockedBy === null"
                    type="button"
                    class="btn-primary-sm"
                    style="margin-left: auto"
                    :disabled="primaryBlockedBy !== null"
                    :title="
                        primaryBlockedBy ??
                        (act.key === 'open_nominations'
                            ? 'Open nominations and set when they close.'
                            : act.key === 'close_nominations'
                              ? 'Stop taking nominations for now. They can be reopened.'
                              : act.key === 'vetting'
                                ? 'Move to vetting. Decide each nomination on the nominations screen.'
                                : act.key === 'open'
                                  ? 'Open voting. The ballot lists the approved candidates and cannot change after this.'
                                  : 'Close voting and go straight to the tally.')
                    "
                    @click="primary"
                >
                    <span>{{ act.label }}</span>
                </button>
            </div>

            <!--
              Law 1 (M53 §1, M56 §3.4): a disabled act says why in one line, before anybody presses it, and the
              nominations Open voting waits on are one press away.
            -->
            <p v-if="primaryBlockedBy !== null && accessBlockedBy === null" class="act-why">
                {{ primaryBlockedBy }}
                <Link v-if="act.key === 'open' && accessBlockedBy === null && undecided > 0" :href="undecidedHref" class="text-link-sm">Review them</Link>
            </p>

            <!-- M56 §3: the election's other acts, at the stages that allow them, the destructive ones apart. -->
            <ElectionLife
                v-if="life"
                :life="life"
                :base="governance(`/ballots/${life.id}`)"
                :can-run="canRun"
                :can-certify="canCertify"
                :can-delete="canDelete"
                @dialog="lifeDialog = $event"
            />


            <!--
              THE COMMITTEE'S MOTIONS (M62 A9, M70 §1.2; step 7). Each one's card: the motion, the households it is put to
              (the server's count) and that nobody else sees it, the papers in, its choices, and the one act its stage
              allows. A motion has no nominations stage: from its draft it goes straight to the vote.
            -->
            <section v-if="committee_ballots.length" class="cb-list" aria-label="The committee's motions">
                <h2 class="cb-heading">Committee motions</h2>
                <div v-for="motion in committee_ballots" :key="motion.id" class="cb-card">
                    <div class="cb-top">
                        <div class="cb-title">{{ motion.title }}</div>
                        <span class="cb-stage">{{ ballotStageWord(motion.stage, motion.stage_label) }}</span>
                    </div>
                    <p v-if="motion.question" class="cb-question">{{ motion.question }}</p>
                    <div class="cb-facts">
                        <span>Put to {{ households(motion.eligible_households) }} on the committee's roll. No other household sees it.</span>
                        <span>{{ motion.papers_in }} of {{ motion.eligible_households }} papers in</span>
                        <span v-if="motion.tally?.length" class="cb-tally">{{ tallyLine(motion, ' · ') }}</span>
                        <span v-else-if="motion.choices.length">{{ motion.choices.join(' · ') }}</span>
                    </div>
                    <div class="cb-actions">
                        <!-- M96 level 3: each act only for a role that may ever take it; the stage chip says the rest. -->
                        <button
                            v-if="motion.stage === 'draft' && accessBlockedBy === null"
                            type="button"
                            class="btn-primary-sm"
                            title="Open voting on this motion to the households on its roll."
                            @click="askOpenMotion(motion)"
                        >
                            <span>Open voting</span>
                        </button>
                        <button
                            v-else-if="motion.stage === 'voting_open' && accessBlockedBy === null"
                            type="button"
                            class="btn-outline-sm"
                            title="Close voting on this motion and go to the count."
                            @click="askCloseMotion(motion)"
                        >
                            <span>Close voting</span>
                        </button>
                        <button
                            v-else-if="motion.stage === 'tally' && canCertify"
                            type="button"
                            class="btn-primary-sm"
                            :disabled="publishingBusy"
                            title="Publish the result to the households on its roll."
                            @click="askPublishMotion(motion)"
                        >
                            <span>Publish results</span>
                        </button>
                    </div>
                </div>
            </section>

            <!--
              Every configured position, which is more than the board draws.
              Board 9 shows five rows against its own "14 POSITIONS CONFIGURED",
              and brief-09 concludes from that gap that the list is a partial
              view. The whole set is drawn instead, for D-047's reason: a
              position with no candidate at all is the one thing a returning
              officer has to act on before nominations close, and a list that
              hid it would conceal an empty seat behind a stat box. The card is
              clipped by the shell rather than paged, exactly as the board's own
              .content is.
            -->
            <div
                style="
                    background: var(--white);
                    border: 1px solid var(--navy-100);
                    border-radius: 16px;
                    padding: 6px 18px;
                "
            >
                <!--
                  A drafted ballot with no position yet (B042). It is the first
                  thing the editor below is for, so the list says so rather than
                  the whole screen reading as an election nobody drafted.
                -->
                <p v-if="positions.length === 0" class="pos-note cr-no-positions">
                    No position is on this ballot yet.{{
                        (canConfigure || positionsOpen) && canAddPosition ? ' Add the first one below: any title this estate uses.' : ''
                    }}
                </p>

                <div v-for="position in positions" :key="position.id" class="position-row">
                    <div class="pos-name">{{ position.name }}</div>

                    <!--
                      A phase-scoped seat draws the amber scope chip INSTEAD of
                      the seat count, which is the board's own substitution: its
                      name already carries the phase ("Phase 2 · Phase Lead"),
                      so the chip says what kind of seat it is rather than
                      repeating where.
                    -->
                    <div v-if="position.seat_label" class="pos-seats">{{ position.seat_label }}</div>
                    <div v-else class="pos-scope">Phase-scoped</div>

                    <div class="pos-cand">{{ position.nominated_label }}</div>

                    <!--
                      Editing, and only while the election is a draft (M10 §7).
                      Once nominations open, somebody has stood for this seat:
                      renaming it changes what they stood for and changing its
                      seats changes who wins. The controls are not drawn rather
                      than drawn and refused — and the service refuses them too,
                      because a screen that does not draw a button is not a
                      guard rail.
                    -->
                    <!--
                      Only on the ballot being configured (B043): a year's other
                      paper is drawn beside it and is not this editor's to change.
                      Up and down are the reorder M10 §7 asks for.
                    -->
                    <!--
                      M56 §3: while nominations are open or paused, a seat nobody has stood for can still be removed.
                      Moving and editing wait for a draft.
                    -->
                    <div
                        v-if="canEditPositions && (editable(position) || (positionsOpen && position.ballot_id === ballotId && position.nominated === 0))"
                        class="pos-edit"
                    >
                        <template v-if="editable(position)">
                            <button
                                type="button"
                                class="pos-link"
                                :disabled="reorder.processing || ownPositions[0]?.id === position.id"
                                :aria-label="`Move ${position.name} up`"
                                title="Move up the ballot paper"
                                @click="move(position, -1)"
                            >
                                Up
                            </button>
                            <button
                                type="button"
                                class="pos-link"
                                :disabled="reorder.processing || ownPositions[ownPositions.length - 1]?.id === position.id"
                                :aria-label="`Move ${position.name} down`"
                                title="Move down the ballot paper"
                                @click="move(position, 1)"
                            >
                                Down
                            </button>
                            <button type="button" class="pos-link" @click="startEdit(position)">Edit</button>
                        </template>
                        <button
                            type="button"
                            class="pos-link danger"
                            title="Take this position off the ballot. Only a position nobody has stood for."
                            @click="remove(position)"
                        >
                            Remove
                        </button>
                    </div>
                </div>


                <!--
                  ADDING A POSITION BY TYPING IT — the section's headline. There
                  is no list to choose from, because there never was one: the
                  seeded Chairman and Vice Chairman are rows, not an enum.
                -->
                <!-- During nominations: what may still change here, and what waits for a draft (M56 §3). -->
                <p v-if="!canConfigure && positionsOpen && configureBlockedReason" class="pos-note">{{ configureBlockedReason }}</p>

                <form v-if="(canConfigure || positionsOpen) && canAddPosition" class="pos-form" @submit.prevent="addPosition">
                    <!-- Examples, "e.g." first (batch 1 item 7); what each box is, said to a screen reader. -->
                    <input
                        v-model="newPosition.name"
                        type="text"
                        maxlength="120"
                        required
                        aria-label="A position to add: any title this estate uses"
                        placeholder="e.g. Treasurer"
                    />
                    <input v-model.number="newPosition.seats" type="number" min="1" max="50" required aria-label="Seats" placeholder="e.g. 1" />
                    <button type="submit" class="pos-link" :disabled="newPosition.processing">Add</button>
                    <div v-if="newPosition.errors.name" class="pos-error">{{ newPosition.errors.name }}</div>
                    <div v-if="newPosition.errors.seats" class="pos-error">{{ newPosition.errors.seats }}</div>
                </form>

                <p v-else-if="canConfigure" class="pos-note">
                    Adding a position needs Governance create access. You can rename this election and edit, reorder or
                    remove the positions already on it.
                </p>

                <p v-else-if="!positionsOpen && configureBlockedReason" class="pos-note">{{ configureBlockedReason }}</p>
            </div>
        </template>

        <!--
          EVERY FORM THAT OPENS IS THE SHARED DIALOG (M95 §2), where each opened in place in the page: drafting an
          election, choosing which minutes to issue, renaming the election, editing a position, and the closing time the
          date acts need. A field's refusal stays under its field.
        -->
        <ConsoleDialog
            :open="creating"
            title="Draft an election"
            size="form"
            confirm-label="Draft election"
            :busy="electionForm.processing"
            :confirm-blocked-reason="canCreate ? draftBlockedBy : createReason"
            :error="refusalBesides(electionForm.errors, ['year', 'title', 'description'])"
            @confirm="submitElection"
            @cancel="creating = false"
        >
            <p class="cr-ask">It starts in draft: add its positions, then open nominations. A year holds one election, because an election is named by its year.</p>
            <RequiredMark legend />
            <div class="cr-create-fields">
                <div class="act-field cr-year-field">
                    <label for="new-election-year">Year <RequiredMark /></label>
                    <input id="new-election-year" v-model.number="electionForm.year" type="number" min="2020" max="2100" required :placeholder="`e.g. ${suggestedYear}`" />
                    <div v-if="electionForm.errors.year" class="act-error">{{ electionForm.errors.year }}</div>
                </div>
                <div class="act-field cr-title-field">
                    <label for="new-election-title">Name <RequiredMark /></label>
                    <input id="new-election-title" v-model="electionForm.title" type="text" maxlength="160" required :placeholder="`e.g. ${suggestedYear} Election`" />
                    <div v-if="electionForm.errors.title" class="act-error">{{ electionForm.errors.title }}</div>
                </div>
            </div>
            <div class="act-field cr-description-field">
                <label for="new-election-description">What it is for: the line a household reads (optional)</label>
                <input id="new-election-description" v-model="electionForm.description" type="text" maxlength="500" />
                <div v-if="electionForm.errors.description" class="act-error">{{ electionForm.errors.description }}</div>
            </div>
        </ConsoleDialog>

        <ConsoleDialog
            :open="minutesOpen"
            title="Issue a meeting's minutes"
            size="form"
            confirm-label="Issue minutes"
            busy-label="Issuing…"
            :busy="minutesForm.processing"
            :confirm-blocked-reason="minutesChoice === null ? 'Choose the meeting first.' : null"
            :error="refusalBesides(minutesForm.errors)"
            @confirm="issueChosenMinutes"
            @cancel="minutesOpen = false"
        >
            <p class="cr-ask">A ballot does not record which meeting the election was held at, so choose the meeting. Its minutes are issued as a PDF and kept for seven years.</p>
            <RequiredMark legend />
            <div class="act-field">
                <label for="cr-minutes-meeting">Meeting <RequiredMark /></label>
                <select id="cr-minutes-meeting" v-model="minutesChoice" required>
                    <option v-for="meeting in minutedMeetings" :key="meeting.id" :value="meeting.id">{{ meeting.title }} · {{ meeting.date }}</option>
                </select>
            </div>
        </ConsoleDialog>

        <ConsoleDialog
            :open="canConfigure && canEditPositions && renaming"
            title="Rename the election"
            size="form"
            confirm-label="Save"
            :busy="renameForm.processing"
            :confirm-blocked-reason="renameForm.title.trim() === '' ? 'Give the election a name first.' : null"
            :error="refusalBesides(renameForm.errors, ['title', 'description'])"
            @confirm="saveRename"
            @cancel="renaming = false"
        >
            <RequiredMark legend />
            <div class="act-field">
                <label for="cr-rename-title">Name <RequiredMark /></label>
                <input id="cr-rename-title" v-model="renameForm.title" type="text" maxlength="160" required />
                <div v-if="renameForm.errors.title" class="act-error">{{ renameForm.errors.title }}</div>
            </div>
            <div class="act-field">
                <label for="cr-rename-description">What it is for: the line a household reads (optional)</label>
                <input id="cr-rename-description" v-model="renameForm.description" type="text" maxlength="500" />
                <div v-if="renameForm.errors.description" class="act-error">{{ renameForm.errors.description }}</div>
            </div>
        </ConsoleDialog>

        <ConsoleDialog
            :open="canConfigure && canEditPositions && editing !== null"
            title="Edit the position"
            size="form"
            confirm-label="Save"
            :busy="editForm.processing"
            :confirm-blocked-reason="editForm.name.trim() === '' ? 'Give the position a title first.' : null"
            :error="refusalBesides(editForm.errors, ['name', 'seats'])"
            @confirm="saveEdit"
            @cancel="editing = null"
        >
            <RequiredMark legend />
            <div class="act-field">
                <label for="cr-edit-name">Position title <RequiredMark /></label>
                <input id="cr-edit-name" v-model="editForm.name" type="text" maxlength="120" required />
                <div v-if="editForm.errors.name" class="act-error">{{ editForm.errors.name }}</div>
            </div>
            <div class="act-field">
                <label for="cr-edit-seats">Seats <RequiredMark /></label>
                <input id="cr-edit-seats" v-model.number="editForm.seats" type="number" min="1" max="50" required />
                <div v-if="editForm.errors.seats" class="act-error">{{ editForm.errors.seats }}</div>
            </div>
        </ConsoleDialog>

        <!-- Open nominations asks a second question of its own; this steps aside while it does. -->
        <ConsoleDialog
            :open="panel !== null && !askingNominations"
            :title="panel === 'extend' ? 'Extend voting' : panel === 'nominations' ? 'Open nominations' : 'Open voting'"
            size="form"
            :confirm-label="panelCopy.submit"
            :busy="closing.processing"
            :confirm-blocked-reason="accessBlockedBy ?? (closing.closes_at === '' ? 'Choose the closing time first.' : null)"
            :error="refusalBesides(closing.errors, ['closes_at'])"
            @confirm="submitClosing"
            @cancel="closePanel"
        >
            <p class="cr-ask">{{ panelHead }}</p>
            <RequiredMark legend />
            <div class="act-field">
                <label for="closes-at">{{ panelCopy.label }} <RequiredMark /></label>
                <input id="closes-at" v-model="closing.closes_at" type="datetime-local" required />
                <div v-if="closing.errors.closes_at" class="act-error">{{ closing.errors.closes_at }}</div>
            </div>
        </ConsoleDialog>

        <ConsoleDialog
            :open="removing !== null"
            :title="removing ? `Remove ${removing.name} from this ballot?` : ''"
            tone="danger"
            :confirm-label="removing ? `Remove ${removing.name}` : 'Remove'"
            busy-label="Removing…"
            :busy="removeForm.processing"
            :error="refusalBesides(removeForm.errors)"
            @confirm="confirmRemove"
            @cancel="removing = null"
        >
            <p style="margin:0;">
                The position comes off the ballot paper, and this cannot be undone from this screen.
                A position somebody has already been nominated for cannot be removed.
            </p>
        </ConsoleDialog>

        <ConsoleDialog
            :open="askingNominations"
            :title="nominationsTitle"
            confirm-label="Open nominations"
            busy-label="Opening…"
            :busy="closing.processing"
            :error="refusalBesides(closing.errors)"
            @confirm="postClosing"
            @cancel="askingNominations = false"
        >
            <p class="cr-ask">{{ nominationsWhen }}</p>
            <p class="cr-ask">Residents are not notified and cannot nominate from the app.</p>
        </ConsoleDialog>

        <!-- A motion's Open voting (M70 §1.2): the server's count of its roll, and the channel. -->
        <ConsoleDialog
            :open="openingMotion !== null"
            :title="openingMotion ? `Open voting to ${households(openingMotion.eligible_households)}?` : ''"
            confirm-label="Open voting"
            busy-label="Opening…"
            :busy="motionForm.processing"
            :error="refusalBesides(motionForm.errors, ['closes_at'])"
            @confirm="postOpenMotion"
            @cancel="openingMotion = null"
        >
            <template v-if="openingMotion">
                <p class="cr-ask">{{ openingMotion.title }}.</p>
                <RequiredMark legend />
                <div class="act-field cb-closes">
                    <label for="motion-closes-at">Voting closes · estate time <RequiredMark /></label>
                    <input id="motion-closes-at" v-model="motionForm.closes_at" type="datetime-local" required />
                    <!-- The field's own refusal, under it in amber, as this page's other dialogs draw theirs. -->
                    <div v-if="motionForm.errors.closes_at" class="act-error">{{ motionForm.errors.closes_at }}</div>
                </div>
                <p class="cr-ask">They are told in the app, and by email where they asked for it. No other household sees it.</p>
            </template>
        </ConsoleDialog>

        <ConsoleDialog
            :open="closingMotion !== null"
            :title="closingMotion ? `Close voting on ${closingMotion.title}?` : ''"
            confirm-label="Close voting"
            busy-label="Closing…"
            :error="closeMotionRefusal"
            @confirm="postCloseMotion"
            @cancel="closingMotion = null"
        >
            <p v-if="closingMotion" class="cr-ask">
                {{ closingMotion.papers_in }} of {{ closingMotion.eligible_households }} papers are in. Nobody can vote on it after this.
            </p>
        </ConsoleDialog>

        <!-- A motion's Publish results (M72 step 7): the count, its roll and the channels, before the press. -->
        <ConsoleDialog
            :open="publishingMotion !== null"
            :title="publishingMotion ? `Publish the result of ${publishingMotion.title}?` : ''"
            confirm-label="Publish results"
            busy-label="Publishing…"
            :busy="publishingBusy"
            :error="publishMotionRefusal"
            @confirm="postPublishMotion"
            @cancel="publishingMotion = null"
        >
            <template v-if="publishingMotion">
                <p v-if="publishingMotion.tally?.length" class="cr-ask">The count: {{ tallyLine(publishingMotion, ', ') }}.</p>
                <p class="cr-ask">
                    The {{ households(publishingMotion.eligible_households) }} on the committee's roll are told in the app, and by email where they asked for it. Nobody else is told.
                </p>
                <p class="cr-ask">It is certified as it stands and cannot be changed after this.</p>
            </template>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its topbar action, its three tabs, its two row buttons and
 * its position rows as <div>s; here they are five buttons and three links. A
 * real button arrives wearing a border, buttonface grey and the browser's own
 * font, and the board's classes supply everything visible. app.css has already
 * taken the UA's underline and blue off every anchor on a board page, so the
 * links need nothing.
 *
 * THE BORDER RESETS NAME ONLY THE CLASSES THE BOARD GIVES NO BORDER TO.
 * `.btn-outline-sm` carries `border:1.5px solid var(--navy-200)` on every board
 * and a scoped element selector would out-specify it and rub the outline off
 * the control entirely — a real difference from the approved design, small
 * enough to survive a passing measurement. See D-045. `.btn-primary-sm`,
 * `.subnav-item` and `.text-link-sm` declare a face and no border, which is why
 * those three are safe to reset.
 */
button {
    font: inherit;
}

button.btn-primary-sm,
button.subnav-item,
button.text-link-sm {
    border: 0;
}

button.subnav-item,
button.text-link-sm {
    background: none;
    padding: 0;
}

/* The tab keeps the board's own padding; only its face is the browser's. */
button.subnav-item {
    padding: 9px 16px;
}

button.btn-primary-sm,
button.btn-outline-sm,
button.subnav-item,
button.text-link-sm {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/* Greyed when it cannot be used yet (M96 level 2), its reason beside it: Open voting looked live while it waited. */
button.btn-primary-sm[disabled],
button.btn-outline-sm[disabled] {
    opacity: 0.55;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no flash, no refusal and no panel,
 * because nothing has ever been pressed on it — and every one of those is
 * something this screen has to be able to say. Kept to the tokens the boards
 * define and to shapes they already use: the panel is the white card with the
 * navy-100 edge that the position list already is, and the type sizes are
 * .lc-sub's and .pos-cand's.
 */
.gov-flash,
.gov-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.gov-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.gov-refusal {
    background: var(--amber-100);
    color: var(--amber-700);
}

.act-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.act-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
    max-width: 760px;
}

.act-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-width: 260px;
}

.act-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.act-field input,
.act-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.act-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

/* Export minutes and why it is greyed, side by side in the topbar (M96 level 2). */
.cr-act {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.cr-why {
    font-size: 11px;
    font-weight: 600;
    color: var(--slate-600);
    max-width: 260px;
}

.act-why {
    margin: -6px 0 16px;
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-700);
    display: flex;
    gap: 8px;
    align-items: baseline;
}

.act-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

/* "Export minutes" — which meeting's. The same card shape as .act-panel. */
.cr-minutes {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.cr-minutes-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
    max-width: 760px;
}

.cr-minutes-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    font-size: 12px;
    color: var(--navy-900);
}

.cr-minutes-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    margin: 0 0 12px;
}

/*
 * The positions editor (M10 §7). Authored: board 9 draws the position rows as a
 * read-only list, because until now there was no way to write one. These borrow
 * the row's own rhythm and add nothing the board does not already use.
 */
.pos-edit {
    display: flex;
    gap: 12px;
    flex: 0 0 auto;
}

.pos-link {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    background: transparent;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-600);
    cursor: pointer;
    padding: 0;
    white-space: nowrap;
}

.pos-link:disabled {
    color: var(--slate-500);
    cursor: not-allowed;
}

/* Remove is destructive: muted red, serious and clearly not an emergency (Hassam's three weights, 11 Oct). */
.pos-link.danger {
    color: var(--red-700);
}

.pos-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 12px 0;
    border-top: 1px solid var(--navy-100);
}

.pos-form input[type='text'] {
    flex: 1;
    min-width: 200px;
}

.pos-form input[type='number'] {
    width: 84px;
}

.pos-form input {
    height: 34px;
    border: 1.5px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 11px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.pos-error {
    flex: 1 0 100%;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
}

/* Why the editor is not drawn, when it is not. */
.pos-note {
    margin: 0;
    padding: 12px 0;
    border-top: 1px solid var(--navy-100);
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--slate-500);
}

/*
 * Drafting, renaming and reordering an election (M10 §7, B042), and the years
 * row. Authored: the board catches an election already running. Kept to the
 * shapes above — the panel is .act-panel, the inline forms are .pos-form.
 */
.cr-no-positions {
    border-top: 0;
}

.cr-row-error {
    padding: 0 0 10px;
}

.cr-rename-link {
    margin-left: 8px;
}

.cr-rename {
    border-top: 0;
    padding-top: 4px;
}

.cr-years {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin: 0 0 14px;
    font-size: 12px;
}

.cr-years-lbl {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.cr-year {
    font-weight: 700;
    color: var(--navy-600);
}

.cr-year.current {
    color: var(--navy-900);
}

.cr-create-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.cr-year-field {
    max-width: 120px;
}

.cr-title-field {
    flex: 1 1 260px;
    max-width: 420px;
}

.cr-description-field {
    max-width: 700px;
}

/* Open nominations' question (M62 B1): two short lines under its title, set close. */
.cr-ask {
    margin: 0;
}

/* Room between a dialog's paragraph and the form under it: the Year label touched the text (batch 1 item 7). */
.cr-ask + .req-legend {
    margin-top: 12px;
}

.cr-ask + .cr-ask {
    margin-top: 6px;
}

/* The committee's motions (M70 §1.2): the white card with the navy-100 edge the positions card already is. */
.cb-list {
    margin-bottom: 16px;
}

.cb-heading {
    margin: 0 0 8px;
    font-size: 14px;
    font-weight: 700;
    color: var(--navy-900);
}

.cb-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 14px 18px;
}

.cb-card + .cb-card {
    margin-top: 10px;
}

.cb-top {
    display: flex;
    align-items: center;
    gap: 10px;
}

.cb-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.cb-stage {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--navy-700);
    white-space: nowrap;
}

.cb-question {
    margin: 6px 0 0;
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--navy-800);
}

.cb-facts {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    margin-top: 8px;
    font-size: 11.5px;
    color: var(--slate-500);
}

.cb-actions {
    margin-top: 10px;
}

.cb-closes {
    margin: 8px 0;
}

/* Which paper the stage above is, of a year with more than one: a caption, in .lc-sub's size. */
.cr-papers {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.55;
    margin: 14px 0 14px;
}

/* A closing date passed with nominations still open: amber, the colour of overdue (Hassam's three weights). */
.stat-box.warn .sv {
    color: var(--amber-700);
}

/*
 * THE NINE STEPS ON A PHONE (batch 1 item 12, seen at 390 px): their labels ran 10 px past the window. From 1023 px down
 * the stepper scrolls sideways in its own frame, so the page itself never does.
 */
@media (max-width: 1023px) {
    .stepper {
        overflow-x: auto;
        padding-bottom: 4px;
    }
}
</style>
