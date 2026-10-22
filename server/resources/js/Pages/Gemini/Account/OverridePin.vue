<script setup>
import { computed } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import { useLiveKind } from '../../../composables/useLiveKind'
import { agoFrom } from '../../../composables/useClock.js'

// Setting a PIN is a form: nothing on it goes stale (M18H §7.2).
useLiveKind('form')

/**
 * A supervisor's own clock-in override PIN (M4 §3.4).
 *
 * NO BOARD DRAWS THIS SCREEN. Kept to the Gemini sheet's tokens, and to one job:
 * set the PIN, or set a new one. Reached from the supervisor's own name in the sidebar.
 *
 * THE PIN IS NEVER SHOWN BACK. The server keeps only a hash of it, so there is nothing
 * to show: the screen says whether one is set, when, when it was last used, and whether
 * it is locked. Setting a new one is how a PIN is changed AND how a locked one comes back.
 *
 * Nothing typed here is kept in the page after it is sent: both PIN fields and the
 * password are cleared on success and on failure.
 */
const props = defineProps({
    pin: { type: Object, required: true },
    identifier: { type: String, required: true },
    digits: { type: Number, required: true },
    max_attempts: { type: Number, required: true },
})

const form = useForm({ current_password: '', pin: '', pin_confirmation: '' })

// How long ago, never the reader's own clock with no label (M19A §4.1).
const when = (iso) => agoFrom(iso, Date.now())

const state = computed(() => {
    if (!props.pin.is_set) {
        return { tone: 'warn', title: 'You have not set an override PIN', body: 'Until you do, you cannot start a guard\'s shift from their phone.' }
    }

    if (props.pin.locked) {
        return {
            tone: 'bad',
            title: 'Your PIN is locked',
            body: `A wrong PIN was entered ${props.max_attempts} times on a guard's phone. It stays locked until you set a new one below. If that was not you, tell the Head of Security.`,
        }
    }

    return {
        tone: 'ok',
        title: 'Your override PIN is set',
        body: `Set ${when(props.pin.set_at)}.${props.pin.last_used_at ? ` Last used ${when(props.pin.last_used_at)}.` : ' Not used yet.'}${props.pin.failed_attempts > 0 ? ` ${props.pin.failed_attempts} wrong ${props.pin.failed_attempts === 1 ? 'try' : 'tries'} since then.` : ''}`,
    }
})

const onlyDigits = (field) => {
    form[field] = String(form[field] ?? '').replace(/\D/g, '').slice(0, props.digits)
}

const save = () => {
    form.post('/account/override-pin', {
        preserveScroll: true,
        // Whatever the answer, what was typed does not stay in the page.
        onFinish: () => form.reset('current_password', 'pin', 'pin_confirmation'),
    })
}
</script>

<template>
    <Head title="Clock-in override PIN" />

    <GeminiConsole title="Clock-in override PIN" :back="{ href: '/dashboard', label: 'Back to dashboard' }">

        <div class="op-panel">
            <div class="op-head">What this PIN does</div>
            <p class="op-text">
                When a guard's phone cannot confirm who is holding it — their fingerprint or face did not match three
                times, or the phone has none set up — the shift does not start on its own. You can start it for them by
                entering your sign-in address and this PIN on their phone. Every use is recorded against your name, flags
                the shift, and is reported to the Head of Security.
            </p>
            <p class="op-text">
                On the guard's phone you are asked for <strong>{{ identifier }}</strong> and your {{ digits }}-digit PIN.
                Enter it yourself. Do not read it out, and set a new one here if anybody else has seen it.
            </p>
        </div>

        <div class="op-panel" :class="`op-${state.tone}`" role="status">
            <div class="op-head">{{ state.title }}</div>
            <p class="op-text">{{ state.body }}</p>
        </div>

        <form class="op-panel" autocomplete="off" @submit.prevent="save">
            <div class="op-head">{{ pin.is_set ? 'Set a new PIN' : 'Set your PIN' }}</div>
            <p class="op-text">
                {{ digits }} digits. {{ pin.is_set ? 'The PIN you have now stops working the moment you save.' : '' }}
                After {{ max_attempts }} wrong tries on a phone, a PIN locks until a new one is set here.
            </p>

            <div class="op-fields">
                <label>
                    <span>New PIN</span>
                    <input
                        v-model="form.pin"
                        type="password"
                        inputmode="numeric"
                        :maxlength="digits"
                        autocomplete="new-password"
                        required
                        @input="onlyDigits('pin')"
                    />
                </label>
                <label>
                    <span>The same PIN again</span>
                    <input
                        v-model="form.pin_confirmation"
                        type="password"
                        inputmode="numeric"
                        :maxlength="digits"
                        autocomplete="new-password"
                        required
                        @input="onlyDigits('pin_confirmation')"
                    />
                </label>
                <label>
                    <span>Your console password</span>
                    <input v-model="form.current_password" type="password" autocomplete="current-password" required />
                </label>
            </div>

            <div v-for="(message, key) in form.errors" :key="key" class="op-error" role="alert">{{ message }}</div>

            <div class="op-actions">
                <button type="submit" class="btn-primary-sm" :disabled="form.processing">
                    <span>{{ pin.is_set ? 'Save the new PIN' : 'Save the PIN' }}</span>
                </button>
            </div>
        </form>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. Kept to the tokens the Gemini boards define.
 */

button {
    font-family: inherit;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

.op-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-width: 760px;
}

.op-panel.op-warn {
    border-color: var(--amber-600);
}

.op-panel.op-bad {
    border-color: var(--red-700);
    background: var(--red-100);
}

.op-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.op-text {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
}

.op-fields {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 220px));
    gap: 10px;
    margin-top: 4px;
}

.op-fields label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.op-fields input {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.op-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-top: 4px;
}

.op-error {
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 4px;
    max-width: 760px;
}

.op-error {
    background: var(--red-100);
    color: var(--red-700);
}
</style>
