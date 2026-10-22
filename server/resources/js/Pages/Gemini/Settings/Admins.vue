<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import { toast } from '../../../lib/toasts.js'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SettingsTabs from './SettingsTabs.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { useLiveKind } from '../../../composables/useLiveKind'

// The platform's administrators, edited here: a settings editor, never re-read under somebody's changes (M18H §7.2).
useLiveKind('form')

/**
 * Platform admins — board 42's tab (12 §2, item 43).
 *
 * NO BOARD DRAWS THIS TAB'S BODY; boards 42 to 45 draw the tab. Built under
 * the same strip so the module reads as one.
 *
 * ACCOUNTS ARE ISSUED BY INVITATION, and nowhere else: a fourteen-day link that
 * names who sent it and which role it carries. This screen sends and withdraws
 * those, and moves an existing person between the console's roles, sets the
 * sites a site-scoped role covers, or suspends them.
 *
 * WHAT A ROLE CAN DO IS NOT CHANGED HERE. That is the role matrix's (M10 §4):
 * the Director edits it there, and this screen moves people between whatever
 * roles it holds — the six seeded ones and any the Director has made, listed
 * apart so a custom role is never mistaken for a seeded one of the same kind.
 *
 * ADDING AN ADMINISTRATOR is an invitation, or moving somebody into a role that
 * can change roles; REMOVING ONE (M10 §4) suspends the account and takes its
 * role and sites away. Removed, never deleted: the audit log names this person
 * against everything they did, so the row stays and the screen says so before
 * the press. Removing is `approve` — the power to change roles — where changing
 * somebody is `update`, so the two carry their own flags.
 *
 * EVERY REFUSAL IS SAID IN WORDS ON THE ROW, not only on hover. Nobody changes or
 * removes their own account, the last active Director cannot be demoted,
 * suspended or removed, and an administrator is changed only by somebody who
 * holds the power they hold. `PlatformAdmins::board()` puts the sentence on the
 * person; the same rule refuses a hand-built post.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    people: { type: Array, required: true },
    invitations: { type: Array, required: true },
    roles: { type: Array, required: true },
    clients: { type: Array, required: true },
    canInvite: { type: Boolean, required: true },
    canManage: { type: Boolean, required: true },
    canRemove: { type: Boolean, required: true },
    writeDisabledReason: { type: String, required: true },
    removeDisabledReason: { type: String, required: true },
})

/* ---- the roles, seeded first and the Director's own apart ---- */
const seededRoles = computed(() => props.roles.filter((role) => role.is_system))
const customRoles = computed(() => props.roles.filter((role) => !role.is_system))

const page = usePage()

/*
 * WHETHER EACH INVITATION'S EMAIL WENT stays in its own row, from what the server recorded (DESIGN §11 row 2): a
 * standing fact. What the last Invite or Resend did is what just happened, so a toast top right with a cross (Hassam,
 * 12 October: three kinds); a Resend the server refused is said inside its question, which stays open, and a refused
 * invitation inside the invite form. Resend waits out the server's minute, counting down, and cannot be pressed twice.
 * It emails somebody, so it asks first, naming the address and the role, and only the question's own button sends
 * (DR-077, the register's Part 7).
 */
const outcome = computed(() => page.props.flash?.invitation_outcome ?? null)

const sayOutcome = (said) => {
    if (said !== null) {
        toast(said.message, { tone: said.state === 'sent' ? 'success' : 'error' })
    }
}

/* ---- inviting ---- */
const inviting = ref(false)
const inviteForm = useForm({ email: '', role: props.roles[0]?.name ?? '' })
const invite = () =>
    inviteForm.post('/settings/admins/invitations', {
        preserveScroll: true,
        onSuccess: (visit) => {
            inviting.value = false
            sayOutcome(visit?.props?.flash?.invitation_outcome ?? null)
        },
    })

// Pressed with no dialog open, so a refusal is what just happened: a toast that stays until closed.
const revoke = (invitation) =>
    router.post(`/settings/admins/invitations/${invitation.id}/revoke`, {}, {
        preserveScroll: true,
        onError: (errors) => toast(errors.admins ?? Object.values(errors)[0] ?? 'The invitation was not withdrawn.', { tone: 'error' }),
    })

const clock = ref(Date.now())
let ticking = null

onMounted(() => {
    ticking = setInterval(() => {
        clock.value = Date.now()
    }, 1000)

    // Arriving with an answer, with no question open: a toast.
    sayOutcome(outcome.value)
})
onBeforeUnmount(() => clearInterval(ticking))

/** Seconds until Resend may be pressed again, from the server's `resend_after`; 0 means now. */
const waitFor = (invitation) => {
    const after = invitation.resend_after ? Date.parse(invitation.resend_after) : Number.NaN

    return Number.isFinite(after) ? Math.max(0, Math.ceil((after - clock.value) / 1000)) : 0
}

const mmss = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`

const resendingId = ref(null)

/*
 * Why Resend is waiting, in words that match the row: "just sent" only when the last try went (the server's
 * `last_try`), never under a row that reads "Could not send".
 */
const waitSentence = (invitation, wait) => {
    if (invitation.last_try === 'sent') {
        return `It was just sent. It can go again in ${mmss(wait)}.`
    }

    if (invitation.last_try === 'not_sent') {
        return `It was tried moments ago and did not go. It can be tried again in ${mmss(wait)}.`
    }

    return `It was tried moments ago. It can be tried again in ${mmss(wait)}.`
}

const resendBlockedBy = (invitation) => {
    if (!props.canInvite) {
        return props.writeDisabledReason
    }

    const wait = waitFor(invitation)

    return wait > 0 ? waitSentence(invitation, wait) : null
}

const resendLabel = (invitation) => {
    if (resendingId.value === invitation.id) {
        return 'Sending…'
    }

    const wait = waitFor(invitation)

    return wait > 0 ? `Resend in ${mmss(wait)}` : 'Resend'
}

/** The invitation whose Resend is being asked about, or null. */
const resendAsk = ref(null)
const resendRefusal = ref(null)

const resend = (invitation) => {
    if (resendBlockedBy(invitation) !== null || resendingId.value !== null) {
        return
    }

    resendRefusal.value = null
    resendAsk.value = invitation
}

const keepResend = () => {
    resendAsk.value = null
    resendRefusal.value = null
}

// The server refusing to send it is said in the question's amber band, which stays open; whether it went is a toast.
const postResend = () => {
    const invitation = resendAsk.value

    if (invitation === null || resendBlockedBy(invitation) !== null || resendingId.value !== null) {
        return
    }

    resendingId.value = invitation.id
    resendRefusal.value = null
    router.post(`/settings/admins/invitations/${invitation.id}/resend`, {}, {
        preserveScroll: true,
        onSuccess: (visit) => {
            resendAsk.value = null
            sayOutcome(visit?.props?.flash?.invitation_outcome ?? null)
        },
        onError: (errors) => {
            resendRefusal.value = errors.admins ?? Object.values(errors)[0] ?? 'The invitation was not sent again.'
        },
        onFinish: () => {
            resendingId.value = null
        },
    })
}

/* ---- managing one person ---- */
const editing = ref(null)
const manageForm = useForm({ role: '', status: 'active', sites: [] })

const roleIsScoped = (name) => props.roles.find((role) => role.name === name)?.scoped ?? false

const edit = (person) => {
    if (editing.value === person.id) {
        editing.value = null

        return
    }

    removing.value = null
    editing.value = person.id
    manageForm.clearErrors()
    manageForm.role = person.role ?? props.roles[0]?.name ?? ''
    manageForm.status = person.status === 'suspended' ? 'suspended' : 'active'
    manageForm.sites = [...person.sites]
}

const save = (person) =>
    manageForm.post(`/settings/admins/${person.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) })

/* ---- taking somebody off the console (M10 §4) ---- */
const removing = ref(null)
const removeForm = useForm({})

/** Suspended and holding no role: what a removal leaves, and nothing more to remove. */
const isRemoved = (person) => person.status === 'suspended' && person.role === null

const removeReason = (person) => {
    if (!props.canRemove) {
        return props.removeDisabledReason
    }

    if (person.reason !== null) {
        return person.reason
    }

    return isRemoved(person)
        ? 'Already off the console: suspended and holding no role. Choose a role for them under Change to bring them back.'
        : null
}

const askRemove = (person) => {
    if (removeReason(person) !== null) {
        return
    }

    editing.value = null
    removeForm.clearErrors()
    removing.value = removing.value === person.id ? null : person.id
}

const remove = (person) =>
    removeForm.post(`/settings/admins/${person.id}/remove`, { preserveScroll: true, onSuccess: () => (removing.value = null) })
</script>

<template>
    <Head title="Platform admins" />

    <GeminiConsole title="Platform settings">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canInvite"
                :title="canInvite ? 'Invite somebody to a Gemini Console role. The link lasts fourteen days.' : writeDisabledReason"
                @click="inviting = !inviting"
            >
                <span>Invite</span>
            </button>
        </template>

        <SettingsTabs :tabs="tabs" />

        <form v-if="inviting" class="ad-panel" @submit.prevent="invite">
            <div class="ad-head">
                The person chooses their own password on a link only they receive. It names you as the inviter and the
                role it carries, and lapses after fourteen days.
            </div>
            <div class="ad-fields">
                <label>
                    <span>Email</span>
                    <input v-model="inviteForm.email" type="email" maxlength="190" required />
                </label>
                <label>
                    <span>Role</span>
                    <select v-model="inviteForm.role">
                        <optgroup label="The platform's own roles">
                            <option v-for="role in seededRoles" :key="role.name" :value="role.name">{{ role.label }}</option>
                        </optgroup>
                        <optgroup v-if="customRoles.length" label="Roles made on the matrix">
                            <option v-for="role in customRoles" :key="role.name" :value="role.name">{{ role.label }}</option>
                        </optgroup>
                    </select>
                </label>
            </div>
            <p v-if="roleIsScoped(inviteForm.role)" class="ad-note">
                This role is scoped to assigned sites. Once the invitation is accepted, set the sites from their row below —
                until then they see nothing.
            </p>
            <div v-for="(message, key) in inviteForm.errors" :key="key" class="ad-error">{{ message }}</div>
            <div class="ad-actions">
                <button type="submit" class="btn-primary-sm" :disabled="inviteForm.processing"><span>Send invitation</span></button>
                <button type="button" class="text-link-sm" @click="inviting = false">Cancel</button>
            </div>
        </form>

        <div class="ad-panel">
            <div class="ad-section">People with a console account</div>

            <EmptyState
                v-if="people.length === 0"
                variant="first-use"
                title="Nobody holds a platform account"
                body="Accounts are issued by invitation and never self-created."
            />

            <div v-for="person in people" :key="person.id" class="ad-row-wrap">
                <div class="ad-row">
                    <div>
                        <div class="ad-name">
                            {{ person.name }}
                            <span v-if="person.status === 'suspended'" class="ad-pill off">Suspended</span>
                        </div>
                        <div class="ad-sub">{{ person.email }} · {{ person.role_label }} · {{ person.site_names }}</div>
                    </div>
                    <div class="ad-actions">
                        <button
                            type="button"
                            class="text-link-sm"
                            :disabled="!canManage || person.reason !== null"
                            :title="!canManage ? writeDisabledReason : person.reason ?? 'Change this person\'s role, sites or standing.'"
                            @click="edit(person)"
                        >
                            {{ editing === person.id ? 'Close' : 'Change' }}
                        </button>
                        <button
                            type="button"
                            class="text-link-sm ad-danger"
                            :disabled="removeReason(person) !== null"
                            :title="removeReason(person) ?? 'Take this person off the Gemini Console. The account is suspended, not deleted.'"
                            @click="askRemove(person)"
                        >
                            {{ removing === person.id ? 'Keep' : 'Remove' }}
                        </button>
                    </div>
                </div>

                <!-- The refusal in words, not only on hover: the last Director, your own account, or an administrator you cannot change. -->
                <p v-if="person.reason" class="ad-note ad-reason">{{ person.reason }}</p>

                <div v-if="removing === person.id" class="ad-edit">
                    <p class="ad-note">
                        Take {{ person.name }} off the Gemini Console? The account is suspended and loses its role and every
                        site at once. It is not deleted: the audit log keeps naming them against what they did, and choosing
                        a role for them under Change later brings them back.
                    </p>
                    <!-- A refusal of the removal, inside what is open (Hassam, 12 October). -->
                    <div v-if="removeForm.errors.admins" class="ad-error" role="alert">{{ removeForm.errors.admins }}</div>
                    <div class="ad-actions">
                        <button type="button" class="btn-red-sm" :disabled="removeForm.processing" @click="remove(person)">
                            <span>{{ removeForm.processing ? 'Removing…' : 'Remove from the console' }}</span>
                        </button>
                        <button type="button" class="text-link-sm" @click="removing = null">Cancel</button>
                    </div>
                </div>

                <form v-if="editing === person.id" class="ad-edit" @submit.prevent="save(person)">
                    <div class="ad-fields">
                        <label>
                            <span>Role</span>
                            <select v-model="manageForm.role">
                                <optgroup label="The platform's own roles">
                                    <option v-for="role in seededRoles" :key="role.name" :value="role.name">{{ role.label }}</option>
                                </optgroup>
                                <optgroup v-if="customRoles.length" label="Roles made on the matrix">
                                    <option v-for="role in customRoles" :key="role.name" :value="role.name">{{ role.label }}</option>
                                </optgroup>
                            </select>
                        </label>
                        <label>
                            <span>Standing</span>
                            <select v-model="manageForm.status">
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </label>
                    </div>
                    <fieldset v-if="roleIsScoped(manageForm.role)" class="ad-sites">
                        <legend>Sites this role covers</legend>
                        <label v-for="client in clients" :key="client.id" class="ad-site">
                            <input v-model="manageForm.sites" type="checkbox" :value="client.id" />
                            {{ client.name }}
                        </label>
                    </fieldset>
                    <!-- A refusal of the change, inside what is open (Hassam, 12 October). -->
                    <div v-for="(message, key) in manageForm.errors" :key="key" class="ad-error" role="alert">{{ message }}</div>
                    <div class="ad-actions">
                        <button type="submit" class="btn-primary-sm" :disabled="manageForm.processing"><span>Save</span></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="ad-panel">
            <div class="ad-section">Invitations not yet accepted</div>
            <p v-if="invitations.length === 0" class="ad-note">No invitation is open.</p>
            <div v-for="invitation in invitations" :key="invitation.id" class="ad-row-wrap">
                <div class="ad-row">
                    <div>
                        <div class="ad-name">
                            {{ invitation.email }}
                            <span v-if="invitation.expired" class="ad-pill off">Lapsed</span>
                        </div>
                        <div class="ad-sub">
                            {{ invitation.role }} · invited by {{ invitation.invited_by }} · {{ invitation.expired ? 'lapsed' : 'open until' }}
                            {{ invitation.expires }}
                        </div>
                        <div class="ad-sub ad-delivery" :class="{ 'ad-not-sent': invitation.delivery_state !== 'sent' }">{{ invitation.delivery }}</div>
                    </div>
                    <div class="ad-actions">
                        <button
                            type="button"
                            class="text-link-sm"
                            :disabled="resendBlockedBy(invitation) !== null || resendingId !== null"
                            :aria-busy="resendingId === invitation.id"
                            :title="resendBlockedBy(invitation) ?? 'Send a fresh link. The old one stops working only if this one goes.'"
                            @click="resend(invitation)"
                        >
                            {{ resendLabel(invitation) }}
                        </button>
                        <button type="button" class="text-link-sm" :disabled="!canInvite" :title="canInvite ? 'Withdraw the invitation.' : writeDisabledReason" @click="revoke(invitation)">
                            Withdraw
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <ConsoleDialog
            :open="resendAsk !== null"
            :title="resendAsk ? `Resend the invitation to ${resendAsk.email}?` : ''"
            confirm-label="Resend invitation"
            busy-label="Sending…"
            :busy="resendingId !== null"
            :error="resendRefusal"
            @confirm="postResend"
            @cancel="keepResend"
        >
            <p v-if="resendAsk" style="margin: 0">
                An email to {{ resendAsk.email }}, with a fresh link to join the Gemini Console as {{ resendAsk.role }}. Once it goes, the earlier link stops working. Nobody else is told.
            </p>
        </ConsoleDialog>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this tab's body. Kept to the tokens the Gemini
 * boards define; the buttons are the sheet's own classes.
 */
button {
    font-family: inherit;
    cursor: pointer;
}

button.btn-primary-sm,
button.btn-red-sm {
    border: 0;
}

button.text-link-sm.ad-danger {
    color: var(--red-700);
}

button.text-link-sm[disabled] {
    color: var(--slate-500);
}

.ad-reason {
    padding: 0 0 8px;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
}

button[disabled] {
    cursor: not-allowed;
}

.ad-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.ad-head,
.ad-section {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.ad-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 320px));
    gap: 10px;
}

.ad-fields label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.ad-fields input,
.ad-fields select {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.ad-row-wrap + .ad-row-wrap {
    border-top: 1px solid var(--navy-100);
}

.ad-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 8px 0;
}

.ad-name {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.ad-sub,
.ad-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.55;
    margin: 0;
}

.ad-pill {
    font-size: 10px;
    font-weight: 700;
    border-radius: 20px;
    padding: 2px 8px;
    margin-left: 6px;
}

.ad-pill.off {
    background: var(--red-100);
    color: var(--red-700);
}

.ad-edit {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 4px 0 12px;
}

.ad-sites {
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    padding: 8px 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    font-size: 12px;
    color: var(--navy-900);
}

.ad-sites legend {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.ad-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

/* A refusal, said inside the form or row it refused: amber, as every ordinary refusal is (red is an open panic's alone). */
.ad-error {
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
    background: var(--amber-100);
    color: var(--amber-700);
}

/* Whether the invitation's email went — the server's words; amber until the mail server has taken it. */
.ad-delivery {
    font-weight: 600;
    overflow-wrap: anywhere;
}

.ad-delivery.ad-not-sent {
    color: var(--amber-700);
}
</style>
