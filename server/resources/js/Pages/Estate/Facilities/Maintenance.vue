<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import DataTable from '../../../Components/DataTable.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { DEFAULT_TARGETS, spellHours } from '../../../lib/serviceTargets.js'

/**
 * The maintenance queue — board screen community-admin-17.
 *
 * NOTHING ON THIS SCREEN IS ADDED UP HERE, AND THE OVERDUE FIGURE IS THE ONE
 * THAT MATTERS. `Maintenance::queueBoard()` walks every ticket once and derives
 * the four tiles, the red chip and each row's badge from the SAME arithmetic —
 * the time reported plus the service target for the priority. So the chip and
 * the tile cannot disagree, and no row can show "In progress" while the tile
 * counts it overdue. A page that re-counted the rows it was given would report a
 * different number the moment the queue paginated, which it already has: twelve
 * tickets are open and the board draws five.
 *
 * THE BADGE COLUMN IS NOT THE STATUS COLUMN. The stored lifecycle is submitted,
 * acknowledged, assigned, in_progress, completed, verified — "Overdue" is none
 * of them. It is an open ticket past its deadline, computed at draw time, which
 * is why #1041 shows as Overdue while its record says a vendor was assigned and
 * nobody has started. Both statements are true and only one of them is stored.
 *
 * NOT ONE FIGURE HERE IS A HOUSEHOLD'S FINANCIAL POSITION. The persona is the
 * Property Manager, who holds Facilities in full and holds nothing on Dues &
 * ledger, payments or accounting posting (D-010). The Location cell prints a
 * unit REFERENCE — "Phase 1 · Lot 9" — as a place, and there is no balance, no
 * bucket and no arrears age anywhere on the payload behind it.
 *
 * TRIAGE HAPPENS ON THE ROW, WHICH IS WHY `canUpdate` IS HERE. Pressing the
 * priority raises or lowers the service target, and the deadline moves with it
 * while the clock does not reset — a three-day-old medium ticket escalated to
 * high is overdue the instant it is escalated, because high means a day from the
 * report and the report was three days ago. That is the point of escalating one.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    estate: { type: Object, required: true },
    /** Four tiles in board order: open, in progress, overdue, average resolution. */
    kpis: { type: Array, required: true },
    /** The chip set — All, Open, In progress, Completed — with its labels. */
    filters: { type: Array, required: true },
    filter: { type: String, required: true },
    /** The same count as the Overdue tile, from the same walk. */
    overdue: { type: Number, required: true },
    rows: { type: Array, required: true },
    /** The queue as the shared table reads it (M32 F5): its order, its columns, its search and this page's place. */
    table: { type: Object, required: true },
    /** The priority ladder as {key: label}, so the page never spells the enum. */
    priorities: { type: Object, required: true },
    /** This estate's service targets, hours by priority (M98); the ladder every estate starts with until it sets its own. */
    targets: { type: Object, default: () => ({ ...DEFAULT_TARGETS }) },
    /** The longest target the server takes, in hours. */
    longestTarget: { type: Number, default: 720 },
    /** Whether this reader may set the targets (Facilities configure). */
    canConfigure: { type: Boolean, default: false },
    canUpdate: { type: Boolean, required: true },
    canCreate: { type: Boolean, required: true },
    /** Whether the viewer may open the supplier register, which is Accounting's screen. */
    canViewVendors: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    createReason: { type: String, required: true },
    reasons: { type: Object, required: true },
    /**
     * What a work order is filed against — the lots matching the office's search,
     * by reference (M11 A2). Empty until a lot is typed; never the whole estate.
     */
    units: { type: Array, required: true },
    unitQuery: { type: String, default: '' },
    unitMatchLimit: { type: Number, default: 20 },
    /** How far back "Reported at" may go, in days, as the server counts it (FAC-12). */
    reportedWithinDays: { type: Number, default: 30 },
    /** The one list (FAC-6): `{ key, label }`, the key what is stored, as the Resident App stores it. */
    categories: { type: Array, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do NOT
 * share one sheet, so every estate page has to name its own or it renders with
 * no board CSS at all. Boards 17 to 20 are all in the batch-5 sheet.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

const page = usePage()

/**
 * All six, and every one of them says something true of a maintenance queue.
 *
 * `empty` is an estate that has never had a fault reported — a real first day,
 * not an error. `empty-filtered` is the chip set having excluded everything: an
 * estate with nothing completed this quarter still has tickets, and telling that
 * reader "no tickets yet" would suggest the queue had been wiped.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.filter !== 'all' || (props.table.q ?? '') !== '',
})

const retry = () => router.reload()

/*
 * "All" is every ticket the estate has ever had, closed ones included, so the
 * queue only grows. It is a page of it now (M32 F5), sorted and paged by the
 * server over the whole queue, at most 200 at once — the long-table window it
 * once needed (M11 A3) has nothing left to do.
 */
const COLUMNS = [
    { key: 'number', label: 'Ticket', sortable: true },
    { key: 'location', label: 'Location', sortable: true },
    { key: 'priority', label: 'Priority', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'assignee', label: 'Assigned', sortable: true },
    { key: 'age', label: 'Age', sortable: true },
    { key: 'actions', label: '' },
]

const noun = computed(() => (props.table.total === 1 ? 'ticket' : 'tickets'))

/*
 * Where this estate's Facilities module lives, in whichever shape the
 * environment serves. Production gives each estate its own hostname and the path
 * starts at /facilities; local serves them all from one host with the estate in
 * the path (/estate/phoenixpark/facilities/maintenance). Cutting the URL that
 * served this page at /facilities is right in both, and — unlike a tenant key
 * read off a prop — it cannot address an estate other than the one already open.
 */
const facilitiesPath = computed(() => {
    const cut = page.url.indexOf('/facilities')

    return cut === -1 ? '/facilities' : `${page.url.slice(0, cut)}/facilities`
})

/*
 * THE CHIPS FILTER IN THE QUERY, NOT IN THE BROWSER. Filtering the rows already
 * sent would leave the four tiles reporting the whole estate above a table
 * reporting one status, and a screen that computes its summary and its list in
 * two places eventually reports two different numbers. Links, so a filtered
 * queue is a URL a manager can bookmark and reload.
 */
const queueHref = computed(() => `${facilitiesPath.value}/maintenance`)

/** A chip keeps the search, the order and the page size, and starts again from the first page (M32 F5). */
const chipHref = (key) => {
    const query = new URLSearchParams()

    if (key !== 'all') query.set('filter', key)
    if (props.table.q) query.set('q', props.table.q)

    for (const [name, value] of Object.entries(keptByTable(props.table))) {
        query.set(name, String(value))
    }

    const suffix = query.toString()

    return `${queueHref.value}${suffix === '' ? '' : `?${suffix}`}`
}

/** What the topbar search carries with it: the chip, the order and the page size — never the page. */
const searchParams = computed(() => ({
    ...(props.filter === 'all' ? {} : { filter: props.filter }),
    ...keptByTable(props.table),
}))

/** Where an emptied queue's one button goes: every ticket, the search cleared. */
const wholeQueueHref = computed(() => `${queueHref.value}`)

const ticketHref = (row) => `${queueHref.value}/${row.number}`

/** "2 photos from the resident", the ticket page's own words, for the row's mark (FAC-5). */
const photoLine = (count) => (count === 1 ? '1 photo from the resident' : `${count} photos from the resident`)

/**
 * The booking diary — board 19, and the second half of this module.
 *
 * A real link, because the screen is built. "Vendors" is a link only for a
 * viewer holding Accounting view: the supplier register is the Accounting
 * module's screen, and the persona this board is drawn for does not hold it —
 * `reasons.vendors` says so on the inert twin, and it is the same register
 * these tickets are assigned from.
 */
const amenitiesHref = computed(() => `${facilitiesPath.value}/amenities/bookings`)

/** Board 26, one module over from the same root. */
const vendorsHref = computed(() => facilitiesPath.value.replace(/\/facilities$/, '/accounting/vendors'))

/**
 * The board's own class name for a priority, which is not the stored value.
 *
 * The sheet defines `.high`, `.med` and `.low`; the enum is high, medium, low.
 * Mapping the one that differs is cheaper than teaching the server the board's
 * abbreviation, and it keeps `priority` readable in the payload.
 */
const dotClass = (priority) => (priority === 'medium' ? 'med' : priority)

/**
 * The board draws four badge colours and the lifecycle has five states.
 *
 * `cancelled` is the one the sheet has no rule for — the board never drew a
 * cancelled ticket — so it is declared inline in the board's own manner, neutral
 * rather than red. A ticket somebody withdrew is a decision, not a failure, and
 * colouring it like a breach would put it in the same bucket as #1041.
 */
const badgeStyle = (status) =>
    status === 'cancelled' ? 'background:var(--navy-100);color:var(--slate-600);' : undefined

/** Each state's icon, beside its word: a badge is never told apart by colour alone (M96). */
const STATUS_ICON = {
    submitted: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
    progress: 'M3 12h4l3-8 4 16 3-8h4',
    completed: 'M20 6 9 17l-5-5',
    cancelled: 'M8 12h8M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
}

/**
 * The tiles print what the server sent them as.
 *
 * Three are counts and the fourth is a DURATION — the board draws "3.2 days"
 * beside "12", "7" and "2" — so the unit word belongs to that tile alone. One
 * decimal always, including a flat 3.0: a second place would suggest an accuracy
 * an average over a dozen jobs has not got, and dropping the place would make
 * the tile disagree with itself between quarters.
 */
const tile = (kpi) => (kpi.key === 'avg_resolution' ? `${kpi.value.toFixed(1)} days` : kpi.value)

/* ------------------------------------------------------------------ */
/* triage */
/* ------------------------------------------------------------------ */

/**
 * The row whose priority is being changed, or null. In the console's modal since M95 §1 (M97 pass): the ladder opened
 * inside the table under its row, pushing the queue down.
 */
const triaging = ref(null)

const triagingRow = computed(() => (triaging.value === null ? null : props.rows.find((row) => row.id === triaging.value) ?? null))

/** The priority chosen in the modal; it starts at the ticket's own. */
const priorityChoice = ref(null)

/** Why Set priority cannot be pressed yet, said under it (M96 level 2). */
const priorityBlocked = computed(() => {
    const row = triagingRow.value

    if (row === null || priorityChoice.value !== row.priority) {
        return null
    }

    return `${row.ticket} is already ${String(props.priorities[row.priority] ?? row.priority).toLowerCase()} priority. Choose another.`
})

const PRIORITY_HINT =
    'Change the priority.'

/**
 * Why the priority cannot be pressed on this row, or null when it can.
 *
 * TWO REFUSALS, and the second is the row's rather than the reader's. Access
 * comes first because it is true of every row and the route enforces it anyway.
 * The second is a closed ticket: a priority is a promise about when work will be
 * done, and there is no work outstanding on a job that is finished — the service
 * would take the press and record a state change that means nothing. Bringing it
 * back is a reopen, which is board 18's act because it has to say why.
 */
const triageBlockedBy = (row) => {
    if (!props.canUpdate) {
        return props.blockedReason
    }

    if (row.status === 'completed' || row.status === 'cancelled') {
        return 'This ticket is closed, so a priority has no deadline to set. If the fault came back, reopen it on the ticket, which keeps the first closure in the record.'
    }

    return null
}

const openTriage = (row) => {
    if (triageBlockedBy(row) !== null) {
        return
    }

    priorityChoice.value = row.priority
    triageRefusal.value = null
    triaging.value = row.id
}

const closeTriage = () => {
    triaging.value = null
    triageRefusal.value = null
}

/** A refused priority, said in the modal's band with the modal still open (Hassam, 12 Oct): it was closed and toasted. */
const triageRefusal = ref(null)

/**
 * Move one ticket's priority.
 *
 * A plain post rather than a form: there is one field, it has three values, and
 * each is its own button — so there is nothing to type, nothing to validate in
 * the browser and no state worth holding between presses.
 *
 * IT LANDS ON THE TICKET, and that is the route's own redirect rather than an
 * accident of this page. Escalating a job is the moment somebody wants to look
 * at it, and the ticket is where the vendor, the timeline and the new deadline
 * are. The button says so before it is pressed.
 */
/* ------------------------------------------------------------------ */
/* raising a work order — the topbar's "New work order" (12 §2, Wave 2) */
/* ------------------------------------------------------------------ */

/* In the console's modal since M95 §1 (M97 pass): the form opened above the tiles and pushed the queue below the fold. */
const raising = ref(false)

const openRaise = () => {
    if (props.canCreate) {
        raising.value = true
    }
}

/* Closing it throws away what was typed: the modal asks first when anything was (M95 §1). */
const closeRaise = () => {
    raising.value = false
    workOrder.reset()
    workOrder.clearErrors()
    unitQuery.value = ''
}

const workOrder = useForm({
    title: '',
    location: '',
    unit_id: '',
    category: props.categories[0]?.key ?? 'other',
    priority: 'medium',
    description: '',
    reported_at: '',
})

/*
 * The optional fields travel as null rather than "". A unit nobody named and
 * a time nobody typed are ABSENT facts; an empty string against either would
 * be refused as a unit id or a date that is not one.
 */
workOrder.transform((data) => ({
    ...data,
    unit_id: data.unit_id === '' ? null : data.unit_id,
    reported_at: data.reported_at === '' ? null : data.reported_at,
    description: data.description.trim() === '' ? null : data.description,
}))

/*
 * THE UNIT IS FOUND, NOT PICKED FROM EVERY LOT (M11 A2). The page used to carry
 * all 2,000 of the estate's lots to fill this one dropdown. The office types the
 * lot instead — "47" or "Lot 47" — and the server sends the few that match, best
 * first; on change rather than per keystroke, as the charge form's own lookup
 * works, because "L" and "Lo" are not lots. One match is chosen for you.
 */
const unitQuery = ref(props.unitQuery ?? '')

const findUnits = () => {
    router.reload({
        only: ['units', 'unitQuery'],
        data: { unit_q: unitQuery.value.trim() || undefined },
        preserveScroll: true,
        onSuccess: () => {
            if (props.units.length === 1) {
                workOrder.unit_id = props.units[0].id
            } else if (!props.units.some((unit) => unit.id === workOrder.unit_id)) {
                workOrder.unit_id = ''
            }
        },
    })
}

/** What the chosen priority commits the estate to, from this estate's own targets (M98), said before the press. */
const slaLine = computed(
    () => `${String(props.priorities[workOrder.priority] ?? workOrder.priority).toLowerCase()} means ${spellHours(props.targets[workOrder.priority])}`
)

/* ------------------------------------------------------------------ */
/* the service targets (M98): how long each priority has */
/* ------------------------------------------------------------------ */

const TARGET_KEYS = ['high', 'medium', 'low']

/** "High a day, Medium 3 days, Low 7 days": the targets as a fact, for every reader of the queue. */
const targetsLine = computed(() =>
    TARGET_KEYS.map((key) => `${props.priorities[key] ?? key} ${spellHours(props.targets[key])}`).join(', ')
)

const settingTargets = ref(false)

const targetsForm = useForm({ ...DEFAULT_TARGETS, ...props.targets })

/* Opened on the targets as they stand now, whatever was typed and discarded before. */
const openTargets = () => {
    if (!props.canConfigure) {
        return
    }

    for (const key of TARGET_KEYS) {
        targetsForm[key] = props.targets[key]
    }

    targetsForm.clearErrors()
    settingTargets.value = true
}

const closeTargets = () => {
    settingTargets.value = false
    targetsForm.clearErrors()
}

const targetsDirty = computed(() => TARGET_KEYS.some((key) => Number(targetsForm[key]) !== Number(props.targets[key])))

/** Why Save targets cannot be pressed, in one line, or null when it can (M96 level 2). The server says the same. */
const targetsBlockedBy = computed(() => {
    const [high, medium, low] = TARGET_KEYS.map((key) => Number(targetsForm[key]))

    if ([high, medium, low].some((value) => !Number.isInteger(value) || value < 1 || value > props.longestTarget)) {
        return `Each target is whole hours, from 1 to ${props.longestTarget}.`
    }

    if (high > medium) {
        return 'High must be no longer than Medium.'
    }

    if (medium > low) {
        return 'Medium must be no longer than Low.'
    }

    return targetsDirty.value ? null : 'Change a target first.'
})

const submitTargets = () => {
    if (!props.canConfigure || targetsBlockedBy.value !== null) {
        return
    }

    targetsForm.post(`${queueHref.value}/targets`, {
        preserveScroll: true,
        onSuccess: () => closeTargets(),
    })
}

/*
 * A TIME STILL TO COME IS SAID BEFORE THE PRESS (FAC-11). The server refuses it on
 * the estate's clock and stays the judge; the browser's clock is the office's
 * own, so the same refusal is said here, under the field, with Raise off.
 */
const reportedLater = computed(() => workOrder.reported_at !== '' && new Date(workOrder.reported_at).getTime() > Date.now())

/*
 * AND NOT BEFORE THE MONTH (FAC-12). The field took 1 January 2020. It goes back `reportedWithinDays`, from the start
 * of that day, as the server counts it: the field's own `min`, and a time further back turns Raise off with the reason.
 */
const reportedFloor = computed(() => {
    const floor = new Date(Date.now() - props.reportedWithinDays * 24 * 60 * 60 * 1000)
    floor.setHours(0, 0, 0, 0)

    return floor
})

const reportedMin = computed(() => {
    const pad = (n) => String(n).padStart(2, '0')
    const floor = reportedFloor.value

    return `${floor.getFullYear()}-${pad(floor.getMonth() + 1)}-${pad(floor.getDate())}T00:00`
})

const reportedTooEarly = computed(
    () => workOrder.reported_at !== '' && new Date(workOrder.reported_at).getTime() < reportedFloor.value.getTime()
)

const reportedTooEarlyLine = computed(() => `A report is keyed within ${props.reportedWithinDays} days of being made. Choose a later time.`)

/*
 * THE LOT IS ONE OF THE MATCHES, OR NONE (FAC-13). A lot typed and never chosen
 * raised a work order against no unit, with no word said. While the search holds
 * a lot and nothing is chosen, Raise is off; clearing the search says the job is
 * not a household's.
 */
const lotNotChosen = computed(() => unitQuery.value.trim() !== '' && workOrder.unit_id === '')

const LOT_NOT_CHOSEN = "Choose the lot from the list, or clear the search if this is not a household's job."

/** Why Raise is off, in one line, or null while it can be pressed (Law 1). */
const raiseBlockedBy = computed(() => {
    if (workOrder.title.trim() === '' || workOrder.location.trim() === '') {
        return 'Say what needs doing and where.'
    }

    if (lotNotChosen.value) {
        return LOT_NOT_CHOSEN
    }

    if (reportedLater.value) {
        return 'The time reported is later than now. Leave it blank for now.'
    }

    if (reportedTooEarly.value) {
        return reportedTooEarlyLine.value
    }

    return null
})

const submitWorkOrder = () => {
    if (!props.canCreate || raiseBlockedBy.value !== null) {
        return
    }

    // A refusal comes back under its field with the modal still open: Inertia's post keeps the page by default.
    workOrder.post(`${facilitiesPath.value}/maintenance`, {
        preserveScroll: true,
        onSuccess: () => closeRaise(),
    })
}

const setPriority = (row, priority) => {
    if (triageBlockedBy(row) !== null || priority === row.priority) {
        return
    }

    // It lands on the ticket (the route's own redirect); a refusal stays in the open modal's band.
    triageRefusal.value = null
    router.post(`${facilitiesPath.value}/maintenance/${row.number}/priority`, { priority }, {
        onSuccess: () => closeTriage(),
        onError: (errors) => {
            triageRefusal.value = errors.priority ?? Object.values(errors)[0] ?? 'The priority was not changed.'
        },
    })
}

const confirmPriority = () => {
    if (triagingRow.value !== null && priorityBlocked.value === null) {
        setPriority(triagingRow.value, priorityChoice.value)
    }
}

/** Something has been typed or chosen in the work order: closing asks first. */
const raiseDirty = computed(() => {
    const typed = ['title', 'location', 'description', 'reported_at'].some((key) => String(workOrder[key] ?? '').trim() !== '')

    return typed || unitQuery.value.trim() !== '' || workOrder.priority !== 'medium'
})

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['ticket'] })
</script>

<template>
    <Head title="Facilities" />

    <EstateConsole
        title="Facilities"
        :estate-name="estate.name"
        active="facilities"
        search-placeholder="Search tickets by number, title, place, lot or vendor…"
        :search-route="queueHref"
        :search-value="table.q ?? ''"
        :search-params="searchParams"
    >
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <template #actions>
            <!-- The estate's service targets (M98), in the modal. M96 level 3: a role that can never set them has no button. -->
            <button v-if="canConfigure" type="button" class="btn-outline-sm" title="Set how long each priority has." @click="openTargets">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                    <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                </svg>
                <span>Service targets</span>
            </button>

            <!--
              Built (12 §2, Wave 2): the form captures the location, the category and the priority together, because
              raising a work order starts an SLA clock and each of those decides something. M96 level 3: a role that
              can never raise one has no New work order at all.
            -->
            <button
                v-if="canCreate"
                type="button"
                class="btn-primary-sm"
                title="Raise a work order from the office."
                @click="openRaise"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>New work order</span>
            </button>
        </template>

        <!--
          Three tabs, one module. The tab for the screen the reader is already
          on is text rather than a control, because there is nowhere for it to
          lead.
        -->
        <div class="subnav">
            <div class="subnav-item active" aria-current="page">Maintenance</div>
            <Link :href="amenitiesHref" class="subnav-item">Amenities</Link>
            <!-- Law 4 and M96 level 3: a role without the vendor list has no Vendors tab at all. -->
            <Link v-if="canViewVendors" :href="vendorsHref" class="subnav-item">Vendors</Link>
        </div>

        <!--
          A ticket that closes lands as a toast (M95 §2), the flash by the console ("Ticket #1042 completed." when
          resolving one returns the manager here); a refused priority stays in its open modal's band (Hassam, 12 Oct).
        -->

        <!-- Raise a work order, in the console's modal (M95 §1). Each refusal sits under its field, the field amber (FAC-11). -->
        <ConsoleDialog
            :open="raising"
            title="Raise a work order"
            size="form"
            confirm-label="Raise work order"
            busy-label="Raising…"
            :busy="workOrder.processing"
            :dirty="raiseDirty"
            discard-title="Discard this work order?"
            :confirm-blocked-reason="raiseBlockedBy"
            @confirm="submitWorkOrder"
            @cancel="closeRaise"
        >
            <p class="wo-head">The service clock runs from the time reported: {{ slaLine }}.</p>

            <!-- Each refusal sits under the field it is about, the field amber (FAC-11). -->
            <RequiredMark legend />
            <div class="wo-fields">
                <div class="wo-field wo-field--wide" :class="{ 'has-error': workOrder.errors.title }">
                    <label for="wo-title">What needs doing <RequiredMark /></label>
                    <input id="wo-title" v-model="workOrder.title" type="text" required maxlength="160" placeholder="e.g. Gate lighting" :aria-invalid="workOrder.errors.title ? 'true' : undefined" />
                    <div v-if="workOrder.errors.title" class="wo-error">{{ workOrder.errors.title }}</div>
                </div>
                <div class="wo-field" :class="{ 'has-error': workOrder.errors.location }">
                    <label for="wo-location">Where <RequiredMark /></label>
                    <input id="wo-location" v-model="workOrder.location" type="text" required maxlength="160" placeholder="e.g. Phase 2 visitor parking" :aria-invalid="workOrder.errors.location ? 'true' : undefined" />
                    <div v-if="workOrder.errors.location" class="wo-error">{{ workOrder.errors.location }}</div>
                </div>
                <div class="wo-field" :class="{ 'has-error': (lotNotChosen && units.length > 0) || workOrder.errors.unit_id }">
                    <label for="wo-unit-search">Unit, if it is a household's (optional)</label>
                    <input
                        id="wo-unit-search"
                        v-model="unitQuery"
                        type="search"
                        placeholder="Type the lot, e.g. 47"
                        title="Find the lot this job is for."
                        @change="findUnits"
                        @keydown.enter.prevent="findUnits"
                    />
                    <select id="wo-unit" v-model="workOrder.unit_id" aria-label="The unit this work order is filed against">
                        <option value="">Not a unit</option>
                        <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.label }}</option>
                    </select>
                    <!-- What the SERVER last searched for, not what is half-typed. -->
                    <div v-if="props.unitQuery !== '' && units.length === 0" class="wo-hint">
                        No lot on this estate matches “{{ props.unitQuery }}”.
                    </div>
                    <div v-else-if="units.length === unitMatchLimit" class="wo-hint">
                        The closest {{ unitMatchLimit }} are listed; type more of the lot to narrow them.
                    </div>
                    <!-- Said once there is a list to choose from; before the search answers, Raise's own title says it. -->
                    <div v-if="lotNotChosen && units.length > 0" class="wo-error">{{ LOT_NOT_CHOSEN }}</div>
                    <div v-if="workOrder.errors.unit_id" class="wo-error">{{ workOrder.errors.unit_id }}</div>
                </div>
                <div class="wo-field" :class="{ 'has-error': workOrder.errors.category }">
                    <label for="wo-category">Category <RequiredMark /></label>
                    <select id="wo-category" v-model="workOrder.category" required>
                        <option v-for="category in categories" :key="category.key" :value="category.key">{{ category.label }}</option>
                    </select>
                    <div v-if="workOrder.errors.category" class="wo-error">{{ workOrder.errors.category }}</div>
                </div>
                <div class="wo-field" :class="{ 'has-error': workOrder.errors.priority }">
                    <label for="wo-priority">Priority (sets the target) <RequiredMark /></label>
                    <select id="wo-priority" v-model="workOrder.priority" required>
                        <option v-for="(label, key) in priorities" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <div v-if="workOrder.errors.priority" class="wo-error">{{ workOrder.errors.priority }}</div>
                </div>
                <div class="wo-field" :class="{ 'has-error': reportedLater || reportedTooEarly || workOrder.errors.reported_at }">
                    <label for="wo-reported">Reported at (optional, blank for now)</label>
                    <input
                        id="wo-reported"
                        v-model="workOrder.reported_at"
                        type="datetime-local"
                        :min="reportedMin"
                        :aria-invalid="reportedLater || reportedTooEarly || workOrder.errors.reported_at ? 'true' : undefined"
                    />
                    <div v-if="reportedLater" class="wo-error">The time reported is later than now. Leave it blank for now.</div>
                    <div v-else-if="reportedTooEarly" class="wo-error">{{ reportedTooEarlyLine }}</div>
                    <div v-else-if="workOrder.errors.reported_at" class="wo-error">{{ workOrder.errors.reported_at }}</div>
                </div>
                <div class="wo-field wo-field--wide" :class="{ 'has-error': workOrder.errors.description }">
                    <label for="wo-description">Detail: what the technician should know (optional)</label>
                    <input id="wo-description" v-model="workOrder.description" type="text" maxlength="2000" :aria-invalid="workOrder.errors.description ? 'true' : undefined" />
                    <div v-if="workOrder.errors.description" class="wo-error">{{ workOrder.errors.description }}</div>
                </div>
            </div>
        </ConsoleDialog>

        <!--
          The service targets (M98): how many hours a ticket of each priority has before it is overdue, counted from
          the report. Each refusal sits under its field, amber; the ladder's own refusal is the confirm's reason too.
        -->
        <ConsoleDialog
            :open="settingTargets"
            title="Service targets"
            size="form"
            confirm-label="Save targets"
            busy-label="Saving…"
            :busy="targetsForm.processing"
            :dirty="targetsDirty"
            discard-title="Discard these targets?"
            :confirm-blocked-reason="targetsBlockedBy"
            @confirm="submitTargets"
            @cancel="closeTargets"
        >
            <p class="wo-head">How long a ticket has before it is overdue, counted from when it was reported.</p>
            <RequiredMark legend />
            <div class="wo-fields wo-fields--three">
                <div v-for="key in TARGET_KEYS" :key="key" class="wo-field" :class="{ 'has-error': targetsForm.errors[key] }">
                    <label :for="`target-${key}`">{{ priorities[key] ?? key }}, in hours <RequiredMark /></label>
                    <input
                        :id="`target-${key}`"
                        v-model.number="targetsForm[key]"
                        type="number"
                        min="1"
                        :max="longestTarget"
                        step="1"
                        inputmode="numeric"
                        :aria-invalid="targetsForm.errors[key] ? 'true' : undefined"
                    />
                    <div v-if="targetsForm.errors[key]" class="wo-error">{{ targetsForm.errors[key] }}</div>
                    <div v-else-if="spellHours(targetsForm[key])" class="wo-hint">{{ spellHours(targetsForm[key]) }}</div>
                </div>
            </div>
            <p class="wo-hint wo-hint--after">New tickets take these. A ticket already raised keeps its own.</p>
        </ConsoleDialog>

        <!--
          A ticket's priority, in the console's modal (M95 §1): the three choices and one confirm. Changing it moves
          the service target and does not restart the clock, so the modal says so before the press.
        -->
        <ConsoleDialog
            :open="triagingRow !== null"
            :title="triagingRow ? `Change the priority of ${triagingRow.ticket}?` : ''"
            confirm-label="Set priority"
            busy-label="Setting…"
            :confirm-blocked-reason="priorityBlocked"
            :error="triageRefusal"
            @confirm="confirmPriority"
            @cancel="closeTriage"
        >
            <p class="triage-head">
                Changing the priority moves the service target and does not restart the clock, so an old ticket escalated
                is overdue at once. The ticket opens with its new target.
            </p>
            <div class="triage-options" role="radiogroup" aria-label="Priority">
                <button
                    v-for="(label, key) in priorities"
                    :key="key"
                    type="button"
                    role="radio"
                    class="f-chip"
                    :class="{ active: key === priorityChoice }"
                    :aria-checked="key === priorityChoice ? 'true' : 'false'"
                    @click="priorityChoice = key"
                >
                    {{ label }}
                </button>
            </div>
        </ConsoleDialog>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <!--
                          The four glyphs, lifted from this board rather than
                          taken from a component. The payload names no icon —
                          `queueBoard()` carries a key, a figure and a label, and
                          nothing about how any of them is drawn — so the pairing
                          lives here, where the board it came from is.
                        -->
                        <svg v-if="kpi.key === 'open'" viewBox="0 0 24 24" fill="none">
                            <path
                                d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <svg v-else-if="kpi.key === 'in_progress'" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                            <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else-if="kpi.key === 'overdue'" viewBox="0 0 24 24" fill="none">
                            <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none">
                            <path d="M3 3v18h18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            <path
                                d="M7 15l4-5 4 3 5-7"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>
                </div>

                <div class="k-val">{{ tile(kpi) }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <!-- What Overdue is measured against, as a fact for every reader (M98): this estate's own targets. -->
        <p class="mq-targets-line">Service targets: {{ targetsLine }}.</p>

        <!--
          The chips stand in every state below, including the empty ones: hiding
          the control that produced an empty list is how a reader gets stuck on
          it. The tiles stand for the same reason — they count the whole queue
          and do not move when a chip is pressed, so an emptied table under them
          is visibly a view rather than an estate with no tickets.
        -->
        <div class="filter-row">
            <Link
                v-for="chip in filters"
                :key="chip.key"
                :href="chipHref(chip.key)"
                class="f-chip"
                :class="{ active: chip.key === filter }"
            >
                {{ chip.label }}
            </Link>

            <!--
              THE OVERDUE BADGE IS A FILTER (M68 §2.4, FAC-1). It was a readout
              drawn like the chips beside it, and pressing it did nothing. It
              lists the tickets past their service target, whatever state each is
              in (overdue is a flag, not a group), keeps the search, shows when it
              is on, and pressing it again shows every ticket. The figure is the
              Overdue tile's, from the same count.

              Absent rather than zero, unless it is the filter that is on: a red
              badge reading "0 overdue" is an alarm for nothing.
            -->
            <Link
                v-if="overdue > 0 || filter === 'overdue'"
                :href="filter === 'overdue' ? chipHref('all') : chipHref('overdue')"
                class="f-chip mq-overdue"
                :class="{ active: filter === 'overdue' }"
                :aria-label="filter === 'overdue' ? 'Show every ticket again' : undefined"
                :title="filter === 'overdue' ? 'Show every ticket again.' : 'Show the tickets past their service target.'"
            >
                {{ overdue }} overdue<span v-if="filter === 'overdue'" aria-hidden="true"> ×</span>
            </Link>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="7" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Facilities is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The maintenance queue could not be read"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <!-- One plain line each (M78 §2): the tiles above still count the whole queue, so an emptied list reads as a view. -->
        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            :title="table.q ? 'No ticket matches that search' : 'No ticket is in this state'"
            :body="table.q ? 'Try another word, or show the whole queue.' : 'None of the estate’s tickets is at this stage right now.'"
            action-label="Show the whole queue"
            @action="router.visit(wholeQueueHref)"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nothing has been reported"
            body="A fault a resident reports in the app, or a work order raised here, appears in this list."
        />

        <DataTable
            v-else
            :columns="COLUMNS"
            :rows="rows"
            :table="table"
            :noun="noun"
        >
            <!-- "#1042 · Gate lighting", composed on the server, so the number and its separator are written in one
                 place and a bill recorded against the job prints the same string. Allowed to break anywhere (DR-116):
                 one long unbroken word in a title pushed Status, Assigned and Age past the window's edge. -->
            <template #cell-number="{ row }">
                <span class="mq-ticket">{{ row.ticket }}</span>
                <!-- The resident's photos, marked on the row (FAC-5): they were found only by opening each ticket. -->
                <span v-if="row.photos > 0" class="mq-photo" :title="photoLine(row.photos)" role="img" :aria-label="photoLine(row.photos)">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linejoin="round"
                        />
                        <circle cx="12" cy="13" r="3.2" stroke="currentColor" stroke-width="1.7" />
                    </svg>
                </span>
            </template>

            <!--
              The priority is the row's one act, so it is the row's one control, and pressing it asks in the modal.
              M96 level 3: where it can never be changed (a role without the access, a closed ticket) it is the
              priority as a fact, not a control that refuses.
            -->
            <template #cell-priority="{ row }">
                <button
                    v-if="triageBlockedBy(row) === null"
                    type="button"
                    class="priority-dot"
                    :class="dotClass(row.priority)"
                    :title="PRIORITY_HINT"
                    @click="openTriage(row)"
                >
                    <i></i>{{ row.priority_label }}
                </button>
                <span v-else class="priority-dot" :class="dotClass(row.priority)"><i></i>{{ row.priority_label }}</span>
            </template>

            <!-- The state it is in, and Overdue beside it, never instead of it (M68 §2.3, FAC-3): an overdue ticket
                 still says whether anybody has started it. Each with its icon beside the word (M96). -->
            <!-- A state is a link to where it is acted on: the work order (Hassam, batch 1 item 10). -->
            <template #cell-status="{ row }">
                <Link :href="ticketHref(row)" class="mq-status status-link" :title="`Open ${row.ticket}.`">
                    <span class="status-badge mq-badge" :class="row.status" :style="badgeStyle(row.status)">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path :d="STATUS_ICON[row.status] ?? STATUS_ICON.submitted" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        {{ row.status_label }}
                    </span>
                    <span v-if="row.is_overdue" class="status-badge overdue">Overdue</span>
                </Link>
            </template>

            <!-- "Unassigned" is the board's own word for a ticket nobody has been given, and it is a fact about the
                 job rather than a blank. Age, or "Closed Sep 2" where the job is finished: how old a completed ticket
                 is tells a manager nothing; when it finished tells them whether the estate is keeping up. -->

            <template #cell-actions="{ row }"><Link :href="ticketHref(row)" class="text-link-sm">Open</Link></template>
        </DataTable>
    </EstateConsole>
</template>

<style scoped>
/* A state that opens where it is acted on (batch 1 item 10): the badge as drawn, a link to the hand and the keyboard. */
.status-link {
    text-decoration: none;
    cursor: pointer;
}

.status-link:hover .mq-badge {
    text-decoration: underline;
}

.status-link:focus-visible {
    outline: 2px solid var(--amber-500);
    outline-offset: 2px;
}

/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its tabs, its topbar action, its chips and its priority cells
 * as divs. Here they are real buttons and real links, and a button element
 * arrives wearing the browser's own font, a border and buttonface grey. The
 * board's own classes supply everything visible; app.css already takes the UA's
 * underline and blue off every anchor on a board page, so the links need nothing.
 *
 * NOTHING BELOW RESETS `border` ON A CLASS THE BOARD GIVES ONE TO. A scoped
 * element selector outranks a single class, so a blanket `button { border: 0 }`
 * would out-specify the board and rub out a real edge — see D-045, where exactly
 * that silently deleted .btn-outline-sm's 1.5px outline and survived measurement
 * because 1.5px on one small control is a few hundred pixels. Each class named
 * here declares no border of its own.
 */
button {
    font: inherit;
}

/*
 * `font: inherit` rather than `font-family: inherit`, because .priority-dot
 * declares no type at all — the board's div takes its 12.5px from the table
 * cell around it, and a button element left alone would take 13.33px Arial from the
 * user agent instead. The classes that DO declare a size out-specify this.
 */
button.priority-dot {
    border: 0;
    background: none;
    padding: 0;
    color: inherit;
    cursor: pointer;
}

/* .btn-primary-sm declares a background and no border; .subnav-item declares
 * neither, and the white belongs to .subnav-item.active — which is this screen,
 * so it is a link and never one of these buttons. .f-chip declares its own
 * navy-100 face and needs only the border taken off. */
button.btn-primary-sm,
button.f-chip {
    border: 0;
}

button[disabled] {
    cursor: not-allowed;
}

/* The priority as a fact where it can never be changed (M96 level 3): the dot and the word, no control. */
span.priority-dot {
    cursor: default;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no work-order form and no priority ladder, because nothing has ever been
 * pressed on it; both are the console's modal's body now (M95 §1), and a refusal is a toast. Kept to the tokens the
 * boards define and the type sizes .data-table's and .res-sub's set.
 */
.triage-head {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-800);
    line-height: 1.5;
    margin: 0 0 12px;
}

.triage-options {
    display: flex;
    align-items: center;
    gap: 10px;
}

.wo-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
    margin: 0 0 12px;
}

/* Two fields a row in the modal (four, the page form's grid, crushed Detail to a quarter of it); one on a phone. */
.wo-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 11px;
}

/* The three service targets, side by side. */
.wo-fields.wo-fields--three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

@media (max-width: 640px) {
    .wo-fields,
    .wo-fields.wo-fields--three {
        grid-template-columns: minmax(0, 1fr);
    }
}

.wo-hint--after {
    margin: 12px 0 0;
}

/* The targets as a fact under the figures (M98), in .wo-hint's slate. */
.mq-targets-line {
    margin: -8px 0 14px;
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.5;
}

/* The status badge's icon, beside its word (M96). */
.mq-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.mq-badge svg {
    width: 11px;
    height: 11px;
    flex: 0 0 auto;
    display: block;
}

.wo-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.wo-field--wide {
    grid-column: 1 / -1;
}

/* The unit search's answer, under it (M11 A2): a sentence, not a control. */
.wo-hint {
    font-size: 10.5px;
    color: var(--slate-500);
}

.wo-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.wo-field input,
.wo-field select {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

/* A refusal is amber, never red (Hassam's red rule, 11 Oct: solid red is an open panic's alone). */
.wo-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

/* A field the server or the clock refused: its edge amber, its words under it (FAC-11). */
.wo-field.has-error input,
.wo-field.has-error select {
    border-color: var(--amber-500);
}

/* The overdue badge (M68 §2.4): the board's amber chip at the row's end, filled when it is the filter that is on. */
.f-chip.mq-overdue {
    margin-left: auto;
    background: var(--amber-100);
    color: var(--amber-700);
}

.f-chip.mq-overdue.active {
    background: var(--amber-700);
    color: var(--white);
}

/* The state and, when late, the Overdue badge beside it: the shared `.status-badge.overdue`, amber (M68 §2.3; D-185). */
.mq-status {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}

/* DR-116: a ticket's title may break anywhere, so no single word sets the table's width ("anywhere", unlike
   "break-word", also lets the column shrink to fit). */
.mq-ticket {
    overflow-wrap: anywhere;
}

/* The resident's photos, a small slate camera after the ticket (FAC-5): a fact about the row, not a warning. */
.mq-photo {
    display: inline-flex;
    vertical-align: middle;
    margin-left: 6px;
    color: var(--slate-500);
}

.mq-photo svg {
    width: 14px;
    height: 14px;
}

/*
 * ON A PHONE (seen at 390 px, 12 Oct): the four figures two a row and the chips wrapping, where the board's single
 * rows pushed the page to 484 px; the table keeps readable columns and scrolls inside its own card, as DataTable's
 * scroll box allows, rather than breaking every title a letter at a time. Doubled classes outrank the board's
 * `body.wf-… .kpi-row`.
 */
@media (max-width: 640px) {
    .kpi-row.kpi-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .filter-row.filter-row {
        flex-wrap: wrap;
    }

    :deep(.data-table) {
        min-width: 760px;
    }
}
</style>
