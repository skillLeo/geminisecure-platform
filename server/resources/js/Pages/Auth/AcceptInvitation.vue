<script setup>
import { onMounted, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'
import DoorNotice from '../../Components/DoorNotice.vue'
import { useWireframe } from '../../composables/useWireframe'

/**
 * Accept an invitation — the one way an account comes into existence.
 *
 * DRAWN ON THE DOOR THE LINK LANDED ON, from that door's own sheet: the person
 * arriving here has never seen this platform, and the card that greets them
 * names the community they are joining, who asked, and what role. A link that
 * opens nothing draws the same card with the reason and no form — there is no
 * account to create.
 *
 * NAME AND PASSWORD, NOTHING ELSE. The address is the invitation's and is shown
 * read-only; the role is the inviter's decision and is shown as a fact. The
 * password is chosen here, once, on a link only this address received — so the
 * account is active the moment it exists and the person is signed in.
 */
const props = defineProps({
    door: { type: Object, required: true },
    token: { type: String, required: true },
    /** Null when the token opens nothing; `refusal` says why. */
    invitation: { type: Object, default: null },
    refusal: { type: String, default: null },
})

useWireframe(
    props.door.key === 'estate'
        ? 'community-admin-01-login-dashboard-structure-and-residents'
        : 'super-admin-01-login-dashboard-and-activity'
)

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
})

const focusedField = ref(null)
const nameField = ref(null)

onMounted(() => nameField.value?.focus())

const submit = () => {
    form.post(`${props.door.login_href.replace(/\/login$/, '')}/invitations/${props.token}`, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head title="Accept invitation" />

    <!-- At least the window's height, the card fitting its content (M97 item 2), as on the sign-in card. -->
    <div class="login-screen" style="min-height: 100vh">
        <div class="login-card">
            <div class="login-mark">
                <BrandMark :colourway="door.colourway" />
                <!-- One name on every door card (M97 item 2): the company, as sign-in says it (D-223). -->
                <div class="lm1">Gemini Security Limited</div>
                <div class="lm2">{{ door.mark }}</div>
            </div>

            <!-- A link that opens nothing: the reason, small (M97 item 2), and the way to sign in. -->
            <template v-if="refusal">
                <DoorNotice tone="error">{{ refusal }}</DoorNotice>
                <p class="ai-lead">Accounts are issued by invitation. Nothing was created.</p>

                <div class="l-row">
                    <Link :href="door.login_href" class="l-forgot">Go to sign in</Link>
                </div>
            </template>

            <form v-else @submit.prevent="submit">
                <!-- Who asked, for what, and how long the link stands: a fact about this door. -->
                <DoorNotice tone="info">
                    <strong>{{ invitation.inviter }}</strong> has invited you to
                    <strong>{{ invitation.where }}</strong> as <strong>{{ invitation.role }}</strong>. This link stands
                    until {{ invitation.expires_on }}.
                </DoorNotice>

                <div class="field">
                    <label for="invite-email">{{ door.email_label }}</label>
                    <div class="l-input">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M2 6l10 7 10-7" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="invite-email"
                            :value="invitation.email"
                            type="email"
                            readonly
                            title="The address this invitation was sent to. It becomes the address you sign in with."
                        />
                    </div>
                </div>

                <div class="field">
                    <label for="invite-name">Your name, as the committee will see it</label>
                    <div class="l-input" :class="{ focused: focusedField === 'name' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.6" />
                            <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="invite-name"
                            ref="nameField"
                            v-model="form.name"
                            type="text"
                            autocomplete="name"
                            required
                            maxlength="120"
                            @focus="focusedField = 'name'"
                            @blur="focusedField = null"
                        />
                    </div>
                    <!-- Under the field it is about (M97 item 2), not above the form. -->
                    <DoorNotice v-if="form.errors.name" tone="error">{{ form.errors.name }}</DoorNotice>
                </div>

                <div class="field">
                    <label for="invite-password">Choose a password (at least ten characters)</label>
                    <div class="l-input" :class="{ focused: focusedField === 'password' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="invite-password"
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
                    <label for="invite-confirm">Again, to be sure</label>
                    <div class="l-input" :class="{ focused: focusedField === 'confirm' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="invite-confirm"
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
                    {{ form.processing ? 'Creating your account…' : 'Accept and sign in' }}
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

a.l-forgot {
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--amber-600);
}

/*
 * AT A PHONE'S WIDTH THE CARD KEEPS A GUTTER (M98 pass), exactly as the sign-in card: the board's fixed 400px card ran
 * to both edges of a 390px phone, and an invitation is most often opened on a phone. Written under .login-screen so it
 * outranks the sheet.
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

/* Authored: what a refused link leaves the reader with, in the footer's own colour. */
.ai-lead {
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 8px 0 16px;
}
</style>
