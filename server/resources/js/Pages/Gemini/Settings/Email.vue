<script setup>
import { computed } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import { toast } from '../../../lib/toasts.js'
import RecentEmails from '../../../Components/RecentEmails.vue'
import SettingsTabs from './SettingsTabs.vue'
import { useLiveKind } from '../../../composables/useLiveKind'
import { agoFrom } from '../../../composables/useClock.js'

// A settings form (M18H §7.2).
useLiveKind('form')

/**
 * Where the platform sends mail from.
 *
 * NO BOARD DRAWS THIS. It is here because of what its absence cost: until 23 September 2026
 * the platform ran with no relay at all, and a resident signs in with a six-digit code posted
 * to their mailbox — so the Resident App could not be signed into by anybody who was not on
 * the server. Guard enrolment, invitations and every password reset were in the same position.
 *
 * THE PASSWORD FIELD IS WRITE-ONLY. The payload never carries it — the server reports only
 * whether one is set and when it last changed. Leaving the field empty keeps the stored one,
 * which is what lets somebody correct the from-name without re-typing a secret.
 *
 * SAVING IS NOT PROVING. Every change clears the verified mark, because a relay nobody has
 * sent through is a relay nobody knows works. The test button is the proof, and it reports
 * what actually happened in words rather than the relay's own numeric refusal.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    settings: { type: Object, required: true },
    canConfigure: { type: Boolean, required: true },
    writeDisabledReason: { type: String, required: true },
    /** M12 §5.1: the emails given up on after three attempts, newest first. */
    failures: { type: Array, default: () => [] },
    /** M18G §7: every email sent, newest first — to an address containing `address` when one was searched for. */
    sent: { type: Array, default: () => [] },
    address: { type: String, default: '' },
    /** The same log as the shared table reads it (M32 F5), a page at a time. */
    sentTable: { type: Object, default: null },
})

const form = useForm({
    host: props.settings.host ?? '',
    port: props.settings.port ?? 587,
    scheme: props.settings.scheme ?? '',
    username: props.settings.username ?? '',
    password: '',
    from_address: props.settings.from_address ?? '',
    from_name: props.settings.from_name ?? '',
})

const testForm = useForm({ to: '' })

const save = () => form.post('/settings/email', { preserveScroll: true, onSuccess: () => (form.password = '') })
/*
 * A test that went or did not go is what just happened ("Could not reach the relay"): a toast top right, that stays
 * until it is closed when it failed (Hassam, 12 October). A fault in the address stays under the field.
 */
const sendTest = () =>
    testForm.post('/settings/email/test', {
        preserveScroll: true,
        onError: (errors) => {
            if (errors.mail) {
                toast(errors.mail, { tone: 'error' })
            }
        },
    })

/**
 * The one setting people get wrong, said before they get it wrong.
 *
 * Port 465 is implicit TLS and needs `smtps`; 587 negotiates after connecting and needs no
 * scheme at all. The wrong pairing does not fail with anything that names the cause — it
 * times out — so this says so on the form rather than in the error afterwards.
 */
const schemeAdvice = computed(() => {
    const port = Number(form.port)

    if (port === 465 && form.scheme !== 'smtps') return 'Port 465 needs SSL/TLS from the first byte. Choose "SSL (implicit)".'
    if (port === 587 && form.scheme === 'smtps') return 'Port 587 negotiates encryption after connecting. Choose "STARTTLS".'

    return null
})

const verified = computed(() => {
    if (!props.settings.verified_at) return null

    // How long ago, never the reader's own clock with no label (M19A §4.1).
    return agoFrom(props.settings.verified_at, Date.now())
})
</script>

<template>
    <Head title="Email" />

    <GeminiConsole title="Platform settings">
        <SettingsTabs :tabs="tabs" />


        <div class="em-panel">
            <div class="em-section">The relay this platform sends through</div>
            <p class="em-head">
                Sign-in codes, invitations and password resets all leave from here. If this is wrong, nobody can sign in
                to either phone app — a resident's code is posted to their mailbox and there is no other way to get it.
            </p>

            <div class="em-state">
                <div class="em-row">
                    <span class="em-label">Currently</span>
                    <span class="em-value">{{ settings.source }}</span>
                </div>
                <div class="em-row">
                    <span class="em-label">Password</span>
                    <span class="em-value">{{ settings.password_set ? 'Set' : 'Not set' }}</span>
                </div>
                <div class="em-row">
                    <span class="em-label">Last proved</span>
                    <span class="em-value">{{ verified ?? 'Never — send a test message' }}</span>
                </div>
                <div v-if="settings.updated_by" class="em-row">
                    <span class="em-label">Last changed by</span>
                    <span class="em-value">{{ settings.updated_by }}</span>
                </div>
            </div>
        </div>

        <form class="em-panel" @submit.prevent="save">
            <div class="em-section">Settings</div>

            <div class="em-fields">
                <label>
                    <span>Host</span>
                    <input v-model="form.host" type="text" maxlength="190" required :disabled="!canConfigure" />
                </label>
                <label>
                    <span>Port</span>
                    <input v-model="form.port" type="number" min="1" max="65535" required :disabled="!canConfigure" />
                </label>
                <label>
                    <span>Encryption</span>
                    <select v-model="form.scheme" :disabled="!canConfigure">
                        <option value="smtps">SSL (implicit) — port 465</option>
                        <option value="">STARTTLS — port 587</option>
                    </select>
                </label>
                <label>
                    <span>Username</span>
                    <input v-model="form.username" type="text" maxlength="190" :disabled="!canConfigure" />
                </label>
                <label>
                    <span>Password</span>
                    <input
                        v-model="form.password"
                        type="password"
                        maxlength="190"
                        autocomplete="new-password"
                        :placeholder="settings.password_set ? '••••••••  leave blank to keep' : ''"
                        :disabled="!canConfigure"
                    />
                </label>
                <label>
                    <span>From address</span>
                    <input v-model="form.from_address" type="email" maxlength="190" required :disabled="!canConfigure" />
                </label>
                <label>
                    <span>From name</span>
                    <input v-model="form.from_name" type="text" maxlength="120" required :disabled="!canConfigure" />
                </label>
            </div>

            <p v-if="schemeAdvice" class="em-note">{{ schemeAdvice }}</p>
            <p v-if="!canConfigure" class="em-note">{{ writeDisabledReason }}</p>

            <div v-for="(message, key) in form.errors" :key="key" class="em-error">{{ message }}</div>

            <div class="em-actions">
                <button type="submit" class="btn-primary-sm" :disabled="!canConfigure || form.processing">
                    <span>Save</span>
                </button>
            </div>
        </form>

        <form class="em-panel" @submit.prevent="sendTest">
            <div class="em-section">Send a test message</div>
            <p class="em-head">
                The only way to know a relay works. It reports what actually happened — delivered, refused, or no answer
                at all — and what to change if it failed.
            </p>

            <div class="em-fields">
                <label>
                    <span>Send to</span>
                    <input v-model="testForm.to" type="email" maxlength="190" required :disabled="!canConfigure" />
                </label>
            </div>

            <div v-if="testForm.errors.to" class="em-error">{{ testForm.errors.to }}</div>

            <div class="em-actions">
                <button type="submit" class="btn-outline-sm" :disabled="!canConfigure || testForm.processing">
                    <span>{{ testForm.processing ? 'Sending…' : 'Send test' }}</span>
                </button>
            </div>
        </form>

        <!-- M18G §7: every email sent, and what became of it — searchable by address. -->
        <RecentEmails :sent="sent" :address="address" :table="sentTable" path="/settings/email" />

        <!--
          M12 §5.1: WHAT COULD NOT BE SENT, HERE AND NOT ONLY IN THE LOG. Every message is tried three times (the
          second and third by the relay's IPv4 address); one that still fails is listed, newest first, with who it
          was for — masked — and why, in words. Nothing about the message itself: no body, no code.
        -->
        <div class="em-panel">
            <div class="em-section">Emails that could not be sent</div>
            <p class="em-head">
                Each was tried three times before the platform gave up. Whoever it was for did not get it — a sign-in code
                listed here is somebody who could not get into the app. The newest fifty, on UTC.
            </p>

            <p v-if="failures.length === 0" class="em-note">None. Every email the platform has tried to send was accepted by the relay.</p>

            <table v-else class="em-failures">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">What</th>
                        <th scope="col">To</th>
                        <th scope="col">Why</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(failure, index) in failures" :key="index">
                        <td class="em-when">{{ failure.at }}</td>
                        <td>{{ failure.subject }}</td>
                        <td>{{ failure.recipients }}</td>
                        <td>
                            {{ failure.reason }}
                            <span class="em-tries">{{ failure.attempts }} {{ failure.attempts === 1 ? 'attempt' : 'attempts' }} · {{ failure.relay }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </GeminiConsole>
</template>

<style scoped>
.em-panel {
    background: var(--white);
    border-radius: 16px;
    padding: 20px 22px;
    margin-bottom: 16px;
}

.em-section {
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--navy-900);
    margin-bottom: 8px;
}

.em-head {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.65;
    margin-bottom: 16px;
    max-width: 70ch;
}

.em-state {
    background: var(--navy-100);
    border-radius: 12px;
    padding: 13px 15px;
}

.em-row {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    font-size: 12px;
}

.em-row + .em-row {
    margin-top: 6px;
}

.em-label {
    color: var(--slate-600);
}

.em-value {
    font-weight: 700;
    color: var(--navy-900);
}

.em-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 14px;
}

.em-fields label {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: 12px;
    color: var(--slate-600);
}

.em-fields input,
.em-fields select {
    /* navy-200, the consoles' field edge (M12 §4.1) — this page alone drew slate-300. */
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    padding: 8px 10px;
    font-size: 13px;
    color: var(--navy-900);
    background: var(--white);
}

.em-fields input:disabled,
.em-fields select:disabled {
    background: var(--navy-100);
    color: var(--slate-600);
}

.em-note {
    font-size: 12px;
    color: var(--slate-600);
    margin-top: 12px;
    line-height: 1.6;
}

.em-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}

.em-failures {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    color: var(--navy-900);
}

.em-failures th {
    text-align: left;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--slate-500);
    padding: 8px 10px;
    border-bottom: 1px solid var(--navy-100);
}

.em-failures td {
    padding: 10px;
    border-bottom: 1px solid var(--navy-100);
    vertical-align: top;
    line-height: 1.5;
}

.em-when {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
    color: var(--text-body);
}

.em-tries {
    display: block;
    margin-top: 3px;
    font-size: 11px;
    color: var(--slate-500);
}

/* A refusal of what was typed, under the form it belongs to: amber, as every ordinary refusal is. */
.em-error {
    background: var(--amber-100);
    color: var(--amber-700);
    border-radius: 10px;
    padding: 10px 13px;
    font-size: 12.5px;
    margin-top: 10px;
}
</style>
