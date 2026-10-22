<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import AddCandidate from '../../../Components/AddCandidate.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { keptByTable } from '../../../lib/tableParams'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'
import { nominationWord } from '../../../lib/electionWords.js'

/**
 * Vetting who may stand — board screen community-admin-10.
 *
 * THIS IS A DECISION ABOUT A MEMBER'S RIGHT TO STAND FOR OFFICE IN THEIR OWN
 * COMMUNITY, which is why every refusal on it carries a reason and why the
 * reason is required by the service rather than by this form. "Rejection always
 * carries a recorded reason" — the Build Spec — and a rejection with nothing
 * after it cannot be explained to the member it is about.
 *
 * THREE OUTCOMES, NOT TWO. Board 10 draws a tick and a cross, and the service
 * carries a third: more information requested. A candidate waiting on a
 * proposer's signature has not been refused, and folding that into `rejected`
 * would put a refusal on a member's permanent record for a missing form. It is
 * offered inside the refusal panel rather than as a third icon, because the
 * board draws two controls and because the two outcomes take the same field —
 * what is missing, or what is wrong.
 *
 * THE PHASE IS READ OFF THE PROPERTY AND NOT OFF THE NOMINATION. Board 10 puts
 * Sonia Campbell at "Phase 4 · Lot 88" and Keith Walters at "Phase 2 · Lot 63";
 * the estate's own map puts both lots in Phase 1, and board 4 puts two different
 * households at Lot 12 in two different phases, so the boards' phase labels
 * cannot all be true of one estate. One estate has one map. Recorded as D-051's
 * third residual and reproduced here rather than resolved in the page.
 *
 * "REJECTED — ARREARS >90 DAYS" IS A RECORDED DECISION, NOT A COMPUTED ONE. The
 * badge prints `status_label`, which is the reason the returning officer wrote
 * down; the arrears ageing that justified it is snapshotted onto the nomination
 * when the decision is taken, so a candidate who clears their balance next week
 * cannot make the record say they were never in arrears. The sub-ledger and the
 * badge are allowed to disagree, and D-051's fourth residual says which wins.
 *
 * NOTHING HERE TOUCHES A BALLOT PAPER. A nomination is who may appear on it; a
 * mark is what a household chose, and the two are separate tables that share no
 * column. This screen names candidates and never voters.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    year: { type: Number, required: true },

    /** The position filter in force, or '' for the whole set. */
    filter: { type: String, required: true },

    /** One chip per position that has a nomination against it. */
    positions: { type: Array, required: true },

    /** Spans the UNFILTERED set — board 10 draws "5 pending review" over a slice holding two. */
    pending: { type: Number, required: true },
    rows: { type: Array, required: true },
    /** The nominations as the shared table reads them (M32 F5): seat by seat, oldest first within each. */
    table: { type: Object, required: true },

    canDecide: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },

    /** Only the nominations still waiting on a decision: where Open voting's blocked line points (M56 §3.4). */
    undecidedOnly: { type: Boolean, default: false },

    /** M56 §3.3: the ballots taking nominations now, with their seats, and whether this reader may add a candidate. */
    addTo: { type: Array, default: () => [] },
    canAdd: { type: Boolean, default: false },
})

/** The Add a candidate dialog (M56 §3.3). */
const adding = ref(false)

/*
 * Boards 9 to 12 share this sheet, and the ten Estate Console sheets share
 * nothing with each other — naming the wrong one renders this screen with no
 * table, no status badges and no filter chips at all.
 */
useWireframe('community-admin-03-elections-nominations-results-and-meetings')

const page = usePage()

/*
 * All six. This is the one governance screen with a real filter on it, so
 * `empty-filtered` is a state a reader can actually reach — by picking a
 * position whose only nomination has just been withdrawn — and it needs its own
 * sentence: "nothing has been lodged yet" and "nothing matches this seat" are
 * different facts, and showing the first to somebody who has clicked a chip
 * tells them their data is gone.
 */
const state = useScreenState({
    rows: () => props.table.total,
    filtered: () => props.filter !== '' || props.undecidedOnly,
})

const COLUMNS = [
    { key: 'name', label: 'Candidate', sortable: true },
    { key: 'position', label: 'Position sought', sortable: true },
    { key: 'nominator', label: 'Nominator', sortable: true },
    { key: 'seconder', label: 'Seconder', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'actions', label: '' },
]

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL. Production gives
 * each estate its own hostname and no prefix; local serves them all from one
 * host with the estate key in the path, so a hard-coded root is correct in
 * exactly one of the two — and cutting the URL that served this page cannot
 * address an estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

/** The control room, which is the screen the reader pressed "Review nominations" on. */
const controlRoomHref = computed(() => governance(`/elections/${props.year}`))

const listHref = computed(() => governance(`/elections/${props.year}/nominations`))

/** This list, filtered to one seat — or back to the whole set — in the same order, from page one (M32 F5). */
const chipHref = (position) => {
    const query = new URLSearchParams({ ...(position === '' ? {} : { position }), ...keptByTable(props.table) }).toString()

    return query ? `${listHref.value}?${query}` : listHref.value
}

/*
 * WHETHER CHIPS LIE PAST THE ROW'S RIGHT EDGE (dev server, 12 October). The row scrolls with its bar hidden, so with
 * sixteen seats "Phase 3 · F" was cut mid-word against the counter and nothing said the row went on. While it does,
 * the edge fades; scrolled to the end, or with everything in sight, it is drawn plain.
 */
const chipRow = ref(null)
const chipsCut = ref(false)

const measureChips = () => {
    const row = chipRow.value

    chipsCut.value = Boolean(row) && row.scrollLeft + row.clientWidth < row.scrollWidth - 2
}

onMounted(() => {
    measureChips()

    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
        window.addEventListener('resize', measureChips)
    }
})

onBeforeUnmount(() => {
    if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function') {
        window.removeEventListener('resize', measureChips)
    }
})

const nominationPath = (id, suffix) => governance(`/nominations/${id}${suffix}`)

/* ------------------------------------------------------------------ */
/* the three decisions */
/* ------------------------------------------------------------------ */

/** The viewer's own access, which is true of all three decisions. */
const accessBlockedBy = computed(() => (props.canDecide ? null : props.blockedReason))

/** Which row's refusal panel is open, by nomination id, or null. */
const deciding = ref(null)

/*
 * ONE FORM FOR BOTH REASONED OUTCOMES. `reject` and `query` take the same field
 * — what is wrong, or what is missing — and the service refuses an empty one on
 * either. Two forms holding one sentence would be two places for it to drift,
 * and the officer is writing the same note whichever button they end on.
 */
const decision = useForm({ reason: '' })

/** The row the panel is about, so the heading can name the candidate. */
const decidingRow = computed(() => props.rows.find((row) => row.id === deciding.value) ?? null)

const openDecision = (id) => {
    deciding.value = deciding.value === id ? null : id
    decision.reason = ''
    decision.clearErrors()
}

const closeDecision = () => {
    deciding.value = null
}

/**
 * Put a candidate on the paper.
 *
 * No panel and no reason, because there is nothing to explain: the eligibility
 * snapshot is written on an acceptance too, exactly as it is on a refusal. A
 * record that only kept the arithmetic behind refusals could not answer the more
 * awkward question — why this candidate was let through — and that is the one a
 * losing candidate asks.
 */
const accept = (id) => {
    if (accessBlockedBy.value !== null) {
        return
    }

    router.post(nominationPath(id, '/accept'), {}, { preserveScroll: true })
}

/**
 * Refuse, or ask for more, with the same sentence.
 *
 * A form submits on Enter from any field and a disabled button does not stop
 * it, so both branches re-ask what their buttons ask. The routes are gated on
 * `estate.governance.update` and the service refuses an empty reason
 * independently — this is only about not sending a press that will bounce.
 */
const submitDecision = (verb) => {
    if (accessBlockedBy.value !== null || deciding.value === null) {
        return
    }

    decision.post(nominationPath(deciding.value, verb === 'query' ? '/query' : '/reject'), {
        preserveScroll: true,
        onSuccess: () => closeDecision(),
    })
}

/*
 * THE DECISION'S REFUSAL IS IN ITS DIALOG'S AMBER BAND (Hassam, 12 Oct, the final refusal rule, D-235): the dialog
 * stays open with the reason typed, and the sentence stays until it is put right. The reason draws no error of its own,
 * so the band says the first.
 */
const decisionRefusal = computed(() => Object.values(decision.errors)[0] ?? null)

/*
 * A REFUSAL OF A DECISION IS A TOAST, TOP RIGHT (M95 §2), where it was a line pushed into the page: Accept, which asks
 * nothing first. Amber, because red is kept for panic and life safety (Hassam, 11 Oct). Never while the decision's
 * dialog or Add a candidate is open: each shows its own refusal in its band, and never a toast as well (D-235).
 */
const refusalInADialog = computed(() => adding.value || decidingRow.value !== null)

watch(
    () => page.props.errors?.nomination,
    (text) => {
        if (refusalInADialog.value) {
            return
        }

        if (text) {
            toast(text, { tone: 'warning' })
        }
    },
    { immediate: true },
)

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['election'] })
</script>

<template>
    <Head :title="`Nominations for the ${year} election`" />

    <!-- The shared back control, top left (M95 §2): the election this list belongs to. -->
    <EstateConsole :title="`Nominations for the ${year} election`" :estate-name="estate.name" active="governance" :back="{ href: controlRoomHref, label: 'Back to the election' }">

        <!--
          ADD A CANDIDATE (M56 §3.3), drawn only while a ballot this year takes nominations (Law 1: what cannot be done
          here is not drawn), and disabled with its reason for a reader who may not.
        -->
        <template v-if="addTo.length && canAdd" #actions>
            <!-- M96 level 3: a role that can never add a candidate has no button at all. -->
            <button type="button" class="btn-primary-sm" title="Put a resident forward for a seat, proposed and seconded by residents." @click="adding = true">
                <span>Add a candidate</span>
            </button>
        </template>

        <AddCandidate v-if="canAdd && addTo.length" :open="adding" :ballots="addTo" :root="governance('')" @close="adding = false" />

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Nominations are not part of your role’s access"
            body="A nomination names a household, who proposed them and the arrears ageing their eligibility was judged against, so it opens only to roles that hold Governance. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The nominations could not be read"
            body="The estate database did not answer. Nobody has been approved, nobody has been refused and no candidate has been put on or taken off a paper. This is a read that failed, and trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!-- Arrived from Open voting's blocked line (M56 §3.4): only the rows it waits on, and the way back to all. -->
            <p v-if="undecidedOnly" class="gov-flash">
                Showing the nominations still waiting on a decision. Approve or reject each one, then Open voting works.
                <Link :href="listHref" class="text-link-sm">Show all</Link>
            </p>

            <div style="display: flex; gap: 10px; margin-bottom: 16px">
                <!--
                  The chip row, generated from the positions that actually have
                  a nomination against them — which is the rule brief-10 states
                  for it, and it produces thirteen chips where the board draws
                  two. Board 10's own slice holds five rows covering two seats;
                  the estate has fourteen positions and twenty-two candidates.

                  It scrolls rather than wrapping. Wrapping would put the table
                  a line lower than the approved design, and clipping would
                  leave a filter an estate cannot use on a seat it is actually
                  electing. Every chip is a real link, so tabbing to one scrolls
                  it into view; the bar itself is hidden because a visible one
                  would add its own height to the row and move the table anyway.
                -->
                <div ref="chipRow" class="chip-scroll" :class="{ cut: chipsCut }" @scroll.passive="measureChips">
                    <Link
                        :href="chipHref('')"
                        class="f-chip2"
                        :class="{ active: filter === '' }"
                        title="Every nomination lodged for this election, whatever seat it is for."
                    >
                        All positions
                    </Link>
                    <Link
                        v-for="position in positions"
                        :key="position"
                        :href="chipHref(position)"
                        class="f-chip2"
                        :class="{ active: filter === position }"
                        :title="`Show only the candidates standing for ${position}.`"
                    >
                        {{ position }}
                    </Link>
                </div>

                <!--
                  The counter, and it is a readout rather than a control: it
                  spans the unfiltered set, so pressing it could only ever show
                  what "All positions" already shows. The board draws it as a
                  chip in the same row, and it stays one.
                -->
                <div
                    class="f-chip2"
                    style="margin-left: auto; background: var(--amber-100); color: var(--amber-700)"
                    :title="`${pending} nomination${pending === 1 ? ' has' : 's have'} been lodged and not yet decided, across every seat on this election.`"
                >
                    {{ pending }} pending
                </div>
            </div>

            <!--
              REFUSE, OR ASK FOR MORE, in the shared dialog (M95 §2). One candidate at a time: each decision is about
              one member, and the reason is the sentence they will be given. The dialog has one confirm, so asking for
              more information is the second button inside it.
            -->
            <ConsoleDialog
                :open="decidingRow !== null"
                :title="decidingRow ? `Refuse ${decidingRow.name}, or ask for more?` : ''"
                size="form"
                confirm-label="Reject"
                busy-label="Recording…"
                :busy="decision.processing"
                :confirm-blocked-reason="accessBlockedBy ?? (decision.reason.trim() === '' ? 'Write the reason first.' : null)"
                :error="decisionRefusal"
                @confirm="submitDecision('reject')"
                @cancel="closeDecision"
            >
                <template v-if="decidingRow">
                    <p class="act-head">
                        {{ decidingRow.name }}, for {{ decidingRow.position }}. A refusal goes on a member’s record and
                        has to be explainable to them. A request for more information does not, and can still become
                        either of the other two.
                    </p>

                    <RequiredMark legend />
                    <div class="act-field">
                        <label for="decision-reason">
                            The reason, in the words the candidate will be given: "more than 90 days in arrears",
                            "proposer is not a registered householder", "seconder’s signature missing" <RequiredMark />
                        </label>
                        <input id="decision-reason" v-model="decision.reason" type="text" maxlength="190" required placeholder="e.g. more than 90 days in arrears" />
                    </div>

                    <button
                        type="button"
                        class="btn-outline-sm"
                        :disabled="accessBlockedBy !== null || decision.processing || decision.reason.trim() === ''"
                        :title="accessBlockedBy ?? 'Ask the candidate for what is missing. It is not a refusal and does not go on their record as one.'"
                        @click="submitDecision('query')"
                    >
                        <span>Ask for more information instead</span>
                    </button>
                </template>
            </ConsoleDialog>

            <!--
              Empty because a chip excluded everything, which is a different
              screen from an election nobody has been nominated for: one offers
              the whole list back, the other says nothing has been lodged.
            -->
            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                :title="`No candidate is standing for ${filter}`"
                body="Nominations have been lodged for this election, but none is for this seat. The returning officer needs to know before nominations close, because a seat with no candidate cannot be filled by the ballot."
                action-label="Show every position"
                @action="router.get(listHref)"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No nominations have been lodged"
                body="Nobody has been put forward for any seat on this election yet. Nominations are lodged while they are open, and the control room shows until when. There is nothing here to decide until one arrives."
            />

            <DataTable v-else :columns="COLUMNS" :rows="rows" :table="table" noun="nominations">
                <template #cell-name="{ row }">
                    <div class="res-cell">
                        <div class="res-avatar">{{ row.initials }}</div>
                        <div>
                            <div class="res-name">{{ row.name }}</div>
                            <!-- The phase is the PROPERTY's, not the
                                 nomination's. See the note at the top. -->
                            <div class="res-sub">{{ row.sub }}</div>
                        </div>
                    </div>
                </template>

                <template #cell-status="{ row }">
                    <!-- The state in the vocabulary's words (M96 §2), and a refusal's recorded reason under it, as
                         it was written down. -->
                    <!-- It leads to the nomination's record, where the decision and its eligibility are read (batch 1 item 10). -->
                    <Link :href="nominationPath(row.id, '')" class="status-badge nm-badge" :class="row.status" :title="`Open the nomination of ${row.name}.`">{{ nominationWord(row.status, row.status_label) }}</Link>
                    <div v-if="row.status === 'rejected' && row.reason" class="nm-reason">{{ row.reason }}</div>
                </template>

                <template #cell-actions="{ row }">
                    <!--
                      Status-driven, which is the board's own rule: a
                      pending row gets the tick and the cross, a decided
                      one gets a link. The tick posts outright; the cross
                      opens the panel, because a refusal without a reason
                      is refused by the service and the officer should
                      find that out before they press rather than after.
                    -->
                    <!-- M96 level 3: the decision is drawn only for a role that may take it; any other reads the record. -->
                    <div v-if="row.decidable && canDecide" class="row-actions">
                        <button
                            type="button"
                            class="icon-btn approve"
                            :disabled="accessBlockedBy !== null"
                            :title="
                                accessBlockedBy ??
                                `Approve ${row.name} onto the ballot paper for ${row.position}. The arrears ageing the estate holds today is recorded alongside, so the estate can explain why this candidate was let through.`
                            "
                            :aria-label="`Approve ${row.name}`"
                            @click="accept(row.id)"
                        >
                            <svg viewBox="0 0 24 24" fill="none">
                                <polyline
                                    points="20 6 9 17 4 12"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </button>

                        <button
                            type="button"
                            class="icon-btn reject"
                            :disabled="accessBlockedBy !== null"
                            :title="
                                accessBlockedBy ??
                                `Refuse ${row.name}, or ask for more before deciding. Either way it needs a reason the candidate can be given.`
                            "
                            :aria-label="`Refuse ${row.name}, with a reason`"
                            @click="openDecision(row.id)"
                        >
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M6 6l12 12M18 6L6 18"
                                    stroke="currentColor"
                                    stroke-width="2.4"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </button>
                    </div>

                    <div v-else class="row-actions">
                        <Link
                            :href="nominationPath(row.id, '')"
                            class="text-link-sm"
                            title="The decision, who took it and when, and the eligibility snapshot it was taken against."
                        >
                            View
                        </Link>
                    </div>
                </template>
            </DataTable>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its chips, its row actions and its back affordance as <div>s;
 * here they are links and buttons, which arrive wearing a border, buttonface
 * grey and the browser's own font. The board's .f-chip2, .icon-btn,
 * .btn-primary-sm and .text-link-sm supply everything visible, and app.css has
 * already taken the UA's underline and blue off every anchor on a board page.
 *
 * THE BORDER RESET NAMES ONLY WHAT THE BOARD GIVES NO BORDER TO.
 * `.btn-outline-sm` carries `border:1.5px solid var(--navy-200)` and a scoped
 * element selector would out-specify that and rub the outline off the control —
 * a real difference from the approved design, small enough to survive a passing
 * measurement. See D-045.
 */
button {
    font: inherit;
}

button.btn-primary-sm,
button.icon-btn,
button.text-link-sm {
    border: 0;
    cursor: pointer;
}

button.text-link-sm {
    background: none;
    padding: 0;
}

button.btn-outline-sm {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/* Greyed when it cannot be used yet (M96 level 2): only the cursor changed. */
button.btn-primary-sm[disabled],
button.btn-outline-sm[disabled] {
    opacity: 0.55;
}

/* "Action required" had no badge of its own and read as loose bold text: amber, as Pending is, because someone must act. */
.status-badge.more_info {
    background: var(--amber-100);
    color: var(--amber-700);
}

/* The tick and the cross 8 px apart, not touching distance (the layout check's line). */
.row-actions.row-actions {
    gap: 8px;
}

/*
 * HASSAM'S THREE WEIGHTS (11 Oct). Solid red is an open panic alone; Rejected, Failed and a destructive act are muted
 * red, serious and clearly not an emergency; errors, warnings and refusals amber. The cross refuses a member, so it is
 * muted red, as board 10 drew it; a Rejected badge is muted red too, one selector stronger than app.css's amber.
 */
.row-actions .icon-btn.reject {
    background: var(--red-100);
}

.row-actions .icon-btn.reject svg {
    color: var(--red-700);
}

.nm-badge.status-badge.rejected {
    background: var(--red-100);
    color: var(--red-700);
}

/*
 * The chip row, which the board fits in one line with four chips and this
 * screen has to fit with fifteen.
 *
 * Same height, same gap, same left edge and the same right-aligned counter
 * beside it — the only difference is that the overflow scrolls instead of being
 * cut off. The bar is hidden because a visible one adds its own height to the
 * row on Windows, which would push the table down and move every figure in it;
 * the chips stay reachable because each is a real link and the browser scrolls
 * a focused one into view.
 */
.chip-scroll {
    display: flex;
    gap: 10px;
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: none;
}

.chip-scroll::-webkit-scrollbar {
    display: none;
}

/* A refusal's recorded reason under its badge: muted words, not the board's link-blue sub-line (Security, 12 October). */
.nm-reason {
    font-size: 11px;
    color: var(--slate-600);
    margin-top: 3px;
    line-height: 1.4;
}

/* More chips past the edge: the last one in sight fades out rather than being cut mid-word (dev server, 12 October). */
.chip-scroll.cut {
    -webkit-mask-image: linear-gradient(to right, #000 calc(100% - 56px), transparent);
    mask-image: linear-gradient(to right, #000 calc(100% - 56px), transparent);
}

/*
 * A CHIP KEEPS ITS OWN WIDTH. Without this, flex shrank every chip to fit the
 * row before the row ever scrolled: an estate electing thirteen seats drew
 * thirteen squeezed pills with their labels wrapped onto four lines and the last
 * one clipped mid-word — found confirming this board's residual (13 E1). The row
 * scrolls; the chips stay the board's shape.
 */
.f-chip2 {
    flex-shrink: 0;
    white-space: nowrap;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no flash, no refusal and no panel,
 * because nothing has ever been pressed on it. Kept to the tokens the boards
 * define and to shapes they already use — the panel is the white card with the
 * navy-100 edge that .data-table already is, and the type sizes are .res-sub's
 * and .status-badge's.
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
    max-width: 620px;
}

.act-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.act-field input {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.act-field input::placeholder {
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
