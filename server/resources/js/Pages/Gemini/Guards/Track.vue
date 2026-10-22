<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SiteMap from '../../../Components/SiteMap.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * THE POSITIONS HELD ABOUT A GUARD (Hassam, 12 October 2026: "nobody is tracked without being able to see what is held
 * about them"). Authored: no board draws it.
 *
 * Shift by shift, every position kept for this officer: when it was taken, why (a regular reading, a clock-in, a scan),
 * where, how accurate, the battery, and whether it waited on the phone out of signal. The same rows, in the same fields,
 * as the officer's own record on the Guard App (GUARD_TRACKING.md §5.2), for any shift still kept, where the app shows
 * today's. Read only; positions are kept only from clock-in to clock-out, with the officer's agreement, and deleted after
 * the retention.
 *
 * It moves by itself while the shift is open: each batch the phone sends announces this guard (`guard.moved`).
 */
const props = defineProps({
    guard: { type: Object, required: true },
    /** `{ id, day, window, post, estate, count }`, newest first: the shifts with positions held, at estates the viewer reaches. */
    shifts: { type: Array, required: true },
    /** The shift shown: `{ id, day, window, post, clocked_in_at, clocked_out_at }`, or null when none is held. */
    shift: { type: Object, default: null },
    retention_days: { type: Number, required: true },
    /** This page of the shift's readings: `{ id, captured_at, taken, lat, lng, accuracy_metres, source, why, battery_percent, arrived, simulated }`. */
    rows: { type: Array, required: true },
    table: { type: Object, required: true },
    /** Every reading of the shift, for the map. */
    pins: { type: Array, required: true },
})

useLiveReload({ entities: ['guard'], ids: () => [props.guard.id] })

// Nothing held at all is this page's first state; a shift is chosen, never filtered, so nothing is filtered out.
const state = useScreenState({ rows: () => props.shifts.length })

const retry = () => router.reload()

const COLUMNS = [
    { key: 'taken', label: 'Taken', sortable: true },
    { key: 'why', label: 'Why' },
    { key: 'place', label: 'Place' },
    { key: 'accuracy', label: 'Accuracy' },
    { key: 'battery', label: 'Battery' },
]

const shiftLabel = (shift) => `${shift.day} · ${shift.window} · ${shift.post}, ${shift.estate} · ${shift.count} position${shift.count === 1 ? '' : 's'}`

const chosen = computed(() => String(props.shift?.id ?? ''))

/** Another shift: its readings from the first, in the order they were taken. */
const openShift = (event) => {
    router.get(`/guards/${props.guard.id}/track`, { shift: event.target.value }, { preserveScroll: true })
}

const place = (row) => `${row.lat.toFixed(6)}, ${row.lng.toFixed(6)}`
</script>

<template>
    <Head :title="`${guard.name} · positions held`" />

    <GeminiConsole :title="`${guard.name} · positions held`" :back="{ href: `/guards/${guard.id}`, label: `Back to ${guard.name}` }">
        <div v-if="state.isLoading.value" class="info-panel">
            <SkeletonRows :rows="6" :columns="5" />
        </div>

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="A guard's positions are not part of your role's access"
            body="Ask your platform administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The positions could not be loaded"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="info-panel">
                <p class="tr-lead">
                    Every position kept for {{ guard.name }}, taken only from clock-in to clock-out and only with their agreement.
                    Positions are deleted after {{ retention_days }} days. {{ guard.name }} sees their own on the Guard App.
                </p>
                <Link :href="`/guards/${guard.id}/location-sharing`" class="text-link-sm">See what {{ guard.name }} agreed to, and when</Link>
            </div>

            <EmptyState
                v-if="state.isEmpty.value"
                variant="first-use"
                :title="`No position is held for ${guard.name}`"
                :body="`Positions are kept for ${retention_days} days from shifts they agreed to share.`"
            />

            <template v-else>
                <div class="info-panel tr-shift">
                    <label for="tr-shift-pick" class="tr-label">Shift</label>
                    <select id="tr-shift-pick" :value="chosen" @change="openShift">
                        <option v-for="each in shifts" :key="each.id" :value="String(each.id)">{{ shiftLabel(each) }}</option>
                    </select>
                    <p v-if="shift" class="tr-note">
                        {{ shift.day }} · {{ shift.window }} · {{ shift.post }}<template v-if="table.total"> · {{ table.total }} position{{ table.total === 1 ? '' : 's' }}</template>
                    </p>
                </div>

                <SiteMap
                    :pins="pins"
                    :height="320"
                    :label="`Where ${guard.name}'s phone recorded a position on this shift`"
                />

                <DataTable :columns="COLUMNS" :rows="rows" :table="table" noun="positions">
                    <template #cell-taken="{ row }">
                        {{ row.taken }}
                        <span v-if="row.simulated" class="tr-tag">simulated</span>
                    </template>
                    <template #cell-why="{ row }">{{ row.why }}</template>
                    <template #cell-place="{ row }">
                        {{ place(row) }}
                        <span v-if="row.arrived" class="tr-sub">{{ row.arrived }}</span>
                    </template>
                    <template #cell-accuracy="{ row }">{{ row.accuracy_metres === null ? 'Not given' : `within ${row.accuracy_metres} m` }}</template>
                    <template #cell-battery="{ row }">{{ row.battery_percent === null ? 'Not given' : `${row.battery_percent}%` }}</template>
                </DataTable>
            </template>
        </template>
    </GeminiConsole>
</template>

<style scoped>
.tr-lead,
.tr-note {
    margin: 0 0 8px;
    font-size: 12.5px;
    line-height: 1.6;
    color: var(--slate-700);
}

.tr-shift {
    display: grid;
    gap: 6px;
}

.tr-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--slate-500);
}

.tr-shift select {
    max-width: 100%;
    height: 36px;
    border: 1.5px solid var(--navy-200);
    border-radius: 10px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 13px;
    color: var(--navy-900);
}

.tr-sub {
    display: block;
    font-size: 11.5px;
    color: var(--slate-500);
}

.tr-tag {
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 6px;
    background: var(--navy-100);
    color: var(--slate-600);
    font-size: 10.5px;
    font-weight: 700;
}
</style>
