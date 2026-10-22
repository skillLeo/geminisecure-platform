<script setup>
import { computed } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'

/**
 * Schedule a meeting — board screen community-admin-12.
 *
 * A DRAFT, AND ONLY A DRAFT. `Governance::scheduleMeeting()`'s own docblock:
 * "writes a draft and nothing else." Saving this form never tells a single
 * household anything — that is a second, separate act, `publishMeeting()`,
 * taken from the register (`Meetings.vue`) once the draft is visible
 * alongside the meetings already announced. Board 12 draws one button and it
 * posts to `meeting.store`; there is no publish control on this screen at
 * all, and `canPublish` below is declared and never rendered for exactly
 * that reason.
 *
 * THE NOTICE PERIOD IS NEVER CHECKED HERE. A secretary sketching an AGM for a
 * fortnight's time has done nothing wrong, and a form that refused to save
 * would lose the agenda they had just typed — so nothing on this screen
 * blocks a save over the statutory notice period. What it DOES do is put the
 * same rule on the Date field's own title, before anything is typed, so the
 * refusal `publishMeeting()` will eventually give is legible a step earlier
 * rather than only after a draft has been saved and re-opened on the
 * register. See `dateHint` below.
 *
 * THREE FIELDS ARE REAL DEFAULTS AND FOUR ARE DELIBERATELY BLANK. Audience,
 * quorum and recording arrive from `Governance::schedulerDefaults()`, counted
 * or configured from real rows: the estate's own phase count, its configured
 * quorum percentage, its recording policy. The title arrives empty, the type's
 * own name offered as its example (Hassam, 12 Oct, batch 1 item 7: nothing typed
 * in for the Secretary; examples are placeholders, "e.g." first). Date, time and the agenda arrive as nothing, because
 * nobody has chosen a date, a time or an order of business yet, and
 * defaulting any of them the way board 12's own mock-up shows them would put
 * a decision on the record that no secretary made — the same reasoning
 * NewCharge.vue's due-date field already follows.
 *
 * THE QUORUM FIELD IS A READOUT, NOT A CONTROL. `scheduleMeeting()` always
 * writes `$settings->meeting_quorum_percent` onto the row, whatever a
 * request carries — a secretary choosing a different threshold per meeting
 * would be deciding a governance rule this screen has no authority to set.
 * So it is a `readonly` input, styled exactly as every other field, carrying
 * the reason on its own title rather than pretending to be an editable one.
 */
const props = defineProps({
    estate: { type: Object, required: true },

    /** Board 12's segmented control, in `Meeting::TYPES`' own order. */
    types: { type: Array, required: true },

    /** Every phase this estate has, for the Audience field's phase-scoped options. */
    phases: { type: Array, required: true },

    /** What this blank form starts holding — see the note above. */
    defaults: { type: Object, required: true },

    eligibleHouseholds: { type: Number, required: true },

    /** The committee's head count: what a committee meeting's quorum counts (M60 §C2). */
    committeeMembers: { type: Number, default: 0 },

    /** What a save must not silently ignore, though it may never refuse on it. */
    notice: { type: Object, required: true },

    canSchedule: { type: Boolean, required: true },

    /*
     * Declared and deliberately not rendered. Publishing happens on the
     * meeting register and nowhere else — a draft this screen has not yet
     * saved has nothing to publish, so there is no publish control here for
     * this flag to gate. An undeclared prop would fall through as an HTML
     * attribute rather than be ignored, so it is named here with its reason
     * instead of dropped.
     */
    canPublish: { type: Boolean, required: true },

    blockedReason: { type: String, required: true },
})

/*
 * Boards 9 to 12 share this sheet. Naming the wrong one renders this screen
 * with no form panel, no segmented control and no agenda rows at all.
 */
useWireframe('community-admin-03-elections-nominations-results-and-meetings')

// Scheduling a meeting is a form: nothing to go stale, and nothing may re-read it under the person's typing (M18H §7.2).
useLiveKind('form')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL — production
 * gives each estate its own hostname and local puts the estate key in the
 * path, so cutting the URL that served this page is right in both and cannot
 * address an estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

/*
 * Always populated. This is a blank form and not a list with a record behind
 * it — there is nothing here for `empty` to mean, and nothing here a filter
 * could apply to — so both are reachable only by forcing `?_state=`, exactly
 * as the sibling governance screens leave `denied` and `error` forceable
 * rather than wired to a signal nothing on this route ever sends.
 */
const state = useScreenState({ rows: () => 1 })

const form = useForm({
    type: props.defaults.type,
    title: '',

    // Blank, deliberately — see the docblock above.
    starts_on: '',
    starts_on_time: '',

    audience_scope: props.defaults.audience_scope,
    phase: null,

    // How long it runs (M56 §2: "In progress" is between its start and its end), and what it is about.
    duration_minutes: 120,
    description: '',

    /*
     * HOW IT IS HELD, AND WHERE (M10 §8).
     *
     * `venue` and `virtual_link` have been columns on `meetings` since the table
     * was created and this form drew NEITHER — so a secretary could schedule an
     * AGM and the notice went out with a date, a time and an audience, and
     * nothing about the place. A household reading it had nowhere to go.
     *
     * In person by default, because that is what every meeting already drafted
     * on this platform is: none of them carries a link, since no form could set
     * one.
     */
    held_as: 'in_person',
    venue: '',
    // "The venue is to be confirmed", ticked on purpose (M68 §2.7, GV-6): the one way an in-person meeting goes without one.
    venue_tbc: false,
    virtual_link: '',
    platform_name: '',

    recording_enabled: props.defaults.recording_enabled,
    recording_consent_notice: props.defaults.recording_consent_notice,

    // Empty — board 12's four rows are the design system's own illustration
    // of a filled-in agenda, not a draft anybody here has started.
    agenda: [],
})

/*
 * The one field the server actually asks for, composed from the two a date
 * input and a time input can bind to. `starts_on` and `starts_on_time` never
 * travel themselves — the server asked for one moment, not two strings.
 */
form.transform((data) => ({
    type: data.type,
    title: data.title,
    starts_at: data.starts_on === '' ? '' : `${data.starts_on}T${data.starts_on_time || '00:00'}`,
    duration_minutes: data.duration_minutes,
    description: data.description.trim() === '' ? null : data.description.trim(),
    audience_scope: data.audience_scope,
    phase: data.phase,

    held_as: data.held_as,

    // Trimmed to null rather than sent as "". The server's `required_if` and
    // `url` both treat an empty string as present, so a blank venue on an
    // in-person meeting would pass the requirement and store nothing.
    venue: data.venue.trim() === '' ? null : data.venue.trim(),
    venue_tbc: data.held_as === 'in_person' && data.venue_tbc && data.venue.trim() === '',
    virtual_link: data.held_as === 'in_person' || data.virtual_link.trim() === '' ? null : data.virtual_link.trim(),
    platform_name: data.held_as === 'other' && data.platform_name.trim() !== '' ? data.platform_name.trim() : null,

    recording_enabled: data.recording_enabled,
    recording_consent_notice: data.recording_consent_notice,
    agenda: data.agenda,
}))

/* ------------------------------------------------------------------ */
/* how it is held — and which of the two place fields that asks for    */
/* ------------------------------------------------------------------ */

const heldAsOptions = [
    { value: 'in_person', label: 'In person' },
    { value: 'zoom', label: 'Zoom' },
    { value: 'google_meet', label: 'Google Meet' },
    { value: 'teams', label: 'Microsoft Teams' },
    { value: 'other', label: 'Other online' },
]

const isOnline = computed(() => form.held_as !== 'in_person')

/*
 * Hybrid is not a sixth option. It is an online meeting that also has a venue —
 * two independent facts, so nothing has to keep a sixth choice in step with the
 * fields it governs. The caption says so where a secretary can act on it.
 */
const isHybrid = computed(() => isOnline.value && form.venue.trim() !== '')

/*
 * WHERE IT IS, OR THAT IT IS TO BE CONFIRMED (M68 §2.7, GV-6). The server refuses an in-person meeting with no venue
 * (unless "to be confirmed" was ticked) and an online one with no link; Schedule says so first, with the reason, rather
 * than being pressed into the refusal (Law 1).
 */
const placeBlockedBy = computed(() => {
    if (!isOnline.value) {
        return form.venue.trim() === '' && !form.venue_tbc ? 'Add the venue, or tick that it is to be confirmed.' : null
    }

    return form.virtual_link.trim() === '' ? 'Add the joining link.' : null
})

const placeHint = computed(() => {
    if (!isOnline.value) {
        return 'Everyone attends in person. Households will be told this address.'
    }

    return isHybrid.value
        ? 'Hybrid: households will be given the link and the address, and may choose.'
        : 'Online only. Add a venue as well to make it a hybrid meeting.'
})

/* ------------------------------------------------------------------ */
/* the segmented control */
/* ------------------------------------------------------------------ */

/*
 * THE TYPE DECIDES WHO IT IS FOR (M56 §2.5). An "Annual General Meeting" typed as a phase meeting for the whole estate
 * got a phase meeting's 7 days' notice instead of an AGM's 21. AGM and EGM are always the whole estate; a phase meeting
 * is always one phase, and the form asks which; a committee meeting is its members. `Meeting::audienceFor()` is the
 * same rule on the server.
 */
/*
 * THE TITLE'S EXAMPLE FOLLOWS THE TYPE (GV-2; batch 1 item 7). The title is typed by the Secretary, never filled in for
 * them; its placeholder offers the chosen type's own name, so a meeting is never saved under another type's name.
 */
const titleExample = computed(() => props.types.find((type) => type.value === form.type)?.title ?? props.defaults.title)

const selectType = (value) => {
    form.type = value

    // A phase meeting's phase is chosen, never taken for the secretary (GV-12): it quietly became Phase 1.
    if (value === 'phase') {
        form.audience_scope = 'phase'
    } else {
        form.audience_scope = value === 'committee' ? 'committee' : 'whole_estate'
        form.phase = null
    }
}

/* ------------------------------------------------------------------ */
/* audience — one field on the board, two columns on the row */
/* ------------------------------------------------------------------ */

const audienceOptions = computed(() => {
    if (form.type === 'phase') {
        return [
            // Asked, not answered (GV-12): until a phase is chosen, Schedule says so.
            { value: 'phase:', label: 'Choose a phase' },
            ...props.phases.map((phase) => ({ value: `phase:${phase}`, label: `${phase} only` })),
        ]
    }

    return form.type === 'committee'
        ? [{ value: 'committee', label: 'Committee members' }]
        : [{ value: 'whole_estate', label: props.defaults.audience_label }]
})

const audienceChoice = computed({
    get: () => (form.audience_scope === 'phase' ? `phase:${form.phase ?? ''}` : form.audience_scope),
    set: (value) => {
        if (value.startsWith('phase:')) {
            form.audience_scope = 'phase'
            form.phase = value.slice('phase:'.length) || null
        } else {
            form.audience_scope = value
            form.phase = null
        }
    },
})

/** A phase meeting with no phase chosen: Schedule is off and says why (GV-12, Law 1). */
const PHASE_MISSING = 'Choose which phase the meeting is for.'

const phaseMissing = computed(() => form.type === 'phase' && !form.phase)

/* ------------------------------------------------------------------ */
/* recording — one field on the board, two columns on the row */
/* ------------------------------------------------------------------ */

const recordingChoice = computed({
    get: () => (form.recording_enabled ? 'on' : 'off'),
    set: (value) => {
        form.recording_enabled = value === 'on'
        form.recording_consent_notice = value === 'on'
    },
})

/* ------------------------------------------------------------------ */
/* the agenda */
/* ------------------------------------------------------------------ */

const addAgendaItem = () => {
    form.agenda.push({ start_time: '', text: '' })
}

const removeAgendaItem = (index) => {
    form.agenda.splice(index, 1)
}

/** Every `agenda.*` error the server sent back, keyed as it sent them. */
const agendaErrors = computed(() => Object.entries(form.errors).filter(([key]) => key.startsWith('agenda.')))

/* ------------------------------------------------------------------ */
/* the notice period, surfaced before it is a refusal */
/* ------------------------------------------------------------------ */

/**
 * Why the Date field carries the title it does.
 *
 * `Governance::publishMeeting()` is what actually refuses a date inside the
 * statutory notice period, and drafting is never refused for it. This is the
 * same rule said a step earlier, on the field a secretary is about to type
 * into, so the refusal is legible before anything is saved rather than
 * after — and it duplicates nothing, because saving this form never checks
 * it.
 */
const dateHint = computed(() => `When the ${props.types.find((t) => t.value === form.type)?.label ?? 'meeting'} takes place.`)

/*
 * THE NOTICE PERIOD AT THE DATE PICKER (M56 §2.2): the field refuses a date inside the type's period and says the
 * earliest allowed date as the date is picked, not after a save. The period binds everyone and stays; what was wrong
 * was hearing of it only at Publish.
 */
const earliestDate = computed(() => props.notice.earliest[form.type])

const shortDate = (iso) => new Intl.DateTimeFormat('en-JM', { day: 'numeric', month: 'short' }).format(new Date(`${iso}T00:00:00`))

const noticeLine = computed(() => {
    const days = props.notice.days[form.type]

    return `Needs ${days} day${days === 1 ? '' : 's'}' notice. Earliest ${shortDate(earliestDate.value)}.`
})

const insideNotice = computed(() => form.starts_on !== '' && form.starts_on < earliestDate.value)

/* The ceiling beside the floor (GV-10): past the server's latest day, Schedule is off with the server's sentence. */
const pastLatest = computed(() => form.starts_on !== '' && props.notice.latest !== undefined && form.starts_on > props.notice.latest)

/**
 * Why Schedule meeting is greyed, said beside it in the topbar (M96 level 2).
 * A title and a day and time are asked as well (seen on the dev server, 12 October): with the venue filled, an empty
 * date left Schedule on, to be refused when pressed. Asked last, so every reason said before still comes first.
 *
 * A RULE A FIELD ALREADY SAYS IS SAID THERE ONCE (Hassam, 12 Oct, batch 1 item 5: "Needs 7 days' notice. Earliest
 * 18 Oct." appeared under Date and again in the top bar). Beside Schedule, these point to the field; the rule itself is
 * the field's own line, amber once it is broken.
 */
const saveBlockedBy = computed(() => {
    if (phaseMissing.value) {
        return 'Choose the phase under Audience.'
    }

    if (insideNotice.value) {
        return 'The date is too soon: see the Date field.'
    }

    if (pastLatest.value) {
        return 'The date is too far ahead: see the Date field.'
    }

    if (placeBlockedBy.value !== null) {
        return placeBlockedBy.value
    }

    if (form.title.trim() === '') {
        return 'Give the meeting a title.'
    }

    return form.starts_on === '' || form.starts_on_time === '' ? 'Choose the date and the time.' : null
})

/**
 * What the quorum counts, for the type chosen (M60 §C2): a committee's own meeting is a head count of the committee, any
 * other a share of the estate's lots ("25% of 1,999 eligible households", never the register's 2,000).
 */
const quorumLabel = computed(() =>
    form.type === 'committee'
        ? `${props.defaults.quorum_percent}% of ${props.committeeMembers} committee members`
        : `${props.defaults.quorum_percent}% of ${props.eligibleHouseholds.toLocaleString('en-US')} eligible households`
)

/** Why the Quorum field cannot be typed into: one line (Law 2). */
const quorumReason = 'Set by the estate\'s governance settings, not per meeting.'

/* ------------------------------------------------------------------ */
/* the preview panel */
/* ------------------------------------------------------------------ */

const eyebrow = computed(() => props.types.find((t) => t.value === form.type)?.eyebrow ?? '')

/**
 * The board's own card title: the estate, the type, the year.
 *
 * "Phoenix Park Village 1 — AGM 2026". Composed here rather than on the
 * server because it joins three facts this screen already has — the estate's
 * own name, the type the secretary just picked, the year off the date they
 * just typed — and joining given facts is this card's own presentation of
 * them, not a fourth one.
 */
const previewTitle = computed(() => {
    // The title typed, as households will read it (GV-12); the board's composed card title until there is one.
    if (form.title.trim() !== '') {
        return form.title.trim()
    }

    const typeLabel = props.types.find((t) => t.value === form.type)?.label ?? ''
    const year = form.starts_on === '' ? new Date().getFullYear() : Number(form.starts_on.slice(0, 4))

    return `${props.estate.name} · ${typeLabel} ${year}`
})

const weekdayMonth = new Intl.DateTimeFormat('en-JM', { weekday: 'long', day: 'numeric', month: 'long' })

/** "10:00" (24-hour, off the native time input) to "10:00 AM". */
const timeOfDay = (time) => {
    const [hourStr, minute] = time.split(':')
    const hour = Number(hourStr)
    const period = hour >= 12 ? 'PM' : 'AM'
    const twelve = hour % 12 === 0 ? 12 : hour % 12

    return `${twelve}:${minute} ${period}`
}

/** "Saturday 27 September · 10:00 AM · Whole estate", built from whatever is filled in so far. */
const previewSummary = computed(() => {
    const parts = []

    if (form.starts_on !== '') {
        parts.push(weekdayMonth.format(new Date(`${form.starts_on}T00:00:00`)))
    }

    if (form.starts_on_time !== '') {
        parts.push(timeOfDay(form.starts_on_time))
    }

    parts.push(audienceOptions.value.find((option) => option.value === audienceChoice.value)?.label ?? '')

    /*
     * WHERE, in the preview a secretary checks before saving (M10 §8).
     *
     * The preview is the only place on this screen that shows what a household
     * will be told, and it said nothing about the place because the form had no
     * place to say. It mirrors `Meeting::placeLine()` on the server, which is
     * what the notice and the Resident App actually render.
     */
    const venue = form.venue.trim()
    // "Other online" by the name typed, as `Meeting::platformLabel()` does on the server.
    const mode = form.held_as === 'other' && form.platform_name.trim() !== ''
        ? form.platform_name.trim()
        : heldAsOptions.find((option) => option.value === form.held_as)?.label ?? 'In person'

    if (!isOnline.value) {
        // "Venue to be confirmed" only when somebody ticked it (GV-6); an empty field nobody chose says nothing.
        parts.push(venue !== '' ? venue : form.venue_tbc ? 'Venue to be confirmed' : '')
    } else {
        parts.push(venue === '' ? mode : `${mode} · also in person at ${venue}`)
    }

    return parts.filter(Boolean).join(' · ')
})

const submit = () => {
    /*
     * A form submits on Enter from any field, and a disabled button does not
     * stop it. The route is gated on `estate.governance.create`, so this is
     * only about not sending a draft that is going to bounce.
     */
    if (!props.canSchedule || insideNotice.value || pastLatest.value || placeBlockedBy.value !== null || phaseMissing.value) {
        return
    }

    form.post(governance('/meetings'), { preserveScroll: true })
}

/** The lengths offered: what "In progress" runs to (M56 §2). */
const durations = [
    { value: 60, label: '1 hour' },
    { value: 90, label: '1½ hours' },
    { value: 120, label: '2 hours' },
    { value: 180, label: '3 hours' },
    { value: 240, label: '4 hours' },
]
</script>

<template>
    <Head title="Schedule a meeting" />

    <EstateConsole title="Schedule a meeting" :estate-name="estate.name" active="governance" :back="{ href: governance('/meetings'), label: 'Back to meetings' }">
        <!--
          M96 LEVELS. A role that can never schedule a meeting has no button at all (level 3). While the form breaks a
          rule the button is greyed and says which beside it (level 2).
        -->
        <template v-if="canSchedule" #actions>
            <span v-if="saveBlockedBy" class="ms-why">{{ saveBlockedBy }}</span>
            <button
                type="submit"
                form="meeting-form"
                class="btn-primary-sm"
                :disabled="form.processing || saveBlockedBy !== null"
                :title="saveBlockedBy ?? 'Save this meeting as a draft. It notifies nobody until it is published.'"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                    <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                </svg>
                <span>{{ form.processing ? 'Saving…' : 'Schedule meeting' }}</span>
            </button>
        </template>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="2" />

        <!--
          A role that can never schedule is not handed a form it could fill in and never save (M96 level 3; the final
          pass, 12 Oct: a Treasurer typing the address got every field and no Schedule button).
        -->
        <EmptyState
            v-else-if="state.isDenied.value || !canSchedule"
            variant="denied"
            title="Scheduling a meeting is not part of your role’s access"
            body="Only the roles that run the estate's meetings can draft one. Ask a committee officer if you need to."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This form could not be opened"
            body="The estate's own settings (its phases, its quorum rule, its notice periods) did not answer. Nothing has been drafted and nothing has been lost. Trying again is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <!--
          Five of the six. The board draws one blank form and nothing to
          filter it by, so `empty-filtered` cannot occur here — forcing it
          falls through to the form below, exactly as it falls through on the
          sibling governance screens where the board draws no filter. `empty`
          is forceable too, though nothing on this route ever sends it for
          real: a blank form is always ready to fill in.
        -->
        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nothing to draft yet"
            body="A meeting starts as a blank form, and this is already one: title, audience, quorum and recording carry the estate's own defaults, and there is no earlier step before it."
        />

        <form
            v-else
            id="meeting-form"
            class="ms-grid"
            @submit.prevent="submit"
        >
            <div class="form-panel">
                <!-- What the mark means, once, above the first field (batch 1 item 3). -->
                <RequiredMark legend />

                <div class="seg" role="radiogroup" aria-label="What kind of meeting this is">
                    <button
                        v-for="type in types"
                        :key="type.value"
                        type="button"
                        class="seg-item"
                        :class="{ active: form.type === type.value }"
                        role="radio"
                        :aria-checked="form.type === type.value"
                        @click="selectType(type.value)"
                    >
                        {{ type.label }}
                    </button>
                </div>

                <div class="m-field">
                    <label for="meeting-title">Meeting title <RequiredMark /></label>
                    <!--
                      The board draws this field .focused — its own
                      illustration of a title somebody has already typed —
                      and the ring follows the field having a value, exactly
                      as the sibling form pages bind it (see NewCharge.vue's
                      Unit field).
                    -->
                    <div class="m-input" :class="{ focused: form.title !== '' }">
                        <span>
                            <input id="meeting-title" v-model="form.title" type="text" required maxlength="160" :placeholder="`e.g. ${titleExample}`" />
                        </span>
                    </div>
                    <div v-if="form.errors.title" class="act-error">{{ form.errors.title }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="starts-on">Date <RequiredMark /></label>
                        <div class="m-input">
                            <span>
                                <input
                                    id="starts-on"
                                    v-model="form.starts_on"
                                    type="date"
                                    required
                                    :min="earliestDate"
                                    :max="notice.latest"
                                    :title="dateHint"
                                />
                            </span>
                        </div>
                        <!-- Said as the date is picked: the period, and the earliest day it allows (M56 §2.2); past the
                             latest day, the server's sentence instead (GV-10). -->
                        <div v-if="pastLatest" class="act-error">{{ notice.latest_line }}</div>
                        <div v-else :class="insideNotice ? 'act-error' : 'm-hint'">{{ noticeLine }}</div>
                        <div v-if="form.errors.starts_at" class="act-error">{{ form.errors.starts_at }}</div>
                    </div>

                    <div class="m-field">
                        <label for="starts-on-time">Time <RequiredMark /></label>
                        <div class="m-input">
                            <span>
                                <input id="starts-on-time" v-model="form.starts_on_time" type="time" required />
                            </span>
                        </div>
                    </div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="audience">Audience <RequiredMark v-if="form.type === 'phase'" /></label>
                        <div class="m-input">
                            <span>
                                <!-- The type decides it (M56 §2.5): only a phase meeting asks which phase. -->
                                <select id="audience" v-model="audienceChoice" :disabled="form.type !== 'phase'" :title="form.type === 'phase' ? 'Which phase the meeting is for.' : 'Set by the meeting type.'">
                                    <option v-for="option in audienceOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </span>
                        </div>
                        <div v-if="form.errors.audience_scope" class="act-error">{{ form.errors.audience_scope }}</div>
                        <div v-if="phaseMissing" class="act-error">{{ PHASE_MISSING }}</div>
                        <div v-else-if="form.errors.phase" class="act-error">{{ form.errors.phase }}</div>
                    </div>

                    <div class="m-field">
                        <label for="duration">Length</label>
                        <div class="m-input">
                            <span>
                                <select id="duration" v-model="form.duration_minutes" title="How long the meeting runs.">
                                    <option v-for="option in durations" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </span>
                        </div>
                        <div v-if="form.errors.duration_minutes" class="act-error">{{ form.errors.duration_minutes }}</div>
                    </div>
                </div>

                <div class="m-field">
                    <label for="description">What it is about (optional)</label>
                    <div class="m-input">
                        <span>
                            <textarea id="description" v-model="form.description" rows="2" maxlength="2000" title="Households read this with the agenda."></textarea>
                        </span>
                    </div>
                    <div v-if="form.errors.description" class="act-error">{{ form.errors.description }}</div>
                </div>

                <!--
                  HOW IT IS HELD, AND WHERE (M10 §8).

                  `venue` and `virtual_link` have been columns on `meetings`
                  since the table was created and this form drew neither, so a
                  notice went out with a date and no place. These three fields
                  are that gap: the mode, then whichever of the two places the
                  mode asks for.

                  A venue is offered on an online meeting as well, because a
                  meeting with both is HYBRID — a real arrangement rather than
                  an accident — and the caption says so where a secretary can
                  act on it.
                -->
                <!--
                  EVERY WAY IT CAN BE HELD, IN SIGHT (M12 §4.2: "make the online options and the joining-link field
                  visible without hunting"). This was a drop-down, so Zoom, Google Meet, Teams and Other online were
                  behind a click nobody knew to make. It is the board's own segmented control now — the one the
                  meeting type above already uses — and the joining link opens directly under it the moment an
                  online option is chosen.
                -->
                <div class="m-field">
                    <label id="held-as-label">How it is held</label>
                    <div class="seg seg-held" role="radiogroup" aria-labelledby="held-as-label">
                        <button
                            v-for="option in heldAsOptions"
                            :key="option.value"
                            type="button"
                            class="seg-item"
                            :class="{ active: form.held_as === option.value }"
                            role="radio"
                            :aria-checked="form.held_as === option.value"
                            @click="form.held_as = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div class="m-hint">{{ placeHint }}</div>
                    <div v-if="form.errors.held_as" class="act-error">{{ form.errors.held_as }}</div>
                </div>

                <!-- "Other online" names its platform (26 Sep): the notice then says "Webex", not "Other online". -->
                <div v-if="form.held_as === 'other'" class="m-field">
                    <label for="platform-name">Platform name (optional)</label>
                    <div class="m-input">
                        <span>
                            <input
                                id="platform-name"
                                v-model="form.platform_name"
                                type="text"
                                maxlength="60"
                                placeholder="e.g. Webex"
                                title="What households are told the meeting is on. Left empty, the notice says Other online."
                            />
                        </span>
                    </div>
                    <div v-if="form.errors.platform_name" class="act-error">{{ form.errors.platform_name }}</div>
                </div>

                <div v-if="isOnline" class="m-field">
                    <label for="virtual-link">Joining link <RequiredMark /></label>
                    <div class="m-input">
                        <span>
                            <input
                                id="virtual-link"
                                v-model="form.virtual_link"
                                type="url"
                                inputmode="url"
                                required
                                placeholder="e.g. https://zoom.us/j/123456789"
                                title="Households see this in the Resident App and on the notice. It must be a http or https address."
                            />
                        </span>
                    </div>
                    <div v-if="form.errors.virtual_link" class="act-error">{{ form.errors.virtual_link }}</div>
                </div>

                <div class="m-field">
                    <!-- Required in person unless it is to be confirmed; optional online, where one makes it hybrid. -->
                    <label for="venue">
                        {{ isOnline ? 'Venue (optional: adding one makes it hybrid)' : 'Venue' }}
                        <RequiredMark v-if="!isOnline && !form.venue_tbc" />
                    </label>
                    <div class="m-input">
                        <span>
                            <input
                                id="venue"
                                v-model="form.venue"
                                type="text"
                                :disabled="!isOnline && form.venue_tbc"
                                :title="!isOnline && form.venue_tbc ? 'The venue is marked to be confirmed. Untick it to type one.' : undefined"
                                :required="!isOnline && !form.venue_tbc"
                                :placeholder="isOnline ? 'e.g. The clubhouse' : 'e.g. The clubhouse, Phoenix Park Drive'"
                            />
                        </span>
                    </div>
                    <!-- GV-6: the one deliberate way an in-person meeting goes out without its venue. -->
                    <label v-if="!isOnline && form.venue.trim() === ''" class="m-check" for="venue-tbc">
                        <input id="venue-tbc" v-model="form.venue_tbc" type="checkbox" />
                        <span>The venue is to be confirmed</span>
                    </label>
                    <div v-if="form.errors.venue" class="act-error">{{ form.errors.venue }}</div>
                </div>

                <div class="m-two-col quorum-row">
                    <div class="m-field">
                        <label for="quorum">Quorum threshold</label>
                        <div class="m-input">
                            <span>
                                <input
                                    id="quorum"
                                    type="text"
                                    :value="quorumLabel"
                                    readonly
                                    :title="quorumReason"
                                />
                            </span>
                        </div>
                    </div>

                    <div class="m-field">
                        <label for="recording">Recording</label>
                        <div class="m-input">
                            <span>
                                <select id="recording" v-model="recordingChoice">
                                    <option value="on">Enabled, with consent notice</option>
                                    <option value="off">Disabled</option>
                                </select>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="m-field">
                    <label>Agenda (optional)</label>

                    <div v-for="(item, index) in form.agenda" :key="index" class="agenda-item">
                        <div class="ai-time">
                            <input v-model="item.start_time" type="time" :aria-label="`Time for agenda item ${index + 1}`" />
                        </div>
                        <div class="ai-txt">
                            <input
                                v-model="item.text"
                                type="text"
                                maxlength="200"
                                required
                                placeholder="e.g. Treasurer's report"
                                :aria-label="`Agenda item ${index + 1}`"
                            />
                        </div>
                        <button
                            type="button"
                            class="ai-remove"
                            title="Remove this agenda item"
                            :aria-label="`Remove agenda item ${index + 1}`"
                            @click="removeAgendaItem(index)"
                        >
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M6 6l12 12M18 6L6 18"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </button>
                    </div>

                    <div v-for="[key, message] in agendaErrors" :key="key" class="act-error">{{ message }}</div>

                    <button type="button" class="add-agenda" @click="addAgendaItem">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <span>Add agenda item</span>
                    </button>
                </div>
            </div>

            <div class="form-panel">
                <div
                    style="
                        font-family: 'Poppins', sans-serif;
                        font-size: 13.5px;
                        color: var(--navy-900);
                        font-weight: 600;
                        margin-bottom: 14px;
                    "
                >
                    Preview
                </div>

                <div
                    style="
                        background: linear-gradient(135deg, var(--navy-600), var(--navy-800));
                        border-radius: 14px;
                        padding: 16px;
                        color: var(--white);
                        margin-bottom: 16px;
                    "
                >
                    <div style="font-size: 10px; color: var(--amber-500); font-weight: 700">{{ eyebrow }}</div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 600; margin-top: 4px">
                        {{ previewTitle }}
                    </div>
                    <div style="font-size: 11px; color: var(--text-on-dark); margin-top: 4px">
                        {{ previewSummary }}
                    </div>
                </div>

                <!--
                  THE BOARD'S SENTENCE WAS TRUE ONLY IN PERSON (26 Sep). "There's no shareable link" sat under an
                  online meeting whose link the secretary had just typed. Who gets the link is what it now says.
                -->
                <!-- DR-013: saving keeps a draft nobody sees; residents see it once it is published. -->
                <div style="font-size: 11.5px; color: var(--slate-500); line-height: 1.6">
                    Residents see this in Meetings and on their Notices feed once it is published. Until then it is a draft that nobody sees.
                    <template v-if="isOnline">
                        Only verified households in the selected audience are given the joining link, in the Resident App and on
                        the notice.
                    </template>
                    <template v-else>Only verified households in the selected audience are told the venue.</template>
                </div>
            </div>
        </form>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws every field as a <div class="m-input"><span>value</span></div>,
 * the segments as <div class="seg-item">, and the agenda's remove button and
 * "Add agenda item" as <div>s. Here they are real controls, and each arrives
 * wearing the browser's own border, background and font — the date and time
 * fields also carry their own picker chrome, which nothing below removes,
 * because the board draws no picker to compare it against and a native one
 * is what tells a secretary the field is a date. Nothing below introduces a
 * colour, size or weight the board does not already declare on `.m-input`,
 * `.seg-item` or `.agenda-item`: the control sits inside the board's own
 * <span> or <div>, so those rules already supply the type and `font: inherit`
 * picks it up.
 */
.m-input span {
    flex: 1;
    min-width: 0;
}

.m-input input,
.m-input select {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

.m-input input[readonly] {
    cursor: default;
}

/* The family and line height only: `font: inherit` would reset the board's .seg-item size and weight (13 E1, as board 34). */
button.seg-item {
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/*
 * "How it is held" as the board's segmented control (M12 §4.2). Five options, so on a narrow window they may take two
 * rows rather than squeeze "Microsoft Teams" to nothing; the hint sits under it, not the board's 16px below.
 */
.seg-held {
    flex-wrap: wrap;
    gap: 2px;
    margin-bottom: 0;
}

.seg-held .seg-item {
    min-width: 96px;
}

.ai-time input,
.ai-txt input {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
}

.ai-txt input::placeholder {
    color: var(--text-placeholder);
    opacity: 1;
}

button.ai-remove,
button.add-agenda {
    border: 0;
    background: none;
    cursor: pointer;
}

/* The board draws this square white, not transparent. */
button.ai-remove {
    background: var(--white);
}

button.btn-primary-sm {
    border: 0;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/* Greyed when it cannot be used yet (M96 level 2), with why beside it. */
button.btn-primary-sm[disabled] {
    opacity: 0.55;
}

.ms-why {
    font-size: 11px;
    font-weight: 600;
    color: var(--slate-600);
    max-width: 320px;
}

/*
 * AUTHORED BELOW THIS LINE. Per-field errors, kept to the same tokens and
 * size as the sibling governance screens' `.act-error` — board 12 draws no
 * error at all, because nothing has ever been typed into it.
 */
.act-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
    margin-top: 4px;
}

/*
 * The hint under "How it is held" (M10 §8). The boards draw no hint anywhere,
 * because they draw no field that needs one — so this borrows `.act-error`'s
 * size and rhythm in the quiet text colour, and says which of the two place
 * fields the chosen mode is asking for.
 */
.m-hint {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.5;
    margin-top: 4px;
}

/*
 * "The venue is to be confirmed" (M68 §2.7, GV-6): a box under the field, in .m-hint's size, chosen on purpose.
 * Under `.m-field` and on the label, because the board's own `.m-field label` (display: block, scoped to the body's
 * board class) outranked a bare `.m-check` and set the box against its words with no gap (dev server, 12 October).
 */
.m-field label.m-check {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-800);
    cursor: pointer;
}

/*
 * The quorum's words ("25% of 1,999 eligible households") need more than half the form below about 1400 pixels of
 * page: a 1280 laptop at 100% cut them at the box's edge (dev server, 12 October). Their row alone stacks there.
 */
@media (max-width: 1400px) {
    .m-two-col.quorum-row {
        flex-direction: column;
    }
}

/* The form beside its preview, as the board draws it; one column from 1023 px down, the preview under the form (item 12). */
.ms-grid {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 18px;
}

/* A column may shrink to the window: a grid item otherwise holds its column open at its content's width. */
.ms-grid > * {
    min-width: 0;
}

@media (max-width: 1023px) {
    .ms-grid {
        grid-template-columns: 1fr;
    }
}

/*
 * At 125% zoom a laptop's 1440 or 1280 pixels are 1152 or 1024 to the page (M71 E2E-M4), and half the form is too
 * narrow for the quorum's words ("25% of 443 eligible households"): below 1200 pixels the two fields stack.
 */
@media (max-width: 1200px) {
    .m-two-col {
        flex-direction: column;
    }
}
</style>
