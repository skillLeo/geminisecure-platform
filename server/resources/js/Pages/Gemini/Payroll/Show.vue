<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import BackLink from '../../../Components/BackLink.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Payslip detail — board screen super-admin-29.
 *
 * The board's page body is one table and nothing else, so that is what this
 * renders. Two facts the board has no room for ride on the cells that own them
 * rather than on rows of their own:
 *
 *   why PAYE is zero        on the PAYE cell
 *   why a row is flagged    on the row
 *
 * Both belong on this screen — a bare 0.00 reads as a defect to the person
 * holding the payslip — and a row apiece would cost a second line per payslip
 * that the approved design does not have.
 *
 * The red row background is the board's own inline style, copied verbatim,
 * because the board's stylesheet defines no class for it.
 *
 * "APPROVE & DISBURSE" IS LIVE. It was permanently disabled while the statutory
 * rates were a draft (D-021); the client ruled on Q-002 (D-082) and unblocked
 * payroll. The server decides whether this viewer may approve and says why not
 * when they may not, and the first live run asks for the acknowledgement the
 * ruling requires — a tick naming the card, recorded on the run.
 */
const props = defineProps({
    run: { type: Object, required: true },
    payslips: { type: Array, required: true },
    filters: { type: Object, required: true },
    /** `reason` is null when this viewer may approve; `acknowledgement` is Part F of the ruling. */
    approval: { type: Object, required: true },
    /**
     * Whether this role may open a guard's record.
     *
     * The roster is a separate module with a separate permission, and a role
     * can hold payroll without it. Where it does not, the employee cell is the
     * plain cell the board draws rather than a link that lands on a 403.
     */
    canOpenGuards: { type: Boolean, required: true },
    /** The run's payslips as the shared table reads them (M32 F5): by client, then guard, a page at a time. */
    table: { type: Object, required: true },
})

/* Every heading sorts the whole run on the server (M32 F5); the figures keep the board's numeric cell. */
const COLUMNS = [
    { key: 'employee', label: 'Employee', sortable: true },
    { key: 'client', label: 'Client', sortable: true },
    { key: 'gross', label: 'Gross', sortable: true, cellClass: 'num-cell' },
    { key: 'nis', label: 'NIS', sortable: true, cellClass: 'num-cell' },
    { key: 'nht', label: 'NHT', sortable: true, cellClass: 'num-cell' },
    { key: 'education_tax', label: 'Ed. Tax', sortable: true, cellClass: 'num-cell' },
    { key: 'paye', label: 'PAYE', sortable: true, cellClass: 'num-cell' },
    { key: 'net', label: 'Net', sortable: true, cellClass: 'num-cell' },
]

/*
 * The board's flagged row, and why the row is flagged, on the row that owns them. Amber, not the board's red (the
 * universal order, rule 20: red is for panic and life safety; a flagged payslip is an ordinary warning).
 */
const slipAttrs = (slip) => ({
    style: slip.exception ? 'background:var(--amber-100);' : undefined,
    title: slip.exception_reason || undefined,
})

/* The five states (rule 19): this page drew only its populated and its two empty ones. */
const state = useScreenState({
    rows: () => props.payslips.length,
    filtered: () => !!props.filters.q,
})

const retry = () => router.reload({ preserveScroll: true })

/*
 * EACH CONTROL AT ITS LEVEL (M96; the payroll pass). A role that never approves a guard run has no Approve at all
 * (level 3), and nor has a run already approved: its badge says so. For an approver barred for now (level 2), the
 * reason is on the screen beside the greyed button, never only on hover.
 */
const showsApprove = computed(() => props.approval.holds !== false && !['approved', 'paid'].includes(props.run.status))

const approveWhy = computed(() => {
    if (props.approval.reason) {
        return props.approval.reason
    }

    return props.approval.acknowledgement.required && !reconciled.value
        ? 'This is the first live guard pay run. Tick the reconciliation acknowledgement first.'
        : null
})

const clearSearch = () => router.get(`/payroll/${props.run.id}`, {}, { preserveScroll: true })

const reconciled = ref(false)

/*
 * What the press did, or why it was refused (B018). The server's own sentences: `flash.success` after an approval, the
 * shell's toast; `errors.run` when the approval service refused it, said inside the question that asked (below). Both
 * used to be lost: the success under a session key nothing shares, the refusal only in the button's hover title.
 */

/** Why the control will not act, or undefined when it will. */
const approveTitle = computed(() => {
    if (props.approval.reason) {
        return props.approval.reason
    }

    if (props.approval.acknowledgement.required && !reconciled.value) {
        return 'This is the first live guard pay run. Tick the reconciliation acknowledgement first.'
    }

    return 'Approve this run. The transfer itself is made from Gemini’s bank.'
})

/*
 * APPROVING TELLS EVERY GUARD ON THE RUN, SO IT ASKS FIRST (DR-073, the register's Part 7): the run and its net
 * (`run.net`), and how many guards are pushed "Payslip ready" (`run.told_count`, the push's own count). Only the
 * question's button approves, with the reconciliation tick as it was given.
 */
const approveBlocked = computed(() => props.approval.reason !== null || (props.approval.acknowledgement.required && !reconciled.value))
const askingApprove = ref(false)
const approving = ref(false)

/* A refusal is said inside the question that asked (M95; Hassam, 12 October): in its amber band, never a toast and never
 * a banner on the page, and the question stays open with the server's sentence until it is fixed or put away. */
const approveError = ref(null)

const toldLine = computed(() => {
    const n = props.run.told_count

    if (n === null || n === undefined) {
        return 'Every guard on it is told in the Guard App that a payslip is ready.'
    }

    return n === 0 ? 'No guard is told in the app.' : `${n} ${n === 1 ? 'guard is' : 'guards are'} told in the Guard App that a payslip is ready.`
})

const approve = () => {
    if (approveBlocked.value || approving.value) {
        return
    }

    approveError.value = null
    askingApprove.value = true
}

const postApprove = () => {
    if (approveBlocked.value || approving.value) {
        return
    }

    approving.value = true
    router.post(`/payroll/${props.run.id}/approve`, { reconciled: reconciled.value }, {
        preserveScroll: true,
        onSuccess: () => {
            askingApprove.value = false
        },
        onError: (errors) => {
            approveError.value = errors.run ?? Object.values(errors)[0] ?? 'The run was not approved.'
        },
        onFinish: () => {
            approving.value = false
        },
    })
}

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['payroll'] })
</script>

<template>
    <Head :title="`${run.period} pay run`" />

    <!--
      No search field. The board draws none in this topbar — it draws a back
      chevron, the title, and "Approve & disburse" — so none is rendered. The
      table's column sort is still live and is how this screen is narrowed.
    -->
    <GeminiConsole :title="`${run.period} pay run`">
        <!--
          The way back, in words (M95 §3): it was a bare chevron with its words only on hover. The shared control,
          placed in the lead slot because the Gemini console's shell takes no `back` (the payroll sweep, 11 Oct).
        -->
        <template #lead>
            <BackLink href="/payroll" label="Back to pay runs" />
        </template>

        <template #actions>
            <!--
              Part F of the Q-002 ruling: the first live run asks the approver to
              confirm it was reconciled against current TAJ tables, naming the
              card. Drawn only while it is required; the server refuses the
              approval without it either way.
            -->
            <label
                v-if="showsApprove && approval.acknowledgement.required && !approval.reason"
                style="display: flex; align-items: center; gap: 6px; max-width: 360px; font-size: 11px; color: var(--slate-600); line-height: 1.35"
            >
                <input v-model="reconciled" type="checkbox" required />
                <span>{{ approval.acknowledgement.statement }}<RequiredMark /></span>
            </label>

            <button
                v-if="showsApprove"
                type="button"
                class="btn-approve-sm"
                :disabled="approveBlocked || approving"
                :title="approveTitle"
                @click="approve"
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
                <span>Approve &amp; disburse</span>
            </button>
        </template>

        <ConsoleDialog
            :open="askingApprove"
            :title="`Approve the ${run.period} pay run, ${run.net} net?`"
            confirm-label="Approve & disburse"
            busy-label="Approving…"
            :busy="approving"
            :error="approveError"
            @confirm="postApprove"
            @cancel="askingApprove = false"
        >
            <p style="margin: 0 0 6px">{{ toldLine }}</p>
            <p style="margin: 0 0 6px">The transfer itself is made from Gemini's bank; this records the decision.</p>
            <p style="margin: 0">Nobody else is told.</p>
        </ConsoleDialog>

        <!-- The run's state at the top (M96 §5), and why Approve is greyed, on the screen (M96 level 2). -->
        <div v-if="!state.isLoading.value && !state.isDenied.value && !state.isError.value" class="pr-head">
            <div class="status-badge" :class="run.badge_class">{{ run.badge_label }}</div>
            <p v-if="showsApprove && approveWhy" class="pr-why">{{ approveWhy }}</p>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="8" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Your role does not reach payroll and accounting"
            body="Ask a platform administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This pay run could not be read"
            body="Nothing was approved or changed. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            :title="`Nobody on this run matches “${filters.q}”`"
            body="Every other payslip is still on the run. Clear the search to see them all."
            action-label="Clear search"
            @action="clearSearch"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This run has no payslips"
            body="A run is calculated from the guards on the roster for its period. Until it is calculated there is nothing here to review."
        />

        <DataTable v-else :columns="COLUMNS" :rows="payslips" :table="table" noun="payslips" :row-attrs="slipAttrs">
            <template #cell-employee="{ row: slip }">
                <component
                    :is="canOpenGuards ? Link : 'div'"
                    class="res-cell"
                    :href="canOpenGuards ? `/guards/${slip.guard_id}` : undefined"
                >
                    <div class="res-avatar">{{ slip.initials }}</div>
                    <div class="res-name">{{ slip.employee }}</div>
                </component>
            </template>
            <template #cell-client="{ row: slip }">{{ slip.estate }}</template>
            <template #cell-paye="{ row: slip }">
                <span :title="slip.paye_note || undefined">{{ slip.paye }}</span>
            </template>
        </DataTable>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS here, and it takes a browser default back off rather
 * than adding a style of its own.
 *
 * The board draws the employee cell as a <div>. It is a link to that guard's
 * record here, so the UA's link colour and underline would otherwise show
 * through and change the pixels. .res-cell and .res-name state everything
 * else — layout, size, weight — so nothing below restates any of it.
 */
a.res-cell {
    color: inherit;
    text-decoration: none;
}

/*
 * The back chevron. The board draws it as an inline-styled <div> because its
 * stylesheet has no class for it; those exact declarations are reproduced
 * here on a real <a> instead, so the control can be clicked, focused and
 * opened in a new tab.
 */
.topbar-back {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.topbar-back svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
}

/* The board's button is a <div>; as a real <button> it arrives with a border,
 * a system font and buttonface grey. .btn-approve-sm supplies the rest. */
button.btn-approve-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

/* Greyed when it cannot act, with the reason on the screen beside it (M96 level 2): it looked live while it refused. */
button.btn-approve-sm[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}

/* The run's state, and why Approve waits, before the payslips (M96 §5 and level 2). */
.pr-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin: 0 0 12px;
}

.pr-why {
    margin: 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--slate-600);
    text-align: right;
}
</style>
