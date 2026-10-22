<script setup>
import { Head, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import Subnav from './Subnav.vue'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { useScreenState } from '../../../composables/useScreenState'
// Where the Roster tab opens (M35 §1), so this link lands where the tab does.
import { ROSTER_HREF } from '../../../lib/workforceTabs.js'

/**
 * On duty — who is working now and when each shift ends, who has not clocked in, who starts soon, which shifts are
 * open, and who is away today (the client's report C4, 26 Sep). No board draws it; it is built from the Guard
 * workforce boards' own panel, row and chip vocabulary, and every figure is the server's (`DutyBoard`), on each
 * estate's clock. Read by every role that reads the workforce; nothing here writes.
 */
const props = defineProps({
    now: { type: String, required: true },
    onDuty: { type: Array, required: true },
    notClockedIn: { type: Array, required: true },
    startingSoon: { type: Array, required: true },
    open: { type: Array, required: true },
    away: { type: Array, required: true },
    scoped: { type: Boolean, default: false },
})

const tiles = [
    { key: 'onDuty', label: 'On duty now', tone: 'good' },
    { key: 'notClockedIn', label: 'Not clocked in', tone: 'alert' },
    { key: 'startingSoon', label: 'Starting in 12 hours', tone: '' },
    { key: 'open', label: 'Open shifts, 24 hours', tone: 'alert' },
    { key: 'away', label: 'Away today', tone: '' },
]

// Moves by itself (M11 §2): a clock-in, a clock-out or a roster change anywhere re-reads it.
useLiveReload({ entities: ['shift', 'request'] })

/* The five states (M98 §3.8): each panel says its own empty; loading, a refused role and a failed read are said here. */
const state = useScreenState()

const retry = () => router.reload()
</script>

<template>
    <Head title="On duty" />

    <GeminiConsole title="Guard workforce">
        <Subnav active="on-duty" />

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="On duty is not part of your role’s access"
            body="It shows every client’s officers and shifts, so it opens only to roles that look after guards and their posts. A Super Admin can grant it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="Who is on duty could not be read"
            body="The platform database did not answer. This screen only reads, so nothing has been changed. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
        <p class="od-now">
            As of {{ now }}<template v-if="scoped"> · your assigned sites only</template>. Each time is on its estate's clock.
        </p>

        <div class="od-tiles">
            <div v-for="tile in tiles" :key="tile.key" class="od-tile" :class="tile.tone">
                <div class="od-tile-value">{{ props[tile.key].length }}</div>
                <div class="od-tile-label">{{ tile.label }}</div>
            </div>
        </div>

        <section class="od-panel" aria-labelledby="od-on">
            <h2 id="od-on" class="od-h">On duty now</h2>
            <p v-if="onDuty.length === 0" class="od-empty">Nobody is clocked in right now.</p>
            <div v-for="row in onDuty" :key="row.id" class="od-row">
                <a :href="`/guards/${row.guard_id}`" class="od-who">{{ row.guard }}</a>
                <span class="od-where">{{ row.post }} · {{ row.estate }}</span>
                <span class="od-when">
                    In since {{ row.since }} · <b :class="{ 'od-late': row.overdue }">shift ends {{ row.ends }}</b>
                    <span v-if="row.overdue" class="od-chip alert">Past its end, not clocked out</span>
                    <span v-else-if="row.on_break" class="od-chip">On a break</span>
                </span>
            </div>
        </section>

        <section class="od-panel" aria-labelledby="od-late">
            <h2 id="od-late" class="od-h">Rostered now, not clocked in</h2>
            <p v-if="notClockedIn.length === 0" class="od-empty">Everybody rostered right now has clocked in.</p>
            <div v-for="row in notClockedIn" :key="row.id" class="od-row">
                <a :href="`/guards/${row.guard_id}`" class="od-who">{{ row.guard }}</a>
                <span class="od-where">{{ row.post }} · {{ row.estate }}</span>
                <span class="od-when">{{ row.window }} <span class="od-chip alert">Started {{ row.late_by }} ago</span></span>
            </div>
        </section>

        <section class="od-panel" aria-labelledby="od-soon">
            <h2 id="od-soon" class="od-h">Starting in the next 12 hours</h2>
            <p v-if="startingSoon.length === 0" class="od-empty">No shift starts in the next twelve hours.</p>
            <div v-for="row in startingSoon" :key="row.id" class="od-row">
                <a :href="`/guards/${row.guard_id}`" class="od-who">{{ row.guard }}</a>
                <span class="od-where">{{ row.post }} · {{ row.estate }}</span>
                <span class="od-when">{{ row.window }} <span class="od-chip">in {{ row.in }}</span></span>
            </div>
        </section>

        <section class="od-panel" aria-labelledby="od-open">
            <h2 id="od-open" class="od-h">Open shifts in the next 24 hours</h2>
            <p v-if="open.length === 0" class="od-empty">Every shift in the next day has an officer.</p>
            <div v-for="row in open" :key="row.id" class="od-row">
                <span class="od-who od-open">Nobody yet</span>
                <span class="od-where">{{ row.post }} · {{ row.estate }}</span>
                <span class="od-when">{{ row.window }} <a :href="ROSTER_HREF" class="text-link-sm">Fill it on the Roster</a></span>
            </div>
        </section>

        <section class="od-panel" aria-labelledby="od-away">
            <h2 id="od-away" class="od-h">Away today</h2>
            <p v-if="away.length === 0" class="od-empty">Nobody is on leave or suspended today.</p>
            <div v-for="row in away" :key="row.guard_id" class="od-row">
                <a :href="`/guards/${row.guard_id}`" class="od-who">{{ row.guard }}</a>
                <span class="od-where">{{ row.estate }}</span>
                <span class="od-when">{{ row.why }}</span>
            </div>
        </section>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/* Authored: no board draws this screen. Kept to the tokens the Gemini boards define. */
.od-now {
    margin: 0 0 14px;
    font-size: 12px;
    color: var(--slate-500);
}

.od-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.od-tile {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 14px 16px;
}

.od-tile-value {
    font-family: 'Poppins', sans-serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--navy-900);
    font-variant-numeric: tabular-nums;
}

.od-tile.good .od-tile-value {
    color: var(--success-700);
}

.od-tile.alert .od-tile-value {
    color: var(--amber-700);
}

.od-tile-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--slate-500);
    margin-top: 2px;
}

.od-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 14px;
}

.od-h {
    margin: 0 0 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--navy-900);
}

.od-empty {
    margin: 0;
    font-size: 12px;
    color: var(--slate-500);
}

.od-row {
    display: grid;
    grid-template-columns: minmax(140px, 1fr) minmax(160px, 1.2fr) minmax(220px, 2fr);
    gap: 12px;
    align-items: baseline;
    border-top: 1px solid var(--navy-100);
    padding: 8px 0 2px;
    font-size: 12px;
    color: var(--navy-900);
}

.od-who {
    font-weight: 700;
    color: var(--navy-800);
    text-decoration: none;
}

.od-open {
    color: var(--amber-700);
}

.od-where {
    color: var(--slate-600);
}

.od-when b {
    font-weight: 700;
}

/* A shift past its end, not clocked out: a warning, in amber. Red is reserved for panic and life safety. */
.od-late {
    color: var(--amber-700);
}

.od-chip {
    display: inline-block;
    margin-left: 6px;
    font-size: 10.5px;
    font-weight: 700;
    border-radius: 999px;
    padding: 2px 8px;
    background: var(--navy-100);
    color: var(--navy-700);
}

.od-chip.alert {
    background: var(--amber-100);
    color: var(--amber-700);
}

@media (max-width: 860px) {
    .od-row {
        grid-template-columns: 1fr;
        gap: 2px;
    }
}
</style>
