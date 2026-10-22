<script setup>
import { computed, reactive, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'

/**
 * Guard tracking (Hassam, 12 October 2026, guard live tracking; no board draws it).
 *
 * HOW OFTEN THIS ESTATE'S ON-DUTY OFFICERS' PHONES SEND THEIR POSITION, AND HOW LONG A SILENCE IS ALLOWED before the
 * control room is told "No signal". Each value is the estate's own choice or the platform's default, and only choices
 * that keep the officers' consent words true are offered ("about once a minute while you move" in 2026-10-10, "every 45
 * to 90 seconds while you move, less often while you stand still or your battery is low" from 2026-10-12): the server
 * holds the same bounds and refuses anything else (`SiteTrackingSettings`).
 *
 * M96 LEVELS. A reader (Settings view) has no Save: a "View only" badge and one line saying what they may do, every
 * value shown, inert. An editor's Save is greyed with "Nothing has changed yet." beside it until something has; pressing
 * it asks first, naming every change, and only the question's own button saves. A refusal of that save is said inside
 * the question, in its amber band (D-235), and the question stays open.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    sections: { type: Array, required: true },
    /** What this estate chose itself; null where it follows the platform's default. */
    chosen: { type: Object, required: true },
    defaults: { type: Object, required: true },
    bounds: { type: Object, required: true },
    /** "No signal" never sooner than the slowest reading plus this many seconds (Hassam, 12 Oct). */
    noSignalMargin: { type: Number, required: true },
    canEdit: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

const page = usePage()

useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

// A settings form: re-reading it under somebody's changes would lose them (M18H §7.2).
useLiveKind('form')

const state = useScreenState({ rows: () => Object.keys(props.defaults).length })

const retry = () => router.reload()

/* ---- the five, in words ---- */

const every = (seconds) => {
    if (seconds < 60 || seconds % 60 !== 0) {
        return `every ${seconds} seconds`
    }

    const minutes = seconds / 60

    return minutes === 1 ? 'every minute' : `every ${minutes} minutes`
}

/** A span in words: "7 minutes", "1 minute", "75 seconds". */
const span = (seconds) => {
    const minutes = seconds / 60

    return Number.isInteger(minutes) ? `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}` : `${seconds} seconds`
}

const after = (seconds) => `after ${span(seconds)}`

const ROWS = [
    {
        key: 'moving_interval_s',
        label: 'While the officer moves',
        help: 'How often the phone sends while the officer walks or drives.',
        options: [45, 60, 75, 90],
        words: every,
    },
    {
        key: 'stationary_interval_s',
        label: 'While the officer stands still',
        help: 'How often the phone sends while the officer has not moved for a few minutes.',
        options: [120, 180, 300, 600, 900],
        words: every,
    },
    {
        key: 'low_battery_pct',
        label: 'The low battery line',
        help: 'Below this, the phone sends less often, unless it is charging.',
        options: [10, 15, 20, 25, 30],
        words: (pct) => `below ${pct}%`,
    },
    {
        key: 'low_battery_interval_s',
        label: 'Below the low battery line',
        help: 'How often the phone sends while its battery is below the line.',
        options: [120, 180, 300, 600, 900],
        words: every,
    },
    {
        key: 'no_signal_after_s',
        label: 'No signal',
        help: 'How long an officer on duty may go without sending a position before the control room is told “No signal”.',
        options: [120, 180, 300, 420, 600, 900, 1200, 1800],
        words: after,
    },
]

const cap = (text) => text.charAt(0).toUpperCase() + text.slice(1)

/** A choice's words: "Every 45 seconds", or "Platform default: every 45 seconds" for a value the estate has not chosen. */
const choiceWords = (row, value) => (value === null ? `Platform default: ${row.words(props.defaults[row.key])}` : cap(row.words(value)))

/* ---- the draft ---- */

const draft = reactive(Object.fromEntries(ROWS.map((row) => [row.key, props.chosen[row.key] ?? null])))

/** What would apply: the estate's choice, else the platform's default. */
const applies = (key) => draft[key] ?? props.defaults[key]

/*
 * "NO SIGNAL" NEVER SOONER THAN THE SLOWEST READING PLUS TWO MINUTES (Hassam, 12 Oct): the floor follows the readings
 * chosen above it, and the page says the consequence rather than only refusing.
 */
const slowest = computed(() => Math.max(applies('moving_interval_s'), applies('stationary_interval_s'), applies('low_battery_interval_s')))
const noSignalFloor = computed(() => slowest.value + props.noSignalMargin)
const noSignalShort = computed(() => applies('no_signal_after_s') < noSignalFloor.value)

const consequence = computed(() =>
    noSignalShort.value
        ? `Readings ${every(slowest.value)}, so “No signal” cannot be raised before ${span(noSignalFloor.value)} of silence.`
        : `Readings ${every(slowest.value)}, so “No signal” is raised ${after(applies('no_signal_after_s'))} of silence.`,
)

/**
 * The options a row offers: its own list within the bounds, and a value saved some other way, so nothing is hidden. "No
 * signal" also offers its floor itself, and any choice under the floor is drawn but cannot be picked.
 */
const optionsOf = (row) => {
    const [low, high] = props.bounds[row.key] ?? [0, Infinity]
    const saved = props.chosen[row.key]
    const base = row.key === 'no_signal_after_s' ? [...row.options, noSignalFloor.value] : row.options
    const values = [...new Set([...base, ...(saved === null ? [] : [saved])])].filter((value) => value >= low && value <= high)

    return values.sort((a, b) => a - b)
}

/** Whether a choice can be picked: only "No signal" has a floor that moves. */
const below = (row, value) => row.key === 'no_signal_after_s' && value < noSignalFloor.value

/** Select values are strings: '' is the platform default. */
const picked = (row) => (draft[row.key] === null ? '' : String(draft[row.key]))

const pick = (row, value) => {
    if (props.canEdit) {
        draft[row.key] = value === '' ? null : Number(value)
    }
}

const changes = computed(() =>
    ROWS.filter((row) => draft[row.key] !== (props.chosen[row.key] ?? null)).map((row) => ({
        key: row.key,
        text: `${row.label}: ${choiceWords(row, props.chosen[row.key] ?? null)} becomes ${choiceWords(row, draft[row.key]).charAt(0).toLowerCase()}${choiceWords(row, draft[row.key]).slice(1)}`,
    })),
)

/** Why Save is greyed, beside it (M96 level 2). Only ever asked of somebody who may save. */
const saveBlockedBy = computed(() => {
    if (changes.value.length === 0) {
        return 'Nothing has changed yet.'
    }

    return noSignalShort.value ? `Choose ${span(noSignalFloor.value)} or longer for “No signal”.` : null
})

/* ---- asking, then saving ---- */

const asking = ref(false)
const saving = ref(false)

const ask = () => {
    if (props.canEdit && saveBlockedBy.value === null) {
        asking.value = true
    }
}

const save = () => {
    if (saving.value) {
        return
    }

    saving.value = true
    router.post(window.location.pathname, { ...draft }, {
        preserveScroll: true,
        onSuccess: () => {
            asking.value = false
        },
        onFinish: () => {
            saving.value = false
        },
    })
}

/** The question's band: the server's refusal of the save, while the question is open (D-235). */
const refusal = computed(() => page.props.errors?.tracking ?? null)
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <template #actions>
            <!-- M96 level 3: a reader who can never save has no Save; the badge says what this screen is to them. -->
            <span v-if="!canEdit" class="view-badge">View only</span>

            <template v-else>
                <span v-if="saveBlockedBy !== null" class="save-why">{{ saveBlockedBy }}</span>
                <button
                    type="button"
                    class="btn-primary-sm"
                    :disabled="saveBlockedBy !== null"
                    title="See what changes before anything is saved."
                    @click="ask"
                >
                    <span>Save changes</span>
                </button>
            </template>
        </template>

        <div class="settings-layout">
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">{{ section.label }}</div>
                    <Link v-else :href="section.href" class="settings-nav-item">{{ section.label }}</Link>
                </template>
            </div>

            <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="1" />

            <EmptyState
                v-else-if="state.isDenied.value"
                variant="denied"
                title="Settings is not part of your role’s access"
                body="Guard tracking is kept in Settings, and your role does not include Settings."
            />

            <EmptyState
                v-else-if="state.isError.value"
                variant="error"
                title="Guard tracking could not be read"
                body="The settings did not answer. Nothing was changed, and officers’ phones keep sending as before. Trying again is safe."
                action-label="Try again"
                @action="retry"
            />

            <div v-else class="form-panel gt-panel">
                <p v-if="!canEdit" class="view-note">{{ blockedReason }}</p>

                <p class="gt-lead">
                    How often the phones of officers on duty at {{ estate.name }} send their position, and how long a
                    silence is allowed before the control room is told “No signal”. The choices stay inside what every
                    officer agreed to: readings every 45 to 90 seconds while moving, and less often while standing still
                    or on a low battery.
                </p>

                <div v-for="row in ROWS" :key="row.key" class="gt-row">
                    <div class="gt-text">
                        <label :for="`gt-${row.key}`" class="gt-label">{{ row.label }}</label>
                        <p class="gt-help">{{ row.help }}</p>
                        <!-- The consequence, said rather than only refused (Hassam, 12 Oct). -->
                        <p v-if="row.key === 'no_signal_after_s'" class="gt-consequence" :class="{ 'gt-consequence--short': noSignalShort }">
                            {{ consequence }}
                        </p>
                    </div>
                    <select
                        :id="`gt-${row.key}`"
                        class="gt-select"
                        :value="picked(row)"
                        :disabled="!canEdit"
                        :title="canEdit ? undefined : blockedReason"
                        @change="pick(row, $event.target.value)"
                    >
                        <option value="" :disabled="below(row, defaults[row.key])">{{ choiceWords(row, null) }}</option>
                        <option v-for="value in optionsOf(row)" :key="value" :value="String(value)" :disabled="below(row, value)">
                            {{ choiceWords(row, value) }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <!-- The question before the save: every change, in words (a short confirmation, so a dialog). -->
        <ConsoleDialog
            :open="asking"
            title="Save these guard tracking changes?"
            confirm-label="Save changes"
            busy-label="Saving…"
            :busy="saving"
            :error="refusal"
            @confirm="save"
            @cancel="asking = false"
        >
            <ul class="gt-changes">
                <li v-for="change in changes" :key="change.key">{{ change.text }}</li>
            </ul>
            <p class="gt-help">It applies to every officer on duty at {{ estate.name }}, from the next time each phone asks the server.</p>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/* Default removal for the real controls the sheet draws as <div>s (D-045). */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

a.settings-nav-item {
    text-decoration: none;
    display: block;
}

/* AUTHORED BELOW THIS LINE: no board draws this page. Tokens the console already carries. */

.gt-panel {
    min-width: 0;
}

.gt-lead {
    margin: 0 0 16px;
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.6;
}

.gt-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px 20px;
    padding: 14px 0;
    border-top: 1px solid var(--navy-100);
}

.gt-text {
    min-width: 0;
    flex: 1 1 260px;
}

.gt-label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
}

.gt-help {
    margin: 3px 0 0;
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.5;
}

/* The consequence of the readings for "No signal": a fact, in navy; amber when the choice falls under the floor. */
.gt-consequence {
    margin: 6px 0 0;
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-700);
    line-height: 1.5;
}

.gt-consequence--short {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 8px;
    background: var(--amber-100);
    color: var(--navy-900);
}

.gt-select {
    flex: 0 0 auto;
    min-width: 240px;
    height: 36px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font-family: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.gt-select:disabled {
    background: var(--navy-100);
    color: var(--slate-500);
    cursor: not-allowed;
}

.gt-changes {
    margin: 0 0 10px;
    padding: 10px 14px 10px 28px;
    border-radius: 10px;
    background: var(--navy-100);
    color: var(--navy-900);
    font-size: 12.5px;
    line-height: 1.7;
}

/* What a reader may do here, at the top: a statement, not a warning. */
.view-note {
    margin: 0 0 14px;
    padding: 9px 13px;
    border-radius: 10px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.5;
}

.view-badge {
    display: inline-flex;
    align-items: center;
    height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 700;
}

.save-why {
    margin-right: 10px;
    font-size: 12px;
    font-weight: 600;
    color: var(--slate-500);
}

/* ON A PHONE: the section list above the page, and each choice under its words, full width. */
@media (max-width: 760px) {
    .settings-layout.settings-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 14px;
    }

    .settings-nav.settings-nav {
        flex-direction: row;
        overflow-x: auto;
    }

    .settings-nav-item.settings-nav-item {
        flex: 0 0 auto;
        width: auto;
        white-space: nowrap;
    }

    .gt-row {
        flex-direction: column;
        align-items: stretch;
    }

    .gt-select {
        min-width: 0;
        width: 100%;
    }
}
</style>
