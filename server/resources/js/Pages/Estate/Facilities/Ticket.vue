<script setup>
import { computed, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'
import { DEFAULT_TARGETS, spellHours } from '../../../lib/serviceTargets.js'

/**
 * One ticket end to end — board screen community-admin-18.
 *
 * THE TIMELINE IS A SPINE, NOT A LOG. Six stages in fixed lifecycle order,
 * drawn whether or not each has happened: done where an entry exists, active on
 * the furthest one reached while the ticket is open, pending and greyed after
 * that. `Maintenance::timeline()` builds it, and it takes the FIRST entry per
 * stage — a ticket reassigned twice has three assignment entries and one
 * "Assigned to vendor" row, carrying the timestamp the resident was already
 * shown. The later ones are history, and history is not the spine.
 *
 * COMPLETED AND VERIFIED ARE TWO FACTS, WHICH IS WHY THERE ARE SIX STAGES AND
 * NOT FIVE. The estate saying a job is finished and the household agreeing are
 * different statements, and the gap between them is where a reopen comes from.
 * Nothing on this screen collapses them.
 *
 * THE REFUSALS BELONG TO THE SERVICE AND THIS SCREEN MAKES THEM FIRST.
 * `Maintenance::resolve()` will not close a ticket with an empty resolution —
 * "a ticket closed with no resolution tells the resident who reported it
 * nothing" — so the field is in front of the manager while they are still
 * looking at the job, rather than the press being sent to be bounced. Assigning
 * a closed ticket is refused outright, because work restarting on a finished job
 * is a REOPEN and the record has to say the fault came back rather than that it
 * never finished. So the primary action is Mark completed on an open ticket and
 * Reopen on a closed one; they are never both offered.
 *
 * NOT ONE FIGURE HERE IS THE REPORTER'S FINANCIAL POSITION. "Lot 47" in the meta
 * line is a place — the unit that has the fault — and the payload behind it
 * carries no balance, no bucket and no arrears age. The Property Manager holds
 * Facilities in full and holds nothing on Dues & ledger (D-010), and a
 * maintenance screen that named what a household owed would hand them exactly
 * what that rule locks them out of.
 *
 * THE THREAD UNDER THE SPINE (M68 §2.1, FAC-7). `history` is every line of the
 * ticket's record, oldest first: reported, assigned, each reassignment, every
 * message to the household and from it, completed, reopened with its reason.
 * Board 18 drew none of it, so a message sent to the resident was accepted and
 * appeared nowhere. The server words each line (what happened, the words, who
 * and when) and this page prints it. It also tells a ticket with nothing on its
 * spine apart from a ticket with nothing recorded at all.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    /** "2:07 PM, Fri 25 Sep" — the time at the estate now, for the visit window's hint. */
    estateNow: { type: String, default: '' },
    estate: { type: Object, required: true },
    ticket: { type: Object, required: true },
    /** Six stages in lifecycle order, each done / active / pending. */
    timeline: { type: Array, required: true },
    /** Null where nobody has been given the job — board 18 draws an assigned one. */
    vendor: { type: Object, default: null },
    history: { type: Array, required: true },
    /** Names only. A vendor's TRN and what it is owed are Accounting's. */
    vendors: { type: Array, required: true },
    priorities: { type: Object, required: true },
    /** This estate's service targets, hours by priority (M98), for what the priority modal says each one means. */
    targets: { type: Object, default: () => ({ ...DEFAULT_TARGETS }) },
    /**
     * What the job has cost (M98): `{ rows, total_minor, currency }`, the bills recorded against this work order, for a
     * reader holding Vendor costs; null for one who does not, and the section is not drawn (M96 level 3).
     */
    costs: { type: Object, default: null },
    /** What Record a bill needs (suppliers, expense accounts, today), for a reader who may record one; null otherwise. */
    costForm: { type: Object, default: null },
    canUpdate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
    /** The photos the resident attached from the Resident App, oldest first; each `url` is private to this page's readers. */
    photos: { type: Array, default: () => [] },
})

/*
 * Boards 17 to 20 share the batch-5 sheet, and the ten Estate Console sheets do
 * not share anything with each other — naming the wrong one renders this screen
 * with no ticket card, no timeline rail and no action stack at all.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

const page = usePage()

/**
 * All six, and the two empty ones are different tickets rather than two wordings.
 *
 * `rows` counts the stages that have actually HAPPENED, so a ticket whose spine
 * carries nothing is empty even though six rows would still be drawn. That is a
 * real record: the ticket-number migration writes a thin recovered ticket for
 * every number a bill already claims, and one of those has no history at all.
 *
 * `empty-filtered` is the same spine emptied by the VIEW rather than by the
 * ticket — the timeline reads only entries that advance a stage, so a recovered
 * ticket somebody has since re-prioritised has history and an empty spine. The
 * two need different sentences: one says nothing has happened, the other says
 * things have happened that this list does not hold.
 */
const state = useScreenState({
    rows: () => props.timeline.filter((stage) => stage.line !== null).length,
    filtered: () => props.history.length > 0,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL. Production gives
 * each estate its own hostname and no prefix; local serves every estate from one
 * host as /estate/{key}/facilities/maintenance/{number}, so a hard-coded root is
 * correct in exactly one of the two — and cutting the URL that served this page
 * cannot address an estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/facilities')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

/** The queue, which is the screen the reader pressed "Open" on. */
const queueHref = computed(() => `${root.value}/facilities/maintenance`)

/** This ticket, addressed by the NUMBER its route binds on. */
const ticketPath = computed(() => `${queueHref.value}/${props.ticket.number}`)

/**
 * The board's class name for a priority, which is not the stored value.
 *
 * The sheet defines `.high`, `.med` and `.low`; the enum is high, medium, low.
 */
const dotClass = computed(() => (props.ticket.priority === 'medium' ? 'med' : props.ticket.priority))

/*
 * EVERY STAGE SAYS WHO AND WHEN (M68 §2.1). Each stage that has happened prints the server's line, "Oct 9, 4:00 PM JA ·
 * Trevor Wright", and a stage's note (a resolution, a visit window) sits under it rather than in its place: the board
 * drew the note instead, which hid who completed the job and when. A pending stage has no entry, so it has neither.
 */

/* ------------------------------------------------------------------ */
/* the three acts, and why each one may be refused */
/* ------------------------------------------------------------------ */

/**
 * Which act's form is open: 'resolve', 'assign', 'reopen', 'message', 'priority', or none. In the console's modal since
 * M95 §1 (M97 pass): each opened as a panel under the card, which made the page longer and announced nothing.
 */
const panel = ref(null)

/** The priority chosen in its modal; it starts at the ticket's own. */
const priorityChoice = ref(null)

const resolveForm = useForm({ resolution: '' })

const assignForm = useForm({
    vendor_id: '',
    technician_name: '',
    technician_phone: '',
    /*
     * Blank rather than today. An ETA is a promise made to a household, and
     * defaulting it to now would put a window in front of a resident that nobody
     * agreed with the vendor.
     */
    eta_starts_at: '',
    eta_ends_at: '',
})

const reopenForm = useForm({ reason: '' })

/* "Message resident" (M53 §4.10): a line on this ticket's thread, which the resident reads in the app. */
const messageForm = useForm({ body: '' })

const submitMessage = () => {
    if (props.reasons.message) {
        return
    }

    messageForm.post(`${ticketPath.value}/message`, {
        preserveScroll: true,
        onSuccess: () => {
            messageForm.reset()
            closePanel()
        },
    })
}

/*
 * The optional fields travel as null rather than as "". A technician nobody
 * named and a window nobody agreed are ABSENT facts, and an empty string stored
 * against either would read back as a technician with no name.
 */
const blankToNull = (value) => (String(value).trim() === '' ? null : value)

assignForm.transform((data) => ({
    ...data,
    technician_name: blankToNull(data.technician_name),
    technician_phone: blankToNull(data.technician_phone),
    eta_starts_at: blankToNull(data.eta_starts_at),
    eta_ends_at: blankToNull(data.eta_ends_at),
}))

/*
 * THE VENDOR THAT ALREADY HAS THE JOB (M68 §2.2, FAC-8). Choosing it again is no reassignment: the panel changes that
 * vendor's visit, starting from the visit as it stands, and stays off until the technician, the phone or the time
 * changes; the server refuses the same press. Choosing another vendor starts from nothing, so the first vendor's
 * technician is never carried over to the second.
 */
const sameVendor = computed(() => props.vendor !== null && String(assignForm.vendor_id) === String(props.vendor.id))

watch(
    () => assignForm.vendor_id,
    (id, was) => {
        if (props.vendor === null) {
            return
        }

        const current = String(props.vendor.id)

        if (String(id) === current) {
            assignForm.technician_name = props.vendor.technician ?? ''
            assignForm.technician_phone = props.vendor.technician_phone ?? ''
            assignForm.eta_starts_at = props.vendor.eta_starts_local ?? ''
            assignForm.eta_ends_at = props.vendor.eta_ends_local ?? ''
        } else if (String(was) === current) {
            assignForm.technician_name = ''
            assignForm.technician_phone = ''
            assignForm.eta_starts_at = ''
            assignForm.eta_ends_at = ''
        }
    }
)

const visitUnchanged = computed(
    () =>
        sameVendor.value &&
        String(assignForm.technician_name).trim() === (props.vendor.technician ?? '') &&
        String(assignForm.technician_phone).trim() === (props.vendor.technician_phone ?? '') &&
        assignForm.eta_starts_at === (props.vendor.eta_starts_local ?? '') &&
        assignForm.eta_ends_at === (props.vendor.eta_ends_local ?? '')
)

/*
 * A WINDOW THAT ENDS BEFORE IT STARTS IS SAID BEFORE THE PRESS (FAC-11). Both times are the estate's, typed in the
 * same form, so no clock is needed to see it; the server refuses the same window in the same words.
 */
const visitEndsFirst = computed(
    () => assignForm.eta_starts_at !== '' && assignForm.eta_ends_at !== '' && assignForm.eta_ends_at <= assignForm.eta_starts_at
)

/** Why the panel's own Assign cannot be pressed, or null when it can. */
const assignSubmitBlockedBy = computed(() => {
    if (visitUnchanged.value) {
        return `${props.vendor.name} already has this job. Change the technician, the phone or the visit time, or choose another supplier.`
    }

    if (visitEndsFirst.value) {
        return 'The visit must end after it starts.'
    }

    // The modal has no form for the browser to check, so the one required choice is said here (M96 level 2).
    return String(assignForm.vendor_id) === '' ? 'Choose a supplier first.' : null
})

/* Why each modal's own button cannot be pressed yet, said under it (M96 level 2); null when it can. */
const resolveBlocked = computed(() => (resolveForm.resolution.trim() === '' ? 'Say what was done.' : null))
const reopenBlocked = computed(() => (reopenForm.reason.trim() === '' ? 'Say why it is being reopened.' : null))
// Nobody on the lot reads requests in the app: said, and nothing is sent to nobody (Law 1; batch 1 item 1).
const messageBlocked = computed(() => {
    if (props.ticket.message_to && props.ticket.message_to.names.length === 0) {
        return props.ticket.message_to.line
    }

    return messageForm.body.trim() === '' ? 'Write the message first.' : null
})
const priorityChoiceBlocked = computed(() =>
    priorityChoice.value === props.ticket.priority
        ? `This ticket is already ${String(props.priorities[props.ticket.priority] ?? props.ticket.priority).toLowerCase()} priority. Choose another.`
        : null
)

/**
 * A refusal of a press made with no dialog open (Acknowledge, Start work): a toast that stays until closed (M95 §2). A
 * refusal of an open dialog is said inside it instead (Hassam, 12 Oct): the priority dialog's band, `priorityRefusal`.
 */
const refused = (errors, fallback) => toast(errors.stage ?? Object.values(errors)[0] ?? fallback, { tone: 'error' })

const priorityRefusal = ref(null)

/** The viewer's own access, which is true of every control on the screen. */
const accessBlockedBy = computed(() => (props.canUpdate ? null : props.blockedReason))

/**
 * Why the primary action cannot be pressed, or null when it can.
 *
 * There is only ever one primary, and which one it is comes from the ticket
 * rather than from a preference: an open job can be finished and a finished one
 * can only come back. Neither is refused for any reason except access — the
 * service's own refusals are exactly what decided which of the two is drawn.
 */
const primaryBlockedBy = accessBlockedBy

const assignBlockedBy = computed(() => {
    if (accessBlockedBy.value !== null) {
        return accessBlockedBy.value
    }

    /*
     * The service's own refusal, said here first. Sending a vendor to a closed
     * job would leave a record that says the work never finished, when what
     * actually happened is that it came back.
     */
    if (props.ticket.is_closed) {
        return 'Reopen the ticket first.'
    }

    if (props.vendors.length === 0) {
        return 'The supplier register holds no active vendor yet; suppliers are added in Accounting.'
    }

    return null
})

/*
 * THE NEXT STEP FOR THIS TICKET, drawn as the one solid button (Hassam, 12 Oct): seen, then given to a supplier, then
 * started, then completed. Only the emphasis follows it; every step this ticket can take stays pressable as before, so
 * the office may still complete a job it never acknowledged. A closed ticket has no next step (Reopen stands alone).
 */
const nextStep = computed(() => {
    if (props.ticket.is_closed) {
        return null
    }

    if (props.ticket.can_acknowledge) {
        return 'acknowledge'
    }

    if (props.vendor === null && assignBlockedBy.value === null) {
        return 'assign'
    }

    return props.ticket.can_start ? 'start' : 'resolve'
})

const priorityBlockedBy = computed(() => {
    if (accessBlockedBy.value !== null) {
        return accessBlockedBy.value
    }

    if (props.ticket.is_closed) {
        return 'This ticket is closed, and a priority only sets a deadline for work still outstanding. There is none.'
    }

    return null
})

const openPanel = (which) => {
    panel.value = which
    priorityChoice.value = props.ticket.priority
    priorityRefusal.value = null

    resolveForm.clearErrors()
    assignForm.clearErrors()
    reopenForm.clearErrors()
    messageForm.clearErrors()
}

/* Closing a form throws away what was typed; its modal asks first when anything was (M95 §1). */
const closePanel = () => {
    panel.value = null
    priorityRefusal.value = null
    resolveForm.reset()
    reopenForm.reset()
    messageForm.reset()
    assignForm.reset()
}

const submitResolve = () => {
    /*
     * The route is gated on `estate.facilities.update` and the service refuses an empty resolution, so this is only
     * about not sending a press that is going to bounce.
     */
    if (primaryBlockedBy.value !== null || resolveBlocked.value !== null) {
        return
    }

    resolveForm.post(`${ticketPath.value}/resolve`, { preserveScroll: true })
}

const submitAssign = () => {
    if (assignBlockedBy.value !== null || assignSubmitBlockedBy.value !== null) {
        return
    }

    /*
     * A refusal keeps the panel and what was typed (FAC-11). Inertia's post keeps the page by default, as every other
     * save in the console does (M98 audit, 103 saves); this one alone kept it on a field error only. A visit that saves
     * closes the panel here.
     */
    assignForm.post(`${ticketPath.value}/assign`, {
        preserveScroll: true,
        onSuccess: () => closePanel(),
    })
}

/*
 * THE SPINE'S TWO MIDDLE STAGES, EACH ONE PRESS (FAC-10). Acknowledged and In progress had no act, so they stayed grey on
 * every job whatever had happened. The server says which of the two this ticket can take (`can_acknowledge`,
 * `can_start`); access is the only other reason either is off, and a refusal comes back as `errors.stage`.
 */
const stamp = (act) => {
    if (accessBlockedBy.value !== null) {
        return
    }

    router.post(`${ticketPath.value}/${act}`, {}, {
        preserveScroll: true,
        onError: (errors) => refused(errors, 'The ticket did not move.'),
    })
}

const submitReopen = () => {
    if (primaryBlockedBy.value !== null || reopenBlocked.value !== null) {
        return
    }

    reopenForm.post(`${ticketPath.value}/reopen`, {
        preserveScroll: true,
        onSuccess: () => closePanel(),
    })
}

/**
 * Move the priority.
 *
 * A plain post rather than a form: one field, three values, each its own button.
 * The deadline moves with it and the clock does not reset — escalating a
 * three-day-old medium ticket to high makes it overdue immediately, because high
 * means a day from the report and the report was three days ago.
 */
const setPriority = (key) => {
    if (priorityBlockedBy.value !== null || key === props.ticket.priority) {
        return
    }

    priorityRefusal.value = null
    router.post(`${ticketPath.value}/priority`, { priority: key }, {
        preserveScroll: true,
        onSuccess: () => closePanel(),
        // Said in the dialog's band, the dialog staying open (Hassam, 12 Oct): it was closed and toasted.
        onError: (errors) => {
            priorityRefusal.value = errors.priority ?? Object.values(errors)[0] ?? 'The priority was not changed.'
        },
    })
}

/* ------------------------------------------------------------------ */
/* what the job has cost (M98, cost capture) */
/* ------------------------------------------------------------------ */

/** "$6,200.00": a bill to the cent, as Bills & payments writes one (a figure to the dollar invites paying the rounding). */
const toTheCent = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: 'JMD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100)

const costing = ref(false)

const billForm = useForm({ vendor_id: '', description: '', amount: '', due_on: '', account: '' })

/* The bill names this job and comes back to it (PayablesController::recordBill). */
billForm.transform((data) => ({ ...data, ticket_number: props.ticket.number, from_ticket: 1 }))

/** Why Record a bill waits, said on the card (M96 level 2), or null while it can be pressed. */
const costButtonBlockedBy = computed(() =>
    props.costForm !== null && (props.costForm.suppliers ?? []).length === 0 ? 'No active supplier is on the register yet.' : null
)

/* Opened on this job's own defaults: its supplier where it has one, its label, the estate's today, Maintenance & Repairs. */
const openCost = () => {
    if (props.costForm === null || costButtonBlockedBy.value !== null) {
        return
    }

    const assigned = props.vendor !== null && props.costForm.suppliers.some((supplier) => supplier.id === props.vendor.id)

    billForm.vendor_id = assigned ? props.vendor.id : ''
    billForm.description = props.costForm.description ?? ''
    billForm.amount = ''
    billForm.due_on = props.costForm.today ?? ''
    billForm.account = props.costForm.account ?? ''
    billForm.clearErrors()
    costing.value = true
}

const closeCost = () => {
    costing.value = false
    billForm.clearErrors()
}

/** The amount as minor units, or null when it is not one: positive, at most two decimals (the server's own rule). */
const billMinor = computed(() => {
    const text = String(billForm.amount ?? '').replace(/[,\s$]/g, '')

    if (!/^\d+(\.\d{1,2})?$/.test(text)) {
        return null
    }

    const minor = Math.round(Number(text) * 100)

    return minor > 0 ? minor : null
})

const costDirty = computed(() => String(billForm.amount ?? '').trim() !== '')

/** Why Record bill cannot be pressed, in one line, or null when it can. */
const costBlockedBy = computed(() => {
    if (String(billForm.vendor_id) === '') {
        return 'Choose the supplier.'
    }

    if (billForm.description.trim() === '') {
        return 'Say what the bill is for.'
    }

    if (billMinor.value === null) {
        return 'Enter the amount, as 6200.00.'
    }

    if (billForm.due_on === '') {
        return 'Choose when it is due.'
    }

    return billForm.account === '' ? 'Choose the account it is charged to.' : null
})

const submitCost = () => {
    if (props.costForm === null || costBlockedBy.value !== null) {
        return
    }

    billForm.post(`${root.value}/accounting/bills`, {
        preserveScroll: true,
        onSuccess: () => closeCost(),
    })
}

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['ticket'] })
</script>

<template>
    <Head :title="ticket.heading" />

    <!-- A nested page: the way back to the queue, in words, top left (M95 §3). -->
    <EstateConsole
        :title="ticket.heading"
        :estate-name="estate.name"
        active="facilities"
        :back="{ href: queueHref, label: 'Back to maintenance' }"
    >
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This ticket is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This ticket could not be loaded"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!--
              A refusal of the priority, Acknowledge or Start work (no field of their own) is a toast; the other acts'
              come back into their modal, under the field that caused them (M95 §2). No banner on the page.
            -->
            <div class="ticket-head">
                <div class="ticket-summary">
                    <div class="ts-top">
                        <div>
                            <!-- "Gate lighting — Phase 2 visitor parking": the
                                 title and the location, with the queue's middot
                                 form flattened to a space. Composed on the
                                 server, so both screens spell one place the
                                 same way. -->
                            <div class="ts-title">{{ ticket.summary_title }}</div>

                            <!-- "Submitted by Andrea Fletcher · Lot 47 · Sep 2,
                                 6:40 PM · Category: Electrical" — composed out
                                 of only the parts this ticket has, so an office
                                 work order with no reporter closes up rather
                                 than leaving a stray middot in the line. -->
                            <div class="ts-meta">{{ ticket.meta }}</div>

                            <!-- "Entry with nobody home: allowed · Best time:
                                 Weekday mornings" — the two named fields the
                                 resident's own form asks for (M4 §3.5). Drawn
                                 only where the request said something: a work
                                 order raised in this office has no opinion on
                                 entry, and printing one against it would be an
                                 answer nobody gave. -->
                            <div v-if="ticket.access" class="ts-meta">{{ ticket.access }}</div>
                        </div>

                        <!--
                          The board draws the priority as a readout. It is the
                          one fact on this card that a manager changes, so it is
                          the control that changes it — pressing the thing you
                          want to alter, rather than hunting for a fourth button
                          in a stack the board draws with three.
                        -->
                        <!-- M96 level 3: where it can never be changed (no access, a closed ticket), a fact, not a control. -->
                        <button
                            v-if="priorityBlockedBy === null"
                            type="button"
                            class="priority-dot"
                            :class="dotClass"
                            title="Change the priority."
                            @click="openPanel('priority')"
                        >
                            <i></i>{{ ticket.priority_label }} priority
                        </button>
                        <span v-else class="priority-dot" :class="dotClass"><i></i>{{ ticket.priority_label }} priority</span>
                    </div>

                    <!-- The resident's own words, in their own words. Absent
                         rather than empty on a ticket nobody described: a pair
                         of quote marks around nothing is not a report. -->
                    <div v-if="ticket.description" class="ts-desc">"{{ ticket.description }}"</div>

                    <!-- What the resident photographed, from the Resident App (7 Oct). Each opens full size in a new
                         tab. A photo no browser draws (HEIC) or a video is offered as its file, never a broken picture. -->
                    <div v-if="photos.length" class="ts-photos">
                        <div class="ts-meta">
                            {{ photos.length === 1 ? '1 photo from the resident' : `${photos.length} photos from the resident` }}
                        </div>
                        <div class="ts-photo-row">
                            <a
                                v-for="photo in photos"
                                :key="photo.id"
                                :href="photo.url"
                                target="_blank"
                                rel="noopener"
                                class="ts-photo"
                                :title="`${photo.filename} · added ${photo.added}`"
                            >
                                <img v-if="photo.is_image" :src="photo.url" :alt="`Photo from the resident, added ${photo.added}`" loading="lazy" />
                                <span v-else class="ts-photo-file">{{ photo.is_video ? 'Video' : 'Photo' }} · open</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!--
                  Three actions, which is what the board draws, and the first of
                  them is whichever one this ticket can actually take. An open
                  job can be finished; a finished one can only come back, and
                  offering both would offer to close a closed ticket.
                -->
                <!-- M96 level 3: a role that can never act on the job sees the job, not a stack of greyed buttons. -->
                <!--
                  THE STEPS IN THE ORDER THE WORK GOES (Hassam, 12 Oct, live: "make them better sorting, then which one is
                  supposed to be clicked first so it is easy for the user to understand. Don't make any restriction"):
                  Acknowledge, Assign vendor, Start work, Mark completed, top to bottom. The next step for this ticket is
                  the one solid button, under a small "Next step"; a step already done is a quiet ticked line; every
                  other step is pressable exactly as before. Message resident is not a step: it sits apart, below.
                -->
                <div v-if="canUpdate" class="action-stack">
                    <button
                        v-if="ticket.is_closed"
                        type="button"
                        class="stack-btn primary"
                        title="Reopen the job if the fault came back."
                        @click="openPanel('reopen')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                            <path
                                d="M3 3.5V8h4.5"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>Reopen ticket</span>
                    </button>

                    <!-- 1. Acknowledge: the spine's second stage, one press (FAC-10), while the ticket can take it. -->
                    <span v-if="nextStep === 'acknowledge'" class="tk-next">Next step</span>
                    <button
                        v-if="ticket.can_acknowledge"
                        type="button"
                        class="stack-btn"
                        :class="nextStep === 'acknowledge' ? 'primary' : 'outline'"
                        title="Say the office has seen this job."
                        @click="stamp('acknowledge')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" stroke="currentColor" stroke-width="1.7" />
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>Acknowledge</span>
                    </button>
                    <p v-else-if="!ticket.is_closed" class="tk-done"><span aria-hidden="true">✓</span> Acknowledged</p>

                    <!-- 2. Assign vendor. One method for assigning and reassigning: the same act, the label follows the
                         ticket. Hidden on a closed ticket (M53 Law 1). Level 2 while the register holds no supplier:
                         greyed, the reason on the card under the stack. -->
                    <span v-if="nextStep === 'assign'" class="tk-next">Next step</span>
                    <button
                        v-if="!ticket.is_closed"
                        type="button"
                        class="stack-btn"
                        :class="nextStep === 'assign' ? 'primary' : 'outline'"
                        :disabled="assignBlockedBy !== null"
                        :title="assignBlockedBy ?? (vendor === null ? 'Give this job to a supplier.' : 'Give this job to a different supplier.')"
                        @click="openPanel('assign')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"
                                stroke="currentColor"
                                stroke-width="1.7"
                            />
                            <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>{{ vendor === null ? 'Assign vendor' : 'Reassign vendor' }}</span>
                    </button>

                    <!-- 3. Start work: the spine's fourth stage, one press, while the ticket can take it. -->
                    <span v-if="nextStep === 'start'" class="tk-next">Next step</span>
                    <button
                        v-if="ticket.can_start"
                        type="button"
                        class="stack-btn"
                        :class="nextStep === 'start' ? 'primary' : 'outline'"
                        title="Say the work on this job has started."
                        @click="stamp('start')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <polygon points="7 4 19 12 7 20 7 4" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                        </svg>
                        <span>Start work</span>
                    </button>
                    <p v-else-if="!ticket.is_closed" class="tk-done"><span aria-hidden="true">✓</span> Work started</p>

                    <!-- 4. Mark completed: pressable at any step of an open job, as it always was. -->
                    <span v-if="nextStep === 'resolve'" class="tk-next">Next step</span>
                    <button
                        v-if="!ticket.is_closed"
                        type="button"
                        class="stack-btn"
                        :class="nextStep === 'resolve' ? 'primary' : 'outline'"
                        title="Close the job and say what was done."
                        @click="openPanel('resolve')"
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
                        <span>Mark completed</span>
                    </button>

                    <!-- Not a step: a line on this ticket's own thread (M53 §4.10), apart from the steps, below them. The
                         resident reads it under the status timeline in the app, and gets a notification. M96 level 3: an
                         office work order has no resident to message, so it has no button. -->
                    <button
                        v-if="reasons.message === null"
                        type="button"
                        class="stack-btn outline tk-apart"
                        title="Send a message about this ticket."
                        @click="openPanel('message')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linejoin="round"
                            />
                            <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <span>Message resident</span>
                    </button>

                    <!-- Why Assign waits, on the card: a tablet has no hover (M96 level 2). -->
                    <p v-if="!ticket.is_closed && assignBlockedBy !== null" class="tk-why">{{ assignBlockedBy }}</p>
                </div>
            </div>

            <!--
              Authored, and drawn only for a ticket that has breached. The board
              draws a job inside its target and has no equivalent, and this is
              the one thing about an overdue ticket a reader cannot work out from
              what is drawn — the deadline is arithmetic on two columns and
              appears nowhere on the card.
            -->
            <!-- The fact, one line (M53 Law 2). How the target is counted lives in the help, not here. -->
            <p v-if="ticket.is_overdue" class="sla-note">Overdue. Was due {{ ticket.sla_due }}.</p>

            <!--
              The five acts, each in the console's modal (M95 §1), all closed on a fresh GET. Each refusal comes back
              into its own modal, under the field that caused it.
            -->
            <ConsoleDialog
                :open="panel === 'resolve'"
                :title="`Mark ${ticket.heading} completed?`"
                size="form"
                confirm-label="Mark completed"
                busy-label="Closing…"
                :busy="resolveForm.processing"
                :dirty="resolveForm.resolution.trim() !== ''"
                discard-title="Discard what was done?"
                :confirm-blocked-reason="resolveBlocked"
                @confirm="submitResolve"
                @cancel="closePanel"
            >
                <RequiredMark legend />
                <div class="act-field">
                    <label for="act-resolution">What was done (the resident who reported this reads it) <RequiredMark /></label>
                    <textarea
                        id="act-resolution"
                        v-model="resolveForm.resolution"
                        rows="3"
                        required
                        maxlength="2000"
                        placeholder="e.g. Both gate floodlights replaced and the photocell re-set."
                    ></textarea>
                    <div v-if="resolveForm.errors.resolution" class="act-error">{{ resolveForm.errors.resolution }}</div>
                </div>
            </ConsoleDialog>

            <ConsoleDialog
                :open="panel === 'assign'"
                :title="vendor === null ? 'Give this job to a supplier' : sameVendor ? `Change ${vendor.name}'s visit` : `Reassign from ${vendor.name}`"
                size="form"
                :confirm-label="sameVendor ? 'Save the visit' : 'Assign'"
                busy-label="Assigning…"
                :busy="assignForm.processing"
                :dirty="String(assignForm.vendor_id) !== ''"
                discard-title="Discard this assignment?"
                :confirm-blocked-reason="assignSubmitBlockedBy"
                @confirm="submitAssign"
                @cancel="closePanel"
            >
                <!-- Each refusal sits under the field it is about, the field amber (FAC-11). -->
                <RequiredMark legend />
                <div class="act-fields">
                    <div class="act-field act-field--wide" :class="{ 'has-error': assignForm.errors.vendor_id }">
                        <label for="act-vendor">Vendor <RequiredMark /></label>
                        <!-- The placeholder is a real, selectable option with an
                             empty value rather than a disabled one: `required`
                             on the select already refuses the empty string, and
                             a control that is permanently inert owes the reader
                             a reason it has not got — this one is a prompt. -->
                        <select id="act-vendor" v-model="assignForm.vendor_id" required>
                            <option value="">Choose a supplier…</option>
                            <option v-for="option in vendors" :key="option.id" :value="option.id">
                                {{ option.name }}
                            </option>
                        </select>
                        <div v-if="assignForm.errors.vendor_id" class="act-error">{{ assignForm.errors.vendor_id }}</div>
                    </div>

                    <div class="act-field" :class="{ 'has-error': assignForm.errors.technician_name }">
                        <label for="act-technician">Technician (optional)</label>
                        <input
                            id="act-technician"
                            v-model="assignForm.technician_name"
                            type="text"
                            maxlength="160"
                            placeholder="e.g. Devon Clarke"
                            :aria-invalid="assignForm.errors.technician_name ? 'true' : undefined"
                        />
                        <div v-if="assignForm.errors.technician_name" class="act-error">{{ assignForm.errors.technician_name }}</div>
                    </div>

                    <div class="act-field" :class="{ 'has-error': assignForm.errors.technician_phone }">
                        <label for="act-phone">Phone (optional)</label>
                        <input
                            id="act-phone"
                            v-model="assignForm.technician_phone"
                            type="tel"
                            maxlength="40"
                            :placeholder="page.props.phoneExample?.local ? `e.g. ${page.props.phoneExample.local}` : ''"
                            :aria-invalid="assignForm.errors.technician_phone ? 'true' : undefined"
                        />
                        <div v-if="assignForm.errors.technician_phone" class="act-error">{{ assignForm.errors.technician_phone }}</div>
                    </div>

                    <div class="act-field" :class="{ 'has-error': assignForm.errors.eta_starts_at }">
                        <label for="act-eta-from">Arrives from (estate time, optional)</label>
                        <input
                            id="act-eta-from"
                            v-model="assignForm.eta_starts_at"
                            type="datetime-local"
                            :aria-invalid="assignForm.errors.eta_starts_at ? 'true' : undefined"
                        />
                        <div v-if="assignForm.errors.eta_starts_at" class="act-error">{{ assignForm.errors.eta_starts_at }}</div>
                    </div>

                    <div class="act-field" :class="{ 'has-error': visitEndsFirst || assignForm.errors.eta_ends_at }">
                        <label for="act-eta-to">Arrives by (estate time, optional)</label>
                        <input
                            id="act-eta-to"
                            v-model="assignForm.eta_ends_at"
                            type="datetime-local"
                            :aria-invalid="visitEndsFirst || assignForm.errors.eta_ends_at ? 'true' : undefined"
                        />
                        <div v-if="visitEndsFirst" class="act-error">The visit must end after it starts.</div>
                        <div v-else-if="assignForm.errors.eta_ends_at" class="act-error">{{ assignForm.errors.eta_ends_at }}</div>
                    </div>
                </div>

                <!--
                  WHICH CLOCK (26 Sep). The window is read on the estate's clock, and somebody working from another
                  country typed their own time and told a household to expect a technician ten hours off.
                -->
                <p class="act-hint">
                    On the estate's clock, where it is {{ estateNow }} now. Leave both empty if no time is agreed; the
                    deadline does not move.
                </p>
            </ConsoleDialog>

            <ConsoleDialog
                :open="panel === 'reopen'"
                :title="`Reopen ${ticket.heading}?`"
                size="form"
                confirm-label="Reopen"
                busy-label="Reopening…"
                :busy="reopenForm.processing"
                :dirty="reopenForm.reason.trim() !== ''"
                discard-title="Discard this reason?"
                :confirm-blocked-reason="reopenBlocked"
                @confirm="submitReopen"
                @cancel="closePanel"
            >
                <p class="act-hint">
                    The job goes back in the queue. The original closure stays in the record, and the service clock still
                    runs from the first report.
                </p>
                <RequiredMark legend />
                <div class="act-field">
                    <label for="act-reason">Why it is being reopened <RequiredMark /></label>
                    <input
                        id="act-reason"
                        v-model="reopenForm.reason"
                        type="text"
                        required
                        maxlength="300"
                        placeholder="e.g. The same light failed again four days after the repair."
                    />
                    <div v-if="reopenForm.errors.reason" class="act-error">{{ reopenForm.errors.reason }}</div>
                </div>
            </ConsoleDialog>

            <ConsoleDialog
                :open="panel === 'message'"
                title="Message the resident"
                size="form"
                confirm-label="Send message"
                busy-label="Sending…"
                :busy="messageForm.processing"
                :dirty="messageForm.body.trim() !== ''"
                discard-title="Discard this message?"
                :confirm-blocked-reason="messageBlocked"
                @confirm="submitMessage"
                @cancel="closePanel"
            >
                <!-- Who reads it, named (Hassam, batch 1 item 1: "Nobody can tell who receives it"). -->
                <p v-if="ticket.message_to" class="act-to">{{ ticket.message_to.line }}</p>
                <RequiredMark legend />
                <div class="act-field">
                    <label for="act-message">Message <RequiredMark /></label>
                    <textarea
                        id="act-message"
                        v-model="messageForm.body"
                        rows="3"
                        required
                        maxlength="500"
                        placeholder="e.g. The technician will come tomorrow between 9 and 11."
                    ></textarea>
                    <div v-if="messageForm.errors.body" class="act-error">{{ messageForm.errors.body }}</div>
                </div>
            </ConsoleDialog>

            <ConsoleDialog
                :open="panel === 'priority'"
                :title="`Change the priority of ${ticket.heading}?`"
                confirm-label="Set priority"
                busy-label="Setting…"
                :confirm-blocked-reason="priorityChoiceBlocked"
                :error="priorityRefusal"
                @confirm="setPriority(priorityChoice)"
                @cancel="closePanel"
            >
                <p class="act-hint">
                    Priority sets the service target: {{ spellHours(targets.high) }} at high, {{ spellHours(targets.medium) }}
                    at medium, {{ spellHours(targets.low) }} at low. Changing it moves the deadline and does not restart
                    the clock.
                </p>
                <div class="act-choices" role="radiogroup" aria-label="Priority">
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

            <!--
              The spine. Six rows always, in lifecycle order and never in
              timestamp order — a stage nobody has reached still occupies its
              place, greyed, because the point of the list is that the resident
              can see what is left.
            -->
            <!-- One plain line each (M78 §2). -->
            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="No stage has been reached yet"
                body="What has been recorded is under History and messages below."
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing has been recorded against this ticket"
                body="Assigning it or marking it completed writes the first entry."
            />

            <div v-else class="tl2">
                <div v-for="stage in timeline" :key="stage.key" class="tl2-row">
                    <div class="tl2-dot" :class="stage.state">
                        <svg v-if="stage.state === 'done'" viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="20 6 9 17 4 12"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <svg v-else-if="stage.state === 'active'" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="5" fill="currentColor" />
                        </svg>
                    </div>

                    <div class="tl2-txt" :class="{ muted: stage.state === 'pending' }">
                        <div class="tt1">{{ stage.label }}</div>
                        <div v-if="stage.line" class="tt2">{{ stage.line }}</div>
                        <div v-if="stage.note" class="tt2 tt-note">{{ stage.note }}</div>
                    </div>
                </div>
            </div>

            <!--
              Who has the job. "Tech: Owen Grant · (876) 555 0110 · ETA today
              2:00–4:00 PM" is composed on the server out of the parts this
              assignment has, so a supplier with no named technician and no
              window still reads as a supplier rather than as a broken line.
            -->
            <div v-if="vendor !== null" class="vendor-panel">
                <div class="vendor-avatar">{{ vendor.initials }}</div>
                <div class="vendor-info">
                    <div class="vn">{{ vendor.name }}</div>
                    <div v-if="vendor.detail" class="vd">{{ vendor.detail }}</div>
                </div>
            </div>

            <!--
              The board draws an assigned ticket and has no panel for this. It is
              the same card rather than an authored one, because "nobody has this
              job" is a fact about the job in the place the reader already looks
              for who has it — an absent panel would read as a screen that had
              not finished loading.
            -->
            <div v-else class="vendor-panel">
                <div class="vendor-avatar">—</div>
                <div class="vendor-info">
                    <div class="vn">Unassigned</div>
                    <div class="vd">Nobody has this job yet. Assigning a supplier does not move the deadline.</div>
                </div>
            </div>

            <!--
              What the job has cost (M98): every bill recorded against this work order and what they come to, for a
              reader holding Vendor costs. Record a bill is for whoever may record one in Accounting; a bill is a draft
              that posts nothing until it is approved there.
            -->
            <section v-if="costs !== null" class="tk-thread tk-costs" aria-label="Costs">
                <div class="tk-costs-head">
                    <div class="tk-thread-head">Costs</div>
                    <button
                        v-if="costForm !== null"
                        type="button"
                        class="stack-btn outline tk-cost-btn"
                        :disabled="costButtonBlockedBy !== null"
                        :title="costButtonBlockedBy ?? 'Record a supplier’s bill for this job.'"
                        @click="openCost"
                    >
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <span>Record a bill</span>
                    </button>
                </div>
                <p v-if="costButtonBlockedBy !== null" class="tk-why">{{ costButtonBlockedBy }}</p>

                <p v-if="costs.rows.length === 0" class="tk-costs-empty">No bill has been recorded against this job yet.</p>
                <template v-else>
                    <ol class="tk-thread-list">
                        <li v-for="bill in costs.rows" :key="bill.id" class="tk-entry tk-cost">
                            <div class="tk-cost-main">
                                <div class="tk-what">{{ bill.supplier ?? 'A supplier' }}</div>
                                <div class="tk-meta">{{ bill.reference }} · {{ bill.description }} · due {{ bill.due_on }}</div>
                            </div>
                            <span class="tk-cost-status" :class="`is-${bill.status}`">{{ bill.status_label }}</span>
                            <span class="tk-cost-amount" :class="{ 'is-void': bill.status === 'void' }">{{ toTheCent(bill.amount_minor) }}</span>
                        </li>
                    </ol>
                    <div class="tk-cost-total">
                        <span>Total recorded, void bills left out</span>
                        <strong>{{ toTheCent(costs.total_minor) }}</strong>
                    </div>
                </template>
            </section>

            <!-- Record a bill for this job, in the console's modal (M95 §1); each refusal under its field, amber. -->
            <ConsoleDialog
                v-if="costForm !== null"
                :open="costing"
                :title="`Record a bill for ${ticket.heading}`"
                size="form"
                confirm-label="Record bill"
                busy-label="Recording…"
                :busy="billForm.processing"
                :dirty="costDirty"
                discard-title="Discard this bill?"
                :confirm-blocked-reason="costBlockedBy"
                @confirm="submitCost"
                @cancel="closeCost"
            >
                <p class="act-hint">A draft: it posts nothing until it is approved in Accounting.</p>
                <RequiredMark legend />
                <div class="act-fields">
                    <div class="act-field act-field--wide" :class="{ 'has-error': billForm.errors.vendor_id }">
                        <label for="cost-supplier">Supplier <RequiredMark /></label>
                        <select id="cost-supplier" v-model="billForm.vendor_id">
                            <option value="">Choose a supplier…</option>
                            <option v-for="supplier in costForm.suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                        </select>
                        <div v-if="billForm.errors.vendor_id" class="act-error">{{ billForm.errors.vendor_id }}</div>
                    </div>
                    <div class="act-field act-field--wide" :class="{ 'has-error': billForm.errors.description }">
                        <label for="cost-description">What it is for <RequiredMark /></label>
                        <input id="cost-description" v-model="billForm.description" type="text" maxlength="200" />
                        <div v-if="billForm.errors.description" class="act-error">{{ billForm.errors.description }}</div>
                    </div>
                    <div class="act-field" :class="{ 'has-error': billForm.errors.amount }">
                        <label for="cost-amount">Amount (JMD) <RequiredMark /></label>
                        <input id="cost-amount" v-model="billForm.amount" type="text" inputmode="decimal" required placeholder="e.g. 6200.00" />
                        <div v-if="billForm.errors.amount" class="act-error">{{ billForm.errors.amount }}</div>
                    </div>
                    <div class="act-field" :class="{ 'has-error': billForm.errors.due_on }">
                        <label for="cost-due">Due on <RequiredMark /></label>
                        <input id="cost-due" v-model="billForm.due_on" type="date" />
                        <div v-if="billForm.errors.due_on" class="act-error">{{ billForm.errors.due_on }}</div>
                    </div>
                    <div class="act-field act-field--wide" :class="{ 'has-error': billForm.errors.account || billForm.errors.ticket_number }">
                        <label for="cost-account">Charged to <RequiredMark /></label>
                        <select id="cost-account" v-model="billForm.account">
                            <option v-for="account in costForm.accounts" :key="account.code" :value="account.code">{{ account.label }}</option>
                        </select>
                        <div v-if="billForm.errors.account" class="act-error">{{ billForm.errors.account }}</div>
                        <div v-else-if="billForm.errors.ticket_number" class="act-error">{{ billForm.errors.ticket_number }}</div>
                    </div>
                </div>
            </ConsoleDialog>

            <!--
              The ticket's thread (M68 §2.1, FAC-7): every line of its history, oldest first, worded on the server. A
              message to the household appears here once it is sent, beside the household's own replies from the app.
            -->
            <section v-if="history.length" class="tk-thread" aria-label="History and messages">
                <div class="tk-thread-head">History and messages</div>
                <ol class="tk-thread-list">
                    <li v-for="entry in history" :key="entry.id" class="tk-entry" :class="entry.kind">
                        <div class="tk-what">{{ entry.what }}</div>
                        <p v-if="entry.words" class="tk-words">{{ entry.words }}</p>
                        <div class="tk-meta">{{ entry.when }}<template v-if="entry.who"> · {{ entry.who }}</template></div>
                    </li>
                </ol>
            </section>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its action stack and its priority readout as <div>s; here they
 * are real buttons, which arrive wearing the browser's own font, a border and
 * buttonface grey. The board's classes supply everything visible, and app.css
 * already takes the UA's underline and blue off every anchor on a board page.
 *
 * THE BORDER RESET NAMES THE VARIANT THAT HAS NO BORDER. A scoped element
 * selector outranks a single class, so `button.stack-btn { border: 0 }` would
 * beat .stack-btn.outline and rub out the 1.5px navy edge that is the entire
 * difference between the outline variant and the solid one — a real fidelity
 * defect small enough to survive a passing measurement. See D-045.
 */
button {
    font: inherit;
}

/*
 * `font: inherit` rather than `font-family: inherit`, because .priority-dot
 * declares no type at all: the board's <div> takes its size and colour from the
 * card around it, and a button element left alone would take 13.33px Arial and
 * buttontext from the user agent. Every class that DOES declare a size — the
 * span inside .stack-btn, .f-chip, .text-link-sm — out-specifies this rule.
 */
button.priority-dot {
    border: 0;
    background: none;
    padding: 0;
    color: inherit;
    cursor: pointer;
}

/* .stack-btn.primary declares a background and no border; .stack-btn.outline
 * declares both, and its border is the variant. .btn-primary-sm and .f-chip
 * each declare their own face and no border. */
button.stack-btn.primary,
button.btn-primary-sm,
button.f-chip {
    border: 0;
}

button.stack-btn {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/* The priority as a fact where it can never be changed (M96 level 3): the dot and the word, no control. */
span.priority-dot {
    cursor: default;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no SLA note and no form of any kind, because nothing has ever been pressed
 * on it; the forms are the console's modal's body now (M95 §1), and a refusal with no field is a toast. Kept to the
 * tokens the boards define and the type sizes .ts-meta's and .tt2's set.
 */

/* An action that cannot be used looks it (M53 Law 1, M96 level 2), and its reason is on the card, under the stack. */
button.stack-btn[disabled] {
    opacity: 0.55;
}

.tk-why {
    margin: 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-500);
}

/* The resident's photos: the navy-100 edge every card here already uses, in .ts-meta's type. */
.ts-photos {
    margin-top: 12px;
}

.ts-photo-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 6px;
}

.ts-photo {
    width: 96px;
    height: 96px;
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--white);
}

.ts-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.ts-photo-file {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-800);
    text-align: center;
    padding: 6px;
}

/* Amber rather than red: a breached target is a job running late, not a fault in the record, and the queue's Overdue
 * badge is amber too (Hassam's red rule, 11 Oct: solid red is an open panic's alone). */
.sla-note {
    font-size: 11.5px;
    color: var(--amber-700);
    line-height: 1.6;
    max-width: 760px;
    margin: 0 0 16px;
}

/*
 * Two fields a row: the supplier on its own, then the technician and the phone, then the visit's two times side by
 * side. Three a row pushed the phone past the modal's edge (seen on the dev server, 12 Oct). One a row on a phone.
 */
.act-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 11px;
    margin-bottom: 11px;
}

.act-field--wide {
    grid-column: 1 / -1;
}

@media (max-width: 640px) {
    .act-fields {
        grid-template-columns: minmax(0, 1fr);
    }
}

.act-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* The steps in order (Hassam, 12 Oct): a small "Next step" over the one solid button, a done step quiet and ticked. */
.tk-next {
    margin: 4px 0 -4px;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--navy-700);
}

.tk-done {
    margin: 0;
    padding: 6px 2px;
    font-size: 12.5px;
    color: var(--slate-500);
}

.tk-done span {
    color: var(--success-600);
    font-weight: 800;
}

/* Message resident is not a step: set apart, below the steps. */
.tk-apart {
    margin-top: 10px;
}

/* Who a message reaches, named above the box (batch 1 item 1): read before writing, never in a tooltip. */
.act-to {
    margin: 0 0 10px;
    padding: 8px 12px;
    border-radius: 8px;
    background: var(--navy-100);
    color: var(--navy-900);
    font-size: 13px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.act-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.act-field input,
.act-field select,
.act-field textarea {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.act-field input,
.act-field select {
    height: 34px;
}

.act-field textarea {
    padding: 9px 10px;
    line-height: 1.55;
    resize: vertical;
}

.act-field input::placeholder,
.act-field textarea::placeholder {
    color: var(--text-placeholder);
    opacity: 1;
}

/* A refusal is amber, never red (Hassam's red rule, 11 Oct: solid red is an open panic's alone). */
.act-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

/* A field the server refused, or a window that ends first: its edge amber, its words under it (FAC-11). */
.act-field.has-error input,
.act-field.has-error select {
    border-color: var(--amber-500);
}

.act-hint {
    margin: 0 0 12px;
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.5;
}

.act-field + .act-hint {
    margin-top: 12px;
}

.act-choices {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* A stage's note, under its who and when (M68 §2.1). */
.tt-note {
    color: var(--navy-800);
}

/* The thread (M68 §2.1): the white card with the navy-100 edge .tl2 and .vendor-panel already are, in .tt2's type. */
.tk-thread {
    margin-top: 14px;
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    background: var(--white);
    padding: 14px 16px;
}

.tk-thread-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin-bottom: 8px;
}

.tk-thread-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.tk-entry {
    padding: 9px 0;
    border-top: 1px solid var(--navy-100);
}

.tk-entry:first-child {
    border-top: 0;
    padding-top: 0;
}

.tk-what {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.5;
}

.tk-words {
    margin: 3px 0 0;
    font-size: 12.5px;
    color: var(--navy-800);
    line-height: 1.55;
    white-space: pre-line;
}

.tk-meta {
    margin-top: 3px;
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.5;
}

/*
 * WHAT THE JOB HAS COST (M98): the thread's card, a supplier and its bill a line, its state as a word and the amount to
 * the cent at the end, and the total under them. No red anywhere: Overdue is amber, Void struck through and grey.
 */
.tk-costs-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

.tk-costs-head .tk-thread-head {
    margin-bottom: 0;
}

button.stack-btn.outline.tk-cost-btn {
    height: 36px;
}

.tk-costs-empty {
    margin: 0;
    font-size: 12.5px;
    color: var(--slate-500);
    line-height: 1.5;
}

.tk-cost {
    display: flex;
    align-items: center;
    gap: 12px;
}

.tk-cost-main {
    flex: 1;
    min-width: 0;
}

.tk-cost-status {
    font-size: 10.5px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--navy-700);
    white-space: nowrap;
}

.tk-cost-status.is-paid {
    background: var(--green-100);
    color: var(--green-700);
}

.tk-cost-status.is-overdue {
    background: var(--amber-100);
    color: var(--amber-700);
}

.tk-cost-status.is-draft,
.tk-cost-status.is-void {
    color: var(--slate-500);
}

.tk-cost-amount {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.tk-cost-amount.is-void {
    color: var(--slate-500);
    text-decoration: line-through;
}

.tk-cost-total {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 4px;
    padding-top: 10px;
    border-top: 1px solid var(--navy-100);
    font-size: 12.5px;
    color: var(--slate-600);
}

.tk-cost-total strong {
    color: var(--navy-900);
    font-variant-numeric: tabular-nums;
}

/*
 * ON A PHONE (seen at 390 px, 12 Oct): the job's card and its actions one above the other, each the full width, where
 * the board's row squeezed the card to a column a word wide beside a 250 px stack. Doubled classes outrank the board's
 * `body.wf-… .ticket-head`.
 */
@media (max-width: 640px) {
    .ticket-head.ticket-head {
        flex-direction: column;
    }

    .action-stack.action-stack {
        width: auto;
    }

    .tk-cost {
        flex-wrap: wrap;
    }
}
</style>
