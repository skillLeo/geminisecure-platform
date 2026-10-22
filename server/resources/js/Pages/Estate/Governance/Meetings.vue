<script setup>
import { computed, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import RowActionsMenu from '../../../Components/RowActionsMenu.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'
import { meetingStageWord } from '../../../lib/meetingStages.js'

/**
 * The meeting register — board screen community-admin-36.
 *
 * THIS IS THE MODULE'S LANDING SCREEN. `EstateNavigation` points Governance's
 * sidebar item at `/governance/meetings` rather than at an election — an
 * election is addressed by year, so a constant would still be pointing at
 * 2026 in 2028, and the register is the one governance screen with a static
 * address that is never empty for an estate that has ever met.
 *
 * EVERY MEETING THE ESTATE HAS EVER HELD OR DRAFTED, in `Governance::meetingsBoard()`'s
 * own order — upcoming soonest-first, then past newest-first — which is the
 * only order a register reads sensibly in: the next thing a resident has to
 * turn up to, then the history behind it (D-052 fix #3, a `sortBy([...])`
 * comparator bug that had silently reordered this exact list).
 *
 * THIS IS WHERE A DRAFT BECOMES PUBLIC, AND NOWHERE ELSE. Board 12 only ever
 * saves a draft — `Governance::scheduleMeeting()`'s own docblock says so in
 * as many words — and `GovernanceController::publishMeeting()`'s says
 * publishing "is done from the register and nowhere else": the act belongs
 * on the one screen where the draft can be seen alongside the meetings
 * already announced. The redirect off board 12's own save says exactly that
 * — "saved as a draft. Publish it to notify households." — which is why the
 * Publish control below exists though board 36 itself never draws one: all
 * five of this estate's seeded meetings are already published, so that
 * branch renders on no row the fidelity check measures. It is `approve`, not
 * `update`, because a published meeting has already told every household in
 * its audience a date they will arrange their day around, and D-013 keeps
 * that a second pair of hands from the Secretary who drafted it.
 *
 * THE WEEKDAYS ARE DERIVED, NEVER STORED. Board 36 dates the AGM "Sat, Sep
 * 27" and the phase meeting "Sat, Oct 4" — both fall on a Sunday in this
 * estate's actual calendar (D-051 residual #5). `Meeting::dateLabel()` reads
 * the weekday off the stored `starts_at` rather than printing one this screen
 * chose, so nothing here can ever print a weekday the calendar contradicts.
 *
 * NO ROW ON THIS SCREEN TOUCHES A BALLOT. A meeting's quorum is counted from
 * `meeting_attendance` — who turned up to a sitting — which shares no table
 * and no column with `ballot_receipts` or `ballot_marks`. There is nothing
 * here that could be read, sorted or hovered to learn how a named household
 * voted, because nothing here is that vote at all.
 */
const props = defineProps({
    estate: { type: Object, required: true },

    /** A page of the meetings this estate has, already ordered and formatted. */
    rows: { type: Array, required: true },
    /** The register as the shared table reads it (M32 F5): its order, its columns, its search and this page's place. */
    table: { type: Object, required: true },

    /** Which election the "Elections" tab leads to — the latest year, never today's. */
    electionYear: { type: Number, required: true },

    /*
     * Whether this person may ever schedule a meeting. Since M96 a role that never may has no "Schedule meeting" and no
     * "Schedule the first meeting" at all (level 3): the scheduler's save is refused for it whatever is typed, so the
     * door led only to a form that could never be sent. The server still refuses the save.
     */
    canSchedule: { type: Boolean, required: true },

    canPublish: { type: Boolean, required: true },
    publishReason: { type: String, required: true },
    reasons: { type: Object, required: true },

    /** The row menus (M56 §4): what this person may do beside publishing. */
    canEdit: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
})

/*
 * Board 36 lives on the ninth Community Admin sheet, alongside Add Resident
 * and New Charge — not the sheet boards 9 to 12 share. Naming the wrong one
 * renders this screen with no table, no subnav and no status badges at all.
 */
useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

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

const newMeetingHref = computed(() => governance('/meetings/new'))
const electionsHref = computed(() => governance(`/elections/${props.electionYear}`))

/*
 * All six since the register has its search (M32 F5): a search that matched no
 * meeting is `empty-filtered`, and not `empty`, which is no meeting ever held.
 */
const state = useScreenState({ rows: () => props.rows.length, filtered: () => (props.table.q ?? '') !== '' })

/*
 * THE REGISTER, AS THE SHARED TABLE (M32 F5): board 36's own order — upcoming soonest-first, then past newest-first —
 * unless a heading asks for another, over every meeting, a page at a time.
 *
 * NO QUORUM COLUMN (M70 §1.1, E2E-M6): it read "Counted at the meeting" on every row, the width the action needed, and
 * the meeting's own page says what its quorum is and whether it was met.
 */
const COLUMNS = [
    { key: 'title', label: 'Meeting', sortable: true },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'date', label: 'Date', sortable: true },
    { key: 'audience', label: 'Audience', sortable: true },
    // A fixed column (batch 1 item 6): the row's action right-aligned beside it, its "..." menu in a column of its own.
    { key: 'actions', label: '', class: 'mt-actions-col' },
]

/** Whether the register is in its own order (no heading asked for another): upcoming first, then the past. */
const inOwnOrder = computed(() => (props.table.sort ?? null) === null && (props.table.default?.sort ?? 'agenda') === 'agenda')

/*
 * THE ARROW ON DATE (M70 §1.1). The register's own order is by date, upcoming soonest first, so Date is the heading
 * that carries the arrow in it; the table is told so, and one press on Date then orders by date the other way.
 */
const headingTable = computed(() => (inOwnOrder.value ? { ...props.table, default: { ...(props.table.default ?? {}), sort: 'date', dir: 'asc' } } : props.table))

/*
 * "PAST MEETINGS" (Hassam, 10 Oct: "make it look deliberate"). In the register's own order the past group starts under a
 * heading across the table, so the first past meeting reads as the start of a section and not as a sorting mistake.
 */
const PAST = { id: 'past-meetings', past_heading: true }

const shownRows = computed(() => {
    const first = inOwnOrder.value ? props.rows.findIndex((row) => row.upcoming === false) : -1

    return first === -1 ? props.rows : [...props.rows.slice(0, first), PAST, ...props.rows.slice(first)]
})

const headingRow = (row) => (row.past_heading ? 'past-heading' : null)
const headingAttrs = (row) => (row.past_heading ? { class: 'mt-past-row' } : {})

const meetingsHref = computed(() => governance('/meetings'))
const searchParams = computed(() => keptByTable(props.table))
const noun = computed(() => (props.table.total === 1 ? 'meeting' : 'meetings'))

const retry = () => router.reload()

/* ------------------------------------------------------------------ */
/* publishing — the one act this screen performs */
/* ------------------------------------------------------------------ */

/*
 * M96 LEVELS FOR PUBLISH. A role that can never publish (no Governance approve, D-013) has no Publish at all (level 3):
 * the draft's chip already says Draft. A draft inside its notice period has Publish greyed, its reason under it on the
 * screen (level 2), with "Move to 16 Oct" in the menu.
 */

// DR-056: the press opens the meeting with its question (who is told, the server's count), the way the row menu opens
// every other act there; it never tells anybody itself.
const publish = (row) => {
    if (!props.canPublish || row.publish_refusal !== null) {
        return
    }

    router.visit(governance(`/meetings/${row.id}?act=publish`))
}

/*
 * EVERY ROW'S MENU (M56 §4): the acts its stage allows that this person may take, each opening the meeting with that
 * act's dialog open, where it confirms, says how many households it tells and, to cancel or delete, asks for the name.
 * Publish stays on the row itself. "Move to 16 Oct" is the menu's first item now (M70 §1.1): the cell holds one action.
 */
const MENU = {
    edit: { label: 'Edit', title: 'Change anything about this draft.', may: 'edit' },
    edit_place: { label: 'Edit place and agenda', title: 'Change the place, the description or the agenda.', may: 'edit' },
    reschedule: { label: 'Change the date', title: 'Choose a new date. Households are told, and their replies start again.', may: 'publish' },
    postpone: { label: 'Postpone', title: 'Put the meeting off, with its new date if you know it. Households are told.', may: 'publish' },
    attendance: { label: 'Record attendance', title: 'Keep the register. Quorum is counted from it.', may: 'edit' },
    minutes: { label: 'Minutes', title: 'Record the minutes, and publish them.', may: 'edit' },
    archive: { label: 'Archive', title: 'Archive this meeting. Its minutes stay readable.', may: 'edit' },
    cancel: { label: 'Cancel meeting', title: 'Call the meeting off, with a reason. It stays on the register, marked.', may: 'publish', danger: true },
    delete: { label: 'Delete draft', title: 'Delete this draft. Nobody has been told of it.', may: 'delete', danger: true },
}

const mayDo = (may) => (may === 'publish' ? props.canPublish : may === 'delete' ? props.canDelete : props.canEdit)

/** "Move to 16 Oct" (M56 §2.2): a draft inside its notice period, to the earliest day it may have, at its own time. */
const moveItem = (row) =>
    row.move_label && (row.acts ?? []).includes('move_to_earliest') && mayDo('edit')
        ? [{ key: 'move_to_earliest', label: row.move_label, title: 'Move the draft to the earliest day its notice period allows, at the same time.', href: governance(`/meetings/${row.id}/move-to-earliest`), method: 'post' }]
        : []

const menuFor = (row) => [
    ...moveItem(row),
    ...(row.acts ?? [])
        .filter((act) => MENU[act] && mayDo(MENU[act].may))
        .map((act) => ({
            key: act,
            label: act === 'reschedule' && row.stage === 'postponed' ? 'Set a new date' : MENU[act].label,
            title: MENU[act].title,
            danger: MENU[act].danger === true,
            href: governance(`/meetings/${row.id}?act=${act}`),
        })),
]

/*
 * THE AGENDA AND THE MINUTES AS PAPER (12 §1). Server-rendered and queued, so
 * this asks for a document rather than producing one — and which of the two is
 * the row's own state, exactly as the board's action label already says.
 */
const paperForm = useForm({})

const askForPaper = (row) => {
    paperForm.post(governance(`/meetings/${row.id}/${row.has_minutes ? 'minutes' : 'agenda'}`), {
        preserveScroll: true,
        // A refusal is a toast top right that stays until closed (M95 §2), where it was a banner pushed into the page.
        onError: (errors) => {
            toast(errors.meeting ?? Object.values(errors)[0] ?? 'The paper was not issued.', { tone: 'warning' })
        },
    })
}

/*
 * A REFUSAL HANDED WITH THE PAGE IS A TOAST TOO (M95 §2). The row menu's own post ("Move to 16 Oct") comes back with
 * the register and its refusal, and this page has no hook into that visit, so what the page is handed is said here.
 * The same words are never shown twice, so a refusal this page also said in its own `onError` stays one message.
 * Amber, because red is kept for panic and life safety (Hassam, 11 Oct).
 */
watch(
    () => [page.props.errors?.starts_at, page.props.errors?.meeting],
    (refusals) => {
        for (const text of refusals) {
            if (text) {
                toast(text, { tone: 'warning' })
            }
        }
    },
    { immediate: true },
)

/** A stage in the vocabulary's words (M96 §2, M98 §3.5), never the server's own name for it. */
const stageWord = (row) => meetingStageWord(row.stage, row.stage_label)

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['meeting'] })
</script>

<template>
    <Head title="Meetings" />

    <EstateConsole
        title="Meetings"
        :estate-name="estate.name"
        active="governance"
        search-placeholder="Search meetings…"
        :search-route="meetingsHref"
        :search-value="table.q ?? ''"
        :search-params="searchParams"
    >
        <template #actions>
            <!-- M96 level 3: a role that can never schedule a meeting has no Schedule meeting at all. -->
            <Link v-if="canSchedule" :href="newMeetingHref" class="btn-primary-sm">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Schedule meeting</span>
            </Link>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Meetings are not part of your role’s access"
            body="Governance covers the estate’s elections, its meetings and the record of both, so it opens only to roles that hold it. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The meeting register could not be read"
            body="The estate database did not answer. No meeting has been drafted, published or held since this screen last loaded. This is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!-- What the last act was refused for is a toast top right (M95 §2), said in the script, never a banner here. -->

            <!--
              Three tabs, one module. The tab for the screen the reader is
              already on is text rather than a control, because there is
              nowhere for it to lead. Notices is board 32 — built since this
              tab was drawn inert — and behind the same governance gate.
            -->
            <div class="subnav">
                <Link :href="governance('/notices')" class="subnav-item">Notices</Link>
                <div class="subnav-item active" aria-current="page">Meetings</div>
                <Link :href="electionsHref" class="subnav-item">Elections</Link>
            </div>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="No meeting matches that search"
                body="The search reads each meeting's title, its kind and who it is for. Every meeting the estate has held or drafted is still in the register. Clear the search to see them all."
                action-label="Clear the search"
                @action="router.visit(meetingsHref)"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No meeting has ever been scheduled"
                body="Governance meetings (the AGM, an EGM, a committee sitting, a phase meeting) start on the scheduler and land here once saved. This estate has not held or drafted one yet."
                :action-label="canSchedule ? 'Schedule the first meeting' : null"
                @action="router.get(newMeetingHref)"
            />

            <DataTable v-else :columns="COLUMNS" :rows="shownRows" :table="headingTable" :noun="noun" :row-slot="headingRow" :row-attrs="headingAttrs">
                <!-- The past group's heading, across the table (M70 §1.1). -->
                <template #past-heading="{ span }">
                    <td :colspan="span" class="mt-past-heading">Past meetings</td>
                </template>

                <!-- The meeting itself, whole: agenda, quorum, minutes and the papers issued. -->
                <template #cell-title="{ row }">
                    <Link :href="governance(`/meetings/${row.id}`)" class="mt-row-link" :title="`Open ${row.title}: everything that can be done to it now`">
                        {{ row.title }}
                    </Link>
                    <!-- Where it is in its life (M56 §4.5), in the vocabulary's words (M96 §2). -->
                    <!-- It leads to the meeting, whose page holds what its stage allows (batch 1 item 10). -->
                    <Link :href="governance(`/meetings/${row.id}`)" class="mt-stage" :class="`stage-${row.stage}`" :title="`Open ${row.title}`">{{ stageWord(row) }}</Link>
                </template>
                <template #cell-type="{ row }">{{ row.type_label }}</template>
                <template #cell-actions="{ row }">
                    <!--
                      ONE ACTION AND THE MENU, NOTHING ELSE (M70 §1.1), side by side in one box with a gap, so the menu
                      never sits over the action (M94 §1.4). A draft's Publish only for a role that may ever publish
                      (M96 level 3); inside its notice period it is greyed with its reason under it on the screen
                      (level 2), with "Move to 16 Oct" in the menu. The period itself binds everyone and stays.
                    -->
                    <div class="mt-acts">
                            <span v-if="row.stage === 'draft' && canPublish" class="mt-publish">
                                <button
                                    type="button"
                                    class="mt-publish-btn"
                                    :disabled="row.publish_refusal !== null"
                                    :title="row.publish_refusal ?? 'Publish this meeting.'"
                                    @click="publish(row)"
                                >
                                    Publish
                                </button>
                                <span v-if="row.publish_refusal !== null" class="row-why">{{ row.publish_refusal }}</span>
                            </span>
                            <template v-else-if="row.stage === 'draft'"></template>
                            <Link v-else-if="row.stage === 'cancelled' || row.stage === 'postponed'" :href="governance(`/meetings/${row.id}`)" class="text-link-sm">Open</Link>

                            <!--
                              Status-driven, which is the board's own rule: an
                              upcoming meeting offers its agenda, a held one its
                              minutes. Both issue a document now (12 §1) —
                              server-rendered, queued, kept seven years — and a
                              meeting with no minutes recorded is refused rather
                              than handed a blank page that looks like a record.
                            -->
                            <button
                                v-else
                                type="button"
                                class="text-link-sm"
                                :disabled="paperForm.processing"
                                :title="
                                    row.has_minutes
                                        ? `Issue the minutes of ${row.title} as a PDF. It is rendered by a worker and kept for seven years.`
                                        : `Issue the agenda for ${row.title} as a PDF. It is rendered by a worker and kept for seven years.`
                                "
                                @click="askForPaper(row)"
                            >
                                {{ row.action }}
                            </button>

                            <!-- Everything else this row's stage allows, in one menu (M56 §4). -->
                            <RowActionsMenu :label="row.title" :items="menuFor(row)" class="mt-row-menu" />
                    </div>
                </template>
            </DataTable>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its topbar action, its subnav tabs and its row actions as
 * <div>s; here the topbar action and the "Notices" and "Elections" tabs are
 * real Links — app.css has already taken the UA's underline and blue off every
 * anchor on a board page, so none of them needs anything below — and the two
 * row actions are real buttons, which arrive wearing a border, buttonface grey
 * and the browser's own font. `.subnav-item` and `.text-link-sm` declare a
 * face and no border on this sheet, which is why both are safe to reset.
 */
button.subnav-item,
button.text-link-sm {
    border: 0;
    background: none;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/* The tab keeps the board's own padding; only its face is the browser's. */
button.subnav-item {
    padding: 9px 16px;
}
button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no flash and no refusal, because
 * nothing has ever been pressed on it. Kept to the tokens the boards define
 * and to shapes they already use, exactly as the sibling governance screens'
 * `.gov-flash` / `.gov-refusal`.
 */
.gov-flash,
.gov-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.gov-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.gov-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

/* The title opens the meeting. It reads as the board's plain cell text. */
a.mt-row-link {
    color: inherit;
    text-decoration: none;
}

/* M56 §4.5: the stage, as a chip beside the title. A cancelled meeting stays on the register, marked. */
.mt-stage {
    margin-left: 8px;
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--navy-700);
    white-space: nowrap;
}

/* Cancelled is finished, neither failed nor refused: an outline grey, as on the meeting's own page (three weights). */
.mt-stage.stage-cancelled {
    background: var(--white);
    border: 1px solid var(--slate-300);
    color: var(--slate-600);
}

.mt-stage.stage-draft,
.mt-stage.stage-postponed {
    background: var(--amber-100);
    color: var(--amber-700);
}

/*
 * THE ROW ACTIONS LINE UP (Hassam, 12 Oct, batch 1 item 6: Publish, View agenda, Open and View minutes differ in width,
 * so the "..." buttons wandered). The column is right-aligned; each row's action sits in the first of two columns,
 * right-aligned, and the menu in the second, a fixed 32 px, so every row's "..." is in the same place. A row with no
 * action of its own still puts its menu in the second column (M94 §1.4: side by side, never one over the other).
 */
:deep(td.mt-actions-col) {
    text-align: right;
    white-space: nowrap;
}

.mt-acts {
    display: grid;
    grid-template-columns: auto 32px;
    justify-content: end;
    justify-items: end;
    align-items: center;
    gap: 10px;
}

.mt-acts > .mt-row-menu {
    grid-column: 2;
}

.mt-acts > :not(.mt-row-menu) {
    grid-column: 1;
}

/* M96 level 2: a greyed Publish with its reason under it, on the screen (as Pay now on Bills & payments). */
.mt-publish {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
}

/* Publish reads as a button (item 6): it is the one act on the register, beside links that only open or issue a paper. */
button.mt-publish-btn {
    border: 1px solid var(--navy-600);
    border-radius: 8px;
    padding: 4px 12px;
    background: var(--navy-600);
    color: var(--white);
    font-family: inherit;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
    cursor: pointer;
}

button.mt-publish-btn[disabled] {
    border-color: var(--navy-200);
    background: var(--white);
    color: var(--slate-500);
    cursor: not-allowed;
}

.row-why {
    max-width: 240px;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--slate-500);
    white-space: normal;
    text-align: right;
}

/* "Past meetings" (M70 §1.1): a break across the table, in the sheet's own heading type, above the past group. */
.mt-past-heading {
    padding-top: 18px;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--slate-500);
    border-top: 2px solid var(--navy-100);
}
</style>
