<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// The statutory rates are an editor: never re-read under somebody's changes (M18H §7.2).
useLiveKind('form')

/**
 * Statutory rate table — board screen super-admin-31.
 *
 * D-021 IS THE SUBJECT OF THIS SCREEN, not a caveat on it. The rates the
 * engine calculates with have never been checked against a worked example by
 * anyone qualified, so they are a DRAFT and no run may be approved against
 * them. The screen has to do two things at once: show the arithmetic honestly
 * enough that an accountant can check it, and say plainly that nobody has.
 *
 * What is deliberately absent is any means of approving them. There is no
 * approve control on this page and no route behind one — a guarded route is one
 * refactor away from being reachable.
 *
 * THE WORKED EXAMPLE IS A REAL PAYSLIP. A rate table whose example is invented
 * proves nothing: it demonstrates the arithmetic somebody typed rather than the
 * arithmetic the engine performed. Its six lines add up to that payslip's net
 * pay to the cent, and if they ever stop, the screen is reporting a real defect.
 */
const props = defineProps({
    table: { type: Object, default: null },
})

const state = useScreenState({
    rows: () => (props.table === null ? 0 : 1),
})

/* A read that failed is read again in place; nothing on this screen writes anything. */
const retry = () => router.reload({ preserveScroll: true })
</script>

<template>
    <Head title="Statutory rate table" />

    <GeminiConsole title="Statutory rate table">
        <!-- The four tabs in the board's own order. -->
        <div class="subnav">
            <Link href="/payroll" class="subnav-item">Pay runs</Link>
            <Link href="/guards" class="subnav-item" title="The people paid here are the guards: this opens Guard workforce.">Employees</Link>
            <Link href="/payroll/filings" class="subnav-item">Statutory filings</Link>
            <Link href="/payroll/rates" class="subnav-item active">Rate table</Link>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="3" />

        <!-- The two states this page lacked (rule 19). -->
        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Your role does not reach payroll and accounting"
            body="Ask a platform administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The rate table could not be read"
            body="No rate was changed: this page changes nothing. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <!--
          No rate version has ever been recorded. A genuine first-use state, not
          an error: the engine cannot calculate until someone enters a rate set,
          and saying so beats a screen of em dashes.
        -->
        <EmptyState
            v-else-if="table === null"
            variant="first-use"
            title="No statutory rates on file"
            body="Pay runs are calculated against a dated set of NIS, NHT, Education Tax and PAYE rates. Until one is recorded there is nothing to apply and no run can be calculated."
        />

        <div v-else class="rates-grid">
            <div class="rate-panel">
                <div class="rate-panel-head">Jamaica statutory deductions</div>
                <!-- No em dash (Law 3): it read "Applied automatically to every pay run — 2026-04, effective ...". -->
                <div class="rate-panel-sub">
                    Applied automatically to every pay run: {{ table.version.label }}, in force from
                    {{ table.version.effective_from }}
                </div>

                <!--
                  Order is load-bearing and is the engine's own: NIS first,
                  because Education Tax and PAYE are both computed on income
                  after it. Listing them differently would describe a different
                  calculation from the one that produced the figures below.
                -->
                <div v-for="line in table.deductions" :key="line.key" class="rate-item">
                    <div class="rate-item-icon">
                        <BoardIcon :name="line.icon" :stroke="1.7" />
                    </div>
                    <div class="rate-item-txt">
                        <div class="rn">{{ line.name }}</div>
                        <div class="rd">{{ line.basis }}</div>
                    </div>
                    <div class="rate-item-val">{{ line.rate }}</div>
                </div>

                <div v-if="table.worked_example" class="example-box">
                    <div class="ex-head">{{ table.worked_example.head }}</div>
                    <div
                        v-for="(row, i) in table.worked_example.rows"
                        :key="i"
                        class="example-row"
                        :class="{ total: row.total }"
                    >
                        <span>{{ row.label }}</span>
                        <span>{{ row.value }}</span>
                    </div>
                </div>
            </div>

            <div class="rate-panel">
                <div class="rate-panel-head">PAYE threshold</div>
                <div class="rate-panel-sub">Determines when income tax applies</div>

                <div class="example-box">
                    <div
                        v-for="(row, i) in table.threshold.rows"
                        :key="i"
                        class="example-row"
                        :class="{ total: row.total }"
                    >
                        <span>{{ row.label }}</span>
                        <span>{{ row.value }}</span>
                    </div>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws every tab as a <div>; here they are
 * links, so the UA's underline would show through. .subnav-item states
 * everything else, and the board's reset already zeroes padding and margin.
 */
a.subnav-item {
    text-decoration: none;
}

/*
 * The two cards, side by side on a desk and one above the other on a phone (Residents' check, 12 Oct: they will need to
 * stack once the Gemini shell is a phone's too). It was an inline grid a narrow screen could not reach.
 */
.rates-grid {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 18px;
}

@media (max-width: 760px) {
    .rates-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
