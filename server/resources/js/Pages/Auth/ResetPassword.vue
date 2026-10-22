<script setup>
import { onMounted, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'
import DoorNotice from '../../Components/DoorNotice.vue'
import { useWireframe } from '../../composables/useWireframe'

/**
 * Choose a new password — both doors. Ruled (12 §2, Wave 1).
 *
 * THE TOKEN AND THE ADDRESS TRAVEL TOGETHER. The link carries both, the form
 * posts both, and the broker refuses a token presented with any address but
 * the one it was issued for — so the address field is shown, read-only, as
 * the account this link opens, and nothing on this page can move the token.
 *
 * ONE REFUSAL FOR EVERY BAD LINK. Expired, used already, issued for another
 * address: the server answers the same sentence, because telling a visitor
 * which would tell a visitor holding somebody else's link something about the
 * account it belongs to.
 */
const props = defineProps({
    door: { type: Object, required: true },
    token: { type: String, required: true },
    email: { type: String, required: true },
})

useWireframe(
    props.door.key === 'estate'
        ? 'community-admin-01-login-dashboard-structure-and-residents'
        : 'super-admin-01-login-dashboard-and-activity'
)

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
})

const focusedField = ref(null)
const passwordField = ref(null)

onMounted(() => passwordField.value?.focus())

const submit = () => {
    form.post(`${props.door.login_href.replace(/\/login$/, '')}/reset-password`, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head title="Choose a new password" />

    <!-- At least the window's height, the card fitting its content (M97 item 2), as on the sign-in card. -->
    <div class="login-screen" style="min-height: 100vh">
        <div class="login-card">
            <div class="login-mark">
                <BrandMark :colourway="door.colourway" />
                <!-- One name on every door card (M97 item 2): the company, as sign-in says it (D-223). -->
                <div class="lm1">Gemini Security Limited</div>
                <div class="lm2">{{ door.mark }}</div>
            </div>

            <form @submit.prevent="submit">
                <div class="field">
                    <label for="reset-email">{{ door.email_label }}</label>
                    <div class="l-input">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M2 6l10 7 10-7" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <!--
                          Read-only, not disabled: the value must post, and a
                          disabled control's does not. The link chose the
                          account; this page does not.
                        -->
                        <input
                            id="reset-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            readonly
                            title="The account this link was issued for. A reset link opens one account and cannot be moved to another."
                        />
                    </div>
                    <!-- The link's refusal, under the account it was for (M97 item 2), with the way on. -->
                    <DoorNotice v-if="form.errors.email || form.errors.token" tone="error">
                        {{ form.errors.email ?? form.errors.token }}
                        <Link :href="door.forgot_href" class="rp-link">Ask for a new link.</Link>
                    </DoorNotice>
                </div>

                <div class="field">
                    <label for="reset-password">New password (at least ten characters)</label>
                    <div class="l-input" :class="{ focused: focusedField === 'password' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="reset-password"
                            ref="passwordField"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            required
                            minlength="10"
                            @focus="focusedField = 'password'"
                            @blur="focusedField = null"
                        />
                    </div>
                    <DoorNotice v-if="form.errors.password" tone="error">{{ form.errors.password }}</DoorNotice>
                </div>

                <div class="field">
                    <label for="reset-confirm">Again, to be sure</label>
                    <div class="l-input" :class="{ focused: focusedField === 'confirm' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="reset-confirm"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            minlength="10"
                            @focus="focusedField = 'confirm'"
                            @blur="focusedField = null"
                        />
                    </div>
                </div>

                <button type="submit" class="l-btn" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Change password' }}
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

.l-input input[readonly] {
    color: var(--slate-500);
}

.l-btn {
    width: 100%;
    border: 0;
    appearance: none;
}

/*
 * AT A PHONE'S WIDTH THE CARD KEEPS A GUTTER (M98 pass), exactly as the sign-in card: the board's fixed 400px card ran
 * to both edges of a 390px phone. Written under .login-screen so it outranks the sheet.
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

/* Authored: the refusal's own link. */
a.rp-link {
    color: inherit;
    font-weight: 700;
}
</style>
