<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { keptByTable } from '../../../lib/tableParams'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Amenity bookings — board screen community-admin-19.
 *
 * THE DIARY, AND NOT ONE FIGURE ON IT IS A HOUSEHOLD'S FINANCIAL POSITION. The
 * persona is the Property Manager, who holds `facilities` in full and holds `-`
 * on `dues_ledger`, `payments` and `accounting_posting` — D-010 split those
 * modules precisely so the person who runs the estate's amenities never reaches
 * what a household owes. The Deposit column is the amount SNAPSHOTTED onto the
 * booking, which is a fact about the booking rather than about the account, and
 * the arrears rule reaches this screen as a boolean or not at all.
 *
 * EVERY STRING IN THE TABLE IS FORMATTED BEFORE IT GETS HERE, and that is the
 * point. `whenLabel()` and `depositLabel()` live on the model because the board
 * prints money in a form the ledger boards do not — a bare "$5,000", no
 * decimals, no J$ — and the amount is stored in minor units like every other
 * amount in this system. A template dividing by a hundred is how a rounding
 * artefact reaches a deposit the estate is actually holding, so nothing here
 * divides by anything.
 *
 * THE DATE CELL IS DERIVED AND THE BOARD IS WHY. Board 19 dates three bookings
 * "Sat, Sep 20", "Sun, Sep 21" and "Sat, Sep 27", and no calendar has all three
 * of those weekdays. So the DATE is stored and the weekday is computed from it,
 * and the screen can never be internally wrong about a Saturday. A past booking
 * collapses to "Aug 15 (past)" for the same reason it is drawn that way: once an
 * event has happened, which two hours of that Saturday it ran for is not what
 * anybody is scanning the list for.
 *
 * "CHARGE FEE" IS INERT FOR THE ROLE THIS BOARD IS DRAWN FOR, DELIBERATELY.
 * Recording a booking fee posts a charge against the unit — Dr 1200 Dues
 * Receivable, Cr 4100 Amenity Booking Fees — which adds to what a household
 * owes, so the route carries `estate.dues_ledger.create` beside the facilities
 * gate. The Property Manager holds the first and not the second. The board names
 * the act among their actions; the platform invariant outranks the board, so the
 * control is drawn and disabled with the real reason on it rather than quietly
 * working. `canCharge` is the answer to that question and `chargeReason` is the
 * sentence.
 *
 * IT IS NOT OFFERED ON A PENDING ROW. Charging a household for a Saturday the
 * estate has not yet agreed to is the same debt-they-never-agreed-to that
 * `Amenities::recordFee()` refuses on a declined booking, one step earlier — so
 * the fee appears against bookings the estate has actually accepted, and a
 * pending row keeps the approve/reject pair the board draws on it. The server
 * holds the same rule (B052): `recordFee()` refuses a pending booking too, so a
 * request that did not come from this button cannot bill one either.
 *
 * BLOCKING A SLOT IS NOT ON THIS SCREEN, and `canCreate` is deliberately unused
 * — the same shape as `reasons.restrict` on board 5. Taking a period out of an
 * amenity's diary means naming a start, an end and a reason against a calendar
 * somebody can see, so that they can tell it does not sit over a confirmed
 * booking; the route exists and refuses the overlap, but a two-datetime form
 * dropped onto a list board is a screen nobody has approved. The board draws no
 * blocking control either.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    estate: { type: Object, required: true },
    /** The chip row: every bookable amenity, in the estate's own order. */
    amenities: { type: Array, required: true },
    /** Which amenity chip is engaged, or '' for all of them. */
    filter: { type: String, required: true },
    /** Whether only the bookings waiting on a decision are shown (AME-2). */
    waiting: { type: Boolean, default: false },
    /** How many bookings are waiting on a decision — the amber chip's count. */
    pending: { type: Number, required: true },
    rows: { type: Array, required: true },
    /** The diary as the shared table reads it (M32 F5): board 19's own order, a page at a time. */
    table: { type: Object, required: true },
    canUpdate: { type: Boolean, required: true },
    canCreate: { type: Boolean, required: true },
    canCharge: { type: Boolean, required: true },
    chargeReason: { type: String, required: true },
    /** Whether the viewer may open the supplier register, which is Accounting's screen. */
    canViewVendors: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

// A booking made or cancelled in the Resident App lands on this list by itself (M18H §7.2).
useLiveReload({ entities: ['booking'] })

const page = usePage()

/*
 * All six. `empty-filtered` is genuinely reachable here and by a control the
 * board draws: the Pool Deck is bookable and has no bookings, so its chip
 * produces a list emptied by the view rather than by the estate. Sources are
 * functions, not values — useScreenState runs once during setup, and a value
 * read there would freeze on the first render.
 */
const state = useScreenState({
    rows: () => props.table.total,
    filtered: () => props.filter !== '' || props.waiting,
})

const COLUMNS = [
    { key: 'amenity', label: 'Amenity', sortable: true },
    { key: 'booked_by', label: 'Booked by', sortable: true },
    { key: 'when', label: 'Date & time', sortable: true },
    { key: 'deposit', label: 'Deposit', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'actions', label: '' },
]

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/facilities/amenities/bookings. The URL that served this page
 * already carries whichever shape this environment uses, so cutting it at
 * /facilities is correct in both — a hard-coded root is correct in exactly one,
 * and unlike a tenant key read off a prop it cannot address an estate other than
 * the one already open.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/facilities')))

const facilities = (suffix) => `${root.value}/facilities${suffix}`

/**
 * One chip per bookable amenity, plus the "all" chip in front.
 *
 * GENERATED FROM THE ESTATE'S OWN AMENITIES rather than fixed at the three the
 * board happens to draw. `Amenities::bookingsBoard()` makes that decision and
 * says why; the consequence on this estate is a fourth chip, Pool Deck, which is
 * bookable and currently has nothing booked against it. Drawing three would mean
 * an estate could not filter to an amenity it owns.
 *
 * The chips are LINKS, so the filter lives in the query string: a filtered diary
 * is a URL somebody can send to whoever has to decide, and the list and its
 * pending count are then computed in one place rather than two.
 */
/*
 * A chip keeps the table's order and page size, and starts it again at page one (M32 F5); an amenity chip keeps the
 * waiting filter while it is on (AME-2).
 */
const chipHref = (amenity, waiting = props.waiting) => {
    const query = new URLSearchParams({
        ...(amenity === '' ? {} : { amenity }),
        ...(waiting ? { waiting: '1' } : {}),
        ...keptByTable(props.table),
    }).toString()

    return facilities(`/amenities/bookings${query ? `?${query}` : ''}`)
}

const chips = computed(() => [
    { label: 'All amenities', href: chipHref(''), active: props.filter === '' },
    ...props.amenities.map((name) => ({
        label: name,
        href: chipHref(name),
        active: props.filter === name,
    })),
])

/**
 * The amber chip: a COUNT, and the control that shows what it counts (AME-2).
 *
 * The board draws it in the chip row wearing the same shape as the four beside
 * it. It was text, because nothing on the server filtered on status; it is now
 * a link to `?waiting=1`, the bookings waiting on a decision, filled when on and
 * pressed again to show every booking. It counts the whole diary and not the
 * filtered view: `bookingsBoard()` tallies before it filters, because "one
 * booking is waiting on you" stays true when somebody clicks Gazebo.
 *
 * Absent at zero rather than drawn as "0 pending approval" — an amber chip
 * announcing nothing is a warning about an empty queue — unless it is on, so it
 * can always be turned off. Amber rather than red: a booking waiting on a
 * decision is work, not a failure.
 */
const pendingLabel = computed(() =>
    `${props.pending} pending approval${props.pending === 1 ? '' : 's'}`
)

const PENDING_CHIP_TITLE = computed(() =>
    props.waiting ? 'Show every booking again.' : 'Show only the bookings waiting on a decision.'
)

/* ------------------------------------------------------------------ */
/* the module's own tabs */
/* ------------------------------------------------------------------ */

/**
 * Maintenance, Amenities, Vendors — one module drawn as three tabs.
 *
 * The tab the reader is on is text rather than a control, because there is
 * nowhere for it to lead. Maintenance is a real link: the queue is board 17, it
 * is behind the same `facilities` gate this screen is, and anybody reading this
 * diary can already reach it.
 *
 * VENDORS IS A LINK FOR WHOEVER HOLDS ACCOUNTING, AND ONLY FOR THEM. The
 * supplier register is board 26, in the Accounting module, and it is the same
 * register these bookings' amenities are maintained from. It used to be inert
 * for everybody; it is inert now only for a role without Accounting view — the
 * Property Manager, by D-010 — and `reasons.vendors` says exactly that. Telling
 * them a built screen was unbuilt would send them to ask for the wrong thing.
 */
const maintenanceHref = computed(() => facilities('/maintenance'))

/** Board 26, cut from the same root one module over. */
const vendorsHref = computed(() => `${root.value}/accounting/vendors`)

/* ------------------------------------------------------------------ */
/* deciding a booking */
/* ------------------------------------------------------------------ */

/**
 * Why approving or declining cannot be pressed, or null when it can.
 *
 * AUTHORED, because this payload carries no `blockedReason` — the board is drawn
 * for the role that holds Facilities in full, so the case does not arise on it.
 * It arises for a President or a Treasurer, both of whom hold Facilities as View
 * only, and a screen that renders them a live tick would be lying about what the
 * route will accept.
 */
const decideBlockedBy = computed(() =>
    props.canUpdate
        ? null
        : 'Deciding a booking tells a household whether their Saturday is theirs, so it needs Facilities update access. You are able to read this diary.'
)

/**
 * The booking being declined, or null. In the console's modal since M95 §1 (M97 pass): the form opened inside the table
 * under its row, which made the diary longer and pushed the rows below it down.
 */
const declining = ref(null)

const decliningRow = computed(() => (declining.value === null ? null : props.rows.find((row) => row.id === declining.value) ?? null))

/** Why Decline booking cannot be pressed yet, said under it (M96 level 2). */
const declineBlocked = computed(() => (form.reason.trim() === '' ? 'Say why, in the words the household will be given.' : null))

/*
 * A REFUSAL OF AN OPEN DIALOG IS SAID INSIDE IT (Hassam, 12 Oct): Approve's and Charge's, each in its own modal's amber
 * band, the modal staying open; Decline's under its field. They were closed and toasted, and at 390 px a toast behind a
 * modal is hidden or scrolled away.
 */
const refusalOf = (errors, fallback) => errors.booking ?? errors.fee ?? Object.values(errors)[0] ?? fallback

const approveRefusal = ref(null)
const chargeRefusal = ref(null)

/*
 * A REASON IS REQUIRED AND THE SERVICE SAYS SO. `Amenities::decline()` refuses
 * an empty one, because a household told only "declined" has nothing to act on
 * and will ask a guard at a gate about it — which is the one place the answer
 * cannot be given. So the field is put in front of the decision rather than the
 * press being sent to be bounced.
 */
const form = useForm({ reason: '' })

const openDecline = (row) => {
    if (decideBlockedBy.value !== null) {
        return
    }

    declining.value = row.id
    form.clearErrors()
    form.reason = ''
}

const closeDecline = () => {
    declining.value = null
    form.reset()
    form.clearErrors()
}

/*
 * APPROVE ASKS FIRST (DR-057). Approving confirms the booking, charges its fee to the household in the same transaction
 * (`Amenities::approveRequest()`) and tells the household, by push and, through ResidentEmails, by email where they asked
 * for it. So the press opens a question naming the booking, the fee (the server's own `fee_label`) and who is told, and
 * only the dialog's own "Approve" posts.
 */
const approving = ref(null)

const approveTitle = computed(() => {
    const row = approving.value

    if (row === null) {
        return ''
    }

    return row.fee_label ? `Approve ${row.reference} and charge ${row.fee_label}?` : `Approve ${row.reference}?`
})

const approveWhat = computed(() => (approving.value === null ? '' : `${approving.value.amenity} · ${approving.value.when} · ${approving.value.booked_by}.`))

const approveWho = computed(() =>
    approving.value?.fee_label
        ? 'The fee goes on their ledger. They are told in the app, and by email where they asked for it.'
        : 'They are told in the app, and by email where they asked for it.'
)

const approve = (row) => {
    if (decideBlockedBy.value !== null) {
        return
    }

    approveRefusal.value = null
    approving.value = row
}

const closeApprove = () => {
    approving.value = null
    approveRefusal.value = null
}

const postApprove = () => {
    if (approving.value === null) {
        return
    }

    approveRefusal.value = null
    router.post(facilities(`/amenities/bookings/${approving.value.id}/approve`), {}, {
        preserveScroll: true,
        onSuccess: () => {
            approving.value = null
        },
        onError: (errors) => {
            approveRefusal.value = refusalOf(errors, 'The booking was not approved.')
        },
    })
}

const submitDecline = () => {
    /*
     * The route is gated and the service refuses an empty reason, so this is only about not sending a decision that
     * will bounce. A refusal of the reason stays under the field; any other refusal is the dialog's own line.
     */
    if (decideBlockedBy.value !== null || declining.value === null || form.reason.trim() === '') {
        return
    }

    form.post(facilities(`/amenities/bookings/${declining.value}/decline`), {
        preserveScroll: true,
        onSuccess: () => closeDecline(),
    })
}

/* ------------------------------------------------------------------ */
/* charging the fee */
/* ------------------------------------------------------------------ */

/**
 * Which rows offer the fee at all.
 *
 * The bookings the estate has actually agreed to. A declined or cancelled
 * booking is a debt the household never agreed to and the service refuses it; a
 * pending one has not been agreed yet, which is the same objection a day earlier.
 */
const chargeable = (row) => row.status === 'confirmed' || row.status === 'completed'

/**
 * Why the fee cannot be recorded on this row, or null when it can.
 *
 * THE PLATFORM INVARIANT COMES FIRST because it is true of every row and it is
 * the one refusal nobody on this screen can do anything about: D-010 locks the
 * Property Manager out of Dues & ledger, and a fee is a charge on a unit. The
 * other three are facts about the booking.
 */
const feeBlockedBy = (row) => {
    if (!props.canCharge) {
        return props.chargeReason
    }

    if (!props.canUpdate) {
        return decideBlockedBy.value
    }

    if (row.is_charged) {
        return `Booking ${row.reference} has already been charged. Billing one Saturday twice is a refund the estate would have to notice before it could make it, so it can happen exactly once.`
    }

    if (row.fee_minor <= 0) {
        return `The ${row.amenity} carries no booking fee, so there is nothing to charge. An entry for nothing is a line in the ledger saying something happened.`
    }

    return null
}

/*
 * CHARGE FEE ASKS FIRST (M68 §2.5, AME-1). It posts money to a household's ledger, and it sat under View as the same
 * amber link. It is a button of its own look now, and the press opens a question naming the amount (the server's own
 * `fee_label`) and the household; only the dialog's own "Charge" posts. Charging sends no message, so the question
 * says only what it does.
 */
const charging = ref(null)

const chargeTitle = computed(() => (charging.value === null ? '' : `Charge ${charging.value.fee_label} to ${charging.value.unit}?`))

const chargeWhat = computed(() =>
    charging.value === null ? '' : `${charging.value.reference} · ${charging.value.amenity} · ${charging.value.when} · ${charging.value.booked_by}.`
)

const charge = (row) => {
    if (feeBlockedBy(row) !== null) {
        return
    }

    chargeRefusal.value = null
    charging.value = row
}

const closeCharge = () => {
    charging.value = null
    chargeRefusal.value = null
}

const postCharge = () => {
    if (charging.value === null) {
        return
    }

    chargeRefusal.value = null
    router.post(facilities(`/amenities/bookings/${charging.value.id}/fee`), {}, {
        preserveScroll: true,
        onSuccess: () => {
            charging.value = null
        },
        onError: (errors) => {
            chargeRefusal.value = refusalOf(errors, 'The fee was not charged.')
        },
    })
}

/** What a live fee control does, in one line (Law 2). */
const FEE_TITLE = 'Charge the booking fee to the household’s ledger.'

/**
 * A status the board's stylesheet has no badge for.
 *
 * It draws Confirmed, Pending and Completed and defines a rule for each.
 * Declined and Cancelled are real states of a booking and neither is a fault to
 * flag in red — one is a decision the estate made and the other a household's —
 * so they take the neutral navy the boards use for a fact that is simply over.
 * Declared inline for the same reason board 26 does it: borrowing a badge that
 * means something else would say something else.
 */
const NEUTRAL_BADGE = 'background:var(--navy-100);color:var(--slate-600);'

/*
 * Rejected is the one vocabulary's word for a declined booking (M96 §2), and it reads in a red outline, drawn inside the
 * badge so it is no larger than the others (Hassam, 11 October: "Rejected becomes a red outline, not solid red, not amber").
 */
const REJECTED_BADGE = 'background:transparent;box-shadow:inset 0 0 0 1px var(--red-700);color:var(--red-700);'

const badgeStyle = (status) => {
    if (['confirmed', 'pending', 'completed'].includes(status)) {
        return undefined
    }

    return status === 'declined' ? REJECTED_BADGE : NEUTRAL_BADGE
}

/** Each state's icon, so a badge is never told apart by colour alone (M96). */
const STATUS_ICON = {
    pending: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
    confirmed: 'M20 6 9 17l-5-5',
    completed: 'M20 6 9 17l-5-5',
    declined: 'M6 6l12 12M18 6L6 18',
    cancelled: 'M8 12h8M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
    expired: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
}
</script>

<template>
    <Head title="Amenity bookings" />

    <EstateConsole title="Amenity bookings" :estate-name="estate.name" active="facilities">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <template #actions>
            <!--
              An outline button, not the primary blue — the rate card is where
              this screen's figures come from rather than the next thing to do
              on it. The gear is the board's own abbreviated one: two paths
              where the sidebar's has the full ring of teeth. Copied as drawn.
            -->
            <Link :href="facilities('/amenities/settings')" class="btn-outline-sm">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5" />
                    <path
                        d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83"
                        stroke="currentColor"
                        stroke-width="1.5"
                    />
                </svg>
                <span>Manage amenities</span>
            </Link>
        </template>

        <div class="subnav">
            <Link :href="maintenanceHref" class="subnav-item">Maintenance</Link>
            <div class="subnav-item active" aria-current="page">Amenities</div>
            <!-- Law 4 and M96 level 3: a role without the vendor list has no Vendors tab at all. -->
            <Link v-if="canViewVendors" :href="vendorsHref" class="subnav-item">Vendors</Link>
        </div>

        <!-- A decision lands as a toast (M95 §2), the flash by the console; a refusal stays in its open modal's band (Hassam, 12 Oct). -->

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Facilities is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The booking diary could not be read"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!--
              The chips stand in every remaining state. Hiding them over an empty
              filtered list would take away the control that produced it, and the
              pending count is about the whole diary rather than the view.
            -->
            <div class="filter-row">
                <Link
                    v-for="chip in chips"
                    :key="chip.label"
                    :href="chip.href"
                    class="f-chip"
                    :class="{ active: chip.active }"
                >
                    {{ chip.label }}
                </Link>

                <Link
                    v-if="pending > 0 || waiting"
                    :href="chipHref(filter, !waiting)"
                    class="f-chip bk-waiting"
                    :class="{ active: waiting }"
                    :title="PENDING_CHIP_TITLE"
                >
                    {{ pendingLabel }}
                </Link>
            </div>

            <!-- Nothing waiting in this view is a different empty from an amenity nobody has booked (AME-2). -->
            <EmptyState
                v-if="state.isEmptyFiltered.value && waiting"
                variant="filtered"
                title="Nothing here is waiting on a decision"
                body="Every booking in this view has been approved or declined."
                action-label="Show every booking"
                @action="router.visit(facilities('/amenities/bookings'))"
            />

            <EmptyState
                v-else-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="Nothing is booked against this amenity"
                body="The chips above reach the other amenities."
                action-label="Show every amenity"
                @action="router.visit(facilities('/amenities/bookings'))"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing is booked yet"
                body="Bookings residents make in the app appear here."
            />

            <DataTable
                v-else
                :columns="COLUMNS"
                :rows="rows"
                :table="table"
                noun="bookings"
            >
                <template #cell-amenity="{ row }">
                    <!--
                      The glyph is the amenity's OWN key, stored on
                      the record rather than matched from its name:
                      the board draws a different one per amenity and
                      the same key has to resolve to the larger card
                      glyph on board 20, so an estate adding a tennis
                      court picks an icon instead of inheriting
                      whichever one a string match landed on.

                      Drawn here rather than taken from BoardIcon,
                      which carries the icons the boards use in more
                      than one place; these four are this pair of
                      screens' own, at this board's own 1.6.
                    -->
                    <div class="amen-cell">
                        <div class="amen-icon">
                            <svg v-if="row.icon === 'gazebo'" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M12 3v18M4 9c0-3.3 3.6-6 8-6s8 2.7 8 6"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linecap="round"
                                />
                                <path d="M4 9h16" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                            <svg v-else-if="row.icon === 'clubhouse'" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M4 21V9l8-6 8 6v12"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linejoin="round"
                                />
                                <path d="M9 21v-7h6v7" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                            <svg v-else-if="row.icon === 'pavilion'" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M3 10l9-6 9 6"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linejoin="round"
                                />
                                <path
                                    d="M5 10v9M11 10v9M13 10v9M19 10v9M3 19h18"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                />
                            </svg>
                            <!--
                              The Pool Deck, which board 19 never
                              draws because nothing is booked against
                              it. Its geometry is board 20's card
                              glyph at this board's own weight —
                              derived from its three siblings, which
                              differ between the two screens only in
                              stroke.
                            -->
                            <svg v-else viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                                <path
                                    d="M12 3v2M12 19v2M3 12h2M19 12h2"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </div>{{ row.amenity }}</div>
                </template>

                <!--
                  The deposit AS THE BOOKING CARRIES IT. Copied off
                  the amenity when the booking was made and never
                  read back through the relation, so the rate card
                  can be edited tomorrow without restating money the
                  estate is already holding. A booking with no
                  deposit due says nothing here rather than "$0",
                  which would claim a deposit of nothing was taken.
                -->
                <template #cell-deposit="{ row }">{{ row.deposit }}</template>

                <!-- The word, its colour and its icon (M96): never the colour alone. -->
                <!-- A state is a link to where it is acted on: the booking (Hassam, batch 1 item 10). -->
                <template #cell-status="{ row }">
                    <Link
                        :href="facilities(`/amenities/bookings/${row.id}`)"
                        class="status-badge bk-status status-link"
                        :class="row.status"
                        :style="badgeStyle(row.status)"
                        :title="`Open ${row.reference}.`"
                    >
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path :d="STATUS_ICON[row.status] ?? STATUS_ICON.pending" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        {{ row.status_label }}
                    </Link>
                </template>

                <template #cell-actions="{ row }">
                    <!--
                      The row's controls are its STATUS, not a preference. A booking waiting on a decision gets the pair
                      that makes it, for a role that can decide; everything else gets the link the board draws. M96
                      level 3: a role that can never decide sees the status and View, not a pair that refuses.
                    -->
                    <div v-if="row.decidable && decideBlockedBy === null" class="row-actions">
                        <button
                            type="button"
                            class="icon-btn approve"
                            :title="`Approve ${row.reference} and tell the household.`"
                            :aria-label="`Approve booking ${row.reference}`"
                            @click="approve(row)"
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
                            :title="`Decline ${row.reference} with a reason for the household.`"
                            :aria-label="`Decline booking ${row.reference}`"
                            @click="openDecline(row)"
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

                    <!--
                      Stacked rather than side by side, so the cell
                      stays inside the width the approve/reject pair
                      above already sets and the table's columns
                      keep the proportions the board drew them in.
                    -->
                    <div v-else class="bk-actions">
                        <!--
                          The booking detail, which no board draws
                          (D-086). The deposit door is there, so this
                          is where "View" goes.
                        -->
                        <Link :href="facilities(`/amenities/bookings/${row.id}`)" class="text-link-sm">
                            View
                        </Link>

                        <!-- Charged already: the button is gone, and the row says when (M53 Law 1). -->
                        <span v-if="row.is_charged" class="bk-charged">{{ row.charged_label ?? 'Charged' }}</span>
                        <!--
                          Money to a household's ledger, so never drawn as the View link above it (AME-1). M96 level 3:
                          only where it can be pressed; a role that never charges, or a booking with no fee, has none.
                        -->
                        <button
                            v-else-if="chargeable(row) && feeBlockedBy(row) === null"
                            type="button"
                            class="bk-charge"
                            :title="FEE_TITLE"
                            @click="charge(row)"
                        >
                            Charge fee
                        </button>
                    </div>
                </template>
            </DataTable>
        </template>

        <!--
          Decline, in the console's modal (M95 §1), naming the booking. The reason is required and the household is
          given it word for word; a refusal of it sits under the field.
        -->
        <ConsoleDialog
            :open="decliningRow !== null"
            :title="decliningRow ? `Decline ${decliningRow.reference}?` : ''"
            size="form"
            tone="danger"
            confirm-label="Decline booking"
            busy-label="Declining…"
            :busy="form.processing"
            :dirty="form.reason.trim() !== ''"
            discard-title="Discard this reason?"
            :confirm-blocked-reason="declineBlocked"
            @confirm="submitDecline"
            @cancel="closeDecline"
        >
            <p v-if="decliningRow" class="bk-ask">
                {{ decliningRow.amenity }} · {{ decliningRow.when }} · {{ decliningRow.booked_by }}. The period goes back
                into the diary.
            </p>
            <RequiredMark legend />
            <div class="bk-field">
                <label for="decline-reason">Why, in the words the household will be given <RequiredMark /></label>
                <input
                    id="decline-reason"
                    v-model="form.reason"
                    type="text"
                    required
                    maxlength="190"
                    placeholder="e.g. The Community Centre is closed that weekend for resurfacing."
                />
                <div v-if="form.errors.reason" class="bk-error">{{ form.errors.reason }}</div>
            </div>
        </ConsoleDialog>

        <ConsoleDialog
            :open="charging !== null"
            :title="chargeTitle"
            confirm-label="Charge"
            busy-label="Charging…"
            :error="chargeRefusal"
            @confirm="postCharge"
            @cancel="closeCharge"
        >
            <p class="bk-ask">{{ chargeWhat }}</p>
            <p class="bk-ask">It goes on the household's ledger and their statement. A booking is charged once.</p>
        </ConsoleDialog>

        <ConsoleDialog
            :open="approving !== null"
            :title="approveTitle"
            confirm-label="Approve"
            busy-label="Approving…"
            :error="approveRefusal"
            @confirm="postApprove"
            @cancel="closeApprove"
        >
            <p class="bk-ask">{{ approveWhat }}</p>
            <p class="bk-ask">{{ approveWho }}</p>
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
 * The board draws every control on this screen as a <div>; here they are real
 * buttons and real links, and a <button> arrives wearing the browser's own font,
 * a border and buttonface grey. The board's own classes supply everything
 * visible. app.css has already taken the UA's underline and blue off every
 * anchor on a board page, so the links need nothing at all.
 *
 * NOTHING BELOW RESETS `border` ON A CLASS THE BOARD GIVES ONE TO. A scoped
 * element selector outranks a single class, so a blanket `button { border: 0 }`
 * would out-specify the board and rub out .btn-outline-sm's 1.5px navy edge —
 * exactly the defect D-045 records, small enough to survive a passing
 * measurement while drawing a control the board does not draw. Every class named
 * here declares no border of its own.
 */
button {
    font-family: inherit;
}

button.subnav-item {
    border: 0;
    background: none;
}

/* .icon-btn and .btn-primary-sm each declare their own background and no border. */
button.icon-btn,
button.btn-primary-sm {
    border: 0;
}

/*
 * The amber chip at the row's end (AME-2): the board's own inline amber, now a class because it is a control,
 * filled when its filter is on, as the maintenance queue's overdue chip is.
 */
.f-chip.bk-waiting {
    margin-left: auto;
    background: var(--amber-100);
    color: var(--amber-700);
}

.f-chip.bk-waiting.active {
    background: var(--amber-700);
    color: var(--white);
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board is a still image of a screen nobody has pressed anything on, so it draws no
 * decline form; the form is the console's modal's body now (M95 §1), a decision lands as a toast, and a refusal stays
 * in its modal (Hassam, 12 Oct).
 * Kept to the tokens the board defines and to the type sizes .data-table and .text-link-sm already set.
 */

/* The status badge's icon, beside its word (M96). */
.bk-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.bk-status svg {
    width: 11px;
    height: 11px;
    flex: 0 0 auto;
    display: block;
}

/*
 * The row's two links, stacked. Two lines of 11.5px type come to less than the
 * 34px amenity glyph in the first cell, so the row keeps the height the board
 * gives it, and the column keeps the width the 28px action pair already sets.
 */
.bk-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
}

/* A charged booking says so, quietly, where the button was. */
.bk-charged {
    font-size: 11.5px;
    color: var(--slate-500);
}

.bk-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.bk-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.bk-field input {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.bk-field input::placeholder {
    color: var(--text-placeholder);
    opacity: 1;
}

/* A refusal under its field is amber (Hassam's three weights, 11 Oct): red is an open panic's, and a destructive act's outline. */
.bk-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.bk-ask {
    margin: 0 0 12px;
}

/* Charge fee (AME-1): an outlined button, so money to a ledger never reads as the View link stacked above it. */
.bk-charge {
    margin-top: 6px;
    padding: 4px 10px;
    border: 1.5px solid var(--navy-700);
    border-radius: 8px;
    background: var(--white);
    color: var(--navy-700);
    font: inherit;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
}

.bk-ask + .bk-ask {
    margin-top: -6px;
}

/*
 * ON A PHONE (seen at 390 px, 12 Oct): the amenity chips wrap, where the board's single row pushed the page to 511 px,
 * and the table keeps readable columns and scrolls inside its own card, as on the maintenance queue. Doubled classes
 * outrank the board's `body.wf-… .filter-row`.
 */
@media (max-width: 640px) {
    .filter-row.filter-row {
        flex-wrap: wrap;
    }

    :deep(.data-table) {
        min-width: 760px;
    }
}
</style>
