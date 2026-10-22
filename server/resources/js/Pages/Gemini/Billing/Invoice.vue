<script setup>
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BackLink from '../../../Components/BackLink.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload'

// The invoice paid, credited or voided in another session re-reads itself (M18H §7.2).
useLiveReload({ entities: ['invoice'] })

/**
 * One invoice, taken apart — board screen super-admin-33.
 *
 * THE LINES ARE THE POINT. An invoice carrying only a total is a number nobody
 * can query; a client asking "why is this J$171,000" needs the two lines that
 * make it up, and this screen exists to answer that. Phoenix Park's August bill
 * is 450 units at J$340 plus 4 guards at J$4,500, and the two add to the posted
 * total exactly.
 *
 * A POSTED RECORD. Nothing on this screen edits the invoice and no route
 * behind it could: a correction to a raised invoice is a credit note, which is
 * a new posted record of its own. The two controls the board draws are inert
 * and say why — on a screen about money, a control that looks live and does
 * nothing is worse than anywhere else on the platform.
 *
 * If the lines ever stop adding up to the posted total, the screen SAYS SO
 * rather than showing one and hiding the other. The posted amount is what the
 * client owes and cannot be edited to make the arithmetic work, so a mismatch
 * is a real finding and gets reported as one.
 */
const props = defineProps({
    invoice: { type: Object, required: true },
    /** Credit notes against this invoice, and what they come to. */
    notes: { type: Array, required: true },
    credited_minor: { type: Number, required: true },
    /** When it was sent, and to whom. */
    sends: { type: Array, required: true },
    /*
     * One permission each since M11 B3 (D-150): emailing the client changes
     * nothing it owes, and a credit note writes some of it off.
     */
    canResend: { type: Boolean, required: true },
    resendDisabledReason: { type: String, required: true },
    /** Who a resend would mail, counted by the server from the same selection the resend makes (DR-074). */
    resendTo: { type: Array, default: () => [] },
    /** Why a resend would go nowhere, in the server's words, or null. */
    resendRefusal: { type: String, default: null },
    canCredit: { type: Boolean, required: true },
    creditDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.invoice.lines.length,
})

/* ------------------------------------------------------------------ */
/* the two writes (12 §2, Wave 4) */
/* ------------------------------------------------------------------ */

/*
 * A RAISED INVOICE IS NEVER EDITED. A correction is a credit note — a new
 * posted record of its own — and the invoice keeps its total, its lines and its
 * reference. Both figures are shown rather than one netted number, because a
 * client holding an invoice for one amount and a statement showing another has
 * been given two answers.
 */
const crediting = ref(false)

const creditForm = useForm({ amount: '', reason: '' })

const resendForm = useForm({})

/*
 * RESEND EMAILS PEOPLE, SO IT ASKS FIRST (DR-074, the register's Part 7), naming the invoice and exactly who is told:
 * `resendTo`, the server's own selection. Off before the press, with the server's reason, when nobody may read it.
 */
const resendBlockedBy = computed(() => (!props.canResend ? props.resendDisabledReason : props.resendRefusal))
const askingResend = ref(false)

/** "a@x and b@y", or "a@x, b@y and c@z". */
const listed = (items) => (items.length <= 1 ? items.join('') : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`)

const resendTitle = computed(() => {
    const n = props.resendTo.length

    return `Email ${props.invoice.reference} to ${n} ${n === 1 ? 'person' : 'people'} at ${props.invoice.client}?`
})

const resend = () => {
    if (resendBlockedBy.value !== null || resendForm.processing) {
        return
    }

    resendForm.clearErrors()
    askingResend.value = true
}

const postResend = () => {
    if (resendBlockedBy.value !== null || resendForm.processing) {
        return
    }

    // A refusal stays in the question's amber band and the question stays open (Hassam, 12 October); it closes when the
    // invoice has gone, and "Invoice resent." is the shell's toast.
    resendForm.post(`/billing/invoices/${props.invoice.id}/resend`, {
        preserveScroll: true,
        onSuccess: () => {
            askingResend.value = false
        },
    })
}

const keepInvoice = () => {
    askingResend.value = false
    resendForm.clearErrors()
}

/*
 * THE CREDIT NOTE OPENS IN THE SHARED MODAL (M95 §1); it was a panel that opened under the invoice. What was typed is
 * never thrown away without asking, and why the confirm is greyed is said under it.
 */
const openCredit = () => {
    if (!props.canCredit) {
        return
    }

    creditForm.clearErrors()
    crediting.value = true
}

const creditBlocked = computed(() => {
    if (creditForm.amount.trim() === '') {
        return 'Enter the amount to credit first.'
    }

    return creditForm.reason.trim() === '' ? 'Say why first: a credit note nobody can explain a year later cannot be reviewed.' : null
})

const submitCredit = () => {
    if (!props.canCredit || creditBlocked.value !== null) {
        return
    }

    creditForm.post(`/billing/invoices/${props.invoice.id}/credit-note`, {
        preserveScroll: true,
        onSuccess: () => {
            crediting.value = false
            creditForm.reset()
        },
    })
}

/** "$1,200.00", from minor units. Never arithmetic on a formatted string. */
const money = (minor) => `$${(minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
</script>

<template>
    <Head :title="`Invoice ${invoice.reference}`" />

    <GeminiConsole :title="`Invoice · ${invoice.period}`">
        <!-- M95 §3: the shared back control, in words, where the bare round arrow was. -->
        <template #lead>
            <BackLink href="/billing" label="Back to billing" />
        </template>

        <!--
          The PDF is a READ of an immutable record, so every viewer who can
          open this screen can take it: an invoice's lines, total and reference
          never change, and a correction is a credit note of its own.
        -->
        <template #actions>
            <a
                :href="`/billing/invoices/${invoice.id}/pdf`"
                class="btn-outline-sm"
                title="Download this invoice as a PDF, with its credit notes."
            >
                <BoardIcon name="export" :stroke="1.8" />
                <span>Download PDF</span>
            </a>
        </template>


        <div class="invoice-head">
            <div class="invoice-card">
                <div class="inv-top">
                    <div>
                        <!--
                          The client's name links to their record. A reader of
                          an invoice is one question away from wanting the
                          client behind it.
                        -->
                        <Link :href="invoice.clientHref" class="inv-name">{{ invoice.client }}</Link>
                        <div class="inv-sub">{{ invoice.periodLabel }}</div>
                    </div>
                    <!--
                      The status pill's inline style is the board's own, with its
                      colours as tokens. The label is --success-700, the signed-off
                      success TEXT colour, rather than the board's --success-600 —
                      3.30:1 on white, below the text threshold (tokens.css).
                    -->
                    <div
                        v-if="invoice.status === 'paid'"
                        style="
                            font-size: 10.5px;
                            font-weight: 700;
                            color: var(--success-700);
                            background: var(--success-100);
                            padding: 5px 11px;
                            border-radius: 20px;
                        "
                    >
                        {{ invoice.statusLabel }}
                    </div>
                    <div
                        v-else
                        style="
                            font-size: 10.5px;
                            font-weight: 700;
                            color: var(--amber-700);
                            background: var(--amber-100);
                            padding: 5px 11px;
                            border-radius: 20px;
                        "
                    >
                        {{ invoice.statusLabel }}
                    </div>
                </div>
                <div class="inv-amount">{{ invoice.amount }}</div>
                <div class="inv-amount-lbl">{{ invoice.settlement }}</div>
            </div>

            <!--
              M96 levels. A role that may never resend or never credit has neither button (level 3), and when neither
              is theirs the stack is not drawn. A resend with nobody to mail is blocked for now (level 2): greyed,
              and the server's reason under it.
            -->
            <div v-if="canResend || canCredit" class="action-stack">
                <button
                    v-if="canResend"
                    type="button"
                    class="stack-btn outline"
                    :disabled="resendBlockedBy !== null || resendForm.processing"
                    title="Email this invoice to the estate accounts that can open its billing, and nobody else."
                    @click="resend"
                >
                    <BoardIcon name="broadcast" :stroke="1.7" />
                    <span>{{ resendForm.processing ? 'Sending…' : 'Resend to client' }}</span>
                </button>
                <p v-if="canResend && resendRefusal" class="inv-why">{{ resendRefusal }}</p>
                <button
                    v-if="canCredit"
                    type="button"
                    class="stack-btn outline"
                    title="Issue a credit note against this invoice. The invoice itself keeps its total."
                    @click="openCredit"
                >
                    <BoardIcon name="pencil" :stroke="1.6" />
                    <span>Issue credit note</span>
                </button>
            </div>
        </div>

        <ConsoleDialog
            :open="askingResend"
            :title="resendTitle"
            confirm-label="Resend invoice"
            busy-label="Sending…"
            :busy="resendForm.processing"
            :error="resendForm.errors.invoice ?? null"
            @confirm="postResend"
            @cancel="keepInvoice"
        >
            <p style="margin: 0 0 6px">To {{ listed(resendTo) }}, the estate accounts that can open its billing.</p>
            <p style="margin: 0">Nobody else is told.</p>
        </ConsoleDialog>

        <!--
          AUTHORED. The board draws an invoice nobody is correcting, so it has
          no panel, no credit-note list and no send log. All three are things
          this screen has to be able to say.
        -->
        <ConsoleDialog
            :open="crediting && canCredit"
            :title="`Issue a credit note against ${invoice.reference}`"
            size="form"
            confirm-label="Issue credit note"
            busy-label="Issuing…"
            :busy="creditForm.processing"
            :dirty="creditForm.isDirty"
            discard-title="Discard this credit note?"
            :confirm-blocked-reason="creditBlocked"
            :error="creditForm.errors.invoice ?? null"
            @confirm="submitCredit"
            @cancel="crediting = false"
        >
            <p class="inv-head" style="margin: 0 0 12px">
                A credit note is a posted record of its own. This invoice keeps its total: what it is worth now is that
                total less its credit notes, and both are shown rather than one netted figure.
            </p>

            <RequiredMark legend />

            <div class="inv-fields">
                <div class="inv-field">
                    <label for="cn-amount">Amount to credit, J$ <RequiredMark /></label>
                    <input id="cn-amount" v-model="creditForm.amount" type="text" inputmode="decimal" required placeholder="e.g. 6200.50" />
                    <div v-if="creditForm.errors.amount" class="inv-error">{{ creditForm.errors.amount }}</div>
                </div>
                <div class="inv-field inv-field--wide">
                    <label for="cn-reason">Reason <RequiredMark /></label>
                    <input id="cn-reason" v-model="creditForm.reason" type="text" required maxlength="300" placeholder="e.g. Two shifts billed that were not worked in October" aria-describedby="cn-reason-hint" />
                    <div id="cn-reason-hint" class="inv-hint">Say why, in words someone can follow a year later.</div>
                    <div v-if="creditForm.errors.reason" class="inv-error">{{ creditForm.errors.reason }}</div>
                </div>
            </div>
        </ConsoleDialog>

        <div v-if="notes.length" class="inv-panel">
            <div class="inv-head">
                Credit notes: {{ invoice.amount }} invoiced, {{ money(credited_minor) }} credited,
                {{ money((invoice.total_minor ?? 0) - credited_minor) }} payable.
            </div>

            <div v-for="note in notes" :key="note.reference" class="inv-row">
                <span><b>{{ note.reference }}</b> · {{ note.reason }}</span>
                <span class="inv-row-meta">
                    −{{ money(note.amount_minor) }} · {{ note.issued_on }} · {{ note.issued_by }}
                </span>
            </div>
        </div>

        <div v-if="sends.length" class="inv-panel">
            <div class="inv-head">Sent to the client</div>
            <div v-for="(send, i) in sends" :key="i" class="inv-row">
                <span>{{ send.recipients }}</span>
                <span class="inv-row-meta">{{ send.at }} · {{ send.by }}</span>
            </div>
        </div>

        <div style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden">
            <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="2" />

            <!--
              A raised invoice with no lines. Not an error and not empty-because
              -filtered: it is an invoice whose breakdown was never recorded,
              and the posted total still stands.
            -->
            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No line breakdown on this invoice"
                body="The posted total stands and is what the client owes. This invoice was raised without an itemised breakdown, so there is nothing to take apart here."
            />

            <template v-else>
                <div v-for="(line, i) in invoice.lines" :key="i" class="line-item-row">
                    <div class="li-desc">
                        <div class="li1">{{ line.description }}</div>
                        <div class="li2">{{ line.detail }}</div>
                    </div>
                    <div class="li-amt">{{ line.amount }}</div>
                </div>

                <!--
                  "Total due" only while nothing has been credited (B080). Once a
                  credit note exists this figure is what was invoiced, and what is
                  payable is the credit-note panel's — "Total due $166,500.00" under
                  "$0.00 payable" would be two answers to one question.
                -->
                <!-- And not on a paid invoice either (M98, seen on the local dev server): nothing is due on it. -->
                <div class="line-total">
                    <span>{{ credited_minor > 0 || invoice.status === 'paid' ? 'Invoice total' : 'Total due' }}</span>
                    <span>{{ invoice.amount }}</span>
                </div>
            </template>
        </div>

        <!--
          Shown only when the breakdown disagrees with the posted total. See
          the class docblock: this is a finding, not something to resolve by
          adjusting one of the two numbers.
        -->
        <div v-if="invoice.reconciliation" class="warn-banner">
            <BoardIcon name="warning" :stroke="1.7" />
            <div>
                <div class="wb1">This breakdown does not reconcile</div>
                <div class="wb2">{{ invoice.reconciliation }}</div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws the back chevron, the two stack buttons, the download
 * control and the client name as <div>s. Here they are a link, buttons and a
 * link, so the UA's underline and the button's own border, face and font would
 * show through. .stack-btn, .btn-outline-sm and .inv-name state everything
 * else.
 */
a.inv-name {
    text-decoration: none;
    color: inherit;
}

button.stack-btn,
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

/*
 * `border` is deliberately not reset anywhere here. The board gives both
 * .stack-btn.outline and .btn-outline-sm their own borders, and resetting the
 * second out-specified the board and stripped a 1.5px navy outline off the
 * topbar control — a real defect that passed measurement because 1.5px on one
 * small button is well under the threshold.
 */

/* Greyed means greyed (Law 1), as the shared modal greys its own button. */
button.stack-btn[disabled],
button.btn-outline-sm[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

a.btn-outline-sm {
    text-decoration: none;
}

button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
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
 * AUTHORED BELOW THIS LINE. The board draws an invoice nobody is correcting, so
 * it has no panel, no credit-note list and no send log. What a press did is the shell's toast, and a refusal of
 * Resend or of a credit note is said inside its own question.
 */
.inv-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.inv-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 780px;
}

.inv-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}

.inv-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.inv-field--wide {
    grid-column: span 2;
}

.inv-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.inv-field input {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

/* What a field is about, one quiet line under it (not in its label). */
.inv-hint {
    font-size: 11px;
    line-height: 1.5;
    color: var(--slate-600);
}

.inv-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

/* In the modal on a phone, the fields one under another. */
@media (max-width: 640px) {
    .inv-fields {
        grid-template-columns: 1fr;
    }

    .inv-field--wide {
        grid-column: auto;
    }
}

/* Why a greyed control cannot act yet, under it, readable without hovering (M96 level 2). */
.inv-why {
    margin: 0;
    max-width: 260px;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.45;
    color: var(--slate-600);
}

.inv-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 14px;
    font-size: 11.5px;
    color: var(--navy-900);
    line-height: 1.6;
    border-top: 1px solid var(--navy-100);
    padding-top: 7px;
}

.inv-row-meta {
    font-size: 10.5px;
    color: var(--slate-500);
    white-space: nowrap;
}

/* ON A PHONE (batch 1, item 12): a credit note's words and its figures one above the other, the figures free to wrap. */
@media (max-width: 640px) {
    .inv-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
    }

    .inv-row-meta {
        white-space: normal;
    }
}
</style>
