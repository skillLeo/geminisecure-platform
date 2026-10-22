<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Requests inbox — board screen super-admin-16.
 *
 * Leave, equipment and messages in one place, because a dispatcher's question
 * is "what is waiting on me" rather than "what kind of thing is waiting on me".
 *
 * THE STRIP ABOVE IS A SECTION INDEX, NOT A FILTER, and the board is what says
 * so: it marks "Leave & duty" active and then draws the equipment queue and the
 * message log underneath it. A filter that left the filtered-out sections on
 * screen would not be a filter. So the three are jump links to the three
 * headings, the counts on them are real, and everything the dispatcher has
 * waiting stays on one scroll — which is the point of an inbox.
 *
 * A DECISION HERE CHANGES A ROSTER. Approving eight days of leave takes a guard
 * off post, so deciding needs Dispatch UPDATE rather than view — an Admin
 * Assistant may watch this queue and may not empty it — and the buttons say so
 * on hover rather than 403ing whoever presses them.
 *
 * DENIALS NEED A REASON, and it is asked for inline rather than assumed. A
 * guard told no without one has a decision they can neither act on nor appeal.
 * An approval does not need one, so it is not demanded.
 *
 * The board draws only pending rows, and that is right: this is an inbox, and a
 * decided request has left it. History is its own screen.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    sections: { type: Array, required: true },
    leave: { type: Array, required: true },
    equipment: { type: Array, required: true },
    messages: { type: Array, required: true },
    canDecide: { type: Boolean, required: true },
    decideBlockedReason: { type: String, required: true },
})

/*
 * Counts on the first two and none on the third, exactly as drawn. Leave and
 * equipment are queues and their depth is the fact a dispatcher is deciding
 * against; the message log is a running record with no bottom, and a number on
 * it would be a number of nothing in particular.
 */
const tabs = computed(() => [
    { key: 'leave', label: `Leave & duty (${props.leave.length})`, href: '#leave-requests' },
    { key: 'equipment', label: `Equipment (${props.equipment.length})`, href: '#equipment-requests' },
    { key: 'messages', label: 'Messages', href: '#recent-messages' },
])

/** Which section was last jumped to. The board opens on the first. */
const here = ref('leave')

const state = useScreenState({
    rows: () => props.leave.length + props.equipment.length + props.messages.length,
})

/*
 * A denial opens a note field on that row rather than a dialog. The reason
 * belongs beside the request it refuses — a modal hides the thing being decided
 * at the moment the decision is made.
 */
const denying = ref(null)
const note = ref('')
const busy = ref(null)

const decide = (id, decision) => {
    // First press on Deny opens the note; the second sends it. Approve needs no
    // reason and so takes effect on the first press.
    if (decision === 'denied' && denying.value !== id) {
        denying.value = id
        note.value = ''

        return
    }

    busy.value = id

    router.post(
        `/dispatch/requests/${id}`,
        { decision, note: decision === 'denied' ? note.value : null },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null
                denying.value = null
            },
        }
    )
}

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['request'] })
</script>

<template>
    <Head title="Requests inbox" />

    <GeminiConsole title="Dispatch — requests inbox">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <template #actions>
            <!-- Decided requests have left the inbox; this is where they went. -->
            <Link
                href="/dispatch/requests/history"
                class="btn-outline-sm"
                title="Every request already decided — what was asked, what was decided, by whom and when."
            >
                <BoardIcon name="reports" :stroke="1.8" />
                <span>Request history</span>
            </Link>
        </template>

        <div class="subnav">
            <template v-for="section in sections" :key="section.label">
                <Link :href="section.href" class="subnav-item" :class="{ active: section.active }">
                    {{ section.label }}
                </Link>
            </template>
        </div>

        <div class="req-tabs">
            <a
                v-for="item in tabs"
                :key="item.key"
                :href="item.href"
                class="req-tab"
                :class="{ active: here === item.key }"
                @click="here = item.key"
            >
                {{ item.label }}
            </a>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="3" />

        <template v-else>
            <div id="leave-requests" class="panel-head" style="margin-top: 4px">Leave &amp; duty requests</div>

            <EmptyState
                v-if="leave.length === 0"
                variant="first-use"
                title="Nothing waiting"
                body="Leave and duty requests raised in the Guard App appear here for a decision. There are none outstanding."
            />

            <div v-for="row in leave" :key="row.id" class="req-card">
                <div class="req-avatar">{{ row.initials }}</div>
                <div class="req-txt">
                    <div class="rq1">{{ row.title }}</div>
                    <div class="rq2">{{ row.detail }}</div>
                    <div v-if="denying === row.id" class="deny-note">
                        <input
                            v-model="note"
                            type="text"
                            placeholder="Why is this refused? The guard sees this."
                            maxlength="500"
                            @keyup.enter="decide(row.id, 'denied')"
                        />
                    </div>
                </div>
                <div class="req-status" :class="row.status">{{ row.status_label }}</div>
                <div class="req-actions">
                    <button
                        type="button"
                        class="req-btn approve"
                        :disabled="!canDecide || busy === row.id"
                        :title="canDecide ? undefined : decideBlockedReason"
                        @click="decide(row.id, 'approved')"
                    >
                        Approve
                    </button>
                    <button
                        type="button"
                        class="req-btn deny"
                        :disabled="!canDecide || busy === row.id"
                        :title="canDecide ? undefined : decideBlockedReason"
                        @click="decide(row.id, 'denied')"
                    >
                        {{ denying === row.id ? 'Confirm' : 'Deny' }}
                    </button>
                </div>
            </div>

            <div id="equipment-requests" class="panel-head" style="margin-top: 18px">Equipment requests</div>

            <EmptyState
                v-if="equipment.length === 0"
                variant="first-use"
                title="Nothing waiting"
                body="Kit requests raised in the Guard App appear here for a decision. There are none outstanding."
            />

            <div v-for="row in equipment" :key="row.id" class="req-card">
                <div class="req-avatar">{{ row.initials }}</div>
                <div class="req-txt">
                    <div class="rq1">{{ row.title }}</div>
                    <div class="rq2">{{ row.detail }}</div>
                    <div v-if="denying === row.id" class="deny-note">
                        <input
                            v-model="note"
                            type="text"
                            placeholder="Why is this refused? The guard sees this."
                            maxlength="500"
                            @keyup.enter="decide(row.id, 'denied')"
                        />
                    </div>
                </div>
                <div class="req-status" :class="row.status">{{ row.status_label }}</div>
                <div class="req-actions">
                    <button
                        type="button"
                        class="req-btn approve"
                        :disabled="!canDecide || busy === row.id"
                        :title="canDecide ? undefined : decideBlockedReason"
                        @click="decide(row.id, 'approved')"
                    >
                        Approve
                    </button>
                    <button
                        type="button"
                        class="req-btn deny"
                        :disabled="!canDecide || busy === row.id"
                        :title="canDecide ? undefined : decideBlockedReason"
                        @click="decide(row.id, 'denied')"
                    >
                        {{ denying === row.id ? 'Confirm' : 'Deny' }}
                    </button>
                </div>
            </div>

            <div id="recent-messages" class="panel-head" style="margin-top: 18px">Recent messages</div>

            <EmptyState
                v-if="messages.length === 0"
                variant="first-use"
                title="No traffic yet"
                body="Broadcasts to posts and messages from guards on shift appear here as they are sent."
            />

            <div
                v-else
                style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 14px; padding: 4px 18px"
            >
                <div v-for="(message, i) in messages" :key="i" class="msg-row">
                    <!--
                      A broadcast wears a solid navy chip rather than a person's
                      initials. The board tints it inline, because its stylesheet
                      has no variant for it; copied verbatim rather than given a
                      class of my own.
                    -->
                    <div
                        class="req-avatar"
                        :style="message.broadcast ? 'background:var(--navy-600);color:var(--white);' : undefined"
                    >
                        {{ message.initials }}
                    </div>
                    <div class="msg-txt">
                        <div class="ms1">{{ message.title }}</div>
                        <div class="ms2">{{ message.body }}</div>
                    </div>
                    <div class="msg-time">{{ message.time }}</div>
                </div>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only, except the deny note.
 *
 * The board draws the section index and both row actions as <div>s. Here the
 * index items are anchors and the actions are real buttons, so an underline in
 * one case and a border, a face and Arial in the other would show through.
 * .req-tab, .req-btn and .btn-outline-sm supply everything visual.
 */
a.req-tab {
    text-decoration: none;
    color: inherit;
}

button.req-btn {
    border: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/*
 * The topbar control keeps ITS OWN BORDER. The board gives .btn-outline-sm a
 * 1.5px navy outline, and a blanket `border: 0` here out-specifies the board
 * and strips it off entirely — a real fidelity defect that survived a passing
 * measurement because 1.5px on one small control is well under the threshold.
 * The browser's own border never needed removing: an author rule already beats
 * the user agent's.
 */
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

/* The section strip: the board draws each tab as a <div>, so an anchor's
 * underline and a button's chrome both have to come back off. */
a.subnav-item,
a.btn-outline-sm {
    text-decoration: none;
}

button.subnav-item {
    border: 0;
    background: none;
    font-family: inherit;
}

button.subnav-item[disabled] {
    cursor: not-allowed;
}

button.req-btn[disabled],
button.btn-outline-sm[disabled] {
    cursor: not-allowed;
}

/*
 * The deny note. Authored, because the board draws no refusal path — it shows
 * a queue nobody has decided yet. Kept to the tokens the boards define, and
 * rendered only while a denial is being written, so it costs nothing against
 * the board in the state the board actually draws.
 */
.deny-note {
    margin-top: 8px;
}

.deny-note input {
    width: 100%;
    border: 1.5px solid var(--red-700);
    border-radius: 9px;
    background: var(--white);
    padding: 7px 11px;
    font-size: 12px;
    font-family: inherit;
    color: var(--navy-900);
    /* No outline reset here (M12 §4.1): it took the one focus ring away, and a keyboard user tabbing here saw nothing. */
}

.deny-note input::placeholder {
    color: var(--slate-500);
}
</style>
