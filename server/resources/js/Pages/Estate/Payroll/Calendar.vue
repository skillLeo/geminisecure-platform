<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Compliance calendar — board 16's "Compliance calendar" (12 §2, Wave 4).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * board 16's own sheet, under the payroll tabs, so it reads as the register's
 * second view.
 *
 * EVERY ROW SAYS WHERE IT CAME FROM. A return on the register carries the due
 * date it was given; a projected S01 or S02 is dated by the rule the register's
 * own dates follow and is a reminder, not a return. Nothing on this screen
 * writes anything — filing is done from the register, by a role that holds
 * Payroll approval.
 *
 * NO DATE IS GUESSED. The GCT return and the annual P24 appear when the register
 * holds them and are never projected, and the page says so.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    months: { type: Array, required: true },
    projects: { type: Boolean, required: true },
    overdue: { type: Number, required: true },
    tabs: { type: Array, required: true },
})

useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

// A run or a filing moved elsewhere re-reads the calendar by itself (M18H §7.2).
useLiveReload({ entities: ['staff'] })

const page = usePage()

/* The five states (rule 19): this screen drew only the populated one and the empty one. */
const state = useScreenState({
    rows: () => props.months.length,
})

const base = computed(() => page.url.split('/payroll')[0])

/* A read that failed is read again in place; nothing on this screen writes anything. */
const retry = () => router.reload({ preserveScroll: true })
</script>

<template>
    <Head title="Compliance calendar" />

    <!-- The way back, in words (M95 §3): it was a bare round arrow with its words only on hover. -->
    <EstateConsole
        title="Compliance calendar"
        :estate-name="estate.name"
        active="payroll"
        :back="{ href: `${base}/payroll/filings`, label: 'Back to statutory filings' }"
    >

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <Link
                    :href="tab.key === 'runs' ? `${base}/payroll` : `${base}/payroll/${tab.key}`"
                    class="subnav-item"
                    :class="{ active: tab.active }"
                >
                    {{ tab.label }}
                </Link>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The calendar could not be read"
            body="Nothing was filed or changed. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!-- In sentences, with no em dash (Law 3): it said a projected return was "— a reminder, not a return". -->
            <p class="cc-note">
                Returns on the register keep the date they were given. For months the register does not hold yet, the
                monthly S01 and S02 are shown as projected, due on the 14th of the month after the period: a reminder,
                not a return. The GCT return and the annual P24 appear once they are on the register.
            </p>

            <!-- Amber, never red (rule 20: red is for panic and life safety). -->
            <div v-if="overdue > 0" class="cc-alert">
                {{ overdue }} {{ overdue === 1 ? 'return is' : 'returns are' }} past due. File from the statutory filings
                register.
            </div>

            <EmptyState
                v-if="months.length === 0"
                variant="first-use"
                title="Nothing falls due"
                :body="projects
                    ? 'No return is outstanding and none is due in the year ahead.'
                    : 'This estate has run no payroll and has no active employee, so it owes no monthly returns. Returns appear here once it does.'"
            />
        </template>

        <div v-for="month in months" v-show="state.isPopulated.value" :key="month.label" class="cc-month">
            <div class="cc-month-head">{{ month.label }}</div>

            <div class="filing-card">
                <div v-for="row in month.rows" :key="row.key" class="filing-row">
                    <div class="filing-txt">
                        <div class="ft1">{{ row.title }}</div>
                        <!-- A projected row is a reminder, not a return: said beside its period, since its badge is a state. -->
                        <div class="ft2">{{ row.period }}<template v-if="row.source === 'projected'"> · projected, not on the register yet</template></div>
                    </div>

                    <div class="filing-due">{{ row.due }}</div>

                    <!-- The one vocabulary (M96, M99); Overdue a flag beside the state, never instead of one. -->
                    <!-- Batch 1 item 10: an entry still to file opens the filings register, where returns are filed. -->
                    <Link
                        v-if="row.state !== 'filed'"
                        :href="`${base}/payroll/filings`"
                        class="status-badge status-link"
                        :class="{
                            exceptions: row.state === 'overdue',
                            draft: row.state === 'outstanding' || row.state === 'projected',
                        }"
                        title="File it from the statutory filings register."
                    >
                        {{ row.state_label }}<template v-if="row.flag"> · {{ row.flag }}</template>
                    </Link>
                    <div v-else class="status-badge approved">
                        {{ row.state_label }}<template v-if="row.flag"> · {{ row.flag }}</template>
                    </div>
                </div>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. The rows, badges and tabs are board 16's
 * own classes; the month heading, the note and the alert are kept to the tokens
 * the boards define.
 */
a.subnav-item {
    text-decoration: none;
}

.filing-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    overflow: hidden;
}

.cc-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 14px;
    max-width: 840px;
}

.cc-alert {
    background: var(--amber-100);
    color: var(--amber-700);
    font-size: 12px;
    font-weight: 700;
    border-radius: 10px;
    padding: 9px 13px;
    margin-bottom: 14px;
}

.cc-month {
    margin-bottom: 16px;
}

.cc-month-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin-bottom: 8px;
}

/* Batch 1 item 10: a status that leads somewhere reads as one, and keeps its badge's colours. */
.status-link {
    text-decoration: none;
    cursor: pointer;
}

.status-link:hover,
.status-link:focus-visible {
    text-decoration: underline;
}
</style>
