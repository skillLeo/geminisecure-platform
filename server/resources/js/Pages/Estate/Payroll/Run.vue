<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import DataTable from '../../../Components/DataTable.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { toast } from '../../../lib/toasts.js'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Pay run approval — board screen community-admin-15.
 *
 * THE FOUR SUMMARY BOXES AND THE SEVEN COLUMNS ARE THE SAME FIGURES TWICE, AND
 * THE WHOLE POINT OF THIS SCREEN IS THAT THEY AGREE. `Payroll::runBoard()` sums
 * both from the payslip lines rather than reading the run header for one and the
 * lines for the other — that would make them agree by construction and prove
 * nothing. `EstatePayrollTest` then re-sums the same lines in raw SQL and checks
 * the header against them, so a run whose stored total drifted from its own
 * payslips fails a test rather than being read by a committee.
 *
 * THE PAYE COLUMN WILL NOT MATCH THE BOARD, AND THE CLIENT HAS RULED WHY.
 * Board 15 draws PAYE as 25% of gross less all three other deductions, charged
 * on the whole once TAJ's FORTNIGHTLY threshold is passed. The ruling on Q-002
 * (D-082): PAYE is 25% of the amount above the MONTHLY threshold, a band on the
 * excess — the board's samples were wrong and this calculator right. Board 15
 * would withhold J$85,392 a month where the ruling asks J$5,230. Boards 13, 15
 * and 16 need redrawing; they have not been touched.
 *
 * "APPROVE & SUBMIT FOR PAYMENT" IS THE IRREVERSIBLE ONE. The server decides
 * whether this viewer may press it and says why not — the role does not hold
 * approval, the viewer calculated this run (the one act the two-person rule
 * counts, D-233), or its rate card is unverified — and each is a different
 * person's problem, so a greyed button with no sentence would send somebody to
 * the wrong one. On the estate's FIRST live run it also asks for
 * the acknowledgement Part F of the ruling requires: a tick naming the card the
 * run was reconciled against, which the server refuses the approval without.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    run: { type: Object, required: true },
    summary: { type: Array, required: true },
    lines: { type: Array, required: true },
    /** The payslips as the shared table reads them (M32 F5). */
    table: { type: Object, required: true },
    canApprove: { type: Boolean, required: true },
    approvalReason: { type: String, default: null },
    /** M96 §1: whether this viewer's role ever approves a pay run. A role that never does sees no Approve button. */
    holdsApproval: { type: Boolean, default: false },
    /**
     * Who approves this run and what stops them, for every viewer before any press, in Hassam's three cases (12 Oct
     * 2026), one line each ("\n").
     */
    approvalNext: { type: String, default: null },
    /** Any other refusal an approver meets (a rate card, a pension account), said under it. */
    approvalBlock: { type: String, default: null },
    /** Request changes, like Calculate, needs Payroll update; a role without it sees no such button (M96 level 3). */
    canRequestChanges: { type: Boolean, default: false },
    /** Each exception's decision, by name, for the approver to weigh (D-233): shown, never counted against them. */
    decisions: { type: Array, default: () => [] },
    /** Part F of the Q-002 ruling — `required` on the estate's first live run. */
    acknowledgement: { type: Object, required: true },
    /** The two files a run leaves in, and what each is for. */
    exportFormats: { type: Array, required: true },
    canExport: { type: Boolean, required: true },
    exportBlockedReason: { type: String, required: true },
    /** Every file this run has left in, as kept for seven years (13 A2). */
    keptFiles: { type: Array, default: () => [] },
})

useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

// The run approved or its lines changed in another console re-reads this page by itself (M18H §7.2).
useLiveReload({ entities: ['staff'] })

const page = usePage()

const state = useScreenState({
    rows: () => props.lines.length,
})

/*
 * A REFUSED DOWNLOAD IS SAID, NEVER SILENT (the payroll audit, 11 Oct). A file is a plain link, not a visit, so a refusal
 * comes back as this page with the server's sentence in `errors`, which nothing on the page drew: the press read as
 * dead. It is a toast that stays (M95 §2).
 */
onMounted(() => {
    const refused = page.props.errors?.format

    if (refused) {
        toast(refused, { tone: 'error' })
    }
})

/* No control on the page at all (M96 level 3): a badge says so, beside the line saying who approves (Hassam, 12 Oct). */
const viewOnly = computed(() => !props.holdsApproval && !(props.canRequestChanges && props.run.status === 'calculated'))

/* A read that failed is read again in place; nothing on this screen writes by reloading. */
const retry = () => router.reload({ preserveScroll: true })

/* Export and its reason belong to a run on the screen, never above a loading, refused or failed page. */
const showsRun = computed(() => !state.isLoading.value && !state.isDenied.value && !state.isError.value)

/* Every figure is sortable over the whole run (M32 F5); the name and the role by the person. */
const LINE_COLUMNS = [
    { key: 'name', label: 'Employee', sortable: true },
    { key: 'gross', label: 'Gross', sortable: true, cellClass: 'num-cell' },
    { key: 'nis', label: 'NIS', sortable: true, cellClass: 'num-cell' },
    { key: 'nht', label: 'NHT', sortable: true, cellClass: 'num-cell' },
    { key: 'education_tax', label: 'Ed. Tax', sortable: true, cellClass: 'num-cell' },
    { key: 'paye', label: 'PAYE', sortable: true, cellClass: 'num-cell' },
    { key: 'net', label: 'Net', sortable: true, cellClass: 'num-cell' },
]

const base = computed(() => page.url.split('/payroll')[0])
const changes = ref('')
const asking = ref(false)

/* ------------------------------------------------------------------ */
/* the two files a run leaves in (12 §1) */
/* ------------------------------------------------------------------ */

/*
 * CHOSEN, NEVER DEFAULTED. A credit file for the bank and a summary for the
 * accountant are different documents — one is an instruction to move money and
 * carries account numbers, the other a record of what was paid and carries
 * deductions — so the control opens a choice rather than guessing.
 */
const choosingFormat = ref(false)

/* In the console's modal since the M98 sweep (M95 §1): it was a panel that opened under the header. */
const chosenFormat = ref(null)

const openExport = () => {
    chosenFormat.value = null
    choosingFormat.value = true
}

const download = () => {
    const format = props.exportFormats.find((each) => each.key === chosenFormat.value)

    if (!format) {
        return
    }

    choosingFormat.value = false
    window.location.assign(formatHref(format))
}

/*
 * NOTHING BUT AN APPROVED RUN LEAVES. A file built from a draft is an
 * instruction to pay figures the committee has not seen. The server refuses it
 * too; this says so before the press rather than after it.
 */
const exportBlockedBy = computed(() => {
    if (!props.canExport) {
        return props.exportBlockedReason
    }

    return props.run.status === 'paid'
        ? null
        : `${props.run.period} has not been approved yet.`
})

const formatHref = (format) => `${base.value}/payroll/runs/${props.run.slug}/export?format=${format.key}`

/**
 * To the cent, the summary boxes too (batch 1, Residents' finding, 12 Oct 2026). The board wrote whole dollars and the
 * boxes rounded, so the run's net pay read a dollar off the bank file's total and the payslips beneath it: a rounded
 * total is a run that does not add up.
 */
const exact = (minor) =>
    `$${(minor / 100).toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const box = (entry) => (entry.value_minor === undefined ? entry.value : exact(entry.value_minor))

const reconciled = ref(false)

/** Why the approve control will not act, or undefined when it will. */
const approveTitle = computed(() => {
    if (!props.canApprove) {
        return props.approvalReason ?? undefined
    }

    if (props.acknowledgement.required && !reconciled.value) {
        return 'This is the estate’s first live pay run. Tick the reconciliation acknowledgement first.'
    }

    return undefined
})

/*
 * ONE PRESS, ONE DISBURSEMENT. Approving posts the whole run — salaries, 2100 and
 * the bank — and the ledger cannot unpost it. A second click while the first was
 * still in flight used to reach the server as a second approval; the server now
 * refuses it, and the control is inert until the first has answered.
 */
const approving = ref(false)

const approve = () => {
    if (approving.value) {
        return
    }

    router.post(
        `${base.value}/payroll/runs/${props.run.slug}/approve`,
        { reconciled: reconciled.value },
        {
            preserveScroll: true,
            onStart: () => {
                approving.value = true
            },
            // A refusal is a toast that stays (M95 §2), where it was a banner pushed into the page.
            onError: (errors) => toast(errors.run ?? Object.values(errors)[0] ?? 'The run was not approved.', { tone: 'error' }),
            onFinish: () => {
                approving.value = false
            },
        },
    )
}

/* Request changes, in the console's modal (M95 §1): it was a box that opened below the payslips. */
const sending = ref(false)
const changesError = ref(null)

const openChanges = () => {
    changesError.value = null
    asking.value = true
}

const requestChanges = () => {
    router.post(
        `${base.value}/payroll/runs/${props.run.slug}/changes`,
        { reason: changes.value },
        {
            preserveScroll: true,
            onStart: () => {
                sending.value = true
            },
            onSuccess: () => {
                changes.value = ''
                asking.value = false
            },
            onError: (errors) => {
                changesError.value = errors.reason ?? errors.run ?? Object.values(errors)[0] ?? null
            },
            onFinish: () => {
                sending.value = false
            },
        },
    )
}
</script>

<template>
    <Head :title="`${run.period} Pay Run`" />

    <EstateConsole :title="`${run.period} Pay Run`" :estate-name="estate.name" active="payroll" :back="{ href: `${base}/payroll`, label: 'Back to pay runs' }">
        <template #actions>
            <!-- M96 level 3: a role that may never download a payroll file has no Export (the payroll audit, 11 Oct). -->
            <button
                v-if="canExport && showsRun"
                type="button"
                class="btn-outline-sm"
                :disabled="exportBlockedBy !== null"
                :title="exportBlockedBy ?? 'Download the accountant’s summary or the bank file.'"
                @click="openExport"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 3v13m0 0l-4-4m4 4l4-4M5 21h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span>Export</span>
            </button>
        </template>

        <!-- M96 level 2: why Export is greyed, on the screen under it, never only in its tooltip (the payroll audit). -->
        <p v-if="canExport && showsRun && exportBlockedBy !== null" class="export-why">{{ exportBlockedBy }}</p>

        <!--
          AUTHORED. The board draws a run nobody is exporting, so it has no
          panel. Each format says what is in it AND what is not — the whole
          reason there are two.
        -->
        <ConsoleDialog
            :open="choosingFormat && exportBlockedBy === null"
            :title="`Download the ${run.period} pay run`"
            confirm-label="Download"
            :confirm-blocked-reason="chosenFormat === null ? 'Choose one of the two files first.' : null"
            @confirm="download"
            @cancel="choosingFormat = false"
        >
            <div class="exp-head">
                What this file is for. A pay run leaves as one of two documents, and the estate records that it left,
                who took it and how many payslips were in it.
            </div>

            <label v-for="format in exportFormats" :key="format.key" class="exp-option">
                <input v-model="chosenFormat" type="radio" name="exp-format" :value="format.key" />
                <span>
                    <span class="exp-label">{{ format.label }}</span>
                    <span class="exp-detail">{{ format.description }}</span>
                </span>
            </label>

            <!--
              THE KEPT COPIES (13 A2). Every file this run has left in is kept
              before it leaves, byte for byte, for seven years — so the bank
              file that moved the money can be produced again in year three.
            -->
            <div v-if="keptFiles.length > 0" class="exp-kept">
                <div class="exp-head">Kept copies</div>
                <a
                    v-for="file in keptFiles"
                    :key="file.id"
                    :href="`${base}/documents/${file.id}`"
                    class="exp-kept-row"
                    :title="file.status_line"
                >
                    <span class="exp-label">{{ file.kind_label }}</span>
                    <span class="exp-detail">Issued {{ file.issued_at }} · kept until {{ file.retain_until }}</span>
                </a>
            </div>
        </ConsoleDialog>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="7" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="A pay run shows what each person earns and what is withheld from their pay, so it opens only to a role that holds Payroll."
        />

        <!-- The fifth state (the universal order, rule 19): a read that failed says so, and that trying again is safe. -->
        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This pay run could not be read"
            body="Nothing was approved or changed. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This run has not been calculated"
            body="No payslips have been worked out yet. Resolve the run's exceptions, then calculate it."
        />

        <template v-else>
            <!--
              AUTHORED: what the server refused, and why. An approval refused on
              the server — a run another tab has already approved, a sentence the
              control could not know about — was answered in silence, which reads
              as a click that did nothing.
            -->

            <div class="approval-banner">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="9" cy="8" r="3.4" stroke="currentColor" stroke-width="1.7" />
                    <path d="M3 20c0-3.3 2.7-5.4 6-5.4s6 2.1 6 5.4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    <path d="M17 11a3 3 0 1 0 0-6M18 20c0-2.6-1-4.3-2.6-5.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                </svg>
                <div>
                    <div class="ab1">
                        <template v-if="run.status === 'paid'">
                            Approved by {{ run.approved_by }} on {{ run.approved_at }}
                        </template>
                        <!-- Who calculated it too, where that is somebody else (B025): neither may approve it. -->
                        <!-- Never "awaiting your approval" to somebody who may not approve it (M53 §15.2): one banner, one truth. -->
                        <template v-else>
                            <!-- Who calculated it is said even when they started it too: it is the one act the two-person rule counts (D-233). -->
                            <template v-if="run.calculated_by && run.calculated_by === run.prepared_by">Prepared and calculated by {{ run.calculated_by }}.</template>
                            <template v-else>Prepared by {{ run.prepared_by }}<template v-if="run.calculated_by">, calculated by {{ run.calculated_by }}</template>.</template>
                            <!-- Sent back with Request changes, it is not waiting for an approver: it waits to be calculated again. -->
                            <!-- "Waiting for approval": there is one approver, the Community Super Admin (the client's ruling). -->
                            {{ canApprove ? 'Awaiting your approval.' : run.sent_back_reason ? 'Sent back for changes.' : 'Waiting for approval.' }}
                        </template>
                    </div>
                    <div class="ab2">
                        <template v-if="run.status === 'paid'">
                            This run has been posted. A pay run is approved once; a correction is a later run.
                        </template>
                        <!-- What was asked when it was sent back, for whoever calculates it again (the way out's middle step). -->
                        <template v-else-if="run.sent_back_reason">
                            What needs changing: {{ run.sent_back_reason }}
                        </template>
                        <template v-else-if="canApprove">
                            Nothing is paid until you approve it.
                        </template>
                        <!-- Who calculated it and who must approve it is said with the controls, where every role looks (the client, 12 Oct). -->
                        <template v-else>
                            Nothing is paid until it is approved.
                        </template>
                    </div>
                </div>
            </div>

            <div class="sum-row">
                <div v-for="entry in summary" :key="entry.key" class="sum-box">
                    <div class="sv">{{ box(entry) }}</div>
                    <div class="sl">{{ entry.label }}</div>
                </div>
            </div>

            <!-- The payslips, as the shared table (M32 F5): the largest gross first unless a heading asks otherwise.
                 The boxes above are every line's, whichever page of them is open. -->
            <DataTable :columns="LINE_COLUMNS" :rows="lines" :table="table" noun="payslips">
                <template #cell-name="{ row: line }">
                    <div class="res-cell">
                        <div class="res-avatar">{{ line.initials }}</div>
                        <div>
                            <div class="res-name">{{ line.name }}</div>
                            <div class="res-sub">{{ line.role }}</div>
                        </div>
                    </div>
                </template>
                <template #cell-gross="{ row: line }">{{ exact(line.gross_minor) }}</template>
                <template #cell-nis="{ row: line }">{{ exact(line.nis_minor) }}</template>
                <template #cell-nht="{ row: line }">{{ exact(line.nht_minor) }}</template>
                <template #cell-education_tax="{ row: line }">{{ exact(line.education_tax_minor) }}</template>
                <!--
                  A nil PAYE carries the reason on it. `PayrollCalculator` writes the sentence naming the threshold,
                  because a bare 0.00 on a payslip reads as a defect to the person holding it.
                -->
                <template #cell-paye="{ row: line }"><span :title="line.paye_note ?? undefined">{{ exact(line.paye_minor) }}</span></template>
                <template #cell-net="{ row: line }">{{ exact(line.net_minor) }}</template>
            </DataTable>

            <ConsoleDialog
                :open="asking"
                :title="`Send ${run.period} back`"
                size="form"
                confirm-label="Send back"
                busy-label="Sending…"
                :busy="sending"
                :dirty="changes.trim() !== ''"
                discard-title="Discard your note?"
                :confirm-blocked-reason="changes.trim() === '' ? 'Say what is wrong first.' : null"
                :error="changesError"
                @confirm="requestChanges"
                @cancel="asking = false"
            >
                <div class="changes-box">
                    <RequiredMark legend />
                    <label for="changes-reason">What needs changing?<RequiredMark /></label>
                    <textarea
                        id="changes-reason"
                        v-model="changes"
                        rows="3"
                        required
                        placeholder="e.g. One payslip's overtime shows 22 hours where the timesheet says 12."
                    ></textarea>
                </div>
            </ConsoleDialog>

            <!--
              WHAT WAS DECIDED ON THE RUN (D-233; Hassam, 12 Oct): each exception's decision, by name, for the approver
              to weigh. Settling one used to bar whoever did it from approving; now it is shown, never counted.
            -->
            <div v-if="decisions.length > 0" class="run-decisions">
                <div class="rd-head">Decided on this run’s exceptions</div>
                <p v-for="decision in decisions" :key="decision" class="rd-line">{{ decision }}</p>
            </div>

            <label v-if="run.status !== 'paid' && acknowledgement.required && canApprove" class="reconcile-ack">
                <input v-model="reconciled" type="checkbox" required />
                <span>{{ acknowledgement.statement }}<RequiredMark /></span>
            </label>

            <!--
              M96: each control at its level. Request changes and Approve are drawn only for a role that can ever press
              them (level 3 is no button). An approver barred from THIS run sees Approve greyed, with the reason and the
              way out on the screen beside it (level 2; M94 §1.1), never only in a tooltip.

              The first live run's greyed Approve says its reason on the screen too. A run already sent back has no
              Request changes: it has been asked (level 3), and the banner says so.

              WHO APPROVES AND WHAT STOPS THEM, BEFORE ANYBODY PRESSES ANYTHING (Hassam, 12 Oct 2026): one of his three
              cases for every viewer, line by line ("Only the Community Super Admin approves a pay run. Dahlia Reid can
              approve this one." / "Dahlia Reid calculated this run." ... / "... That is Dahlia Reid."), and under it
              any other refusal an approver meets. A role with no control at all has the View only badge beside it:
              never a page with the table and nothing else, which the client read as broken.
            -->
            <div v-if="run.status !== 'paid' && (approvalNext || approvalBlock || viewOnly)" class="approve-say">
                <span v-if="viewOnly" class="view-badge">View only</span>
                <p v-if="approvalNext" class="approve-rule">{{ approvalNext }}</p>
                <p v-if="approvalBlock" class="approve-why">{{ approvalBlock }}</p>
                <p v-if="canApprove && acknowledgement.required && !reconciled" class="approve-why">{{ approveTitle }}</p>
            </div>
            <div v-if="run.status !== 'paid' && ((canRequestChanges && run.status === 'calculated') || holdsApproval)" class="approval-btns">
                <button v-if="canRequestChanges && run.status === 'calculated'" type="button" class="btn-outline-sm" @click="openChanges">
                    <span>Request changes</span>
                </button>

                <button
                    v-if="holdsApproval"
                    type="button"
                    class="btn-amber-sm"
                    :disabled="!canApprove || (acknowledgement.required && !reconciled) || approving"
                    :title="approveTitle"
                    @click="approve"
                >
                    <svg viewBox="0 0 24 24" fill="none">
                        <polyline points="20 6 9 17 4 12" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>{{ approving ? 'Approving…' : 'Approve & submit for payment' }}</span>
                </button>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its export action and its two footer
 * controls as <div>s. `.btn-outline-sm`'s border IS its variant so it keeps it;
 * `.btn-amber-sm` declares a fill and no border, so only that one is reset —
 * see D-045, where a blanket rule rubbed out an outline the board did draw.
 */
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.btn-amber-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-outline-sm[disabled],
button.btn-amber-sm[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}

/* Why an approver cannot approve this run, and the way out, beside the greyed button (M94 §1.1, M96 level 2). */
.approve-why {
    margin: 14px 0 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--slate-600);
    text-align: right;
}

/* Who approves and what stops them, a shade stronger than anything under it, one line to each of its lines (Hassam). */
.approve-rule {
    margin: 14px 0 0;
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.5;
    color: var(--navy-800);
    text-align: right;
    white-space: pre-line;
}

.approve-rule + .approve-why {
    margin-top: 4px;
}

/* The line's block: the badge sits above it, at the right, where a button would be. */
.approve-say {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

/* Level 3's badge, where the buttons would be: the Settings pages' own. */
.view-badge {
    display: inline-flex;
    align-items: center;
    height: 30px;
    margin-top: 14px;
    padding: 0 12px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 700;
}

/* What was decided on the run's exceptions, for the approver to weigh (D-233): a plain list under the payslips. */
.run-decisions {
    margin: 16px 0 0;
    padding: 14px 16px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
}

.rd-head {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.3px;
    text-transform: uppercase;
    color: var(--slate-500);
}

.rd-line {
    margin: 6px 0 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--navy-900);
}

/* Why Export is greyed, under it at the top of the page (M96 level 2). */
.export-why {
    margin: 0 0 14px;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--slate-600);
    text-align: right;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws "Request changes" as a control with
 * nowhere to type, because a mock-up does not need somewhere to type. A reason
 * is required — the service refuses without one — so the field has to exist.
 * The export panel is authored for the same reason: two documents, chosen.
 */
.exp-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    margin-bottom: 10px;
}

/* Each file is a choice in the modal, its words beside its radio (M95 §1, the M98 sweep). */
.exp-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: var(--navy-100);
    border-radius: 11px;
    padding: 11px 14px;
    margin-bottom: 8px;
    cursor: pointer;
}

.exp-option input {
    margin: 3px 0 0;
    flex: 0 0 auto;
}

.exp-label {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.exp-detail {
    display: block;
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.55;
}

.exp-kept {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding-top: 4px;
}

.exp-kept-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 2px;
    border-top: 1px solid var(--navy-100);
    text-decoration: none;
}

/* Inside the console's modal since M95: the modal is the panel, so the box draws none of its own. */
.changes-box {
    margin-top: 4px;
}

.changes-box label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--slate-500);
    margin-bottom: 7px;
}

.changes-box textarea {
    width: 100%;
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    padding: 9px 11px;
    font-family: 'Inter', sans-serif;
    font-size: 12.5px;
    color: var(--navy-900);
    resize: vertical;
}

.changes-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 10px;
}

/*
 * Part F of the Q-002 ruling — the first-live-run acknowledgement. Authored,
 * because the board predates the ruling; drawn quietly above the two footer
 * controls, and only on the one run that needs it.
 */
.reconcile-ack {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 16px;
    font-size: 11.5px;
    line-height: 1.45;
    color: var(--slate-600);
}

.reconcile-ack input {
    margin-top: 2px;
}

/*
 * ON A PHONE (Residents' check at 390 px with the new shell, 12 Oct): the four totals stayed in one row and pushed the
 * page to 501 px. Two to a row below 640 px; doubled class to outrank the board's rule. The payslips scroll in their card.
 */
@media (max-width: 640px) {
    .sum-row.sum-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .sum-row > * {
        min-width: 0;
    }
}
</style>
