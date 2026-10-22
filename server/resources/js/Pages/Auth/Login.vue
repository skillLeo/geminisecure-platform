<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'
import DoorNotice from '../../Components/DoorNotice.vue'
import { useWireframe } from '../../composables/useWireframe'

/**
 * Sign in — board screens super-admin-01 AND community-admin-01.
 *
 * This page wears the board class itself rather than going through
 * GeminiConsole: the sign-in screen has no sidebar and no topbar, so it is not
 * a console page, it is the door to one.
 *
 * ONE COMPONENT FOR BOTH DOORS. The two boards draw the same card with four
 * different strings and one extra row, and `LoginController::door()` is where
 * that difference lives — see its docblock for why this is not two files. Everything this component does that matters to security is therefore
 * written once: the single generic error, the absence of any registration
 * affordance, and no "remember me".
 *
 * WHICH BOARD'S STYLESHEET IT WEARS FOLLOWS THE CONSOLE, because the two cards
 * are drawn in different sheets and neither defines the other's classes. Board
 * 38 spent a whole measurement at 29% wearing a sheet that defined none of its
 * classes; a page that picks its sheet from a prop cannot make that mistake
 * silently, because the prop is the same one that picked the words.
 *
 * The card's DOM, classes and label text are the board's. Three things the
 * board draws as static decoration are real controls here — the two fields and
 * the submit button — and the only CSS in this file takes the browser's
 * default styling for those controls back off so the board's own .l-input and
 * .l-btn rules are what is seen.
 *
 * The board draws no "create account" link, which matches the platform: there
 * is no registration route anywhere in this application and there must never
 * be one. Accounts are issued by invitation.
 */
const props = defineProps({
    /**
     * Which door this is, and every word that differs between the two.
     *
     * NOT NAMED console, AND THAT IS NOT A STYLE CHOICE. console sits on
     * Vue's own template global allow-list beside Math and JSON, so
     * {{ console.mark }} compiles to the BROWSER's console object rather
     * than to this prop. Every interpolation renders empty and nothing throws:
     * the card came back with no console name, no field label, no button text
     * and no footer, and measured 7.08% against a board it otherwise matched.
     */
    door: { type: Object, required: true },
    // Empty outside local. The server does not even query roles there.
    quickLoginRoles: { type: Array, default: () => [] },
    // A message from the quick-login bypass, e.g. a role with no estate.
    quickLoginNotice: { type: String, default: null },
})

useWireframe(
    props.door.key === 'estate'
        ? 'community-admin-01-login-dashboard-structure-and-residents'
        : 'super-admin-01-login-dashboard-and-activity'
)

const CONSOLE_LABELS = {
    gemini: 'Gemini Console',
    estate: 'Estate Console',
}

const grouped = computed(() => {
    const groups = new Map()

    for (const role of props.quickLoginRoles) {
        if (!groups.has(role.console)) {
            groups.set(role.console, [])
        }
        groups.get(role.console).push(role)
    }

    return [...groups].map(([console, roles]) => ({
        console,
        label: CONSOLE_LABELS[console] ?? console,
        roles,
    }))
})

const page = usePage()

/** "Your password has been changed. Sign in with it." — from the reset door. */
const status = computed(() => page.props.flash?.status ?? null)

const form = useForm({
    email: '',
    password: '',
})

/*
 * The board draws the email field in its focused state. That state is tracked
 * from real focus events rather than hardcoded, so the ring follows the
 * caret — and the field is focused on mount, which is both what the board
 * shows and where anyone arriving here wants to start typing.
 */
const focusedField = ref(null)
const emailField = ref(null)

onMounted(() => emailField.value?.focus())

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Sign in" />

    <!--
      The board's inline height was 900px: on a laptop at 100 per cent the card's foot and its button fell below the
      fold. The screen is now at least the window's height, and the card fits its content (M97 item 2).
    -->
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
                  What the reset door said on the way here: a small notice, never a hero graphic (M97 item 2). Absent
                  on an ordinary visit.
                -->
                <DoorNotice v-if="status" tone="ok">{{ status }}</DoorNotice>

                <div class="field">
                    <label for="login-email">{{ door.email_label }}</label>
                    <div class="l-input" :class="{ focused: focusedField === 'email' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M2 6l10 7 10-7" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="login-email"
                            ref="emailField"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            required
                            @focus="focusedField = 'email'"
                            @blur="focusedField = null"
                        />
                    </div>
                </div>

                <div class="field">
                    <label for="login-password">Password</label>
                    <div class="l-input" :class="{ focused: focusedField === 'password' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="login-password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            required
                            @focus="focusedField = 'password'"
                            @blur="focusedField = null"
                        />
                    </div>
                    <!--
                      One message for both a wrong password and an unknown address: distinguishing them turns this form
                      into an oracle for which addresses hold accounts. Under the fields it is about, small, the error
                      alone (M97 item 2); how accounts are issued is at the card's foot, always.
                    -->
                    <DoorNotice v-if="form.errors.email" tone="error">{{ form.errors.email }}</DoorNotice>
                    <!-- A refusal about the password alone was drawn nowhere: the press looked dead (M98 pass). -->
                    <DoorNotice v-else-if="form.errors.password" tone="error">{{ form.errors.password }}</DoorNotice>
                </div>

                <!--
                  The Gemini door's own notice, and only its own. An estate console holds one community's records
                  rather than every client's, and nothing has ruled that a committee member must carry a second factor,
                  so the estate card promises no control that does not exist. See LoginController::console().
                -->
                <DoorNotice v-if="door.mfa_note" tone="info">{{ door.mfa_note }}</DoorNotice>

                <!--
                  A real link, on both doors (12 §2, Wave 1). A committee
                  member who cannot get in is exactly who reads this card. The
                  page it opens sends a single-use, thirty-minute link to the
                  address on the account and says the same sentence whether or
                  not the address holds one.
                -->
                <div v-if="door.forgot_password && door.forgot_placement === 'row'" class="l-row">
                    <Link :href="door.forgot_href" class="l-forgot">Forgot password?</Link>
                </div>

                <button type="submit" class="l-btn" :disabled="form.processing">
                    {{ form.processing ? door.submitting_label : door.submit_label }}
                </button>
            </form>

            <!-- How accounts come to exist, at the foot and always visible, not inside an error (M97 item 2). -->
            <p class="l-policy">Accounts are issued by invitation. If you cannot get in, ask your administrator.</p>

            <!--
              The Gemini door carries its forgot link here, as a fourth clause
              on the footer the board already draws, rather than in a row the
              board does not — see LoginController::doorFor().
            -->
            <div class="l-foot">
                {{ door.foot
                }}<template v-if="door.forgot_password && door.forgot_placement === 'foot'">
                    ·
                    <Link :href="door.forgot_href" class="l-foot-link">Forgot password?</Link>
                </template>
            </div>
        </div>
    </div>

    <!--
      Quick sign-in. Rendered only when the server sends roles, which it does
      only in local; in any other environment the array is empty and this block
      is absent from the DOM rather than merely hidden.

      It sits BELOW the login screen, not beside it, so it cannot move a
      single pixel of the card. Scroll to reach it.
    -->
    <div v-if="quickLoginRoles.length > 0" class="content">
        <DoorNotice v-if="quickLoginNotice" tone="warn">
            {{ quickLoginNotice }} Nothing was signed in. Pick another role below.
        </DoorNotice>

        <div class="warn-banner">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                <path
                    d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
                    stroke="currentColor"
                    stroke-width="1.7"
                />
            </svg>
            <div>
                <div class="wb1">Quick sign-in &middot; local development only</div>
                <div class="wb2">
                    One click signs you in as that role, to compare what each one sees. These accounts exist only on
                    this machine, hold an unusable random password, and cannot be reached from the form above.
                </div>
            </div>
        </div>

        <div v-for="group in grouped" :key="group.console" class="report-group">
            <div class="report-group-head">{{ group.label }}</div>
            <div class="report-grid">
                <a
                    v-for="role in group.roles"
                    :key="role.name"
                    class="report-card"
                    :href="`/dev/login/${role.name}`"
                >
                    <div class="rc-name">{{ role.label }}</div>
                    <div class="rc-desc">{{ role.scope }}</div>
                    <div class="rc-action">Sign in as this role</div>
                </a>
            </div>
        </div>
    </div>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and only where a static thing on the
 * board had to become a real control.
 *
 * The board draws both fields as a <div> holding a <span> of typed-looking
 * text, and the submit as a <div>. A real <input> and a real <button> bring
 * the browser's own border, background, font and intrinsic width with them,
 * which would show through and change the pixels. These rules take exactly
 * those defaults back off. They introduce no colour, spacing or size the board
 * does not already declare.
 */

/* The type values here are the board's own `.l-input span` rule — 13.5px /
 * 500 / --navy-900. An <input> does not inherit font from its ancestor, so
 * they have to be restated for the live field to land where the span did. */
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

/* A <button> sizes to its content even at display:flex, so width has to be
 * restored to the block width the board's <div> had. Everything else the
 * board's own .l-btn rule already sets. */
.l-btn {
    width: 100%;
    border: 0;
    appearance: none;
}

/* The estate board draws "Forgot password?" as a <span> inside .l-row. A real
 * link brings an underline and the browser's blue; the type values here are
 * the board's own — 11.5px / 700 / --amber-600. */
a.l-forgot {
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--amber-600);
}

/* The footer clause: the footer's own type, in the board's amber for a link. */
a.l-foot-link {
    text-decoration: none;
    font: inherit;
    color: var(--amber-600);
    font-weight: 700;
}

/* How accounts come to exist, at the foot of the card (M97 item 2): the footer's own type, a line above it. */
.l-policy {
    margin: 18px 0 0;
    text-align: center;
    font-family: 'Inter', sans-serif;
    font-size: 11px;
    line-height: 1.5;
    color: var(--slate-500);
}

/*
 * AT A PHONE'S WIDTH THE CARD KEEPS A GUTTER (M98 pass; the client: spacing and alignment). The board's card is a fixed
 * 400px, so on a 390px phone it ran to both edges of the screen with its corners cut off. Under 480px it fills the width
 * less a 16px gutter each side, and its padding narrows with it. Written under .login-screen so it outranks the sheet.
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

/* Local-only quick sign-in: the board draws these cards as <div>, and a link
 * underlines every line inside itself. Removing that one default. */
a.report-card {
    text-decoration: none;
}
</style>
