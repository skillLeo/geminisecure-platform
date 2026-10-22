<script setup>
import { onMounted, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'
import DoorNotice from '../../Components/DoorNotice.vue'
import { useWireframe } from '../../composables/useWireframe'

/**
 * The Gemini door's second step (M11 §0.3) — on the same card, in the same sheet, as the first (Auth/Login.vue).
 *
 *   challenge  the code from the authenticator app; or, the phone being lost, one of the recovery codes
 *   enrol      the first sign-in: scan the QR code (or type the key) into an authenticator app, then give its code
 *   codes      straight after enrolling: the eight recovery codes, shown this once
 *
 * No board draws this screen: its card, fields, button, notice and footer are the sign-in board's own classes,
 * so it is the same door, one step on. Nobody is signed in on this page before a code is accepted.
 */
const props = defineProps({
    door: { type: Object, required: true },
    mode: { type: String, required: true },
    email: { type: String, default: '' },
    qr: { type: String, default: null },
    secret: { type: String, default: null },
    codes: { type: Array, default: () => [] },
    continue: { type: String, default: '/' },
})

useWireframe('super-admin-01-login-dashboard-and-activity')

const useRecovery = ref(false)
const focused = ref(false)
const codeField = ref(null)

const form = useForm({ code: '' })
const recoveryForm = useForm({ recovery_code: '' })

onMounted(() => codeField.value?.focus())

const submit = () => {
    if (props.mode === 'enrol') {
        form.post('/two-factor/confirm', { onFinish: () => form.reset('code') })
    } else if (useRecovery.value) {
        recoveryForm.post('/two-factor/recovery', { onFinish: () => recoveryForm.reset('recovery_code') })
    } else {
        form.post('/two-factor', { onFinish: () => form.reset('code') })
    }
}

const cancel = () => router.post('/two-factor/cancel')
</script>

<template>
    <Head :title="mode === 'codes' ? 'Recovery codes' : 'Two-factor sign-in'" />

    <div class="login-screen tf-screen">
        <div class="login-card">
            <div class="login-mark">
                <BrandMark :colourway="door.colourway" />
                <!-- One name on every door card (M97 item 2): nobody is signed in here yet, so the company (D-223). -->
                <div class="lm1">Gemini Security Limited</div>
                <div class="lm2">{{ door.mark }}</div>
            </div>

            <template v-if="mode === 'codes'">
                <DoorNotice tone="ok">
                    Two-factor sign-in is set up for {{ email }}. Keep these recovery codes somewhere safe, away from your
                    phone: each signs you in once if the phone is lost. They are not shown again.
                </DoorNotice>
                <ol class="tf-codes" aria-label="Recovery codes">
                    <li v-for="code in codes" :key="code">{{ code }}</li>
                </ol>
                <Link :href="props.continue" class="l-btn tf-link-btn">I have saved them · continue</Link>
            </template>

            <form v-else @submit.prevent="submit">
                <div class="field tf-lead">
                    <label>Signing in as</label>
                    <div class="tf-email">{{ email }}</div>
                </div>

                <template v-if="mode === 'enrol'">
                    <DoorNotice tone="info">
                        This console needs a second factor. Scan this code with an authenticator app on your phone
                        (Google Authenticator, Microsoft Authenticator, 1Password or similar), then enter the six-digit
                        code it shows.
                    </DoorNotice>
                    <div class="tf-qr">
                        <img :src="qr" alt="QR code to set up your authenticator app" width="200" height="200" />
                    </div>
                    <div class="field">
                        <label>Or type this key into the app</label>
                        <!-- One group of four per span, so a line never breaks inside a group being typed. -->
                        <div class="tf-secret" :aria-label="secret">
                            <span v-for="(group, i) in (secret || '').split(' ')" :key="i" class="tf-group">{{ group }}</span>
                        </div>
                    </div>
                </template>

                <div v-if="!useRecovery" class="field">
                    <label for="tf-code">Six-digit code from your authenticator app</label>
                    <div class="l-input" :class="{ focused }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="6" y="2" width="12" height="20" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M11 18h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <input
                            id="tf-code"
                            ref="codeField"
                            v-model="form.code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="7"
                            required
                            @focus="focused = true"
                            @blur="focused = false"
                        />
                    </div>
                    <!-- Under the field it is about (M97 item 2), not above it. -->
                    <DoorNotice v-if="form.errors.code" tone="error">{{ form.errors.code }}</DoorNotice>
                </div>

                <div v-else class="field">
                    <label for="tf-recovery">Recovery code</label>
                    <div class="l-input" :class="{ focused }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="tf-recovery"
                            v-model="recoveryForm.recovery_code"
                            autocomplete="off"
                            autocapitalize="characters"
                            maxlength="20"
                            required
                            @focus="focused = true"
                            @blur="focused = false"
                        />
                    </div>
                    <DoorNotice v-if="recoveryForm.errors.recovery_code" tone="error">
                        {{ recoveryForm.errors.recovery_code }}
                    </DoorNotice>
                </div>

                <button type="submit" class="l-btn" :disabled="form.processing || recoveryForm.processing">
                    {{ form.processing || recoveryForm.processing ? 'Checking…' : mode === 'enrol' ? 'Turn on and sign in' : 'Sign in' }}
                </button>

                <div class="tf-actions">
                    <button v-if="mode === 'challenge'" type="button" class="tf-text-btn" @click="useRecovery = !useRecovery">
                        {{ useRecovery ? 'Use the code from my app' : 'Lost your phone? Use a recovery code' }}
                    </button>
                    <button type="button" class="tf-text-btn" @click="cancel">Sign in as somebody else</button>
                </div>
            </form>

            <div class="l-foot">{{ door.foot }}</div>
        </div>
    </div>
</template>

<style scoped>
/*
 * The sign-in board's own classes carry the card. These rules only make the three things no board draws — the QR
 * code, the typed key and the recovery codes — sit in that card in its own type, and take a real control's browser
 * defaults back off, as Login.vue does.
 */
.tf-screen {
    min-height: 100vh;
}

/*
 * AT A PHONE'S WIDTH THE CARD KEEPS A GUTTER (M98 pass), exactly as the sign-in card: the board's fixed 400px card ran
 * to both edges of a 390px phone. Written under .login-screen so it outranks the sheet.
 */
@media (max-width: 480px) {
    .tf-screen {
        padding: 24px 16px;
        box-sizing: border-box;
    }

    .login-screen .login-card {
        width: 100%;
        max-width: 400px;
        padding: 30px 22px;
        box-sizing: border-box;
    }
}

.l-input input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: none;
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    font-weight: 600;
    letter-spacing: 2px;
    color: var(--navy-900);
}

.l-btn {
    width: 100%;
    border: 0;
    appearance: none;
}

.tf-link-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.tf-email {
    font-family: 'Inter', sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--navy-900);
    word-break: break-all;
}

.tf-qr {
    display: flex;
    justify-content: center;
    margin: 14px 0;
}

.tf-qr img {
    width: 200px;
    height: 200px;
    background: var(--white);
    padding: 8px;
    border-radius: 10px;
    border: 1px solid var(--navy-200);
}

.tf-secret,
.tf-codes {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    color: var(--navy-900);
}

.tf-secret {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
    font-size: 13px;
    font-weight: 500;
    letter-spacing: 1px;
    user-select: all;
}

.tf-group {
    white-space: nowrap;
}

.tf-codes {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px 16px;
    margin: 16px 0 20px;
    padding: 0;
    list-style: none;
    font-size: 15px;
    font-weight: 500;
    letter-spacing: 1px;
    user-select: all;
}

.tf-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 10px;
    margin-top: 14px;
}

.tf-text-btn {
    min-height: 44px;
    padding: 0;
    border: 0;
    background: none;
    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 700;
    color: var(--amber-700);
    cursor: pointer;
}

.tf-text-btn:focus-visible {
    outline: 2px solid var(--amber-500);
    outline-offset: 2px;
}
</style>
