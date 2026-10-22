<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import InfoButton from '../../../Components/InfoButton.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'
import { submitDialog } from '../../../lib/dialogSubmit.js'

/**
 * One unit's ledger — board screen community-admin-06.
 *
 * THIS IS A SUBSIDIARY LEDGER, not a list of bills. Charge is a debit to the
 * unit's receivable, Payment a credit, and the Balance column is the running
 * balance of that account — accumulated OLDEST FIRST and printed NEWEST FIRST,
 * which is why the top row's balance and the hero figure are the same number.
 * Every figure on it is summed from posted journal lines by `Dues`, so what a
 * resident is shown here is what the accounts say and not what the billing
 * tables remember.
 *
 * THE AGEING STRIP IS BUCKETED OFF THE DUE DATE, NOT THE POSTING DATE. The
 * board is what proves it: two open charges, one posted this month and one
 * last, and the strip puts both in 30 DAYS with nothing in CURRENT. The server
 * derives that under FIFO; this screen only prints it.
 *
 * FOUR ACTIONS, AND TWO OF THEM LEAD ELSEWHERE. Placing a household on a plan
 * and sending a dunning notice are links to boards 7 and 8 — drawn inert here
 * while those screens did not exist, and built since — so each act happens on
 * its own screen under its own write gate. Flagging hardship still has no
 * screen and says so on hover. Recording a payment is the one that is a
 * permission question, and it is treated as one below.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    unit: { type: Object, required: true },
    balance_minor: { type: Number, required: true },
    bucket: { type: String, required: true },
    bucket_label: { type: String, required: true },
    strip: { type: Array, required: true },
    statement: { type: Array, required: true },
    /** The whole statement as the shared table reads it (M32 F5): the newest line first, a page at a time. */
    table: { type: Object, required: true },
    canRecord: { type: Boolean, required: true },

    /*
     * THE ESTATE'S TODAY, "2026-09-30" (bug hunt B106). "Received on" starts from
     * it and cannot pass it. The browser's own ISO date is the UTC date, which
     * from 7 PM in Kingston is already tomorrow — and on the last evening
     * of a month, next month's first, which posted September's takings into
     * October.
     */
    today: { type: String, required: true },

    /** The hardship or dispute in force on this unit, or null. */
    flag: { type: Object, default: null },
    flagKinds: { type: Object, required: true },
    canFlag: { type: Boolean, required: true },
    flagBlockedReason: { type: String, required: true },
    /** Statements issued for this unit — server-rendered, queued, kept 7 years. */
    documents: { type: Array, required: true },
    /** Reversing one charge, with a reason (M53 §2): the approval reversing a month is. Without it the column is absent. */
    canReverse: { type: Boolean, default: false },
})

/*
 * The screen names its board, because the layout cannot. Ten Community Admin
 * stylesheets sit behind the Estate Console and they disagree with each other,
 * so each is scoped to its own body class and the page that reproduces a board
 * is the only thing that knows which one it is.
 */
useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

/*
 * Five of the six. A ledger carries no filter — there is no query that could
 * have excluded the entries — so `empty-filtered` cannot happen here, and
 * rendering a "clear the filter" panel on a screen with no filter would invent
 * a control to explain a state that does not exist.
 */
const state = useScreenState({
    rows: () => props.table.total,
})

/*
 * The statement's columns (M32 F5), on the shared table. The figures keep the board's own type — the charge navy, the
 * payment green, the balance slate — inside their cells, and sit right as the board ranges them.
 */
const STATEMENT_COLUMNS = computed(() => [
    { key: 'date', label: 'Date', sortable: true },
    { key: 'description', label: 'Description', sortable: true },
    { key: 'charge', label: 'Charge', sortable: true, class: 'ul-num' },
    { key: 'payment', label: 'Payment', sortable: true, class: 'ul-num' },
    { key: 'balance', label: 'Balance', sortable: true, class: 'ul-num' },
    // One charge reversed with a reason (M53 §2), for a role that holds the approval: absent for every other (Law 4).
    ...(props.canReverse ? [{ key: 'reverse', label: 'Actions', class: 'ul-act' }] : []),
])

/*
 * REVERSING ONE CHARGE (M53 §2). Two presses: Reverse on the line opens the console's dialog (M95 §1; it opened as a
 * panel between the tabs and the statement, away from the line pressed: the client's "reverse section"), which names
 * the charge and says what happens, and only its own button posts. The reversing entry is dated today and both lines stay on the
 * statement; a line of a month's run, of the demonstration's sample or already reversed has no Reverse (the server
 * sends `reverse` only where it can be done).
 */
const reversing = ref(null)

const reverseForm = useForm({ reason: '' })

const openReverse = (charge) => {
    reverseForm.reset()
    reverseForm.clearErrors()
    reversing.value = charge
}

/** Why the reversal cannot be posted yet, said under the dialog's button (M96 level 2). */
const reverseBlocked = computed(() => (reverseForm.reason.trim().length < 10 ? 'Say why, in ten characters at least.' : null))

const submitReverse = () => {
    if (reversing.value === null || reverseForm.reason.trim().length < 10) {
        return
    }

    // Each of this ledger's dialogs saves through the one helper (lib/dialogSubmit.js): a refusal keeps it open, typed.
    submitDialog(reverseForm, 'post', `${root.value}/finance/charges/${reversing.value.charge}/reverse`, {
        close: () => (reversing.value = null),
        onSuccess: () => reverseForm.reset(),
    })
}

const retry = () => router.reload()

/*
 * Where this module is rooted, read off the page's own URL — production serves
 * an estate bare and local under /estate/{key}, and cutting the URL that served
 * this ledger is correct in both.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const financePath = (suffix) => `${root.value}/finance${suffix}`

/*
 * Boards 7 and 8, which this ledger's plan and dunning controls were drawn
 * inert to wait for. Both are behind the same `dues_ledger.view` gate this
 * screen is, so anybody reading the ledger can open them; what each lets them
 * DO there is decided by that screen's own write gates.
 */
const planHref = computed(() => financePath(`/units/${props.unit.id}/payment-plan`))

const dunningHref = computed(() => financePath('/dunning'))

// "Send reminder now" opens board 8 ON THIS HOUSEHOLD, where its reminder is chosen, read and sent (bug hunt B111).
const sendReminderHref = computed(() => financePath(`/dunning?unit=${props.unit.id}`))

// The TEST card checkout for this unit — a preview of paying by card that sends and posts nothing.
const testCheckoutHref = computed(() => financePath(`/units/${props.unit.id}/test-checkout`))

/*
 * Where the chevron goes: the arrears list, which is the screen the reader
 * clicked "View ledger" on.
 *
 * Read off the sidebar rather than written as a literal. An estate is served
 * bare in production and under /estate/{tenant} in local (routes/tenant.php),
 * and the nav already carries this environment's own shape — a hard-coded
 * /finance/arrears answers 404 on every local machine.
 */
const backHref = computed(() => page.props.estateNav?.find((item) => item.key === 'dues_ledger')?.href ?? null)

/*
 * TWO MONEY FORMATS ON ONE SCREEN, and the difference is the design's rather
 * than an inconsistency to tidy away. The hero is the figure a household is
 * asked to settle, and a figure on a demand is quoted to the cent. The ageing
 * strip and the statement columns are read DOWN as columns, where a run of
 * ".00" is noise between the eye and the digits that differ.
 *
 * Every amount arrives as minor units — integer cents — because that is the
 * only form that survives arithmetic. The divide by 100 happens here, at the
 * last possible moment, and nothing is ever added up after it.
 *
 * EVERY FIGURE TO THE CENT (Hassam, 12 Oct: "Every amount that could carry cents, carries them"). The statement's
 * charges, payments and running balance, the ageing bands and the reversal's amount were rounded to the dollar to match
 * the board's whole-dollar columns, so $6,200.50 read $6,201 beside a journal that said otherwise.
 */
const toTheCent = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: 'JMD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100)

/*
 * A role may read what a household owes and still not be allowed to credit it (`canRecord`), or to flag it
 * (`canFlag`). Neither button is drawn for such a role (M96 level 3): each was greyed with its reason on hover, a
 * control that role could never press.
 */

/** Whether the payment dialog is open. */
const recording = ref(false)

/* ------------------------------------------------------------------ */
/* the statement (12 §1) */
/* ------------------------------------------------------------------ */

/*
 * ASKED FOR, NOT PRODUCED. A statement is rendered by a worker, so the press
 * records the request and the panel below shows what came of it. Pressing again
 * within the window hands back the same document rather than making a second —
 * which is what stops four identical statements existing for one balance — but
 * only while nothing on the account has moved: a press after a payment is a new
 * statement, with the payment on it (B027).
 */
const statementForm = useForm({})

const askForStatement = () => {
    statementForm.post(`${root.value}/finance/units/${props.unit.id}/statement`, { preserveScroll: true })
}

/* ------------------------------------------------------------------ */
/* hardship and dispute (12 §1) */
/* ------------------------------------------------------------------ */

/*
 * WHAT A FLAG DOES AND DOES NOT DO, and the panel says both out loud. It stops
 * the estate's automated chasing. It does NOT move the debt: the balance above,
 * the ageing strip beside it and the arrears board all go on saying exactly what
 * they said, because the household still owes what it owes. A control that
 * quietly shrank a balance would be a write-off nobody voted for.
 */
const flagging = ref(false)

const lifting = ref(false)

const flagForm = useForm({ kind: 'hardship', reason: '', minute_reference: '' })

const liftForm = useForm({ lifted_reason: '', lifted_minute_reference: '' })

const openFlag = () => {
    if (!props.canFlag) {
        return
    }

    if (props.flag !== null) {
        liftForm.clearErrors()
        lifting.value = true

        return
    }

    flagForm.clearErrors()
    flagging.value = true
}

/* Each in the console's dialog (M95 §1): why its button waits, and whether closing it would throw words away. */
const flagBlocked = computed(() => (flagForm.reason.trim() === '' || flagForm.minute_reference.trim() === '' ? 'Say why, and name the minute.' : null))

const flagChanged = computed(() => flagForm.reason.trim() !== '' || flagForm.minute_reference.trim() !== '')

const liftBlocked = computed(() =>
    liftForm.lifted_reason.trim() === '' || liftForm.lifted_minute_reference.trim() === '' ? 'Say why, and name the minute.' : null
)

const liftChanged = computed(() => liftForm.lifted_reason.trim() !== '' || liftForm.lifted_minute_reference.trim() !== '')

const closeFlag = () => {
    flagging.value = false
    flagForm.reset()
    flagForm.clearErrors()
}

const closeLift = () => {
    lifting.value = false
    liftForm.reset()
    liftForm.clearErrors()
}

const submitFlag = () => {
    if (flagForm.reason.trim() === '' || flagForm.minute_reference.trim() === '') {
        return
    }

    submitDialog(flagForm, 'post', `${root.value}/finance/units/${props.unit.id}/flag`, {
        close: () => (flagging.value = false),
        onSuccess: () => flagForm.reset(),
    })
}

const submitLift = () => {
    submitDialog(liftForm, 'post', `${root.value}/finance/units/${props.unit.id}/flag/${props.flag.id}/lift`, {
        close: () => (lifting.value = false),
        onSuccess: () => liftForm.reset(),
    })
}

/*
 * THE RECEIPT NUMBER IS NOT A FIELD. It is allocated when the payment posts,
 * from the estate's own sequence, and comes back in the flash — a number typed
 * here could be reused, skipped or held against a payment that never posts,
 * which is exactly what the ruling forbids. The reference is the paper's own:
 * a cheque number, the bank's transfer reference, or nothing for cash.
 */
const paymentForm = useForm({
    method: 'cash',
    amount: '',
    received_on: props.today,
    reference: '',
})

const METHODS = [
    { value: 'cash', label: 'Cash' },
    { value: 'cheque', label: 'Cheque' },
    { value: 'bank', label: 'Bank transfer' },
]

const referenceLabel = computed(() =>
    ({ cash: 'Receipt book number, if the cash came with one', cheque: 'Cheque number', bank: 'Bank transfer reference' })[
        paymentForm.method
    ]
)

const openRecording = () => {
    if (!props.canRecord) {
        return
    }

    recording.value = true
    paymentForm.clearErrors()
}

const closeRecording = () => {
    recording.value = false
    confirmingPayment.value = false
    paymentForm.reset()
    paymentForm.clearErrors()
}

/*
 * A PAYMENT ASKS BEFORE IT POSTS (M87 §1.5, Sunday step 5), as Post charge does (DR-006) and posting a month does
 * (EST-S-03). It posted on one press: a receipt number taken and a household's ledger credited, with nothing between a
 * mistyped amount and the books. The press now opens a confirmation naming the amount, the lot, the household by name,
 * how and when the money came and what posting does; only the dialog's own "Record payment" posts. Cancel sends nothing.
 *
 * An amount the server would refuse still goes straight to it, so the server's own sentence lands on the field and
 * nothing can post that way. What it accepts is read here exactly as it reads it (`numeric`, `decimal:0,2`, at least
 * 0.01): the figure the dialog names is the one that posts, worked out from the field's own digits, never a float.
 */
const confirmingPayment = ref(false)

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/** "2026-10-10" as the console writes a date, "10 Oct 2026" (Law 5), read off the field: no clock can shift it. */
const asDate = (iso) => {
    const [year, month, day] = String(iso).split('-').map(Number)

    return year && month && day ? `${day} ${MONTHS[month - 1]} ${year}` : ''
}

/** The typed amount in cents, or null when the server would refuse it. */
const paymentMinor = computed(() => {
    const typed = String(paymentForm.amount ?? '').trim()
    const match = /^\+?(\d*)(?:\.(\d{0,2}))?$/.exec(typed)

    if (match === null || `${match[1]}${match[2] ?? ''}` === '') {
        return null
    }

    const minor = Number(match[1] || '0') * 100 + Number((match[2] ?? '').padEnd(2, '0'))

    return minor >= 1 && minor <= 9_999_999_999 ? minor : null
})

const hasHousehold = computed(() => {
    const resident = props.unit.resident ?? ''

    return resident !== '' && resident !== 'No resident on record'
})

const paymentHow = computed(() => {
    const reference = paymentForm.reference.trim()

    return (
        {
            cash: 'Cash',
            cheque: reference === '' ? 'Cheque' : `Cheque ${reference}`,
            bank: reference === '' ? 'Bank transfer' : `Bank transfer ${reference}`,
        }[paymentForm.method] ?? 'Payment'
    )
})

const askPaymentTitle = computed(() => `Record ${toTheCent(paymentMinor.value ?? 0)} against ${props.unit.reference}?`)

const askPaymentWho = computed(
    () =>
        `${hasHousehold.value ? `${props.unit.resident}'s household.` : `No household is on record at ${props.unit.reference}.`} ${paymentHow.value}, received ${asDate(paymentForm.received_on)}.`
)

const askPaymentWhat = computed(() =>
    hasHousehold.value
        ? 'This credits their ledger straight away and issues the next receipt number.'
        : `This credits ${props.unit.reference}'s ledger straight away and issues the next receipt number.`
)

const postPayment = () =>
    submitDialog(paymentForm, 'post', financePath(`/units/${props.unit.id}/payments`), {
        close: closeRecording,
        // A refusal belongs on the fields it names, so the dialog steps aside for it.
        onError: () => {
            confirmingPayment.value = false
        },
    })

const submitPayment = () => {
    /*
     * A form submits on Enter from any field and a disabled button does not
     * stop it. The route is gated and the service refuses a non-positive
     * amount and a future date, so this is only about not sending a press
     * that will bounce.
     */
    if (!props.canRecord || paymentForm.amount === '' || confirmingPayment.value) {
        return
    }

    if (paymentMinor.value !== null && asDate(paymentForm.received_on) !== '' && paymentForm.received_on <= props.today) {
        confirmingPayment.value = true

        return
    }

    postPayment()
}

/* The payment's dialog (M95 §1): its two steps in one modal, the details, then the question that posts. */
const paymentChanged = computed(
    () => paymentForm.amount !== '' || paymentForm.reference !== '' || paymentForm.method !== 'cash' || paymentForm.received_on !== props.today
)

const paymentBlocked = computed(() => (paymentForm.amount === '' ? 'Enter the amount received.' : null))

const confirmPayment = () => (confirmingPayment.value ? postPayment() : submitPayment())

const cancelPayment = () => {
    if (confirmingPayment.value) {
        confirmingPayment.value = false

        return
    }

    closeRecording()
}

/* A statement that could not be asked for says so, as a toast (M95 §2): it was a banner above the ledger. */
watch(
    () => page.props.errors?.document,
    (refusal) => {
        if (refusal) {
            toast(refusal, { tone: 'error', key: 'statement-refused' })
        }
    },
    { immediate: true },
)

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['dues'] })
</script>

<template>
    <Head :title="unit.title" />

    <!--
      A nested page: the way back to the arrears list, in words, top left (M95 §3), where a bare chevron stood. A viewer
      whose sidebar has no arrears list (this route is gated as the nav item is, so it cannot happen) gets no back control.
    -->
    <EstateConsole
        :title="unit.title"
        :estate-name="estate.name"
        active="dues_ledger"
        :back="backHref ? { href: backHref, label: 'Back to arrears' } : null"
    >

        <!--
          The statement is server-rendered and queued (12 §1), so this press
          asks for one rather than producing one — and a second press inside the
          window hands back the same document rather than making a second, while
          nothing on the account has changed.
        -->
        <template #actions>
            <button
                type="button"
                class="btn-outline-sm"
                :disabled="statementForm.processing"
                title="Ask for a statement of account. It is rendered by a worker and kept for seven years; pressing again hands you the same one while nothing on the account has changed."
                @click="askForStatement"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linejoin="round"
                    />
                </svg>
                <span>Print statement</span>
            </button>
        </template>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="5" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This unit’s ledger is not part of your role’s access"
            body="A unit's ledger names a household and what it owes, so only roles that handle dues can open it. Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This ledger could not be loaded"
            body="Nothing has been posted and nothing has been lost: every entry is still on the account. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="ledger-head">
                <div class="unit-summary">
                    <div class="us-top">
                        <div>
                            <div class="us-name">{{ unit.resident }}</div>
                            <div class="us-unit">{{ unit.meta }}</div>
                        </div>

                        <!--
                          The board's own inline override, kept verbatim and
                          kept to the bucket it was drawn for. .age-badge.d30 is
                          amber-700 on amber-100 — a chip designed for a white
                          table row, which glares on this navy card — so the
                          board dims the ground and lifts the text to amber-500
                          instead. The other three buckets keep their stylesheet
                          colours: tinting them the same way would be drawing
                          three variants no board has ever drawn.
                        -->
                        <div
                            class="age-badge"
                            :class="bucket"
                            :style="bucket === 'd30' ? 'background:rgba(255,182,39,.2);color:var(--amber-500);' : undefined"
                        >
                            {{ bucket_label }}
                        </div>
                    </div>

                    <div class="us-bal">{{ toTheCent(balance_minor) }}</div>
                    <div class="us-bal-lbl">TOTAL OUTSTANDING</div>

                    <div class="us-age-row">
                        <div v-for="band in strip" :key="band.key" class="us-age-item">
                            <div class="ua-v">{{ toTheCent(band.value_minor) }}</div>
                            <div class="ua-l">{{ band.label }}</div>
                        </div>
                    </div>
                </div>

                <div class="action-stack">
                    <button
                        v-if="canRecord"
                        type="button"
                        class="stack-btn primary"
                        title="Record money taken at the office: cash, a cheque or a transfer. It credits this unit and takes the next receipt number."
                        @click="openRecording"
                    >
                        <!-- This board draws the card a pixel higher than the Gemini boards do,
                             and BoardIcon carries no printer at all, so all six glyphs on this
                             screen are lifted from this board rather than four from a component
                             and two from here. Two sources on one stack is how they drift. -->
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M2 9h20" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>Record manual payment</span>
                    </button>

                    <!--
                      AUTHORED, not on the board: the card checkout preview, how paying by card will
                      look (the client's request, 25 September 2026). Only Stripe's published numbers,
                      no request, nothing posted; the page says PREVIEW throughout. Never "test" beside
                      a household's dues (DR-052; M77 §6).
                    -->
                    <Link
                        :href="testCheckoutHref"
                        class="stack-btn outline"
                        title="Preview how paying this unit’s dues by card will look. Nothing is charged."
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.7" stroke-dasharray="3 2" />
                            <path d="M2 9h20" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>Card payment preview</span>
                    </Link>

                    <!--
                      Board 7, a real link since it was built. Anybody reading
                      this ledger may open the plan; drawing one up and
                      activating it are that screen's own create and approve
                      gates, and it states them itself.
                    -->
                    <Link
                        :href="planHref"
                        class="stack-btn amber"
                        title="Open this household’s payment plan: draw one up, record that they agreed, or read the one in force. A plan in progress pauses automated reminders and lifts the arrears restriction."
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path
                                d="M3 10h18M8 3v4M16 3v4"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linecap="round"
                            />
                        </svg>
                        <span>Place on payment plan</span>
                    </Link>

                    <!--
                      Board 8. A reminder is chosen, sent and logged verbatim
                      there, so a dispute can be settled from what the household
                      was actually told — this ledger is not the place to send one
                      blind.
                    -->
                    <Link
                        :href="sendReminderHref"
                        class="stack-btn outline"
                        title="Open Reminders on this household, where a reminder is chosen, read exactly as it will go out, and sent."
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
                        <span>Send reminder now</span>
                    </Link>

                    <button
                        v-if="canFlag"
                        type="button"
                        class="stack-btn outline"
                        :title="flag === null ? 'Record a committee decision to stop chasing this household. The balance and the ageing do not change.' : 'Lift the flag. Automated reminders resume from the next run.'"
                        @click="openFlag"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <span>{{ flag === null ? 'Flag hardship / dispute' : 'Lift the flag' }}</span>
                    </button>
                </div>
            </div>

            <!--
              AUTHORED. The board draws a unit with no document asked for, so
              it has no list. A queued document is not a failure and must not
              read as one — the sentence says it is being made.
            -->
            <div v-if="documents.length" class="doc-list">
                <div class="doc-head">Statements issued for {{ unit.reference }}</div>

                <div v-for="doc in documents" :key="doc.id" class="doc-row">
                    <div>
                        <div class="doc-title">{{ doc.title }}</div>
                        <div class="doc-status">{{ doc.status_line }}</div>
                    </div>

                    <a v-if="doc.is_ready" :href="`${root}/documents/${doc.id}`" class="text-link-sm">Download</a>
                    <span v-else class="doc-waiting">{{ doc.status === 'failed' ? 'Failed' : 'In progress' }}</span>
                </div>
            </div>

            <!--
              The board draws a household nobody has flagged, so it has neither
              the banner nor the panel below.

              The banner states the two halves of the ruling together, because
              a reader who sees only the first will think the debt moved.
            -->
            <div v-if="flag" class="flag-banner">
                <div class="flag-head">{{ flag.headline }}</div>
                <div class="flag-body">
                    {{ flag.reason }}
                </div>
                <div class="flag-meta">
                    Raised by {{ flag.raised_by }} on {{ flag.raised_at }}. Automated reminders are paused. The balance,
                    the ageing and the arrears list are unchanged: this household still owes what it owes, and a reminder
                    can still be sent on purpose.
                </div>
            </div>

            <!--
              The unit's own tabs, which are not the module's: board 5 puts
              Arrears, Charge schedule, Payment plans and Receipts in this strip
              and board 6 replaces the lot. Statement is the screen the reader
              is on, so it is text rather than a control — there is nowhere for
              it to lead. The other two are boards 8 and 7, built since this
              strip was drawn inert.
            -->
            <div class="subnav">
                <!-- M32 F12: every line below is a posted journal line, and why none is ever edited. -->
                <div class="subnav-item active" aria-current="page">Statement <InfoButton topic="journals" /></div>
                <Link :href="dunningHref" class="subnav-item">Reminders</Link>
                <Link :href="planHref" class="subnav-item">Payment plan</Link>
            </div>

            <!--
              An empty ledger takes the card's place rather than sitting inside
              it: the statement card is its own bordered surface, and column
              headings ruled over nothing say nothing.
            -->
            <EmptyState
                v-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing has been posted to this unit"
                body="No charge has been raised against it and no payment received, so its account carries no entries and no balance. A maintenance fee, a special assessment, a fine or a receipted payment each write a line here."
            />

            <!--
              Keyed by the journal LINE, which each row now carries (M32 F5) —
              the reference belongs to the entry, which a monthly dues run shares
              across every unit in the estate.

              A charge row leaves the payment cell empty and a payment row
              the charge cell, exactly as the board draws them: empty cells,
              never a dash, because the column width is the alignment and a
              dash would read as a value.
            -->
            <DataTable v-else :columns="STATEMENT_COLUMNS" :rows="statement" :table="table" noun="lines">
                <template #cell-date="{ row }"><span class="stmt-date">{{ row.date }}</span></template>
                <template #cell-description="{ row }"><span class="stmt-desc">{{ row.description }}</span></template>
                <template #cell-charge="{ row }">
                    <span class="stmt-charge">{{ row.charge_minor === null ? '' : toTheCent(row.charge_minor) }}</span>
                </template>
                <template #cell-payment="{ row }">
                    <span class="stmt-payment">{{ row.payment_minor === null ? '' : toTheCent(row.payment_minor) }}</span>
                </template>
                <template #cell-balance="{ row }"><span class="stmt-bal">{{ toTheCent(row.balance_minor) }}</span></template>
                <template #cell-reverse="{ row }">
                    <button
                        v-if="row.reverse"
                        type="button"
                        class="btn-outline-sm"
                        title="Reverse this charge, with a reason."
                        @click="openReverse(row.reverse)"
                    >
                        <span>Reverse</span>
                    </button>
                    <span
                        v-else-if="row.reversed"
                        class="stmt-reversed"
                        title="Reversed: a reversing entry took this charge off the balance. Both lines stay on the statement."
                        >Void</span
                    >
                </template>
            </DataTable>

            <!--
              THE FOUR FORMS OF THIS LEDGER, each in the console's modal (M95 §1). Each opened as a panel inside the page:
              the payment between the head and the statement, the flag and its lifting beside it, and the reversal
              between the tabs and the statement, away from the line pressed (the client's "reverse section").
            -->

            <!--
              Recording a payment: two steps in one dialog, as it always asked twice (M87 §1.5). The payment's details,
              then the question naming the amount, the lot and the household; only that second press posts.
            -->
            <ConsoleDialog
                :open="recording"
                :title="confirmingPayment ? askPaymentTitle : `Record a payment against ${unit.reference}`"
                size="form"
                :confirm-label="confirmingPayment ? 'Record payment' : 'Check the payment'"
                busy-label="Posting…"
                :cancel-label="confirmingPayment ? 'Change it' : 'Cancel'"
                :busy="paymentForm.processing"
                :dirty="!confirmingPayment && paymentChanged"
                discard-title="Discard this payment?"
                :confirm-blocked-reason="confirmingPayment ? null : paymentBlocked"
                :error="paymentForm.errors.payment ?? null"
                @confirm="confirmPayment"
                @cancel="cancelPayment"
            >
                <template v-if="confirmingPayment">
                    <p class="pay-ask">{{ askPaymentWho }}</p>
                    <p class="pay-ask">{{ askPaymentWhat }}</p>
                </template>
                <template v-else>
                    <p class="pay-lead">The receipt number is given when it posts, from the estate's own sequence.</p>
                    <div class="pay-fields">
                        <div class="pay-field">
                            <label for="pay-method">How it arrived</label>
                            <select id="pay-method" v-model="paymentForm.method" required>
                                <option v-for="method in METHODS" :key="method.value" :value="method.value">{{ method.label }}</option>
                            </select>
                            <div v-if="paymentForm.errors.method" class="pay-error">{{ paymentForm.errors.method }}</div>
                        </div>
                        <div class="pay-field">
                            <label for="pay-amount">Amount, J$</label>
                            <input id="pay-amount" v-model="paymentForm.amount" type="text" inputmode="decimal" required placeholder="12400.00" />
                            <div v-if="paymentForm.errors.amount" class="pay-error">{{ paymentForm.errors.amount }}</div>
                        </div>
                        <div class="pay-field">
                            <label for="pay-received">Received on</label>
                            <input id="pay-received" v-model="paymentForm.received_on" type="date" :max="today" required />
                            <div v-if="paymentForm.errors.received_on" class="pay-error">{{ paymentForm.errors.received_on }}</div>
                        </div>
                        <div class="pay-field">
                            <label for="pay-reference">{{ referenceLabel }}</label>
                            <input id="pay-reference" v-model="paymentForm.reference" type="text" maxlength="60" />
                        </div>
                    </div>
                </template>
            </ConsoleDialog>

            <ConsoleDialog
                :open="flagging"
                :title="`Flag ${unit.reference}`"
                size="form"
                confirm-label="Flag this household"
                busy-label="Flagging…"
                :busy="flagForm.processing"
                :dirty="flagChanged"
                discard-title="Discard this flag?"
                :confirm-blocked-reason="flagBlocked"
                @confirm="submitFlag"
                @cancel="closeFlag"
            >
                <p class="pay-lead">It stops the estate's automated reminders and moves no money: the balance and the arrears list stay as they are.</p>
                <div class="pay-fields">
                    <div class="pay-field">
                        <label for="fl-kind">Which this is</label>
                        <select id="fl-kind" v-model="flagForm.kind" required>
                            <option v-for="(label, key) in flagKinds" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="pay-field">
                        <label for="fl-minute">The committee minute that agreed it</label>
                        <input id="fl-minute" v-model="flagForm.minute_reference" type="text" required maxlength="80" placeholder="Min. 2026-09-03 §4" />
                        <div v-if="flagForm.errors.minute_reference" class="pay-error">{{ flagForm.errors.minute_reference }}</div>
                    </div>
                    <div class="pay-field pay-field--wide">
                        <label for="fl-reason">Why, in the estate's own words</label>
                        <input id="fl-reason" v-model="flagForm.reason" type="text" required maxlength="500" />
                        <div v-if="flagForm.errors.reason" class="pay-error">{{ flagForm.errors.reason }}</div>
                    </div>
                </div>
            </ConsoleDialog>

            <!-- A reason and a minute, as raising it needed (13 A4): the committee stopped the chasing and starts it again. -->
            <ConsoleDialog
                :open="lifting && flag !== null"
                :title="`Lift the flag on ${unit.reference}?`"
                size="form"
                confirm-label="Lift the flag"
                busy-label="Lifting…"
                :busy="liftForm.processing"
                :dirty="liftChanged"
                discard-title="Discard this reason?"
                :confirm-blocked-reason="liftBlocked"
                @confirm="submitLift"
                @cancel="closeLift"
            >
                <p class="pay-lead">Automated reminders resume from the next run. The flag stays on the record, with its beginning and its end.</p>
                <div class="pay-fields">
                    <div class="pay-field">
                        <label for="fl-lift-minute">The committee minute that agreed it</label>
                        <input id="fl-lift-minute" v-model="liftForm.lifted_minute_reference" type="text" required maxlength="80" placeholder="Min. 2026-10-01 §3" />
                        <div v-if="liftForm.errors.lifted_minute_reference" class="pay-error">{{ liftForm.errors.lifted_minute_reference }}</div>
                    </div>
                    <div class="pay-field pay-field--wide">
                        <label for="fl-lift">Why it is being lifted</label>
                        <input id="fl-lift" v-model="liftForm.lifted_reason" type="text" required maxlength="500" />
                        <div v-if="liftForm.errors.lifted_reason" class="pay-error">{{ liftForm.errors.lifted_reason }}</div>
                    </div>
                </div>
            </ConsoleDialog>

            <ConsoleDialog
                :open="reversing !== null"
                :title="reversing ? `Reverse ${reversing.reference}?` : ''"
                size="form"
                tone="danger"
                confirm-label="Reverse this charge"
                busy-label="Reversing…"
                cancel-label="Not now"
                :busy="reverseForm.processing"
                :dirty="reverseForm.reason.trim() !== ''"
                discard-title="Discard this reason?"
                :confirm-blocked-reason="reverseBlocked"
                @confirm="submitReverse"
                @cancel="reversing = null"
            >
                <p v-if="reversing" class="pay-lead">
                    {{ reversing.description }}, {{ toTheCent(reversing.amount_minor) }}. This posts a reversing entry
                    dated today. Both lines stay on {{ unit.reference }}'s statement, and the charge leaves the arrears.
                </p>
                <div class="pay-field">
                    <label for="rv-reason">Why, in the estate's own words</label>
                    <input id="rv-reason" v-model="reverseForm.reason" type="text" required minlength="10" maxlength="300" />
                    <div v-if="reverseForm.errors.reason" class="pay-error">{{ reverseForm.errors.reason }}</div>
                </div>
            </ConsoleDialog>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws every control on this screen as a
 * <div>; here they are one link and seven buttons, which arrive wearing an
 * underline, a border, buttonface grey and the browser's own font. Everything
 * visible still comes from the board's .btn-outline-sm, .stack-btn and
 * .subnav-item.
 *
 * THE BORDER RESETS NAME THE VARIANTS THAT HAVE NO BORDER. A scoped element
 * selector outranks a single class, so `button.stack-btn { border: 0 }` would
 * beat .stack-btn.outline and rub out the 1.5px edge that is the entire
 * difference between the outline variant and the solid ones. .btn-outline-sm is
 * left alone for the same reason: its own rule draws a border, and the two
 * places this screen wants it gone say so inline, as the board does.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

button.stack-btn.primary,
button.stack-btn.amber {
    border: 0;
}

/* The tab strip's rule declares neither, so a button brings both back. */
button.subnav-item {
    border: 0;
    background: none;
}

button.stack-btn {
    cursor: pointer;
}

button.btn-primary-sm {
    border: 0;
    cursor: pointer;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/* The statement's figures range right, heading and cell, as the board draws them (M32 F5). */
:deep(.data-table th.ul-num),
:deep(.data-table td.ul-num) {
    text-align: right;
}

/* The line's own action (M53 §2), ranged right after the figures and no wider than its button. */
:deep(.data-table th.ul-act),
:deep(.data-table td.ul-act) {
    text-align: right;
    white-space: nowrap;
    width: 1%;
}

.stmt-reversed {
    color: var(--slate-500);
    font-size: 12px;
}

/*
 * AUTHORED BELOW THIS LINE. The board is a still image of a ledger nobody has
 * pressed anything on, so it draws no payment panel, no flash and no refusal.
 * Kept to the tokens the board defines and the shapes it already uses: the
 * white card with the navy-100 edge the statement sits in, and the type sizes
 * .stmt-row already sets.
 */
/* The forms' one line above their fields, in the dialog (M95 §1). */
.pay-lead {
    margin: 0 0 12px;
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.5;
}

.pay-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 11px;
}

.pay-field--wide {
    grid-column: span 2;
}

@media (max-width: 640px) {
    .pay-fields {
        grid-template-columns: 1fr;
    }

    .pay-field--wide {
        grid-column: auto;
    }
}

/* The documents list, which the board has no equivalent for. */
.doc-list {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 14px;
}

.doc-head {
    font-size: 11px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
    margin-bottom: 8px;
}

.doc-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 7px 0;
    border-top: 1px solid var(--navy-100);
}

.doc-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.5;
}

.doc-status {
    font-size: 10.5px;
    color: var(--slate-500);
    line-height: 1.55;
}

.doc-waiting {
    font-size: 11px;
    color: var(--slate-400);
    white-space: nowrap;
}

/* The flag banner: amber, because it is a condition rather than a failure. */
.flag-banner {
    background: var(--amber-100);
    border-radius: 12px;
    padding: 12px 15px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.flag-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--amber-700);
    line-height: 1.5;
}

.flag-body {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.5;
}

.flag-meta {
    font-size: 10.5px;
    color: var(--slate-600);
    line-height: 1.55;
}

.pay-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.pay-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.pay-field input,
.pay-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.pay-field input::placeholder {
    color: var(--text-placeholder);
    opacity: 1;
}

/* Amber: a field's refusal is an ordinary error, and saturated red is the panic alarm's alone (rule 20). */
.pay-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.pay-ask {
    margin: 0;
}

.pay-ask + .pay-ask {
    margin-top: 6px;
}
</style>
