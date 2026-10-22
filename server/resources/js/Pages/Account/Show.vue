<script setup>
import { computed, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../Layouts/GeminiConsole.vue'
import EstateConsole from '../../Layouts/EstateConsole.vue'
import ConsoleAvatar from '../../Components/ConsoleAvatar.vue'
import ConsoleDialog from '../../Components/ConsoleDialog.vue'
import ProfilePhotoDialog from '../../Components/ProfilePhotoDialog.vue'
import { submitDialog } from '../../lib/dialogSubmit.js'
import { toast } from '../../lib/toasts.js'
import { useLiveKind } from '../../composables/useLiveKind'
import { useWireframe } from '../../composables/useWireframe'

// A page of forms: nothing on it goes stale under the reader (M18H §7.2).
useLiveKind('form')

/**
 * MY ACCOUNT (M32 F9): one page, the same in both consoles, in whichever console's shell the account belongs to.
 * Reached from the person's own name at the foot of the left navigation. Everything on it is the signed-in person's
 * own (App\Http\Controllers\Console\AccountController): there is no {user} anywhere.
 *
 * EVERY FORM A BUTTON OPENS IS THE CONSOLE'S MODAL (M95 §1; the hub's census, 12 Oct): the sign-in email's two steps,
 * the password, each two-step act, the authenticator's setup and the recovery codes shown once. Only "Your details",
 * the page's own form, stays on the page. Each save goes through `submitDialog`, so a refusal keeps the modal open with
 * what was typed (bar the password and code fields, cleared whatever the answer) and the server's words under the
 * fields; a save that went through closes it.
 *
 * NOTHING TYPED HERE STAYS IN THE PAGE. Every password and code field is cleared when its request finishes, whether
 * it was refused or not, and the recovery codes are shown on the one view the server sends them with.
 *
 * WHAT THE SERVER REFUSES, THIS PAGE DOES NOT OFFER. A role that requires two-step verification sees "Off" disabled
 * and no "Turn it off" at all (M96 level 3), with the reason; the server refuses the same request made by hand. The
 * email is never sent with the name and phone (the server refuses one by name): it changes in its own two steps
 * (M34 §1), the new address and the password, then the code emailed to the new address, and a demonstration account
 * is told why it cannot.
 *
 * No board draws this page. It is kept to the consoles' own tokens.
 */
const props = defineProps({
    console: { type: String, required: true },
    estate: { type: Object, default: null },
    root: { type: String, default: '' },
    details: { type: Object, required: true },
    signInEmail: { type: Object, required: true },
    password: { type: Object, required: true },
    twoFactor: { type: Object, required: true },
    sessions: { type: Array, required: true },
})

const page = usePage()

/*
 * THE ESTATE'S SHELL IS DRAWN BY A BOARD'S STYLESHEET, which every estate page names for itself; the Gemini shell names
 * its own. Without one, the estate's navigation lost its sizes on a page opened directly or reloaded, every icon as
 * wide as the bar. This page wears the board of the estate's Settings, where an account's own settings sit.
 */
if (props.console === 'estate') {
    useWireframe('community-admin-06-settings-estate-profile-users-and-roles')
}

const shell = computed(() => (props.console === 'estate' ? EstateConsole : GeminiConsole))
const shellProps = computed(() => (props.console === 'estate'
    ? { title: 'My account', estateName: props.estate?.name ?? '' }
    : { title: 'My account' }))

/*
 * EVERY MESSAGE A TOAST, TOP RIGHT (M95 §2). The estate's shell says every flash itself; the Gemini shell draws the
 * shared toast stack but does not say the flash yet (GeminiConsole.vue), so this page says its own there, as the
 * incident page does. A refusal is amber: red is an open alert's alone.
 */
watch(
    () => page.props.flash,
    (flash) => {
        if (props.console === 'estate' || !flash) {
            return
        }

        if (flash.success) {
            toast(flash.success, { tone: 'success' })
        }

        if (flash.error) {
            toast(flash.error, { tone: 'warning' })
        }
    },
    { immediate: true },
)

const phoneExample = computed(() => page.props.phoneExample?.local ?? '')
const path = (rest) => `${props.root}/account${rest}`

/** Which modal is open, if one is: 'email', 'password', 'on', 'off', 'replace' or 'codes'. */
const opened = ref('')
const close = () => {
    opened.value = ''
}

/* --- your details: the page's own form ---------------------------------- */

const mine = useForm({ name: props.details.name, phone: props.details.phone })

const saveDetails = () => {
    mine.post(path('/details'), { preserveScroll: true })
}

const photoOpen = ref(false)
const me = computed(() => ({ name: props.details.name, photo_version: props.details.photo_version }))

const removingPhoto = ref(false)
const removePhoto = () => {
    if (removingPhoto.value) return
    removingPhoto.value = true
    router.delete(path('/photo'), { preserveScroll: true, onFinish: () => { removingPhoto.value = false } })
}

/* --- sign-in email (M34 §1), in the modal ------------------------------- */

const moving = useForm({ new_email: '', current_password: '' })
const proving = useForm({ code: '' })

const openEmail = () => {
    moving.reset()
    moving.clearErrors()
    opened.value = 'email'
}

const sendEmailCode = () => {
    submitDialog(moving, 'post', path('/sign-in-email/code'), {
        close,
        // The password does not stay in the page, refused or not; the address does, so a slip can be put right.
        onFinish: () => { moving.current_password = '' },
    })
}

/** Step two's modal is open while a code is waiting: the server says so (`pending`), so it survives a reload. */
const finishEmail = () => {
    submitDialog(proving, 'post', path('/sign-in-email'), { onFinish: () => proving.reset() })
}

const cancellingEmail = ref(false)
const cancelEmail = () => {
    if (cancellingEmail.value) return
    cancellingEmail.value = true
    router.post(path('/sign-in-email/cancel'), {}, { preserveScroll: true, onFinish: () => { cancellingEmail.value = false } })
}

/* --- password, in the modal --------------------------------------------- */

const sending = useForm({})
const change = useForm({ current_password: '', code: '', password: '', password_confirmation: '' })

const openPassword = () => {
    change.reset()
    change.clearErrors()
    sending.clearErrors()
    opened.value = 'password'
}

const sendCode = () => {
    sending.post(path('/password/code'), { preserveScroll: true })
}

const changePassword = () => {
    submitDialog(change, 'post', path('/password'), {
        close,
        // Refused or not, what was typed does not stay in the page.
        onFinish: () => change.reset(),
    })
}

/* --- two-step verification, each act in the modal ---------------------- */

const tf = computed(() => props.twoFactor)
const locked = computed(() => tf.value.required_because ?? null)
const step = useForm({ current_password: '', code: '' })

const openStep = (which) => {
    step.reset()
    step.clearErrors()
    opened.value = which
}

const STEP_ROUTES = { on: '/two-factor/start', off: '/two-factor/off', replace: '/two-factor/replace', codes: '/two-factor/codes' }

const sendStep = () => {
    submitDialog(step, 'post', path(STEP_ROUTES[opened.value]), {
        close,
        onFinish: () => step.reset(),
    })
}

/** The radios say what is on; choosing the other one opens what changes it. */
const chooseOff = () => { if (tf.value.on && !locked.value) openStep('off') }
const chooseOn = () => { if (!tf.value.on && !tf.value.setup) openStep('on') }

const confirm = useForm({ code: '' })
const finishSetup = () => {
    submitDialog(confirm, 'post', path('/two-factor/confirm'), { onFinish: () => confirm.reset() })
}

const cancelling = ref(false)
const cancelSetup = () => {
    if (cancelling.value) return
    cancelling.value = true
    router.post(path('/two-factor/cancel'), {}, { preserveScroll: true, onFinish: () => { cancelling.value = false } })
}

/** The new recovery codes, on the one view that has them; put away by the person, and gone on the next view anyway. */
const codesKept = ref(false)
const newCodes = computed(() => (codesKept.value ? null : tf.value.new_codes ?? null))

// A later set of new codes is shown too, whoever put the last set away on this page.
watch(() => props.twoFactor.new_codes, (codes) => {
    if (codes) {
        codesKept.value = false
    }
})

const STEP_COPY = {
    on: { head: 'Turn on two-step verification', body: 'Enter your password to begin. Then you scan a code with your authenticator app and type back the code it shows.', submit: 'Continue', code: false, tone: 'primary' },
    off: { head: 'Turn off two-step verification', body: 'Enter your password and the code your app shows now. Sign-in will then ask for your password only.', submit: 'Turn off', code: true, tone: 'danger' },
    replace: { head: 'Replace the app', body: 'Enter your password and the code your current app shows now. Then you set up the new app; the old one stops working when the new one\'s code is typed here.', submit: 'Continue', code: true, tone: 'primary' },
    codes: { head: 'Make new recovery codes', body: 'Enter your password and the code your app shows now. Your old recovery codes stop working.', submit: 'Replace my codes', code: true, tone: 'primary' },
}

const stepCopy = computed(() => STEP_COPY[opened.value] ?? null)

/* --- where you are signed in ------------------------------------------- */

const others = computed(() => props.sessions.filter((s) => !s.current))
const signingOut = ref(null)

const signOut = (session) => {
    if (signingOut.value !== null) return
    signingOut.value = session.id
    router.post(path(`/sessions/${session.id}/sign-out`), {}, { preserveScroll: true, onFinish: () => { signingOut.value = null } })
}

const signOutOthers = () => {
    if (signingOut.value !== null) return
    signingOut.value = 'others'
    router.post(path('/sessions/sign-out-others'), {}, { preserveScroll: true, onFinish: () => { signingOut.value = null } })
}

const where = (session) => [session.device, session.address ?? 'address not known', session.seen].join(' · ')
</script>

<template>
    <Head title="My account" />

    <component :is="shell" v-bind="shellProps">
        <div class="ma-page">
            <!-- Your details: the page's own form, always open. -->
            <form class="ma-panel" @submit.prevent="saveDetails">
                <h2 class="ma-head">Your details</h2>

                <div class="ma-row">
                    <span class="ma-label">Photo</span>
                    <div class="ma-photo">
                        <ConsoleAvatar box="ma-avatar" :user="me" :root="root" />
                        <button type="button" class="ma-btn" @click="photoOpen = true">Upload a photo</button>
                        <button v-if="details.photo_version" type="button" class="ma-btn" :disabled="removingPhoto" @click="removePhoto">
                            Remove
                        </button>
                    </div>
                </div>

                <label class="ma-row" for="ma-name">
                    <span class="ma-label">Name</span>
                    <span class="ma-field">
                        <input id="ma-name" v-model="mine.name" type="text" autocomplete="name" maxlength="120" required />
                        <span v-if="mine.errors.name" class="ma-error" role="alert">{{ mine.errors.name }}</span>
                    </span>
                </label>

                <div class="ma-row">
                    <span class="ma-label">Email</span>
                    <span class="ma-value">{{ details.email }}</span>
                    <span class="ma-note">{{ details.email_note }}</span>
                </div>
                <p v-if="mine.errors.email" class="ma-error" role="alert">{{ mine.errors.email }}</p>

                <div class="ma-row">
                    <span class="ma-label">Role</span>
                    <span class="ma-value">{{ details.role }}</span>
                    <span class="ma-note">{{ details.role_note }}</span>
                </div>

                <label class="ma-row" for="ma-phone">
                    <span class="ma-label">Phone</span>
                    <span class="ma-field">
                        <input id="ma-phone" v-model="mine.phone" type="tel" autocomplete="tel" maxlength="40" :placeholder="phoneExample" required />
                        <span v-if="mine.errors.phone" class="ma-error" role="alert">{{ mine.errors.phone }}</span>
                    </span>
                </label>

                <div class="ma-actions">
                    <button type="submit" class="ma-btn ma-primary" :disabled="mine.processing">Save</button>
                </div>
            </form>

            <!-- Sign-in email (M34 §1): its two steps open in the console's modal. -->
            <section class="ma-panel">
                <h2 class="ma-head">Sign-in email</h2>
                <p class="ma-text">You sign in with <strong>{{ details.email }}</strong>.</p>

                <p v-if="signInEmail.locked" class="ma-note ma-locked">{{ signInEmail.locked }}</p>

                <div v-else-if="!signInEmail.pending" class="ma-actions">
                    <button type="button" class="ma-btn" @click="openEmail">Change my sign-in email</button>
                </div>

                <p v-else class="ma-note">A code went to <strong>{{ signInEmail.pending }}</strong>: enter it to finish.</p>
            </section>

            <!-- Password: changed in the console's modal. -->
            <section class="ma-panel">
                <h2 class="ma-head">Password</h2>
                <p class="ma-text">{{ password.changed }}</p>

                <div class="ma-actions">
                    <button type="button" class="ma-btn" @click="openPassword">Change my password</button>
                </div>
            </section>

            <!-- Two-step verification: each act in the console's modal. -->
            <section class="ma-panel">
                <h2 class="ma-head">Two-step verification</h2>

                <div class="ma-radios" role="radiogroup" aria-label="Two-step verification">
                    <label class="ma-radio" :class="{ 'is-disabled': tf.on && locked }">
                        <input type="radio" name="ma-two-factor" :checked="!tf.on" :disabled="Boolean(tf.on && locked)" @change="chooseOff" />
                        <span>Off</span>
                    </label>
                    <label class="ma-radio">
                        <input type="radio" name="ma-two-factor" :checked="tf.on" :disabled="Boolean(tf.setup)" @change="chooseOn" />
                        <span>On, with an authenticator app</span>
                        <span v-if="tf.on && tf.since" class="ma-note">{{ tf.since }}</span>
                    </label>
                </div>

                <!-- Never this role's to turn off (M96 level 3): no button, and the reason in its place. -->
                <p v-if="locked" class="ma-note ma-locked">{{ locked }}</p>

                <div v-if="tf.on && !tf.setup" class="ma-actions">
                    <button v-if="!locked" type="button" class="ma-btn" title="Turn two-step verification off" @click="openStep('off')">
                        Turn it off
                    </button>
                    <button type="button" class="ma-btn" @click="openStep('replace')">Replace the app</button>
                </div>

                <div v-if="tf.on && !tf.setup" class="ma-row">
                    <span class="ma-label">Recovery codes</span>
                    <span class="ma-value">{{ tf.codes_left }} of {{ tf.codes_total }} left</span>
                    <button type="button" class="ma-btn" @click="openStep('codes')">Make new codes</button>
                </div>
            </section>

            <!-- Where you are signed in -->
            <section class="ma-panel">
                <h2 class="ma-head">Where you are signed in</h2>

                <ul class="ma-sessions">
                    <li v-for="(session, i) in sessions" :key="session.id ?? `current-${i}`" class="ma-session">
                        <span class="ma-label">{{ session.current ? 'This device' : 'Another' }}</span>
                        <span class="ma-value">{{ where(session) }}</span>
                        <button
                            v-if="!session.current && session.id !== null"
                            type="button"
                            class="ma-btn"
                            :disabled="signingOut !== null"
                            @click="signOut(session)"
                        >
                            Sign it out
                        </button>
                    </li>
                </ul>

                <p class="ma-note">
                    {{ others.length === 0 ? 'You are not signed in anywhere else that we know of.' : '' }}
                    Signing out everywhere else also ends a session this list cannot see. This browser stays signed in.
                </p>

                <div class="ma-actions">
                    <button type="button" class="ma-btn" :disabled="signingOut !== null" @click="signOutOthers">Sign out everywhere else</button>
                </div>
            </section>
        </div>

        <!-- The sign-in email, step one: the new address and the password (M34 §1). -->
        <ConsoleDialog
            :open="opened === 'email'"
            title="Change your sign-in email"
            size="form"
            confirm-label="Email a code to the new address"
            busy-label="Sending…"
            :busy="moving.processing"
            :dirty="moving.isDirty"
            discard-title="Discard the new address?"
            @confirm="sendEmailCode"
            @cancel="close"
        >
            <p class="ma-text">
                Enter the new address and your current password. We email a code to the new address, and nothing changes
                until you type that code here, so the new address is proven to receive mail before you depend on it.
            </p>
            <div class="ma-fields">
                <label>
                    <span>New email</span>
                    <input v-model="moving.new_email" type="email" autocomplete="email" maxlength="190" required />
                    <span v-if="moving.errors.new_email" class="ma-error" role="alert">{{ moving.errors.new_email }}</span>
                </label>
                <label>
                    <span>Current password</span>
                    <input v-model="moving.current_password" type="password" autocomplete="current-password" required />
                    <span v-if="moving.errors.current_password" class="ma-error" role="alert">{{ moving.errors.current_password }}</span>
                </label>
            </div>
            <p v-if="moving.errors.code" class="ma-error" role="alert">{{ moving.errors.code }}</p>
        </ConsoleDialog>

        <!-- Step two: the code emailed to the new address. Open while one is waiting; closing it forgets the address. -->
        <ConsoleDialog
            :open="Boolean(!signInEmail.locked && signInEmail.pending)"
            title="Enter the code from the email"
            size="form"
            confirm-label="Change my sign-in email"
            busy-label="Changing…"
            :busy="proving.processing || cancellingEmail"
            :dirty="proving.isDirty"
            discard-title="Leave without changing your sign-in email?"
            @confirm="finishEmail"
            @cancel="cancelEmail"
        >
            <p class="ma-text">
                A code went to <strong>{{ signInEmail.pending }}</strong>. It works for {{ signInEmail.minutes }} minutes.
                Once you type it here you sign in with that address; your old address is emailed to say so, and you stay
                signed in everywhere.
            </p>
            <div class="ma-fields">
                <label>
                    <span>Code from the email</span>
                    <input v-model="proving.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required />
                </label>
            </div>
            <p v-if="proving.errors.code" class="ma-error" role="alert">{{ proving.errors.code }}</p>
            <p v-if="proving.errors.new_email" class="ma-error" role="alert">{{ proving.errors.new_email }}</p>
        </ConsoleDialog>

        <!-- The password: the old one and a code emailed to this account's own address. -->
        <ConsoleDialog
            :open="opened === 'password'"
            title="Change your password"
            size="form"
            confirm-label="Save the new password"
            busy-label="Changing…"
            :busy="change.processing"
            :dirty="change.isDirty"
            @confirm="changePassword"
            @cancel="close"
        >
            <p class="ma-text">
                You need your current password and a code we email to <strong>{{ password.code_to }}</strong>. When it is
                changed, every other place you are signed in is signed out. This one stays.
            </p>

            <div class="ma-actions">
                <button type="button" class="ma-btn" :disabled="sending.processing" @click="sendCode">Email me a code</button>
            </div>
            <p v-if="sending.errors.code" class="ma-error" role="alert">{{ sending.errors.code }}</p>

            <div class="ma-fields">
                <label>
                    <span>Current password</span>
                    <input v-model="change.current_password" type="password" autocomplete="current-password" required />
                    <span v-if="change.errors.current_password" class="ma-error" role="alert">{{ change.errors.current_password }}</span>
                </label>
                <label>
                    <span>Code from the email</span>
                    <input v-model="change.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required />
                    <span v-if="change.errors.code" class="ma-error" role="alert">{{ change.errors.code }}</span>
                </label>
                <label>
                    <span>New password</span>
                    <input v-model="change.password" type="password" autocomplete="new-password" minlength="10" required />
                    <span v-if="change.errors.password" class="ma-error" role="alert">{{ change.errors.password }}</span>
                </label>
                <label>
                    <span>New password again</span>
                    <input v-model="change.password_confirmation" type="password" autocomplete="new-password" minlength="10" required />
                </label>
            </div>
            <p class="ma-note">Ten characters at least, and not one of the passwords everybody guesses first.</p>
        </ConsoleDialog>

        <!-- A two-step act: the password (and a current code) before anything changes. -->
        <ConsoleDialog
            :open="stepCopy !== null"
            :title="stepCopy?.head ?? ''"
            size="form"
            :tone="stepCopy?.tone ?? 'primary'"
            :confirm-label="stepCopy?.submit ?? 'Continue'"
            busy-label="Checking…"
            :busy="step.processing"
            @confirm="sendStep"
            @cancel="close"
        >
            <template v-if="stepCopy">
                <p class="ma-text">{{ stepCopy.body }}</p>
                <div class="ma-fields">
                    <label>
                        <span>Your password</span>
                        <input v-model="step.current_password" type="password" autocomplete="current-password" required />
                        <span v-if="step.errors.current_password" class="ma-error" role="alert">{{ step.errors.current_password }}</span>
                    </label>
                    <label v-if="stepCopy.code">
                        <span>Code from your app</span>
                        <input v-model="step.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required />
                    </label>
                </div>
                <p v-if="step.errors.code" class="ma-error" role="alert">{{ step.errors.code }}</p>
            </template>
        </ConsoleDialog>

        <!-- Setting up (or replacing) the app: the key, then a code typed back. Not on until it is. -->
        <ConsoleDialog
            :open="Boolean(tf.setup)"
            :title="tf.setup?.mode === 'replace' ? 'Set up the new app' : 'Set up your authenticator app'"
            size="form"
            confirm-label="Finish"
            busy-label="Checking…"
            :busy="confirm.processing || cancelling"
            @confirm="finishSetup"
            @cancel="cancelSetup"
        >
            <template v-if="tf.setup">
                <p class="ma-text">
                    1. In an authenticator app on your phone, add an account and scan this code, or type the key below it.
                </p>
                <div class="ma-key">
                    <img :src="tf.setup.qr" alt="QR code for your authenticator app" width="168" height="168" />
                    <code class="ma-secret">{{ tf.setup.secret }}</code>
                </div>
                <p class="ma-text">
                    2. Type the six-digit code the app shows.
                    {{ tf.setup.mode === 'replace'
                        ? 'Your old app keeps working until this is done.'
                        : 'Two-step verification is not on until you do.' }}
                </p>
                <div class="ma-fields">
                    <label>
                        <span>Code from your app</span>
                        <input v-model="confirm.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required />
                    </label>
                </div>
                <p v-if="confirm.errors.code" class="ma-error" role="alert">{{ confirm.errors.code }}</p>
            </template>
        </ConsoleDialog>

        <!-- Shown once: on this view, and never again. -->
        <ConsoleDialog
            :open="newCodes !== null"
            title="Your recovery codes"
            size="form"
            confirm-label="I have kept them"
            cancel-label="Close"
            @confirm="codesKept = true"
            @cancel="codesKept = true"
        >
            <p class="ma-text">
                Each one works once, in place of your app's code, if you lose your phone. Write them down or keep them
                somewhere safe now: they are shown this once and never again, and nobody can read them back to you.
            </p>
            <ul class="ma-code-list">
                <li v-for="code in newCodes ?? []" :key="code"><code>{{ code }}</code></li>
            </ul>
        </ConsoleDialog>
    </component>

    <ProfilePhotoDialog :open="photoOpen" :user="me" :root="root" @close="photoOpen = false" />
</template>

<style scoped>
/*
 * AUTHORED. No board draws this page: it is kept to the consoles' own tokens, and carries its own buttons because
 * each board's button classes are scoped to that board's sheet. The modals are the shared ConsoleDialog; the fields
 * inside them wear this page's own classes, which reach the teleported dialog through the slot.
 */
.ma-page {
    display: flex;
    flex-direction: column;
    gap: 14px;
    max-width: 820px;
}

.ma-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
}

.ma-head {
    margin: 0;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--navy-900);
}

/*
 * The value and its note share the row evenly (seen on the dev server, 12 Oct): sized to its content, a long note
 * squeezed the value until a sign-in address broke in the middle ("president@ph / oenixpark.test").
 */
.ma-row {
    display: grid;
    grid-template-columns: 130px minmax(0, 1fr) minmax(0, 1fr);
    align-items: center;
    gap: 12px;
}

.ma-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.ma-value {
    font-size: 12.5px;
    color: var(--navy-900);
    overflow-wrap: anywhere;
}

.ma-note {
    margin: 0;
    font-size: 11px;
    line-height: 1.5;
    color: var(--slate-500);
}

.ma-locked {
    color: var(--navy-700);
}

.ma-text {
    margin: 0 0 10px;
    font-size: 11.5px;
    line-height: 1.6;
    color: var(--slate-600);
}

.ma-panel .ma-text {
    margin: 0;
}

.ma-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    grid-column: 2 / 4;
}

.ma-field input,
.ma-fields input {
    height: 34px;
    max-width: 340px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.ma-photo {
    display: flex;
    align-items: center;
    gap: 10px;
    grid-column: 2 / 4;
}

.ma-photo :deep(.ma-avatar) {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 14px;
    font-weight: 700;
}

.ma-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 260px));
    gap: 10px;
    margin: 10px 0;
}

.ma-fields label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.ma-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

.ma-btn {
    height: 34px;
    padding: 0 14px;
    border-radius: 10px;
    border: 1.5px solid var(--navy-200);
    background: var(--white);
    color: var(--navy-700);
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
}

.ma-btn.ma-primary {
    border-color: var(--navy-600);
    background: var(--navy-600);
    color: var(--white);
}

.ma-btn[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

.ma-radios {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ma-radio {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    color: var(--navy-900);
}

.ma-radio.is-disabled {
    color: var(--slate-500);
}

.ma-key {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin: 0 0 10px;
}

.ma-secret,
.ma-code-list code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
    color: var(--navy-900);
    letter-spacing: 0.04em;
}

.ma-code-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(4, max-content);
    gap: 8px 22px;
}

.ma-sessions {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ma-session {
    display: grid;
    grid-template-columns: 130px minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
}

/* A field's refusal is amber, a refusal and not an emergency (Hassam's three weights, 12 Oct). */
.ma-error {
    font-size: 11.5px;
    font-weight: 600;
    margin: 0;
    color: var(--amber-700);
}

@media (max-width: 720px) {
    .ma-row,
    .ma-session {
        grid-template-columns: minmax(0, 1fr);
        gap: 4px;
    }

    .ma-field,
    .ma-photo {
        grid-column: auto;
    }

    .ma-fields {
        grid-template-columns: minmax(0, 1fr);
    }

    .ma-code-list {
        grid-template-columns: repeat(2, max-content);
    }
}
</style>
