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
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Vendors — board screen community-admin-26.
 *
 * The supplier register: who the estate buys from, what each has been paid this
 * year, and how to reach them. It is the screen a bill is recorded against, so
 * everything a bill needs about a supplier is settled here.
 *
 * PAID YTD IS NOT A COLUMN ON THE VENDOR. It is the debit side of 2000 Accounts
 * Payable on payment entries only, summed by vendor from the year's posted
 * lines. Storing it beside the name would be a second copy of the ledger free to
 * drift from it, and a reversal of a wrongly approved bill also debits 2000 —
 * counting that as money paid would tell a committee the estate had settled an
 * invoice it had merely cancelled. `Payables::paidByVendor()` filters on the
 * entry's source for exactly that reason.
 *
 * THE CATEGORY COLUMN IS READ FROM THE JOURNAL TOO. "Maintenance" and
 * "Utilities" are not stored on the vendor: they are which expense account that
 * vendor's bills actually landed on, which is a fact the books already hold. A
 * copy kept on the row would be free to say "Maintenance" about a supplier whose
 * costs all went to 5200. A vendor that has never been billed therefore has no
 * category at all, and the cell says so with an em dash rather than guessing.
 *
 * THE TRN IS REQUIRED BEFORE A BILL IS PAID, NEVER BEFORE ONE IS RECORDED. A
 * vendor with no taxpayer registration number on file is not an invalid row and
 * is not drawn as one — the estate may still receive their invoice and must
 * still book what it owes, because refusing to record it would understate the
 * payables. What it may not do is move money to them. So the register marks that
 * vendor as one whose NEXT PAYMENT WILL BE REFUSED, beside a status that is
 * still Active, which is what `Payables::pay()` actually enforces.
 *
 * A VENDOR IS DEACTIVATED, NEVER DELETED. One with bills against it is history
 * the estate has to keep, and `bills.vendor_id` restricts at the database to say
 * so. The board draws no delete control and neither does this screen; taking a
 * supplier off the register is a status change, and it belongs on the edit
 * screen `reasons` names rather than on a row action.
 *
 * TWO CONTENT RESIDUALS, both recorded and neither a styling fault:
 *
 *   The board draws five populated Paid YTD figures. Only one of the five bills
 *   board 27 specifies has been paid, so four of these rows honestly read $0 —
 *   the payments that would fill the column are not in the seed and inventing
 *   them would put money through the ledger that no board asked for.
 *
 *   The board draws "JU" as the avatar for Jamaica Public Service Co. No
 *   initials rule over that name produces it; `Vendor::initials()` returns "JP",
 *   which is correct, and a lookup table of exceptions to a two-letter initial
 *   would outlive the wireframe it came from.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** Budgets and Manual journals when switched on: the same tab row as the chart's (AccountingController::optionalTabs). */
    optionalTabs: { type: Array, default: () => [] },
    rows: { type: Array, required: true },
    /** The register as the shared table reads it (M32 F5): its order, its columns, its search and this page's place. */
    table: { type: Object, required: true },
    /** Null where the estate's spending is withheld from this reader (M10 §5). */
    paid_ytd_total_minor: { type: Number, default: null },
    canCreate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },

    /*
     * M10 §5. What every supplier was paid this year is the estate's spending.
     * For a reader who may not have the estate's position the server sends
     * every Paid YTD as null and this sentence, and the column is not drawn.
     */
    positionWithheld: { type: Boolean, default: false },
    positionReason: { type: String, default: '' },
})

/*
 * ADDING A VENDOR IS BUILT (12 §2, Wave 1): "TRN required before a bill may be
 * paid." The form asks for the TRN and does not require it: a supplier goes
 * on the register when the estate starts dealing with them, paperwork or not,
 * and the payment is where the missing number refuses.
 *
 * IN THE CONSOLE'S MODAL SINCE M95 §1, each error under its own field; it closes when the supplier is on the register.
 */
const adding = ref(false)

const vendorForm = useForm({
    name: '',
    category: '',
    trn: '',
    contact_name: '',
    contact_phone: '',
    contact_email: '',
})

const openAdding = () => {
    if (!props.canCreate) {
        return
    }

    vendorForm.clearErrors()
    adding.value = true
}

const closeAdding = () => {
    adding.value = false
    vendorForm.reset()
    vendorForm.clearErrors()
}

const submitVendor = () => {
    if (!props.canCreate || vendorForm.name.trim() === '') {
        return
    }

    vendorForm.post(accounting('/vendors'), { preserveScroll: true, onSuccess: () => closeAdding() })
}

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

// A vendor added or set inactive in another console moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['accounting'] })

const page = usePage()

/*
 * All six since the register has its search (M32 F5): a search that matched no
 * supplier is `empty-filtered`, and not `empty`, which is no supplier at all.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => (props.table.q ?? '') !== '',
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/accounting/vendors. The URL that served this page already
 * carries whichever shape this environment uses, so cutting it at /accounting is
 * correct in both — a hard-coded root is correct in exactly one.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/accounting')))

const accounting = (suffix) => `${root.value}/accounting${suffix}`

/**
 * The board's money: whole dollars, comma-grouped, no decimals.
 *
 * ONE FORMAT ON THIS SCREEN, unlike its sibling. Board 25 abbreviates in its KPI
 * tiles and prints exactly in its table, because a tile is read at a glance and
 * eight digits in it are noise; board 26 draws no tiles at all, so there is
 * nothing here to abbreviate and Paid YTD is the figure a treasurer reconciles
 * a column against.
 *
 * Every amount arrives as minor units — integer cents — because that is the only
 * form that survives arithmetic. The divide by 100 happens here, at the last
 * possible moment, and nothing is added up after it.
 */
// The sign before the dollar, "-$100", the console's one way to write a negative amount (Law 5): a supplier paid more
// than it billed is in debit, and that read "$-100".
// With its cents, always: "$6,200.50", never "$6,201" (batch 1, money).
const exact = (minor) => `${minor < 0 ? '-' : ''}$${(Math.abs(minor) / 100).toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

/**
 * The column's own total, which the board draws nowhere.
 *
 * It is carried by the server because it is the figure a treasurer checks the
 * column against, and it is put on the heading rather than in a footer row: the
 * board has no footer, and adding one would be drawing a row no board drew.
 */
const paidYtdTotal = computed(() =>
    props.paid_ytd_total_minor === null
        ? null
        : `These ${props.table.total} vendors have been paid ${exact(props.paid_ytd_total_minor)} year to date.`
)

/*
 * THE TABLE (M32 F5): the whole register ordered by the server and paged — name order by default. Paid YTD sorts, and
 * is drawn, only for a reader who may read the estate's spending (M10 §5).
 */
const columns = computed(() => [
    { key: 'name', label: 'Vendor', sortable: true },
    { key: 'category', label: 'Category', sortable: true },
    { key: 'contact', label: 'Contact', sortable: true },
    ...(props.positionWithheld ? [] : [{ key: 'paid', label: 'Paid YTD', sortable: true, title: paidYtdTotal.value ?? undefined, cellClass: 'num-cell' }]),
    { key: 'status', label: 'Status', sortable: true },
    { key: 'actions', label: '' },
])

const vendorsPath = computed(() => accounting('/vendors'))
const searchParams = computed(() => keptByTable(props.table))
const noun = computed(() => (props.table.total === 1 ? 'vendor' : 'vendors'))

/**
 * The four Accounting tabs, all of which are built.
 *
 * The one the reader is on carries no href, because there is nowhere for it to
 * lead; it is text rather than a control. The other three are real links, so the
 * module can be navigated with the keyboard and any of its screens opened in its
 * own tab.
 *
 * `reasons` carries only `add` on this screen, and that is right. A tab's reason
 * is what an unbuilt screen owes the reader, and none of the four is unbuilt any
 * more.
 */
const tabs = computed(() => [
    { key: 'chart', label: 'Chart of accounts', href: accounting('/chart-of-accounts') },
    { key: 'vendors', label: 'Vendors', href: null },
    { key: 'bills', label: 'Bills & payments', href: accounting('/bills') },
    { key: 'reconciliation', label: 'Bank reconciliation', href: accounting('/reconciliation') },
    ...props.optionalTabs.map((tab) => ({ key: tab.key, label: tab.label, href: accounting(`/${tab.key}`) })),
])

/**
 * What a missing TRN actually costs, said where a treasurer will read it.
 *
 * Not a validation message. The vendor is on the register, the vendor is Active,
 * and their invoices will be recorded exactly as everybody else's are. The one
 * thing that will not happen is a payment, and the fix is paperwork from the
 * supplier rather than anything anybody can type on this screen.
 */
const NO_TRN =
    'No taxpayer registration number on file. Bills from this vendor are recorded normally, since the estate owes what it owes, but the next payment to them will be refused until the TRN is on the vendor record.'

/** The board's own em dash, for a fact that is absent rather than zero. */
const NONE = '—'
</script>

<template>
    <Head title="Vendors" />

    <EstateConsole
        title="Vendors"
        :estate-name="estate.name"
        active="accounting"
        search-placeholder="Search vendors by name, trade or contact…"
        :search-route="vendorsPath"
        :search-value="table.q ?? ''"
        :search-params="searchParams"
    >
        <template #actions>
            <!-- M96 level 3: a role that can never add a supplier has no Add vendor at all. -->
            <button
                v-if="canCreate"
                type="button"
                class="btn-primary-sm"
                title="Add a supplier."
                @click="openAdding"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add vendor</span>
            </button>
        </template>

        <!--
          The module's tabs. The one the reader is on is text and not a control;
          the other three are real links.
        -->
        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <Link v-if="tab.href" :href="tab.href" class="subnav-item">{{ tab.label }}</Link>
                <div v-else class="subnav-item active" aria-current="page">{{ tab.label }}</div>
            </template>
        </div>


        <!--
          Add a vendor, in the console's modal (M95 §1). The TRN field says in its own label what leaving it blank costs;
          every refusal sits under its own field, in the server's words (M18C).
        -->
        <ConsoleDialog
            :open="adding"
            title="Add a vendor"
            size="form"
            confirm-label="Add to register"
            busy-label="Adding…"
            :busy="vendorForm.processing"
            :dirty="vendorForm.isDirty"
            discard-title="Discard this vendor?"
            :confirm-blocked-reason="vendorForm.name.trim() === '' ? 'Enter the supplier’s name.' : null"
            @confirm="submitVendor"
            @cancel="closeAdding"
        >
            <RequiredMark legend />

            <!-- Six fields, two to a row (one on a phone): name and trade, contact and phone, email and TRN. -->
            <div class="vnd-fields">
                <div class="vnd-field">
                    <label for="vnd-name">Name <RequiredMark /></label>
                    <input id="vnd-name" v-model="vendorForm.name" type="text" required maxlength="160" placeholder="e.g. Island Electric Services" />
                    <div v-if="vendorForm.errors.name" class="vnd-error">{{ vendorForm.errors.name }}</div>
                </div>

                <div class="vnd-field">
                    <label for="vnd-category">Trade <RequiredMark /></label>
                    <input id="vnd-category" v-model="vendorForm.category" type="text" maxlength="64" placeholder="e.g. Electrical contractor" required />
                    <div v-if="vendorForm.errors.category" class="vnd-error">{{ vendorForm.errors.category }}</div>
                </div>

                <div class="vnd-field">
                    <label for="vnd-contact">Contact <RequiredMark /></label>
                    <input id="vnd-contact" v-model="vendorForm.contact_name" type="text" maxlength="160" placeholder="e.g. Andre Campbell" required />
                    <div v-if="vendorForm.errors.contact_name" class="vnd-error">{{ vendorForm.errors.contact_name }}</div>
                </div>

                <div class="vnd-field">
                    <label for="vnd-phone">Phone <RequiredMark /></label>
                    <input id="vnd-phone" v-model="vendorForm.contact_phone" type="tel" maxlength="40" :placeholder="`e.g. ${page.props.phoneExample?.local ?? ''}`" required />
                    <div v-if="vendorForm.errors.contact_phone" class="vnd-error">{{ vendorForm.errors.contact_phone }}</div>
                </div>

                <div class="vnd-field">
                    <label for="vnd-email">Email <RequiredMark /></label>
                    <input id="vnd-email" v-model="vendorForm.contact_email" type="email" maxlength="190" placeholder="e.g. accounts@campbellelectric.com" required />
                    <div v-if="vendorForm.errors.contact_email" class="vnd-error">{{ vendorForm.errors.contact_email }}</div>
                </div>

                <div class="vnd-field">
                    <label for="vnd-trn">TRN (optional)</label>
                    <input id="vnd-trn" v-model="vendorForm.trn" type="text" inputmode="numeric" maxlength="24" placeholder="e.g. 100-482-517" aria-describedby="vnd-trn-hint" />
                    <div id="vnd-trn-hint" class="vnd-hint">Nine digits. Leave it blank until it arrives; no payment can be made to this vendor until it is on file.</div>
                    <div v-if="vendorForm.errors.trn" class="vnd-error">{{ vendorForm.errors.trn }}</div>
                </div>
            </div>
        </ConsoleDialog>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="The supplier register is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The vendor register could not be read"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <!--
          A first-use empty, not a "no results". An estate with no vendors has
          never recorded a bill, because a bill is recorded AGAINST a vendor —
          so this panel is the start of the payables ledger rather than the end
          of a search.
        -->
        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No vendor matches that search"
            body="No supplier matches that search."
            action-label="Clear the search"
            @action="router.visit(vendorsPath)"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No vendors on the register yet"
            body="Add a supplier to start recording bills."
        />

        <template v-else>
            <p v-if="positionWithheld" class="vendors-withheld">{{ positionReason }}</p>

            <DataTable :columns="columns" :rows="rows" :table="table" :noun="noun">
                <template #cell-name="{ row }">
                    <div class="res-cell">
                        <div class="res-avatar">{{ row.initials }}</div>
                        <div>
                            <div class="res-name">{{ row.name }}</div>
                            <div class="res-sub">{{ row.trade }}</div>
                        </div>
                    </div>
                </template>

                <!-- Null until this vendor's first bill posts, because the column is which expense account their costs
                     landed on and a vendor with no costs has landed on none. -->
                <template #cell-category="{ row }">{{ row.category ?? NONE }}</template>
                <template #cell-paid="{ row }">{{ exact(row.paid_ytd_minor) }}</template>

                <template #cell-status="{ row }">
                    <!--
                      The board's sheet defines .status-badge.active and no twin for a vendor that has been taken off
                      the register. Deactivated is neutral rather than red: it is the ONLY way a supplier ever leaves this
                      list — one with bills against them is history the estate has to keep.
                    -->
                    <!-- Each is a link to the supplier's page, where the status and the TRN are changed (batch 1, item 10). -->
                    <Link :href="accounting(`/vendors/${row.id}`)" class="status-link" :title="`Open ${row.name}: its status and details are changed there.`">
                        <span
                            class="status-badge"
                            :class="row.status"
                            :style="row.status === 'active' ? undefined : 'background:var(--navy-100);color:var(--slate-600);'"
                        >
                            {{ row.status_label }}
                        </span>
                    </Link>

                    <!--
                      The payment rule, on the row it applies to, in the board's own amber, BESIDE the status: the vendor
                      is genuinely still Active, and what is missing is the supplier's paperwork.
                    -->
                    <Link v-if="!row.has_trn" :href="accounting(`/vendors/${row.id}`)" class="status-link" :title="NO_TRN">
                        <span class="status-badge" style="background: var(--amber-100); color: var(--amber-700)">
                            No TRN, cannot be paid
                        </span>
                    </Link>
                </template>

                <template #cell-actions="{ row }">
                    <Link :href="accounting(`/vendors/${row.id}`)" class="text-link-sm">View</Link>
                </template>
            </DataTable>
        </template>
    </EstateConsole>
</template>

<style scoped>
/* AUTHORED (M10 §5): why Paid YTD is not drawn, above the table it concerns. */
.vendors-withheld {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 10px;
}

/*
 * Default-removal only. The board draws its topbar action, its four tabs and its
 * row links as <div>s; here they are one button and several links. A real
 * button arrives wearing a border, buttonface grey and the browser's own font, and
 * .btn-primary-sm supplies everything visible. app.css has already taken the
 * UA's underline and blue off every anchor on a board page, so the links need
 * nothing at all.
 *
 * Not one declaration below introduces a colour, a size or a spacing, and none
 * of them touches a class the board gives a border to — .btn-primary-sm
 * declares a background and no border, which is why that one is safe to reset.
 */
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
    font-family: inherit;
    line-height: inherit;
    padding: 0;
    cursor: pointer;
}

/* A status that leads somewhere: the badge itself, drawn as a link on hover and focus (batch 1, item 10). */
.status-link {
    display: inline-block;
    margin: 0 6px 4px 0;
}

.status-link:hover .status-badge,
.status-link:focus-visible .status-badge {
    text-decoration: underline;
}

/*
 * AUTHORED BELOW THIS LINE. The board is a still image of a register nobody is
 * adding to, so it draws no panel and no flash. Kept to the tokens the boards
 * define and the shapes they already use.
 */
.vnd-flash {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
    background: var(--green-100);
    color: var(--green-700);
}

.vnd-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.vnd-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
}

.vnd-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px 14px;
}

.vnd-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

/* In the modal on a phone, the fields one under another. */
@media (max-width: 640px) {
    .vnd-fields {
        grid-template-columns: 1fr;
    }
}

/* What a field costs or accepts, one quiet line under it (not in its label). */
.vnd-hint {
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--slate-600);
}

.vnd-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.vnd-field input {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.vnd-field input::placeholder {
    color: var(--text-placeholder);
    opacity: 1;
}

/* An error is amber (Hassam's three weights, 11 Oct). */
.vnd-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

.vnd-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

/*
 * ON A PHONE (seen at 390 px, 12 Oct): the four Accounting tabs wrap inside the page, where the board's single row
 * pushed it to 424 px. Doubled classes outrank the board's `body.wf-… .subnav`.
 */
@media (max-width: 640px) {
    .subnav.subnav {
        flex-wrap: wrap;
        max-width: 100%;
    }
}
</style>
