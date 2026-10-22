<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ConsoleDialog from './ConsoleDialog.vue'
import RequiredMark from './RequiredMark.vue'
import { meetingStageWord } from '../lib/meetingStages.js'

/**
 * A MEETING'S LIFE, ON ITS OWN PAGE (the user, M56 §2 and §4).
 *
 * "I cannot edit a meeting. I cannot delete one. I cannot postpone one." The server says where the meeting is in its
 * life (`stage`) and which acts that stage allows (`acts`); this draws those and NOTHING ELSE (M53 Law 1: what cannot
 * be done here is not drawn). The destructive acts sit apart, after a divider, and confirm by typing the meeting's
 * name; every act that reaches households says how many before it is pressed. The routes refuse the same acts
 * whatever this screen offers.
 */
const props = defineProps({
    meeting: { type: Object, required: true },
    /** `…/governance/meetings/{id}`, in whichever shape this environment serves. */
    base: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
    canPublish: { type: Boolean, default: false },
    publishReason: { type: String, default: '' },
})

const emit = defineEmits(['dialog'])

const has = (act) => props.meeting.acts.includes(act)

/*
 * M96 LEVELS. An act this role can never take is not drawn at all (level 3): the stage chip already says where the
 * meeting is. An act a rule blocks for now says why beside its button, in its dialog (level 2).
 */
const may = (act) => (['reschedule', 'postpone', 'cancel'].includes(act) ? props.canPublish : act === 'delete' ? props.canDelete : props.canEdit)
const canDo = (act) => has(act) && may(act)
const mine = computed(() => props.meeting.acts.filter((act) => !['publish', 'attendance', 'minutes'].includes(act) && may(act)))

const told = computed(() =>
    props.meeting.households_told === 0
        ? 'This tells no household: a committee meeting is for its members.'
        : `This tells ${props.meeting.households_told.toLocaleString('en-JM')} households.`
)

/** Which dialog is open: edit, date, postpone, cancel, delete or archive. */
const open = ref(null)

/*
 * The register's row menu opens this page with the act it chose (M56 §4): that act's dialog, if this stage allows it
 * and this person may take it. Nothing else is opened from the address.
 */
const ACT_DIALOG = { edit: ['edit', 'edit'], edit_place: ['edit', 'edit'], reschedule: ['date', 'publish'], postpone: ['postpone', 'publish'], cancel: ['cancel', 'publish'], delete: ['delete', 'delete'], archive: ['archive', 'edit'] }
if (typeof window !== 'undefined') {
    const asked = new URLSearchParams(window.location.search).get('act')
    const [dialog, may] = ACT_DIALOG[asked] ?? [null, null]
    const allowed = may === 'publish' ? props.canPublish : may === 'delete' ? props.canDelete : props.canEdit

    if (dialog && allowed && props.meeting.acts.includes(asked)) {
        open.value = dialog
    }
}

const close = () => {
    open.value = null

    // A refusal read is not standing in the band the next time a dialog opens.
    for (const form of [dateForm, postponeForm, cancelForm, deleteForm, archiveForm, editForm]) {
        form.clearErrors()
    }
}

/*
 * A REFUSAL OF A DIALOG'S ACT IS IN THAT DIALOG'S AMBER BAND, AND NEVER A TOAST AS WELL (Hassam, 12 Oct, the final
 * refusal rule, D-235): the person is looking at the dialog, the fix is in it, and the sentence stays until they fix
 * it. The dialog stays open with what was typed. While one is open the page is told, and holds its own toast for the
 * same words. No field in these dialogs draws its own error, so the band says the first.
 */
watch(open, (dialog) => emit('dialog', dialog !== null), { immediate: true })
onBeforeUnmount(() => emit('dialog', false))

const firstError = (form) => Object.values(form.errors)[0] ?? null

const done = (form) => ({
    preserveScroll: true,
    onSuccess: () => {
        open.value = null
        form.reset()
    },
})

/* ---- a new date (M56 §2.2): the picker refuses inside the period, and says the earliest as it is picked ---- */
const dateForm = useForm({ starts_at: props.meeting.starts_local < props.meeting.earliest_local ? props.meeting.earliest_local : props.meeting.starts_local })
const dateInside = computed(() => dateForm.starts_at !== '' && dateForm.starts_at < props.meeting.earliest_local)
const sendDate = () => {
    if (!dateInside.value) {
        dateForm.post(`${props.base}/date`, done(dateForm))
    }
}

/* ---- postpone (§2.3, M94 §3.3): why, and the new date when the office has one, in one act with one message ---- */
const postponeForm = useForm({ reason: '', starts_at: '' })
postponeForm.transform((data) => (data.starts_at === '' ? { reason: data.reason } : data))

/** The picker starts at whichever is later: the earliest the notice period allows, or the meeting's own date. */
const postponeMin = computed(() => (props.meeting.starts_local > props.meeting.earliest_local ? props.meeting.starts_local : props.meeting.earliest_local))

const postponeRefusal = computed(() => {
    if (postponeForm.reason.trim().length < 3) {
        return 'Say why first.'
    }

    if (postponeForm.starts_at === '') {
        return null
    }

    if (postponeForm.starts_at <= props.meeting.starts_local) {
        return 'Choose a date later than the meeting’s own, or use Change the date.'
    }

    return postponeForm.starts_at < props.meeting.earliest_local ? props.meeting.notice_line : null
})

/* ---- cancel (§1.2), delete a draft, archive ---- */
const cancelForm = useForm({ reason: '', confirm_name: '' })
const deleteForm = useForm({ confirm_name: '' })
const archiveForm = useForm({})
const earliestForm = useForm({})

const namedRight = (typed) => typed.trim().toLowerCase() === props.meeting.title.trim().toLowerCase()

/* ---- edit: a draft entirely; a published meeting its place, description and agenda ---- */
const isDraft = computed(() => props.meeting.stage === 'draft')

const editForm = useForm({
    type: props.meeting.type,
    title: props.meeting.title,
    phase: props.meeting.phase,
    starts_at: props.meeting.starts_local,
    duration_minutes: props.meeting.duration_minutes,
    description: props.meeting.description ?? '',
    held_as: props.meeting.held_as,
    venue: props.meeting.venue ?? '',
    virtual_link: props.meeting.virtual_link ?? '',
    platform_name: props.meeting.platform_name ?? '',
    recording_enabled: props.meeting.recording_enabled,
    recording_consent_notice: props.meeting.recording_enabled,
    agenda: props.meeting.agenda_items.map((item) => ({ ...item })),
})

editForm.transform((data) => {
    const place = {
        held_as: data.held_as,
        venue: data.venue.trim() === '' ? null : data.venue.trim(),
        virtual_link: data.held_as === 'in_person' || data.virtual_link.trim() === '' ? null : data.virtual_link.trim(),
        platform_name: data.held_as === 'other' && data.platform_name.trim() !== '' ? data.platform_name.trim() : null,
        description: data.description.trim() === '' ? null : data.description.trim(),
        agenda: data.agenda.filter((item) => item.text.trim() !== '').map((item) => ({ text: item.text.trim(), start_time: item.start_time || null })),
    }

    return isDraft.value
        ? { ...place, type: data.type, title: data.title, phase: data.type === 'phase' ? data.phase : null, starts_at: data.starts_at, duration_minutes: data.duration_minutes, recording_enabled: data.recording_enabled, recording_consent_notice: data.recording_enabled }
        : place
})

const editInside = computed(() => isDraft.value && editForm.starts_at !== '' && editForm.starts_at < props.meeting.earliest_local && editForm.type === props.meeting.type)

const heldAs = [
    { value: 'in_person', label: 'In person' },
    { value: 'zoom', label: 'Zoom' },
    { value: 'google_meet', label: 'Google Meet' },
    { value: 'teams', label: 'Microsoft Teams' },
    { value: 'other', label: 'Other online' },
]

const types = [
    { value: 'agm', label: 'AGM' },
    { value: 'egm', label: 'EGM' },
    { value: 'committee', label: 'Committee' },
    { value: 'phase', label: 'Phase meeting' },
]

const durations = [
    { value: 60, label: '1 hour' },
    { value: 90, label: '1½ hours' },
    { value: 120, label: '2 hours' },
    { value: 180, label: '3 hours' },
    { value: 240, label: '4 hours' },
]

/* ---- the register (M56 §2: "record attendance"), while the meeting is on and after it ---- */
const attendanceForm = useForm({ lot: '', name: '', represented_by: '', state: 'present' })
const record = () => attendanceForm.post(`${props.base}/attendance`, { preserveScroll: true, onSuccess: () => attendanceForm.reset('lot', 'name', 'represented_by') })
const removeForm = useForm({})
const remove = (id) => removeForm.post(`${props.base}/attendance/${id}/remove`, { preserveScroll: true })
</script>

<template>
    <div class="mt-card life">
        <div class="life-head">
            <div class="mt-head" style="margin: 0">What can be done now</div>
            <span class="life-chip" :class="`stage-${meeting.stage}`">{{ meetingStageWord(meeting.stage, meeting.stage_label) }}</span>
        </div>

        <!-- Why it is where it is: who postponed or cancelled it, when, and why (M56 §1.2). -->
        <p v-if="meeting.cancelled" class="life-note">
            Cancelled {{ meeting.cancelled.when }} by {{ meeting.cancelled.by }}. Reason: {{ meeting.cancelled.reason }}
        </p>
        <p v-if="meeting.postponed" class="life-note">
            Postponed {{ meeting.postponed.when }} by {{ meeting.postponed.by }}. Reason: {{ String(meeting.postponed.reason ?? '').replace(/[.\s]+$/, '') }}. Set a new date to schedule it again.
        </p>
        <p v-if="meeting.archived" class="life-note">Archived {{ meeting.archived }}.</p>

        <!-- A draft inside its notice period says so here, with the way out (M56 §2.2). -->
        <p v-if="meeting.publish_refusal" class="life-warn">{{ meeting.publish_refusal }}</p>

        <div v-if="mine.length" class="life-row">
            <button v-if="canDo('edit') || canDo('edit_place')" type="button" class="btn-outline-sm" :title="has('edit') ? 'Change anything about this draft.' : 'Change the place, the description or the agenda.'" @click="open = 'edit'">
                <span>{{ has('edit') ? 'Edit' : 'Edit place and agenda' }}</span>
            </button>
            <button v-if="canDo('move_to_earliest')" type="button" class="btn-outline-sm" :disabled="earliestForm.processing" title="Move the draft to the earliest day its notice period allows, at the same time." @click="earliestForm.post(`${base}/move-to-earliest`, { preserveScroll: true })">
                <span>{{ meeting.move_label }}</span>
            </button>
            <button v-if="canDo('reschedule')" type="button" class="btn-outline-sm" title="Choose a new date. Households are told, and their replies start again." @click="open = 'date'">
                <span>{{ meeting.stage === 'postponed' ? 'Set a new date' : 'Change the date' }}</span>
            </button>
            <button v-if="canDo('postpone')" type="button" class="btn-outline-sm" title="Put the meeting off, with its new date if you know it. Households are told." @click="open = 'postpone'">
                <span>Postpone</span>
            </button>
            <button v-if="canDo('archive')" type="button" class="btn-outline-sm" title="Archive this meeting. Its minutes stay readable." @click="open = 'archive'">
                <span>Archive</span>
            </button>

            <!-- The destructive acts, apart (M56 §4.2). -->
            <template v-if="canDo('cancel') || canDo('delete')">
                <span class="life-divider" aria-hidden="true"></span>
                <button v-if="canDo('cancel')" type="button" class="life-danger" title="Call the meeting off, with a reason. It stays on the register, marked." @click="open = 'cancel'">
                    <span>Cancel meeting</span>
                </button>
                <button v-if="canDo('delete')" type="button" class="life-danger" title="Delete this draft. Nobody has been told of it." @click="open = 'delete'">
                    <span>Delete draft</span>
                </button>
            </template>
        </div>
        <p v-else-if="!meeting.cancelled && !meeting.archived && !has('attendance')" class="mt-muted">
            {{ meeting.acts.some((act) => !['attendance', 'minutes'].includes(act)) ? 'Your role reads this meeting. It does not change it.' : 'Nothing is changed at this stage.' }}
        </p>

        <!-- The register, while the meeting is on and after it. Quorum is counted from it, never typed. -->
        <div v-if="has('attendance')" class="life-register">
            <div class="mt-head">Attendance register</div>
            <p class="mt-muted">Quorum is counted from this register: every household or member recorded present counts.</p>
            <!-- Kept by a role that may edit the meeting; any other reads the register (M96 level 3). -->
            <form v-if="canEdit" class="life-form" @submit.prevent="record">
                <input v-if="meeting.counts_households" v-model="attendanceForm.lot" type="text" maxlength="40" placeholder="e.g. Lot 12" aria-label="Lot" />
                <input v-model="attendanceForm.name" type="text" maxlength="160" :placeholder="meeting.counts_households ? 'Name (optional)' : 'Member name'" aria-label="Name" />
                <input v-model="attendanceForm.represented_by" type="text" maxlength="160" placeholder="Proxy (optional)" aria-label="Represented by" />
                <select v-model="attendanceForm.state" aria-label="Attendance">
                    <option value="present">Present</option>
                    <option value="apologies">Apologies</option>
                    <option value="absent">Absent</option>
                </select>
                <button type="submit" class="btn-primary-sm" :disabled="attendanceForm.processing" title="Add this line to the register.">
                    <span>Record</span>
                </button>
            </form>
            <!-- A field's own fault, under the form, in amber; a refusal of the register as a whole is the page's toast (D-235). -->
            <p v-if="attendanceForm.errors.lot || attendanceForm.errors.state" class="mt-refusal">
                {{ attendanceForm.errors.lot || attendanceForm.errors.state }}
            </p>
            <table v-if="meeting.register.length" class="mt-rsvps">
                <thead>
                    <tr><th>Who</th><th>Attendance</th><th>Proxy</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in meeting.register" :key="row.id">
                        <td>{{ row.who }}</td>
                        <td>{{ row.state }}</td>
                        <td>{{ row.represented_by ?? 'None' }}</td>
                        <td><button v-if="canEdit" type="button" class="text-link-sm" title="Take this line off the register." @click="remove(row.id)">Remove</button></td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="mt-muted">Nobody is on the register yet.</p>
        </div>

        <!-- ---- the dialogs ---- -->

        <ConsoleDialog :open="open === 'date'" :title="meeting.stage === 'postponed' ? 'Set a new date' : 'Change the date'" confirm-label="Move the meeting" :busy="dateForm.processing" :confirm-blocked-reason="dateInside ? meeting.notice_line : null" :error="firstError(dateForm)" @confirm="sendDate" @cancel="close">
            <RequiredMark legend />
            <label class="life-label" for="life-date">New date and time <RequiredMark /></label>
            <input id="life-date" v-model="dateForm.starts_at" type="datetime-local" required :min="meeting.earliest_local" class="life-input" />
            <p :class="dateInside ? 'mt-refusal' : 'mt-muted'">{{ meeting.notice_line }}</p>
            <p class="mt-muted">{{ told }} Every reply resets, and the notice period starts again from today.</p>
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'postpone'" title="Postpone the meeting" :confirm-label="postponeForm.starts_at === '' ? 'Postpone' : 'Postpone to this date'" :busy="postponeForm.processing" :confirm-blocked-reason="postponeRefusal" :error="firstError(postponeForm)" @confirm="postponeForm.post(`${base}/postpone`, done(postponeForm))" @cancel="close">
            <RequiredMark legend />
            <label class="life-label" for="life-postpone">Why (households are told) <RequiredMark /></label>
            <textarea id="life-postpone" v-model="postponeForm.reason" rows="3" maxlength="500" required class="life-input"></textarea>
            <label class="life-label" for="life-postpone-date">New date and time (optional, if you know it)</label>
            <input id="life-postpone-date" v-model="postponeForm.starts_at" type="datetime-local" :min="postponeMin" class="life-input" />
            <p v-if="postponeForm.starts_at === ''" class="mt-muted">{{ told }} Its date reads "New date to be set", and households cannot reply until it has one. Setting the date starts every reply again.</p>
            <template v-else>
                <p :class="postponeForm.starts_at < meeting.earliest_local ? 'mt-refusal' : 'mt-muted'">{{ meeting.notice_line }}</p>
                <p class="mt-muted">{{ told }} One message gives the new date and why. Every reply resets, and the notice period starts again from today.</p>
            </template>
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'cancel'" title="Cancel the meeting" tone="danger" confirm-label="Cancel the meeting" cancel-label="Keep it" :busy="cancelForm.processing" :confirm-blocked-reason="cancelForm.reason.trim().length < 3 ? 'Say why first.' : !namedRight(cancelForm.confirm_name) ? 'Type the meeting\'s name to confirm.' : null" :error="firstError(cancelForm)" @confirm="cancelForm.post(`${base}/cancel`, done(cancelForm))" @cancel="close">
            <RequiredMark legend />
            <label class="life-label" for="life-cancel">Why (households are told) <RequiredMark /></label>
            <textarea id="life-cancel" v-model="cancelForm.reason" rows="3" maxlength="500" required class="life-input"></textarea>
            <label class="life-label" for="life-cancel-name">Type the meeting's name to confirm: {{ meeting.title }} <RequiredMark /></label>
            <input id="life-cancel-name" v-model="cancelForm.confirm_name" type="text" required class="life-input" autocomplete="off" />
            <p class="mt-muted">{{ told }} A cancelled meeting is never deleted or reopened: it stays on the register, marked, with this reason.</p>
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'delete'" title="Delete this draft" tone="danger" confirm-label="Delete the draft" cancel-label="Keep it" :busy="deleteForm.processing" :confirm-blocked-reason="!namedRight(deleteForm.confirm_name) ? 'Type the meeting\'s name to confirm.' : null" :error="firstError(deleteForm)" @confirm="deleteForm.post(`${base}/delete`, done(deleteForm))" @cancel="close">
            <RequiredMark legend />
            <label class="life-label" for="life-delete-name">Type the meeting's name to confirm: {{ meeting.title }} <RequiredMark /></label>
            <input id="life-delete-name" v-model="deleteForm.confirm_name" type="text" required class="life-input" autocomplete="off" />
            <p class="mt-muted">It is a draft, so nobody has been told of it.</p>
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'archive'" title="Archive the meeting" confirm-label="Archive" :busy="archiveForm.processing" :error="firstError(archiveForm)" @confirm="archiveForm.post(`${base}/archive`, done(archiveForm))" @cancel="close">
            <p class="mt-muted">Its minutes stay published and readable in the Resident App.</p>
        </ConsoleDialog>

        <ConsoleDialog :open="open === 'edit'" :title="isDraft ? 'Edit the draft' : 'Edit place and agenda'" confirm-label="Save" :busy="editForm.processing" :confirm-blocked-reason="editInside ? meeting.notice_line : null" :error="firstError(editForm)" @confirm="editForm.post(`${base}/edit`, done(editForm))" @cancel="close">
            <RequiredMark legend />
            <template v-if="isDraft">
                <label class="life-label" for="life-title">Title <RequiredMark /></label>
                <input id="life-title" v-model="editForm.title" type="text" maxlength="160" required class="life-input" />
                <label class="life-label" for="life-type">Type (it decides who the meeting is for)</label>
                <select id="life-type" v-model="editForm.type" class="life-input">
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <template v-if="editForm.type === 'phase'">
                    <label class="life-label" for="life-phase">Phase</label>
                    <select id="life-phase" v-model="editForm.phase" class="life-input">
                        <option v-for="p in meeting.phases" :key="p" :value="p">{{ p }}</option>
                    </select>
                </template>
                <label class="life-label" for="life-starts">Date and time <RequiredMark /></label>
                <input id="life-starts" v-model="editForm.starts_at" type="datetime-local" required :min="editForm.type === meeting.type ? meeting.earliest_local : null" class="life-input" />
                <p v-if="editForm.type === meeting.type" :class="editInside ? 'mt-refusal' : 'mt-muted'">{{ meeting.notice_line }}</p>
                <p v-else class="mt-muted">A different type has its own notice period: the date is checked against it when you save.</p>
                <label class="life-label" for="life-length">Length</label>
                <select id="life-length" v-model="editForm.duration_minutes" class="life-input">
                    <option v-for="d in durations" :key="d.value" :value="d.value">{{ d.label }}</option>
                </select>
            </template>
            <label class="life-label" for="life-held">How it is held</label>
            <select id="life-held" v-model="editForm.held_as" class="life-input">
                <option v-for="h in heldAs" :key="h.value" :value="h.value">{{ h.label }}</option>
            </select>
            <template v-if="editForm.held_as === 'other'">
                <label class="life-label" for="life-platform">Platform name (optional)</label>
                <input id="life-platform" v-model="editForm.platform_name" type="text" maxlength="60" class="life-input" />
            </template>
            <template v-if="editForm.held_as !== 'in_person'">
                <label class="life-label" for="life-link">Joining link <RequiredMark /></label>
                <input id="life-link" v-model="editForm.virtual_link" type="url" maxlength="255" required class="life-input" placeholder="e.g. https://zoom.us/j/123456789" />
            </template>
            <label class="life-label" for="life-venue">
                Venue{{ editForm.held_as === 'in_person' ? '' : ' (optional, for a hybrid meeting)' }}
                <RequiredMark v-if="editForm.held_as === 'in_person'" />
            </label>
            <input id="life-venue" v-model="editForm.venue" type="text" maxlength="190" :required="editForm.held_as === 'in_person'" class="life-input" placeholder="e.g. The clubhouse" />
            <label class="life-label" for="life-description">What it is about (optional)</label>
            <textarea id="life-description" v-model="editForm.description" rows="2" maxlength="2000" class="life-input"></textarea>
            <div class="life-label">Agenda</div>
            <div v-for="(item, i) in editForm.agenda" :key="i" class="life-agenda">
                <input v-model="item.start_time" type="time" aria-label="Time" />
                <input v-model="item.text" type="text" maxlength="200" aria-label="Item" />
                <button type="button" class="text-link-sm" @click="editForm.agenda.splice(i, 1)">Remove</button>
            </div>
            <button type="button" class="text-link-sm" @click="editForm.agenda.push({ start_time: '', text: '' })">Add an item</button>
            <p v-if="!isDraft" class="mt-muted">A changed place is told to every household that said it is coming.</p>
        </ConsoleDialog>
    </div>
</template>

<style scoped>
.life-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}

.life-chip {
    font-size: 11px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--navy-700);
}

/* Cancelled is finished, not an alarm: an outline grey, as Void is. Red is kept for panic and life safety. */
.life-chip.stage-cancelled {
    background: var(--white);
    border: 1px solid var(--slate-300);
    color: var(--slate-600);
}

.life-chip.stage-postponed,
.life-chip.stage-draft {
    background: var(--amber-100);
    color: var(--amber-700);
}

.life-chip.stage-in_progress,
.life-chip.stage-scheduled {
    background: var(--green-100, #e3f6ea);
    color: var(--green-700, #1c7a43);
}

.life-note {
    font-size: 12px;
    color: var(--navy-900);
    margin: 0 0 10px;
}

.life-warn {
    font-size: 12px;
    font-weight: 700;
    color: var(--amber-700);
    margin: 0 0 10px;
}

.life-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.life-divider {
    width: 1px;
    height: 24px;
    background: var(--navy-200);
    margin: 0 6px;
}

.life-danger {
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    padding: 7px 12px;
    border-radius: 10px;
    background: var(--white);
    /* A destructive act in a red outline: serious, clearly not an emergency (Hassam's three weights, 11 Oct). */
    color: var(--red-700);
    border: 1px solid var(--red-700);
}

.life-danger[disabled],
button[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}

.life-register {
    margin-top: 14px;
}

.life-form {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 8px 0;
}

.life-form input,
.life-form select,
.life-input,
.life-agenda input {
    font: inherit;
    font-size: 12.5px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 8px 10px;
    color: var(--navy-900);
    background: var(--white);
}

.life-input {
    width: 100%;
    box-sizing: border-box;
}

.life-label {
    display: block;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin: 10px 0 4px;
}

.life-agenda {
    display: flex;
    gap: 6px;
    align-items: center;
    margin-bottom: 6px;
}

.life-agenda input[type='text'] {
    flex: 1;
}

/*
 * THE MEETING PAGE'S OWN CARD LINES, restated here. They are scoped to Meeting.vue, and a scoped rule reaches a child
 * component's root and nothing inside it, so the register's heading, notes and table were drawn in the browser's own
 * type (seen on the dev server, 12 October). The same values as Meeting.vue's.
 */
.mt-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin-bottom: 10px;
}

.mt-muted {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.6;
}

.mt-refusal {
    font-size: 12px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
    background: var(--amber-100);
    color: var(--amber-700);
}

.mt-rsvps {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
    font-size: 12px;
}

.mt-rsvps th {
    text-align: left;
    font-size: 11px;
    color: var(--slate-600);
    font-weight: 600;
    padding: 6px 4px;
    border-bottom: 1px solid var(--navy-100);
}

.mt-rsvps td {
    padding: 6px 4px;
    border-bottom: 1px solid var(--navy-100);
    color: var(--navy-900);
}
</style>
