<script setup>
import { computed } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// The simulator is a tool you drive, not a list that moves: a form (M18H §7.2).
useLiveKind('form')

/**
 * The event simulator — /simulator. Part C of the web deliverable.
 *
 * NOT ON ANY BOARD, and drawn from the console's own shared sheet so it reads
 * as part of the console: the add-guard form's panels, the audit log's table,
 * the roster's badges. Nothing here is a class the Gemini boards do not define.
 *
 * WHAT IT DOES. Fires the events a Guard App handset would — a panic, an
 * arrival at the gate, a shift change — through the REAL /api/v1 endpoints,
 * with a real bearer token on a real enrolled device, and shows what each
 * endpoint answered. It never writes a row itself, so what the dispatch
 * screens then show is what the API was willing to create. See
 * App\Services\Simulation\Simulator.
 *
 * TWO MODES. Manual is one chosen event, for putting a specific panic on a
 * specific estate and watching it land. Ambient is a burst drawn from a fixed
 * seed: the same seed always produces the same events with the same
 * idempotency keys, so pressing it twice does not double the queue, and two
 * reviewers with the same seed see the same night.
 *
 * THE RESULTS TABLE IS THE ENDPOINT'S ANSWER, not the simulator's claim. A 201
 * is a row the endpoint made, a 200 on a shift is a transition it accepted,
 * and a refusal is shown with the endpoint's own message — a simulator that
 * said "fired" over a 422 would be lying about the API.
 *
 * ABSENT WHEN OFF. The routes exist only while GS_SIMULATOR_ENABLED is set, so
 * this page cannot be reached on a host that does not have the flag — not
 * gated, not greyed: 404.
 */
const props = defineProps({
    /** Every estate on the platform — an event needs somewhere to happen. */
    estates: { type: Array, required: true },
    /** The alert kinds the endpoint accepts, in its own words. */
    kinds: { type: Array, required: true },
    /** The arrivals catalogue, one line each: verdict, category, basis. */
    arrivals: { type: Array, required: true },
    ambientMax: { type: Number, required: true },
    /** What the last press produced. Empty on a fresh GET. */
    results: { type: Array, required: true },
    summary: { type: String, default: null },
})

/*
 * `empty` is a fresh page — nothing fired yet — and is the ordinary state
 * rather than a fault. There is no filter here, so empty-filtered is reachable
 * only when forced, and it says so.
 */
const state = useScreenState({
    rows: () => props.results.length,
})

const firstEstate = computed(() => props.estates[0]?.id ?? '')

const alertForm = useForm({
    tenant_id: firstEstate.value,
    kind: props.kinds[0] ?? 'panic',
    offline: false,
})

const gateForm = useForm({
    tenant_id: firstEstate.value,
    arrival: 0,
    subject: '',
})

const shiftsForm = useForm({
    tenant_id: firstEstate.value,
})

/*
 * Seven, because it is the seed the release notes and the tests use — a
 * reviewer who presses this without reading anything sees the same night
 * everybody else describes.
 */
const ambientForm = useForm({
    seed: 7,
    count: 12,
})

/** Why nothing can be fired at all, or null when it can. */
const blockedBy = computed(() =>
    props.estates.length === 0
        ? 'No estate is provisioned, so there is nowhere for an event to happen. Provision one from Clients first.'
        : null
)

const fireAlert = () => {
    if (blockedBy.value !== null) {
        return
    }

    alertForm.post('/simulator/alert', { preserveScroll: true })
}

const fireGate = () => {
    if (blockedBy.value !== null) {
        return
    }

    gateForm.post('/simulator/gate-event', { preserveScroll: true, onSuccess: () => (gateForm.subject = '') })
}

const fireShifts = () => {
    if (blockedBy.value !== null) {
        return
    }

    shiftsForm.post('/simulator/shifts', { preserveScroll: true })
}

const fireAmbient = () => {
    if (blockedBy.value !== null) {
        return
    }

    ambientForm.post('/simulator/ambient', { preserveScroll: true })
}

/**
 * The board's own status pills, borrowed by meaning: `ok` is the roster's
 * green, `pending` its amber, `exception` its red.
 */
const outcomeClass = (outcome) =>
    ({ created: 'ok', ok: 'ok', idle: 'pending', refused: 'exception' })[outcome] ?? 'pending'

const outcomeLabel = (row) =>
    ({
        created: `${row.status} created`,
        ok: `${row.status} accepted`,
        idle: 'nothing to do',
        refused: row.status === null ? 'no handset' : `${row.status} refused`,
    })[row.outcome] ?? row.outcome

const kindLabel = (kind) => ({ alert: 'Alert', gate: 'Gate event', shift: 'Shift', handset: 'Handset' })[kind] ?? kind

const busy = computed(
    () => alertForm.processing || gateForm.processing || shiftsForm.processing || ambientForm.processing
)
</script>

<template>
    <Head title="Event simulator" />

    <GeminiConsole title="Event simulator">
        <template #byline>
            <span class="sim-byline">
                Fires Guard App events through the real /api/v1 endpoints, as an enrolled handset. Nothing here
                writes a row — every event below is what an endpoint was willing to create, flagged simulated.
            </span>
        </template>


        <div class="form-layout">
            <div class="form-panel">
                <div class="form-sec-head">Manual — one event</div>

                <!-- An alert: the queue, the live map and the alertness board all move. -->
                <form class="sim-form" @submit.prevent="fireAlert">
                    <div class="m-two-col">
                        <div class="m-field">
                            <label for="alert-estate">Estate</label>
                            <div class="m-input">
                                <select id="alert-estate" v-model="alertForm.tenant_id" required>
                                    <option v-for="estate in estates" :key="estate.id" :value="estate.id">
                                        {{ estate.name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="m-field">
                            <label for="alert-kind">Alert</label>
                            <div class="m-input">
                                <select id="alert-kind" v-model="alertForm.kind" required>
                                    <option v-for="kind in kinds" :key="kind" :value="kind">{{ kind }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <label class="sim-check">
                        <input v-model="alertForm.offline" type="checkbox" />
                        <span>
                            Captured offline — the handset's clock runs nine minutes slow, so the console's clock-skew
                            flag has something to show
                        </span>
                    </label>

                    <div v-if="alertForm.errors.kind" class="field-error">{{ alertForm.errors.kind }}</div>
                    <div v-if="alertForm.errors.tenant_id" class="field-error">{{ alertForm.errors.tenant_id }}</div>

                    <button
                        type="submit"
                        class="stack-btn primary"
                        :disabled="busy || blockedBy !== null"
                        :title="blockedBy ?? 'POST /api/v1/alerts as a borrowed handset. The alert lands in the dispatch queue, flagged simulated.'"
                    >
                        <BoardIcon name="warning" :stroke="1.8" />
                        <span>{{ alertForm.processing ? 'Raising…' : 'Raise this alert' }}</span>
                    </button>
                </form>

                <!-- An arrival: the gate log and board 07's Visitor passes bar move. -->
                <form class="sim-form" @submit.prevent="fireGate">
                    <div class="m-two-col">
                        <div class="m-field">
                            <label for="gate-estate">Estate</label>
                            <div class="m-input">
                                <select id="gate-estate" v-model="gateForm.tenant_id" required>
                                    <option v-for="estate in estates" :key="estate.id" :value="estate.id">
                                        {{ estate.name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="m-field">
                            <label for="gate-arrival">Arrival</label>
                            <div class="m-input">
                                <select id="gate-arrival" v-model="gateForm.arrival" required>
                                    <option v-for="arrival in arrivals" :key="arrival.index" :value="arrival.index">
                                        {{ arrival.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="m-field">
                        <label for="gate-subject">Who arrived — leave blank for one of the usual names</label>
                        <div class="m-input" :class="{ focused: gateForm.subject !== '' }">
                            <input id="gate-subject" v-model="gateForm.subject" type="text" maxlength="120" />
                        </div>
                    </div>

                    <div v-if="gateForm.errors.arrival" class="field-error">{{ gateForm.errors.arrival }}</div>

                    <button
                        type="submit"
                        class="stack-btn outline"
                        :disabled="busy || blockedBy !== null"
                        :title="blockedBy ?? 'POST /api/v1/gate-events as a borrowed handset. The arrival lands in the gate log, flagged simulated; an override carries its reason.'"
                    >
                        <BoardIcon name="clients" :stroke="1.7" />
                        <span>{{ gateForm.processing ? 'Recording…' : 'Record this arrival' }}</span>
                    </button>
                </form>

                <!-- A shift change: the coverage board and the live map say who is on. -->
                <form class="sim-form" @submit.prevent="fireShifts">
                    <div class="m-field">
                        <label for="shifts-estate">Clock today's roster on and yesterday's off</label>
                        <div class="m-input">
                            <select id="shifts-estate" v-model="shiftsForm.tenant_id" required>
                                <option v-for="estate in estates" :key="estate.id" :value="estate.id">
                                    {{ estate.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="stack-btn outline"
                        :disabled="busy || blockedBy !== null"
                        :title="blockedBy ?? 'POST /api/v1/shifts/{shift}/clock-in for every shift due to have started, and clock-out for every one due to have ended. The coverage board reads the result.'"
                    >
                        <BoardIcon name="guards" :stroke="1.7" />
                        <span>{{ shiftsForm.processing ? 'Changing shifts…' : 'Change shifts' }}</span>
                    </button>
                </form>
            </div>

            <div class="preview-panel">
                <div class="preview-head">Ambient — a seeded night</div>

                <form class="sim-form" @submit.prevent="fireAmbient">
                    <div class="m-two-col">
                        <div class="m-field">
                            <label for="ambient-seed">Seed</label>
                            <div class="m-input">
                                <input id="ambient-seed" v-model.number="ambientForm.seed" type="number" min="0" max="999999" required />
                            </div>
                        </div>

                        <div class="m-field">
                            <label for="ambient-count">Events, up to {{ ambientMax }}</label>
                            <div class="m-input">
                                <input id="ambient-count" v-model.number="ambientForm.count" type="number" min="1" :max="ambientMax" required />
                            </div>
                        </div>
                    </div>

                    <div v-if="ambientForm.errors.seed" class="field-error">{{ ambientForm.errors.seed }}</div>
                    <div v-if="ambientForm.errors.count" class="field-error">{{ ambientForm.errors.count }}</div>

                    <button
                        type="submit"
                        class="stack-btn primary"
                        style="width: 100%"
                        :disabled="busy || blockedBy !== null"
                        :title="blockedBy ?? 'A burst across every estate, drawn from the seed: one alert in four, the rest arrivals. The same seed fires the same events with the same keys, so pressing twice does not double the queue.'"
                    >
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>{{ ambientForm.processing ? 'Simulating…' : 'Run this night' }}</span>
                    </button>
                </form>

                <div class="preview-note">
                    The same seed always produces the same night: the same estates, the same kinds, the same
                    idempotency keys. Run it twice and the endpoints hand back the rows they already made — the
                    summary below says how many were new. Change the seed for a different night.
                </div>
            </div>
        </div>

        <div class="sim-results">
            <div class="form-sec-head">
                What the endpoints answered<template v-if="summary"> — {{ summary }}</template>
            </div>

            <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

            <EmptyState
                v-else-if="state.isDenied.value"
                variant="denied"
                title="The simulator is not part of your role’s access"
                body="Firing an event into a client’s live dispatch queue is a platform act, and only the Director holds it. The dispatch screens themselves open to any role that holds Dispatch."
            />

            <EmptyState
                v-else-if="state.isError.value"
                variant="error"
                title="The simulator could not be read"
                body="The last results could not be loaded. Nothing has been fired by this read — every event goes through an endpoint you press for, and none was pressed."
                action-label="Try again"
                @action="router.reload()"
            />

            <EmptyState
                v-else-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="Nothing matches"
                body="This table has no filter, so this state is only ever forced for review. Fire an event to see what the endpoint answers."
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing fired yet"
                body="Raise an alert, record an arrival, change the shifts, or run a seeded night. Each row that appears here is an endpoint’s own answer — a status code and what it made — and the dispatch screens then show exactly that, badged as simulated."
            />

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Event</th>
                        <th>Answer</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in results" :key="i">
                        <td class="num-cell">{{ row.at ?? '—' }}</td>
                        <td>
                            <span class="action-tag access">{{ kindLabel(row.kind) }}</span>
                            {{ row.label }}
                        </td>
                        <td>
                            <div class="status-badge" :class="outcomeClass(row.outcome)">{{ outcomeLabel(row) }}</div>
                        </td>
                        <td>{{ row.detail }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal, the same removals the add-guard form makes: the sheet draws
 * every field as a <div class="m-input"> around typed text, and a real input
 * or select arrives wearing the browser's own chrome.
 */
.m-input input,
.m-input select {
    flex: 1;
    min-width: 0;
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

.m-input input[type='number']::-webkit-inner-spin-button,
.m-input input[type='number']::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.stack-btn.outline {
    border: 1.5px solid var(--navy-200);
}

button.stack-btn[disabled] {
    cursor: not-allowed;
    opacity: 0.6;
}

/*
 * AUTHORED BELOW THIS LINE. No board draws this screen, so what it adds is kept
 * to the tokens the boards define and the type sizes the sheet already uses.
 */
.sim-byline {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.5;
}

.sim-form {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding-bottom: 18px;
    margin-bottom: 18px;
    border-bottom: 1px solid var(--navy-100);
}

.sim-form:last-child {
    padding-bottom: 0;
    margin-bottom: 0;
    border-bottom: 0;
}

.sim-form .stack-btn {
    align-self: flex-start;
}

.sim-check {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0 0 14px;
    cursor: pointer;
}

.sim-check input {
    margin: 3px 0 0;
    flex: 0 0 auto;
}

.sim-results {
    margin-top: 18px;
}

.sim-results .action-tag {
    margin-right: 8px;
}
</style>
