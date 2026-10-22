<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import EstateIcon from '../../../Components/EstateIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Chart of accounts — board screen community-admin-25.
 *
 * The structure every journal in this estate posts against, grouped by type
 * and ordered by code, which is what an accountant navigates by.
 *
 * NO BALANCE ON THIS SCREEN IS STORED. Each is the sum of the account's posted
 * lines, computed on read. A stored balance is a second copy of the ledger free
 * to drift from it — and the copy is the one a committee reads, so the drift is
 * invisible until an auditor arrives.
 *
 * INCOME AND EXPENSE ARE FOR THE PERIOD; EVERYTHING ELSE IS CUMULATIVE, and
 * that distinction is what the two kinds of account mean rather than a
 * presentation choice. A bank balance is everything that ever happened to it;
 * "Maintenance Fee Income" with no period stated is a number nobody can act on.
 * The board draws J$2,790,000 against maintenance income, which is exactly 450
 * units at J$6,200 — one month's assessment (D-042) — so the period is the
 * current month and the screen SAYS SO. The board leaves it implied; a figure
 * whose period a reader has to guess is the defect that surfaces at an audit.
 *
 * THE EQUITY GROUP IS NOT ON THE BOARD, and it has to be here. Total the twelve
 * accounts the board draws and they are out by J$8,556,719 — which is not an
 * error in the figures, it IS the members' accumulated fund. A chart of
 * accounts that hides an account from the accountant, and a trial balance that
 * can never agree, are both worse than a row the board did not draw. See D-041.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    groups: { type: Array, required: true },
    period: { type: String, required: true },
    /*
     * The day every balance is read as at — the estate's today, or the last day
     * of a month already over (bug hunt B079). An entry dated later, such as next
     * month's dues billed in advance, is not in any figure here yet.
     */
    asAt: { type: String, default: '' },
    kpis: { type: Array, required: true },
    tabs: { type: Array, required: true },
    /** Whether the viewer holds Accounting configure — the chart's own verb. */
    canConfigure: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    /** Every active account, for the parent picker, each with its type. */
    parents: { type: Array, required: true },

    /*
     * M10 §5. 1200 on this chart is the estate's total arrears and the tiles are
     * its cash, receivable, payables and reserve. For a reader who may not have
     * the estate's position the server sends every `balance_minor` as null, no
     * tiles, and this sentence. The codes, names and types stay — they are what
     * a bill is posted against — and the Balance column is not drawn.
     */
    positionWithheld: { type: Boolean, default: false },
    positionReason: { type: String, default: '' },
    /** M84 §3.1: whether an account opens to its entries (FEATURE_ACCOUNT_DRILLDOWN, and the right to the balances). */
    drilldown: { type: Boolean, default: false },
    /*
     * EST-S-07 (M85 A5): both sides of the balance sheet, assets and liabilities plus equity, each summed from its own
     * groups' rows; null while the position is withheld. Equity carries the surplus not yet closed to the fund as rows
     * marked `derived`, and every group carries its `total_minor`, so the chart visibly balances.
     */
    balance: { type: Object, default: null },
})

const page = usePage()

/*
 * ADDING AN ACCOUNT IS BUILT, WITH THE REVIEW STEP (12 §2, Wave 1). An account
 * changes what every future entry may post against and can be archived,
 * never removed, so the form reads the code, the name and the type back to
 * the person adding it and asks them to confirm before the press. The server
 * refuses the press without the confirmation too.
 *
 * IN THE CONSOLE'S MODAL SINCE M95 §1: it opened as a panel above the chart, which made the page longer and announced
 * nothing. Its errors sit under their own fields, and what was typed is never thrown away without asking.
 */
const adding = ref(false)

const TYPES = [
    { value: 'asset', label: 'Asset' },
    { value: 'liability', label: 'Liability' },
    { value: 'equity', label: 'Equity' },
    { value: 'income', label: 'Income' },
    { value: 'expense', label: 'Expense' },
]

const accountForm = useForm({
    code: '',
    name: '',
    type: 'expense',
    parent_id: '',
    is_bank_account: false,
    reviewed: false,
})

/** Only parents of the same type: an expense filed under an asset is a chart that lies. */
const parentsOfType = computed(() => props.parents.filter((parent) => parent.type === accountForm.type))

/** A type in words, never the stored word (M96 §2.3: no raw database words on a screen). */
const typeName = (value) => TYPES.find((type) => type.value === value)?.label ?? value

const typeLabel = computed(() => typeName(accountForm.type))

const review = computed(() => {
    if (accountForm.code.trim() === '' || accountForm.name.trim() === '') {
        return null
    }

    const parent = props.parents.find((p) => p.id === accountForm.parent_id)

    return `${accountForm.code.trim()} · ${accountForm.name.trim()}, ${typeLabel.value.toLowerCase()}${parent ? `, under ${parent.label}` : ''}${accountForm.is_bank_account ? ', money at a bank' : ''}. Every future entry may post to it. It can be archived and never removed.`
})

/** Why Add account in the modal cannot be pressed yet, said under it (M96 level 2). */
const addBlocked = computed(() => {
    if (review.value === null) {
        return 'Enter a code and a name first.'
    }

    return accountForm.reviewed ? null : 'Tick the review first.'
})

const openAdding = () => {
    if (!props.canConfigure) {
        return
    }

    accountForm.clearErrors()
    adding.value = true
}

const closeAdding = () => {
    adding.value = false
    accountForm.reset()
    accountForm.clearErrors()
}

const onTypeChange = () => {
    accountForm.parent_id = ''
    accountForm.reviewed = false

    if (accountForm.type !== 'asset') {
        accountForm.is_bank_account = false
    }
}

const submitAccount = () => {
    if (!props.canConfigure || !accountForm.reviewed || review.value === null) {
        return
    }

    accountForm.post(`${base.value}/accounting/chart-of-accounts`, {
        preserveScroll: true,
        onSuccess: () => closeAdding(),
    })
}

/*
 * ARCHIVING ASKS FIRST (M97 pass). It acted on one click: an account closed to every future entry, by a small word at
 * the end of a name, with nothing said. Now the console's dialog names the account and what archiving does. A refusal
 * is said inside it, in its amber band, and the dialog stays open (Hassam, 12 October: at 390 px a toast behind an open
 * modal is hidden); it closes only when the account is archived, or on Keep it open.
 */
const archiving = ref(null)
const archiveBusy = ref(false)
const archiveError = ref(null)

const archive = (row) => {
    if (!props.canConfigure || !row.is_active) {
        return
    }

    archiveError.value = null
    archiving.value = row
}

const keepOpen = () => {
    archiving.value = null
    archiveError.value = null
}

const confirmArchive = () => {
    const row = archiving.value

    if (row === null) {
        return
    }

    archiveError.value = null
    router.post(`${base.value}/accounting/chart-of-accounts/${row.id}/archive`, {}, {
        preserveScroll: true,
        onStart: () => {
            archiveBusy.value = true
        },
        onSuccess: () => {
            archiving.value = null
        },
        onError: (errors) => {
            archiveError.value = errors.account ?? Object.values(errors)[0] ?? 'The account was not archived.'
        },
        onFinish: () => {
            archiveBusy.value = false
        },
    })
}

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

// An account added or archived, or a posting that moves a balance, re-reads this page by itself (M18H §7.2).
useLiveReload({ entities: ['accounting', 'dues'] })

const state = useScreenState({
    rows: () => props.groups.reduce((total, group) => total + group.rows.length, 0),
})

/**
 * The board's two money formats, and they are two on purpose.
 *
 * The KPI tiles abbreviate — "$4.30M", "$412k" — because a tile is read at a
 * glance and eight digits in it are noise. The table does not, because an
 * accountant reconciling a column needs the figure itself. Both are drawn that
 * way on the board.
 */
const abbreviated = (minor) => {
    const value = Math.abs(minor) / 100
    const sign = minor < 0 ? '-' : ''

    if (value >= 1_000_000) {
        return `${sign}$${(value / 1_000_000).toFixed(2)}M`
    }

    if (value >= 1_000) {
        return `${sign}$${Math.round(value / 1_000)}k`
    }

    return `${sign}$${value.toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

// The sign before the dollar, "-$100.00", the console's one way to write a negative amount (Law 5): it read "$-100".
// With its cents, always (batch 1, money): the table is what an accountant reconciles a column against.
const exact = (minor) => `${minor < 0 ? '-' : ''}$${(Math.abs(minor) / 100).toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

/**
 * The estate path this console is served under.
 *
 * Local puts the estate in the path and production gives each its own
 * hostname, so the prefix is taken from the URL we are already on rather than
 * rebuilt. Everything up to `/accounting` is the prefix, whichever shape it is.
 */
const base = computed(() => page.url.split('/accounting')[0])
</script>

<template>
    <Head title="Accounting" />

    <EstateConsole title="Accounting" :estate-name="estate.name" active="accounting">
        <template #actions>
            <!-- M96 level 3: a role that cannot change the chart has no Add account at all. -->
            <button
                v-if="canConfigure"
                type="button"
                class="btn-primary-sm"
                title="Add an account."
                @click="openAdding"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add account</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <div v-if="tab.key === 'chart'" class="subnav-item active">{{ tab.label }}</div>
                <Link v-else :href="`${base}/accounting/${tab.key}`" class="subnav-item">
                    {{ tab.label }}
                </Link>
            </template>
        </div>

        <!--
          Add an account, in the console's modal (M95 §1), with its review step: the account is read back as one
          sentence and the tick is what enables the press. Each error sits under its own field.
        -->
        <ConsoleDialog
            :open="adding"
            title="Add an account"
            size="form"
            confirm-label="Add account"
            busy-label="Adding…"
            :busy="accountForm.processing"
            :dirty="accountForm.isDirty"
            discard-title="Discard this account?"
            :confirm-blocked-reason="addBlocked"
            :error="accountForm.errors.account ?? null"
            @confirm="submitAccount"
            @cancel="closeAdding"
        >
            <RequiredMark legend />

            <div class="coa-fields">
                <div class="coa-field">
                    <label for="coa-code">Code <RequiredMark /></label>
                    <input id="coa-code" v-model="accountForm.code" type="text" required maxlength="16" placeholder="e.g. 5300" @input="accountForm.reviewed = false" />
                    <div v-if="accountForm.errors.code" class="coa-error">{{ accountForm.errors.code }}</div>
                </div>

                <div class="coa-field">
                    <label for="coa-type">Type <RequiredMark /></label>
                    <select id="coa-type" v-model="accountForm.type" required @change="onTypeChange">
                        <option v-for="type in TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                </div>

                <div class="coa-field coa-field--wide">
                    <label for="coa-name">Name <RequiredMark /></label>
                    <input id="coa-name" v-model="accountForm.name" type="text" required maxlength="120" placeholder="e.g. Security Services" @input="accountForm.reviewed = false" />
                    <div v-if="accountForm.errors.name" class="coa-error">{{ accountForm.errors.name }}</div>
                </div>

                <div class="coa-field coa-field--wide">
                    <label for="coa-parent">Filed under (optional)</label>
                    <select id="coa-parent" v-model="accountForm.parent_id" aria-describedby="coa-parent-hint" @change="accountForm.reviewed = false">
                        <option value="">Top level</option>
                        <option v-for="parent in parentsOfType" :key="parent.id" :value="parent.id">{{ parent.label }}</option>
                    </select>
                    <div id="coa-parent-hint" class="coa-hint">An account of the same type, or the top level.</div>
                    <div v-if="accountForm.errors.parent_id" class="coa-error">{{ accountForm.errors.parent_id }}</div>
                </div>
            </div>

            <label v-if="accountForm.type === 'asset'" class="coa-check">
                <input v-model="accountForm.is_bank_account" type="checkbox" @change="accountForm.reviewed = false" />
                <span>Money at a bank: counted in cash on hand, and reconcilable against a statement</span>
            </label>

            <!-- THE REVIEW STEP. -->
            <div class="coa-review" :class="{ ready: review !== null }">
                <div class="coa-review-lead">Read it back before it is added:</div>
                <div class="coa-review-line">{{ review ?? 'Enter a code and a name.' }}</div>
                <label class="coa-check">
                    <input v-model="accountForm.reviewed" type="checkbox" :disabled="review === null" :title="review === null ? 'Enter a code and a name first.' : undefined" />
                    <span>The code is not already in use and the type is right. Add it.</span>
                </label>
                <div v-if="accountForm.errors.reviewed" class="coa-error">{{ accountForm.errors.reviewed }}</div>
            </div>
        </ConsoleDialog>

        <!-- Archiving asks first, naming the account and what it does (M97 pass). -->
        <ConsoleDialog
            :open="archiving !== null"
            :title="archiving ? `Archive ${archiving.code} ${archiving.name}?` : ''"
            tone="danger"
            confirm-label="Archive the account"
            busy-label="Archiving…"
            cancel-label="Keep it open"
            :busy="archiveBusy"
            :error="archiveError"
            @confirm="confirmArchive"
            @cancel="keepOpen"
        >
            <p style="margin: 0">
                Nothing new can post to it. Everything already posted to it stands, and it stays on the chart, marked
                Closed. It can never be removed.
            </p>
        </ConsoleDialog>

        <p v-if="positionWithheld" class="chart-note chart-withheld">{{ positionReason }}</p>

        <div v-else class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon"><EstateIcon :name="kpi.icon" /></div>
                </div>
                <div class="k-val">{{ abbreviated(kpi.value_minor) }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="12" :columns="4" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No chart of accounts yet"
            body="Every journal entry posts against an account. Until the chart exists, nothing in this estate can be booked."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account name</th>
                    <th>Type</th>
                    <th v-if="!positionWithheld">Balance</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="group in groups" :key="group.label">
                    <tr class="group-head">
                        <td :colspan="positionWithheld ? 3 : 4">{{ group.label }}</td>
                    </tr>
                    <tr v-for="row in group.rows" :key="row.id" :class="{ 'coa-archived': !row.is_active, 'coa-derived': row.derived }">
                        <td>{{ row.code }}</td>
                        <!-- EST-S-07: the surplus not yet closed to the fund is a row, not an account; nothing to open or archive. -->
                        <td v-if="row.derived">
                            <span title="Income less expenses, not yet closed into the Accumulated Fund.">{{ row.name }}</span>
                        </td>
                        <td v-else>
                            <!-- M84 §3.1: the account opens to its entries, only while the switch is on (`drilldown`). -->
                            <Link
                                v-if="drilldown"
                                :href="`${base}/accounting/chart-of-accounts/${row.id}`"
                                class="coa-open"
                                :title="`Open the entries of ${row.code} ${row.name}.`"
                            >{{ row.name }}</Link>
                            <template v-else>{{ row.name }}</template>
                            <!--
                              Archive, for a viewer holding configure, on an
                              account still open. Drawn as a quiet link at the
                              end of the name rather than a fifth column the
                              board does not have. An archived account stays
                              in the chart, greyed, with its history.
                            -->
                            <button
                                v-if="canConfigure && row.is_active && !row.is_control"
                                type="button"
                                class="coa-archive"
                                :title="`Archive ${row.code} ${row.name}. Nothing new can post to it.`"
                                :aria-label="`Archive ${row.code} ${row.name}`"
                                @click="archive(row)"
                            >
                                archive
                            </button>
                            <!--
                              M96 level 3: a control account is never archived (the ledger refuses it every time), so it
                              has no archive control, only why, in one quiet line where the control would be.
                            -->
                            <span
                                v-else-if="canConfigure && row.is_active && row.is_control"
                                class="coa-control"
                                title="A control account: a sub-ledger ties to its balance, so it is never archived."
                            >control account</span>
                            <!-- M96: an archived account's state in the one vocabulary, Closed, as a badge. -->
                            <span v-else-if="!row.is_active" class="coa-archived-tag" title="Archived: nothing new may post to it, and everything posted to it stands.">Closed</span>
                        </td>
                        <td>{{ typeName(row.type) }}</td>
                        <td v-if="!positionWithheld" class="num-cell">{{ exact(row.balance_minor) }}</td>
                    </tr>
                    <!-- EST-S-07: each group's total, and under Equity liabilities and equity together, so assets visibly equal them. -->
                    <tr v-if="!positionWithheld && typeof group.total_minor === 'number'" class="coa-total">
                        <td></td>
                        <td>Total {{ group.label.toLowerCase() }}</td>
                        <td></td>
                        <td class="num-cell">{{ exact(group.total_minor) }}</td>
                    </tr>
                    <tr v-if="!positionWithheld && balance && group.label === 'Equity'" class="coa-total coa-total--sheet">
                        <td></td>
                        <td>Total liabilities and equity</td>
                        <td></td>
                        <td class="num-cell">{{ exact(balance.liabilities_and_equity_minor) }}</td>
                    </tr>
                </template>
            </tbody>
        </table>

        <!--
          Authored, and it earns its place. Two of the five groups above are
          period figures and three are cumulative; a reader who assumes one rule
          for the whole column will misread the income by a factor of however
          many months the estate has been billing.
        -->
        <p v-if="!state.isEmpty.value && !positionWithheld" class="chart-note">
            Income and expense balances are for {{ period }}<template v-if="asAt">, up to {{ asAt }}</template>. Assets, liabilities and equity are cumulative<template v-if="asAt">, as at {{ asAt }}</template>.
        </p>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the topbar action as a <div> and the
 * three sibling tabs as <div>s; here the first is a real button and the others
 * are anchors, which arrive with a border, a face, the browser's own font and
 * an underline. .btn-primary-sm and .subnav-item supply everything visible.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

a.subnav-item {
    text-decoration: none;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

/*
 * The period note. Authored, because the board carries no equivalent — and it
 * is the one thing on this screen a reader cannot work out from what is drawn.
 * Kept to the tokens the boards define.
 */
.chart-note {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.6;
    margin: 12px 2px 0;
}

/* M10 §5: the same note, standing where the four tiles would. */
.chart-withheld {
    margin: 0 2px 16px;
    max-width: 820px;
}

button.text-link-sm {
    border: 0;
    background: none;
    font-family: inherit;
    line-height: inherit;
    padding: 0;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a chart nobody is adding to, so it
 * has no panel, no flash, no review and no archive control. Kept to the tokens
 * the boards define and the shapes they already use.
 */

/* EST-S-07: the group totals, and the surplus rows, which are figures and not accounts. */
.coa-total td {
    font-weight: 700;
    color: var(--navy-800);
    border-top: 1px solid var(--navy-100);
}

.coa-total--sheet td {
    border-top: 2px solid var(--navy-200);
}

.coa-derived td {
    font-style: italic;
}
.coa-flash,
.coa-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.coa-flash {
    background: var(--green-100);
    color: var(--green-700);
}

/* A refusal is amber (Hassam's three weights, 11 Oct): solid red is for an open panic or duress alert alone. */
.coa-refusal {
    background: var(--amber-100);
    color: var(--amber-700);
}

.coa-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.coa-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
}

.coa-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px 14px;
}

.coa-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

/* What a field accepts, one quiet line under it (not in its label). */
.coa-hint {
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--slate-600);
}

.coa-field--wide {
    grid-column: span 2;
}

.coa-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.coa-field input,
.coa-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.coa-check {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    cursor: pointer;
}

.coa-check input {
    margin: 3px 0 0;
    flex: 0 0 auto;
}

/* The review step: the account read back, and the tick that enables the press. */
.coa-review {
    background: var(--navy-100);
    border-radius: 10px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.coa-review-lead {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.coa-review-line {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--slate-500);
    line-height: 1.5;
}

.coa-review.ready .coa-review-line {
    color: var(--navy-900);
}

.coa-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.coa-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.coa-archive {
    border: 0;
    background: none;
    font: inherit;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    padding: 0;
    margin-left: 8px;
    cursor: pointer;
}

/* A destructive act: muted red, words only, never a solid fill (Hassam's three weights, 11 Oct). */
.coa-archive:hover {
    color: var(--red-700);
}

.coa-archived td {
    color: var(--slate-500);
}

/* Where Archive would be on a control account: why there is none, quietly, in the archive link's own size. */
.coa-control {
    margin-left: 8px;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--slate-500);
    cursor: default;
}

/* In the modal on a phone, the fields one under another. */
@media (max-width: 640px) {
    .coa-fields {
        grid-template-columns: 1fr;
    }

    .coa-field--wide {
        grid-column: auto;
    }
}

.coa-archived-tag {
    display: inline-block;
    margin-left: 8px;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    background: var(--navy-100);
    color: var(--slate-600);
}
</style>
