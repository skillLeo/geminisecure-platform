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
 * Resident app access (the user, 8 Oct 2026); no board draws it. What a Dependent and a Helper may each do in the
 * Resident App, a Helper's default days and hours, and who is told when one of them comes through the gate: the
 * estate's own rules, in one place, changed here without a release (App\Api\AccessRules).
 *
 * WHAT NOBODY CAN CHANGE IS SHOWN, WITH WHY: a Member has everything, panic and a person's own e-Pass are never taken,
 * only the primary manages the household's people. So a committee reads the whole rule, not half of it.
 *
 * MONEY, SECURITY AND PRIVACY ARE MARKED on the rows that touch them. A row that makes no sense without another (paying
 * without seeing what is owed) says so and is closed with it, and is saved closed: what is saved is what is drawn.
 *
 * THESE DECIDE WHAT PEOPLE MAY DO, SO NOTHING IS SAVED WITHOUT BEING NAMED FIRST (the Settings lane, 12 Oct 2026): Save
 * asks, listing every change in words ("Helper gains “Answer the gate”", "A Helper's days: Mon to Fri becomes Mon to
 * Sat"), and only the question's own button saves. A refusal of that save is said inside the question, in its amber band
 * (D-235), and the question stays open. One save is one audit entry, gated on Settings configure.
 *
 * M96 LEVELS. A reader has no Save: a "View only" badge and one line saying what they may do; every switch is drawn,
 * inert, with the reason on it. An editor's Save is greyed with "Nothing has changed yet." beside it until something has.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    sections: { type: Array, required: true },
    groups: { type: Array, required: true },
    fixed: { type: Array, required: true },
    helper_days: { type: Array, required: true },
    helper_from: { type: String, required: true },
    helper_to: { type: String, required: true },
    entry: { type: Object, required: true },
    levelsOpen: { type: Boolean, required: true },
    levelsClosedReason: { type: String, required: true },
    canEdit: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

const page = usePage()

useWireframe('community-admin-08-reports-unit-claims-and-notices')
// A settings form: re-reading it under somebody's changes would lose them (M18H §7.2).
useLiveKind('form')

const state = useScreenState({ rows: () => props.groups.length })

const retry = () => router.reload()

const DAYS = [
    [1, 'Mon'],
    [2, 'Tue'],
    [3, 'Wed'],
    [4, 'Thu'],
    [5, 'Fri'],
    [6, 'Sat'],
    [7, 'Sun'],
]

/** Every half hour of a day, as the app offers them. */
const TIMES = Array.from({ length: 48 }, (_, i) => {
    const h = Math.floor(i / 2)
    const m = i % 2 ? '30' : '00'
    const value = `${String(h).padStart(2, '0')}:${m}`
    const label = `${h % 12 === 0 ? 12 : h % 12}:${m} ${h < 12 ? 'AM' : 'PM'}`

    return { value, label }
})

const WHO = [
    { value: 'primary', label: 'The primary resident' },
    { value: 'members', label: 'Every Member of the household' },
    { value: 'nobody', label: 'Nobody' },
]

const FLAGS = { money: 'Money', security: 'Security', privacy: 'Privacy' }

const LEVEL_WORD = { dependent: 'Dependent', helper: 'Helper' }

const draft = reactive({
    dependent: Object.fromEntries(props.groups.map((g) => [g.key, g.dependent])),
    helper: Object.fromEntries(props.groups.map((g) => [g.key, g.helper])),
    helper_days: [...props.helper_days],
    helper_from: props.helper_from,
    helper_to: props.helper_to,
    entry: { ...props.entry },
})

/** "Needs …": a row that cannot be had without another is drawn off while that one is. */
const needsOff = (level, group) => {
    const needed = props.groups.find((g) => g.label === group.needs)

    return needed ? !draft[level][needed.key] : false
}

const isOn = (level, group) => draft[level][group.key] && !needsOff(level, group)

/** The same, of what is saved. */
const savedOn = (level, group) => {
    const needed = props.groups.find((g) => g.label === group.needs)

    return group[level] && !(needed && !needed[level])
}

const flip = (level, key) => {
    const group = props.groups.find((g) => g.key === key)

    // A row that needs another is not pressed while that one is off (DR-025): it would change what Save stores, unseen.
    if (props.canEdit && !(group && needsOff(level, group))) {
        draft[level][key] = !draft[level][key]
    }
}

/** Why a switch cannot be pressed, in one line, or nothing when it can (Law 1). */
const switchBlocked = (level, group) => {
    if (!props.canEdit) {
        return props.blockedReason
    }

    return needsOff(level, group) ? `Turn on “${group.needs}” for the ${LEVEL_WORD[level]} first.` : null
}

const toggleDay = (day) => {
    if (!props.canEdit) {
        return
    }

    draft.helper_days = draft.helper_days.includes(day) ? draft.helper_days.filter((d) => d !== day) : [...draft.helper_days, day].sort()
}

/* ---- every change, in words ---- */

const dayWords = (days) => (days.length === 0 ? 'no day' : DAYS.filter(([day]) => days.includes(day)).map(([, name]) => name).join(', '))
const timeWords = (value) => TIMES.find((t) => t.value === value)?.label ?? value
const whoWords = (value) => WHO.find((w) => w.value === value)?.label ?? value

const sameDays = (a, b) => a.length === b.length && a.every((day) => b.includes(day))

const changes = computed(() => {
    const out = []

    for (const level of ['dependent', 'helper']) {
        for (const group of props.groups) {
            const before = savedOn(level, group)
            const after = isOn(level, group)

            if (before !== after) {
                out.push({ key: `${level}.${group.key}`, text: `${LEVEL_WORD[level]} ${after ? 'gains' : 'loses'} “${group.label}”` })
            }
        }
    }

    if (!sameDays(draft.helper_days, props.helper_days)) {
        out.push({ key: 'days', text: `A Helper’s days: ${dayWords(props.helper_days)} becomes ${dayWords(draft.helper_days)}` })
    }

    if (draft.helper_from !== props.helper_from) {
        out.push({ key: 'from', text: `A Helper’s e-Pass works from ${timeWords(props.helper_from)}, now from ${timeWords(draft.helper_from)}` })
    }

    if (draft.helper_to !== props.helper_to) {
        out.push({ key: 'to', text: `A Helper’s e-Pass works until ${timeWords(props.helper_to)}, now until ${timeWords(draft.helper_to)}` })
    }

    for (const level of ['helper', 'dependent']) {
        if (draft.entry[level] !== props.entry[level]) {
            out.push({ key: `entry.${level}`, text: `Told when a ${LEVEL_WORD[level]} comes in: ${whoWords(props.entry[level])} becomes ${whoWords(draft.entry[level]).toLowerCase()}` })
        }
    }

    return out
})

/** Why Save is greyed, beside it (M96 level 2). Only ever asked of somebody who may save. */
const saveBlockedBy = computed(() => {
    if (changes.value.length === 0) {
        return 'Nothing has changed yet.'
    }

    return draft.helper_days.length === 0 ? 'Choose at least one day a Helper may come.' : null
})

/* ---- asking, then saving ---- */

const asking = ref(false)
const saving = ref(false)

const ask = () => {
    if (props.canEdit && saveBlockedBy.value === null) {
        asking.value = true
    }
}

/** What is saved is what is drawn: a row closed with the one it needs is saved closed. */
const posted = () => ({
    dependent: Object.fromEntries(props.groups.map((g) => [g.key, isOn('dependent', g)])),
    helper: Object.fromEntries(props.groups.map((g) => [g.key, isOn('helper', g)])),
    helper_days: draft.helper_days,
    helper_from: draft.helper_from,
    helper_to: draft.helper_to,
    entry: { ...draft.entry },
})

const save = () => {
    if (saving.value) {
        return
    }

    saving.value = true
    router.post(window.location.pathname, posted(), {
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
const refusal = computed(() => page.props.errors?.rules ?? null)
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <template #actions>
            <!-- M96 level 3: a reader who can never save has no Save; the badge says what this screen is to them. -->
            <span v-if="!canEdit" class="view-badge">View only</span>

            <template v-else>
                <span v-if="saveBlockedBy !== null" class="save-why">{{ saveBlockedBy }}</span>
                <button type="button" class="btn-primary-sm" :disabled="saveBlockedBy !== null" title="See every change before anything is saved." @click="ask">
                    <span>Save changes</span>
                </button>
            </template>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Settings is not part of your role’s access"
            body="These rules decide what household members may do in the Resident App, so they open only to a role that holds Settings."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="Resident app access could not be read"
            body="The rules did not answer. Nothing was changed, and every household keeps what it may do now. Trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <div v-else class="settings-layout">
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">{{ section.label }}</div>
                    <Link v-else :href="section.href" class="settings-nav-item">{{ section.label }}</Link>
                </template>
            </div>

            <div>
                <div v-if="!levelsOpen" class="ra-note" role="note">
                    <strong>Nobody is a Dependent or a Helper yet.</strong> {{ levelsClosedReason }} These rules apply from
                    the day they are switched on.
                </div>
                <!-- What a reader may do here: a statement, not a warning. -->
                <p v-if="!canEdit" class="ra-blocked">{{ blockedReason }}</p>

                <div class="notif-group">
                    <div class="notif-group-head">What a Dependent and a Helper may do</div>
                    <div class="notif-panel">
                        <div v-for="group in groups" :key="group.key" class="notif-row">
                            <div class="notif-txt">
                                <div class="nt1">
                                    {{ group.label }}
                                    <span v-if="group.flag" class="ra-flag" :class="`ra-flag--${group.flag}`">{{ FLAGS[group.flag] }}</span>
                                </div>
                                <div class="nt2">
                                    Platform default: Dependent {{ group.dependent_default ? 'yes' : 'no' }} · Helper {{ group.helper_default ? 'yes' : 'no' }}<template v-if="group.needs"> · needs “{{ group.needs }}”</template>
                                </div>
                            </div>
                            <div class="channel-switches">
                                <div v-for="level in ['dependent', 'helper']" :key="level" class="channel-sw">
                                    <button
                                        type="button"
                                        class="switch"
                                        :class="{ on: isOn(level, group) }"
                                        role="switch"
                                        :aria-checked="isOn(level, group)"
                                        :aria-label="`${group.label} · ${LEVEL_WORD[level]}`"
                                        :disabled="switchBlocked(level, group) !== null"
                                        :title="switchBlocked(level, group) ?? undefined"
                                        @click="flip(level, group.key)"
                                    >
                                        <span class="knob"></span>
                                    </button>
                                    <span>{{ LEVEL_WORD[level] }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="notif-group">
                    <div class="notif-group-head">What nobody can change</div>
                    <div class="notif-panel">
                        <div v-for="row in fixed" :key="row.label" class="notif-row">
                            <div class="notif-txt">
                                <div class="nt1">{{ row.label }}</div>
                                <div class="nt2">Member: {{ row.member }} · Dependent: {{ row.dependent }} · Helper: {{ row.helper }}. {{ row.why }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="notif-group">
                    <div class="notif-group-head">A Helper’s days and hours</div>
                    <div class="notif-panel">
                        <div class="notif-row ra-hours">
                            <div class="notif-txt">
                                <div class="nt1">When a Helper’s e-Pass works at the gate</div>
                                <div class="nt2">The estate’s default. A household can set its own helper’s days, hours and end date in the Resident App, and pause them at once.</div>
                            </div>
                            <div class="ra-days" role="group" aria-label="Days a helper may come">
                                <button
                                    v-for="[day, name] in DAYS"
                                    :key="day"
                                    type="button"
                                    class="ra-day"
                                    :class="{ on: draft.helper_days.includes(day) }"
                                    :aria-pressed="draft.helper_days.includes(day)"
                                    :disabled="!canEdit"
                                    :title="canEdit ? undefined : blockedReason"
                                    @click="toggleDay(day)"
                                >
                                    {{ name }}
                                </button>
                            </div>
                            <div class="ra-times">
                                <label>
                                    <span>From</span>
                                    <select v-model="draft.helper_from" :disabled="!canEdit" :title="canEdit ? undefined : blockedReason">
                                        <option v-for="t in TIMES" :key="t.value" :value="t.value">{{ t.label }}</option>
                                    </select>
                                </label>
                                <label>
                                    <span>Until</span>
                                    <select v-model="draft.helper_to" :disabled="!canEdit" :title="canEdit ? undefined : blockedReason">
                                        <option v-for="t in TIMES" :key="t.value" :value="t.value">{{ t.label }}</option>
                                    </select>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="notif-group">
                    <div class="notif-group-head">Who is told when somebody comes in on their e-Pass</div>
                    <div class="notif-panel">
                        <div v-for="level in ['helper', 'dependent']" :key="level" class="notif-row">
                            <div class="notif-txt">
                                <div class="nt1">When a {{ LEVEL_WORD[level] }} comes through the gate</div>
                                <div class="nt2">A notification with their name, the gate and the time.</div>
                            </div>
                            <select v-model="draft.entry[level]" class="ra-who" :disabled="!canEdit" :title="canEdit ? undefined : blockedReason" :aria-label="`Who is told when a ${level} comes in`">
                                <option v-for="w in WHO" :key="w.value" :value="w.value">{{ w.label }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- The question before the save: every change, in words (a short confirmation, so a dialog). -->
        <ConsoleDialog
            :open="asking"
            title="Save these changes to what household members may do?"
            confirm-label="Save changes"
            busy-label="Saving…"
            :busy="saving"
            :error="refusal"
            @confirm="save"
            @cancel="asking = false"
        >
            <ul class="ra-changes">
                <li v-for="change in changes" :key="change.key">{{ change.text }}</li>
            </ul>
            <p class="ra-ask">The Resident App follows these rules from the next thing each person does in it. It is one change on the audit log, with your name.</p>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/* Default removal for the real controls the sheet draws as <div>s (as on Notification defaults), then the authored
 * pieces no board draws: the marks, the day chips, the time and recipient selects, from the boards' own tokens. */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

a.settings-nav-item {
    text-decoration: none;
    display: block;
}

button.switch {
    border: 0;
    padding: 0;
    cursor: pointer;
}

button.btn-primary-sm[disabled],
button.switch[disabled] {
    cursor: not-allowed;
}

button.btn-primary-sm[disabled] {
    opacity: 0.55;
}

.ra-note,
.ra-blocked {
    border-radius: 12px;
    padding: 12px 15px;
    font-size: 12.5px;
    line-height: 1.6;
    margin-bottom: 14px;
}

.ra-note {
    background: var(--amber-100);
    color: var(--navy-900);
    border: 1px solid var(--amber-500);
}

/* What a reader may do here: a statement, not a warning. */
.ra-blocked {
    background: var(--navy-100);
    color: var(--navy-700);
    font-weight: 600;
}

.ra-flag {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 700;
    background: var(--amber-100);
    color: var(--navy-900);
}

.ra-hours {
    flex-wrap: wrap;
    gap: 12px;
}

.ra-days {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.ra-day {
    border: 1px solid var(--slate-300);
    background: var(--white);
    color: var(--slate-600);
    border-radius: 999px;
    padding: 5px 11px;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

.ra-day.on {
    background: var(--navy-600);
    border-color: var(--navy-600);
    color: var(--white);
}

.ra-day[disabled] {
    cursor: not-allowed;
}

.ra-times {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.ra-times label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    color: var(--slate-600);
}

.ra-times select,
.ra-who {
    border: 1px solid var(--slate-300);
    border-radius: 9px;
    padding: 7px 10px;
    font-size: 13px;
    color: var(--navy-900);
    background: var(--white);
    max-width: 100%;
}

.ra-changes {
    margin: 0 0 10px;
    padding: 10px 14px 10px 28px;
    border-radius: 10px;
    background: var(--navy-100);
    color: var(--navy-900);
    font-size: 12.5px;
    line-height: 1.7;
}

.ra-ask {
    margin: 0;
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.5;
}

/* Level 3's badge, where Save would be; level 2's reason, beside the greyed Save. */
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

/*
 * ON A PHONE (checked at 390 px with the new shell, 12 Oct). The board keeps the section list beside the page, 200 px of
 * a 390 px screen, and every Settings page ran past the window. Below 760 px the list sits above the page as one row
 * that scrolls sideways, and the page takes the full width. Doubled classes outrank the board's own rules.
 */
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
}
</style>
