<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'

/**
 * Notices — board screen community-admin-32.
 *
 * THE SEEN BAR IS THE POINT OF THE SCREEN AND IT IS COUNTED, NOT STORED. Board
 * 32 draws 47%, 71% and 88%, and each is read receipts over the size of the
 * audience at the moment the page is drawn. Both halves move — the numerator
 * every time somebody opens the notice, the denominator every time a household
 * moves in — so a stored percentage would be a photograph of a number still
 * changing, and it is the photograph a secretary would act on.
 *
 * TWO BYLINE SHAPES, AND THE BOARD IS RIGHT ABOUT BOTH. Its gate closure reads
 * "Posted by Property Manager" and its AGM notice "Posted by Delroy Samuels" —
 * a role on one and a person on the other. That is not an inconsistency to
 * normalise: a gate closure belongs to the office, because whoever manages the
 * estate next month owns it too, while an AGM notice is signed personally. The
 * server composes the line; this page prints it.
 *
 * THE COMPOSER POSTS IMMEDIATELY, because the board's one button says "Post
 * notice". A draft state exists in the schema and nothing here creates one —
 * the honest position, since no estate has asked for drafts and a control
 * nobody drew is a control nobody wants.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    /** The notices as the shared table reads them (M32 F5): newest first, a page at a time, with their search. */
    table: { type: Object, required: true },
    audiences: { type: Array, required: true },
    kinds: { type: Array, required: true },
    canPost: { type: Boolean, required: true },
    /** The election the "Elections" tab opens — the latest this estate has held. */
    electionYear: { type: Number, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 32 is drawn in "Reports Unit Claims and Notices" — its own sheet
 * defines `.notice-row`, `.notice-tag`, `.seen-bar` and `.compose-panel`, and
 * the governance sheet defines none of them. Checked against the sheet rather
 * than inferred from the sidebar item it sits under.
 */
useWireframe('community-admin-08-reports-unit-claims-and-notices')

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => (props.table.q ?? '') !== '',
})

const base = computed(() => page.url.split('/governance')[0])

/*
 * THE LOG, AS A TABLE (M32 F5): every published notice, newest first unless the headings ask otherwise, a page at a
 * time. The notice's own cell carries what the board's row drew — its title, its words, who posted it, its files and
 * the seen bar — and the share read is its own column, so the log can be ordered by it.
 */
const COLUMNS = [
    { key: 'posted', label: 'Posted', sortable: true },
    { key: 'title', label: 'Notice', sortable: true },
    { key: 'kind', label: 'Kind', sortable: true },
    { key: 'audience', label: 'To', sortable: true },
    // One line: "Read by" wrapped onto two at 1440 (Security's dev-server note, 12 Oct).
    { key: 'seen', label: 'Read by', sortable: true, class: 'nt-nowrap' },
]

const noticesPath = computed(() => `${base.value}/governance/notices`)

const retry = () => router.reload()

/** Why Post notice is greyed while the composer is half written (M96 level 2), said beside it. */
const postBlockedBy = computed(() => (draft.title.trim() === '' || draft.body.trim() === '' ? 'A notice needs a title and something to say.' : null))
const searchParams = computed(() => keptByTable(props.table))
const noun = computed(() => (props.table.total === 1 ? 'notice' : 'notices'))

/*
 * GENERAL UNLESS SOMEBODY CHOOSES URGENT (NOT-1). The composer opened on Urgent, so a notice nobody thought about went
 * to the estate as Urgent; Urgent is now a press.
 */
const draft = reactive({
    kind: 'general',
    title: '',
    body: '',
    audience_scope: props.audiences[0]?.key ?? 'estate',
    audience_phase: props.audiences[0]?.phase ?? null,
})

/*
 * THE FILE THE NOTICE CARRIES (M4 §3.5). The board draws three fields and no
 * fourth, because attachments were ruled in after it was drawn — and a notice
 * that says "see the contractor's letter" with no letter is the half of the
 * feature a resident would notice. It is optional, it is one file, and the
 * kinds offered are the kinds the server keeps (Notices::ATTACHMENT_TYPES);
 * anything else is refused there, in words, rather than silently dropped.
 *
 * Held apart from `draft` because a File is not a plain value: Inertia sends
 * the post as multipart the moment one is in the payload, and clearing it
 * after a successful post has to clear the INPUT too, or the reader sees a
 * file name still sitting under a composer that has already sent it.
 */
const ATTACHMENT_ACCEPT = '.pdf,.jpg,.jpeg,.png,.doc,.docx'
const attachment = ref(null)
const attachmentField = ref(null)

/*
 * WHAT WAS CHOSEN, SAID; WHAT WILL BE REFUSED, REFUSED NOW (GV-7). The input took a .exe and an 11 MB file, which the
 * server refused only after the press, named nothing, and offered no way to take a file off. The kinds and the 10 MB
 * are the server's own (Notices::ATTACHMENT_TYPES and `max:10240`); the server stays the judge.
 */
const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024
const attachmentRefusal = ref(null)

/** "240 KB", "11.0 MB": the size a person reads. */
const sizeLabel = (bytes) => (bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)

const clearFile = () => {
    attachment.value = null
    attachmentRefusal.value = null

    if (attachmentField.value) {
        attachmentField.value.value = ''
    }
}

const chooseFile = (event) => {
    const file = event.target.files?.[0] ?? null
    const extension = file === null ? '' : `.${String(file.name).split('.').pop().toLowerCase()}`

    const refusal =
        file === null
            ? null
            : !ATTACHMENT_ACCEPT.split(',').includes(extension)
              ? 'That file is not one a notice can carry: a PDF, a Word document, or a JPEG or PNG picture.'
              : file.size > ATTACHMENT_MAX_BYTES
                ? `That file is ${sizeLabel(file.size)}. A notice carries one of up to 10 MB.`
                : null

    clearFile()
    attachmentRefusal.value = refusal
    attachment.value = refusal === null ? file : null
}

/**
 * The audience field is one control over two stored columns.
 *
 * A notice is either estate-wide or scoped to one phase, and the composer shows
 * that as a single list — so the option's own index carries both halves and
 * neither can be set without the other. Choosing "Phase 3 only" and leaving the
 * scope on estate-wide is not a state a reader can produce.
 */
const chooseAudience = (event) => {
    const option = props.audiences[Number(event.target.value)]

    draft.audience_scope = option.key
    draft.audience_phase = option.phase
}

/*
 * The board draws the Title field with the amber focus ring, and the ring is
 * tracked from real focus events rather than hardcoded so it follows the caret.
 * Focused on mount because that is both what the board shows and where somebody
 * opening a composer wants to start typing — the same pattern the sign-in card
 * uses for its email field.
 */
const focusedField = ref(null)
const titleField = ref(null)

onMounted(() => titleField.value?.focus())

/*
 * WHAT THE LAST POST WAS REFUSED FOR, AND WHAT IT DID (B048). This composer used
 * to read neither: a file too large or of the wrong kind, a missing title, a
 * notice the service refused — each came back as an error nothing drew, and the
 * button looked as though it had done nothing. So did a post that WORKED, whose
 * "posted" note was flashed under a key no page is sent. Every field's refusal is
 * drawn under that field, a refusal of the notice as a whole above the button,
 * and what the server says it did in the two notes at the top.
 */
const errors = computed(() => page.props.errors ?? {})

const audienceError = computed(() => errors.value.audience_scope ?? errors.value.audience_phase ?? null)

/*
 * POST NOTICE ASKS FIRST (DR-055; Hassam, 10 Oct: a control that tells people something confirms first, naming how many
 * and by what channel, counted from the server). A notice is pushed to every resident it is for the moment it is posted,
 * so the press opens a question naming the chosen audience's own count (`size`, the server's) and the channel, and only
 * the dialog's own "Post notice" posts. Cancel sends nothing.
 */
const askingNotice = ref(false)

const chosenAudience = computed(
    () => props.audiences.find((option) => option.key === draft.audience_scope && (option.phase ?? null) === (draft.audience_phase ?? null)) ?? props.audiences[0] ?? { label: '', size: 0 }
)

const noticeTitle = computed(() => {
    const size = chosenAudience.value.size ?? 0

    return `Post this notice to ${size.toLocaleString('en-US')} resident${size === 1 ? '' : 's'}?`
})

const noticeWhat = computed(() => `${draft.title.trim()} · ${chosenAudience.value.label}.`)

const noticeWho = computed(() =>
    (chosenAudience.value.size ?? 0) === 0
        ? 'Nobody lives there yet, so nobody is told. You cannot unsend this.'
        : 'They are told in the app, and by email where they asked for it. You cannot unsend this.'
)

/*
 * A REFUSED POST STAYS IN ITS QUESTION, IN THE AMBER BAND (Hassam, 12 Oct, the final refusal rule, D-235): the question
 * was the thing doing it. It used to close and leave the refusal to a toast. A field's own fault is also drawn under its
 * field in the composer, where it is fixed once the question is closed.
 */
const noticeRefusal = ref(null)

const refusalInADialog = computed(() => askingNotice.value)

/*
 * A REFUSAL OF THE NOTICE AS A WHOLE, HANDED WITH THE PAGE, IS A TOAST, TOP RIGHT (M95 §2), where it was a line above the
 * button; never while the question is open, whose band says it (D-235). Amber, because red is kept for panic and life
 * safety (Hassam, 11 Oct). A field's own refusal stays under its field.
 */
watch(
    () => page.props.errors?.notice,
    (text) => {
        if (refusalInADialog.value) {
            return
        }

        if (text) {
            toast(text, { tone: 'warning' })
        }
    },
    { immediate: true },
)

const post = () => {
    noticeRefusal.value = null
    askingNotice.value = true
}

const postNotice = () => {
    noticeRefusal.value = null
    router.post(`${base.value}/governance/notices`, { ...draft, attachment: attachment.value }, {
        preserveScroll: true,
        forceFormData: attachment.value !== null,
        onError: (refused) => {
            noticeRefusal.value = refused?.notice ?? Object.values(refused ?? {})[0] ?? null
        },

        /*
         * A notice the server posted comes back as a success — even when its file
         * was then refused, which is said in the error note and not as a
         * validation error — so the composer is cleared whenever it went out and
         * the office is never left looking at a sent notice with Post still live.
         */
        onSuccess: () => {
            askingNotice.value = false
            draft.title = ''
            draft.body = ''
            clearFile()
        },
    })
}

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['notice'] })
</script>

<template>
    <Head title="Notices" />

    <EstateConsole
        title="Notices"
        :estate-name="estate.name"
        active="governance"
        search-placeholder="Search notices…"
        :search-route="noticesPath"
        :search-value="table.q ?? ''"
        :search-params="searchParams"
    >
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Governance is not part of your role’s access"
            body="A notice is an announcement to every household on the estate, so posting and reading the log open only to a role that holds Governance."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The notices could not be read"
            body="The estate database did not answer. Nothing has been posted and nobody has been told anything. This is a read that failed, and trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <!-- M96 level 3: a role that can never post has no composer at all, and the log takes the width. -->
        <div v-else class="notices-grid" :class="{ 'log-only': !canPost }">
            <div>
                <div class="subnav">
                    <div class="subnav-item active" aria-current="page">Notices</div>
                    <Link :href="`${base}/governance/meetings`" class="subnav-item">Meetings</Link>
                    <!--
                      An election is addressed by year, and /governance/elections
                      alone is not a route — so the tab is told which one, by the
                      same rule board 36's tab uses: the latest this estate has
                      held (`Governance::electionYear()`).
                    -->
                    <Link :href="`${base}/governance/elections/${electionYear}`" class="subnav-item">Elections</Link>
                </div>

                <EmptyState
                    v-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="No notice matches that search"
                    body="The search reads each notice's title and words. Every notice the estate has posted is still in the log. Clear the search to see them all."
                    action-label="Clear the search"
                    @action="router.visit(noticesPath)"
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="Nothing has been announced yet"
                    :body="canPost ? 'A notice tells every household on the estate something at once. Post the first one on the right. It appears here with a bar showing how much of the estate has read it.' : 'A notice tells every household on the estate something at once. When one is posted it appears here, with a bar showing how much of the estate has read it.'"
                />

                <DataTable v-else :columns="COLUMNS" :rows="rows" :table="table" :noun="noun">
                    <template #cell-title="{ row: notice }">
                        <div class="notice-txt">
                            <div class="n1">{{ notice.title }}</div>
                            <div class="n2">{{ notice.body }}</div>
                            <div class="notice-meta">{{ notice.meta }}</div>
                            <!--
                              What went out with it. The board drew no such line because attachments were ruled in after
                              it (M4 §3.5) — and without it the office presses Post and has no way of telling whether the
                              contractor's letter went with the notice. The name only: the file opens on the documents
                              route, behind the same Governance gate as this screen.
                            -->
                            <div v-if="notice.attachments.length" class="notice-files">
                                <a
                                    v-for="file in notice.attachments"
                                    :key="file.id"
                                    :href="`${base}/documents/${file.id}`"
                                    class="notice-file"
                                >{{ file.name }}</a>
                            </div>

                            <!-- The board's bar; the figure is on its title and in the next columns but one. -->
                            <div
                                class="seen-bar"
                                :title="`${notice.seen_count} of ${notice.audience_size} residents have read this · ${notice.seen_pct}%`"
                            >
                                <i :style="{ width: `${notice.seen_share}%` }"></i>
                            </div>
                        </div>
                    </template>
                    <template #cell-kind="{ row: notice }">
                        <div class="notice-tag" :class="notice.kind === 'urgent' ? 'urgent' : 'general'">
                            {{ notice.kind_label }}
                        </div>
                    </template>
                    <template #cell-seen="{ row: notice }">
                        <span :title="`${notice.seen_count} of ${notice.audience_size} residents`">{{ notice.seen_pct }}%</span>
                    </template>
                </DataTable>
            </div>

            <div v-if="canPost" class="compose-panel">
                <div class="compose-head">Post a notice</div>
                <!-- What the mark means, once, above the first field (batch 1 item 3). -->
                <RequiredMark legend />

                <!-- AUTHORED (B048): what the last post did. Absent on a fresh GET. -->

                <!-- One choice, said as one (GV-16): a radio group, as the meeting type's toggle is. -->
                <div class="seg" role="radiogroup" aria-label="Kind of notice">
                    <button
                        v-for="kind in kinds"
                        :key="kind.key"
                        type="button"
                        role="radio"
                        :aria-checked="draft.kind === kind.key ? 'true' : 'false'"
                        class="seg-item"
                        :class="{ active: draft.kind === kind.key }"
                        :disabled="!canPost"
                        :title="canPost ? undefined : reasons.post"
                        @click="draft.kind = kind.key"
                    >
                        {{ kind.label }}
                    </button>
                </div>
                <div v-if="errors.kind" class="field-error">{{ errors.kind }}</div>

                <div class="m-field">
                    <label for="notice-title">Title <RequiredMark /></label>
                    <div class="m-input" :class="{ focused: focusedField === 'title' }">
                        <input
                            id="notice-title"
                            ref="titleField"
                            v-model="draft.title"
                            type="text"
                            @focus="focusedField = 'title'"
                            @blur="focusedField = null"
                            maxlength="190"
                            required
                            :disabled="!canPost"
                            :title="canPost ? undefined : reasons.post"
                            placeholder="e.g. Water interruption, Phase 3"
                        />
                    </div>
                    <div v-if="errors.title" class="field-error">{{ errors.title }}</div>
                </div>

                <div class="m-field">
                    <label for="notice-body">Message <RequiredMark /></label>
                    <div class="m-textarea">
                        <textarea
                            id="notice-body"
                            v-model="draft.body"
                            maxlength="4000"
                            required
                            :disabled="!canPost"
                            :title="canPost ? undefined : reasons.post"
                            placeholder="e.g. NWC has scheduled a supply interruption for Phase 3 tomorrow, 9 AM–3 PM, for main line repairs."
                        ></textarea>
                    </div>
                    <div v-if="errors.body" class="field-error">{{ errors.body }}</div>
                </div>

                <div class="m-field">
                    <label for="notice-audience">Audience</label>
                    <div class="m-input">
                        <select
                            id="notice-audience"
                            :disabled="!canPost"
                            :title="canPost ? undefined : reasons.post"
                            @change="chooseAudience"
                        >
                            <option v-for="(option, index) in audiences" :key="index" :value="index">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                    <div v-if="audienceError" class="field-error">{{ audienceError }}</div>
                </div>

                <div class="m-field">
                    <label for="notice-attachment">Attach a file to the notice: a map, a letter, a form (optional)</label>
                    <div class="m-input">
                        <input
                            id="notice-attachment"
                            ref="attachmentField"
                            type="file"
                            :accept="ATTACHMENT_ACCEPT"
                            :disabled="!canPost"
                            :title="canPost ? undefined : reasons.post"
                            @change="chooseFile"
                        />
                    </div>
                    <div v-if="attachmentRefusal" class="field-error">{{ attachmentRefusal }}</div>
                    <div v-else-if="errors.attachment" class="field-error">{{ errors.attachment }}</div>
                    <!-- The file chosen, named, and the way to take it off (GV-7). -->
                    <div v-if="attachment" class="field-note notice-chosen">
                        <span>{{ attachment.name }} · {{ sizeLabel(attachment.size) }}</span>
                        <button type="button" class="text-link-sm" title="Take this file off the notice." @click="clearFile">Remove</button>
                    </div>
                    <div class="field-note">
                        One file: a PDF, a Word document, or a JPEG or PNG picture, up to 10 MB. Every household the
                        notice is for can open it from the Resident App.
                    </div>
                </div>

                <button
                    type="button"
                    class="stack-btn primary"
                    style="width: 100%; justify-content: center; height: 44px"
                    :disabled="postBlockedBy !== null"
                    :title="postBlockedBy ?? 'Post it to the audience chosen. It asks first.'"
                    @click="post"
                >
                    <span>Post notice</span>
                </button>
                <!-- M96 level 2: greyed, and why, beside it. -->
                <div v-if="postBlockedBy" class="field-note">{{ postBlockedBy }}</div>
            </div>
        </div>

        <ConsoleDialog
            :open="askingNotice"
            :title="noticeTitle"
            confirm-label="Post notice"
            busy-label="Posting…"
            :error="noticeRefusal"
            @confirm="postNotice"
            @cancel="askingNotice = false"
        >
            <p class="nt-ask">{{ noticeWhat }}</p>
            <p class="nt-ask">{{ noticeWho }}</p>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its two segment pills, its three fields
 * and its post control as <div>s and <span>s; here they are real controls, which
 * arrive with a border, buttonface grey, an intrinsic width and the browser's
 * own font. `.seg-item`, `.m-input`, `.m-textarea` and `.stack-btn` supply
 * everything visible, and nothing below reaches past a browser default — which
 * is the mistake D-045 records.
 */
button.seg-item,
button.subnav-item {
    border: 0;
    background: none;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

button.subnav-item {
    cursor: not-allowed;
}

a.subnav-item {
    text-decoration: none;
}

button.stack-btn.primary {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.seg-item[disabled],
button.stack-btn.primary[disabled] {
    cursor: not-allowed;
}

/* Greyed when it cannot be used yet (M96 level 2), with why under it: it looked pressable and did nothing. */
button.stack-btn.primary[disabled] {
    opacity: 0.55;
}

/*
 * URGENT IN AMBER, NOT RED (Hassam, 11 Oct): saturated red is kept for panic and life safety, and an urgent notice
 * (a water interruption, a closed gate) is neither. Board 32 drew it amber; app.css's site-wide rule turned every urgent
 * tag red, so this page puts the board's own colours back, one selector stronger than that rule.
 */
.notices-grid .notice-tag.urgent {
    background: var(--amber-500);
    color: var(--navy-900);
}

/*
 * The board draws typed-looking text inside .m-input and .m-textarea as a
 * <span>. A real <input>, <textarea> and <select> do not inherit font from an
 * ancestor, so the board's own type values are restated for the live fields to
 * land where the spans did.
 */
.m-input input,
.m-input select,
.m-textarea textarea {
    flex: 1;
    width: 100%;
    min-width: 0;
    border: 0;
    outline: 0;
    background: none;
    appearance: none;
    font-family: 'Inter', sans-serif;
    font-size: 12.5px;
    color: var(--navy-900);
}

.m-textarea textarea {
    min-height: 80px;
    resize: vertical;
}

.m-input input::placeholder,
.m-textarea textarea::placeholder {
    color: var(--slate-500);
    opacity: 1;
}

/*
 * AUTHORED BELOW THIS LINE. The board sets the two-column grid and the list
 * card with inline styles rather than named classes, so the same values are
 * declared here; the compose heading likewise.
 */
.notices-grid {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 18px;
}

/*
 * A column may shrink to the window, and the table scrolls in its own frame (seen at 390 px: the table held the column
 * open and the page ran to 539 px, since a grid item does not shrink below its content).
 */
.notices-grid > * {
    min-width: 0;
}

/* No composer for a role that can never post (M96 level 3): the log takes the width. */
.notices-grid.log-only {
    grid-template-columns: 1fr;
}

/* One column from 1023 px down: the log, then the composer, each the phone's width (batch 1 item 12). */
@media (max-width: 1023px) {
    .notices-grid {
        grid-template-columns: 1fr;
    }
}

.compose-head {
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    color: var(--navy-900);
    font-weight: 600;
    margin-bottom: 14px;
}

/*
 * The attachment field and the line of file names under a notice. Neither is on
 * board 32 — attachments were ruled in after it was drawn (M4 §3.5) — so both
 * are built from the board's own values: the field is an `.m-field` like the
 * three above it, and the names take the notice meta line's type and the
 * sub-navigation's navy.
 */
.m-input input[type='file'] {
    font-size: 11.5px;
    color: var(--slate-600);
}

.field-note {
    font-family: 'Inter', sans-serif;
    font-size: 11px;
    line-height: 1.5;
    color: var(--slate-500);
    margin-top: 6px;
}

/* The file chosen and its Remove, on one line (GV-7). */
.notice-chosen {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--navy-800);
}

.notice-files {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 6px;
}

.notice-file {
    font-family: 'Inter', sans-serif;
    font-size: 11.5px;
    color: var(--navy-600);
    text-decoration: underline;
}

/*
 * What the last post did and what it was refused for (B048). Not on board 32 —
 * a board is a still image and nothing has been pressed on it — so both take the
 * field note's type and the same green and red the governance screens' own notes
 * use; each field's refusal sits under it at the field note's size.
 */
.notice-flash,
.notice-refusal {
    font-family: 'Inter', sans-serif;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
}

.notice-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.notice-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.field-error {
    font-family: 'Inter', sans-serif;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.5;
    color: var(--amber-700);
    margin-top: 6px;
}

/* "Read by" on one line: the shared table draws its own headers, so this reaches into it. */
:deep(th.nt-nowrap) {
    white-space: nowrap;
}
</style>
