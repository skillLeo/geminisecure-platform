<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import AddResidentFields from '../../../Components/AddResidentFields.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useAddResident } from '../../../composables/useAddResident.js'
import { useLiveReload } from '../../../composables/useLiveReload'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { toast } from '../../../lib/toasts.js'

/**
 * The register — board screen community-admin-04.
 *
 * THE BALANCE COLUMN NEVER PRINTS A FIGURE THIS PAGE HAD TO WORK OUT.
 * `row.standing` is `Residents::standingOf()`, whole, for every row — the same
 * shape boards 31 and 38 read — so a restricted household's cell reads the
 * amber word here exactly as it does everywhere else that function is
 * consulted, and a viewer without `estate.dues_ledger.view` reads "Balance not
 * shown" rather than a number this page invented a reason to withhold. Nothing
 * here reaches past `row.standing.label` for a figure; `EstateResidentsTest`
 * scans every one of these rows for the vocabulary of money and for any digit
 * at all, against a household carrying a real balance.
 *
 * CONTENT RESIDUAL, RECORDED IN D-056 — the register holds up to
 * `Residents::LIST_LIMIT` (50) of however many households the estate actually
 * has; the board draws six as an illustration of the shape, with no pagination
 * control and no affordance for a second page. The two named residuals below
 * are D-056's own:
 *
 *   - Keith Walters's row reads "K. A. Walters", the register's own form of the
 *     name — board 31's whole subject is that variance, so the register cannot
 *     print the claimant's spelling without erasing the reason that card is amber.
 *   - This estate's other 427 households are real seeded rows, not padding:
 *     `EstateFinanceSeeder` gives every unit a household, and this page renders
 *     whichever the server's own ordering (newest active first) sends down —
 *     never a client-side slice pretending the register is smaller than it is.
 *
 * THE FREE-TEXT SEARCH BOX IS THE SHELL'S, AND THIS SCREEN NOW GIVES IT
 * SOMEWHERE TO GO. `EstateConsole` drew it permanently disabled on every screen
 * that asked for one, on the reasoning that a search needed an index first —
 * and that had quietly stopped being true: `Residents::listBoard()` takes a
 * `search` argument and matches household name, resident full name, lot
 * reference and phase, which is exactly the three the placeholder promises.
 *
 * The cost of leaving it off was not cosmetic. This register shows at most
 * `LIST_LIMIT` (50) of an estate's households, ordered newest-active first, with
 * no pagination — so on a 441-household estate the other 391 were unreachable
 * from this screen, and the only control that could have found one was switched
 * off. Looking for a named resident left nothing but the browser's own find,
 * which searches the fifty rows already rendered and truthfully reports none.
 *
 * The search carries the phase chip with it (`searchParams`), or typing a name
 * would silently widen the list the operator had just narrowed. The PHASE CHIPS
 * remain real navigation, each a link to this same screen with `?phase=` set.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /**
     * M32 F1: which view of the register this is. `people` (the default): every resident, one row each, so a person who
     * exists is on this page. `households`: board 4's list, one row a household with its balance.
     */
    view: { type: String, default: 'people' },
    pending: { type: Object, required: true },
    phase_chips: { type: Array, required: true },
    filters: { type: Object, required: true },

    /**
     * What an empty filtered list says and which filters its one button clears
     * (B113) — `{ title, body, action_label, clears: ['q' | 'phase', …] }`, or
     * null when no filter is set. The server writes it because only it knows
     * which of the two filters, or both, emptied the list.
     */
    empty_filtered: { type: Object, default: null },
    rows: { type: Array, required: true },
    total: { type: Number, required: true },
    shown: { type: Number, required: true },

    /**
     * How the register is ordered, the columns the server can order by — so this page cannot invent one — and which
     * page of it this is (M32 F5, `App\Support\TableQuery::meta`).
     */
    table: { type: Object, required: true },
    /**
     * Whether this list may carry balances for this VIEWER — true for every row
     * alike. False without the ledger, and false for a viewer who holds the
     * ledger but not the estate's position (M10 §5): every household's balance
     * side by side is the arrears register by another door.
     */
    money_visible: { type: Boolean, required: true },
    /** The server's sentence for which of those two it is. Null while balances show. */
    money_reason: { type: String, default: null },
    canCreate: { type: Boolean, required: true },

    /*
     * `estate.residents.approve` — named `canReview` because that is what board
     * 4's own banner calls it, and it is a DIFFERENT permission from board 31's
     * own `canReview` (which is `update`, gating refuse/request-document there).
     * One label, two meanings, because the two screens are asking different
     * questions: this banner is asking who may DECIDE a claim, not who may act
     * on one at all.
     */
    canReview: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },

    /**
     * What became of a pending household's invite, keyed by household id (household invites, stage 2):
     * `{ resident_id, status, line, can_resend, resend_after }`. Absent for a household with nothing to say.
     */
    inviteStates: { type: Object, default: () => ({}) },

    /** How many Resident App accounts are deleted and can still come back — the Deleted accounts tab's badge (M18H §6). */
    deletedAccounts: { type: Number, default: 0 },

    /**
     * Add resident's board, the same one `/residents/new` is drawn from: sent only when the modal asks for it (a partial
     * reload), so it is absent on every ordinary visit to the register.
     */
    addResident: { type: Object, default: null },

    /**
     * The bulk import's preview while the reader is between its two presses (M98 census), or null: `{ token, file, rows,
     * row_count, errors, people, households, valid }`, the first 200 rows and every problem.
     */
    residentImport: { type: Object, default: null },
})

/*
 * THE REGISTER MOVES BY ITSELF (M18H §6): a person added or corrected, a claim decided, an app account deleted or
 * restored, a payment posted — the list is read again through its own route, keeping the filters and the scroll.
 */
useLiveReload({ entities: ['resident', 'account', 'dues'] })

/*
 * Which board's stylesheet this page wears. Boards 3, 4 and 38 all live in the
 * first Community Admin sheet, and naming the wrong one — or none — renders this
 * screen with no board CSS at all.
 */
useWireframe('community-admin-01-login-dashboard-structure-and-residents')

const page = usePage()

/**
 * Five of the six. `filtered` is true whenever a phase chip or the search
 * narrows the list, and `empty-filtered` is what a filter that matched nothing
 * looks like, as opposed to `empty`, which is this estate having none at all.
 *
 * WHICH FILTER EMPTIED IT DECIDES WHAT IT SAYS (B113). This used to be written
 * for the chip alone, so a search with no chip set read "No household is filed
 * in " — a blank phase — and blamed a chip nobody had pressed, and its "All
 * phases" button kept the search and reloaded the same empty list. The words and
 * the filters the button clears now come from the server (`empty_filtered`).
 */
const state = useScreenState({
    rows: () => props.rows.length,

    // Either filter narrowing counts. A search that matched nothing is
    // `empty-filtered` — "no household matches that" — and not `empty`, which
    // would tell the operator this estate has no residents at all.
    filtered: () => props.filters.phase !== '' || props.filters.search !== '',
})

/*
 * The filters the topbar search must carry with it, which is every filter but
 * the query itself. Dropping the phase here would widen the list on the first
 * keystroke, back to all five phases, without the chip row ever changing. The
 * view, the sort and the page size go with it too (M32 F5); the page does not —
 * a new search starts from the first.
 */
const searchParams = computed(() => ({
    ...(props.view === 'households' ? { view: 'households' } : {}),
    ...(props.filters.phase === '' ? {} : { phase: props.filters.phase }),
    ...keptByTable(props.table),
}))

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/residents. The URL that served this page already carries
 * whichever shape this environment uses, so cutting it at /residents is correct
 * in both — and unlike a tenant key read off a prop, it cannot address an
 * estate other than the one already open.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/residents')))

const residentsPath = computed(() => `${root.value}/residents`)

/* ------------------------------------------------------------------ */
/* the bulk import (M98 census): choose the file, preview it, then add everyone or nobody */
/* ------------------------------------------------------------------ */

const importing = ref(false)

const importForm = useForm({ file: null })

const openImport = () => {
    if (props.canCreate) {
        importForm.reset()
        importForm.clearErrors()
        importing.value = true
    }
}

const importFileChosen = (event) => {
    importForm.file = event.target.files?.[0] ?? null
}

/*
 * A REFUSAL OF AN OPEN DIALOG IS SAID INSIDE IT (Hassam, 12 Oct): the file dialog's band for a refused preview
 * (`importForm.errors.import`), the preview dialog's for a refused commit; never a toast while a dialog is open. Only a
 * commit whose preview has gone (it expired, so its dialog closed with it) is a toast: no dialog is open to hold it.
 */
const previewImport = () => {
    if (!props.canCreate || importForm.file === null) {
        return
    }

    importForm.post(`${residentsPath.value}/import/preview`, {
        forceFormData: true,
        onSuccess: () => {
            importing.value = false
        },
    })
}

const committingImport = ref(false)
const commitRefusal = ref(null)

const commitImport = () => {
    if (!props.canCreate || !props.residentImport?.valid) {
        return
    }

    committingImport.value = true
    commitRefusal.value = null
    router.post(`${residentsPath.value}/import/commit`, { token: props.residentImport.token }, {
        onError: (errors) => {
            const refusal = errors.import ?? Object.values(errors)[0] ?? 'Nobody was added. Please try again.'

            if (props.residentImport !== null) {
                commitRefusal.value = refusal
            } else {
                toast(refusal, { tone: 'error' })
            }
        },
        onFinish: () => {
            committingImport.value = false
        },
    })
}

const discardImport = () => router.visit(residentsPath.value)

/** "Residents.csv: 42 rows": the file and how many people it holds. */
const importTitle = computed(() =>
    props.residentImport === null ? '' : `${props.residentImport.file}: ${props.residentImport.row_count} row${props.residentImport.row_count === 1 ? '' : 's'}`
)
const claimsPath = computed(() => `${root.value}/residents/claims`)
const missingContactsPath = computed(() => `${root.value}/residents/missing-contacts`)
const deletedAccountsPath = computed(() => `${root.value}/residents/deleted-accounts`)
// M32 F1: a lot can hold more than one household, and a row's `household_param` names its own when it is not the lot's latest.
const unitPath = (slug, household = null) => `${root.value}/residents/${slug}${household ? `?household=${household}` : ''}`

/*
 * EVERY LINK ON THIS SCREEN IS THIS URL WITH ONE THING CHANGED.
 *
 * Phase, search, sort, direction and page all live in the query string, so the
 * register is linkable, correct under the back button and reproducible in a bug
 * report. Building each link from the current filters is what stops a phase chip
 * silently dropping the search, or a sort header dropping the phase — the fault
 * the old hand-built `?phase=` had, which discarded everything else the reader
 * had set.
 */
const href = (changes) => {
    const merged = {
        view: props.view,
        phase: props.filters.phase,
        q: props.filters.search,
        ...keptByTable(props.table),
        page: props.table.page,
        ...changes,
    }

    // A direction with nothing to sort, and page 1, say nothing the bare URL
    // does not already say — so they are dropped rather than left in it. The
    // People view is the default, so it is not written either.
    if (!merged.sort) {
        merged.dir = null
    }

    if (merged.page === 1) {
        merged.page = null
    }

    if (merged.view === 'people') {
        merged.view = null
    }

    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(merged)) {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, String(value))
        }
    }

    const string = query.toString()

    return `${residentsPath.value}${string === '' ? '' : `?${string}`}`
}

/*
 * A phase chip's own href. It carries the search and the sort with it now: a
 * chip that dropped them would silently widen the list the reader had just
 * narrowed and re-order it under them.
 *
 * Back to page 1, because page 4 of "all phases" is not page 4 of "Phase 2" —
 * keeping the number would land the reader past the end of the filtered list.
 */
const chipHref = (phase) => href({ phase, page: 1 })

/**
 * The two views (M32 F1). The search and the phase go with the switch; the sort and the page do not, because the
 * two tables are ordered by different columns.
 */
const viewHref = (view) => href({ view, sort: null, dir: null, page: 1 })
const isPeople = computed(() => props.view !== 'households')

/**
 * Where the empty filtered list's one button goes: this register with the
 * filters that emptied it cleared (B113), back on page 1. A button that kept the
 * search while clearing the phase went nowhere at all when the search was the
 * filter.
 */
const clearedHref = computed(() =>
    href({
        ...Object.fromEntries((props.empty_filtered?.clears ?? ['phase']).map((key) => [key, null])),
        page: 1,
    })
)

/*
 * THE TWO TABLES (M32 F5): sortable where the server can sort, plain where it cannot. Balance and Status are built per
 * row in PHP — from Dues::unitBalances() and the pending-claim list — so there is no column to ORDER BY, and a heading
 * that sorted only the twenty-five rows already fetched would let a reader page through four hundred households seeing
 * each page "sorted by balance" and never reach the largest debt on the estate.
 */
const PEOPLE_COLUMNS = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'lot', label: 'Phase and lot', sortable: true },
    { key: 'household', label: 'Household', sortable: true },
    { key: 'role', label: 'Primary or member', sortable: true },
    { key: 'state', label: 'State', sortable: true },
    { key: 'added', label: 'Added', sortable: true },
    { key: 'actions', label: '' },
]

const householdColumns = computed(() => [
    { key: 'household', label: 'Household', sortable: true },
    { key: 'unit', label: 'Unit', sortable: true },
    { key: 'members', label: 'Members', sortable: true },
    { key: 'balance', label: 'Balance', title: balanceHeaderTitle.value ?? undefined },
    { key: 'status', label: 'Status' },
    { key: 'active', label: 'Last active', sortable: true },
    { key: 'actions', label: '' },
])

const noun = computed(() =>
    isPeople.value ? (props.table.total === 1 ? 'person' : 'people') : (props.table.total === 1 ? 'household' : 'households')
)

/*
 * M96 LEVEL 3 (M98 pass): a role that can never add a resident has no Add resident at all, and a role that can never
 * decide a claim (D-053's Approver tag) has no Review now. Both were greyed buttons that said why only on hover; the
 * banner still says what is waiting.
 */

/*
 * ONE VOCABULARY (M96 §2, M98 pass): a person's state and a household's verification in the vocabulary's words, never
 * the stored ones. Verified is Active, as the People tab already said it; waiting on the office is Pending. "Invited"
 * and "Pending review" have no word of their own, so they read Pending, the closest, and the household's invite line
 * under the badge still says what became of the invite.
 */
const STATE_WORDS = { active: 'Active', verified: 'Active', invited: 'Pending', pending: 'Pending' }

const stateWord = (state, label) => STATE_WORDS[state] ?? label

/*
 * ADD RESIDENT, IN THE CONSOLE'S MODAL (Hassam, 11 Oct, from the client's clicking: "Add resident opens the form inline
 * on the page. It must open in a modal."). The form is the one `/residents/new` draws (`useAddResident()` and
 * `AddResidentFields`), over the register instead of in place of it. Its board is asked for when the modal opens, and
 * kept: a live reload of the register does not send it, and must not empty a form somebody is typing into. Each lookup
 * of the unit re-reads only `addResident`, under `add_phase` and `add_lot`, so the register's own phase filter is never
 * touched. A refused save keeps the modal and what was typed (the router's posts keep the page's state), with each
 * refusal under its field; a saved one goes to the household page, where the shell says what became of the invite.
 */
const adding = ref(false)
const addBoard = ref(null)
const addFailed = ref(false)
let addStarted = false

const add = useAddResident({
    board: () => addBoard.value,
    only: ['addResident'],
    params: { phase: 'add_phase', lot: 'add_lot' },
    active: () => adding.value,
    postTo: () => residentsPath.value,
})

/** The modal's form, started once per opening: on the board already here, or on the one the opening asked for. */
const startAdding = () => {
    if (!adding.value || addStarted || addBoard.value === null) {
        return
    }

    addStarted = true
    add.start({ lot: '' })
    add.askIfStale()
}

watch(
    () => props.addResident,
    (board) => {
        if (board) {
            addBoard.value = board
            startAdding()
        }
    },
)

const openAdd = () => {
    if (!props.canCreate) {
        return
    }

    adding.value = true
    addStarted = false
    addFailed.value = false

    if (addBoard.value !== null) {
        startAdding()

        return
    }

    router.reload({
        only: ['addResident'],
        onFinish: () => {
            // The five states: a board that did not come is said in the modal, never a form that loads for ever.
            addFailed.value = addBoard.value === null && !props.addResident
        },
    })
}

const closeAdd = () => {
    adding.value = false
}

/** Why the modal's Add resident cannot be pressed yet, said under it (M96 level 2). */
const addBlocked = computed(() => {
    if (addBoard.value === null) {
        return 'The form is still loading.'
    }

    return add.submitBlockedBy
})

/*
 * `?add=1` opens it at once, so another page's Add resident (the dashboard's quick action) can open the same modal
 * rather than a page of its own.
 */
onMounted(() => {
    if (new URLSearchParams(page.url.split('?')[1] ?? '').get('add') === '1') {
        openAdd()
    }
})

/**
 * The header's own explanation for why a column full of "Balance not shown" is
 * not a bug — the server's sentence, because only the server knows whether the
 * reader lacks the ledger or holds it without the estate's position.
 */
const balanceHeaderTitle = computed(() =>
    props.money_visible
        ? null
        : props.money_reason ??
          'A resident’s financial position needs Dues & ledger view access. You are able to read everything else on this register.'
)

/*
 * RESEND FROM THE REGISTER (household invites, stage 2), ASKING FIRST (DR-070). It emails somebody, and a control that
 * starts contact confirms first, naming how many and by what channel (the register's Part 7): the question names the
 * person and the address, and only its own button sends. One post at a time, so a second press cannot send twice; the
 * answer is drawn where the invite lives, the household page, in the person's own row.
 */
const resending = ref(null)
const resendAsk = ref(null)

const inviteFor = (row) => props.inviteStates?.[row.id] ?? null

/*
 * M96 LEVELS FOR RESEND (M98 pass). A role that can never send an invite has no Resend (level 3); one sent a moment ago
 * is greyed, and why is said on the screen under it, not only on hover (level 2).
 */
const mayResend = (state) => props.canCreate && state?.can_resend === true

const resendWait = (state) => {
    const after = state.resend_after ? Date.parse(state.resend_after) : Number.NaN

    return Number.isFinite(after) && after > Date.now()
        ? 'It was just sent. Try again in a few minutes.'
        : null
}

const resend = (row, state) => {
    if (!mayResend(state) || resending.value !== null || resendWait(state) !== null) {
        return
    }

    resendAsk.value = { row, state }
}

/*
 * A refusal was drawn nowhere on the register, so the press looked dead (M98 pass). It is said in the dialog's own band
 * now, the dialog staying open (Hassam, 12 Oct: never a toast while a dialog is open, never closed and then toasted).
 */
const resendRefusal = ref(null)

const closeResend = () => {
    resendAsk.value = null
    resendRefusal.value = null
}

const postResend = () => {
    if (resendAsk.value === null) {
        return
    }

    const { row, state } = resendAsk.value
    resending.value = row.id
    resendRefusal.value = null

    router.post(
        `${unitPath(row.unit_slug)}/people/${state.resident_id}/invite`,
        {},
        {
            onSuccess: () => {
                resendAsk.value = null
            },
            onError: (errors) => {
                resendRefusal.value = errors.invite ?? Object.values(errors)[0] ?? 'The invite was not sent.'
            },
            onFinish: () => {
                resending.value = null
            },
        }
    )
}
</script>

<template>
    <Head title="Residents" />

    <EstateConsole
        title="Residents"
        :estate-name="estate.name"
        active="residents"
        search-placeholder="Search by name, email, phone, lot, phase or vehicle…"
        :search-route="residentsPath"
        :search-value="filters.search"
        :search-params="searchParams"
    >
        <template #actions>
            <!--
              The board's outline button (M98 pass): both links wore btn-secondary-sm, which no stylesheet defines, so
              they drew as bare words beside Add resident.
            -->
            <!-- The chase list (M18C §5): households whose people are missing a phone or an email. -->
            <Link :href="missingContactsPath" class="btn-outline-sm"><span>Missing contact details</span></Link>
            <!-- M18H §6: accounts deleted and still coming back, with how many there are. -->
            <Link :href="deletedAccountsPath" class="btn-outline-sm">
                <span>Deleted accounts</span><span v-if="deletedAccounts > 0" class="deleted-badge" :aria-label="`${deletedAccounts} deleted`">{{ deletedAccounts }}</span>
            </Link>
            <!--
              M96 level 3: a role that can never add a resident has no Add resident at all. It opens the form in the
              console's modal over the register (Hassam, 11 Oct), where it used to open a page of its own.
            -->
            <!-- The bulk import (M98 census), in the console's modal. M96 level 3: absent for a role that can never add. -->
            <button v-if="canCreate" type="button" class="btn-outline-sm res-import-btn" title="Add many residents from a CSV file." @click="openImport">
                <span>Import residents</span>
            </button>
            <button v-if="canCreate" type="button" class="btn-primary-sm" title="Add a resident to the register." @click="openAdd">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add resident</span>
            </button>
        </template>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Residents is not part of your role’s access"
            body="The register names every household on the estate and, for roles that hold it, what each owes. A committee officer or the estate administrator can grant Residents from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The register could not be read"
            body="The estate database did not answer. No household has changed and no claim has moved. This is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div v-if="pending.count > 0" class="pending-banner">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                    <path
                        d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
                        stroke="currentColor"
                        stroke-width="1.7"
                    />
                </svg>
                <div>
                    <div class="pb1">{{ pending.title }}</div>
                    <div class="pb2">{{ pending.body }}</div>
                </div>
                <!-- M96 level 3: a role that can never decide a claim has no Review now; the banner still says what waits. -->
                <Link v-if="canReview" :href="claimsPath" class="pb-btn">Review now</Link>
            </div>

            <!-- M32 F1: the register as people (the default) or as households, in the chip style this page already uses. -->
            <div class="filter-row" role="tablist" aria-label="Show the register as">
                <Link :href="viewHref('people')" class="f-chip" :class="{ active: isPeople }" role="tab" :aria-selected="isPeople">People</Link>
                <Link :href="viewHref('households')" class="f-chip" :class="{ active: !isPeople }" role="tab" :aria-selected="!isPeople">Households</Link>
            </div>

            <div class="filter-row">
                <Link
                    v-for="chip in phase_chips"
                    :key="chip.value"
                    :href="chipHref(chip.value)"
                    class="f-chip"
                    :class="{ active: filters.phase === chip.value }"
                >
                    {{ chip.label }}
                </Link>
            </div>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                :title="empty_filtered?.title ?? 'No household matches these filters'"
                :body="empty_filtered?.body ?? 'Clear the filters to see every household again.'"
                :action-label="empty_filtered?.action_label ?? 'Clear the filters'"
                @action="router.get(clearedHref)"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No households are on the register yet"
                body="A resident is filed at a unit the estate already has, so the register stays empty until the first one is added."
                :action-label="canCreate ? 'Add resident' : null"
                @action="openAdd"
            />

            <!-- M32 F1: every resident, one row each. A member is on this page as surely as a primary. -->
            <DataTable v-else-if="isPeople" :columns="PEOPLE_COLUMNS" :rows="rows" :table="table" :noun="noun">
                <template #cell-name="{ row }">
                    <div class="res-cell">
                        <div class="res-avatar">{{ row.initials }}</div>
                        <div>
                            <div class="res-name">
                                {{ row.name }}
                                <span v-if="row.sample" class="status-badge draft" title="Sample data.">sample</span>
                            </div>
                            <div class="res-sub">{{ row.email ?? 'No email on file' }}</div>
                        </div>
                    </div>
                </template>
                <!-- "Phase 2 · Lot 47": the unit's own address label already names its phase (Unit::addressLabel). -->
                <template #cell-lot="{ row }">{{ row.unit }}</template>
                <template #cell-role="{ row }">{{ row.role_label }}</template>
                <!-- A state is a link to where it is acted on: the household's page (Hassam, batch 1 item 10). -->
                <template #cell-state="{ row }">
                    <Link
                        v-if="row.unit_slug"
                        :href="unitPath(row.unit_slug, row.household_param)"
                        class="status-badge status-link"
                        :class="row.state === 'active' ? 'verified' : 'pending'"
                        :title="`Open ${row.name}'s household.`"
                    >{{ stateWord(row.state, row.state_label) }}</Link>
                    <div v-else class="status-badge" :class="row.state === 'active' ? 'verified' : 'pending'">{{ stateWord(row.state, row.state_label) }}</div>
                </template>
                <template #cell-actions="{ row }">
                    <div class="row-actions">
                        <Link
                            v-if="row.unit_slug"
                            :href="unitPath(row.unit_slug, row.household_param)"
                            class="icon-btn"
                            title="View household"
                            aria-label="View household"
                        >
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                />
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7" />
                            </svg>
                        </Link>
                    </div>
                </template>
            </DataTable>

            <DataTable v-else :columns="householdColumns" :rows="rows" :table="table" :noun="noun">
                <template #cell-household="{ row }">
                    <div class="res-cell">
                        <div class="res-avatar">{{ row.initials }}</div>
                        <div>
                            <div class="res-name">
                                {{ row.name }}
                                <span v-if="row.sample" class="status-badge draft" title="Sample data.">sample</span>
                            </div>
                            <div class="res-sub">{{ row.email ?? 'No email on file' }}</div>
                        </div>
                    </div>
                </template>

                <!--
                  `standing.tone` is the only thing this cell reads to decide its colour. A restricted household's tone
                  is 'amber' and its label is D-025's own wording — never a figure, so there is nothing here that could
                  print one by mistake.
                -->
                <template #cell-balance="{ row }">
                    <span :class="{ 'standing-amber': row.standing.tone === 'amber' }">{{ row.standing.label }}</span>
                </template>

                <template #cell-status="{ row }">
                    <Link
                        v-if="row.unit_slug"
                        :href="unitPath(row.unit_slug, row.household_param)"
                        class="status-badge status-link"
                        :class="row.verification"
                        :title="`Open ${row.name}.`"
                    >{{ stateWord(row.verification, row.verification_label) }}</Link>
                    <div v-else class="status-badge" :class="row.verification">{{ stateWord(row.verification, row.verification_label) }}</div>
                    <div v-if="inviteFor(row)" class="reg-invite" :class="{ bad: ['not_sent', 'expired'].includes(inviteFor(row).status) }">
                        {{ inviteFor(row).line }}<template v-if="mayResend(inviteFor(row))">
                            ·
                            <button
                                type="button"
                                class="reg-resend"
                                :disabled="resending !== null || resendWait(inviteFor(row)) !== null"
                                :title="resendWait(inviteFor(row)) ?? 'Email the invite again. The household page says whether it went.'"
                                @click="resend(row, inviteFor(row))"
                            >
                                {{ resending === row.id ? 'Sending…' : 'Resend' }}
                            </button>
                        </template>
                    </div>
                    <!-- M96 level 2: why Resend is greyed, on the screen. -->
                    <div v-if="mayResend(inviteFor(row)) && resendWait(inviteFor(row))" class="reg-why">{{ resendWait(inviteFor(row)) }}</div>
                </template>
                <template #cell-active="{ row }">{{ row.last_active ?? 'Never' }}</template>
                <template #cell-actions="{ row }">
                    <div class="row-actions">
                        <Link
                            :href="unitPath(row.unit_slug, row.household_param)"
                            class="icon-btn"
                            title="View household"
                            aria-label="View household"
                        >
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                />
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7" />
                            </svg>
                        </Link>
                    </div>
                </template>
            </DataTable>
        </template>

        <!-- DR-070: who is told, and how, before the invite goes again. -->
        <ConsoleDialog
            :open="resendAsk !== null"
            :title="resendAsk ? `Resend the invite to ${resendAsk.row.name}?` : ''"
            confirm-label="Resend invite"
            busy-label="Sending…"
            :busy="resending !== null"
            :error="resendRefusal"
            @confirm="postResend"
            @cancel="closeResend"
        >
            <p v-if="resendAsk" style="margin: 0">An email to {{ resendAsk.row.email }}, with the link to join the Resident App. Nobody else is told.</p>
        </ConsoleDialog>

        <!-- Add resident, in the console's modal (Hassam, 11 Oct): the form /residents/new draws, over the register. -->
        <ConsoleDialog
            :open="adding"
            title="Add a resident"
            size="form"
            confirm-label="Add resident"
            busy-label="Adding…"
            :busy="add.form.processing"
            :dirty="add.form.isDirty === true"
            discard-title="Discard this resident?"
            :confirm-blocked-reason="addBlocked"
            @confirm="add.submit"
            @cancel="closeAdd"
        >
            <form v-if="addBoard" class="add-resident-form" @submit.prevent="add.submit">
                <AddResidentFields :add="add" :board="addBoard" with-preview />
            </form>
            <p v-else-if="addFailed" class="add-resident-wait">The form could not be read. Close this and press Add resident again.</p>
            <p v-else class="add-resident-wait">Loading the form…</p>
        </ConsoleDialog>

        <!-- The bulk import (M98 census), in the console's modal: the file first, then its preview. -->
        <ConsoleDialog
            :open="importing && residentImport === null"
            title="Import residents"
            size="form"
            confirm-label="Preview"
            busy-label="Checking…"
            :busy="importForm.processing"
            :confirm-blocked-reason="importForm.file === null ? 'Choose a CSV file.' : null"
            :error="importForm.errors.import ?? null"
            @confirm="previewImport"
            @cancel="importing = false"
        >
            <form class="res-import" @submit.prevent="previewImport">
                <p class="res-import-note">
                    Columns: <strong>lot, name, relationship, email, phone, moved in</strong>. Lot and name are required, and an
                    email or a phone for each person. A lot is written as the estate writes it, "Lot 47" or "47".
                </p>
                <RequiredMark legend />
                <div class="res-import-field" :class="{ 'has-error': importForm.errors.file }">
                    <label for="res-import-file">File <RequiredMark /></label>
                    <input id="res-import-file" type="file" accept=".csv,text/csv" required @change="importFileChosen" />
                    <div v-if="importForm.errors.file" class="res-import-error">{{ importForm.errors.file }}</div>
                </div>
                <p class="res-import-note">Preview checks every row against the register. Nothing is written yet, and nobody is emailed.</p>
            </form>
        </ConsoleDialog>

        <!-- The import's preview: Add writes everyone, Discard throws the file away. -->
        <ConsoleDialog
            :open="residentImport !== null"
            :title="importTitle"
            size="form"
            :confirm-label="residentImport ? `Add ${residentImport.people} ${residentImport.people === 1 ? 'person' : 'people'}` : 'Add'"
            busy-label="Adding…"
            cancel-label="Discard"
            :busy="committingImport"
            :confirm-blocked-reason="residentImport && !residentImport.valid ? 'Some rows have problems. Fix the file and preview it again: a list is added whole or not at all.' : null"
            :error="commitRefusal"
            @confirm="commitImport"
            @cancel="discardImport"
        >
            <div v-if="residentImport !== null" class="res-import">
                <p class="res-import-head">
                    <template v-if="residentImport.valid">
                        Every row passes: {{ residentImport.people }} {{ residentImport.people === 1 ? 'person' : 'people' }},
                        {{ residentImport.households }} new household{{ residentImport.households === 1 ? '' : 's' }}.
                    </template>
                    <template v-else>
                        {{ residentImport.errors.length }} problem{{ residentImport.errors.length === 1 ? '' : 's' }}. Nothing is added
                        until the file passes whole.
                    </template>
                </p>
                <ol v-if="residentImport.errors.length > 0" class="res-import-problems">
                    <li v-for="problem in residentImport.errors" :key="`${problem.line}-${problem.message}`">
                        <strong>Line {{ problem.line }}:</strong> {{ problem.message }}
                    </li>
                </ol>
                <div v-if="residentImport.rows.length > 0" class="res-import-scroll">
                    <table class="data-table res-import-table" aria-label="What the import will do, person by person">
                        <thead>
                            <tr><th>Line</th><th>Lot</th><th>Name</th><th>Relationship</th><th>Reached at</th><th>What happens</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in residentImport.rows" :key="row.line">
                                <td>{{ row.line }}</td>
                                <td>{{ row.lot }}</td>
                                <td>{{ row.name }}</td>
                                <td>{{ row.relationship }}</td>
                                <td>{{ [row.email, row.phone].filter(Boolean).join(' · ') }}</td>
                                <td>{{ row.outcome }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="residentImport.row_count > residentImport.rows.length" class="res-import-note">
                    The first {{ residentImport.rows.length }} of {{ residentImport.row_count }} are listed.
                </p>
                <p class="res-import-note">
                    Each person is added as Pending. No invite is sent: send each from the lot's page when you are ready.
                </p>
            </div>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/* A state that opens where it is acted on (batch 1 item 10): the badge as drawn, a link to the hand and the keyboard. */
.status-link {
    text-decoration: none;
    cursor: pointer;
}

.status-link:hover {
    text-decoration: underline;
}

.status-link:focus-visible {
    outline: 2px solid var(--amber-500);
    outline-offset: 2px;
}

/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its topbar action, its banner CTA, its phase chips and its
 * row action as <div>s; here they are links and buttons, each arriving with the
 * browser's own font, border and — for the anchors — an underline app.css has
 * already stripped on every board page. Nothing below introduces a colour, a
 * size or a weight the board does not already carry on the class.
 *
 * .btn-primary-sm, .pb-btn, .f-chip and .icon-btn none of them are given a
 * border by the board, which is what makes `border: 0` a removal here rather
 * than an override — see D-045.
 */
a.btn-primary-sm,
a.btn-outline-sm,
a.pb-btn,
a.f-chip {
    text-decoration: none;
}

/* The pager and the sortable headings are the shared table's (M32 F5, Components/DataTable.vue). */

button.btn-primary-sm,
button.pb-btn,
button.f-chip {
    border: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled],
button.pb-btn[disabled] {
    cursor: not-allowed;
}

a.icon-btn {
    display: flex;
}

/* Authored: the amber a restricted household's Balance cell reads in. No row
   in the seeded register carries it, so it touches nothing the board draws. */
.standing-amber {
    color: var(--amber-700);
    font-weight: 700;
}

/* Authored (household invites, stage 2): the invite line under a pending badge, and its Resend. */
.reg-invite {
    font-size: 10.5px;
    color: var(--slate-600);
    margin-top: 4px;
    white-space: nowrap;
}

/* Hassam's rule 20: saturated red is for panic alone; an invite that did not go, and the deleted count, are amber. */
.reg-invite.bad {
    color: var(--amber-700);
}

.reg-resend {
    border: 0;
    background: none;
    padding: 0;
    font-family: inherit;
    font-size: inherit;
    font-weight: 700;
    color: var(--navy-700);
    text-decoration: underline;
    cursor: pointer;
}

.reg-resend[disabled] {
    color: var(--slate-500);
    cursor: not-allowed;
}

/* Add resident's modal while its form is read, or when it could not be. */
.add-resident-wait {
    margin: 0;
    font-size: 13px;
    color: var(--slate-600);
}

/* M96 level 2: why Resend is greyed, under the invite line, in its type. */
.reg-why {
    font-size: 10.5px;
    color: var(--slate-600);
    margin-top: 2px;
}

/* M18H §6: the Deleted accounts count, white on amber-700 (#8F5E06, 5.56:1 on white per tokens.css, so its white reads). */
.deleted-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    margin-left: 6px;
    padding: 0 5px;
    border-radius: 9px;
    background: var(--amber-700);
    color: var(--text-on-dark);
    font-size: 11px;
    font-weight: 700;
}

/* Inside the board's outline button, whose `span` rule would otherwise set the count navy on red. */
.btn-outline-sm .deleted-badge {
    color: var(--text-on-dark);
    font-size: 11px;
}

/* M32 F1: the `sample` chip — see the household page (`Show.vue`) for why it is here and not in app.css. */
.status-badge.draft {
    background: var(--navy-100);
    color: var(--navy-700);
}

/*
 * THE BULK IMPORT (M98 census): the board's outline button as a real button (font and cursor only; its 1.5px border is
 * the board's, D-045), and the two modals' bodies in the board's type sizes. Problems are amber, never red; the preview's
 * table scrolls inside the modal rather than widening it.
 */
button.btn-outline-sm.res-import-btn {
    font: inherit;
    cursor: pointer;
}

.res-import {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.res-import-note {
    margin: 0;
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.5;
}

.res-import-head {
    margin: 0;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
}

.res-import-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.res-import-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.res-import-error,
.res-import-problems {
    font-size: 11.5px;
    color: var(--amber-700);
    line-height: 1.6;
}

.res-import-error {
    font-weight: 600;
}

.res-import-problems {
    margin: 0;
    padding-left: 18px;
    max-height: 160px;
    overflow-y: auto;
}

.res-import-scroll {
    overflow: auto;
    max-height: 320px;
}

.res-import-table {
    min-width: 640px;
}

/*
 * ON A PHONE (seen at 390 px on the local app, 12 Oct): the phase chips wrap, where their one row pushed the page to
 * 448 px. The table already scrolls inside its own card. Doubled classes outrank the board's `.filter-row`.
 */
@media (max-width: 640px) {
    .filter-row.filter-row {
        flex-wrap: wrap;
    }
}
</style>
