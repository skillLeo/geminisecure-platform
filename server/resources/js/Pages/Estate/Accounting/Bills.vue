<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import DataTable from '../../../Components/DataTable.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { keptByTable } from '../../../lib/tableParams'
import { toast } from '../../../lib/toasts.js'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Bills & payments — board screen community-admin-27.
 *
 * THE ACCOUNTS PAYABLE SUB-LEDGER, and it is a sub-ledger rather than a list of
 * invoices. Every figure on it is summed from posted journal lines by
 * `Payables`: "Total payable" is the balance of 2000 Accounts Payable, and the
 * four open rows beneath it are the same lines grouped by bill. That is why the
 * tile and the rows tie exactly — J$8,500 + J$12,000 + J$6,890 + J$94,300 is
 * J$121,690 — and why nothing here is added up in this page.
 *
 * TWO REFUSALS THE SERVICE MAKES, AND THIS SCREEN HAS TO MAKE THEM FIRST.
 *
 *   A PAYMENT CANNOT EXCEED THE BILL WITHOUT AN EXPLICIT OVER-PAYMENT REASON.
 *   The excess leaves the vendor in debit on 2000, which is a real thing — a
 *   deposit, a duplicate to be recovered — and a balance nobody explained is one
 *   nobody can recover. So the reason field APPEARS the moment the typed amount
 *   goes over what the bill still owes, beside the number that caused it, rather
 *   than the press being sent to be bounced.
 *
 *   A PAYMENT IS REFUSED WHEN THE VENDOR HAS NO TRN. Withholding compliance
 *   bites when money moves, not when an invoice arrives, and it is the one
 *   refusal here that cannot be fixed on this screen — the supplier has to send
 *   paperwork. So "Pay now" is inert on that row and says so on hover, because
 *   the alternative is a treasurer filling in four fields to be told the amount
 *   was never the problem.
 *
 * APPROVING IS NOT PAYING, AND THEY ARE NOT THE SAME PERMISSION. A recorded bill
 * is a claim; approving it is the moment it becomes money the estate owes, which
 * is why it needs `estate.accounting_posting.approve_bills` where paying needs
 * `.create` (D-013, and M11 B3 for the approval being its own permission). A
 * draft row therefore offers Approve and not Pay: there is
 * nothing on 2000 to pay down until the liability is posted.
 *
 * NOTHING HERE EDITS A POSTED ENTRY, and nothing on it offers to. A bill that
 * was approved wrongly is corrected by a journal, not by changing the row.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** Budgets and Manual journals when switched on: the same tab row as the chart's (AccountingController::optionalTabs). */
    optionalTabs: { type: Array, default: () => [] },
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
    /** The bills as the shared table reads them (M32 F5): its order, its columns, its search and this page's place. */
    table: { type: Object, required: true },
    canPay: { type: Boolean, required: true },
    canApprove: { type: Boolean, required: true },
    /** Whether the viewer holds Facilities view, which the work order sits behind. */
    canOpenTicket: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
    /** What recording a bill chooses from: the register, the expense accounts, the open work orders. */
    billVendors: { type: Array, required: true },
    expenseAccounts: { type: Array, required: true },
    openTickets: { type: Array, required: true },

    /*
     * The ESTATE's today, "YYYY-MM-DD", from the server (bug hunt B106). Never
     * `new Date().toISOString()`, which is the UTC calendar and a day ahead of
     * Kingston every evening from 7 PM.
     */
    today: { type: String, required: true },

    /*
     * M10 §5. Total payable, Overdue and Paid this month are the estate's
     * payables as a whole. For a reader who may not have the estate's position
     * the server leaves those three tiles out — "Due this week", a count, stays —
     * and sends this sentence. Every bill's own figures stay on its row, because
     * paying a bill is filled from what that bill still owes.
     */
    positionWithheld: { type: Boolean, default: false },
    positionReason: { type: String, default: '' },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

// A bill entered, approved or paid in another console moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['accounting'] })

const page = usePage()

/**
 * All six, and the sixth is reachable only by forcing.
 *
 * `empty` is an estate that has recorded no bill at all — a first-use panel that
 * offers the register, not a table with headings ruled over nothing.
 * `empty-filtered` is the same table emptied by the view rather than by the
 * estate: this screen is deliberately open bills PLUS whatever was settled this
 * month, so a bill paid in July is absent from a payables screen that is not
 * empty of bills. The search (M32 F5) produces it too: no bill matching what was
 * typed.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => (props.table.q ?? '') !== '',
})

/*
 * THE TABLE (M32 F5): every bill the screen holds — open, plus settled this month — ordered by the server and paged.
 * Soonest due first by default: what is owed next is the question this screen is asked.
 */
const COLUMNS = [
    { key: 'vendor', label: 'Vendor', sortable: true },
    { key: 'reference', label: 'Reference', sortable: true },
    { key: 'amount', label: 'Amount', sortable: true, cellClass: 'num-cell' },
    { key: 'due', label: 'Due date', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'actions', label: '' },
]

const noun = computed(() => (props.table.total === 1 ? 'bill' : 'bills'))
const searchParams = computed(() => keptByTable(props.table))

const retry = () => router.reload()

/*
 * Where this estate's accounting lives, in whichever shape the environment
 * serves. Production gives each estate its own hostname and the path starts at
 * /accounting; local serves them all from one host with the estate in the path
 * (/estate/phoenixpark/accounting/bills). Cutting the current URL at /accounting
 * is right in both, and — unlike a tenant key read off a prop — it cannot
 * address an estate other than the one already open.
 */
const accountingPath = computed(() => {
    const cut = page.url.indexOf('/accounting')

    return cut === -1 ? '/accounting' : `${page.url.slice(0, cut)}/accounting`
})

/*
 * THE REMITTANCE ADVICE (12 §1). Asked for rather than produced — rendered by
 * a worker, kept seven years — and a second press inside the window hands back
 * the same document rather than sending a supplier two.
 */
const remittanceForm = useForm({})

const askForRemittance = (row) => {
    if (row.settled_payment_id === null) {
        return
    }

    remittanceForm.post(`${accountingPath.value}/bill-payments/${row.settled_payment_id}/remittance`, {
        preserveScroll: true,
        // A refusal is a toast (M95 §2), where it was a banner pushed into the page.
        onError: (errors) => toast(errors.document ?? errors.bill ?? 'The remittance advice was not issued.', { tone: 'error' }),
    })
}

/**
 * The work order behind a bill — board 18, one module over from the same root,
 * addressed by the ticket NUMBER the bill stores.
 */
const ticketHref = (row) => accountingPath.value.replace(/\/accounting$/, `/facilities/maintenance/${row.ticket_id}`)

/**
 * The four Accounting tabs, which are one module and not four screens.
 *
 * All four are built, so all four are links. The tab for the screen the reader
 * is already on is text rather than a control, because there is nowhere for it
 * to lead.
 */
const tabs = computed(() => [
    { key: 'chart', label: 'Chart of accounts', href: `${accountingPath.value}/chart-of-accounts` },
    { key: 'vendors', label: 'Vendors', href: `${accountingPath.value}/vendors` },
    { key: 'bills', label: 'Bills & payments', href: null },
    { key: 'reconciliation', label: 'Bank reconciliation', href: `${accountingPath.value}/reconciliation` },
    ...props.optionalTabs.map((tab) => ({ key: tab.key, label: tab.label, href: `${accountingPath.value}/${tab.key}` })),
])

/* ------------------------------------------------------------------ */
/* money */
/* ------------------------------------------------------------------ */

/**
 * The board's format, and the one a payment is actually posted in.
 *
 * `board` is what screen 27 draws in BOTH the tiles and the Amount column —
 * "$121,690", "$8,500" — and unlike the chart of accounts on screen 25 the tiles
 * here are NOT abbreviated. That is the board's own choice and it is the right
 * one: a payables tile is checked against the column beneath it, and "$122k"
 * cannot be checked against anything.
 *
 * `toTheCent` is J$ and two decimals, and it appears only inside the payment
 * panel — on the outstanding figure being settled and on the over-payment
 * warning. A payment is posted to the cent, and a figure quoted to the dollar
 * beside a field that accepts cents invites somebody to pay the rounded number.
 *
 * Every amount arrives as MINOR UNITS — integer cents, the only form that
 * survives arithmetic. The divide by 100 happens here, at the last moment, and
 * nothing is added up after it.
 */
// With its cents, always: a tile is checked against the column beneath it, and a bill can be $6,200.50 (batch 1, money).
const board = (minor) => `${minor < 0 ? '-' : ''}$${(Math.abs(minor) / 100).toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const toTheCent = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: 'JMD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100)

/* ------------------------------------------------------------------ */
/* paying one */
/* ------------------------------------------------------------------ */

/** The bill whose payment panel is open, or null. One at a time, deliberately. */
const paying = ref(null)

const form = useForm({
    amount: '',
    method: 'bank',
    /*
     * Blank rather than today. A payment is dated the day the money moved, which
     * is on the cheque stub or the bank's own record — and defaulting it to today
     * would silently date a Friday transfer to the Monday somebody typed it in,
     * putting it in the wrong bank statement period.
     */
    paid_on: '',
    reference: '',
    overpayment_reason: '',
})

/** The amount field as a number, whatever tidying has been done to it. */
const parseAmount = (value) => {
    const parsed = Number.parseFloat(String(value ?? '').replace(/[^0-9.-]/g, ''))

    return Number.isFinite(parsed) ? parsed : 0
}

const amountMinor = computed(() => Math.round(parseAmount(form.amount) * 100))

/** The row the open panel belongs to, so the panel can quote its own figures. */
const openRow = computed(() => props.rows.find((row) => row.id === paying.value) ?? null)

/**
 * Whether what has been typed is more than the bill still owes.
 *
 * THIS IS THE SERVICE'S RULE, ASKED HERE FIRST. `Payables::pay()` refuses an
 * amount above the outstanding balance unless a reason came with it; asking the
 * same question against the same figure means the reason field is in front of
 * the treasurer while they are still looking at the number, instead of arriving
 * as a refusal after the press.
 */
const isOverPayment = computed(
    () => openRow.value !== null && amountMinor.value > openRow.value.outstanding_minor
)

const overPaymentMinor = computed(() =>
    openRow.value === null ? 0 : amountMinor.value - openRow.value.outstanding_minor
)

/** The over-payment is stated and unexplained — the one thing that cannot post. */
const needsReason = computed(() => isOverPayment.value && form.overpayment_reason.trim() === '')

/**
 * Why "Pay now" cannot be pressed on this row, or null when it can.
 *
 * THREE DIFFERENT REFUSALS, and the difference matters to whoever is holding the
 * cheque. The viewer's own access comes first because it is true of every row.
 * The TRN is the vendor's paperwork and cannot be fixed here at all. A draft has
 * raised no liability, so there is nothing on 2000 to pay down — approving it is
 * the next act, and the row offers that instead.
 */
const payBlockedBy = (row) => {
    if (!props.canPay) {
        return props.blockedReason
    }

    if (!row.vendor.has_trn) {
        return `${row.vendor.name} has no TRN on file, so this bill cannot be paid. A bill may be recorded without one, since refusing that would understate what the estate owes, but withholding compliance bites when money moves. Put the taxpayer registration number on the vendor record first.`
    }

    if (row.status === 'draft') {
        return 'This bill is still a draft and has raised no liability, so there is nothing on 2000 Accounts Payable to pay down. Approve it first, so the estate’s accounts say it owes the money before they say it paid it.'
    }

    return null
}

/**
 * Why "Approve" cannot be pressed, or null when it can.
 *
 * AUTHORED, because the payload carries one `blockedReason` and it is about
 * paying — the sentence names Accounting CREATE access, which is not the
 * permission approval needs. Approving is where a claim becomes a liability, so
 * it needs `estate.accounting_posting.approve_bills`, and telling somebody they
 * lack the wrong permission is worse than telling them nothing.
 */
const approveBlockedBy = computed(() =>
    props.canApprove
        ? null
        : 'Approving a bill posts the liability to 2000 Accounts Payable, so it needs the Approve bills permission on Accounting. A role may prepare a bill without being able to commit the estate to it.'
)

/**
 * The short form of why Pay now is greyed on a row, said under it on the screen (M96 level 2); the whole sentence is
 * its tooltip. Only the reasons a role that may pay can meet: a role that never pays has no Pay now at all.
 */
const payWhyShort = (row) => (!row.vendor.has_trn ? 'No TRN on file' : row.status === 'draft' ? 'Approve it first' : null)

/** Open the panel on one row, with the outstanding balance already in it. */
const openPayment = (row) => {
    paying.value = row.id

    form.clearErrors()
    form.amount = (row.outstanding_minor / 100).toFixed(2)
    form.method = 'bank'
    form.paid_on = ''
    form.reference = ''
    form.overpayment_reason = ''
}

const closePayment = () => {
    paying.value = null
    form.reset()
    form.clearErrors()
}

/*
 * The field reads like money; the server receives arithmetic. `Money::of`
 * refuses a value it cannot represent exactly, so the amount travels as a
 * decimal STRING and is never cast to a float on the way.
 */
form.transform((data) => ({ ...data, amount: parseAmount(data.amount).toFixed(2) }))

const tidyAmount = () => {
    if (form.amount.trim() !== '') {
        form.amount = parseAmount(form.amount).toFixed(2)
    }
}

const submitPayment = (row) => {
    /*
     * A form submits on Enter from any field, and a disabled button does not stop
     * it. The server refuses both of these too — the route is gated on
     * `estate.accounting_posting.create` and the service refuses an unexplained
     * over-payment — so this is only about not sending a payment that will bounce.
     */
    if (payBlockedBy(row) !== null || needsReason.value) {
        return
    }

    form.post(`${accountingPath.value}/bills/${row.id}/pay`, {
        preserveScroll: true,
        onSuccess: () => closePayment(),
    })
}

/** Why Record payment in the modal cannot be pressed yet, said under it (M96 level 2). */
const payConfirmBlocked = computed(() =>
    needsReason.value ? 'A payment cannot exceed the bill without an over-payment reason. Say what the excess is.' : null
)

/* ------------------------------------------------------------------ */
/* recording a bill — the topbar's "Record bill" (12 §2, Wave 1) */
/* ------------------------------------------------------------------ */

/**
 * The expense account and the work order are chosen here, deliberately, as
 * ruled — a plumber and an electricity bill are different costs, and a bill
 * for a job should say which job. A draft: nothing posts until it is approved.
 */
const recording = ref(false)

/*
 * Thirty days after the estate's today. The arithmetic is on the calendar date
 * the server sent, read as a UTC midnight so no zone can move it a day.
 */
const thirtyDaysOn = (day) => new Date(Date.parse(`${day}T00:00:00Z`) + 30 * 86400000).toISOString().slice(0, 10)

const recordForm = useForm({
    vendor_id: props.billVendors[0]?.id ?? '',
    description: '',
    amount: '',
    due_on: thirtyDaysOn(props.today),
    account: props.expenseAccounts[0]?.code ?? '',
    ticket_number: '',
})

recordForm.transform((data) => ({
    ...data,
    amount: data.amount.trim() === '' ? '' : parseAmount(data.amount).toFixed(2),
    ticket_number: data.ticket_number === '' ? null : data.ticket_number,
}))

const recordBlockedBy = computed(() => {
    if (!props.canPay) {
        return 'Recording a bill puts a claim on the payables register and needs Accounting create access. You are able to read this screen.'
    }

    if (props.billVendors.length === 0) {
        return 'No supplier is on the register yet. Add the vendor first: a bill has to name who is owed.'
    }

    return null
})

const openRecording = () => {
    if (recordBlockedBy.value !== null) {
        return
    }

    recordForm.clearErrors()
    recording.value = true
}

const closeRecording = () => {
    recording.value = false
    recordForm.reset()
    recordForm.clearErrors()
}

const submitRecord = () => {
    if (recordBlockedBy.value !== null || recordForm.amount.trim() === '') {
        return
    }

    recordForm.post(`${accountingPath.value}/bills`, {
        preserveScroll: true,
        onSuccess: () => closeRecording(),
    })
}

/**
 * The bill whose approval is on its way to the server, or null.
 *
 * ONE PRESS, ONE LIABILITY. Approving posts Dr the expense and Cr 2000, and the
 * ledger cannot unpost it. A second click while the first is still in flight used
 * to reach the server as a second approval; the server now refuses it, and the
 * control is inert until the first has answered, so nobody is shown a refusal
 * for a click they did not mean to make.
 */
const approving = ref(null)

const approve = (row) => {
    if (approveBlockedBy.value !== null || approving.value !== null) {
        return
    }

    router.post(`${accountingPath.value}/bills/${row.id}/approve`, {}, {
        preserveScroll: true,
        onStart: () => {
            approving.value = row.id
        },
        // A refusal is a toast (M95 §2), where it was a banner pushed into the page.
        onError: (errors) => toast(errors.bill ?? Object.values(errors)[0] ?? 'The bill was not approved.', { tone: 'error' }),
        onFinish: () => {
            approving.value = null
        },
    })
}

/* ------------------------------------------------------------------ */
/* voiding a draft (bug hunt B036) */
/* ------------------------------------------------------------------ */

/*
 * A DRAFT RECORDED BY MISTAKE IS VOIDED, WITH A REASON. It has raised no
 * journal, so nothing is reversed and nothing posts; the row stays on the
 * supplier's record marked Void, with the reason and who voided it. Deciding the
 * estate does not owe a claim is the approver's call, so it sits behind the same
 * permission as Approve, `approve_bills` (M11 B3) — not the reconciliation
 * sign-off. An approved bill is never voided — its liability is on 2000, and it
 * is paid or corrected by a journal.
 */
const voiding = ref(null)

const voidForm = useForm({ reason: '' })

const voidBlockedBy = computed(() =>
    props.canApprove
        ? null
        : 'Voiding a bill decides the estate does not owe it, so it needs the Approve bills permission on Accounting, the same as approving one.'
)

/** The draft whose void is open, so the modal can name it. */
const voidRow = computed(() => props.rows.find((row) => row.id === voiding.value) ?? null)

const openVoid = (row) => {
    if (voidBlockedBy.value !== null) {
        return
    }

    voidForm.reset()
    voidForm.clearErrors()
    voiding.value = row.id
}

const closeVoid = () => {
    voiding.value = null
    voidForm.reset()
    voidForm.clearErrors()
}

const submitVoid = (row) => {
    if (voidBlockedBy.value !== null || voidForm.processing || voidForm.reason.trim() === '') {
        return
    }

    voidForm.post(`${accountingPath.value}/bills/${row.id}/void`, {
        preserveScroll: true,
        onSuccess: () => closeVoid(),
    })
}
</script>

<template>
    <Head title="Bills &amp; payments" />

    <EstateConsole
        title="Bills &amp; payments"
        :estate-name="estate.name"
        active="accounting"
        search-placeholder="Search bills by supplier or reference…"
        :search-route="`${accountingPath}/bills`"
        :search-value="table.q ?? ''"
        :search-params="searchParams"
    >
        <template #actions>
            <!-- M96: no Record bill for a role that can never record one (level 3); greyed with why when no supplier exists. -->
            <button
                v-if="canPay"
                type="button"
                class="btn-primary-sm"
                :disabled="recordBlockedBy !== null"
                :title="recordBlockedBy ?? 'Record a bill.'"
                @click="openRecording"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Record bill</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <Link v-if="tab.href" :href="tab.href" class="subnav-item">{{ tab.label }}</Link>
                <div v-else class="subnav-item active" aria-current="page">{{ tab.label }}</div>
            </template>
        </div>

        <!-- M96 level 2: why Record bill is greyed, on the screen. A refusal from the server is a toast (M95 §2). -->
        <p v-if="canPay && recordBlockedBy" class="bills-why">{{ recordBlockedBy }}</p>

        <!--
          Record a bill, in the console's modal (M95 §1). A draft is what it makes: the liability posts when a Treasurer
          approves it, not here. Each refusal sits under its own field.
        -->
        <ConsoleDialog
            :open="recording"
            title="Record a bill"
            size="form"
            confirm-label="Record bill"
            busy-label="Recording…"
            :busy="recordForm.processing"
            :dirty="recordForm.isDirty"
            discard-title="Discard this bill?"
            :confirm-blocked-reason="recordForm.amount.trim() === '' ? 'Enter the invoice amount.' : null"
            :error="recordForm.errors.bill ?? null"
            @confirm="submitRecord"
            @cancel="closeRecording"
        >
            <p class="pay-head">Saved as a draft. Once approved it posts to the ledger and is added to what the estate owes.</p>

            <RequiredMark legend />

            <div class="pay-fields">
                <div class="pay-field">
                    <label for="rec-vendor">Supplier <RequiredMark /></label>
                    <select id="rec-vendor" v-model="recordForm.vendor_id" required>
                        <option v-for="vendor in billVendors" :key="vendor.id" :value="vendor.id">
                            {{ vendor.name }}{{ vendor.has_trn ? '' : ' · no TRN yet' }}
                        </option>
                    </select>
                    <div v-if="recordForm.errors.vendor_id" class="pay-error">{{ recordForm.errors.vendor_id }}</div>
                </div>

                <div class="pay-field">
                    <label for="rec-account">Which cost this is <RequiredMark /></label>
                    <select id="rec-account" v-model="recordForm.account" required>
                        <option v-for="account in expenseAccounts" :key="account.code" :value="account.code">
                            {{ account.label }}
                        </option>
                    </select>
                    <div v-if="recordForm.errors.account" class="pay-error">{{ recordForm.errors.account }}</div>
                </div>

                <div class="pay-field">
                    <label for="rec-amount">Amount, J$ <RequiredMark /></label>
                    <input id="rec-amount" v-model="recordForm.amount" type="text" inputmode="decimal" required placeholder="e.g. 45000.00" />
                    <div v-if="recordForm.errors.amount" class="pay-error">{{ recordForm.errors.amount }}</div>
                </div>

                <div class="pay-field">
                    <label for="rec-due">Due on <RequiredMark /></label>
                    <input id="rec-due" v-model="recordForm.due_on" type="date" required />
                    <div v-if="recordForm.errors.due_on" class="pay-error">{{ recordForm.errors.due_on }}</div>
                </div>

                <div class="pay-field pay-field--wide">
                    <label for="rec-description">What it is for <RequiredMark /></label>
                    <input id="rec-description" v-model="recordForm.description" type="text" required maxlength="200" placeholder="e.g. Replacement of two gate floodlights and photocell" />
                    <div v-if="recordForm.errors.description" class="pay-error">{{ recordForm.errors.description }}</div>
                </div>

                <div class="pay-field pay-field--wide">
                    <label for="rec-ticket">Work order (optional)</label>
                    <select id="rec-ticket" v-model="recordForm.ticket_number" aria-describedby="rec-ticket-hint">
                        <option value="">Not for a work order</option>
                        <option v-for="ticket in openTickets" :key="ticket.number" :value="ticket.number">{{ ticket.label }}</option>
                    </select>
                    <div id="rec-ticket-hint" class="pay-hint">Leave it for a bill that is not for a job.</div>
                    <div v-if="recordForm.errors.ticket_number" class="pay-error">{{ recordForm.errors.ticket_number }}</div>
                </div>
            </div>
        </ConsoleDialog>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <!--
                          The four glyphs, lifted from this board rather than
                          taken from a component. The payload names no icon —
                          `billsBoard()` carries a key, a figure and a label, and
                          nothing about how any of them is drawn — so the pairing
                          is here, where the board it came from is.
                        -->
                        <svg v-if="kpi.key === 'payable'" viewBox="0 0 24 24" fill="none">
                            <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <svg v-else-if="kpi.key === 'overdue'" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                            <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else-if="kpi.key === 'due_week'" viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="20 6 9 17 4 12"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>
                </div>

                <!--
                  "Due this week" IS A COUNT AND NOT AN AMOUNT. The board draws it
                  bare — "4", no currency — beside three tiles of money, and the
                  payload says the same thing in its own shape: that tile carries
                  `value` where the other three carry `value_minor`. Formatting it
                  as money would tell a committee the estate owes four dollars.
                -->
                <div class="k-val">
                    {{ kpi.value_minor === undefined ? kpi.value : board(kpi.value_minor) }}
                </div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <p v-if="positionWithheld" class="bills-withheld">{{ positionReason }}</p>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Accounting is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The payables ledger could not be read"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            :title="table.q ? 'No bill matches that search' : 'No bill falls inside this view'"
            body="Bills paid in earlier months are on each supplier’s page."
            :action-label="table.q ? 'Clear the search' : null"
            @action="router.visit(`${accountingPath}/bills`)"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This estate owes nothing"
            body="No bills recorded yet."
        />

        <DataTable
            v-else
            :columns="COLUMNS"
            :rows="rows"
            :table="table"
            :noun="noun"
        >
            <template #cell-vendor="{ row }">
                <!--
                  The board puts the name DIRECTLY inside .res-cell here, with no .res-sub line beneath it — unlike the
                  vendor register on screen 26, which carries the trade as a second line. Reproduced as drawn.
                -->
                <div class="res-cell">
                    <div class="res-avatar">{{ row.vendor.initials }}</div>
                    <div class="res-name">{{ row.vendor.name }}</div>
                </div>
            </template>

            <!--
              A ticket-linked bill draws the work order as a link, addressed by the ticket NUMBER the bill stores; a viewer
              without Facilities view keeps the inert twin and the reason. The electricity bill has no ticket and draws its
              description as plain text — a link icon on a row that leads nowhere is a control that lies.
            -->
            <template #cell-reference="{ row }">
                            <Link
                                v-if="row.is_ticket && canOpenTicket"
                                :href="ticketHref(row)"
                                class="ref-link"
                                :title="`Open work order #${row.ticket_id}: what the job was, who did it, and when it closed.`"
                            >
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path
                                        d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                                <span>{{ row.reference }}</span>
                            </Link>
                            <!-- M96 level 3: a role that cannot open work orders reads the reference, not a dead link. -->
                            <template v-else>{{ row.reference }}</template>
            </template>

            <template #cell-amount="{ row }">{{ board(row.amount_minor) }}</template>
            <template #cell-due="{ row }">{{ row.due_on }}</template>
            <!--
              The status leads to where the bill is acted on (batch 1, item 10): a bill that can be paid opens its payment
              dialog (the press only opens it, nothing posts); every other status opens the supplier's page, where the bill
              sits in its history and the TRN is kept.
            -->
            <template #cell-status="{ row }">
                <button
                    v-if="canPay && row.status !== 'draft' && row.status !== 'paid' && payBlockedBy(row) === null"
                    type="button"
                    class="status-link"
                    :title="`Pay ${row.vendor.name}.`"
                    @click="openPayment(row)"
                >
                    <span class="status-badge" :class="row.status">{{ row.status_label }}</span>
                </button>
                <Link
                    v-else
                    :href="`${accountingPath}/vendors/${row.vendor.id}`"
                    class="status-link"
                    :title="`Open ${row.vendor.name}: this bill sits in its history there.`"
                >
                    <span class="status-badge" :class="row.status">{{ row.status_label }}</span>
                </Link>
            </template>

            <template #cell-actions="{ row }">
                            <!--
                              The row's accounting state decides its action rather
                              than a preference. A draft has raised nothing, so it
                              is agreed — or, recorded by mistake, voided (B036). A
                              settled bill has a receipt the supplier keeps.
                              Everything else can be paid.
                            -->
                            <!-- M96 level 3: a role that cannot approve bills has neither Approve nor Void; the row's state says Draft. -->
                            <span v-if="row.status === 'draft' && canApprove" class="bill-actions">
                                <button
                                    type="button"
                                    class="text-link-sm"
                                    :disabled="approveBlockedBy !== null || approving !== null"
                                    :title="approveBlockedBy ?? 'Approve this bill.'"
                                    @click="approve(row)"
                                >
                                    {{ approving === row.id ? 'Approving…' : 'Approve' }}
                                </button>
                                <button
                                    type="button"
                                    class="text-link-sm"
                                    :disabled="voidBlockedBy !== null || approving !== null"
                                    :title="voidBlockedBy ?? 'Void this draft bill.'"
                                    @click="openVoid(row)"
                                >
                                    Void
                                </button>
                            </span>
                            <template v-else-if="row.status === 'draft'"></template>
                            <!--
                              A REMITTANCE ADVICE, and the label is the board's.
                              A receipt is issued by whoever RECEIVED the money
                              and the estate is the payer, so what it can issue
                              is the advice: what was paid, against which
                              invoice, when and how. Server-rendered and queued,
                              kept seven years (12 §1).
                            -->
                            <!-- A paid bill with no payment recorded against it can never have an advice: no button (M96 level 3). -->
                            <button
                                v-else-if="row.status === 'paid' && row.settled_payment_id !== null"
                                type="button"
                                class="text-link-sm"
                                :disabled="remittanceForm.processing"
                                title="Issue the remittance advice the estate sends its supplier: what was paid, against which invoice, when and how. A receipt would come from them, not from here."
                                @click="askForRemittance(row)"
                            >
                                View receipt
                            </button>
                            <template v-else-if="row.status === 'paid'"></template>
                            <!--
                              M96: Pay now only for a role that may pay (level 3 otherwise); a bill that cannot be paid yet is
                              greyed with the short reason under it on the screen and the whole sentence as its tooltip (level 2).
                            -->
                            <span v-else-if="canPay" class="bill-pay">
                                <button
                                    type="button"
                                    class="text-link-sm"
                                    :disabled="payBlockedBy(row) !== null"
                                    :title="payBlockedBy(row) ?? `Pay ${row.vendor.name}.`"
                                    @click="openPayment(row)"
                                >
                                    Pay now
                                </button>
                                <span v-if="payWhyShort(row)" class="row-why">{{ payWhyShort(row) }}</span>
                            </span>
            </template>

        </DataTable>

        <!--
          Void a draft, in the console's modal (M95 §1; B036): a void says why. It names the bill it is about to void, and
          its refusal sits under its field.
        -->
        <ConsoleDialog
            :open="voidRow !== null"
            :title="voidRow ? `Void ${voidRow.reference} from ${voidRow.vendor.name}?` : ''"
            tone="danger"
            size="form"
            confirm-label="Void bill"
            busy-label="Voiding…"
            cancel-label="Keep the bill"
            :busy="voidForm.processing"
            :dirty="voidForm.isDirty"
            discard-title="Discard the reason you typed?"
            :confirm-blocked-reason="voidForm.reason.trim() === '' ? 'Say why the estate does not owe it.' : null"
            :error="voidForm.errors.bill ?? null"
            @confirm="voidRow && submitVoid(voidRow)"
            @cancel="closeVoid"
        >
            <p class="pay-head">A draft, so nothing has posted and nothing is reversed. It stays on the supplier’s record, marked Void.</p>
            <RequiredMark legend />
            <div class="pay-field pay-field--wide">
                <label for="void-reason">Why the estate does not owe it <RequiredMark /></label>
                <input
                    id="void-reason"
                    v-model="voidForm.reason"
                    type="text"
                    required
                    maxlength="190"
                    placeholder="e.g. Recorded against the wrong supplier, a duplicate of AP-2026-09-0004"
                />
                <div v-if="voidForm.errors.reason" class="pay-error">{{ voidForm.errors.reason }}</div>
            </div>
        </ConsoleDialog>

        <!--
          Pay a bill, in the console's modal (M95 §1). It names the supplier, the bill and what it still owes to the cent;
          the over-payment reason appears with the over-payment rather than after the refusal, because the excess leaves
          the vendor in debit on 2000, a deposit or a duplicate to be recovered, and the ledger should be told which.
        -->
        <ConsoleDialog
            :open="openRow !== null"
            :title="openRow ? `Pay ${openRow.vendor.name}` : ''"
            size="form"
            confirm-label="Record payment"
            busy-label="Posting…"
            :busy="form.processing"
            :dirty="form.isDirty"
            discard-title="Discard this payment?"
            :confirm-blocked-reason="payConfirmBlocked"
            :error="form.errors.bill ?? null"
            @confirm="openRow && submitPayment(openRow)"
            @cancel="closePayment"
        >
            <p v-if="openRow" class="pay-head">{{ toTheCent(openRow.outstanding_minor) }} outstanding on {{ openRow.reference }}.</p>

            <RequiredMark legend />

            <div class="pay-fields">
                <div class="pay-field">
                    <label for="pay-amount">Amount <RequiredMark /></label>
                    <input id="pay-amount" v-model="form.amount" type="text" inputmode="decimal" required @blur="tidyAmount" />
                    <div v-if="form.errors.amount" class="pay-error">{{ form.errors.amount }}</div>
                </div>

                <div class="pay-field">
                    <label for="pay-method">Method <RequiredMark /></label>
                    <select id="pay-method" v-model="form.method" required>
                        <option value="bank">Bank transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="card">Card</option>
                        <option value="cash">Cash</option>
                    </select>
                    <div v-if="form.errors.method" class="pay-error">{{ form.errors.method }}</div>
                </div>

                <div class="pay-field">
                    <label for="pay-paid-on">Paid on <RequiredMark /></label>
                    <!-- No later than the estate's today (B033): a payment is dated the day the money moved. -->
                    <input id="pay-paid-on" v-model="form.paid_on" type="date" :max="today" required />
                    <div v-if="form.errors.paid_on" class="pay-error">{{ form.errors.paid_on }}</div>
                </div>

                <div class="pay-field">
                    <label for="pay-reference">Reference (optional)</label>
                    <input id="pay-reference" v-model="form.reference" type="text" maxlength="64" placeholder="e.g. Cheque or transfer number" />
                    <div v-if="form.errors.reference" class="pay-error">{{ form.errors.reference }}</div>
                </div>
            </div>

            <div v-if="isOverPayment" class="pay-field pay-field--wide">
                <label for="pay-overpayment">Over-payment reason <RequiredMark /></label>
                <input
                    id="pay-overpayment"
                    v-model="form.overpayment_reason"
                    type="text"
                    required
                    maxlength="190"
                    placeholder="e.g. A deposit against future work, a duplicate to be recovered"
                    aria-describedby="pay-overpayment-hint"
                />
                <div id="pay-overpayment-hint" class="pay-hint">This is {{ toTheCent(overPaymentMinor) }} more than the bill still owes.</div>
                <div v-if="form.errors.overpayment_reason" class="pay-error">{{ form.errors.overpayment_reason }}</div>
            </div>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/* AUTHORED (M10 §5): why three of the four tiles are not drawn. */
.bills-withheld {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 14px;
    max-width: 820px;
}

/*
 * Default-removal only, and the removals name the elements that need them.
 *
 * The board draws every control on this screen as a <div>; here they are links
 * and buttons, which arrive wearing an underline, a border, buttonface grey and
 * the browser's own font. .btn-primary-sm, .subnav-item, .ref-link and
 * .text-link-sm supply everything visible.
 *
 * NOTHING BELOW RESETS `border` ON A CLASS THE BOARD GIVES ONE TO. A scoped
 * element selector outranks a single class, so a blanket `button { border: 0 }`
 * would out-specify the board and strip .btn-outline-sm's 1.5px navy edge — a
 * real fidelity defect that survives measurement, because 1.5px on one small
 * control is well under any threshold. See the note in Gemini/Reports/
 * ReportShell.vue. The three classes reset here declare no border of their own.
 */
a.subnav-item {
    text-decoration: none;
}

button.subnav-item {
    border: 0;
    background: none;
    font-family: inherit;
}

button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.ref-link,
button.text-link-sm {
    border: 0;
    background: none;
    font-family: inherit;
    line-height: inherit;
    padding: 0;
    text-align: left;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board is a still image of a screen nobody has
 * pressed anything on, so it draws no payment panel, no flash and no refusal —
 * and all three are things this screen has to be able to say. Kept to the tokens
 * the boards define, and to the shapes they already use: the panel is the
 * navy-100 inset the boards put inside a white card, and the type sizes are the
 * ones .data-table and .res-sub already set.
 */
.bills-flash,
.bills-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.bills-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.bills-refusal {
    /* A refusal is amber (Hassam's three weights, 11 Oct): solid red is for an open panic or duress alert alone. */
    background: var(--amber-100);
    color: var(--amber-700);
}

/* The panel's own cell, in the row the shared table opens under the bill (M32 F5). */
.pay-cell {
    background: var(--navy-100);
    padding: 14px 16px;
}

.pay-panel {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

/* The record panel sits above the tiles rather than inside a row, so it carries the row's spacing itself. */
.record-panel {
    margin-bottom: 16px;
}

.pay-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
}

.pay-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px 14px;
}

.pay-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

/* What a field accepts or costs, one quiet line under it (not in its label). */
.pay-hint {
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--slate-600);
}

.pay-field--wide {
    grid-column: 1 / -1;
}

/* In the modal: the lead line above the fields, and on a phone the fields one under another. */
.pay-head {
    margin: 0 0 12px;
}

@media (max-width: 640px) {
    .pay-fields {
        grid-template-columns: 1fr;
    }
}

/* M96 level 2: why a control is greyed, on the screen. Under Record bill at the top; under Pay now on its row. */
.bills-why {
    margin: 0 0 14px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-600);
}

.bill-pay {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
}

.row-why {
    font-size: 10.5px;
    font-weight: 600;
    color: var(--slate-500);
    white-space: nowrap;
}

.pay-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
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

.pay-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.pay-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

/* A status that leads somewhere: the badge itself, drawn as a link on hover and focus (batch 1, item 10). */
.status-link {
    display: inline-block;
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    cursor: pointer;
}

.status-link:hover .status-badge,
.status-link:focus-visible .status-badge {
    text-decoration: underline;
}

/* A draft's two acts, Approve and Void, side by side (B036). */
.bill-actions {
    display: inline-flex;
    align-items: center;
    gap: 12px;
}
</style>
