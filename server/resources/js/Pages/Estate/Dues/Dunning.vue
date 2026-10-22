<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useWireframe } from '../../../composables/useWireframe'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { submitDialog } from '../../../lib/dialogSubmit.js'

/**
 * Dunning & reminders — board screen community-admin-08.
 *
 * TWO PANES THAT MUST NEVER READ EACH OTHER, and that is the whole screen. On
 * the left is what the estate SENT; on the right is what it WILL send. The
 * template is editable and the log is not, because a dispute about an arrear is
 * settled from the words a resident actually received — so `dunning_notices`
 * carries its own finished subject and body, frozen by `Collections::send` at
 * the moment of sending, and nothing anywhere re-renders a logged notice from
 * the wording that happens to be in force today.
 *
 * THE LOG IS CHRONOLOGICAL, WHICH THE BOARD IS NOT. The board draws Lot 9's
 * "Yesterday" above Lot 21's "Today", and there is no column for a hand-set
 * position; a delivery log that is not in time order cannot be read at all. The
 * server orders by `sent_at` and the drawn sequence is a mock's arrangement.
 * Reproduced as the server sends it rather than shuffled to match the picture.
 *
 * NO SUB-NAVIGATION. Boards 5 and 6 each draw a tab strip above their content
 * and this one draws none — `.content` holds a single `.main-grid` and nothing
 * else (brief-08, and the board itself). The strip is 40px tall and would push
 * every row of the log and every line of the editor down by that much, so
 * adding one would not be a tab strip, it would be a different screen. The way
 * back to the arrears list is the sidebar item this screen sits under.
 *
 * NOTHING HERE POSTS A JOURNAL ENTRY. Chasing a debt does not restate it: the
 * receivable was recognised when the charge was raised, and this module is a
 * communications log over the AR sub-ledger. The Balance column is read from
 * the ledger when the screen is drawn, which is why it cannot drift from the
 * arrears board beside it.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** A page of the notices, newest batch first. Each row is a fact, not a render. */
    sends: { type: Array, required: true },
    /** The whole log as the shared table reads it (M32 F5); null where the log is withheld. */
    table: { type: Object, default: null },
    /** The active ladder, in stage order — board 8's three tabs. */
    templates: { type: Array, required: true },
    /** The closed catalogue of merge tokens a template may use. */
    mergeFields: { type: Array, required: true },
    /** The channel combinations this estate can send on, key → label. */
    channels: { type: Object, required: true },
    /** Proposed steps waiting on the committee. Nothing here sends. */
    drafts: { type: Array, required: true },
    canSend: { type: Boolean, required: true },
    canAdopt: { type: Boolean, required: true },
    adoptBlockedReason: { type: String, required: true },
    /**
     * Whether a step that goes out by SMS can text anybody (M10 §1). False while
     * no provider is connected, and then `sms_note` says so beside the choice.
     */
    sms_available: { type: Boolean, default: true },
    sms_note: { type: String, default: null },

    /*
     * M10 §5. The log is a list of the households the estate has chased, which
     * is who is behind. For a reader who may not have the estate's position the
     * server sends no sends and this sentence, and the left pane draws its
     * denied state — not "No reminder has been sent", which would be false.
     */
    positionWithheld: { type: Boolean, default: false },
    positionReason: { type: String, default: '' },

    /*
     * ONE HOUSEHOLD TO SEND TO, when the screen was opened from its ledger's
     * "Send reminder now" (bug hunt B111) — null otherwise. Each step comes
     * rendered against the unit by the same `Collections::render()` a send
     * freezes onto the log, so what is read here is what the household gets.
     * One household's own reminders and balance are the office's to see, as its
     * ledger and its plan already are; the estate's log beside it stays behind
     * the rule above.
     */
    sendTo: { type: Object, default: null },

    /*
     * REMINDER DELIVERY (M84 §3.5), sent only while its switch is on; absent otherwise. Sending ships off, so this is
     * a preview: each reminder a delivery run would send now, to whom and by which channel, with its words as the log
     * holds them, and how many older ones it would mark expired. Nothing on this screen sends.
     */
    delivery: { type: Object, default: null },
})

/** True when the chosen way out includes a text that nothing can send. */
const smsWontSend = (channel) => !props.sms_available && String(channel ?? '').split('+').includes('sms')

/*
 * The ten estate boards are ten stylesheets that disagree with each other, so
 * each is scoped to its own body class and a screen names the board it
 * reproduces. Without this line the page renders with no board CSS at all.
 */
useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

/*
 * Five of the six states. The log carries no filter — there is no query on this
 * screen that could have excluded a send — so `empty-filtered` cannot arise
 * from the data. It is still handled below rather than falling through to a
 * table: forcing it in local development draws the same first-use panel, since
 * offering to clear a filter that does not exist would invent a control to
 * explain a state nobody reached.
 */
const state = useScreenState({
    rows: () => props.table?.total ?? props.sends.length,
})

/* The log's columns (M32 F5). Balance is read from the ledger as the page is drawn, so it does not sort. */
const SEND_COLUMNS = [
    { key: 'unit', label: 'Unit', sortable: true },
    { key: 'step', label: 'Step', sortable: true },
    { key: 'channel', label: 'Channel', sortable: true },
    { key: 'sent', label: 'Sent', sortable: true },
    { key: 'balance', label: 'Balance' },
    { key: 'status', label: 'Status', sortable: true },
]

const retry = () => router.reload()

/**
 * The Balance column, to the cent (the money rule: every figure ties to the ledger). It rounded to the dollar, so a
 * balance of $12,400.50 read $12,401.
 *
 * Written from MINOR UNITS, integer cents, split into dollars and cents rather than divided into a fraction, so no
 * figure is ever rounded on the way to the screen; a credit reads with its minus first. The estate's ledger is
 * denominated in JMD and the board prints a bare $, which is how Jamaica writes its own currency domestically.
 *
 * This is NOT the figure the resident was quoted. That one is rendered into the notice body at send time and frozen
 * there. This is what the unit owes now, summed from posted journal lines, which is the number a treasurer reading the
 * log beside the arrears board needs the two screens to agree on.
 */
const amount = (minor) => {
    const whole = Math.abs(minor)

    return `${minor < 0 ? '-' : ''}$${Math.trunc(whole / 100).toLocaleString('en-US')}.${String(whole % 100).padStart(2, '0')}`
}

/**
 * What each delivery state means, under the cursor.
 *
 * DELIVERY IS FIVE FACTS, NOT TWO. queued, sent, delivered, failed and bounced
 * are different things to a treasurer chasing a household: queued means nothing
 * has left the building, delivered means a channel confirmed arrival, and the
 * gap between them is where an unanswered reminder actually sits. The pill's
 * text is the state's own word in every case — "Queued", "Sent", "Delivered",
 * and for a failure its own detail, "SMS failed" rather than "Failed", because
 * a multi-channel step can fail on one leg and land on the others.
 *
 * Keyed on the label because the raw `delivery_state` is not on this payload:
 * `Collections::dunningBoard` sends the rendered label and a two-valued tone,
 * and the board draws exactly two pill grounds — green for anything on its way,
 * red for anything that failed — so a per-state colour would be a style no
 * board has drawn. Anything absent from this map is a failure that named
 * itself, and `tone` says which side of the line it fell on.
 */
const DELIVERY_MEANING = {
    Queued: 'Written to the log and not yet handed to a channel. Nothing has reached the resident.',
    Sending: 'Being handed to its channels now.',
    Sent: 'Handed to the channel, which has not yet said what became of it.',
    Delivered: 'The channel confirmed it arrived.',
    Bounced: 'It reached nowhere and came back.',
    'Expired, never sent': 'Queued before delivery started, so it was never sent.',
}

const deliveryMeaning = (row) =>
    DELIVERY_MEANING[row.status] ??
    (row.tone === 'failed'
        ? 'A channel refused this notice. The step still went out on whichever legs it had left.'
        : 'This notice is on its way.')

/*
 * THE STATE IN THE ONE VOCABULARY (M96 §2, Hassam's three sets, 11 Oct): a reminder moves through steps, so its badge
 * takes the workflow word, closest first; the delivery's own words ("Queued", "SMS failed") stay in its tooltip, where a
 * treasurer chasing one household can still read which leg failed. The server's words are unchanged.
 */
const deliveryWord = (row) => {
    if (row.tone === 'failed') {
        return row.status === 'Expired, never sent' ? 'Expired' : 'Failed'
    }

    return { Queued: 'Pending', Sending: 'In progress', Sent: 'Submitted' }[row.status] ?? 'Completed'
}

/*
 * Which tab opens.
 *
 * The board draws "Reminder 1" selected rather than the first tab, and there is
 * a rule behind it rather than a designer's whim: "Day 20" fires BEFORE
 * anything is overdue — a pre-due courtesy with `days_overdue` of zero — so it
 * is not a rung of the escalation ladder. The editor opens on the first step
 * that chases an actual arrear, which is what a treasurer came here to reword.
 */
const firstLadderStep = props.templates.find((template) => template.days_overdue > 0)

const selectedId = ref((firstLadderStep ?? props.templates[0])?.id ?? null)

const selected = computed(() => props.templates.find((template) => template.id === selectedId.value) ?? null)

/** A merge token, anywhere in a body: {resident_first_name} and its kind. */
const TOKEN = /(\{[a-z0-9_]+\})/gi

/**
 * The template body, split into plain runs and merge tokens.
 *
 * Split rather than substituted, and rendered as text nodes rather than through
 * v-html: the raw token text is what the template stores and what the estate is
 * editing, and a body that arrived carrying markup would otherwise render it.
 *
 * ANY TOKEN-SHAPED RUN IS BOLDED, not only the five in the catalogue. The
 * catalogue is closed server-side, and a token outside it is left standing when
 * the notice renders — so a stray one is going to arrive on a resident's phone
 * as its own literal text. Amber in the editor is the earliest place that
 * mistake is legible, and the resident is the last person who should find it.
 */
const bodyParts = computed(() =>
    (selected.value?.body ?? '')
        .split(TOKEN)
        .filter((part) => part !== '')
        .map((part) => ({ text: part, token: /^\{[a-z0-9_]+\}$/i.test(part) }))
)

/*
 * THE WRITE CONTROLS ARE THERE ONLY FOR A ROLE THAT CAN EVER PRESS THEM (M96 level 3). `canSend` is
 * `estate.dues_ledger.create`, the gate on the send route and on what a household is told about its debt; a role without
 * it reads the log and the wording and has no New template, Reword, or Send. Put in force is the committee's
 * (`canAdopt`); a role without it sees each draft's state, Pending, instead of a button it could never press.
 */

/* ------------------------------------------------------------------ */
/* the editor (12 §1) */
/* ------------------------------------------------------------------ */

/*
 * A SAVE IS A DRAFT, ALWAYS. Nothing this panel does changes what the estate
 * sends: the collections run reads the ladder in force, and a step reworded at
 * four in the afternoon does not change what the four-o'clock run sends. What
 * puts a wording in force is the committee, against a resolution reference, on
 * the list below.
 */
const editing = ref(false)

const draftForm = useForm({
    template_id: null,
    label: '',
    stage: 1,
    channel: 'email',
    subject: '',
    body: '',
    days_overdue: 0,
})

/*
 * What the modal opened with, so closing it untouched asks nothing. One form serves New and Reword and is filled as it
 * opens, so Inertia's own isDirty would count the step's existing wording as a change.
 */
const draftOpenedWith = ref('')

const draftChanged = computed(() => editing.value && JSON.stringify(draftForm.data()) !== draftOpenedWith.value)

/** Reword the step on display. The wording in force keeps sending meanwhile. */
const openEdit = () => {
    if (!props.canSend || !selected.value) {
        return
    }

    draftForm.clearErrors()
    Object.assign(draftForm, {
        template_id: selected.value.id,
        label: selected.value.label,
        stage: selected.value.stage,
        channel: selected.value.channel,
        subject: selected.value.subject,
        body: selected.value.body,
        days_overdue: selected.value.days_overdue,
    })
    draftOpenedWith.value = JSON.stringify(draftForm.data())
    editing.value = true
}

/*
 * Propose a rung the estate does not have. It fires the day after the ladder's last rung (M94 §3.4: "stop defaulting
 * to 0 days"), where it defaulted to 0, a final demand before the first reminder. Whether a typed day keeps the ladder
 * in order is the server's rule to come (M94 §3.4, batch three); this is only the default.
 */
const openNew = () => {
    if (!props.canSend) {
        return
    }

    draftForm.clearErrors()
    Object.assign(draftForm, {
        template_id: null,
        label: '',
        stage: Math.max(0, ...props.templates.map((template) => template.stage)) + 1,
        channel: 'email',
        subject: '',
        body: '',
        days_overdue: Math.max(0, ...props.templates.map((template) => template.days_overdue)) + 1,
    })
    draftOpenedWith.value = JSON.stringify(draftForm.data())
    editing.value = true
}

/** Why Save as draft in the modal cannot be pressed yet, said under it (M96 level 2). */
const draftBlocked = computed(() => (draftForm.label.trim() === '' || draftForm.body.trim() === '' ? 'A step has a name and a body.' : null))

/*
 * The tokens in the box that this estate cannot fill, found here so the mistake
 * is legible before the save rather than on a resident's phone. The server
 * refuses them too — this is the earliest warning, not the guard.
 */
const strayTokens = computed(() => {
    const found = `${draftForm.body} ${draftForm.subject}`.match(/\{[a-z0-9_]+\}/gi) ?? []

    return [...new Set(found.filter((token) => !props.mergeFields.includes(token.toLowerCase())))]
})

// From the dialog through the one save (lib/dialogSubmit.js): a refusal keeps it open with what was typed.
const submitDraft = () => {
    submitDialog(draftForm, 'post', `${duesPath.value}/dunning/drafts`, { close: () => (editing.value = false) })
}

/* Adoption — the committee's act, one resolution reference per draft. */
const adoptingId = ref(null)

const adoptForm = useForm({ resolution_reference: '' })

const openAdopt = (draft) => {
    if (!props.canAdopt) {
        return
    }

    adoptForm.clearErrors()
    adoptForm.resolution_reference = draft.resolution_reference ?? ''
    adoptingId.value = draft.id
}

/** The draft being put in force, so the dialog can name it. */
const adoptingDraft = computed(() => props.drafts.find((draft) => draft.id === adoptingId.value) ?? null)

/** A reference typed and not yet used: closing the dialog then asks first. */
const adoptChanged = computed(() => adoptingDraft.value !== null && adoptForm.resolution_reference !== (adoptingDraft.value.resolution_reference ?? ''))

const submitAdopt = (draft) => {
    if (adoptForm.resolution_reference.trim() === '') {
        return
    }

    submitDialog(adoptForm, 'post', `${duesPath.value}/dunning/drafts/${draft.id}/adopt`, {
        close: () => (adoptingId.value = null),
        onSuccess: () => adoptForm.reset(),
    })
}

/** Where this console is rooted, read off the page's own URL. */
const duesPath = computed(() => `${page.url.slice(0, page.url.indexOf('/finance'))}/finance`)

/* ------------------------------------------------------------------ */
/* a deliberate send (bug hunt B111) */
/* ------------------------------------------------------------------ */

/*
 * "SEND REMINDER NOW" SENDS ONE. The ledger's control used to land here on a log
 * and an editor with no unit and no send, so the reminder it promised could not
 * be sent from the console at all. The step defaults to the one the morning
 * ladder has reached for this household; the office may choose another, reads it
 * exactly as it will go out, and sends it onto the log with their own name.
 */
const sendForm = useForm({
    unit: props.sendTo?.unit.id ?? null,
    template: props.sendTo?.suggested ?? props.sendTo?.steps[0]?.id ?? null,
})

const chosenStep = computed(() => props.sendTo?.steps.find((step) => step.id === sendForm.template) ?? null)

const submitSend = () => {
    if (!props.canSend || chosenStep.value === null) {
        return
    }

    sendForm.post(`${duesPath.value}/dunning`, { preserveScroll: true })
}

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['dues'] })
</script>

<template>
    <Head title="Reminders" />

    <!--
      No search box and no profile chip: this board's topbar carries its title
      and one action, and rendering either would move the action out of place.
      "Reminders", never "Dunning", a word a committee does not know (M94 §1.5). Opened from a household's ledger
      ("Send reminder now"), the page says the way back to that ledger (M95 §3).
    -->
    <EstateConsole
        title="Reminders"
        :estate-name="estate.name"
        active="dues_ledger"
        :back="sendTo ? { href: `${duesPath}/units/${sendTo.unit.id}`, label: `Back to ${sendTo.unit.reference}'s ledger` } : null"
    >
        <template #actions>
            <!-- M96 level 3: a role that can never word a reminder has no New template. -->
            <button
                v-if="canSend"
                type="button"
                class="btn-primary-sm"
                title="Write a new reminder step, as a draft for the committee."
                @click="openNew"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>New template</span>
            </button>
        </template>


        <!--
          AUTHORED (bug hunt B111). The board draws the estate's log and its
          wording; it has no send, because a board has never had a household in
          front of it. Opened from a ledger's "Send reminder now", the screen
          carries that household, and this is where its reminder is sent from.
        -->
        <form
            v-if="sendTo && !state.isLoading.value && !state.isError.value && !state.isDenied.value"
            class="dun-panel dun-send"
            @submit.prevent="submitSend"
        >
            <div class="dun-head">Send a reminder to {{ sendTo.unit.title }}</div>

            <p class="dun-send-note">
                It is logged against {{ sendTo.unit.reference }} word for word, exactly as it reads below, the moment it
                is sent: the log is what a dispute is settled from.
            </p>

            <p v-if="sendTo.flag" class="dun-send-flag" role="note">
                {{ sendTo.flag }}. Automated reminders are suppressed for this household; a reminder sent from here is a
                deliberate decision, made in your name.
            </p>

            <p v-if="sendTo.steps.length === 0" class="dun-error">
                The estate's ladder has no step in force, so there is nothing to send. A step is worded below and put in
                force by the committee.
            </p>

            <template v-else>
                <div class="dun-fields">
                    <div class="dun-field dun-field--wide">
                        <label for="ds-step">Which step</label>
                        <select id="ds-step" v-model="sendForm.template" required>
                            <option v-for="step in sendTo.steps" :key="step.id" :value="step.id">
                                {{ step.label }} · {{ step.channel_label }}{{ step.id === sendTo.suggested ? ', the step this household has reached' : '' }}
                            </option>
                        </select>
                        <p v-if="smsWontSend(chosenStep?.channel)" class="dun-sms-note" role="note">{{ sms_note }}</p>
                    </div>
                </div>

                <div v-if="chosenStep" class="dun-send-preview">
                    <div class="dun-send-subject">{{ chosenStep.subject }}</div>
                    <div class="dun-send-body">{{ chosenStep.body }}</div>
                </div>
            </template>

            <div v-if="sendTo.notices.length" class="dun-send-history">
                <div class="dun-draft-sub">Already on {{ sendTo.unit.reference }}'s log</div>
                <div v-for="notice in sendTo.notices" :key="notice.id" class="dun-draft-sub" :title="`${notice.status}. ${deliveryMeaning(notice)}`">
                    {{ notice.step }} · {{ notice.channel }} · {{ notice.sent }} · {{ deliveryWord(notice) }}
                </div>
            </div>

            <div v-if="sendForm.errors.template" class="dun-error">{{ sendForm.errors.template }}</div>
            <div v-if="sendForm.errors.unit" class="dun-error">{{ sendForm.errors.unit }}</div>

            <!-- M96 level 3: Send is there only for a role that may send; the way back is the shell's, top left. -->
            <div v-if="canSend" class="dun-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="sendForm.processing || chosenStep === null"
                    :title="chosenStep === null ? 'The ladder has no step in force, so there is nothing to send.' : 'Send this step to the household and log it, word for word, against this unit.'"
                >
                    <span>{{ sendForm.processing ? 'Sending…' : 'Send this reminder' }}</span>
                </button>
            </div>
        </form>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The reminders could not be loaded"
            body="No reminder has been sent or changed. Reading them again is safe."
            action-label="Try again"
            @action="retry"
        />

        <!--
          Above the panes on purpose. A dunning log names the households the
          estate is chasing and what each of them was told, so a refusal must
          not be drawn under a table that has already answered the question.
        -->
        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Reminders are not part of your role's access"
            body="It names every household the estate is chasing and what each was told, so only roles that handle dues can open it. Ask your estate administrator if you need it."
        />

        <div v-else class="main-grid" style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 18px">
            <!--
              REMINDER DELIVERY, across both panes and only while its switch is on. A preview: no control here sends,
              and the state line says in words whether delivery is on.
            -->
            <section v-if="delivery" class="dun-delivery" aria-label="Reminder delivery" style="grid-column: 1 / -1">
                <div class="panel-head" style="margin-bottom: 12px">
                    <h3 style="font-family: 'Poppins', sans-serif; font-size: 14.5px; color: var(--navy-900); font-weight: 600">
                        Reminder delivery
                    </h3>
                </div>
                <p class="dun-delivery-state">
                    {{ delivery.enabled ? 'Delivery is on. Each morning\'s reminders go out by email after the run.' : 'Delivery is off. Nothing is sent. This is what would go out if it were on.' }}
                </p>
                <p v-if="delivery.would_expire > 0" class="dun-delivery-state">
                    {{ delivery.would_expire }} older {{ delivery.would_expire === 1 ? 'reminder' : 'reminders' }} would be marked expired and never sent.
                </p>
                <EmptyState
                    v-if="delivery.rows.length === 0"
                    variant="first-use"
                    title="Nothing would go out now"
                    body="The morning run queues the reminders that are due. Those queued in the last day are shown here before anything is sent."
                />
                <div v-else class="dun-delivery-list">
                    <article v-for="row in delivery.rows" :key="row.id" class="dun-delivery-row">
                        <div class="dun-delivery-head">
                            <strong>{{ row.unit }}</strong> · {{ row.to }} · {{ row.step }} · queued {{ row.queued }}
                        </div>
                        <ul class="dun-delivery-legs">
                            <li v-for="leg in row.legs" :key="leg.channel">{{ leg.channel }}: {{ leg.to ?? leg.note }}</li>
                        </ul>
                        <div class="dun-delivery-subject">{{ row.subject }}</div>
                        <p class="dun-delivery-body">{{ row.body }}</p>
                    </article>
                    <p v-if="delivery.total > delivery.rows.length" class="dun-delivery-state">
                        And {{ delivery.total - delivery.rows.length }} more.
                    </p>
                </div>
            </section>

            <div>
                <div class="panel-head" style="margin-bottom: 12px">
                    <h3 style="font-family: 'Poppins', sans-serif; font-size: 14.5px; color: var(--navy-900); font-weight: 600">
                        Recent reminders
                    </h3>
                </div>

                <!--
                  M10 §5, in this pane only. Which households the estate has
                  chased is who is behind; the wording across the grid names
                  nobody and stays the office's to draft.
                -->
                <EmptyState
                    v-if="positionWithheld"
                    variant="denied"
                    title="Which households were sent reminders is not part of your role's access"
                    :body="positionReason"
                />

                <!--
                  The two empties are one screen here. `empty-filtered` is
                  reachable only by forcing it in local development, because the
                  log has no filter to have excluded anything, and a panel
                  offering to clear one would name a control this board does not
                  draw.
                -->
                <EmptyState
                    v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
                    variant="first-use"
                    title="No reminder has been sent from this estate"
                    body="Each reminder the estate sends is logged here, with its step, its channels and the exact words the household received."
                />

                <!--
                  THE LOG IS NEVER RENDERED FROM THE TEMPLATE, and this is where
                  it would be tempting. Each row already names its step, the
                  editor across the grid already holds that step's wording, and
                  joining the two would put the message text in this table with
                  no extra payload at all. It would also be wrong the first time
                  anybody edited a template: `dunning_notices` stores the
                  finished subject and body AS SENT because the log exists to
                  settle a dispute about what a resident was actually told, and
                  a log that followed the wording would show them a message they
                  never received. Nothing on this screen may reach across the
                  grid for a word of it.
                -->
                <!--
                  The lot without its phase prefix, as the board prints it. The
                  step is the one the notice WAS, copied onto the row at send
                  time — not the label of the template it came from: "Reminder 2"
                  and "Reminder 5" went out under wording grouped as "Reminder 1"
                  and "Reminder 4+", and asked in a year which step ran, the row
                  answers alone.
                -->
                <DataTable v-else :columns="SEND_COLUMNS" :rows="sends" :table="table" noun="reminders">
                    <template #cell-balance="{ row }">{{ amount(row.balance_minor) }}</template>
                    <template #cell-status="{ row }">
                        <!-- The vocabulary's word; the delivery's own words and what they mean, on hover. -->
                        <!-- An expired reminder was never sent: amber, apart from a failed one (Hassam's three weights). -->
                        <div class="dun-status" :class="deliveryWord(row) === 'Expired' ? 'expired' : row.tone" :title="`${row.status}. ${deliveryMeaning(row)}`">
                            {{ deliveryWord(row) }}
                        </div>
                    </template>
                </DataTable>
            </div>

            <div>
                <div class="panel-head" style="margin-bottom: 12px">
                    <h3 style="font-family: 'Poppins', sans-serif; font-size: 14.5px; color: var(--navy-900); font-weight: 600">
                        Message templates
                    </h3>
                </div>

                <template v-if="selected">
                    <!--
                      Local state, not a link, and the one place this screen
                      differs from board 5's filter chips deliberately. Those
                      chips filter the query, because the ageing cards above them
                      would otherwise report the estate while the list reported
                      one phase. A tab here chooses which wording is on display
                      and changes nothing the server computed — least of all the
                      log, which is every step the estate sent whatever tab is
                      open.
                    -->
                    <div class="tmpl-tabs">
                        <button
                            v-for="template in templates"
                            :key="template.id"
                            type="button"
                            class="tmpl-tab"
                            :class="{ active: template.id === selectedId }"
                            @click="selectedId = template.id"
                        >
                            {{ template.label }}
                        </button>
                    </div>

                    <div class="tmpl-editor">
                        <div class="tmpl-body">
                            <template v-for="(part, i) in bodyParts" :key="i">
                                <b v-if="part.token">{{ part.text }}</b>
                                <template v-else>{{ part.text }}</template>
                            </template>
                        </div>

                        <!--
                          A catalogue, not a control strip. Each chip names a
                          token a template MAY use, and every one of them has to
                          be resolvable against a unit at the instant of sending
                          — a token nobody can resolve is a sentence with a hole
                          in it arriving on a resident's phone. They are not
                          buttons because there is no editable field to insert
                          one into: the body above is the wording as stored, and
                          the editor that would take a click lands with this
                          board's write path.
                        -->
                        <div class="merge-row">
                            <div v-for="field in mergeFields" :key="field" class="merge-chip">{{ field }}</div>
                        </div>

                        <!--
                          It opens the rewording; it saved nothing, so "Save template" said what it did not do (M97 pass).
                          M96 level 3: there only for a role that may word a reminder.
                        -->
                        <button
                            v-if="canSend"
                            type="button"
                            class="stack-btn primary"
                            style="width: 100%; justify-content: center"
                            title="Reword this step, as a draft for the committee."
                            @click="openEdit"
                        >
                            <span>Reword this step</span>
                        </button>
                    </div>
                </template>

                <!--
                  An estate with no active step. The ladder is data, not code (a step carries its own stage, channels and
                  wording), so an estate mid-onboarding can reach this screen with nothing to word, and a bare heading
                  over white space would read as a screen that failed rather than one with nothing in it yet.
                  Its v-else hangs on the ladder above. It hung on the drafts list, so the panel told an estate with a
                  working ladder that it had no step whenever no draft was waiting (M97 pass).
                -->
                <EmptyState
                    v-else
                    variant="first-use"
                    title="No reminder step is set up"
                    body="There is no active reminder step yet, so nothing can be reworded or sent. Write the first one with New template."
                />

                <!--
                  AUTHORED. The board draws a rate card nobody is editing, so
                  it has neither of the two panels below.

                  The editor. Every save is a draft: the collections run reads
                  the ladder in force and never a draft, so a final demand
                  reworded at four in the afternoon does not change what the
                  four-o'clock run sends.
                -->
                <!--
                  The step's wording, in the console's modal (M95 §1): it opened as a panel under the templates. One line
                  says what a save does, where three explained the system (M94 §1.5); each refusal under its own field.
                -->
                <ConsoleDialog
                    :open="editing"
                    :title="draftForm.template_id === null ? 'New reminder step' : `Reword ${draftForm.label}`"
                    size="form"
                    confirm-label="Save as draft"
                    busy-label="Saving…"
                    :busy="draftForm.processing"
                    :dirty="draftChanged"
                    discard-title="Discard this wording?"
                    :confirm-blocked-reason="draftBlocked"
                    @confirm="submitDraft"
                    @cancel="editing = false"
                >
                    <p class="dun-lead">It saves as a draft: nothing the estate sends changes until the committee puts it in force.</p>

                    <div class="dun-fields">
                        <div class="dun-field dun-field--wide">
                            <label for="dt-label">What this step is called</label>
                            <input id="dt-label" v-model="draftForm.label" type="text" required maxlength="80" placeholder="Final demand" />
                            <div v-if="draftForm.errors.label" class="dun-error">{{ draftForm.errors.label }}</div>
                        </div>
                        <div class="dun-field">
                            <label for="dt-stage">Stage</label>
                            <input id="dt-stage" v-model.number="draftForm.stage" type="number" min="0" max="20" required />
                            <div v-if="draftForm.errors.stage" class="dun-error">{{ draftForm.errors.stage }}</div>
                        </div>
                        <div class="dun-field">
                            <label for="dt-days">Sent at, days past due</label>
                            <input id="dt-days" v-model.number="draftForm.days_overdue" type="number" min="0" max="365" required />
                            <div v-if="draftForm.errors.days_overdue" class="dun-error">{{ draftForm.errors.days_overdue }}</div>
                        </div>
                        <div class="dun-field dun-field--wide">
                            <label for="dt-channel">How it goes out</label>
                            <select id="dt-channel" v-model="draftForm.channel" required>
                                <option v-for="(label, key) in channels" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <!--
                              AUTHORED (M10 §1). Said beside the choice, on the
                              screen, because a committee picking "SMS" would
                              otherwise believe a household gets a text.
                            -->
                            <p v-if="smsWontSend(draftForm.channel)" class="dun-sms-note" role="note">{{ sms_note }}</p>
                            <div v-if="draftForm.errors.channel" class="dun-error">{{ draftForm.errors.channel }}</div>
                        </div>
                        <div class="dun-field dun-field--wide">
                            <label for="dt-subject">Subject line</label>
                            <input id="dt-subject" v-model="draftForm.subject" type="text" required maxlength="190" />
                            <div v-if="draftForm.errors.subject" class="dun-error">{{ draftForm.errors.subject }}</div>
                        </div>
                        <div class="dun-field dun-field--full">
                            <label for="dt-body">The reminder</label>
                            <textarea id="dt-body" v-model="draftForm.body" rows="7" required maxlength="4000"></textarea>
                            <div v-if="draftForm.errors.body" class="dun-error">{{ draftForm.errors.body }}</div>
                        </div>
                    </div>

                    <div class="merge-row">
                        <div v-for="field in mergeFields" :key="field" class="merge-chip">{{ field }}</div>
                    </div>

                    <p v-if="strayTokens.length" class="dun-error">
                        {{ strayTokens.join(', ') }} cannot be filled in against a household, so it would arrive on a
                        resident's phone as its own literal text.
                    </p>
                </ConsoleDialog>

                <!--
                  Drafts waiting on the committee. Adopted ones are not here —
                  what they say is readable from the ladder itself, and leaving
                  a decided thing on a "waiting" list would misreport it.
                -->
                <div v-if="drafts.length" class="dun-drafts">
                    <div class="dun-head">Waiting on the committee. None of these is being sent.</div>

                    <div v-for="draft in drafts" :key="draft.id" class="dun-draft">
                        <div class="dun-draft-top">
                            <div>
                                <div class="dun-draft-name">{{ draft.target }}</div>
                                <div class="dun-draft-sub">
                                    Stage {{ draft.stage }} · {{ draft.channel_label }} · day {{ draft.days_overdue }} ·
                                    drafted by {{ draft.drafted_by }}{{ draft.drafted_at ? ` on ${draft.drafted_at}` : '' }}
                                </div>
                            </div>
                            <!-- M96 level 3: Put in force is the committee's; any other role sees the draft's state. -->
                            <button
                                v-if="canAdopt"
                                type="button"
                                class="text-link-sm"
                                title="Put this wording in force against the committee resolution that agreed it."
                                @click="openAdopt(draft)"
                            >
                                Put in force
                            </button>
                            <span v-else class="dun-pending" title="Waiting for the committee to put it in force.">Pending</span>
                        </div>
                    </div>
                </div>

                <!-- Putting a draft in force, in the console's dialog (M95 §1): it opened as a box under the draft. -->
                <ConsoleDialog
                    :open="adoptingDraft !== null"
                    :title="adoptingDraft ? `Put ${adoptingDraft.target} in force?` : ''"
                    size="form"
                    confirm-label="Put in force"
                    busy-label="Putting in force…"
                    cancel-label="Not now"
                    :busy="adoptForm.processing"
                    :dirty="adoptChanged"
                    discard-title="Discard the reference you typed?"
                    :confirm-blocked-reason="adoptForm.resolution_reference.trim() === '' ? 'Name the resolution.' : null"
                    @confirm="adoptingDraft && submitAdopt(adoptingDraft)"
                    @cancel="adoptingId = null"
                >
                    <p class="dun-lead">Reminders already sent keep the wording they went out with.</p>
                    <div class="dun-field">
                        <label for="dr-ref">The committee resolution that agreed it: the minute or resolution number from the estate's own book</label>
                        <input id="dr-ref" v-model="adoptForm.resolution_reference" type="text" required maxlength="80" placeholder="Res. 2026-14" />
                        <div v-if="adoptForm.errors.resolution_reference" class="dun-error">{{ adoptForm.errors.resolution_reference }}</div>
                    </div>
                </ConsoleDialog>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its topbar action, its template tabs
 * and its save control as <div>s; here they are real buttons, and a <button>
 * arrives wearing the system UI font and a border of the browser's own.
 * Everything visible still comes from the board's .btn-primary-sm, .tmpl-tab
 * and .stack-btn.
 *
 * THE BORDER RESETS NAME THE VARIANTS THAT HAVE NO BORDER, and nothing else.
 * A blanket `button { border: 0 }` would out-specify any board rule that draws
 * one — .btn-outline-sm carries 1.5px solid var(--navy-200) and would lose it
 * silently, which is a real fidelity defect small enough to survive a passing
 * measurement (see the comment in Gemini/Reports/ReportShell.vue). This screen
 * draws none of those, and the resets below are still written variant by
 * variant so that adding one later cannot quietly strip it.
 *
 * BACKGROUND IS RESET NOWHERE. .tmpl-tab declares navy-100 and .tmpl-tab.active
 * navy-600; a scoped `button.tmpl-tab { background: none }` ties with the first
 * on specificity, wins on order, and rubs the ground off every inactive tab.
 * .btn-primary-sm and .stack-btn.primary declare their own faces too, so the
 * browser's buttonface never shows through anywhere here.
 *
 * Not one declaration below introduces a colour, a size or a spacing.
 */
button {
    font: inherit;
}

button.btn-primary-sm,
button.tmpl-tab,
button.stack-btn.primary {
    border: 0;
}

button[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    cursor: pointer;
}

/*
 * The wording keeps its own paragraphs, as the household receives them: the board's body ran "Dear …," and "Thank
 * you," into one line, so the preview did not read like the reminder it previews (seen in the M98 screenshot).
 */
.tmpl-body {
    white-space: pre-line;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a ladder nobody is editing and no
 * draft waiting on anybody, so it has none of this. Kept to the tokens the
 * boards define and to the shapes they already use.
 */
.dun-panel,
.dun-drafts {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-top: 14px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.dun-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
}

.dun-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.dun-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.dun-field--wide,
.dun-field--full {
    grid-column: span 2;
}

/* In the modals: the one line above the fields, and on a phone the fields one under another. */
.dun-lead {
    margin: 0 0 10px;
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.5;
}

@media (max-width: 640px) {
    .dun-fields {
        grid-template-columns: 1fr;
    }

    .dun-field--wide,
    .dun-field--full {
        grid-column: auto;
    }
}

/* M96: a draft's state for a role that cannot put it in force, as the boards draw a badge. */
.dun-pending {
    flex: 0 0 auto;
    padding: 2px 9px;
    border-radius: 20px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 10.5px;
    font-weight: 700;
}

.dun-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.dun-field input,
.dun-field select {
    height: 32px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.dun-field textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 9px 10px;
    font: inherit;
    font-size: 12px;
    line-height: 1.6;
    color: var(--navy-900);
    resize: vertical;
}

/* A refusal is amber (Hassam's three weights: solid red is an open panic alert only). */
.dun-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.dun-status.expired {
    background: var(--amber-100);
    color: var(--amber-700);
}

/* The deliberate send (bug hunt B111): above the panes, the full width of the screen. */
.dun-send {
    margin: 0 0 18px;
}

.dun-send-note,
.dun-send-flag {
    margin: 0;
    font-size: 11px;
    line-height: 1.55;
    color: var(--slate-600);
}

.dun-send-flag {
    font-weight: 600;
    color: var(--amber-700);
}

.dun-send-preview {
    background: var(--navy-100);
    border-radius: 10px;
    padding: 11px 13px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.dun-send-subject {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
}

.dun-send-body {
    font-size: 12px;
    line-height: 1.6;
    color: var(--navy-900);
    white-space: pre-line;
}

.dun-send-history {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

/* Reminder delivery's preview (M84 §3.5): the deliberate send's own grounds, one card a reminder. */
.dun-delivery {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.dun-delivery-state {
    margin: 0;
    font-size: 12px;
    line-height: 1.55;
    color: var(--slate-600);
}

.dun-delivery-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.dun-delivery-row {
    background: var(--navy-100);
    border-radius: 10px;
    padding: 11px 13px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.dun-delivery-head,
.dun-delivery-legs {
    margin: 0;
    font-size: 12px;
    color: var(--navy-900);
}

.dun-delivery-legs {
    padding-left: 16px;
    color: var(--slate-600);
}

.dun-delivery-subject {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
}

.dun-delivery-body {
    margin: 0;
    font-size: 12px;
    line-height: 1.6;
    color: var(--navy-900);
    white-space: pre-line;
}

/* M10 §1 — the caution beside an SMS choice, in the board's own amber. */
.dun-sms-note {
    margin: 4px 0 0;
    font-size: 11px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.dun-actions {
    display: flex;
    align-items: center;
    gap: 13px;
}

.dun-draft {
    border-top: 1px solid var(--navy-100);
    padding-top: 11px;
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.dun-draft-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.dun-draft-name {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.dun-draft-sub {
    font-size: 10.5px;
    color: var(--slate-500);
    line-height: 1.5;
}
</style>
