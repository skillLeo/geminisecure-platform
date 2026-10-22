<script setup>
import { computed, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import MeetingLife from '../../../Components/MeetingLife.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'
import { meetingStageWord } from '../../../lib/meetingStages.js'

/**
 * One meeting, whole — the detail behind a board 36 row (12 §2, Wave 2 item 21).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * board 36's own sheet so the register and the meeting behind a row read as one.
 *
 * THE QUORUM IS THE REGISTER'S, not this page's. It is counted from attendance
 * by the same method that prints board 36's badge, so the two can never
 * disagree; attendance is shown as counts, because a count is what quorum turns
 * on and a list of who stayed home is not what a meeting record is for.
 *
 * THE PAPERS ARE LISTED WITH THEIR RETENTION. An agenda or minutes issued as a
 * PDF is kept seven years (12 §1), and "was the agenda sent?" is answered here.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    meeting: { type: Object, required: true },
    documents: { type: Array, required: true },
    canPublish: { type: Boolean, required: true },
    publishReason: { type: String, required: true },
    // M21 §1.4: who may write the minutes, and why not when they may not.
    canMinute: { type: Boolean, default: false },
    minuteReason: { type: String, default: '' },
    // M56 §2: who may edit and keep the register, and who may delete a draft.
    canEdit: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
    // Who Publish reaches (M65 §3): a committee's own meeting and the committee's head count, from the server.
    publishReach: { type: Object, default: () => ({ committee: false, members: null }) },
})

useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

const page = usePage()

const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

const paperForm = useForm({})

/*
 * WHICH PAPER THIS MEETING CAN ISSUE NOW (Security's dev-server note, 12 Oct: "Issue agenda" stayed live on a meeting
 * already held, its agenda long issued). The minutes once written; the agenda only until the meeting is over; after
 * that, with no minutes yet, nothing, because an agenda is for before the meeting (M96 level 3).
 */
const paperAct = computed(() => {
    if (['cancelled', 'draft'].includes(props.meeting.stage)) {
        return null
    }

    if (props.meeting.has_minutes) {
        return 'minutes'
    }

    return ['scheduled', 'postponed', 'in_progress'].includes(props.meeting.stage) ? 'agenda' : null
})

const askForPaper = () => {
    if (paperAct.value === null) {
        return
    }

    paperForm.post(governance(`/meetings/${props.meeting.id}/${paperAct.value}`), {
        preserveScroll: true,
    })
}

/*
 * PUBLISH ASKS FIRST (M62 B1, DR-007). Publishing tells households at once and cannot be taken back, so the press opens
 * a confirmation naming how many are told, the server's own count (`households_told`, the number the meeting's panel
 * reads as "This tells N households"), the meeting and when it is; only the dialog's own "Publish" posts. A committee's
 * own meeting tells no household and says so. A publication is a push, and ResidentEmails sends the same news by email
 * to whoever turned Email on (off by default), so the dialog says both channels and never more.
 */
const askingPublish = ref(false)

const told = computed(() => props.meeting.households_told ?? 0)

// M65 §3: the committee case resolves to the committee's head count, the general case to the households, both the server's.
const forCommittee = computed(() => props.publishReach?.committee === true)

const publishTitle = computed(() =>
    forCommittee.value ? 'Publish to the committee?' : `Publish to ${told.value.toLocaleString('en-US')} household${told.value === 1 ? '' : 's'}?`
)

const publishWhen = computed(() => `${props.meeting.title} · ${props.meeting.when}.`)

const publishWho = computed(() => {
    if (forCommittee.value) {
        const members = props.publishReach.members ?? 0

        return `${members === 1 ? 'The 1 committee member sees' : `The ${members} committee members see`} it in the console. No household is told. You cannot unsend this.`
    }

    // The push, and the same news by email to whoever turned Email on (ResidentEmails; off by default).
    return told.value === 0 ? 'No household is told. You cannot unsend this.' : 'They are told in the app, and by email where they asked for it. You cannot unsend this.'
})

const publish = () => {
    if (!props.canPublish) {
        return
    }

    publishRefusal.value = null
    askingPublish.value = true
}

/*
 * A REFUSED PUBLISH STAYS IN ITS QUESTION, IN THE AMBER BAND (Hassam, 12 Oct, the final refusal rule, D-235): the
 * question was the thing doing it, so it stays open with the server's sentence until the person closes it. It used to
 * close and leave the refusal to a toast; "never close the dialog and then toast".
 */
const publishRefusal = ref(null)

// The register's Publish opens this page with ?act=publish (DR-056): the same question, asked on arrival when this
// meeting may be published now. Nothing is posted from the address.
if (typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('act') === 'publish') {
    if (props.canPublish && props.meeting.stage === 'draft' && props.meeting.publish_refusal === null) {
        askingPublish.value = true
    }
}

const postPublish = () => {
    publishRefusal.value = null
    router.post(governance(`/meetings/${props.meeting.id}/publish`), {}, {
        preserveScroll: true,
        onSuccess: () => {
            askingPublish.value = false
        },
        onError: (errors) => {
            publishRefusal.value = Object.values(errors ?? {})[0] ?? null
        },
    })
}

/*
 * THE MINUTES (M21 §1.4): written here once the meeting has been held, corrected while they are unpublished, and
 * published to the meeting's households — the Resident App shows them from then on. Before M21 nothing wrote them.
 */
const minutesForm = useForm({ body: props.meeting.minutes?.body ?? '' })

/*
 * WHERE THE MINUTES STAND, ONCE (M68 §2.6, GV-5). The line read "Not yet adopted · … · published to the households
 * Sep 28": published and, it seemed, not. It says whether the households can read them and since when; an adoption is
 * said only once it has happened (the server's label, "Adopted Oct 5, 2026 by …", Governance::meetingDetail), so "Not
 * yet adopted" is never printed.
 */
const minutesState = computed(() => {
    const minutes = props.meeting.minutes

    if (!minutes) {
        return ''
    }

    const parts = [minutes.published ? `Published to the households ${minutes.published}` : 'Not yet published to the households']

    if (typeof minutes.adopted === 'string' && minutes.adopted.startsWith('Adopted')) {
        parts.push(`a${minutes.adopted.slice(1)}`)
    }

    if (minutes.recorded_by) {
        parts.push(`recorded by ${minutes.recorded_by}`)
    }

    return parts.join(' · ')
})

/** Whether this role writes the minutes here and now: the meeting allows them, and the role may (M96 level 3 otherwise). */
const writesMinutes = computed(() => props.meeting.can_minute && props.canMinute)

const saveMinutes = () => {
    minutesForm.post(governance(`/meetings/${props.meeting.id}/minutes/record`), { preserveScroll: true })
}

/*
 * PUBLISH MINUTES ASKS FIRST (M65 1.2, DR-054): the same fault as DR-007. "Minutes are available" goes to the meeting's
 * households at once, and published minutes are the record, so the press opens the same question with the same counts
 * from the server, and only the dialog's own "Publish minutes" posts. A committee's meeting reaches no household's phone.
 */
const askingMinutes = ref(false)

const minutesTitle = computed(() =>
    forCommittee.value
        ? 'Publish the minutes to the committee?'
        : `Publish the minutes to ${told.value.toLocaleString('en-US')} household${told.value === 1 ? '' : 's'}?`
)

const minutesWhat = computed(() => `The minutes of ${props.meeting.title}, held ${props.meeting.when}.`)

const minutesWho = computed(() => {
    if (forCommittee.value) {
        const members = props.publishReach.members ?? 0

        return `${members === 1 ? 'The 1 committee member sees' : `The ${members} committee members see`} them in the console. No household is told. Once published, the minutes are the record.`
    }

    return told.value === 0
        ? 'No household is told. Once published, the minutes are the record.'
        : 'They are told in the app, and by email where they asked for it. Once published, the minutes are the record.'
})

const publishMinutes = () => {
    if (!props.canPublish) {
        return
    }

    minutesRefusal.value = null
    askingMinutes.value = true
}

/* A refused publication of the minutes stays in its question, in the amber band, as Publish's does (D-235). */
const minutesRefusal = ref(null)

const postMinutes = () => {
    minutesRefusal.value = null
    router.post(governance(`/meetings/${props.meeting.id}/minutes/publish`), {}, {
        preserveScroll: true,
        onSuccess: () => {
            askingMinutes.value = false
        },
        onError: (errors) => {
            minutesRefusal.value = Object.values(errors ?? {})[0] ?? null
        },
    })
}

/*
 * A REFUSAL THIS PAGE IS HANDED IS A TOAST, TOP RIGHT (M95 §2), where each was a line pushed into the top of the page:
 * a paper, a date, a publish, the register or the minutes refused. Amber, because red is kept for panic and life
 * safety (Hassam, 11 Oct). A field's own refusal stays under its field.
 *
 * EXCEPT WHILE ONE OF THE MEETING'S DIALOGS IS OPEN (Hassam, 12 Oct, the final refusal rule, D-235): the refusal of
 * its act is in its amber band, and never a toast as well. MeetingLife says when one of its own is open; Publish and
 * Publish minutes are this page's, and stay open on a refusal like every other.
 */
const lifeDialog = ref(false)

const refusalInADialog = computed(() => lifeDialog.value || askingPublish.value || askingMinutes.value)

watch(
    () => ['document', 'starts_at', 'meeting', 'attendance', 'minutes'].map((key) => page.props.errors?.[key]),
    (refusals) => {
        if (refusalInADialog.value) {
            return
        }

        for (const text of refusals) {
            if (text) {
                toast(text, { tone: 'warning' })
            }
        }
    },
    { immediate: true },
)

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['meeting'] })
</script>

<template>
    <Head :title="meeting.title" />

    <EstateConsole :title="meeting.title" :estate-name="estate.name" active="governance" :back="{ href: governance('/meetings'), label: 'Back to meetings' }">

        <template #actions>
            <!--
              M96 LEVELS. A role that can never publish has no Publish at all (level 3): the chip says Draft. A draft
              inside its notice period has Publish greyed with the reason beside it (level 2, M56 §2.2), and the way out
              in the card below.
            -->
            <span v-if="meeting.stage === 'draft' && canPublish" class="mt-publish">
                <button
                    type="button"
                    class="btn-primary-sm"
                    :disabled="meeting.publish_refusal !== null"
                    :title="meeting.publish_refusal ?? 'Publish this meeting.'"
                    @click="publish"
                >
                    <span>Publish</span>
                </button>
                <span v-if="meeting.publish_refusal !== null" class="mt-why">{{ meeting.publish_refusal }}</span>
            </span>
            <button
                v-else-if="paperAct !== null"
                type="button"
                class="btn-outline-sm"
                :disabled="paperForm.processing"
                :title="`Issue the ${paperAct} as a PDF, kept for seven years.`"
                @click="askForPaper"
            >
                <span>{{ paperAct === 'minutes' ? 'Issue minutes' : 'Issue agenda' }}</span>
            </button>
        </template>

        <!-- M56 §2: what this meeting may have done to it now, and nothing else. -->
        <MeetingLife
            :meeting="meeting"
            :base="governance(`/meetings/${meeting.id}`)"
            :can-edit="canEdit"
            :can-delete="canDelete"
            :can-publish="canPublish"
            :publish-reason="publishReason"
            @dialog="lifeDialog = $event"
        />

        <div class="mt-grid">
            <div class="mt-card">
                <div class="mt-head">The meeting</div>
                <dl class="mt-dl">
                    <dt>Type</dt>
                    <dd>{{ meeting.type_label }}</dd>
                    <dt>When</dt>
                    <dd>{{ meeting.when }}</dd>
                    <dt>Where</dt>
                    <dd>
                        {{ meeting.venue ?? 'No venue recorded' }}
                        <div v-if="meeting.virtual_link" class="mt-muted">Online: {{ meeting.virtual_link }}</div>
                    </dd>
                    <dt>Audience</dt>
                    <dd>{{ meeting.audience }}</dd>
                    <dt>Status</dt>
                    <dd>{{ meetingStageWord(meeting.stage, meeting.stage_label) }}</dd>
                    <template v-if="meeting.notice">
                        <dt>Notice</dt>
                        <dd>{{ meeting.notice }}</dd>
                    </template>
                    <dt>Recording</dt>
                    <dd>{{ meeting.recording }}</dd>
                </dl>
            </div>

            <div class="mt-card">
                <div class="mt-head">Quorum</div>
                <div class="status-badge" :class="meeting.quorum.state">{{ meeting.quorum.label }}</div>
                <dl class="mt-dl" style="margin-top: 10px">
                    <dt>Rule</dt>
                    <dd>{{ meeting.quorum.basis }} · {{ meeting.quorum.required }} needed</dd>
                    <dt>Present</dt>
                    <dd>{{ meeting.quorum.present }}</dd>
                    <dt>Apologies</dt>
                    <dd>{{ meeting.quorum.apologies }}</dd>
                    <dt>Absent</dt>
                    <dd>{{ meeting.quorum.absent }}</dd>
                </dl>
            </div>
        </div>

        <div class="mt-card">
            <div class="mt-head">Agenda</div>
            <p v-if="meeting.agenda.length === 0" class="mt-muted">No agenda items have been recorded for this meeting.</p>
            <ol v-else class="mt-agenda">
                <li v-for="(item, i) in meeting.agenda" :key="i">
                    <span v-if="item.time" class="mt-time">{{ item.time }}</span>
                    {{ item.text }}
                    <span v-if="item.motion" class="mt-muted"> · Motion {{ item.motion }}</span>
                </li>
            </ol>
        </div>

        <!-- M21 §1.4: what the Resident App's replies say. Not attendance — quorum is counted from the register. -->
        <div v-if="meeting.rsvps" class="mt-card">
            <div class="mt-head">Replies from the Resident App</div>
            <p class="mt-muted">
                {{ meeting.rsvps.attending }} attending · {{ meeting.rsvps.apologies }} apologies · {{ meeting.rsvps.not_attending }} not attending.
                A reply is who to expect, not the attendance register.
            </p>
            <table v-if="meeting.rsvps.rows.length" class="mt-rsvps">
                <thead>
                    <tr><th>Lot</th><th>Reply</th><th>Who replied</th><th>When</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in meeting.rsvps.rows" :key="i">
                        <td>{{ row.lot }}</td>
                        <td>{{ row.response }}</td>
                        <td>{{ row.by ?? '—' }}</td>
                        <td>{{ row.when ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-card">
            <div class="mt-head">Minutes</div>
            <!--
              M96 LEVELS. The editor is drawn for a role that may write the minutes, while the meeting allows them; any
              other role reads them (level 3). Save and Publish minutes, when blocked for now, say why beside them.
            -->
            <template v-if="meeting.minutes">
                <!-- One state (M68 §2.6, GV-5): published or not; an adoption only once it has happened. -->
                <div class="mt-muted mt-minutes-state">{{ minutesState }}</div>
                <div v-if="!writesMinutes" class="mt-minutes">{{ meeting.minutes.body }}</div>
            </template>
            <p v-else-if="!writesMinutes" class="mt-muted">No minutes have been recorded. They are written once the meeting has been held.</p>

            <form v-if="writesMinutes" class="mt-minutes-form" @submit.prevent="saveMinutes">
                <label class="mt-muted" for="mt-minutes-body">
                    What the meeting decided: who attended, each item, and what was resolved. Saved minutes can be corrected until they are published. <RequiredMark />
                </label>
                <textarea id="mt-minutes-body" v-model="minutesForm.body" rows="10" maxlength="20000" required></textarea>
                <p v-if="minutesForm.errors.body" class="mt-refusal">{{ minutesForm.errors.body }}</p>
                <div class="mt-actions">
                    <button type="submit" class="btn-outline-sm" :disabled="minutesForm.processing || minutesForm.body.trim().length < 20" title="Save the minutes. Households do not see them until they are published.">
                        <span>{{ minutesForm.processing ? 'Saving…' : 'Save minutes' }}</span>
                    </button>
                    <span v-if="minutesForm.body.trim().length < 20" class="mt-why">Write the minutes first: at least 20 characters.</span>
                    <template v-if="meeting.minutes && canPublish">
                        <button
                            type="button"
                            class="btn-primary-sm"
                            :disabled="minutesForm.isDirty"
                            :title="minutesForm.isDirty ? 'Save the changes first.' : `Publish the minutes to ${meeting.audience.toLowerCase()}. Once published they are the record.`"
                            @click="publishMinutes"
                        >
                            <span>Publish minutes</span>
                        </button>
                        <span v-if="minutesForm.isDirty" class="mt-why">Save the changes first.</span>
                    </template>
                </div>
            </form>

            <!-- A role that publishes the minutes without writing them still publishes them, from what was saved. -->
            <div v-else-if="meeting.can_minute && meeting.minutes && canPublish" class="mt-actions">
                <button
                    type="button"
                    class="btn-primary-sm"
                    :title="`Publish the minutes to ${meeting.audience.toLowerCase()}. Once published they are the record.`"
                    @click="publishMinutes"
                >
                    <span>Publish minutes</span>
                </button>
            </div>
        </div>

        <div class="mt-card">
            <div class="mt-head">Papers issued</div>
            <p v-if="documents.length === 0" class="mt-muted">No agenda or minutes have been issued as a document yet.</p>
            <div v-for="doc in documents" :key="doc.id" class="mt-doc">
                <div>
                    <div class="mt-doc-title">{{ doc.title }}</div>
                    <!-- The server's line already says how long it is kept (`Document::statusLine()`): said once. -->
                    <div class="mt-muted">{{ doc.status_line }}</div>
                </div>
                <a v-if="doc.is_ready" :href="`${root}/documents/${doc.id}`" class="text-link-sm">Download</a>
                <span v-else class="mt-muted">{{ doc.status === 'failed' ? 'Not issued' : 'Being produced' }}</span>
            </div>
        </div>

        <ConsoleDialog
            :open="askingPublish"
            :title="publishTitle"
            confirm-label="Publish"
            busy-label="Publishing…"
            :error="publishRefusal"
            @confirm="postPublish"
            @cancel="askingPublish = false"
        >
            <p class="mt-ask">{{ publishWhen }}</p>
            <p class="mt-ask">{{ publishWho }}</p>
        </ConsoleDialog>

        <ConsoleDialog
            :open="askingMinutes"
            :title="minutesTitle"
            confirm-label="Publish minutes"
            busy-label="Publishing…"
            :error="minutesRefusal"
            @confirm="postMinutes"
            @cancel="askingMinutes = false"
        >
            <p class="mt-ask">{{ minutesWhat }}</p>
            <p class="mt-ask">{{ minutesWho }}</p>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. The badge and buttons are board 36's
 * sheet's own classes; the cards, the definition lists and the notices are kept
 * to the tokens the boards define.
 */
button.btn-primary-sm,
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm {
    border: 0;
}

button[disabled] {
    cursor: not-allowed;
}

/* Greyed when it cannot be used yet (M96 level 2), its reason beside it: Publish and the minutes' buttons looked live. */
button.btn-primary-sm[disabled],
button.btn-outline-sm[disabled] {
    opacity: 0.55;
}

/* A held meeting that missed its quorum: Failed, so muted red, serious and clearly not an emergency (three weights). */
.status-badge.failed {
    background: var(--red-100);
    color: var(--red-700);
}

/* Publish, and why it is greyed when it is (M96 level 2): the reason beside it, read without hovering. */
.mt-publish {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.mt-why {
    font-size: 11px;
    font-weight: 600;
    color: var(--slate-600);
}

.mt-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 14px;
}

/* A column may shrink to the window: a grid item otherwise holds its column open at its content's width. */
.mt-grid > * {
    min-width: 0;
}

/* One column from 1023 px down, each card the phone's width (batch 1 item 12). */
@media (max-width: 1023px) {
    .mt-grid {
        grid-template-columns: 1fr;
    }
}

.mt-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 14px;
}

.mt-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin-bottom: 10px;
}

.mt-dl {
    display: grid;
    grid-template-columns: 110px 1fr;
    gap: 7px 14px;
    margin: 0;
    font-size: 12px;
}

.mt-dl dt {
    color: var(--slate-500);
    font-weight: 700;
}

.mt-dl dd {
    margin: 0;
    color: var(--navy-900);
}

.mt-muted {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.6;
}

.mt-agenda {
    margin: 0;
    padding-left: 18px;
    font-size: 12px;
    color: var(--navy-900);
    line-height: 1.8;
}

.mt-time {
    font-weight: 700;
    color: var(--navy-700);
    margin-right: 6px;
}

.mt-minutes {
    white-space: pre-line;
    font-size: 12px;
    color: var(--navy-900);
    line-height: 1.7;
    margin-top: 8px;
}

.mt-minutes-form {
    display: grid;
    gap: 8px;
    margin-top: 10px;
}

.mt-minutes-form textarea {
    width: 100%;
    min-height: 180px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 10px 12px;
    font: inherit;
    font-size: 12.5px;
    line-height: 1.6;
    color: var(--navy-900);
    background: var(--white);
    resize: vertical;
}

.mt-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
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

.mt-doc {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-top: 1px solid var(--navy-100);
}

.mt-doc:first-of-type {
    border-top: 0;
}

.mt-doc-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-900);
}

.mt-flash,
.mt-refusal {
    font-size: 12px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
}

.mt-flash {
    background: var(--success-100);
    color: var(--success-700);
}

.mt-refusal {
    background: var(--amber-100);
    color: var(--amber-700);
}

/* Publish's question (M62 B1): two short lines under its title, set close. */
.mt-ask {
    margin: 0;
}

.mt-ask + .mt-ask {
    margin-top: 6px;
}
</style>
