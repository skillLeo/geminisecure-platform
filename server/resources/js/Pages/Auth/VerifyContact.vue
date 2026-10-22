<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'
import DoorNotice from '../../Components/DoorNotice.vue'
import { useWireframe } from '../../composables/useWireframe'

/**
 * Confirm the address an emergency alert is sent to — M10 §1.4, both doors.
 *
 * ONE PAGE FOR BOTH CONSOLES, drawn on the sign-in card of the door it was reached
 * from, because it is still part of getting in: the console has not opened yet.
 * `ContactVerificationController::show()` decides every word that differs.
 *
 * EVERY RULE ON THIS PAGE IS THE SERVER'S. The ten minutes, the five tries and the
 * one-code-a-minute cooldown arrive as props from `ContactVerification`, and the
 * countdown is drawn from the seconds the SERVER says remain — so reloading the page
 * cannot shorten it, and the page cannot state a rule the server does not enforce.
 * When the countdown reaches zero the button comes back; if the clock here is ahead
 * of the server's, the server refuses in a plain sentence and that sentence is shown.
 *
 * THE PHONE IS WORDED FROM `SmsSettings::canSend()`. No text-message provider is
 * connected today, so the page says texts are not sent yet rather than implying they
 * are.
 */
const props = defineProps({
    door: { type: Object, required: true },
    root: { type: String, default: '' },
    account: { type: Object, required: true },
    contact: { type: Object, required: true },
    code: { type: Object, default: null },
    rules: { type: Object, required: true },
    sms: { type: Object, required: true },
    required: { type: Boolean, default: true },
    continue_href: { type: String, default: null },
    administrator: { type: String, required: true },
})

useWireframe(
    props.door.key === 'estate'
        ? 'community-admin-01-login-dashboard-structure-and-residents'
        : 'super-admin-01-login-dashboard-and-activity'
)

const page = usePage()
const status = computed(() => page.props.flash?.status ?? null)

const sendForm = useForm({
    email: props.contact.email ?? '',
    phone: props.contact.phone ?? '',
})

const codeForm = useForm({
    verification_code: '',
})

const phoneForm = useForm({
    phone: props.contact.phone ?? '',
})

/* --- the two clocks, both counted from what the server said --------------- */

const resendIn = ref(props.code?.resend_in ?? 0)
const expiresIn = ref(props.code?.expires_in ?? 0)

watch(
    () => props.code,
    (code) => {
        resendIn.value = code?.resend_in ?? 0
        expiresIn.value = code?.expires_in ?? 0
    }
)

let tick = null

onMounted(() => {
    tick = setInterval(() => {
        if (resendIn.value > 0) resendIn.value -= 1
        if (expiresIn.value > 0) expiresIn.value -= 1
    }, 1000)
})

onBeforeUnmount(() => clearInterval(tick))

const clock = (seconds) => {
    const m = Math.floor(seconds / 60)
    const s = String(seconds % 60).padStart(2, '0')

    return `${m}:${s}`
}

/* --- what the page is showing -------------------------------------------- */

const normalised = (value) => (value ?? '').trim().toLowerCase()

/** A code is waiting for THIS address, still inside its ten minutes, with tries left. */
const waiting = computed(
    () =>
        props.code !== null &&
        props.code.state === 'waiting' &&
        expiresIn.value > 0 &&
        normalised(props.code.sent_to) === normalised(sendForm.email)
)

/** Changing a proven address clears the proof the moment a code is sent. */
const changingProven = computed(
    () => props.contact.verified && normalised(sendForm.email) !== normalised(props.contact.email)
)

const codeStateSentence = computed(() => {
    if (props.code === null) {
        return null
    }

    if (waiting.value) {
        return null
    }

    const expired = `The last code has expired. A code lasts ${props.rules.ttl_minutes} minutes. Send a new one.`

    return {
        expired,
        locked: `${props.rules.max_attempts} wrong codes were entered, so the last code no longer works. Send a new one.`,
        replaced: 'The last code was sent to a different address, or replaced by a newer one. Send a new one.',
        used: null,
        waiting:
            expiresIn.value <= 0 ? expired : 'The last code was sent to a different address. Send a new one to the address above.',
    }[props.code.state]
})

/**
 * The rules in sentences (M97 item 2: "A code works once, for 10 minutes, and allows 5 tries" read as one clause
 * contradicting itself). Every number is the server's.
 */
const rulesSentence = computed(() => {
    const tries = props.rules.max_attempts === 1 ? '1 wrong try' : `${props.rules.max_attempts} wrong tries`

    return `A new code can be sent once a minute. Each code works once, for ${props.rules.ttl_minutes} minutes, and stops working after ${tries}.`
})

/** Help for a code that has not come: only once one has been sent and not yet used, never beside "verified". */
const codeOutstanding = computed(() => props.code !== null && props.code.state !== 'used')

const sendLabel = computed(() => {
    if (sendForm.processing) return 'Sending…'
    if (resendIn.value > 0) return `Resend in ${clock(resendIn.value)}`

    return props.code === null ? 'Send code' : 'Resend code'
})

const send = () => {
    sendForm.post(`${props.root}/verify-contact/code`, { preserveScroll: true })
}

const verify = () => {
    codeForm.post(`${props.root}/verify-contact`, {
        preserveScroll: true,
        onFinish: () => codeForm.reset('verification_code'),
    })
}

const savePhone = () => {
    phoneForm.post(`${props.root}/verify-contact/phone`, { preserveScroll: true })
}

const signOut = () => router.post('/logout')

const codeField = ref(null)

watch(waiting, (now) => {
    if (now) {
        setTimeout(() => codeField.value?.focus(), 0)
    }
})
</script>

<template>
    <Head :title="contact.verified ? 'Your alert address' : 'Confirm your alert address'" />

    <div class="login-screen vc-screen">
        <div class="login-card vc-card">
            <div class="login-mark">
                <BrandMark :colourway="door.colourway" />
                <!-- One name on every door card (M97 item 2): the company, as sign-in and forgot password say it (D-223). -->
                <div class="lm1">Gemini Security Limited</div>
                <div class="lm2">{{ door.mark }}</div>
            </div>

            <!-- A verified account reaches this page from its menu to change the address: it is told where alerts go. -->
            <h1 class="vc-title">
                {{ contact.verified ? 'Where emergency alerts reach you' : 'Confirm where emergency alerts reach you' }}
            </h1>

            <!--
              THE BANNER §1.4 ASKS FOR, where it can be true. An unverified address is never used for alerting, and
              until this page is done the reader is not reached by email, so it says so, first, before anything else:
              something to do (M96's "Action required"), not a failure.
            -->
            <DoorNotice v-if="!contact.verified" tone="warn">
                <div class="vc-notice-head">Alerts cannot reach you by email until this address is verified.</div>
                <div v-if="required">
                    A panic at the estate is emailed to the people who can act on it. The console opens once you have
                    entered the code we send to this address.
                </div>
                <div v-else>
                    Your role does not receive panic alerts, so this step is not asked of you. You can go on to the
                    console.
                </div>
            </DoorNotice>

            <DoorNotice v-else tone="ok">
                Alerts reach you at <strong>{{ contact.email }}</strong>, verified {{ contact.verified_at_label }}.
            </DoorNotice>

            <DoorNotice v-if="status" tone="ok">{{ status }}</DoorNotice>

            <!-- 1. The address, and the phone (both required, M18C). -->
            <form class="vc-step" @submit.prevent="send">
                <div class="field">
                    <label for="vc-email">Email for alerts</label>
                    <div class="l-input">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M2 6l10 7 10-7" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <!-- A long address scrolls inside the field; the whole of it is on hover (M97 item 2). -->
                        <input
                            id="vc-email"
                            v-model="sendForm.email"
                            type="email"
                            autocomplete="email"
                            required
                            maxlength="191"
                            :title="sendForm.email"
                        />
                    </div>
                    <div class="vc-hint">
                        Filled in with the address you sign in with ({{ account.login_email }}). Change it if alerts
                        should go to a mailbox you check more often. Signing in does not change.
                    </div>
                    <div v-if="changingProven" class="vc-hint vc-hint--warn">
                        Sending a code to a new address stops alerts going to {{ contact.email }} straight away. They
                        reach the new address once its code is entered.
                    </div>
                    <DoorNotice v-if="sendForm.errors.email" tone="error">{{ sendForm.errors.email }}</DoorNotice>
                </div>

                <div v-if="!contact.verified" class="field">
                    <label for="vc-phone">Mobile number</label>
                    <div class="l-input">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="6" y="2" width="12" height="20" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M11 18h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <input
                            id="vc-phone"
                            v-model="sendForm.phone"
                            type="tel"
                            autocomplete="tel"
                            required
                            maxlength="32"
                            :placeholder="page.props.phoneExample?.local"
                        />
                    </div>
                    <div class="vc-hint">The control room calls it in an emergency. {{ sms.note }}</div>
                    <DoorNotice v-if="sendForm.errors.phone" tone="error">{{ sendForm.errors.phone }}</DoorNotice>
                </div>

                <DoorNotice v-if="sendForm.errors.resend" tone="error">{{ sendForm.errors.resend }}</DoorNotice>

                <button
                    type="submit"
                    class="l-btn"
                    :class="{ 'vc-btn--quiet': waiting }"
                    :disabled="sendForm.processing || resendIn > 0"
                >
                    {{ sendLabel }}
                </button>

                <div class="vc-hint">{{ rulesSentence }}</div>
            </form>

            <!-- 2. The code. -->
            <form v-if="code !== null && code.state !== 'used'" class="vc-step" @submit.prevent="verify">
                <div v-if="waiting" class="vc-sent">
                    Code sent to <strong>{{ code.sent_to }}</strong>. It expires in {{ clock(expiresIn) }}
                    · {{ code.attempts_left }} {{ code.attempts_left === 1 ? 'try' : 'tries' }} left.
                </div>
                <div v-else-if="codeStateSentence" class="vc-hint vc-hint--warn">{{ codeStateSentence }}</div>

                <div class="field">
                    <label for="vc-code">{{ rules.code_length }}-digit code</label>
                    <div class="l-input">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="vc-code"
                            ref="codeField"
                            v-model="codeForm.verification_code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            pattern="[0-9 ]*"
                            :maxlength="rules.code_length + 2"
                            :disabled="!waiting"
                            required
                        />
                    </div>
                    <DoorNotice v-if="codeForm.errors.verification_code" tone="error">
                        {{ codeForm.errors.verification_code }}
                    </DoorNotice>
                </div>

                <button type="submit" class="l-btn" :disabled="codeForm.processing || !waiting">
                    {{ codeForm.processing ? 'Checking…' : 'Verify' }}
                </button>
            </form>

            <!-- A verified account may keep its phone up to date without re-verifying. -->
            <form v-if="contact.verified" class="vc-step" @submit.prevent="savePhone">
                <div class="field">
                    <label for="vc-phone-only">Mobile number</label>
                    <div class="l-input">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="6" y="2" width="12" height="20" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M11 18h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <input
                            id="vc-phone-only"
                            v-model="phoneForm.phone"
                            type="tel"
                            autocomplete="tel"
                            required
                            maxlength="32"
                            :placeholder="page.props.phoneExample?.local"
                        />
                    </div>
                    <div class="vc-hint">The control room calls it in an emergency. {{ sms.note }}</div>
                    <DoorNotice v-if="phoneForm.errors.phone" tone="error">{{ phoneForm.errors.phone }}</DoorNotice>
                </div>

                <button type="submit" class="l-btn vc-btn--quiet" :disabled="phoneForm.processing">
                    {{ phoneForm.processing ? 'Saving…' : 'Save phone number' }}
                </button>
            </form>

            <!-- 3. When the code does not come: only once one has been sent, never beside "verified" (M97 item 2). -->
            <details v-if="codeOutstanding" class="vc-help">
                <summary>The code has not arrived</summary>
                <ol>
                    <li>Look in your spam or junk folder, and under "Promotions" or "Updates" if your mail sorts them.</li>
                    <li>Check the address above for a typing slip. If it is wrong, correct it and send a new code.</li>
                    <li>
                        Wait for the countdown (one code a minute) and press Resend. Each new code replaces the last,
                        so use the newest one.
                    </li>
                    <li>
                        Still nothing: ask {{ administrator }}. They can check the address held for you, but they cannot
                        verify it for you. Only the person who reads the mailbox can.
                    </li>
                </ol>
            </details>

            <div class="vc-actions">
                <a v-if="continue_href" :href="continue_href" class="vc-continue">Continue to the console</a>
                <button type="button" class="vc-signout" @click="signOut">Sign out</button>
            </div>

            <div class="l-foot">{{ door.foot }}</div>
        </div>
    </div>
</template>

<style scoped>
/*
 * No board draws this screen; it is the sign-in card carrying the steps §1.4 lists.
 * The card's own classes (.login-card, .field, .l-input, .l-btn) are the board's, its
 * notices the door's own (DoorNotice); what follows is only what a longer card with
 * three steps needs, in the board's own type and colour tokens.
 */
.vc-screen {
    min-height: 100vh;
    height: auto;
    padding: 40px 16px;
}

/*
 * An address with no space in it wraps inside the card rather than running past its edge (M97 item 2). Written under
 * .login-screen (M98 pass): as `.vc-card` alone its width lost to the board's fixed 400px card, so on a 390px phone the
 * card ran to both edges of the screen in spite of the gutter above.
 */
.login-screen .vc-card {
    /* The 400px it has always been drawn at on a desktop: the fix is the phone's, not a wider card. */
    max-width: 400px;
    width: 100%;
    box-sizing: border-box;
    overflow-wrap: anywhere;
}

@media (max-width: 480px) {
    .login-screen .vc-card {
        padding: 30px 22px;
    }
}

.vc-notice-head {
    font-weight: 700;
}

.vc-title {
    font-family: 'Inter', sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: var(--navy-900);
    margin: 0 0 14px;
    line-height: 1.35;
}

.vc-step {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--navy-200);
}

.l-input input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: none;
    font-family: 'Inter', sans-serif;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--navy-900);
}

.l-input input:disabled {
    color: var(--slate-500);
    cursor: not-allowed;
}

#vc-code {
    letter-spacing: 0.3em;
    font-variant-numeric: tabular-nums;
}

.l-btn {
    width: 100%;
    border: 0;
    appearance: none;
    margin-top: 10px;
}

.l-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.vc-btn--quiet {
    background: var(--white);
    color: var(--navy-900);
    box-shadow: inset 0 0 0 1px var(--slate-300);
}

.vc-hint {
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--slate-500);
    margin-top: 6px;
}

/* Near-black and bold, not amber: --amber-700 is 3.6:1 on white, under the 4.5:1 a
 * line of small text needs, and no darker amber is a token. */
.vc-hint--warn {
    color: var(--navy-900);
    font-weight: 600;
}

.vc-optional {
    font-weight: 500;
    color: var(--slate-500);
}

.vc-sent {
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--navy-900);
    margin-bottom: 8px;
}

.vc-help {
    margin-top: 16px;
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--navy-900);
}

.vc-help summary {
    cursor: pointer;
    font-weight: 700;
    color: var(--amber-600);
}

.vc-help ol {
    margin: 8px 0 0;
    padding-left: 18px;
}

.vc-help li + li {
    margin-top: 4px;
}

.vc-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 18px;
}

.vc-continue {
    font-weight: 700;
    font-size: 13px;
    color: var(--amber-600);
    text-decoration: none;
}

.vc-signout {
    margin-left: auto;
    border: 0;
    background: none;
    font: inherit;
    font-size: 13px;
    font-weight: 600;
    color: var(--slate-500);
    cursor: pointer;
    text-decoration: underline;
}
</style>
