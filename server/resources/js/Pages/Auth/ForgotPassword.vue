<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'
import DoorNotice from '../../Components/DoorNotice.vue'
import { useWireframe } from '../../composables/useWireframe'

/**
 * Forgot password — both doors. Ruled (12 §2, Wave 1).
 *
 * THE SAME CARD AS SIGN-IN, on whichever door it was reached from, drawn from
 * that door's own sheet: no board draws this screen, and a reset request that
 * looked like a different product would read as a phishing page to exactly
 * the person it is for.
 *
 * ONE SENTENCE WHATEVER HAPPENED. The server answers "if that address holds an
 * account, a link has been sent" to every request — known address, unknown
 * address, suspended account, throttled retry — and this page prints exactly
 * that. It never learns which case it was, so it cannot leak it.
 */
const props = defineProps({
    door: { type: Object, required: true },
})

useWireframe(
    props.door.key === 'estate'
        ? 'community-admin-01-login-dashboard-structure-and-residents'
        : 'super-admin-01-login-dashboard-and-activity'
)

const page = usePage()

const sent = computed(() => page.props.flash?.status ?? null)

const form = useForm({ email: '' })

const focusedField = ref(null)
const emailField = ref(null)

onMounted(() => emailField.value?.focus())

const submit = () => {
    form.post(props.door.forgot_href, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    })
}
</script>

<template>
    <Head title="Forgot password" />

    <!-- At least the window's height, the card fitting its content: the board's fixed 900px pushed it below a laptop's fold. -->
    <div class="login-screen" style="min-height: 100vh">
        <div class="login-card">
            <div class="login-mark">
                <BrandMark :colourway="door.colourway" />
                <!-- M24 (D-223): a door anybody can open names the company, never the product. -->
                <div class="lm1">Gemini Security Limited</div>
                <div class="lm2">{{ door.mark }}</div>
            </div>

            <form @submit.prevent="submit">
                <!--
                  ONE MESSAGE AT A TIME (M97 item 2). Before sending, what this card does; once sent, the server's
                  sentence in its place, the same words for every address because the server sends the same words. No
                  icon until there is something to report.
                -->
                <DoorNotice v-if="sent" tone="ok">{{ sent }}</DoorNotice>
                <p v-else class="fp-lead">
                    Enter the address on your account. If it holds an account, a link to choose a new password is sent
                    to it. The link works once, for thirty minutes.
                </p>

                <div class="field">
                    <label for="forgot-email">{{ door.email_label }}</label>
                    <div class="l-input" :class="{ focused: focusedField === 'email' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M2 6l10 7 10-7" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="forgot-email"
                            ref="emailField"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            required
                            @focus="focusedField = 'email'"
                            @blur="focusedField = null"
                        />
                    </div>
                    <!-- Under the field it is about, small (M97 item 2). -->
                    <DoorNotice v-if="form.errors.email" tone="error">{{ form.errors.email }}</DoorNotice>
                </div>

                <div class="l-row">
                    <Link :href="door.login_href" class="l-forgot">Back to sign in</Link>
                </div>

                <button type="submit" class="l-btn" :disabled="form.processing">
                    {{ form.processing ? 'Sending…' : 'Send reset link' }}
                </button>
            </form>

            <div class="l-foot">{{ door.foot }}</div>
        </div>
    </div>
</template>

<style scoped>
/* The same default-removal the sign-in card makes, for the same reasons. */
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

.l-btn {
    width: 100%;
    border: 0;
    appearance: none;
}

a.l-forgot {
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--amber-600);
}

/*
 * AT A PHONE'S WIDTH THE CARD KEEPS A GUTTER (M98 pass; the client: spacing and alignment), exactly as the sign-in
 * card: the board's fixed 400px card ran to both edges of a 390px phone. Written under .login-screen so it outranks
 * the sheet.
 */
@media (max-width: 480px) {
    .login-screen {
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

/* Authored: the one sentence this card carries that the sign-in card does not. */
.fp-lead {
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 16px;
}
</style>
