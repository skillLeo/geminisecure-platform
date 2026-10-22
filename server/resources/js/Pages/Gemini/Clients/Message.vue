<script setup>
import { computed, watch } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import { useLiveKind } from '../../../composables/useLiveKind'

// Writing a message is a form: nothing may re-read it under the person's typing (M18H §7.2).
useLiveKind('form')

/**
 * Message a client's committee — board screen super-admin-11.
 *
 * A security company tells a committee things that matter: a guard suspended
 * for a lapsed licence, an incident overnight. A conversation nobody can
 * produce afterwards is the one that turns into a dispute, so every message is
 * a record with a recipient, a sender and a timestamp, and none is deletable.
 *
 * THE CATEGORY ROUTES. Billing defaults to the Treasurer, compliance to the
 * Property Manager, general to the President. Sending everything to the
 * President is how a committee stops reading any of it. The sender may still
 * override the recipient — a general note to the Treasurer is legitimate — but
 * the default belongs to the platform rather than to whoever is typing.
 *
 * Recipients are the estate's own committee, read from their assignments. An
 * operator cannot address somebody who holds no role there, which is a
 * correctness rule and a privacy one at once: the picker cannot be used to
 * find out who works elsewhere.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    categories: { type: Array, required: true },
    contacts: { type: Array, required: true },
    recent: { type: Array, required: true },
})

const form = useForm({
    category: 'general',
    recipient_id: null,
    subject: '',
    body: '',
})

/** Who a category is for, unless the sender has chosen otherwise. */
const defaultRecipientFor = (categoryKey) => {
    const category = props.categories.find((c) => c.key === categoryKey)
    const match = props.contacts.find((c) => c.role_key === category?.role)

    return (match ?? props.contacts[0])?.id ?? null
}

form.recipient_id = defaultRecipientFor('general')

/*
 * Changing category moves the recipient to that category's desk — but only
 * while the sender has not picked someone themselves. Overwriting a deliberate
 * choice because a segment was clicked is how a message reaches the wrong
 * person.
 */
let recipientTouched = false

watch(
    () => form.category,
    (category) => {
        if (!recipientTouched) {
            form.recipient_id = defaultRecipientFor(category)
        }
    }
)

const recipient = computed(() => props.contacts.find((c) => c.id === form.recipient_id) ?? null)

const submit = () => form.post(`/clients/${props.estate.id}/message`, { preserveScroll: true })
</script>

<template>
    <Head :title="`Message — ${estate.name}`" />

    <GeminiConsole :title="`Message · ${estate.name}`" :back="{ href: `/clients/${estate.id}`, label: `Back to ${estate.name}` }">

        <div class="form-layout">
            <form class="form-panel" @submit.prevent="submit">
                <div class="seg" role="radiogroup" aria-label="Message category">
                    <button
                        v-for="category in categories"
                        :key="category.key"
                        type="button"
                        role="radio"
                        :aria-checked="form.category === category.key"
                        class="seg-item"
                        :class="{ active: form.category === category.key }"
                        @click="form.category = category.key"
                    >
                        {{ category.label }}
                    </button>
                </div>

                <div class="m-field">
                    <label for="recipient_id">To</label>
                    <div class="m-input">
                        <select
                            id="recipient_id"
                            v-model="form.recipient_id"
                            :disabled="contacts.length === 0"
                            :title="contacts.length === 0 ? 'This estate has no committee members on file yet' : undefined"
                            required
                            @change="recipientTouched = true"
                        >
                            <option v-for="contact in contacts" :key="contact.id" :value="contact.id">
                                {{ contact.name }} — {{ contact.role }}
                            </option>
                        </select>
                    </div>
                    <div v-if="form.errors.recipient_id" class="field-error">{{ form.errors.recipient_id }}</div>
                </div>

                <div class="m-field">
                    <label for="subject">Subject</label>
                    <div class="m-input" :class="{ focused: form.subject !== '' }">
                        <input id="subject" v-model="form.subject" type="text" maxlength="200" required />
                    </div>
                    <div v-if="form.errors.subject" class="field-error">{{ form.errors.subject }}</div>
                </div>

                <div class="m-field">
                    <label for="body">Message</label>
                    <div class="m-textarea">
                        <textarea id="body" v-model="form.body" maxlength="5000" required></textarea>
                    </div>
                    <div v-if="form.errors.body" class="field-error">{{ form.errors.body }}</div>
                </div>

                <button
                    type="submit"
                    class="stack-btn primary"
                    style="width: 100%"
                    :disabled="form.processing || contacts.length === 0"
                    :title="contacts.length === 0 ? 'There is nobody at this estate to send to yet' : undefined"
                >
                    <BoardIcon name="broadcast" :stroke="1.7" />
                    <span>{{ form.processing ? 'Sending…' : 'Send message' }}</span>
                </button>
            </form>

            <div class="preview-panel">
                <div class="preview-head">Estate contacts</div>

                <div v-for="contact in contacts" :key="contact.id" class="recipient-row">
                    <div class="assign-avatar">{{ contact.initials }}</div>
                    <div class="assign-txt">
                        <div class="an">{{ contact.name }}</div>
                        <div class="ap">{{ contact.role }}</div>
                    </div>
                </div>

                <div v-if="contacts.length === 0" class="preview-note">
                    Nobody holds a role at {{ estate.name }} yet. A message needs a committee member to reach, and
                    they are added when the estate's users are set up.
                </div>
                <!--
                    WHAT THIS ACTUALLY DOES, IN THE WORDS OF WHAT IT ACTUALLY DOES.

                    This note once read: "This sends to the Estate Console's Notices inbox and
                    copies <name>'s email on file. Delivery and read status will show here once
                    sent." Not one of those three things existed. `ClientMessaging::send` wrote a
                    row and an audit entry; there was no committee-facing inbox, no mail and no
                    notification, and nothing anywhere set `client_messages.read_at`. An operator
                    reading that sentence believed a treasurer had been emailed about an overdue
                    invoice. It was replaced by the true sentence — that nothing was delivered —
                    and the client then ruled for the inbox (Q-M40, D-M128), which is what the
                    copy below describes. There is still NO EMAIL, and this screen still does not
                    claim one.
                -->
                <div v-else class="preview-note">
                    This records the message on {{ estate.name }}'s file and puts it in
                    {{ recipient ? recipient.name.split(' ')[0] : 'the recipient' }}'s own Estate Console,
                    where their notification bell says it is waiting. You will see here when they open it.
                    Nobody is emailed — if it cannot wait, telephone.
                </div>

                <template v-if="recent.length">
                    <div class="preview-head" style="margin-top: 16px">Recently sent</div>
                    <div v-for="(message, i) in recent" :key="i" class="recipient-row">
                        <div class="assign-txt">
                            <div class="an">{{ message.subject }}</div>
                            <div class="ap">{{ message.detail }}</div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the segments as <div>s and both fields
 * as <div>s wrapping typed text; here they are radio buttons, an input, a
 * select and a textarea. The board's .seg-item, .m-input and .m-textarea rules
 * supply everything visual.
 */
/* The family and line height only: `font: inherit` would reset the board's .seg-item size and weight (13 E1, as board 34). */
button.seg-item {
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

.m-input input,
.m-input select {
    flex: 1;
    min-width: 0;
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

/* The board's .m-textarea is a fixed-height box holding a <span>. A real
 * textarea brings a border, a resize grip and its own scrollbar gutter; it
 * fills the box instead. */
.m-textarea textarea {
    width: 100%;
    height: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    resize: none;
}

button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.stack-btn[disabled],
.m-input select[disabled] {
    cursor: not-allowed;
}

/* Authored: the board has no error state to copy. Kept to the boards' own
 * tokens, and under the field it belongs to. */
.field-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    margin-top: 5px;
    line-height: 1.45;
}

</style>
