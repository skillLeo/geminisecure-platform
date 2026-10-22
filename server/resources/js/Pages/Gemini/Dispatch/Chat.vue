<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { agoFrom, useTickingNow } from '../../../composables/useClock.js'

/**
 * Dispatch → Live chat (M50 §4.3) — no board draws it: the Resident App's Help & support draws "Live chat", and the
 * control room, here around the clock, answers it.
 *
 * WAITING FIRST. A resident whose question has no answer yet is at the top, the longest wait first, with how long they
 * have waited; then every other conversation, newest first. The same residents are in the bell.
 *
 * DELIVERED IS WHAT EACH SIDE WAS SHOWN. Opening a conversation here is the control room being shown the resident's
 * messages — their phone reads "Delivered" from that moment — and an answer reads "Delivered" here once their phone
 * has shown it. Answering needs Dispatch update; a viewer who may only watch reads the conversation and is told why
 * the box is shut.
 *
 * It moves by itself: a message from a phone, an answer from another desk, a conversation resolved — `chat.changed`
 * re-reads this page, keeping what is typed.
 */
const props = defineProps({
    /** Category B (13 C3): every row here was written on a resident's phone. */
    sourceBadge: { type: Object, required: true },
    sections: { type: Array, required: true },
    chats: { type: Array, required: true },
    thread: { type: Object, default: null },
    missing: { type: Boolean, required: true },
    typicalReplyMinutes: { type: Number, default: null },
    canReply: { type: Boolean, required: true },
    replyBlockedReason: { type: String, required: true },
})

const now = useTickingNow()

const waiting = computed(() => props.chats.filter((c) => c.waiting_since))

/** Two letters for the avatar: the resident's initials, or "R" for one with no name yet. */
const initials = (name) => {
    const letters = String(name ?? '').split(/\s+/).filter(Boolean).map((w) => w[0].toUpperCase())

    return letters.length ? letters.slice(0, 2).join('') : 'R'
}

/** "Waiting 12 minutes" — said in the age's own words ("just now" reads "Waiting since just now"). */
const waitWords = (iso) => {
    const ago = agoFrom(iso, now.value)

    return ago === null ? 'Waiting' : ago === 'just now' ? 'Waiting since just now' : `Waiting ${ago.replace(/ ago$/, '')}`
}

const rowStatus = (chat) => (chat.waiting_since ? waitWords(chat.waiting_since) : chat.status === 'resolved' ? 'Resolved' : 'Answered')

const speed = computed(() =>
    props.typicalReplyMinutes === null
        ? 'Not enough answers yet to say how fast this desk replies — the Resident App says who answers, not how fast, until there are.'
        : `Residents are told this desk typically replies in ${props.typicalReplyMinutes} min — the median over the last 30 days.`
)

/* --- answering ---------------------------------------------------------- */

const reply = useForm({ body: '' })
const max = 1000

const sendReply = () => {
    if (!props.thread || reply.processing || reply.body.trim() === '') {
        return
    }

    reply.post(`/dispatch/chat/${props.thread.id}/reply`, { preserveScroll: true, onSuccess: () => reply.reset('body') })
}

const resolving = ref(false)
const resolve = () => {
    if (!props.thread || resolving.value) {
        return
    }

    resolving.value = true
    router.post(`/dispatch/chat/${props.thread.id}/resolve`, {}, { preserveScroll: true, onFinish: () => (resolving.value = false) })
}

useLiveReload({ entities: ['chat'] })
</script>

<template>
    <Head title="Live chat" />

    <GeminiConsole title="Dispatch — live chat">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <div class="subnav">
            <template v-for="section in sections" :key="section.label">
                <Link :href="section.href" class="subnav-item" :class="{ active: section.active }">
                    {{ section.label }}
                </Link>
            </template>
        </div>

        <p class="chat-speed">{{ speed }}</p>

        <EmptyState
            v-if="chats.length === 0"
            variant="first-use"
            title="No conversations yet"
            body="Residents write here from Help & support in the Resident App. Anyone waiting on an answer is also in the bell."
        />

        <div v-else class="chat-layout">
            <div>
                <div class="panel-head" style="margin-top: 4px">
                    {{ waiting.length ? `Waiting on an answer (${waiting.length})` : 'Nobody is waiting' }}
                </div>
                <Link
                    v-for="chat in chats"
                    :key="chat.id"
                    :href="`/dispatch/chat?chat=${chat.id}`"
                    class="req-card chat-row"
                    :class="{ picked: thread && thread.id === chat.id }"
                    preserve-scroll
                >
                    <div class="req-avatar">{{ initials(chat.resident) }}</div>
                    <div class="req-txt">
                        <div class="rq1">{{ chat.resident }} · {{ chat.estate }}</div>
                        <div class="rq2">
                            <template v-if="chat.last">{{ chat.last.from === 'staff' ? 'You: ' : '' }}{{ chat.last.body }}</template>
                        </div>
                    </div>
                    <div class="chat-row-side">
                        <div class="req-status" :class="chat.waiting_since ? 'pending' : 'approved'">{{ rowStatus(chat) }}</div>
                        <div v-if="chat.unread" class="chat-unread" :aria-label="`${chat.unread} not read yet`">{{ chat.unread }}</div>
                    </div>
                </Link>
            </div>

            <div>
                <div v-if="missing" class="panel">
                    <div class="chat-note" role="status">That conversation is on an estate you do not cover, or it no longer exists.</div>
                </div>

                <div v-else-if="!thread" class="panel">
                    <div class="chat-note">Choose a conversation to read it and answer.</div>
                </div>

                <div v-else class="panel">
                    <div class="panel-head chat-head">
                        <span>{{ thread.resident }} · {{ thread.estate }}<span v-if="thread.signs_in_with" class="chat-who"> · signs in with {{ thread.signs_in_with }}</span></span>
                        <button
                            v-if="thread.status === 'open'"
                            type="button"
                            class="btn-outline-sm"
                            :disabled="!canReply || resolving"
                            :title="canReply ? 'Off the waiting list. If they write again, it opens again at the top.' : replyBlockedReason"
                            @click="resolve"
                        >
                            {{ resolving ? 'Resolving…' : 'Mark resolved' }}
                        </button>
                        <span v-else class="chat-who">Resolved — their next message opens it again</span>
                    </div>

                    <div class="chat-thread" aria-live="polite">
                        <div v-for="m in thread.messages" :key="m.id">
                            <div class="chat-bubble" :class="m.from === 'resident' ? 'dispatcher' : 'guard'">{{ m.body }}</div>
                            <div class="chat-meta" :style="m.from === 'resident' ? null : 'text-align:right;'">
                                {{ m.from === 'resident' ? (m.name ?? thread.resident) : `Gemini Security${m.name ? ' · ' + m.name : ''}` }}
                                · {{ agoFrom(m.at, now) }}
                                <template v-if="m.from !== 'resident'"> · {{ m.seen_at ? 'Delivered' : 'Sent' }}</template>
                            </div>
                        </div>
                    </div>

                    <form class="field-box chat-box" @submit.prevent="sendReply">
                        <input
                            v-model="reply.body"
                            type="text"
                            :disabled="!canReply || reply.processing"
                            :maxlength="max"
                            :placeholder="canReply ? `Answer ${thread.resident}…` : 'You can read this conversation, not answer it'"
                            :title="canReply ? undefined : replyBlockedReason"
                            :aria-label="`Answer ${thread.resident}`"
                        />
                        <button type="submit" class="chat-send" :disabled="!canReply || reply.processing || reply.body.trim() === ''">
                            {{ reply.processing ? 'Sending…' : 'Send' }}
                        </button>
                    </form>
                    <div v-if="reply.errors.body" class="chat-error">{{ reply.errors.body }}</div>
                    <div v-else-if="!canReply" class="chat-note">{{ replyBlockedReason }}</div>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Authored: no board draws this screen. It borrows the dispatch boards' own pieces — the request card for a
 * conversation, the panel, the chat bubbles of the alert page — and adds only the two-column frame, the picked row,
 * the unread count and the reply box's frame, each from the boards' tokens.
 */
.chat-layout {
    display: grid;
    grid-template-columns: minmax(280px, 380px) 1fr;
    gap: 18px;
    align-items: start;
}

.chat-speed {
    margin: 0 0 12px;
    font-size: 12px;
    color: var(--slate-500);
}

.chat-row {
    text-decoration: none;
    color: inherit;
}

.chat-row.picked {
    border-color: var(--navy-600);
}

.chat-row-side {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
}

.chat-unread {
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    background: var(--navy-600);
    color: var(--white);
    font-size: 11px;
    font-weight: 700;
    line-height: 20px;
    text-align: center;
}

.chat-head {
    gap: 12px;
}

.chat-who {
    font-size: 12px;
    font-weight: 400;
    color: var(--slate-500);
}

.chat-box {
    margin-top: 12px;
    height: 38px;
    border-radius: 10px;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    padding: 0 13px;
    gap: 8px;
}

.chat-box input {
    flex: 1;
    min-width: 0;
    border: 0;
    background: transparent;
    outline: none;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.chat-send {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    background: transparent;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-600);
    cursor: pointer;
    padding: 0;
    white-space: nowrap;
}

.chat-send:disabled {
    color: var(--slate-500);
    cursor: not-allowed;
}

.chat-error {
    margin-top: 6px;
    font-size: 12px;
    color: var(--red-700);
}

.chat-note {
    margin-top: 6px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--navy-700);
}
</style>
