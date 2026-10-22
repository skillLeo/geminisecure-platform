<script setup>
import { computed } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SettingsTabs from './SettingsTabs.vue'
import { useLiveKind } from '../../../composables/useLiveKind'
import { agoFrom } from '../../../composables/useClock.js'
import { toast } from '../../../lib/toasts.js'

// A settings form (M18H §7.2).
useLiveKind('form')

/**
 * Which provider the platform texts through — M10 §1.
 *
 * NO BOARD DRAWS THIS. It is the Email screen's twin, and for the same reason: the client asked
 * to connect Twilio "without a deploy", and until this existed a provider meant an SSH session,
 * an environment file and a secret pasted into a shell. Every panic text to a site's emergency
 * numbers and every sign-in code sent by SMS goes through what is saved here.
 *
 * THE TOKEN FIELD IS WRITE-ONLY. The payload never carries it — the server reports only whether
 * one is saved. Leaving the field empty keeps the stored one, which is what lets somebody change
 * the sender without re-typing a secret.
 *
 * "NONE" IS SAID PLAINLY, AND LOUDLY. While `can_send` is false the platform cannot send a text
 * message at all, and the note that says so sits above everything else on the page — worded once,
 * on the server, so this screen and the estate screens cannot disagree about it.
 *
 * SAVING IS NOT PROVING. Every change clears the verified mark. The test sends one real message
 * through the SAVED settings and reports what the provider said, in words and with its own code.
 * On "None" the button is off and says why: a test that "passed" because a message was written to
 * a log file would prove nothing.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    settings: { type: Object, required: true },
    /** Gemini Security's phone line: what the Resident App's "Call Gemini Security" calls, or hides while empty. */
    supportLine: { type: Object, required: true },
    drivers: { type: Array, required: true },
    canConfigure: { type: Boolean, required: true },
    writeDisabledReason: { type: String, required: true },
})

const lineForm = useForm({ support_phone: props.supportLine.display ?? '' })
const saveLine = () => lineForm.post('/settings/support-line', { preserveScroll: true })

const page = usePage()

const known = (driver) => props.drivers.some((d) => d.value === driver)

const form = useForm({
    driver: known(props.settings.driver) ? props.settings.driver : 'log',
    account_sid: props.settings.account_sid ?? '',
    token: '',
    from: props.settings.from ?? '',
})

const testForm = useForm({ to: '' })

const save = () => form.post('/settings/sms', { preserveScroll: true, onFinish: () => (form.token = '') })
/*
 * A test text that went or did not go is what just happened ("Could not reach the gateway"): a toast top right, that
 * stays until it is closed when it failed (Hassam, 12 October). A fault in the number stays under the field.
 */
const sendTest = () =>
    testForm.post('/settings/sms/test', {
        preserveScroll: true,
        onError: (errors) => {
            if (errors.sms) {
                toast(errors.sms, { tone: 'error' })
            }
        },
    })

const choosingNone = computed(() => form.driver === 'log')

const verified = computed(() => {
    if (!props.settings.verified_at) return null

    // How long ago, never the reader's own clock with no label (M19A §4.1).
    return agoFrom(props.settings.verified_at, Date.now())
})

/**
 * Why the test button is off, when it is — in a sentence, never a silent grey button.
 *
 * The test uses the SAVED settings, so an unsaved change in the form does not turn it on.
 */
const testDisabledReason = computed(() => {
    if (!props.canConfigure) return props.writeDisabledReason
    if (!props.settings.can_send) {
        return 'Save a provider with its account SID, auth token and sender first. On "None" a message is written to the log and delivered to nobody, so a test would prove nothing.'
    }

    return null
})

const missing = computed(() => {
    const items = props.settings.missing ?? []

    if (items.length === 0) return null

    return 'Still needed before a text can be sent: ' + items.join(', ') + '.'
})
</script>

<template>
    <Head title="SMS" />

    <GeminiConsole title="Platform settings">
        <SettingsTabs :tabs="tabs" />

        <!--
            A refusal is shown once, beside the form that caused it: `useForm` carries the
            response's errors on that form, so repeating `errors.sms` up here would print the
            provider's answer twice.
        -->

        <div v-if="!settings.can_send" class="sm-warning" role="status">
            <strong>No text messages are being sent.</strong>
            <span>{{ settings.note }}</span>
            <span v-if="missing">{{ missing }}</span>
        </div>

        <div class="sm-panel">
            <div class="sm-section">The provider this platform texts through</div>
            <p class="sm-head">
                A panic at a site is texted to that site's emergency numbers, and a resident may ask for their sign-in
                code by text. Both go through the provider saved here — and while it is "None", neither leaves the
                building.
            </p>

            <div class="sm-state">
                <div class="sm-row">
                    <span class="sm-label">Provider</span>
                    <span class="sm-value">{{ settings.driver_label }}</span>
                </div>
                <div class="sm-row">
                    <span class="sm-label">Currently</span>
                    <span class="sm-value">{{ settings.source }}</span>
                </div>
                <div class="sm-row">
                    <span class="sm-label">Auth token</span>
                    <span class="sm-value">{{ settings.has_token ? 'Saved' : 'Not saved' }}</span>
                </div>
                <div class="sm-row">
                    <span class="sm-label">Can send a text</span>
                    <span class="sm-value">{{ settings.can_send ? 'Yes' : 'No' }}</span>
                </div>
                <div class="sm-row">
                    <span class="sm-label">Last proved</span>
                    <span class="sm-value">{{ verified ?? 'Never — send a test message' }}</span>
                </div>
                <div v-if="settings.updated_by" class="sm-row">
                    <span class="sm-label">Last changed by</span>
                    <span class="sm-value">{{ settings.updated_by }}</span>
                </div>
            </div>
        </div>

        <form class="sm-panel" @submit.prevent="save">
            <div class="sm-section">Settings</div>

            <div class="sm-fields">
                <label>
                    <span>Provider</span>
                    <select v-model="form.driver" :disabled="!canConfigure">
                        <option v-for="driver in drivers" :key="driver.value" :value="driver.value">{{ driver.label }}</option>
                    </select>
                </label>
                <label>
                    <span>Account SID</span>
                    <input
                        v-model="form.account_sid"
                        type="text"
                        maxlength="191"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="AC…"
                        :disabled="!canConfigure"
                    />
                </label>
                <label>
                    <span>Auth token</span>
                    <input
                        v-model="form.token"
                        type="password"
                        maxlength="190"
                        autocomplete="new-password"
                        :placeholder="settings.has_token ? '••••••••  leave blank to keep' : ''"
                        :disabled="!canConfigure"
                    />
                </label>
                <label>
                    <span>Sender number or ID</span>
                    <input
                        v-model="form.from"
                        type="text"
                        maxlength="32"
                        autocomplete="off"
                        :placeholder="page.props.phoneExample?.international"
                        :disabled="!canConfigure"
                    />
                </label>
            </div>

            <p v-if="choosingNone" class="sm-note">
                With "None" chosen nothing is texted to anybody. The fields are kept, so switching back does not mean
                typing them again.
            </p>
            <p v-else class="sm-note">
                The account SID and auth token are on the front page of the Twilio console. The sender is a number bought
                on that account, or a sender ID registered on it.
            </p>
            <p v-if="!canConfigure" class="sm-note">{{ writeDisabledReason }}</p>

            <div v-for="(message, key) in form.errors" :key="key" class="sm-error">{{ message }}</div>

            <div class="sm-actions">
                <button type="submit" class="btn-primary-sm" :disabled="!canConfigure || form.processing">
                    <span>Save</span>
                </button>
            </div>
        </form>

        <form class="sm-panel" @submit.prevent="sendTest">
            <div class="sm-section">Send a test message</div>
            <p class="sm-head">
                The only way to know the provider works. One real text goes through the saved settings — not what is in
                the form above — and the provider's answer is reported as it was given: accepted, or refused and why.
            </p>

            <div class="sm-fields">
                <label>
                    <span>Send to</span>
                    <input
                        v-model="testForm.to"
                        type="tel"
                        maxlength="32"
                        required
                        :placeholder="page.props.phoneExample?.local"
                        :disabled="testDisabledReason !== null"
                    />
                </label>
            </div>

            <p v-if="testDisabledReason" class="sm-note">{{ testDisabledReason }}</p>

            <div v-if="testForm.errors.to" class="sm-error">{{ testForm.errors.to }}</div>

            <div class="sm-actions">
                <button
                    type="submit"
                    class="btn-outline-sm"
                    :disabled="testDisabledReason !== null || testForm.processing"
                    :title="testDisabledReason ?? undefined"
                >
                    <span>{{ testForm.processing ? 'Sending…' : 'Send test' }}</span>
                </button>
            </div>
        </form>

        <!--
            GEMINI SECURITY'S PHONE LINE (8 Oct 2026). Not a provider setting: the number every resident's "Call Gemini
            Security" calls. Empty, the Resident App hides that button — no placeholder number, no button that rings nothing.
        -->
        <form class="sm-panel" @submit.prevent="saveLine">
            <div class="sm-section">Gemini Security's phone number</div>
            <p class="sm-head">
                Residents see "Call Gemini Security" under Help &amp; support only while a number is saved here, and it
                calls this number. Leave it empty to hide the button. Kept in international form.
            </p>

            <div class="sm-state">
                <div class="sm-row">
                    <span class="sm-label">Residents are offered</span>
                    <span class="sm-value">{{ supportLine.display ? `Call Gemini Security · ${supportLine.display}` : 'Nothing — the button is hidden' }}</span>
                </div>
                <div v-if="supportLine.updated_by" class="sm-row">
                    <span class="sm-label">Last changed by</span>
                    <span class="sm-value">{{ supportLine.updated_by }}</span>
                </div>
            </div>

            <div class="sm-fields" style="margin-top: 14px">
                <label>
                    <span>Phone number</span>
                    <input
                        v-model="lineForm.support_phone"
                        type="tel"
                        maxlength="32"
                        autocomplete="off"
                        :placeholder="page.props.phoneExample?.international"
                        :disabled="!canConfigure"
                    />
                </label>
            </div>
            <p v-if="!canConfigure" class="sm-note">{{ writeDisabledReason }}</p>
            <div v-for="(message, key) in lineForm.errors" :key="key" class="sm-error">{{ message }}</div>

            <div class="sm-actions">
                <button type="submit" class="btn-primary-sm" :disabled="!canConfigure || lineForm.processing">
                    <span>{{ lineForm.support_phone.trim() === '' && supportLine.number ? 'Clear the number' : 'Save' }}</span>
                </button>
            </div>
        </form>
    </GeminiConsole>
</template>

<style scoped>
.sm-panel {
    background: var(--white);
    border-radius: 16px;
    padding: 20px 22px;
    margin-bottom: 16px;
}

.sm-section {
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--navy-900);
    margin-bottom: 8px;
}

.sm-head {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.65;
    margin-bottom: 16px;
    max-width: 70ch;
}

.sm-state {
    background: var(--navy-100);
    border-radius: 12px;
    padding: 13px 15px;
}

.sm-row {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    font-size: 12px;
}

.sm-row + .sm-row {
    margin-top: 6px;
}

.sm-label {
    color: var(--slate-600);
}

.sm-value {
    font-weight: 700;
    color: var(--navy-900);
    text-align: right;
}

.sm-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 14px;
}

.sm-fields label {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: 12px;
    color: var(--slate-600);
}

.sm-fields input,
.sm-fields select {
    border: 1px solid var(--slate-300);
    border-radius: 9px;
    padding: 8px 10px;
    font-size: 13px;
    color: var(--navy-900);
    background: var(--white);
}

.sm-fields input:disabled,
.sm-fields select:disabled {
    background: var(--navy-100);
    color: var(--slate-600);
}

.sm-note {
    font-size: 12px;
    color: var(--slate-600);
    margin-top: 12px;
    line-height: 1.6;
}

.sm-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}

/*
 * The "nothing is being texted" banner. Amber rather than red: it is not an error the reader
 * made, it is the platform's true state, and it stays until somebody connects a provider.
 */
.sm-warning {
    display: flex;
    flex-direction: column;
    gap: 4px;
    background: var(--amber-100);
    color: var(--navy-900);
    border: 1px solid var(--amber-500);
    border-radius: 12px;
    padding: 12px 15px;
    font-size: 12.5px;
    line-height: 1.6;
    margin-bottom: 16px;
}

.sm-warning strong {
    font-weight: 700;
}

/* A refusal of what was typed, under the form it belongs to: amber, as every ordinary refusal is. */
.sm-error {
    background: var(--amber-100);
    color: var(--amber-700);
    border-radius: 10px;
    padding: 10px 13px;
    font-size: 12.5px;
    margin-top: 10px;
}
</style>
