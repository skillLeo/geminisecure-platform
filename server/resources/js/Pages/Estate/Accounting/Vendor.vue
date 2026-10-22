<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import DataTable from '../../../Components/DataTable.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Vendor detail — board screen community-admin-39.
 *
 * ONE SUPPLIER'S ACCOUNTS-PAYABLE SUB-LEDGER. The hero is what the estate has
 * paid them and what it still owes; the table beneath is the bills those figures
 * came out of.
 *
 * THE HERO IS NOT THE SUM OF THE ROWS, AND MUST NOT BE. "Currently owed" is this
 * vendor's balance on 2000 Accounts Payable and "Paid YTD" is the debits on the
 * same account raised by payments — both summed by `Payables` over every bill
 * this vendor ever had, while the table is a recent-history view. The board says
 * so itself: it draws four bills and eleven jobs completed. A page that added up
 * the rows on screen would produce a smaller, plausible, wrong number, and it
 * would be wrong by however much history the table did not show.
 *
 * THE TRN IS A PAYMENT RULE, NOT A VALIDATION. A vendor with no taxpayer
 * registration number on file may be billed all day — refusing to record their
 * invoices would understate what the estate owes, which is the one thing a
 * payables ledger exists to state. What may not happen is money moving, and
 * `Payables::pay()` checks it FIRST, before anything about the bill, because it
 * is the only refusal on that path nobody at this estate can clear. So this
 * screen states it as a consequence in front of the vendor's own controls rather
 * than as an error on a record that is perfectly valid.
 *
 * NO DELETE, AND NOT BECAUSE THE BOARD OMITTED ONE. A vendor with bills against
 * them is history the estate has to keep, and `bills.vendor_id` restricts at the
 * database to enforce it. The board draws exactly two actions — record a bill and
 * edit the vendor — and taking a supplier off the register is the status change
 * on the edit panel, beside the TRN rule, refused while a bill is open.
 *
 * CONTENT RESIDUAL, recorded and not a styling fault: the board draws four bills
 * for Island Electric — one unpaid and three settled — and the seed carries the
 * one open bill board 27 specifies. The three paid ones, the $142,300 paid to
 * date and the eleven completed jobs are history no board itemises, and posting
 * invented payments through the ledger to reach them would put money through the
 * estate's accounts that nobody asked for. The screen renders what the data says.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    vendor: { type: Object, required: true },
    stats: { type: Array, required: true },
    bills: { type: Array, required: true },
    /** The bill history as the shared table reads it (M32 F5). */
    table: { type: Object, required: true },
    reasons: { type: Object, required: true },
    canOpenTicket: { type: Boolean, required: true },
    canCreate: { type: Boolean, required: true },
    canEdit: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    editBlockedReason: { type: String, required: true },
    /** What recording a bill chooses from. The supplier is this one. */
    expenseAccounts: { type: Array, required: true },
    openTickets: { type: Array, required: true },

    /*
     * The ESTATE's today, "YYYY-MM-DD", from the server (bug hunt B106). Never
     * `new Date().toISOString()`, which is the UTC calendar and a day ahead of
     * Kingston every evening from 7 PM.
     */
    today: { type: String, required: true },

    /*
     * M10 §5. "Paid YTD" and "Currently owed" are the estate's spending with
     * this supplier, summed. For a reader who may not have the estate's
     * position the server leaves both out of `stats` and sends this sentence;
     * every bill below keeps its own amount.
     */
    positionWithheld: { type: Boolean, default: false },
    positionReason: { type: String, default: '' },

    /**
     * The jobs this supplier has been given (M98, maintenance and vendors): `{ rows, open, total }`, open ones first, for
     * a reader who may open a work order; null for one who may not, and the section is not drawn (M96 level 3).
     */
    workOrders: { type: Object, default: null },
})

/** "2 open of 5 given to this supplier", the jobs as a fact above their list. */
const jobsLine = computed(() => {
    const jobs = props.workOrders

    if (jobs === null || jobs.total === 0) {
        return ''
    }

    const listed = jobs.rows.length < jobs.total ? ` The ${jobs.rows.length} most recent are listed.` : ''

    return `${jobs.open} open of ${jobs.total} given to this supplier.${listed}`
})

/* ------------------------------------------------------------------ */
/* editing the supplier (12 §2, Wave 2) */
/* ------------------------------------------------------------------ */

const editing = ref(false)

const editForm = useForm({
    name: props.vendor.name,
    category: props.vendor.trade ?? '',
    trn: props.vendor.trn ?? '',
    contact_name: props.vendor.contact_name ?? '',
    contact_phone: props.vendor.contact_phone ?? '',
    contact_email: props.vendor.contact_email ?? '',
    status: props.vendor.status ?? 'active',
})

const openEditing = () => {
    if (!props.canEdit) {
        return
    }

    editForm.clearErrors()
    editing.value = true
}

/*
 * The TRN rule, stated as the consequence of what is in the box rather than as
 * a validation on it. Filling one in is what unblocks payment; clearing one
 * blocks it again on the next attempt, and says so before the press.
 */
const trnConsequence = computed(() =>
    editForm.trn.replace(/\D/g, '').length === 9
        ? 'Bills against this supplier can be paid.'
        : 'Bills can be recorded against this supplier and payment will refuse until a nine-digit TRN is on file.'
)

const submitEdit = () => {
    if (!props.canEdit) {
        return
    }

    editForm.post(`${root.value}/accounting/vendors/${props.vendor.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false
        },
    })
}

/*
 * RECORDING A BILL IS BUILT (12 §2, Wave 1) — the same panel board 27 carries,
 * with the supplier fixed to this one. A draft: the liability posts when a
 * Treasurer approves it on Bills & payments.
 */
const recording = ref(false)

/*
 * Thirty days after the estate's today. The arithmetic is on the calendar date
 * the server sent, read as a UTC midnight so no zone can move it a day.
 */
const thirtyDaysOn = (day) => new Date(Date.parse(`${day}T00:00:00Z`) + 30 * 86400000).toISOString().slice(0, 10)

const recordForm = useForm({
    vendor_id: props.vendor.id,
    description: '',
    amount: '',
    due_on: thirtyDaysOn(props.today),
    account: props.expenseAccounts[0]?.code ?? '',
    ticket_number: '',
    from_vendor: true,
})

recordForm.transform((data) => ({
    ...data,
    amount: data.amount.trim() === '' ? '' : Number(data.amount.replace(/[^0-9.]/g, '')).toFixed(2),
    ticket_number: data.ticket_number === '' ? null : data.ticket_number,
}))

const recordBlockedBy = computed(() => {
    if (!props.canCreate) {
        return props.blockedReason
    }

    if (props.vendor.status !== 'active') {
        return 'This supplier is inactive. A bill cannot be recorded against a vendor the estate no longer deals with.'
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

    recordForm.post(`${root.value}/accounting/bills`, {
        preserveScroll: true,
        onSuccess: () => closeRecording(),
    })
}

/*
 * Board 39 lives in the Payroll/Details/Billing sheet, not the Accounting one
 * its module sits in. The ten Estate Console boards do not share a stylesheet,
 * so a page names the board it reproduces rather than the module it belongs to —
 * naming board 26's sheet here would render this screen with no hero card, no
 * action stack and no detail head at all.
 */
useWireframe('community-admin-10-payroll-employees-details-and-billing')

// The vendor corrected, or a bill of theirs entered or paid elsewhere, re-reads this page by itself (M18H §7.2).
useLiveReload({ entities: ['accounting'] })

const page = usePage()

/*
 * Five of the six. A vendor's own sub-ledger carries no filter and no search —
 * there is no query that could have excluded the bills — so `empty-filtered`
 * cannot occur, and drawing a "clear the filter" panel on a screen with no
 * filter would invent a control to explain a state that does not exist.
 */
const state = useScreenState({
    rows: () => props.table.total,
})

/* The bill history on the shared table (M32 F5): the newest agreed bill first, a page at a time. */
const BILL_COLUMNS = [
    { key: 'reference', label: 'Reference', sortable: true },
    { key: 'amount', label: 'Amount', sortable: true, cellClass: 'num-cell' },
    { key: 'date', label: 'Date', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
]

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL — the same cut board
 * 26 makes. Production gives each estate its own hostname and no prefix; local
 * serves every estate from one host as /estate/{key}/accounting/vendors/{id}, so
 * a hard-coded root is correct in exactly one of the two.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/accounting')))

/** The register, which is the screen the reader pressed "View" on. */
const backHref = computed(() => `${root.value}/accounting/vendors`)

/**
 * The board's money: whole dollars, comma-grouped, no decimals — board 26's
 * formatter, unchanged, because these are the same figures read from the same
 * account and a supplier quoted $142,300 on one screen and $142.3k on the next
 * has been quoted two numbers.
 *
 * Minor units in, dollars out, and nothing is added up after the divide.
 */
// The sign before the dollar, "-$100", the console's one way to write a negative amount (Law 5): a supplier paid more
// than it billed is in debit, and that read "$-100".
// With its cents, always: "$6,200.50", never "$6,201" (batch 1, money).
const exact = (minor) => `${minor < 0 ? '-' : ''}$${(Math.abs(minor) / 100).toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

/**
 * The hero strip carries four figures and only two of them are money.
 *
 * That is the board's, not an inconsistency to tidy away: "Paid YTD" and
 * "Currently owed" are amounts, "Jobs completed" is a count and "Vendor since"
 * is a month. The server sends money as `value_minor` and the other two as
 * `value` precisely so the page does not have to guess which is which — a count
 * of 11 run through a currency formatter reads "$11", which is a smaller lie
 * than it looks and a completely different fact.
 *
 * `'value_minor' in stat` rather than a truthiness test, because a vendor paid
 * nothing this year has a value_minor of 0 and still needs a dollar sign.
 */
const figure = (stat) => ('value_minor' in stat ? exact(stat.value_minor) : stat.value)

/**
 * The board's status pill, and the one state it does not draw.
 *
 * .status-badge.active is the only variant board 10's sheet defines, so the
 * others are declared inline exactly as the board declares its own Unpaid
 * override. Amber for a bill still owed, red for one whose due date has passed,
 * neutral for a voided one — a void bill is a correction rather than a failure,
 * and colouring it red would put a settled decision in the same visual bucket as
 * an unpaid debt.
 */
const badgeStyle = (status) => {
    if (status === 'paid') {
        return undefined
    }

    // Amber, not red: red is urgent and life-safety only (M12 §4.4, D-185). "Overdue" says what it is.
    if (status === 'overdue') {
        return 'background:var(--amber-100);color:var(--amber-700);'
    }

    if (status === 'void') {
        return 'background:var(--navy-100);color:var(--slate-600);'
    }

    return 'background:var(--amber-100);color:var(--amber-700);'
}

/**
 * The consequence of a missing TRN, said in front of the controls it blocks.
 *
 * Deliberately phrased as what happens next rather than as what is wrong. There
 * is nothing wrong: the vendor is on the register, their bills post, and their
 * balance on 2000 is real money the estate owes. The refusal arrives at payment
 * and is cleared by paperwork from the supplier, which is not something anybody
 * can type into this screen.
 */
const NO_TRN =
    'No taxpayer registration number on file. Bills from this vendor are still recorded and still owed. The next payment to them is what will be refused, until the TRN is on the vendor record.'

/**
 * Why the pill is green, or is not.
 *
 * The board hard-codes the green Active pill inline, because it draws an active
 * vendor. A deactivated one is neutral rather than red: deactivation is the only
 * way a supplier ever leaves the register — one with bills against them cannot
 * be deleted — so it is a fact about the relationship, not a fault.
 */
const pillStyle = computed(() =>
    props.vendor.status === 'active'
        ? 'font-size:10.5px;font-weight:700;color:var(--green-700);background:var(--success-100);padding:5px 11px;border-radius:20px;'
        : 'font-size:10.5px;font-weight:700;color:var(--slate-600);background:var(--navy-100);padding:5px 11px;border-radius:20px;'
)
</script>

<template>
    <Head :title="vendor.name" />

    <!--
      The way back, in words (M95 §3): the shell's back control, to the register this vendor was opened from. It cuts
      its destination out of the URL that served the page, so it is always that register.
    -->
    <EstateConsole :title="vendor.name" :estate-name="estate.name" active="accounting" :back="{ href: backHref, label: 'Back to vendors' }">

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This vendor’s account is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This vendor’s account could not be loaded"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="detail-head">
                <div class="hero-card">
                    <div class="hero-top">
                        <div>
                            <div class="hero-name">{{ vendor.name }}</div>

                            <!-- "Electrical contractor · Owen Grant · (876) 555 0110" —
                                 composed on the server out of whichever of the trade,
                                 the contact and the phone this vendor actually has, so
                                 a missing one closes up rather than leaving a stray
                                 middot in the line. -->
                            <div class="hero-sub">{{ vendor.sub }}</div>
                        </div>

                        <div :style="pillStyle">{{ vendor.status_label }}</div>
                    </div>

                    <!--
                      Four figures over the whole bill set, not over the rows
                      below. Each is printed by what the server sent it as: an
                      amount, a count, a month.
                    -->
                    <div class="hero-stats">
                        <div v-for="stat in stats" :key="stat.key" class="hero-stat">
                            <div class="hs-v">{{ figure(stat) }}</div>
                            <div class="hs-l">{{ stat.label }}</div>
                        </div>
                    </div>

                    <p v-if="positionWithheld" class="vendor-withheld">{{ positionReason }}</p>
                </div>

                <!--
                  Two actions, which is what the board draws. Both are real acts
                  with consequences outside this screen — recording a bill posts
                  a journal, and editing a vendor changes who the estate is
                  allowed to pay — so each is visibly inert and says which form
                  it is waiting on rather than answering a press with silence.

                  There is no third. A vendor is deactivated on the edit screen
                  and never deleted, and a delete control here would promise
                  something the database refuses.
                -->
                <!--
                  M96: each action at its level. A role that can never record a bill or edit a supplier has neither
                  button (level 3); a supplier taken off the register greys Record new bill, with why on the screen
                  under it (level 2).
                -->
                <div v-if="canCreate || canEdit" class="action-stack">
                    <button
                        v-if="canCreate"
                        type="button"
                        class="stack-btn primary"
                        :disabled="recordBlockedBy !== null"
                        :title="recordBlockedBy ?? `Record a bill from ${vendor.name}.`"
                        @click="openRecording"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <span>Record new bill</span>
                    </button>

                    <button
                        v-if="canEdit"
                        type="button"
                        class="stack-btn outline"
                        :title="`Edit ${vendor.name}.`"
                        @click="openEditing"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                            <path
                                d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>Edit vendor</span>
                    </button>

                    <p v-if="canCreate && recordBlockedBy" class="stack-why">{{ recordBlockedBy }}</p>
                </div>
            </div>

            <!--
              Authored, and it earns its place. It is drawn only for a vendor the
              estate cannot pay, it sits under the hero because that is where the
              money figures are, and it states the consequence rather than
              marking the record invalid.
            -->
            <p v-if="!vendor.trn" class="trn-note">{{ NO_TRN }}</p>


            <!-- Edit the vendor, in the console's modal (M95 §1); each refusal under its own field. -->
            <ConsoleDialog
                :open="editing"
                :title="`Edit ${vendor.name}`"
                size="form"
                confirm-label="Save vendor"
                busy-label="Saving…"
                :busy="editForm.processing"
                :dirty="editForm.isDirty"
                discard-title="Discard these changes?"
                :confirm-blocked-reason="editForm.name.trim() === '' ? 'A supplier has a name.' : null"
                @confirm="submitEdit"
                @cancel="editing = false"
            >
                <p class="bill-head">
                    Bills already posted do not move: a journal carries the amount, not the supplier's paperwork.
                </p>

                <RequiredMark legend />

                <!-- The same six fields as Add a vendor, in the same order and the same words, then the register status. -->
                <div class="bill-fields">
                    <div class="bill-field">
                        <label for="ve-name">Name <RequiredMark /></label>
                        <input id="ve-name" v-model="editForm.name" type="text" required maxlength="120" />
                        <div v-if="editForm.errors.name" class="bill-error">{{ editForm.errors.name }}</div>
                    </div>
                    <div class="bill-field">
                        <label for="ve-category">Trade <RequiredMark /></label>
                        <input id="ve-category" v-model="editForm.category" type="text" maxlength="60" placeholder="e.g. Electrical" required />
                        <div v-if="editForm.errors.category" class="bill-error">{{ editForm.errors.category }}</div>
                    </div>
                    <div class="bill-field">
                        <label for="ve-contact">Contact <RequiredMark /></label>
                        <input id="ve-contact" v-model="editForm.contact_name" type="text" maxlength="120" required />
                        <div v-if="editForm.errors.contact_name" class="bill-error">{{ editForm.errors.contact_name }}</div>
                    </div>
                    <div class="bill-field">
                        <label for="ve-phone">Phone <RequiredMark /></label>
                        <input id="ve-phone" v-model="editForm.contact_phone" type="tel" maxlength="40" :placeholder="`e.g. ${page.props.phoneExample?.local ?? ''}`" required />
                        <div v-if="editForm.errors.contact_phone" class="bill-error">{{ editForm.errors.contact_phone }}</div>
                    </div>
                    <div class="bill-field">
                        <label for="ve-email">Email <RequiredMark /></label>
                        <input id="ve-email" v-model="editForm.contact_email" type="email" maxlength="160" required />
                        <div v-if="editForm.errors.contact_email" class="bill-error">{{ editForm.errors.contact_email }}</div>
                    </div>
                    <div class="bill-field">
                        <label for="ve-trn">TRN (optional)</label>
                        <input id="ve-trn" v-model="editForm.trn" type="text" maxlength="20" placeholder="e.g. 100-482-517" aria-describedby="ve-trn-hint" />
                        <div id="ve-trn-hint" class="bill-hint">Nine digits. No payment can be made to this vendor until it is on file.</div>
                        <div v-if="editForm.errors.trn" class="bill-error">{{ editForm.errors.trn }}</div>
                    </div>
                    <div class="bill-field bill-field--wide">
                        <label for="ve-status">On the register</label>
                        <select id="ve-status" v-model="editForm.status">
                            <option value="active">Active · bills may be recorded and paid</option>
                            <option value="inactive">Inactive · no new bills; every past one stays</option>
                        </select>
                        <div v-if="editForm.errors.status" class="bill-error">{{ editForm.errors.status }}</div>
                    </div>
                </div>

                <p class="bill-read">{{ trnConsequence }}</p>
            </ConsoleDialog>

            <!-- Record a bill, in the console's modal (M95 §1); each refusal under its own field. -->
            <ConsoleDialog
                :open="recording"
                :title="`Record a bill from ${vendor.name}`"
                size="form"
                confirm-label="Record bill"
                busy-label="Recording…"
                :busy="recordForm.processing"
                :dirty="recordForm.isDirty"
                discard-title="Discard this bill?"
                :confirm-blocked-reason="recordForm.amount.trim() === '' ? 'Enter the invoice amount.' : null"
                @confirm="submitRecord"
                @cancel="closeRecording"
            >
                <!-- Plain words, one line (M53 Law 2): no Dr, no Cr, no account code in a lead sentence. -->
                <p class="bill-head">Saved as a draft. Once approved it posts to the ledger and is added to what the estate owes.</p>

                <RequiredMark legend />

                <div class="bill-fields">
                    <div class="bill-field">
                        <label for="vb-account">Which cost this is <RequiredMark /></label>
                        <select id="vb-account" v-model="recordForm.account" required>
                            <option v-for="account in expenseAccounts" :key="account.code" :value="account.code">
                                {{ account.label }}
                            </option>
                        </select>
                        <div v-if="recordForm.errors.account" class="bill-error">{{ recordForm.errors.account }}</div>
                    </div>

                    <div class="bill-field">
                        <label for="vb-amount">Amount, J$ <RequiredMark /></label>
                        <input id="vb-amount" v-model="recordForm.amount" type="text" inputmode="decimal" required placeholder="e.g. 45000.00" />
                        <div v-if="recordForm.errors.amount" class="bill-error">{{ recordForm.errors.amount }}</div>
                    </div>

                    <div class="bill-field">
                        <label for="vb-due">Due on <RequiredMark /></label>
                        <input id="vb-due" v-model="recordForm.due_on" type="date" required />
                        <div v-if="recordForm.errors.due_on" class="bill-error">{{ recordForm.errors.due_on }}</div>
                    </div>

                    <div class="bill-field">
                        <label for="vb-ticket">Work order (optional)</label>
                        <select id="vb-ticket" v-model="recordForm.ticket_number" aria-describedby="vb-ticket-hint">
                            <option value="">Not for a work order</option>
                            <option v-for="ticket in openTickets" :key="ticket.number" :value="ticket.number">{{ ticket.label }}</option>
                        </select>
                        <div id="vb-ticket-hint" class="bill-hint">Leave it for a bill that is not for a job.</div>
                        <div v-if="recordForm.errors.ticket_number" class="bill-error">{{ recordForm.errors.ticket_number }}</div>
                    </div>

                    <div class="bill-field bill-field--wide">
                        <label for="vb-description">What it is for <RequiredMark /></label>
                        <input id="vb-description" v-model="recordForm.description" type="text" required maxlength="200" placeholder="e.g. October generator service" />
                        <div v-if="recordForm.errors.description" class="bill-error">{{ recordForm.errors.description }}</div>
                    </div>
                </div>
            </ConsoleDialog>

            <!-- A bare section heading, not a panel — the board uses
                 .info-panel-head standalone here, with its own inline margin. -->
            <div class="info-panel-head" style="margin-bottom: 12px">Bill history</div>

            <!--
              Different from board 26's empty in what it means and what comes
              next. An estate with no vendors has never bought anything; a vendor
              with no bills is a supplier the estate has taken on and not yet
              used, which is an ordinary state on the day they are added.
            -->
            <EmptyState
                v-if="state.isEmpty.value"
                variant="first-use"
                title="No bill has been recorded against this vendor"
                body="No bills from this supplier yet."
            />

            <DataTable v-else :columns="BILL_COLUMNS" :rows="bills" :table="table" noun="bills">
                <!-- A ticket-linked bill prints the work order rather
                     than the invoice number, because a committee asking
                     what a job cost is asking about the ticket. The
                     ticket is board 18, a Facilities screen: a link for a
                     role that holds Facilities view, and the number with
                     the reason on hover for one that does not. -->
                <template #cell-reference="{ row: bill }">
                    <Link
                        v-if="bill.ticket_id && canOpenTicket"
                        :href="`${root}/facilities/maintenance/${bill.ticket_id}`"
                        class="text-link-sm"
                        :title="`Open work order #${bill.ticket_id}: what the job was, who did it, and when it closed.`"
                    >
                        {{ bill.reference }}
                    </Link>
                    <span v-else :title="bill.ticket_id ? reasons.ticket : undefined">{{ bill.reference }}</span>
                </template>

                <template #cell-amount="{ row: bill }">{{ exact(bill.amount_minor) }}</template>

                <!-- "Paid Aug 22" against a bill dated Aug 20: the
                     settlement date is its own fact and the server
                     composes the label, because the two-day lag is
                     the difference between the credit to 2000 and
                     the debit that cleared it. A void bill carries why,
                     and who voided it, on hover (B036). -->
                <template #cell-status="{ row: bill }">
                    <div
                        class="status-badge"
                        :class="{ active: bill.status === 'paid' }"
                        :style="badgeStyle(bill.status)"
                        :title="bill.void_note ?? undefined"
                    >
                        {{ bill.status_label }}
                    </div>
                </template>
            </DataTable>

            <!--
              The jobs this supplier has been given (M98, maintenance and vendors): every work order assigned to it,
              open ones first, each a link to the job. In the queue's own words: the state, Overdue beside it when late
              (amber, never red), the priority, the age. Absent for a reader who may not open a work order.
            -->
            <template v-if="workOrders !== null">
                <div class="info-panel-head vendor-jobs-head">Work orders</div>
                <p v-if="workOrders.total === 0" class="vendor-jobs-line">No job has been given to this supplier yet.</p>
                <template v-else>
                    <p class="vendor-jobs-line">{{ jobsLine }}</p>
                    <div class="vendor-jobs-scroll">
                        <table class="data-table vendor-jobs">
                            <thead>
                                <tr>
                                    <th>Work order</th>
                                    <th>Where</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Age</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="job in workOrders.rows" :key="job.number">
                                    <td>
                                        <Link :href="`${root}/facilities/maintenance/${job.number}`" class="text-link-sm" :title="`Open work order #${job.number}.`">
                                            {{ job.ticket }}
                                        </Link>
                                    </td>
                                    <td>{{ job.location }}</td>
                                    <td>{{ job.priority_label }}</td>
                                    <td>
                                        <span class="vendor-job-state">
                                            <span class="status-badge" :class="{ active: job.status === 'completed' }">{{ job.status_label }}</span>
                                            <span v-if="job.is_overdue" class="status-badge overdue">Overdue</span>
                                        </span>
                                    </td>
                                    <td>{{ job.age }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </template>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * The supplier's work orders (M98): a heading like Bill history's, the counts as a line, and the board's own table in a
 * box that scrolls sideways on a phone rather than pushing the page.
 */
.vendor-jobs-head {
    margin: 24px 0 8px;
}

.vendor-jobs-line {
    margin: 0 0 12px;
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.5;
}

.vendor-jobs-scroll {
    overflow-x: auto;
}

.vendor-job-state {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}

/* A state with no colour of its own reads as a pill too: navy, never red; Completed keeps the board's green. */
.vendor-job-state .status-badge:not(.active):not(.overdue) {
    background: var(--navy-100);
    color: var(--navy-700);
}

/*
 * ON A PHONE (seen at 390 px, 12 Oct): the supplier's card and its two actions one above the other, where the board's
 * row squeezed the card and pushed the page to 480 px. Doubled classes outrank the board's `body.wf-… .detail-head`.
 */
@media (max-width: 640px) {
    .detail-head.detail-head {
        flex-direction: column;
    }

    .action-stack.action-stack {
        width: auto;
    }
}

/* AUTHORED (M10 §5): why two of the four hero figures are not drawn. */
.vendor-withheld {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 10px 0 0;
    max-width: 820px;
}

/*
 * Default-removal only. The board draws both actions as <div>s; here they are
 * real buttons, which arrive wearing a border, buttonface grey and the browser's
 * own font. .stack-btn and its two modifiers supply everything visible.
 *
 * THE BORDER RESET NAMES THE VARIANT THAT HAS NO BORDER. A scoped element
 * selector outranks a single class, so `button.stack-btn { border: 0 }` would
 * beat .stack-btn.outline and rub out the 1.5px navy edge that is the entire
 * difference between the outline variant and the solid one — a real fidelity
 * defect small enough to survive a passing measurement. Neither variant needs
 * its background touched: each modifier declares its own.
 */
button {
    font-family: inherit;
}

button.stack-btn.primary {
    border: 0;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * The TRN note. Authored, because the board carries no equivalent — it draws a
 * vendor whose paperwork is in order — and it is the one thing on this screen a
 * reader cannot work out from what is drawn. Kept to the tokens the board
 * defines, and drawn in amber rather than red: nothing here has failed yet.
 */
.trn-note {
    font-size: 11.5px;
    color: var(--amber-700);
    line-height: 1.6;
    max-width: 720px;
    margin: 0 0 16px;
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

/* The record-bill panel and its flash — authored, closed on a fresh GET. */
.bill-flash {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
    background: var(--green-100);
    color: var(--green-700);
}

.bill-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.bill-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
}

.bill-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px 14px;
}

.bill-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.bill-field--wide {
    grid-column: span 2;
}

/* What a field costs or accepts, one quiet line under it (not in its label). */
.bill-hint {
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--slate-600);
}

/* In the modal on a phone, the fields one under another. */
@media (max-width: 640px) {
    .bill-fields {
        grid-template-columns: 1fr;
    }

    .bill-field--wide {
        grid-column: auto;
    }
}

/* Why Record new bill is greyed, under it, on the screen (M96 level 2). */
.stack-why {
    margin: 4px 0 0;
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--slate-600);
}

.bill-head {
    margin: 0 0 12px;
}

.bill-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.bill-field input,
.bill-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

/* The TRN consequence, read back before the press. */
.bill-read {
    font-size: 11.5px;
    color: var(--navy-900);
    font-weight: 600;
    line-height: 1.5;
    margin: 0;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 14px;
}

/* An error is amber (Hassam's three weights, 11 Oct). */
.bill-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.bill-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
