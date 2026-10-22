<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useLiveReload } from '../../../composables/useLiveReload'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * RESIDENTS → DELETED ACCOUNTS (M18H §6). Authored: no board draws it.
 *
 * Deleting an account is a state it can come back from. Every row is somebody who deleted their Resident App account (or
 * whose account the office deleted) and can still be restored: the person themselves by signing in for the estate's
 * first window, the office for the second. When the second closes, nothing of them is left on the account — by itself,
 * overnight — unless the Community Super Admin deletes it permanently sooner, with the name typed and a reason.
 *
 * What the lot owes is on every row for a viewer who may see the ledger: a debt stays with the home.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    total: { type: Number, required: true },
    expiring: { type: Number, required: true },
    search: { type: String, default: '' },
    only_expiring: { type: Boolean, default: false },
    windows: { type: Object, required: true },
    orphaned: { type: Array, default: () => [] },
    canRestore: { type: Boolean, required: true },
    restoreBlockedReason: { type: String, required: true },
    canDeletePermanently: { type: Boolean, required: true },
    permanentBlockedReason: { type: String, required: true },
    /** Households the office removed from their lot, still restorable (M21 §3.1). */
    removedHouseholds: { type: Array, default: () => [] },
    /** The list as the shared table reads it (M32 F5): the oldest deletion first, a page at a time. */
    table: { type: Object, required: true },
})

/* What the lot owes is a column only where the server offers it as a sort: for a viewer who may see the ledger. */
const COLUMNS = computed(() => [
    { key: 'name', label: 'Person', sortable: true },
    { key: 'lot', label: 'Lot', sortable: true },
    { key: 'deleted', label: 'Deleted', sortable: true },
    { key: 'days', label: 'Days left', sortable: true },
    ...((props.table.sortable ?? []).includes('balance') ? [{ key: 'balance', label: 'The lot', sortable: true }] : []),
    { key: 'actions', label: '' },
])

/*
 * The register's own sheet (M97 item 7, M98 pass): board 8's, which this page wore, defines no `.panel` and no buttons
 * of the size this page uses, so the page drew with no console styling at all.
 */
useWireframe('community-admin-01-login-dashboard-structure-and-residents')

// Somebody restoring themselves from their phone, or the overnight run, moves this list by itself.
useLiveReload({ entities: ['account', 'resident'] })

/* The five states (Hassam's rule 19). Nothing deleted, and nothing matching the search, are said apart below. */
const state = useScreenState({ rows: () => props.table.total, filtered: () => props.search !== '' || props.only_expiring })

const retry = () => router.reload()

/** Why an account cannot be restored any more, said beside its greyed Restore (M96 level 2). */
const NO_LONGER_RESTORABLE = 'The time to restore it has run out.'

const page = usePage()
const root = computed(() => {
    const cut = page.url.indexOf('/residents')

    return cut === -1 ? '' : page.url.slice(0, cut)
})
const listPath = computed(() => `${root.value}/residents/deleted-accounts`)

/* ── search and the "expiring" filter, both in the query string ── */

const query = ref(props.search)
// A search or the filter keeps the table's order and page size, and starts again at page one (M32 F5).
const visit = (params) => router.get(listPath.value, { ...keptByTable(props.table), ...params }, { preserveScroll: true, preserveState: true, replace: true })
const submitSearch = () => visit({ ...(query.value.trim() ? { q: query.value.trim() } : {}), ...(props.only_expiring ? { expiring: 1 } : {}) })
const toggleExpiring = () => visit({ ...(props.search ? { q: props.search } : {}), ...(props.only_expiring ? {} : { expiring: 1 }) })

// Past the office's window there is nothing left to count down, and it is not "expiring": it has (the hub's finding, 12 Oct).
const daysLine = (row) => (!row.can_restore ? 'None' : row.days_left === 1 ? '1 day left' : `${row.days_left} days left`)
const soon = (row) => row.can_restore && row.days_left <= 7

/* ── restore ── */

/*
 * A REFUSED RESTORE IS SAID INSIDE ITS DIALOG (Hassam, 12 Oct): in the amber band, the dialog staying open until it is
 * closed. It was closed and toasted, and at 390 px a toast behind a modal is hidden or scrolled away.
 */
const firstRefusal = (form) => Object.values(form.errors ?? {})[0] ?? null

const restoring = ref(null)
const restoreForm = useForm({})
const openRestore = (row) => {
    restoreForm.clearErrors()
    restoring.value = row
}
const confirmRestore = () => {
    if (restoring.value === null) return
    restoreForm.post(`${listPath.value}/${restoring.value.id}/restore`, {
        preserveScroll: true,
        onSuccess: () => {
            restoring.value = null
        },
    })
}

/* ── a removed household, back on its lot (M21 §3.1) ── */

const restoringHousehold = ref(null)
const householdForm = useForm({})
const openHouseholdRestore = (household) => {
    householdForm.clearErrors()
    restoringHousehold.value = household
}
const confirmHouseholdRestore = () => {
    if (restoringHousehold.value === null) return
    householdForm.post(`${root.value}/residents/removed-households/${restoringHousehold.value.id}/restore`, {
        preserveScroll: true,
        onSuccess: () => {
            restoringHousehold.value = null
        },
    })
}

/* ── delete permanently: the Community Super Admin, the name typed, a reason ── */

const removing = ref(null)
const removeForm = useForm({ confirm_name: '', reason: '' })
const openRemove = (row) => {
    removeForm.reset()
    removeForm.clearErrors()
    removing.value = row
}
const nameMatches = computed(() => removing.value !== null && removeForm.confirm_name.trim().toLowerCase() === removing.value.name.trim().toLowerCase())
const removeBlocked = computed(() => {
    if (!nameMatches.value) return 'Type the name exactly as shown to confirm.'
    if (removeForm.reason.trim().length < 10) return 'Say why, in a sentence.'
    return null
})
const confirmRemove = () => {
    if (removing.value === null || removeBlocked.value) return
    removeForm.post(`${listPath.value}/${removing.value.id}/delete-permanently`, {
        preserveScroll: true,
        onSuccess: () => {
            removing.value = null
        },
    })
}

/** A refusal of the permanent delete that names neither field, said inside its modal. */
const removeRefusal = computed(() => Object.entries(removeForm.errors).find(([field]) => !['confirm_name', 'reason'].includes(field))?.[1] ?? null)
</script>

<template>
    <Head title="Deleted accounts" />

    <!-- A nested page (the register's Deleted accounts opens it): the way back, in words (M95 §3). -->
    <EstateConsole title="Residents" :estate-name="estate.name" active="residents" :back="{ href: `${root}/residents`, label: 'Back to residents' }">

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="5" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Deleted accounts are not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The deleted accounts could not be read"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
        <div class="panel da-head">
            <h1 class="da-title">Deleted accounts</h1>
            <p class="da-lead">
                A deleted account stops at once (sign-in, e-pass, visitor passes, bookings and notifications), and nothing
                of it is erased yet. The person can restore it themselves by signing in for {{ windows.self }} days; the
                office can restore it for {{ windows.office }}. After that nothing of the person is left on it. Their dues,
                gate log, incidents and votes stay with the home either way.
            </p>
            <div class="da-count">
                {{ total === 0 ? 'No deleted accounts.' : `${total} deleted ${total === 1 ? 'account' : 'accounts'}` }}<template v-if="expiring > 0"> · <span class="da-soon">{{ expiring }} expiring within 7 days</span></template>
            </div>

            <form class="da-tools" role="search" @submit.prevent="submitSearch">
                <label class="da-search">
                    <span class="da-sr">Search deleted accounts</span>
                    <input v-model="query" type="search" placeholder="Search by name, email, lot or reference" />
                </label>
                <!-- The board's outline button: btn-secondary-sm is defined nowhere, so both drew as browser buttons. -->
                <button type="submit" class="btn-outline-sm"><span>Search</span></button>
                <button type="button" class="btn-outline-sm" :aria-pressed="only_expiring" :class="{ 'da-on': only_expiring }" @click="toggleExpiring">
                    <span>Expiring within 7 days</span>
                </button>
            </form>
        </div>


        <div v-if="table.total === 0" class="panel da-empty">
            {{ total === 0 ? `No Resident App account at ${estate.name} is deleted.` : 'No deleted account matches.' }}
        </div>

        <!-- Every deleted account the search admits, on the shared table (M32 F5): the oldest deletion first. -->
        <DataTable v-else :columns="COLUMNS" :rows="rows" :table="table" noun="deleted accounts">
            <template #cell-name="{ row }">
                <div class="da-name">
                    {{ row.name }}
                    <span v-if="row.was_primary" class="da-tag">Was primary</span>
                </div>
                <div v-if="row.email" class="da-meta">{{ row.email }}</div>
            </template>
            <template #cell-lot="{ row }">
                <div class="da-meta">
                    <template v-if="row.unit">
                        <Link v-if="row.unit_slug" :href="`${root}/residents/${row.unit_slug}`">{{ row.unit }}</Link>
                        <template v-else>{{ row.unit }}</template>
                        <template v-if="row.phase"> · {{ row.phase }}</template>
                    </template>
                    <template v-else>Not linked to a lot</template>
                </div>
            </template>
            <template #cell-deleted="{ row }">
                <div class="da-meta">{{ row.deleted_when }} by {{ row.deleted_by }}</div>
                <div class="da-meta">{{ row.reference }}</div>
            </template>
            <template #cell-days="{ row }">
                <div class="da-days" :class="{ 'da-soon': soon(row) }">{{ daysLine(row) }}</div>
                <div class="da-meta">
                    <!-- Past the office's window it says so (seen on the local app, 12 Oct): it read "Only the office can restore it now". -->
                    {{
                        row.can_self_restore
                            ? `They can restore it by signing in until ${row.self_until}.`
                            : row.can_restore
                              ? `Only the office can restore it now, until ${row.permanent_on}.`
                              : `The time to restore it ran out on ${row.permanent_on}.`
                    }}
                </div>
            </template>
            <template #cell-balance="{ row }">
                <div v-if="row.balance" class="da-balance" :class="{ 'da-owes': row.balance.owes }">
                    {{ row.balance.owes ? `Owes ${row.balance.text}` : row.balance.minor === 0 ? 'Nothing owed' : row.balance.text }}
                </div>
            </template>
            <!--
              M96 levels (M98 pass): Restore only for a role that may restore, Delete permanently only for the Community
              Super Admin (level 3; both were greyed with the reason on hover only); an account whose time has run out
              keeps a greyed Restore with the reason under it (level 2). The hub's finding, 12 Oct: the greyed Restore
              looked almost live, and its reason sat under Delete permanently. It is plainly off now, the reason its own.
            -->
            <template #cell-actions="{ row }">
                <div class="da-actions">
                    <div v-if="canRestore" class="da-restore">
                        <button
                            type="button"
                            class="btn-primary-sm"
                            :disabled="!row.can_restore"
                            :title="row.can_restore ? `Restore ${row.name}'s account.` : NO_LONGER_RESTORABLE"
                            @click="openRestore(row)"
                        >
                            <span>Restore</span>
                        </button>
                        <span v-if="!row.can_restore" class="da-why">{{ NO_LONGER_RESTORABLE }}</span>
                    </div>
                    <button
                        v-if="canDeletePermanently"
                        type="button"
                        class="da-danger"
                        :title="`Delete ${row.name}'s account permanently.`"
                        @click="openRemove(row)"
                    >
                        Delete permanently
                    </button>
                </div>
            </template>
        </DataTable>

        <!-- M18H §4.1: a primary resident who deleted their account leaves a household with nobody primary. -->
        <div v-if="orphaned.length" class="panel da-orphans">
            <h2 class="da-sub">Households with no primary resident</h2>
            <p class="da-lead">
                Each has people on it and nobody named primary, usually because the primary resident deleted their account.
                Open the household and make someone primary.
            </p>
            <ul class="da-orphan-list">
                <li v-for="h in orphaned" :key="h.household_id">
                    <Link v-if="h.unit_slug" :href="`${root}/residents/${h.unit_slug}`">{{ h.unit }}</Link>
                    <template v-else>{{ h.unit ?? 'No lot on record' }}</template>
                    · {{ h.household }} · {{ h.members }} {{ h.members === 1 ? 'person' : 'people' }}
                </li>
            </ul>
        </div>

        <!-- M21 §3.1: households the office removed from their lot, still restorable. -->
        <div v-if="removedHouseholds.length" class="panel da-orphans">
            <h2 class="da-sub">Removed households</h2>
            <p class="da-lead">
                Each was removed from its lot by the office, and every member's account is listed above. Restoring one puts
                the household back on its lot and brings back every account its removal deleted. What the lot owes never moved.
            </p>
            <div v-for="h in removedHouseholds" :key="h.id" class="da-row da-row-flat">
                <div class="da-main">
                    <div class="da-name">{{ h.name }}</div>
                    <div class="da-meta">{{ h.lot }} · {{ h.members }} {{ h.members === 1 ? 'person' : 'people' }} · removed {{ h.removed_on }}{{ h.removed_by ? ` by ${h.removed_by}` : '' }} · {{ h.reference }}</div>
                    <div v-if="h.reason" class="da-meta">“{{ h.reason }}”</div>
                </div>
                <div class="da-state">
                    <div class="da-days" :class="{ 'da-soon': h.days_left <= 7 }">{{ h.days_left === 1 ? '1 day left' : `${h.days_left} days left` }}</div>
                    <div class="da-meta">{{ h.lot_taken ? 'Another household is on this lot now, so this one cannot go back to it.' : `The office can restore it until ${h.restore_until}.` }}</div>
                </div>
                <!-- Level 3 for a role that can never restore; level 2 while another household is on the lot, said beside it. -->
                <div v-if="canRestore" class="da-actions">
                    <button
                        type="button"
                        class="btn-primary-sm"
                        :disabled="h.lot_taken"
                        :title="h.lot_taken ? 'Another household is on this lot now.' : `Put ${h.name} back on ${h.lot}.`"
                        @click="openHouseholdRestore(h)"
                    >
                        <span>Restore household</span>
                    </button>
                </div>
            </div>
        </div>
        </template>

        <ConsoleDialog
            :open="restoringHousehold !== null"
            :title="restoringHousehold ? `Put ${restoringHousehold.name} back on ${restoringHousehold.lot}?` : ''"
            confirm-label="Restore the household"
            cancel-label="Not now"
            busy-label="Restoring…"
            :busy="householdForm.processing"
            :error="firstRefusal(householdForm)"
            @confirm="confirmHouseholdRestore"
            @cancel="restoringHousehold = null"
        >
            <p style="margin: 0">
                The household goes back on its lot with the people its removal took off the register, and every account the
                removal deleted is restored, and each person is emailed. Visitor passes and bookings it cancelled stay cancelled.
            </p>
        </ConsoleDialog>

        <ConsoleDialog
            :open="restoring !== null"
            :title="restoring ? `Restore ${restoring.name}'s account?` : ''"
            confirm-label="Restore the account"
            cancel-label="Not now"
            busy-label="Restoring…"
            :busy="restoreForm.processing"
            :error="firstRefusal(restoreForm)"
            @confirm="confirmRestore"
            @cancel="restoring = null"
        >
            <p style="margin: 0">
                Everything comes back as it was (their home, household, balance and history), and they can sign in again with
                the same email address. They are emailed to say so. Visitor passes the deletion stopped stay stopped.
            </p>
        </ConsoleDialog>

        <ConsoleDialog
            :open="removing !== null"
            :title="removing ? `Delete ${removing.name}'s account permanently?` : ''"
            tone="danger"
            confirm-label="Delete permanently"
            cancel-label="Keep it"
            busy-label="Deleting…"
            :busy="removeForm.processing"
            :confirm-blocked-reason="removeBlocked"
            :error="removeRefusal"
            @confirm="confirmRemove"
            @cancel="removing = null"
        >
            <p style="margin: 0 0 10px">
                <strong>This cannot be undone.</strong> Nothing of the person will be left on the account or on the register: their
                name, email and phone go, and the account becomes "Removed resident". Their dues, payments, gate log and
                incidents stay with the lot, without a name. Without this it happens by itself on
                {{ removing?.permanent_on }}.
            </p>
            <RequiredMark legend />
            <label class="da-field">
                <span>Type their name to confirm: <strong>{{ removing?.name }}</strong> <RequiredMark /></span>
                <input v-model="removeForm.confirm_name" type="text" autocomplete="off" required />
                <span v-if="removeForm.errors.confirm_name" class="da-error">{{ removeForm.errors.confirm_name }}</span>
            </label>
            <label class="da-field">
                <span>Why it must go now <RequiredMark /></span>
                <textarea v-model="removeForm.reason" rows="3" maxlength="300" required placeholder="e.g. The resident asked in writing for everything to be erased." />
                <span v-if="removeForm.errors.reason" class="da-error">{{ removeForm.errors.reason }}</span>
            </label>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
.da-head {
    display: grid;
    gap: 8px;
    margin-bottom: 16px;
}
/* The board's outline and primary buttons as real buttons: the browser's border and face off, the hand on. */
button.btn-outline-sm,
button.btn-primary-sm {
    font-family: inherit;
    cursor: pointer;
}
button.btn-primary-sm {
    border: 0;
}
.da-on span {
    color: var(--text-on-dark);
}
/* M96 level 2: Restore and, when it is greyed, why, one under the other, so the reason is read with its own button. */
.da-restore {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    max-width: 170px;
}
.da-why {
    font-size: 12px;
    line-height: 1.45;
    color: var(--text-body);
}
.da-title {
    margin: 0;
    font-family: 'Poppins', sans-serif;
    font-size: 19px;
    font-weight: 700;
    color: var(--text-primary);
}
.da-sub {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
}
.da-lead {
    margin: 0;
    font-size: 13px;
    line-height: 1.55;
    color: var(--text-body);
    max-width: 780px;
}
.da-count {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
}
/* Hassam's rule 20: saturated red is for panic alone; what needs attention here is amber. */
.da-soon {
    color: var(--amber-700);
    font-weight: 700;
}
.da-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.da-search input {
    min-width: 280px;
    height: 36px;
    padding: 0 12px;
    border: 1.5px solid var(--navy-200);
    border-radius: 10px;
    font: inherit;
    font-size: 13px;
    color: var(--text-primary);
}
.da-search input::placeholder {
    color: var(--text-placeholder);
}
.da-sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
}
.da-on {
    background: var(--navy-900);
    color: var(--text-on-dark);
}
.da-flash {
    font-size: 12.5px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
    background: var(--green-100);
    color: var(--green-700);
}
.da-flash-bad {
    background: var(--red-100);
    color: var(--red-700);
}
.da-empty {
    font-size: 13px;
    color: var(--text-body);
}
.da-row {
    display: grid;
    grid-template-columns: minmax(220px, 2fr) minmax(200px, 1.4fr) auto;
    gap: 14px;
    align-items: center;
}
/* A removed household inside its panel: the same row, without a panel of its own. */
.da-row-flat {
    padding: 12px 0;
    border-top: 1px solid var(--navy-100);
}
.da-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
}
.da-tag {
    display: inline-block;
    white-space: nowrap;
    margin-left: 6px;
    padding: 1px 7px;
    border-radius: 8px;
    background: var(--amber-100);
    color: var(--amber-700);
    font-size: 11px;
    font-weight: 700;
}
.da-meta {
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--text-body);
}
.da-days {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
}
.da-balance {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-body);
}
.da-owes {
    color: var(--amber-700);
}
.da-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: flex-end;
}
.da-danger {
    -webkit-appearance: none;
    appearance: none;
    height: 34px;
    padding: 0 14px;
    border-radius: 10px;
    /* Destructive, so a red outline (Hassam's red rule, final): serious, not an emergency. */
    border: 1.5px solid var(--red-700);
    background: var(--white);
    color: var(--red-700);
    font: inherit;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
}
.da-danger:disabled,
.da-actions .btn-primary-sm:disabled {
    cursor: not-allowed;
    background: var(--app-bg);
    border-color: var(--navy-200);
    color: var(--text-body);
    box-shadow: none;
}
/*
 * Greyed as this lane greys a button (Residents/Show.vue, M96 level 2): faded, and an outline, never a filled pill. Grey
 * on grey with no edge read as a quiet live button (the hub's finding, 12 Oct).
 */
.da-actions .btn-primary-sm:disabled {
    border: 1.5px solid var(--navy-200);
    background: var(--white);
    opacity: 0.55;
}
/* The board writes the primary button's words white on its span: greyed, they were white on grey and could not be read. */
.da-actions .btn-primary-sm:disabled span {
    color: var(--text-body);
}
.da-orphan-list {
    margin: 0;
    padding-left: 18px;
    font-size: 13px;
    line-height: 1.7;
    color: var(--text-body);
}
.da-field {
    display: grid;
    gap: 4px;
    margin-top: 10px;
    font-size: 13px;
    color: var(--text-primary);
}
.da-field input,
.da-field textarea {
    border: 1.5px solid var(--navy-200);
    border-radius: 10px;
    padding: 8px 10px;
    font: inherit;
    font-size: 13px;
    color: var(--text-primary);
}
.da-error {
    color: var(--amber-700);
    font-size: 12px;
    font-weight: 600;
}
@media (max-width: 760px) {
    .da-row {
        grid-template-columns: 1fr;
    }
    .da-actions {
        justify-content: flex-start;
    }
    .da-search input {
        min-width: 0;
        width: 100%;
    }
}
</style>
