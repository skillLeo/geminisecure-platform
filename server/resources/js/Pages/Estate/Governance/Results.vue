<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'
import { otherPapers } from '../../../lib/electionWords.js'

/**
 * The tally, and the one act that makes it final — board community-admin-11.
 *
 * NOTHING ON THIS SCREEN CAN BE USED TO LEARN HOW A NAMED HOUSEHOLD VOTED, and
 * that is a schema guarantee before it is a screen one. `ballot_receipts`
 * records THAT a household voted and `ballot_marks` records WHAT was chosen;
 * the two share no column, there is no query that could join them, and
 * `Governance` has no method that would answer the question. Everything drawn
 * here is an aggregate:
 *
 *   turnout    COUNT(*) over the receipts against a snapshotted denominator
 *   phases     the same count grouped by the PROPERTY's phase, never by unit
 *   the cards  COUNT(*) over the marks per option
 *
 * The finest grain anything on this screen reaches is board 11's own headline,
 * "318 of 450 households (71%)" — a numerator, a denominator and a percentage.
 * There is no roll, no list of who has voted, no per-household tick, and no
 * ordering anywhere that could be correlated with one. There is also no control
 * that casts a vote and no route that could back one: voting is a resident act
 * in the resident app, and this console runs the election without ever marking
 * a paper.
 *
 * THE SHARES ARE OF BALLOTS CAST, NOT OF VOTES CAST, and board 11 is drawn that
 * way deliberately: in a three-seat race one voter marks three names, so the
 * shares sum to more than 100 and are supposed to. 187 and 131 happen to sum to
 * 318 because Chairman is a single seat, which is what makes the two readings
 * look identical on the Chairman card and different on the one beside it.
 * Dividing by total votes would make a three-seat race look like a three-way
 * split of one.
 *
 * CERTIFICATION IS IRREVERSIBLE AND THERE IS NOTHING HERE THAT UNDOES IT. The
 * Build Spec: "a certified ballot cannot be reopened or edited". There is no
 * decertify method in the service, no route, and no control on this page — a
 * certified result that turns out to be wrong is put right by running another
 * ballot, exactly as a posted journal is corrected by another entry. Once the
 * ballot is certified this screen prints the certificate and offers nothing.
 *
 * AND IT IS THE PRESIDENT'S ACT, NOT THE SECRETARY'S. The estate matrix gives
 * Governance `Full · Approver` to the President and Vice President and plain
 * `Full` to the Secretary, so the officer who RUNS the election is deliberately
 * not the officer who declares it final — D-013. The banner's own copy
 * addresses the Returning Officer, which is a description of who reviews the
 * tally rather than of who holds the permission; a Secretary reading this
 * screen sees the button inert with that distinction on it rather than a
 * permission error.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    estate: { type: Object, required: true },
    year: { type: Number, required: true },

    /** The most advanced paper of the year — the one with a tally on it. Null for a year with none. */
    ballot: { type: Object, default: null },

    /** { cast, eligible, percent, label } — counts, never a breakdown. */
    turnout: { type: Object, default: null },
    quorum: { type: Object, default: null },
    phases: { type: Array, required: true },
    cards: { type: Array, required: true },

    /** Whether the poll has closed. Until it has, the server sends the turnout and no candidate's count (Hassam, 11 Oct). */
    count_closed: { type: Boolean, default: false },

    /** The year's election papers, each with its stage, and which one this page shows (`Governance::papersOf()`). */
    papers: { type: Array, default: () => [] },

    canCertify: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Boards 9 to 12 share this sheet. Naming the wrong one renders this screen
 * with no certify banner, no turnout bar and no result cards at all.
 */
useWireframe('community-admin-03-elections-nominations-results-and-meetings')

const page = usePage()

/*
 * Five of the six. The board draws no search box and no filter — a tally is the
 * whole tally — so `empty-filtered` cannot occur here, and rendering a "clear
 * the filter" panel on a screen with no filter would invent a control in order
 * to explain a state that does not exist. `?_state=` still forces the other
 * five.
 */
// While the poll is open there are no cards by design, and the page is the turnout: populated, not empty.
const state = useScreenState({
    rows: () => (props.ballot === null ? 0 : props.count_closed ? props.cards.length : 1),
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL — production gives
 * each estate its own hostname and local puts the estate key in the path, so
 * cutting the URL that served this page is right in both and cannot address an
 * estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

const controlRoomHref = computed(() => governance(`/elections/${props.year}`))

/** The paper this page shows, when the year has more than one (Security's dev-server note, 12 Oct). */
const shownPaper = computed(() => (props.papers.length > 1 ? props.papers.find((paper) => paper.shown) ?? null : null))

/* ------------------------------------------------------------------ */
/* the certificate (12 §1) */
/* ------------------------------------------------------------------ */

/*
 * ISSUED ONLY FOR A CERTIFIED COUNT, and the server refuses otherwise. The
 * document names who was elected and a returning officer stands behind it; a
 * count still open can still move, and a certificate over one would put
 * somebody's name on a result that changed the next morning.
 */
const certificateBlockedBy = computed(() => {
    if (props.ballot === null) {
        return `There is no ballot for ${props.year} to certify.`
    }

    return props.ballot.certified
        ? null
        : 'This count has not been certified. A certificate names who was elected and a returning officer stands behind it, so it is issued from a certified result and never from one still open.'
})

const certificateForm = useForm({})

const askForCertificate = () => {
    if (certificateBlockedBy.value !== null) {
        return
    }

    certificateForm.post(governance(`/elections/${props.year}/certificate`), { preserveScroll: true })
}

/* ------------------------------------------------------------------ */
/* the banner, which says which of four moments this is */
/* ------------------------------------------------------------------ */

/**
 * The two lines board 11 draws in its banner, and what they say at each stage.
 *
 * The board catches the one moment that has a decision in it — the poll shut,
 * the tally counted, nobody has signed for it — and prints its copy verbatim
 * there. The other three are moments this screen can genuinely be opened in and
 * the board has no wording for, so each says what is actually true rather than
 * borrowing a sentence about certification.
 */
const banner = computed(() => {
    if (props.ballot === null) {
        return { cb1: 'No result to certify', cb2: 'No ballot has been drafted for this year.' }
    }

    if (props.ballot.certified) {
        return {
            cb1: `Certified${props.ballot.certified_at === null ? '' : ' ' + props.ballot.certified_at}`,
            cb2:
                `${props.ballot.title} was certified by ${props.ballot.certified_by ?? 'the returning officer'}` +
                `${props.ballot.published ? ' and published estate-wide' : ''}. It cannot be undone: a result that ` +
                'turns out to be wrong is put right by running another ballot, not by reopening this one.',
        }
    }

    if (props.ballot.stage === 'tally') {
        return {
            cb1: 'Voting closed, waiting for certification',
            // Board 11's words, for the officer who certifies; anybody else is told whose act it is, not asked to do it
            // (Security's dev-server note, 12 Oct: the Treasurer was told to certify).
            cb2: props.canCertify
                ? 'As Returning Officer, review the tally below then certify to publish results estate-wide.'
                : 'The tally below waits for the Returning Officer to certify it. Households are told nothing until then.',
        }
    }

    return {
        cb1: 'No tally to certify yet',
        cb2:
            'A result is certified from the tally of a closed poll. Certifying anything earlier would declare a ' +
            'winner while votes were still being cast.',
    }
})

/**
 * Why "Certify & publish results" cannot be pressed, or null when it can.
 *
 * ACCESS IS ASKED FIRST, because it is the refusal that will not change
 * whatever anybody does. The sentence the controller sends for it is not a
 * permission error — a Secretary holds Full on Governance — so it says whose
 * act this is rather than that they should not be reading the screen.
 */
const certifyBlockedBy = computed(() => {
    if (!props.canCertify) {
        return props.blockedReason
    }

    if (props.ballot === null) {
        return 'There is no ballot for this year, so there is no result to certify.'
    }

    if (props.ballot.stage !== 'tally') {
        return (
            `${props.ballot.title} cannot be certified yet. A result is certified from the tally of a closed poll, ` +
            'and the control room is where a poll is closed.'
        )
    }

    return null
})

/** The confirmation panel, which is closed on every fresh GET. */
const confirming = ref(false)

/*
 * The outcome statement is OPTIONAL and the service composes one when it is
 * left blank — turnout, quorum and the elected names, from the counts. It is
 * offered because board 11 calls this the estate's formal record and a
 * returning officer may want their own words; it is written once and never
 * again, because a record that could be edited afterwards is a draft.
 */
const certifying = useForm({ outcome: '' })

const openConfirm = () => {
    if (certifyBlockedBy.value !== null) {
        return
    }

    confirming.value = !confirming.value
    certifying.clearErrors()
}

/**
 * Certify, and publish in the same request — board 11's one button.
 *
 * A form submits on Enter from any field and a disabled button does not stop
 * it, so this re-asks what the button asks. The route is gated on
 * `estate.governance.certify_elections` and the service refuses anything that is not at
 * the tally, so this is only about not sending a press that will bounce.
 */
const certify = () => {
    if (certifyBlockedBy.value !== null) {
        return
    }

    certifying.post(governance(`/ballots/${props.ballot.id}/certify`), {
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = false
        },
    })
}

/**
 * What the turnout headline is, said in full, on the figure itself.
 *
 * The quorum is real and board 11 draws it nowhere. It belongs beside the
 * turnout because it is the same count measured against a different bar, and it
 * is put on a title rather than into the panel because adding a line the
 * approved design does not have would move every figure below it.
 */
const turnoutTitle = computed(() => {
    if (props.turnout === null || props.quorum === null) {
        return undefined
    }

    return (
        `${props.turnout.label}. Counted from the ballot receipts, which record that a household voted and never ` +
        `what it chose, against the ${props.turnout.eligible} households eligible when the poll opened. ` +
        `The quorum of ${props.quorum.required} households was ${props.quorum.met ? 'met' : 'not met'}.`
    )
})

/** Each phase's own fraction, which is not a slice of the overall figure. */
const phaseTitle = (phase) =>
    `${phase.cast} of ${phase.eligible} households in ${phase.phase} voted. Each phase is measured against its ` +
    'own household count, so the five figures are not an average of the overall turnout.'

/**
 * Whether this candidate took a seat, said without drawing one.
 *
 * Board 11 marks no winner — it draws the elected and the unelected
 * identically — and adding a badge would be adding a thing the approved design
 * does not have. The fact is real and derived from the seat count, so it is
 * carried on the row's own title where it costs nothing and hides nothing.
 */
const seatTitle = (card, row) =>
    row.is_winner
        ? `${row.name} takes one of ${card.position}'s ${card.seat_count} seat${card.seat_count === 1 ? '' : 's'}: ` +
          `${row.votes} of the ${props.turnout.cast} ballots cast carried this name.`
        : `${row.name} was not elected: ${row.votes} of the ${props.turnout.cast} ballots cast carried this name, and ` +
          `${card.position} has ${card.seat_count} seat${card.seat_count === 1 ? '' : 's'}.`

/*
 * A REFUSAL IS A TOAST, TOP RIGHT (M95 §2), where each was a line pushed into the page: the certificate refused.
 * Amber, because red is kept for panic and life safety (Hassam, 11 Oct). Never while the certify dialog is open: its
 * refusal is in its amber band, and never a toast as well (Hassam, 12 Oct, the final refusal rule, D-235).
 */
const refusalInADialog = computed(() => confirming.value)

/* The certify dialog's band: the refusal of the certification, the server's sentence. */
const certifyRefusal = computed(() => Object.values(certifying.errors)[0] ?? null)

watch(
    () => [page.props.errors?.document, page.props.errors?.outcome],
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
useLiveReload({ entities: ['election'] })
</script>

<template>
    <Head :title="`${year} election results`" />

    <!-- The shared back control, top left (M95 §2): the election this result belongs to. -->
    <EstateConsole :title="`${year} election results`" :estate-name="estate.name" active="governance" :back="{ href: controlRoomHref, label: 'Back to the election' }">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <template #actions>
            <!--
              THE CERTIFICATE (12 §1). The old reason asked for a template and
              a signature block, and both exist now — and it is only issued for
              a CERTIFIED count, because the document names who was elected and
              a returning officer stands behind it. A count still open can move.
            -->
            <!-- M96 level 2: greyed until the count is certified, and why beside it. -->
            <span class="rs-act">
                <button
                    type="button"
                    class="btn-outline-sm"
                    :disabled="certificateBlockedBy !== null"
                    :title="certificateBlockedBy ?? 'Issue the election certificate: the tally, the turnout and a signature block for the returning officer. Rendered by a worker and kept for seven years.'"
                    @click="askForCertificate"
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
                    <span>Export report</span>
                </button>
                <span v-if="certificateBlockedBy" class="rs-why">{{ certificateBlockedBy.split('. ')[0].replace(/\.$/, '') }}.</span>
            </span>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This election’s result is not part of your role’s access"
            body="Governance covers the estate’s elections and the record of them, so it opens only to roles that hold it. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The tally could not be read"
            body="The estate database did not answer. Nothing has been certified, nothing has been published and not one mark has moved — every figure on this screen is counted from rows at the moment it is drawn, so re-running the read is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            :title="`There is no result for ${year}`"
            body="No ballot has been run for this year, so there is nothing to tally and nothing to certify. An election starts in the control room, and a result exists only once a poll has opened and closed."
            action-label="Open the control room"
            @action="router.get(controlRoomHref)"
        />

        <template v-else>
            <div class="certify-banner">
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M9 12l2 2 4-4"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                </svg>

                <div>
                    <div class="cb1">{{ banner.cb1 }}</div>
                    <div class="cb2">{{ banner.cb2 }}</div>
                </div>

                <!--
                  ONE BUTTON, AND IT DISAPPEARS THE MOMENT IT HAS BEEN PRESSED.
                  A certified ballot has nothing left to offer: there is no
                  decertify act, no reopen and no edit anywhere in this
                  application, so drawing a greyed control here would imply an
                  act that does not exist. The certificate itself replaces it.
                -->
                <!-- M96 level 3: not drawn for a role that can never certify; the banner says where the count stands. -->
                <button
                    v-if="!ballot.certified && canCertify"
                    type="button"
                    class="certify-btn"
                    :disabled="certifyBlockedBy !== null"
                    :title="
                        certifyBlockedBy ??
                        'Declare this result final and publish it estate-wide. It cannot be undone: a result that turns out to be wrong is put right by running another ballot.'
                    "
                    @click="openConfirm"
                >
                    Certify &amp; publish results
                </button>
            </div>

            <!-- Of a year with more than one paper, which one this page shows, and where the rest stand. -->
            <p v-if="shownPaper" class="rs-papers">
                This page shows {{ shownPaper.title }}, the paper furthest along. Also in this election, not shown here:
                {{ otherPapers(papers) }}.
            </p>

            <!--
              THE CONFIRMATION, in the shared dialog (M95 §2): the one press on the Estate Console that cannot be taken
              back, so it asks first, says so, and takes the returning officer's own words if they want them.
            -->
            <ConsoleDialog
                :open="confirming"
                :title="`Certify and publish ${ballot.title}?`"
                size="form"
                confirm-label="Certify and publish"
                busy-label="Certifying…"
                :busy="certifying.processing"
                :confirm-blocked-reason="certifyBlockedBy"
                :error="certifyRefusal"
                @confirm="certify"
                @cancel="confirming = false"
            >
                <p class="act-head">
                    It declares this tally the estate’s formal record and publishes it to every household. It cannot be
                    undone: no ballot is reopened, edited or decertified, and a result that turns out to be wrong is put
                    right by running another ballot.
                </p>

                <div class="act-field">
                    <label for="outcome">
                        The outcome statement, in the returning officer’s own words. Leave it empty and the turnout, the
                        quorum and the elected names are written from the counts (optional).
                    </label>
                    <textarea
                        id="outcome"
                        v-model="certifying.outcome"
                        rows="3"
                        maxlength="2000"
                        :placeholder="turnout && quorum ? `e.g. Turnout ${turnout.label}. Quorum of ${quorum.required} households ${quorum.met ? 'met' : 'not met'}.` : ''"
                    ></textarea>
                </div>
            </ConsoleDialog>

            <!--
              The certificate. Drawn only once the ballot is certified, which is
              a state board 11 does not have — its whole subject is the moment
              before. It is the record the outcome statement was written for,
              and it is the only thing this screen has to say once the decision
              has been taken.
            -->
            <p v-if="ballot.certified && ballot.outcome" class="outcome-statement">{{ ballot.outcome }}</p>

            <div class="turnout-panel">
                <div class="turnout-top">
                    <h3>Overall turnout</h3>

                    <!--
                      A COUNT AND A DENOMINATOR, and the finest grain anything on
                      this screen goes to. It says how many households voted and
                      never which.
                    -->
                    <div class="tv" :title="turnoutTitle">
                        {{ turnout.cast }}
                        <span style="font-size: 12px; color: var(--slate-500); font-weight: 600">
                            of {{ turnout.eligible }} households ({{ turnout.percent }}%)
                        </span>
                    </div>
                </div>

                <div class="t-bar"><i :style="{ width: `${turnout.percent}%` }"></i></div>

                <!--
                  EACH PHASE IS ITS OWN FRACTION and not a share of the whole —
                  the board's own note says the five percentages are not an
                  average of the overall figure, because each is measured
                  against that phase's household count. The phase of a voter is
                  a property of the property, which is why the receipts are
                  joined to the units to count this and why nothing here reaches
                  a household.
                -->
                <div class="phase-turnout-row">
                    <div v-for="phase in phases" :key="phase.phase" class="ptr-item" :title="phaseTitle(phase)">
                        <div class="ptr-track"><i :style="{ height: `${phase.percent}%` }"></i></div>
                        <span>{{ phase.caption }}</span>
                    </div>
                </div>
            </div>

            <!--
              One card per position on the paper, which is four where the board
              draws two. Board 11 shows Chairman and Vice Chairman against an
              election whose own control room configures fourteen seats;
              dropping Secretary and Treasurer to match would hide two elected
              officers on the screen that declares them elected. The grid is the
              board's own two columns and simply runs to a second row.
            -->
            <!--
              NO COUNT BEFORE THE CLOSE (Hassam, 11 Oct). While households are still voting the server sends no
              candidate's count at all, so this page says what it is showing and why, beside the turnout.
            -->
            <p v-if="!count_closed" class="rs-note">
                Each candidate's count is shown once voting closes. Until then this page shows how many households have
                voted, and nothing more: a running count would change how people vote.
            </p>

            <div v-else class="rs-cards">
                <div
                    v-for="card in cards"
                    :key="card.position"
                    style="
                        background: var(--white);
                        border: 1px solid var(--navy-100);
                        border-radius: 16px;
                        padding: 16px;
                    "
                >
                    <!-- "Vice Chairman · 3 seats", and a bare "Chairman" for
                         one seat. Composed on the server, so the suffix rule
                         lives in one place. -->
                    <div
                        style="
                            font-family: 'Poppins', sans-serif;
                            font-size: 13px;
                            color: var(--navy-900);
                            font-weight: 600;
                            margin-bottom: 10px;
                        "
                    >
                        {{ card.heading }}
                    </div>

                    <!--
                      Every candidate on this seat, including any who took no
                      marks at all: the tally is a LEFT JOIN for that reason,
                      and a results card that silently dropped the candidate
                      nobody voted for would be the one screen where an absence
                      looks like an oversight.
                    -->
                    <div v-for="row in card.rows" :key="row.id" class="winner-row" :title="seatTitle(card, row)">
                        <div class="w-avatar">{{ row.initials }}</div>
                        <div class="w-info">
                            <div class="wn">{{ row.name }}</div>
                            <!-- The phase only, with no lot number. A result is
                                 about a candidate, and their address is not
                                 part of it. -->
                            <div class="wr">{{ row.phase }}</div>
                        </div>
                        <div class="w-votes">
                            <b>{{ row.votes }}</b>
                            <!-- Of BALLOTS CAST, not of votes cast. See the
                                 note at the top of this file. -->
                            <span>{{ row.percent }}%</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its topbar action and its certify control as <div>s; here
 * they are buttons, which arrive wearing a border, buttonface grey and the
 * browser's own font. `.certify-btn`, `.btn-primary-sm` and `.text-link-sm`
 * declare a face and no border, which is why those three are safe to reset.
 *
 * `.btn-outline-sm` IS NOT RESET. It carries `border:1.5px solid
 * var(--navy-200)` on every board and a scoped element selector would
 * out-specify that and rub the outline off the control entirely — a real
 * difference from the approved design, small enough to survive a passing
 * measurement. See D-045.
 */
button {
    font: inherit;
}

button.certify-btn,
button.btn-primary-sm,
button.text-link-sm {
    border: 0;
    cursor: pointer;
}

button.text-link-sm {
    background: none;
    padding: 0;
}

button[disabled] {
    cursor: not-allowed;
}

/* Greyed when it cannot be used yet (M96 level 2): only the cursor changed. */
button.certify-btn[disabled],
button.btn-outline-sm[disabled] {
    opacity: 0.55;
}

/* Export report and why it is greyed, side by side in the topbar. */
.rs-act {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.rs-why {
    font-size: 11px;
    font-weight: 600;
    color: var(--slate-600);
}

/* Which paper this page shows, of a year with more than one: a caption under the banner. */
.rs-papers {
    font-size: 11.5px;
    line-height: 1.55;
    color: var(--slate-600);
    margin: -6px 2px 14px;
}

/* The result cards, two to a row as the board draws them; one column from 1023 px down (batch 1 item 12). */
.rs-cards {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* A card may shrink to the window: a grid item otherwise holds its column open at its content's width. */
.rs-cards > * {
    min-width: 0;
}

@media (max-width: 1023px) {
    .rs-cards {
        grid-template-columns: 1fr;
    }
}

/* What the page shows while the poll is open, in the panel's own type. */
.rs-note {
    font-size: 12px;
    line-height: 1.6;
    color: var(--slate-600);
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 14px 16px;
    margin: 0;
}

/*
 * AUTHORED BELOW THIS LINE, and only for states the board has none of: it draws
 * the single moment after the poll closed and before anybody signed for the
 * result, with nothing pressed, nothing refused and nothing certified. Kept to
 * the tokens the boards define and to shapes they already use — the panel is
 * the white card with the navy-100 edge that .turnout-panel already is.
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

/* The estate's formal record of the result, printed as a record rather than as
 * a notice: no colour panel behind it, because it is not news. */
.outcome-statement {
    font-size: 12.5px;
    color: var(--navy-800);
    line-height: 1.6;
    max-width: 860px;
    margin: 0 0 20px;
}

.act-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.act-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
    max-width: 860px;
}

.act-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-width: 860px;
}

.act-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.act-field textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 9px 10px;
    font: inherit;
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--navy-900);
    resize: vertical;
}

.act-field textarea::placeholder {
    color: var(--text-placeholder);
    opacity: 1;
}

.act-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.act-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
