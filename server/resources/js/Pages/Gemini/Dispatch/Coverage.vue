<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import DispatchConnectionBar from '../../../Components/DispatchConnectionBar.vue'
import PanicAlarmControls from '../../../Components/PanicAlarmControls.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveDispatch } from '../../../composables/useLiveDispatch.js'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Post coverage board — board screen super-admin-13.
 *
 * DOM and class names are the board's. Every figure is derived from today's
 * roster: a post is covered in a window when a shift is rostered across it and,
 * once that window has begun, somebody actually clocked in. A standing
 * assignment is not coverage — it carries no time at all, and reading it as
 * "manned tonight" is the one mistake this screen exists to prevent.
 *
 * ON THE LIVE STREAM (13 C1). Coverage turns on clock-ins, and a clock-in
 * happens on a handset at a gate, so this board listens on the estates it shows
 * and re-reads its KPIs and rows when a guard clocks on or off — and polls, every
 * fifteen seconds, only while that channel is down. Fifteen, not three: a
 * coverage gap is a staffing problem measured in minutes, not a panic.
 */
const props = defineProps({
    sections: { type: Array, required: true },
    /** The day this board is reporting — today unless one was chosen. */
    day: { type: String, required: true },
    date: { type: String, required: true },
    isToday: { type: Boolean, required: true },
    kpis: { type: Array, required: true },
    /** The two window headings, in the board's wording. */
    windows: { type: Array, required: true },
    rows: { type: Array, required: true },
    scoped: { type: Boolean, default: false },
    /**
     * Whether the clock-ins behind this board came from real handsets or from
     * the simulator. See App\Support\SourceBadge.
     */
    sourceBadge: { type: Object, required: true },
    /** The estates whose channel this board may listen on. */
    estateIds: { type: Array, default: () => [] },
})

const { poll, connection, panic } = useLiveDispatch({
    only: ['kpis', 'rows'],
    intervalMs: 15000,
    estateIds: props.estateIds,
})

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render and stop agreeing with
 * the props behind it.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.scoped,
})

/** A real GET, so a chosen day can be bookmarked and the back button works. */
const chooseDay = (event) => {
    const value = event.target.value

    if (value) {
        router.get('/dispatch/coverage', { date: value }, { preserveScroll: true })
    }
}

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['shift'] })
</script>

<template>
    <Head title="Dispatch — post coverage board" />

    <GeminiConsole title="Dispatch — post coverage board">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
            <DispatchConnectionBar :connection="connection" @refresh="poll.refresh()" />
            <!-- The siren sounds here too, so it can be quietened here (B107). -->
            <PanicAlarmControls :alarm="panic" />
        </template>

        <template #actions>
            <!--
              The board draws a date pill. It opens a day picker now that a
              forward roster exists (12 §2, Wave 5): an open shift is a row on
              the rota, so another day is a question this board can answer
              truly rather than reporting every post uncovered.
            -->
            <label class="btn-outline-sm cov-date" title="Choose the day this board reports. A day nobody has rostered reads as uncovered, which is what it is.">
                <BoardIcon name="calendar" :stroke="1.8" />
                <span>{{ isToday ? `Today, ${day}` : day }}</span>
                <input type="date" :value="date" aria-label="Day to report" @change="chooseDay" />
            </label>
        </template>

        <div class="subnav">
            <template v-for="section in sections" :key="section.label">
                <Link :href="section.href" class="subnav-item" :class="{ active: section.active }">
                    {{ section.label }}
                </Link>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="5" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The roster could not be read"
            body="Today's shifts could not be loaded, so this board cannot say which posts are covered. It is showing nothing rather than showing every post as uncovered, which would be a false alarm."
            action-label="Try again"
            @action="router.reload()"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Coverage is not yours to see"
            body="The coverage board is limited to roles holding dispatch access. Your role can be granted it from the role access matrix by a platform administrator."
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No sites in your assignment"
            body="Your role covers assigned sites rather than the whole platform, and none of the estates assigned to you has posts yet. Other sites may be staffed; they are simply not yours to see."
            action-label="Open the live map"
            @action="router.visit('/dispatch/map')"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No posts to cover yet"
            body="A post is a guarded position at a client site — a gate, a patrol route, a relief slot. Once a client has posts and a roster, every one of them appears here with its coverage across both twelve-hour windows."
            action-label="Go to clients"
            @action="router.visit('/clients')"
        />

        <template v-else>
            <div class="kpi-row">
                <div v-for="kpi in kpis" :key="kpi.label" class="kpi-card">
                    <div class="k-top">
                        <div class="kpi-icon" :class="kpi.tone">
                            <BoardIcon :name="kpi.icon" :stroke="kpi.stroke" />
                        </div>
                    </div>
                    <div class="k-val">{{ kpi.value }}</div>
                    <div class="k-lbl">{{ kpi.label }}</div>
                </div>
            </div>

            <table class="coverage-table">
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Post</th>
                        <th v-for="window in windows" :key="window">{{ window }}</th>
                        <th>Assigned to</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key">
                        <td>{{ row.site }}</td>
                        <td>{{ row.post }}</td>
                        <td v-for="(cell, index) in row.cells" :key="index">
                            <div class="cov-pill" :class="cell.class">
                                <i></i>
                                {{ cell.label }}
                            </div>
                        </td>
                        <!--
                          Never a bare "Unassigned". Naming the guard and what
                          happened to them is the difference between a gap and a
                          gap with a cause, and it is the sentence a supervisor
                          needs when they ask why the Service Gate is empty.
                        -->
                        <td>{{ row.assigned }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule REMOVES a browser
 * default rather than adding a style.
 *
 * The board draws the section tabs and the date pill as <div>s. They are real
 * buttons here — one navigates, one has to be able to say why it cannot — and a
 * <button> arrives wearing the system UI font and its own border.
 */
.subnav-item,
.btn-outline-sm {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    font-family: inherit;
    cursor: pointer;
}

/*
 * :not(.active) on purpose: a bare `.subnav-item { background: none }` ties on
 * specificity with the board's own `.subnav-item.active`, and which won would
 * then depend on the order the bundler emitted them in.
 */
.subnav-item:not(.active) {
    background: none;
}

.subnav-item[disabled],
.btn-outline-sm[disabled] {
    cursor: not-allowed;
}

/* The board's own border, restated after the UA one is removed above, rather
 * than left to specificity luck. */
.btn-outline-sm {
    border: 1.5px solid var(--navy-200);
}

/*
 * The date pill is the board's .btn-outline-sm; the native date input is laid
 * over it, invisible, so pressing the pill opens the browser's own picker and
 * the pill keeps the board's geometry.
 */
.cov-date {
    position: relative;
    cursor: pointer;
}

.cov-date input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
}
</style>
