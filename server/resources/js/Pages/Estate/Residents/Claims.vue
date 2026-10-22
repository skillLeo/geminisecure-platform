<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { keptByTable } from '../../../lib/tableParams'
import { toast } from '../../../lib/toasts.js'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Unit claim review — board screen community-admin-31.
 *
 * EVERY CARD IS A COMPARISON, AND `mismatch` IS DERIVED SERVER-SIDE, NEVER HERE.
 * `claim.submitted.mismatch` and `claim.record.mismatch` are independent
 * booleans off `Residents::claimsBoard()` — card 1 flags only the right column,
 * card 2 flags neither, card 3 flags both — and this page does no comparing of
 * its own. A page that re-derived "does the name match" from the two columns'
 * own rows would be a second place for that judgement to drift from the one a
 * reviewer is actually shown.
 *
 * THE ARREARS SUFFIX ON A NAME IS Q-014, NOT THIS PAGE'S DECISION. Where the
 * third card's On-record row reads "Tanya Simms — 90+ days arrears" on the
 * board, this screen prints whatever `row.value` the server composed —
 * `Residents::recordNameWithStanding()` appends the bucket only for a viewer
 * holding `estate.dues_ledger.view`, per `ARREARS_FLAG_NEEDS_LEDGER_ACCESS`.
 * Measured as the Property Manager, board 31's own persona and the role Q-014
 * is about, that suffix is absent — the identity flag stays and the money does
 * not, which is the whole point of the flag standing at `true`.
 *
 * TWO GATES, NOT ONE — D-013's WHOLE REASON FOR EXISTING. Approving a claim
 * binds a person to a household and needs `estate.residents.approve`; refusing
 * one and asking for a document are both `estate.residents.update` and need
 * only that. The Secretary holds plain Full on Residents (D-053) — every
 * refuse and every document request on this screen and neither approval — and
 * this page asks the two permissions separately for exactly that household.
 *
 * REFUSING NEEDS A REASON THE BOARD DRAWS NO FIELD FOR. `Residents::rejectClaim()`
 * throws on an empty one, so a plain "Reject" button that posted nothing would
 * bounce every time it was pressed. The reason field is AUTHORED — opened only
 * once Reject is pressed, never on the still image this board is — the same
 * shape Facilities/Ticket.vue uses for its own reopen reason.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    estate: { type: Object, required: true },
    title: { type: String, required: true },
    pending_count: { type: Number, required: true },
    claims: { type: Array, required: true },
    /** The queue as a table (M32 F5, F8): newest first unless its headings ask otherwise, a page at a time. */
    table: { type: Object, required: true },
    canApprove: { type: Boolean, required: true },
    canReview: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

/*
 * Board 31 lives in the eighth Community Admin sheet, alongside the rest of
 * "Reports, Unit Claims and Notices" — a different sheet from the residents
 * directory and detail screens either side of it in the sidebar.
 */
useWireframe('community-admin-08-reports-unit-claims-and-notices')

// A claim made in the app, or decided in another console, moves this queue by itself (M18H §6).
useLiveReload({ entities: ['resident', 'account'] })

const page = usePage()

/**
 * All six. The queue's search (M32 F5) is its one filter: a search that matched
 * nothing is `empty-filtered`, and not `empty`, which is no claim waiting at all.
 */
const state = useScreenState({
    rows: () => props.claims.length,
    filtered: () => (props.table.q ?? '') !== '',
})

/*
 * THE QUEUE, AS A TABLE (M32 F5, F8). Each row is one claim on this page, in the order its headings ask for — newest
 * first unless they ask otherwise — and its "Review" goes to the claim's own card below, where it is decided. The cards
 * follow the table: the same claims, in the same order.
 */
const COLUMNS = [
    { key: 'made', label: 'Made', sortable: true },
    { key: 'claimant', label: 'Claimant', sortable: true },
    { key: 'lot', label: 'Phase and lot', sortable: true },
    { key: 'type', label: 'Claim on', sortable: true },
    { key: 'match', label: 'Matched the register', sortable: true },
    { key: 'review', label: '' },
]

const searchParams = computed(() => keptByTable(props.table))
const noun = computed(() => (props.table.total === 1 ? 'claim' : 'claims'))

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL. Production gives
 * each estate its own hostname and no prefix; local serves every estate from
 * one host with the estate key in the path, as /estate/{key}/residents/claims.
 * Cutting the current URL at /residents is correct in both.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/residents')))
const residentsPath = computed(() => `${root.value}/residents`)
const claimsPath = computed(() => `${root.value}/residents/claims`)
const claimPath = (id, action) => `${root.value}/residents/claims/${id}/${action}`

/**
 * Why an action cannot be pressed, keyed on which one it is.
 *
 * THE APPROVE REFUSAL IS THE SERVER'S OWN SENTENCE — it already names the
 * approve gate exactly. The refuse-and-document refusal is authored here
 * because no server sentence on this payload is about `update`; `blockedReason`
 * is written for approve and would misname the gate if reused for the other two.
 */
const NO_REVIEW_ACCESS =
    'Refusing a claim or asking for a document changes its record, so it needs Residents update access. You are able to read this screen.'

const blockedFor = (key) => {
    if (key === 'approve') {
        return props.canApprove ? null : props.blockedReason
    }

    return props.canReview ? null : NO_REVIEW_ACCESS
}

/* ------------------------------------------------------------------ */
/* approve (asked first, it tells the claimant) and request-document (one press, nobody is sent anything) */
/* ------------------------------------------------------------------ */

/*
 * THE ACCESS THE APPROVAL GIVES (household invites, stage 2). Every card says it — `notes.access`, the level of
 * the register entry the approval binds to — and an office that can change a person's access afterwards may
 * choose it here instead, so one press never gives a Dependent or a Helper everything a Member has.
 */
const LEVELS = [
    { value: 'member', label: 'Member' },
    { value: 'dependent', label: 'Dependent' },
    { value: 'helper', label: 'Helper' },
]

const chosenLevel = ref({})

const mayChooseLevel = (claim) =>
    props.canReview && !!claim.notes?.access?.open && !claim.notes?.access?.locked

const levelFor = (claim) => chosenLevel.value[claim.id] ?? claim.notes?.access?.level ?? 'member'

const setLevel = (claim, level) => {
    chosenLevel.value = { ...chosenLevel.value, [claim.id]: level }
}

/*
 * ONE POST PER PRESS. A second press on Approve while the first is on its way would post twice — one of the
 * race paths behind a household with two primaries — so the card's buttons wait for the answer.
 */
const approving = ref(null)

/*
 * A REFUSAL OF AN OPEN DIALOG IS SAID INSIDE IT (Hassam, 12 Oct): approve's and reject's, each in its own modal's amber
 * band, the modal staying open. A toast that stays is for a press with no dialog open: Request ID document, made
 * straight from the card (M95 §2, M98 sweep).
 */
const refused = (errors, fallback) => {
    toast(errors.claim ?? errors.document ?? Object.values(errors)[0] ?? fallback, { tone: 'error' })
}

const approveRefusal = ref(null)

const closeApprove = () => {
    askingApprove.value = null
    approveRefusal.value = null
}

/*
 * ONE VOCABULARY (M96, M98 sweep): a claim waiting on the office is Pending, an approved one Confirmed, a refused one
 * Rejected, never the stored "Pending review" or "Approved".
 */
const CLAIM_WORDS = { pending: 'Pending', approved: 'Confirmed', rejected: 'Rejected' }

const claimWord = (claim) => CLAIM_WORDS[claim.status] ?? claim.status_label

const approveLabel = (claim) => claim.notes?.invited?.approve_label ?? 'Approve claim'

/*
 * APPROVING TELLS THE CLAIMANT, SO IT ASKS FIRST (DR-072, the register's Part 7). The approval pushes "Your account is
 * approved" to the account that made the claim, and emails it where that person turned email on. A control that starts
 * contact confirms first, naming who is told and how: the press opens the question, and only its own button approves,
 * with the access chosen on the card.
 */
const askingApprove = ref(null)

const approveTitle = computed(() => {
    const claim = askingApprove.value

    return claim === null ? '' : `Approve ${claim.claimant}${claim.lot ? ` for ${claim.lot}` : ''}?`
})

const accessOnApproval = (claim) =>
    mayChooseLevel(claim) ? LEVELS.find((level) => level.value === levelFor(claim))?.label : claim.notes?.access?.label

const approve = (claim) => {
    if (blockedFor('approve') !== null || approving.value !== null) {
        return
    }

    approveRefusal.value = null
    askingApprove.value = claim
}

const postApprove = () => {
    const claim = askingApprove.value

    if (claim === null || blockedFor('approve') !== null || approving.value !== null) {
        return
    }

    approving.value = claim.id
    approveRefusal.value = null

    router.post(
        claimPath(claim.id, 'approve'),
        mayChooseLevel(claim) ? { access_level: levelFor(claim) } : {},
        {
            preserveScroll: true,
            onSuccess: () => {
                askingApprove.value = null
            },
            onError: (errors) => {
                approveRefusal.value = errors.claim ?? Object.values(errors)[0] ?? 'The claim was not approved.'
            },
            onFinish: () => {
                approving.value = null
            },
        }
    )
}

const requestDocument = (claim) => {
    if (blockedFor('document') !== null) {
        return
    }

    // The service defaults the kind to "photo ID" when none is sent, and the
    // board draws no field to type one into — so none is sent.
    router.post(claimPath(claim.id, 'document'), {}, {
        preserveScroll: true,
        onError: (errors) => refused(errors, 'The document was not asked for.'),
    })
}

/* ------------------------------------------------------------------ */
/* reject: authored, because a reason is required and the board has no field */
/* ------------------------------------------------------------------ */

/** Which claim's refuse-reason modal is open, or null (M95 §1: it was a panel inside the card). */
const rejecting = ref(null)

const rejectingClaim = computed(() => props.claims.find((claim) => claim.id === rejecting.value) ?? null)

const rejectForm = useForm({ reason: '' })

const openReject = (claim) => {
    if (blockedFor('reject') !== null) {
        return
    }

    rejecting.value = claim.id
    rejectForm.reset()
    rejectForm.clearErrors()
}

const cancelReject = () => {
    rejecting.value = null
    rejectForm.reset()
    rejectForm.clearErrors()
}

const submitReject = () => {
    const claim = rejectingClaim.value

    if (claim === null || rejectForm.reason.trim() === '') {
        return
    }

    rejectForm.post(claimPath(claim.id, 'reject'), {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null
        },
    })
}

/** Which action a card's outline button is — 'reject' or 'document'. */
const outlineKey = (claim) => claim.actions.find((a) => a.style === 'outline')?.key ?? 'document'

const handleOutline = (claim) => {
    const key = outlineKey(claim)

    if (key === 'reject') {
        openReject(claim)
    } else {
        requestDocument(claim)
    }
}
</script>

<template>
    <Head :title="title" />

    <EstateConsole
        :title="title"
        :estate-name="estate.name"
        active="residents"
        search-placeholder="Search claims by name, lot or phone…"
        :search-route="claimsPath"
        :search-value="table.q ?? ''"
        :search-params="searchParams"
        :back="{ href: residentsPath, label: 'Back to residents' }"
    >
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Unit claims are not part of your role’s access"
            body="A claim names who is asking to be recognised against a household and what the register already holds, so it opens only to roles that hold Residents. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The claim queue could not be read"
            body="The estate database did not answer. No claim has been decided and nobody has been bound to a household: this is a read that failed, and running it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No claim matches that search"
            body="The search looks at the claimant's name, the phase and lot they gave, and their phone number. Clear it to see every claim waiting."
            action-label="Clear the search"
            @action="router.get(claimsPath)"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No unit claims are waiting on a decision"
            body="A claim is raised when somebody's own account of a household doesn't match the register exactly. There is nothing self-reported that needs reconciling right now."
            action-label="Back to Residents"
            @action="router.get(residentsPath)"
        />

        <template v-else>
            <DataTable :columns="COLUMNS" :rows="claims" :table="table" :noun="noun" class="claims-queue">
                <template #cell-claimant="{ row }">
                    {{ row.claimant }}
                    <div v-if="row.notes?.invited" class="claim-invited-pill claims-queue-pill">From an invite</div>
                </template>
                <template #cell-lot="{ row }">{{ row.lot || '—' }}</template>
                <template #cell-type="{ row }">{{ row.type_label }}</template>
                <template #cell-match="{ row }">{{ row.match_label }}</template>
                <template #cell-review="{ row }">
                    <a :href="`#claim-${row.id}`" class="text-link-sm">Review</a>
                </template>
            </DataTable>

            <div v-for="claim in claims" :id="`claim-${claim.id}`" :key="claim.id" class="claim-card" :data-claim="claim.id">
                <div class="claim-top">
                    <div class="claim-title">{{ claim.title }}</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center">
                        <div v-if="claim.notes?.invited" class="claim-invited-pill">From an invite</div>
                        <div class="status-badge" :class="claim.status">{{ claimWord(claim) }}</div>
                    </div>
                </div>

                <!--
                  A CLAIM MADE FROM AN INVITE: who invited, the access their
                  entry holds, and what the sign-in proved. Every sentence is the
                  server's (`ResidentInvites::claimNotes`).
                -->
                <div v-if="claim.notes?.invited" class="claim-notes">
                    <div class="compare-row">
                        <span>Invite</span><span>{{ claim.notes.invited.by_line }}</span>
                    </div>
                    <div v-if="claim.notes.invited.level_label" class="compare-row">
                        <span>Offered access</span><span>{{ claim.notes.invited.level_label }}</span>
                    </div>
                    <div class="compare-row">
                        <span>Proof</span><span>{{ claim.notes.invited.proof_line }}</span>
                    </div>
                </div>

                <!-- G3: somebody of this name at this home had their app access ended by the office. -->
                <div v-if="claim.notes?.revoked_name_on" class="claim-revoked" role="note">
                    App access for a person of this name at this unit was ended by the estate office on
                    {{ claim.notes.revoked_name_on }}. Check this is somebody else before approving.
                </div>

                <div class="compare-grid">
                    <div class="compare-col" :class="{ mismatch: claim.submitted.mismatch }">
                        <div class="cc-lbl">{{ claim.submitted.label }}</div>
                        <div v-for="row in claim.submitted.rows" :key="row.label" class="compare-row">
                            <span>{{ row.label }}</span><span>{{ row.value }}</span>
                        </div>
                    </div>
                    <div class="compare-col" :class="{ mismatch: claim.record.mismatch }">
                        <div class="cc-lbl">
                            {{ claim.record.label }}
                            <span v-if="claim.record_sample" class="status-badge draft" title="Sample data.">sample</span>
                        </div>
                        <div v-for="row in claim.record.rows" :key="row.label" class="compare-row">
                            <span>{{ row.label }}</span><span>{{ row.value }}</span>
                        </div>
                    </div>
                </div>

                <!--
                  ON EVERY CARD, INVITED OR NOT: the access this approval gives.
                  A select for an office that may change it afterwards; the
                  primary's is fixed, because a primary is always a Member.
                -->
                <div v-if="claim.notes?.access" class="claim-access">
                    <label :for="`claim-level-${claim.id}`">Access on approval</label>
                    <select
                        v-if="mayChooseLevel(claim)"
                        :id="`claim-level-${claim.id}`"
                        :value="levelFor(claim)"
                        :disabled="approving !== null"
                        @change="setLevel(claim, $event.target.value)"
                    >
                        <option v-for="level in LEVELS" :key="level.value" :value="level.value">{{ level.label }}</option>
                    </select>
                    <span v-else :id="`claim-level-${claim.id}`" class="claim-access-fixed">{{ claim.notes.access.label }}</span>
                </div>

                <!--
                  M96 levels (M98 pass): Reject or Request ID document only for a role that may refuse or ask, Approve
                  only for one that may approve (D-013's two gates); a role that may do neither sees who decides, in place
                  of two buttons that refuse. Both were greyed with their reason on hover only.
                -->
                <p v-if="!canReview && !canApprove" class="claim-who-decides">An officer who can approve residents decides this claim. You can read it.</p>

                <div v-else class="claim-actions">
                    <button
                        v-if="canReview"
                        type="button"
                        class="stack-btn outline"
                        :class="{ 'claim-reject': outlineKey(claim) === 'reject' }"
                        :title="
                            outlineKey(claim) === 'reject'
                                ? 'Refuse this claim, with a reason that stays on the record.'
                                : 'Ask the claimant for a photo ID.'
                        "
                        @click="handleOutline(claim)"
                    >
                        <svg v-if="outlineKey(claim) === 'reject'" viewBox="0 0 24 24" fill="none">
                            <path
                                d="M6 6l12 12M18 6L6 18"
                                stroke="currentColor"
                                stroke-width="2.2"
                                stroke-linecap="round"
                            />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>{{ outlineKey(claim) === 'reject' ? 'Reject' : 'Request ID document' }}</span>
                    </button>

                    <button
                        v-if="canApprove"
                        type="button"
                        class="stack-btn primary"
                        :disabled="approving !== null"
                        :title="approving !== null ? 'Another approval is being recorded.' : 'Approve this claim.'"
                        @click="approve(claim)"
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
                        <span>{{ approving === claim.id ? 'Approving…' : approveLabel(claim) }}</span>
                    </button>
                </div>
            </div>

            <ConsoleDialog
                :open="askingApprove !== null"
                :title="approveTitle"
                :confirm-label="askingApprove ? approveLabel(askingApprove) : 'Approve claim'"
                busy-label="Approving…"
                :busy="approving !== null"
                :error="approveRefusal"
                @confirm="postApprove"
                @cancel="closeApprove"
            >
                <template v-if="askingApprove">
                    <p style="margin: 0">
                        {{ askingApprove.claimant }} is told in the app that their account is approved, and by email if they asked for it. Nobody else is told.
                    </p>
                    <p v-if="accessOnApproval(askingApprove)" style="margin: 8px 0 0">Access on approval: {{ accessOnApproval(askingApprove) }}.</p>
                </template>
            </ConsoleDialog>

            <!-- Refusing a claim, in the console's modal (M95 §1): it was a panel that opened inside the card. -->
            <ConsoleDialog
                :open="rejectingClaim !== null"
                :title="rejectingClaim ? `Refuse ${rejectingClaim.claimant}'s claim?` : ''"
                tone="danger"
                confirm-label="Refuse the claim"
                busy-label="Refusing…"
                cancel-label="Keep it"
                :busy="rejectForm.processing"
                :dirty="rejectForm.reason.trim() !== ''"
                discard-title="Discard this reason?"
                :confirm-blocked-reason="rejectForm.reason.trim() === '' ? 'Say why the claim is refused.' : null"
                :error="rejectForm.errors.claim ?? null"
                @confirm="submitReject"
                @cancel="cancelReject"
            >
                <form class="reject-panel" @submit.prevent="submitReject">
                    <RequiredMark legend />
                    <div class="m-field">
                        <label for="reject-reason">Reason for refusing (it stays on the claim's record) <RequiredMark /></label>
                        <div class="m-input" :class="{ focused: rejectForm.reason !== '' }">
                            <span>
                                <input
                                    id="reject-reason"
                                    v-model="rejectForm.reason"
                                    type="text"
                                    required
                                    maxlength="190"
                                    placeholder="e.g. The name does not match the title deed on file."
                                />
                            </span>
                        </div>
                        <div v-if="rejectForm.errors.reason" class="reject-error">{{ rejectForm.errors.reason }}</div>
                    </div>
                </form>
            </ConsoleDialog>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its back affordance and every card's two actions as <div>s;
 * here they are a link and a set of buttons, each arriving with the browser's
 * own font, border and buttonface grey. .stack-btn declares a background for
 * .primary and both a background and a border for .outline — its own border IS
 * the variant, so only the primary style and the authored panel's own controls
 * are reset here. See D-045.
 */
button.stack-btn.primary {
    border: 0;
}

button.stack-btn {
    font: inherit;
    cursor: pointer;
}

button.stack-btn[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no flash, no refusal and no reject
 * panel of any kind, because it is a still image of a screen nobody has pressed
 * anything on. Kept to the tokens the boards do define — the reason field
 * borrows .m-field/.m-input from this same sheet, and the panel sits in the
 * same card padding .claim-actions already uses.
 */
.claims-flash,
.claims-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.claims-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.claims-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.reject-panel {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.reject-panel .m-field {
    margin-bottom: 0;
}

/* The reason's field across the modal: its span took only its placeholder's width, which clipped it (seen on the dev server). */
.reject-panel .m-input span {
    flex: 1;
    min-width: 0;
}

.reject-panel input {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
}

.reject-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--amber-700);
    margin-top: 5px;
}

/*
 * HASSAM'S RED RULE (11 Oct, final): a destructive control is a red outline, serious and not an emergency (solid red is
 * an open panic's alone). Reject is one; Request ID document keeps the board's outline.
 */
button.stack-btn.outline.claim-reject {
    border-color: var(--red-700);
}

button.stack-btn.outline.claim-reject span,
button.stack-btn.outline.claim-reject svg {
    color: var(--red-700);
}

/* M96 level 3: who decides the claim, where a role that can do neither act had two greyed buttons. */
.claim-who-decides {
    margin: 12px 0 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-600);
}

/* Household invites, stage 2: the Invited pill, its three rows, the revoked-name warning and the access line. */
.claim-invited-pill {
    font-size: 10.5px;
    font-weight: 700;
    border-radius: 999px;
    padding: 3px 9px;
    background: var(--navy-100);
    color: var(--navy-700);
}

.claim-notes {
    margin: 0 0 12px;
}

.claim-revoked {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
    background: var(--amber-100);
    color: var(--amber-700);
}

.claim-access {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 12px 0;
    font-size: 12px;
}

.claim-access label {
    font-weight: 700;
    color: var(--navy-900);
}

.claim-access select {
    height: 34px;
    border: 1.5px solid var(--navy-200);
    border-radius: 8px;
    background: var(--white);
    padding: 0 10px;
    font-family: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.claim-access-fixed {
    color: var(--slate-600);
}

.claim-card-refusal {
    margin: 0 0 12px;
}

/* M32 F1: the `sample` chip — see the household page (`Show.vue`) for why it is here and not in app.css. */
.status-badge.draft {
    background: var(--navy-100);
    color: var(--navy-700);
}

/* M32 F5: the queue's table, above the cards it leads to. */
.claims-queue {
    margin-bottom: 18px;
}

.claims-queue-pill {
    display: inline-block;
    margin-left: 6px;
}

/*
 * ON A PHONE (batch 1 item 12, 12 Oct): a claim's two comparison columns one above the other, where the board's pair
 * left each about 145 px at 390 and a long email ran past its column; the decisions, the title's pills and the access
 * line wrap, where one row was wider than the card; the queue keeps readable columns and scrolls inside its own card,
 * as on the maintenance queue. Doubled classes outrank the board's `body.wf-… .claim-top`.
 */
@media (max-width: 640px) {
    .compare-grid.compare-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .compare-row.compare-row span {
        overflow-wrap: anywhere;
    }

    .compare-row.compare-row {
        gap: 10px;
    }

    .claim-actions.claim-actions {
        flex-wrap: wrap;
        justify-content: flex-start;
    }

    .claim-top.claim-top {
        flex-wrap: wrap;
        gap: 6px;
    }

    .claim-access {
        flex-wrap: wrap;
    }

    :deep(.data-table) {
        min-width: 560px;
    }
}
</style>
