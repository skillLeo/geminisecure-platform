<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useLiveReload } from '../../../composables/useLiveReload'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * HOUSEHOLDS WITH MISSING CONTACT DETAILS — the Property Manager's chase list (M18C §5). Authored: no board draws it.
 *
 * Every field a person fills in became required on 27 September. A record saved before then with a blank phone or
 * email is somebody a panic cannot reach, and nobody is locked out for it: the Resident App asks them on sign-in, and
 * this is the office's half — each household, who in it is missing what, and the link to put it right.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** A page of the list (M32 F5); `table.total` and `people` count the whole of it. */
    households: { type: Array, required: true },
    table: { type: Object, required: true },
    people: { type: Number, required: true },
})

const COLUMNS = [
    { key: 'lot', label: 'Lot', sortable: true },
    { key: 'household', label: 'Household', sortable: true },
    { key: 'people', label: 'Who is missing what', sortable: true },
    { key: 'open', label: '' },
]

/*
 * The residents board's sheet, the register's own (M98 pass): it is the one that defines the `.panel` this page draws
 * its head and its empty state in. Board 8's, which it wore, has no `.panel`, so both drew as bare text.
 */
useWireframe('community-admin-01-login-dashboard-structure-and-residents')

// A detail put right anywhere, this console or the app, drops its row by itself (M18H §6).
useLiveReload({ entities: ['resident'] })

/* The five states (Hassam's rule 19): nothing missing is this list's empty state; there is no filter to empty it. */
const state = useScreenState({ rows: () => props.table.total })

const retry = () => router.reload()

// Where this console is rooted: its own hostname in production, /estate/{key} locally.
const page = usePage()
const root = computed(() => {
    const cut = page.url.indexOf('/residents')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const WORDS = { phone: 'phone number', email: 'email', relationship: 'relationship' }
const missingLine = (missing) => {
    const blanks = missing.filter((m) => m !== 'email_unreachable')
    const parts = []
    if (blanks.length) parts.push(`No ${blanks.map((m) => WORDS[m] ?? m).join(', no ')}`)
    // M18G §6: an address whose domain has nowhere to deliver to — a typo such as "gmail.comm".
    if (missing.includes('email_unreachable')) parts.push('The email on file cannot receive mail. Check its spelling')
    return parts.join(' · ')
}
</script>

<template>
    <Head title="Missing contact details" />

    <!-- A nested page (the register's Missing contact details opens it): the way back, in words (M95 §3). -->
    <EstateConsole title="Residents" :estate-name="estate.name" active="residents" :back="{ href: `${root}/residents`, label: 'Back to residents' }">

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Missing contact details are not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The list could not be read"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
        <div class="panel mc-head">
            <h1 class="mc-title">Households with missing contact details</h1>
            <p class="mc-lead">
                A panic emails every member and emergency contact of a household and puts their numbers in front of the
                dispatcher. Anybody below with no phone or no email cannot be reached that way. Open the household and add what
                is missing. Residents are also asked for their own details when they next open the app.
            </p>
            <div class="mc-count">
                {{ table.total === 0 ? 'Nothing is missing.' : `${table.total} ${table.total === 1 ? 'household' : 'households'} · ${people} ${people === 1 ? 'person' : 'people'} to chase` }}
            </div>
        </div>

        <div v-if="state.isEmpty.value" class="panel mc-done">
            Every member and emergency contact at {{ estate.name }} has a phone number and an email on file.
        </div>

        <!-- The whole chase list on the shared table (M32 F5), by lot until a heading asks otherwise. -->
        <DataTable v-else :columns="COLUMNS" :rows="households" :table="table" row-key="household_id" noun="households">
            <template #cell-lot="{ row: h }"><span class="mc-unit">{{ h.unit ?? 'No lot on record' }}</span></template>
            <template #cell-household="{ row: h }"><span class="mc-household">{{ h.household }}</span></template>
            <template #cell-people="{ row: h }">
                <ul class="mc-people">
                    <li v-for="(p, i) in h.people" :key="i" class="mc-person">
                        <span class="mc-name">{{ p.name }}</span>
                        <span class="mc-kind">{{ p.kind }}</span>
                        <span class="mc-missing">{{ missingLine(p.missing) }}</span>
                    </li>
                </ul>
            </template>
            <template #cell-open="{ row: h }">
                <Link v-if="h.unit_slug" :href="`${root}/residents/${h.unit_slug}`" class="mc-open">Open the household</Link>
            </template>
        </DataTable>
        </template>
    </EstateConsole>
</template>

<style scoped>
.mc-head {
    display: grid;
    gap: 6px;
    margin-bottom: 16px;
}
.mc-title {
    margin: 0;
    font-family: 'Poppins', sans-serif;
    font-size: 19px;
    font-weight: 700;
    color: var(--text-primary);
}
.mc-lead {
    margin: 0;
    font-size: 13px;
    line-height: 1.55;
    color: var(--text-body);
    max-width: 760px;
}
.mc-count {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
}
.mc-done {
    font-size: 13px;
    color: var(--text-body);
}
.mc-row {
    display: grid;
    gap: 8px;
}
.mc-row-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.mc-unit {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
}
.mc-household {
    font-size: 12.5px;
    color: var(--text-body);
}
.mc-open {
    display: inline-flex;
    align-items: center;
    min-height: 36px;
    padding: 0 14px;
    border-radius: 10px;
    border: 1.5px solid var(--navy-600);
    color: var(--navy-600);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}
.mc-people {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 6px;
}
.mc-person {
    display: grid;
    grid-template-columns: minmax(140px, 1.2fr) minmax(120px, 1fr) 2fr;
    gap: 10px;
    padding-top: 6px;
    border-top: 1px solid var(--navy-100);
    font-size: 13px;
    color: var(--text-body);
}
.mc-name {
    font-weight: 600;
    color: var(--text-primary);
}
.mc-missing {
    color: var(--amber-700);
    font-weight: 600;
}
@media (max-width: 700px) {
    .mc-person {
        grid-template-columns: 1fr;
        gap: 2px;
    }
}
</style>
