<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import Subnav from './Subnav.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Security incident log — board screen super-admin-27.
 *
 * The record a security company is judged on: by an insurer after a claim, by a
 * client deciding whether to renew, and by the PSRA. These are incidents on
 * posts Gemini staffs involving guards it employs, which is why the log is
 * central and readable across clients without opening an estate database.
 *
 * OPEN IS A STATE WITH CONSEQUENCES. An incident stays open until somebody
 * records what was done about it, and the badge says so because an incident
 * nobody closed is the one that gets asked about later.
 *
 * LOGGING IS A STRUCTURED INTAKE (12 §2, item 27): the client, when, what kind,
 * how serious, what happened, and the guard on post where there was one. It is
 * authored — the board draws a log, not a form — and closed on a fresh GET, so
 * it costs nothing against the board in the state the board draws.
 *
 * DURESS ALERTS ARE NOT INCIDENTS. The board spends its closing panel saying
 * none are on record, and that is the reassurance rather than an empty state:
 * a duress alert is a life-safety event with its own table, its own broadcast
 * channel and its own response screen. Folding them into a case log would put
 * a panic button behind case management.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    incidents: { type: Array, required: true },
    /** The log as the shared table reads it (M32 F5). */
    table: { type: Object, required: true },
    duress_count: { type: Number, required: true },
    canLog: { type: Boolean, required: true },
    clients: { type: Array, required: true },
    officers: { type: Array, required: true },
    severities: { type: Object, required: true },
    writeDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.incidents.length,
})

const COLUMNS = [
    { key: 'date', label: 'Date', sortable: true },
    { key: 'estate', label: 'Client', sortable: true },
    { key: 'kind', label: 'Type', sortable: true },
    { key: 'guard', label: 'Guard involved', sortable: true },
    { key: 'severity', label: 'Severity', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'view', label: '' },
]

/*
 * LOGGING IS THE CONSOLE'S MODAL SINCE M95 §1 (M98 pass): it opened as a panel above the log, which made the page
 * longer and announced nothing. Its errors sit under their own fields, the server's own refusal inside the dialog,
 * and what was typed is never thrown away without asking.
 */
const logging = ref(false)

const form = useForm({
    tenant_id: props.clients[0]?.id ?? '',
    guard_id: '',
    occurred_at: '',
    kind: '',
    severity: 'low',
    detail: '',
})

/* The guards at the chosen client, first, then everyone else this role covers. */
const officersForClient = computed(() => props.officers.filter((o) => o.tenant_id === form.tenant_id))
const otherOfficers = computed(() => props.officers.filter((o) => o.tenant_id !== form.tenant_id))

/** Why Log incident in the modal cannot be pressed yet, said under it (M96 level 2). */
const logBlocked = computed(() => {
    if (form.tenant_id === '') {
        return 'Choose the client first.'
    }

    if (form.occurred_at === '') {
        return 'Say when it happened.'
    }

    if (form.kind.trim() === '') {
        return 'Say what kind of incident it was.'
    }

    return form.detail.trim() === '' ? 'Write what happened.' : null
})

const openLogging = () => {
    if (!props.canLog) {
        return
    }

    form.clearErrors()
    logging.value = true
}

const closeLogging = () => {
    logging.value = false
    form.clearErrors()
}

const submit = () => {
    form.transform((data) => ({ ...data, guard_id: data.guard_id === '' ? null : data.guard_id })).post('/guards/incidents', {
        preserveScroll: true,
        onSuccess: () => {
            logging.value = false
            form.reset()
        },
    })
}

/* The five states (M98 §3.8): a refused role and a failed read are said in words. */
const retry = () => router.reload()

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['incident'] })
</script>

<template>
    <Head title="Security incident log" />

    <GeminiConsole title="Security incident log">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <template #actions>
            <!-- M96 level 3: a role that can never log an incident has no Log incident at all. -->
            <button
                v-if="canLog"
                type="button"
                class="btn-primary-sm"
                title="Record an incident on a Gemini-staffed post."
                @click="openLogging"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Log incident</span>
            </button>
        </template>

        <Subnav active="incidents" />

        <!--
          Log an incident, in the console's modal (M95 §1). Each error sits under its own field; the server's own refusal
          is said inside the dialog, so nothing refused is lost on the page behind it.
        -->
        <ConsoleDialog
            :open="logging"
            title="Log an incident"
            size="form"
            confirm-label="Log incident"
            busy-label="Logging…"
            :busy="form.processing"
            :dirty="form.isDirty"
            discard-title="Discard this incident?"
            :confirm-blocked-reason="logBlocked"
            :error="form.errors.incident ?? null"
            @confirm="submit"
            @cancel="closeLogging"
        >
            <div class="inc-head">
                An incident record is evidence: an insurer, the client and the PSRA read it. Every field but the guard is
                required, and the record stays open until what was done about it is written down.
            </div>

            <RequiredMark legend />

            <div class="inc-fields">
                <div class="inc-field">
                    <label for="inc-client">Client <RequiredMark /></label>
                    <select id="inc-client" v-model="form.tenant_id" required>
                        <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                    </select>
                    <div v-if="form.errors.tenant_id" class="inc-error">{{ form.errors.tenant_id }}</div>
                </div>
                <div class="inc-field">
                    <label for="inc-when">When it happened <RequiredMark /></label>
                    <input id="inc-when" v-model="form.occurred_at" type="datetime-local" required />
                    <div v-if="form.errors.occurred_at" class="inc-error">{{ form.errors.occurred_at }}</div>
                </div>
                <div class="inc-field">
                    <label for="inc-kind">What kind of incident <RequiredMark /></label>
                    <input
                        id="inc-kind"
                        v-model="form.kind"
                        type="text"
                        maxlength="160"
                        placeholder="e.g. Attempted unauthorized access"
                        required
                    />
                    <div v-if="form.errors.kind" class="inc-error">{{ form.errors.kind }}</div>
                </div>
                <div class="inc-field">
                    <label for="inc-severity">Severity <RequiredMark /></label>
                    <select id="inc-severity" v-model="form.severity" required>
                        <option v-for="(label, key) in severities" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <div v-if="form.errors.severity" class="inc-error">{{ form.errors.severity }}</div>
                </div>
                <div class="inc-field inc-field--wide">
                    <label for="inc-guard">Guard on post (optional)</label>
                    <select id="inc-guard" v-model="form.guard_id">
                        <option value="">No guard involved</option>
                        <optgroup v-if="officersForClient.length" label="Posted at this client">
                            <option v-for="o in officersForClient" :key="o.id" :value="o.id">{{ o.label }}</option>
                        </optgroup>
                        <optgroup v-if="otherOfficers.length" label="Elsewhere">
                            <option v-for="o in otherOfficers" :key="o.id" :value="o.id">{{ o.label }}</option>
                        </optgroup>
                    </select>
                    <div v-if="form.errors.guard_id" class="inc-error">{{ form.errors.guard_id }}</div>
                </div>
                <div class="inc-field inc-field--wide">
                    <label for="inc-detail">What happened <RequiredMark /></label>
                    <textarea id="inc-detail" v-model="form.detail" rows="4" maxlength="4000" placeholder="e.g. A man walked the perimeter twice and left when challenged." required />
                    <div v-if="form.errors.detail" class="inc-error">{{ form.errors.detail }}</div>
                </div>
            </div>
        </ConsoleDialog>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="6" />

        <!-- The five states (M98 §3.8): a refused role and a failed read, said in words, with nothing of the log. -->
        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="The incident log is not part of your role’s access"
            body="It holds incidents on every client’s posts, so it opens only to roles that look after guards and their posts. A Super Admin can grant it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The incident log could not be read"
            body="The platform database did not answer. This is a read that failed, and nothing has been changed. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No incidents recorded"
            body="Nothing has been logged against a Gemini-staffed post. Incidents appear here as they are recorded, across every client."
        />

        <!-- Newest first unless a heading asks otherwise, sorted and paged by the server (M32 F5). -->
        <DataTable v-else :columns="COLUMNS" :rows="incidents" :table="table" noun="incidents">
            <!-- Severity is a scale, not a status (Hassam, 11 Oct): High is a red outline with an icon and the word. -->
            <template #cell-severity="{ row: incident }">
                <div class="severity-badge sev" :class="incident.severity">
                    <BoardIcon v-if="incident.severity === 'high'" name="alert" :stroke="2" class="sev-icon" />
                    <span>{{ incident.severity_label }}</span>
                </div>
            </template>
            <template #cell-status="{ row: incident }">
                <div class="status-badge" :class="incident.status">{{ incident.status_label }}</div>
            </template>
            <template #cell-view="{ row: incident }">
                <Link :href="`/guards/incidents/${incident.id}`" class="text-link-sm">View</Link>
            </template>
        </DataTable>

        <!--
          The duress panel. Shown when nothing has been triggered, because
          "nothing has happened" is the single most reassuring thing this screen
          can report and a blank space does not report it.
        -->
        <div v-if="duress_count === 0" class="empty-state">
            <BoardIcon name="shield" :stroke="1.7" />
            <div class="es1">No duress alerts on record</div>
            <div class="es2">
                Every guard's duress button is monitored live through Gate Activity. Nothing has been triggered across
                any client to date.
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal first. The board draws the topbar action and the row link as
 * <div>s; as real controls the button arrives with a border, a face and Arial,
 * and the row link sits inline on a baseline where the board's is a block.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

a.text-link-sm {
    display: block;
    text-decoration: none;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE: the intake, which the board does not draw. Closed on
 * a fresh GET, and kept to the tokens the Gemini boards define.
 */
/*
 * A field's error, under its field, in amber (Hassam, 11 Oct: red is reserved for panic and life safety, and a field
 * the server refused is an ordinary error).
 */
.inc-error {
    font-size: 11px;
    font-weight: 600;
    line-height: 1.45;
    color: var(--amber-700);
}

.inc-head {
    margin-bottom: 12px;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 800px;
}

.inc-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.inc-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.inc-field--wide {
    grid-column: span 2;
}

.inc-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.inc-field input,
.inc-field select,
.inc-field textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 7px 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.inc-field input,
.inc-field select {
    height: 33px;
    padding: 0 10px;
}

/*
 * SEVERITY IS A SCALE, NOT A STATUS (Hassam, 11 Oct): Low neutral grey, Medium solid amber, High a red OUTLINE with an
 * icon and the word, never solid saturated red, which is the open panic's alone. `.sev` outranks the board's sheet.
 */
.sev.severity-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.sev.severity-badge.low {
    background: var(--white);
    color: var(--slate-600);
    box-shadow: inset 0 0 0 1px var(--slate-300);
}

.sev.severity-badge.med {
    background: var(--amber-600);
    color: var(--navy-900);
}

.sev.severity-badge.high {
    background: var(--white);
    color: var(--red-700);
    box-shadow: inset 0 0 0 1.5px var(--red-700);
}

.sev-icon {
    width: 12px;
    height: 12px;
}

/* In the modal on a phone, the fields one under another. */
@media (max-width: 640px) {
    .inc-fields {
        grid-template-columns: 1fr;
    }

    .inc-field--wide {
        grid-column: auto;
    }
}
</style>
