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
 * Payroll employees — board screen community-admin-37.
 *
 * THE BANK ACCOUNT AND NIS NUMBERS ARE MASKED BEFORE THEY LEAVE THE SERVER, not
 * here. `Payroll::maskedBank()` and `maskedNis()` build "NCB •••• 3315" and
 * "••• •••5 208" at the boundary, and the payload carries nothing fuller — a
 * page that masked in a template would be sending the whole number to every
 * browser and hiding it with CSS, which is not hiding it at all. Anybody at the
 * desk can open the network tab. `EstatePayrollTest` asserts no field on any row
 * carries a run of digits long enough to be an account.
 *
 * THE RATE IS THE ONLY MONEY ON THIS SCREEN and it is a monthly gross. The four
 * rates sum to the J$440,000 board 15 debits to payroll expense — one number
 * appearing on two screens because both read the same register, not because
 * either was typed twice.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    /** The register as the shared table reads it (M32 F5). */
    table: { type: Object, required: true },
    tabs: { type: Array, required: true },
    canCreate: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 37 is drawn in the employees-details-and-billing sheet beside boards 38
 * and 40, NOT in the payroll sheet its two siblings use. Checked against the
 * sheet that defines `.res-cell` and `.res-avatar` rather than inferred from the
 * module — board 38 lost a whole measurement to exactly that assumption.
 */
useWireframe('community-admin-10-payroll-employees-details-and-billing')

// An employee added or corrected in another console moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['staff'] })

const page = usePage()

/* A search that matches nothing is not an empty register: it says so and offers the way back (rule 19). */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => (props.table.q ?? '') !== '',
})

const base = computed(() => page.url.split('/payroll')[0])

/* A read that failed is read again in place; nothing on this screen writes by reloading. */
const retry = () => router.reload({ preserveScroll: true })

const COLUMNS = [
    { key: 'name', label: 'Employee', sortable: true },
    { key: 'role', label: 'Role', sortable: true },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'bank', label: 'Bank account' },
    { key: 'nis', label: 'NIS number' },
    { key: 'rate', label: 'Rate', sortable: true, cellClass: 'num-cell' },
    { key: 'status', label: 'Status', sortable: true },
]

/* ------------------------------------------------------------------ */
/* adding somebody, behind the consent box (12 §1) */
/* ------------------------------------------------------------------ */

const adding = ref(false)

const form = useForm({
    full_name: '',
    job_title: '',
    employment_type: 'full_time',
    monthly_rate: '',
    // Empty is none on the server; a "0" here opened the form with a value nobody typed (batch 1 item 3).
    approved_pension: '',
    employed_since: '',
    bank_name: '',
    bank_account_number: '',
    nis_number: '',
    consent: false,
})

const ADD_TITLE = 'Add someone to the payroll.'

/*
 * IN THE CONSOLE'S MODAL SINCE THE M98 SWEEP (M95 §1): it was a panel above the register that made the page longer.
 * Each refusal sits under its own field; what was typed is never thrown away without asking, and is gone once it is.
 */
const openAdding = () => {
    form.clearErrors()
    adding.value = true
}

const closeAdding = () => {
    adding.value = false
    form.reset()
    form.clearErrors()
}

/** Why Add employee in the modal cannot be pressed yet, said under it on the screen (M96 level 2). */
const addBlocked = computed(() => {
    if (form.full_name.trim() === '' || form.job_title.trim() === '') {
        return 'Enter the name and the job title first.'
    }

    if (String(form.monthly_rate).trim() === '') {
        return 'Enter the monthly gross first.'
    }

    return form.consent ? null : 'Tick the consent box first.'
})

const submit = () => {
    // The server refuses an unticked box too. This is about not sending a
    // write that will bounce, never about being the guard.
    if (!props.canCreate || addBlocked.value !== null) {
        return
    }

    form.post(`${base.value}/payroll/employees`, {
        preserveScroll: true,
        onSuccess: () => closeAdding(),
    })
}

/** "$185,000.00 a month": the board's "/mo" in words, to the cent (batch 1: a rate can carry cents). */
const rate = (minor) => `$${(minor / 100).toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} a month`
</script>

<template>
    <Head title="Payroll & HR" />

    <EstateConsole title="Payroll & HR" :estate-name="estate.name" active="payroll">
        <template #actions>
            <!-- M96 level 3: a role that can never put somebody on the payroll has no Add employee at all. -->
            <button
                v-if="canCreate"
                type="button"
                class="btn-primary-sm"
                :title="ADD_TITLE"
                @click="openAdding"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add employee</span>
            </button>
        </template>


        <!--
          AUTHORED. The board draws a register nobody is adding to.

          THE CONSENT BOX IS THE RULING (12 §1) AND IT IS NOT DECORATION. The
          bank details are optional — somebody can go on the register before
          their bank sends the account — and the consent is not: the server
          refuses without it, and records the officer who took it.
        -->
        <ConsoleDialog
            :open="adding"
            title="Add an employee"
            size="form"
            confirm-label="Add employee"
            busy-label="Adding…"
            :busy="form.processing"
            :dirty="form.isDirty"
            discard-title="Discard this employee?"
            :confirm-blocked-reason="addBlocked"
            @confirm="submit"
            @cancel="closeAdding"
        >
            <p class="emp-head">
                Nothing is paid or posted. An employee is a row on the register; pay posts only when a run is approved,
                at the rate in force that day.
            </p>

            <!-- Batch 1 item 3: what the server requires is marked, and the mark is explained once. -->
            <RequiredMark legend />

            <div class="emp-fields">
                <div class="emp-field emp-field--wide">
                    <label for="em-name">Full name<RequiredMark /></label>
                    <input id="em-name" v-model="form.full_name" type="text" required maxlength="120" />
                    <div v-if="form.errors.full_name" class="emp-error">{{ form.errors.full_name }}</div>
                </div>
                <div class="emp-field">
                    <label for="em-title">Job title<RequiredMark /></label>
                    <input id="em-title" v-model="form.job_title" type="text" required maxlength="80" placeholder="e.g. Security Supervisor" />
                    <div v-if="form.errors.job_title" class="emp-error">{{ form.errors.job_title }}</div>
                </div>
                <div class="emp-field">
                    <label for="em-type">Employment<RequiredMark /></label>
                    <select id="em-type" v-model="form.employment_type" required>
                        <option value="full_time">Full time</option>
                        <option value="part_time">Part time</option>
                        <option value="contract">Fixed term</option>
                    </select>
                    <div v-if="form.errors.employment_type" class="emp-error">{{ form.errors.employment_type }}</div>
                </div>
                <div class="emp-field">
                    <label for="em-rate">Monthly gross, J$<RequiredMark /></label>
                    <input id="em-rate" v-model="form.monthly_rate" type="text" inputmode="decimal" required placeholder="e.g. 185000.00" />
                    <div v-if="form.errors.monthly_rate" class="emp-error">{{ form.errors.monthly_rate }}</div>
                </div>
                <!--
                  Q-017, ruled: nobody has an approved pension scheme, so this is
                  0 for everybody. The field exists so the day somebody joins
                  one, their statutory income is right and nobody else's moves.
                -->
                <div class="emp-field">
                    <label for="em-pension">Approved pension, J$ a month</label>
                    <input
                        id="em-pension"
                        v-model="form.approved_pension"
                        type="text"
                        inputmode="decimal"
                        placeholder="e.g. 4000.00"
                        title="An approved pension contribution, if any. Leave it empty if none."
                    />
                    <div v-if="form.errors.approved_pension" class="emp-error">{{ form.errors.approved_pension }}</div>
                </div>
                <div class="emp-field">
                    <label for="em-since">Employed since</label>
                    <input id="em-since" v-model="form.employed_since" type="date" />
                    <div v-if="form.errors.employed_since" class="emp-error">{{ form.errors.employed_since }}</div>
                </div>
                <!-- M18C §3.3: what can genuinely come later, under its own heading, never "optional". -->
                <div class="emp-field emp-field--wide emp-later">Pay details can be added when the bank sends them</div>
                <div class="emp-field">
                    <label for="em-bank">Bank</label>
                    <input id="em-bank" v-model="form.bank_name" type="text" maxlength="60" placeholder="e.g. NCB" />
                    <div v-if="form.errors.bank_name" class="emp-error">{{ form.errors.bank_name }}</div>
                </div>
                <div class="emp-field">
                    <label for="em-acct">Account number</label>
                    <input id="em-acct" v-model="form.bank_account_number" type="text" maxlength="32" />
                    <div v-if="form.errors.bank_account_number" class="emp-error">{{ form.errors.bank_account_number }}</div>
                </div>
                <div class="emp-field">
                    <label for="em-nis">NIS number</label>
                    <input id="em-nis" v-model="form.nis_number" type="text" maxlength="32" />
                    <div v-if="form.errors.nis_number" class="emp-error">{{ form.errors.nis_number }}</div>
                </div>
            </div>

            <label class="emp-consent">
                <input v-model="form.consent" type="checkbox" required />
                <span>
                    This person was asked, and agreed, before the estate holds their bank and NIS numbers. The estate
                    keeps payroll records for seven years. Who ticked this and when is recorded against their record.<RequiredMark />
                </span>
            </label>
            <div v-if="form.errors.consent" class="emp-error">{{ form.errors.consent }}</div>
        </ConsoleDialog>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <div v-if="tab.active" class="subnav-item active" aria-current="page">{{ tab.label }}</div>
                <Link
                    v-else
                    :href="tab.key === 'runs' ? `${base}/payroll` : `${base}/payroll/${tab.key}`"
                    class="subnav-item"
                >
                    {{ tab.label }}
                </Link>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="7" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="This register holds every member of staff's pay rate, bank account and NIS number, so it opens only to a role that holds Payroll."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The employee register could not be read"
            body="Nobody was added or changed. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="Nobody matches that search"
            body="Everyone is still on the register; the search hides the rest."
            action-label="Clear the search"
            @action="router.visit(`${base}/payroll/employees`)"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nobody is on the payroll yet"
            body="Each member of staff appears here once they are added to the payroll."
        />

        <!-- The register, as the shared table (M32 F5): name order unless a heading asks otherwise. The masked numbers
             do not sort: an order over them would be an order over the numbers they hide. -->
        <DataTable v-else :columns="COLUMNS" :rows="rows" :table="table" noun="employees">
            <template #cell-name="{ row }">
                <div class="res-cell">
                    <div class="res-avatar">{{ row.initials }}</div>
                    <div>
                        <div class="res-name">{{ row.name }}</div>
                        <div class="res-sub">{{ row.since }}</div>
                    </div>
                </div>
            </template>
            <template #cell-rate="{ row }">{{ rate(row.rate_minor) }}</template>
            <!-- Active green, Inactive neutral: an inactive person wore Active's green (the payroll pass). -->
            <template #cell-status="{ row }">
                <div class="status-badge" :class="row.status === 'active' ? 'active' : 'inactive'">{{ row.status === 'active' ? 'Active' : 'Inactive' }}</div>
            </template>
        </DataTable>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its topbar action and its three tabs as
 * <div>s; here one is a button and two are anchors.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

a.subnav-item {
    text-decoration: none;
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
 * AUTHORED BELOW THIS LINE. The board draws a register nobody is adding to, so
 * it has no form. Kept to the tokens the boards define. The form sits in the
 * console's modal since the M98 sweep; the modal is its panel, two fields to a
 * row in its width.
 */
.emp-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    margin: 0 0 12px;
}

.emp-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 11px;
    margin-bottom: 12px;
}

.emp-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.emp-field--wide {
    grid-column: span 2;
}

.emp-later {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-body);
    margin-top: 6px;
}

.emp-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.emp-field input,
.emp-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

/* The consent box carries the amber ground the boards give a condition. */
.emp-consent {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.55;
    background: var(--amber-100);
    border-radius: 10px;
    padding: 11px 14px;
    cursor: pointer;
}

.emp-consent input {
    margin: 3px 0 0;
    flex: 0 0 auto;
}

/* Inactive, neutral: the board's sheet draws only Active, in green. */
.status-badge.inactive {
    background: var(--navy-100);
    color: var(--slate-600);
}

/* A refusal under its field: amber, never the red kept for life safety (the universal order, rule 20). */
.emp-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

/* In the modal on a phone, the fields one under another. */
@media (max-width: 640px) {
    .emp-fields {
        grid-template-columns: 1fr;
    }

    .emp-field--wide {
        grid-column: auto;
    }
}
</style>
