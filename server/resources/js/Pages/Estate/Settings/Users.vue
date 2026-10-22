<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'
import { submitDialog } from '../../../lib/dialogSubmit.js'
import { toast } from '../../../lib/toasts.js'

/**
 * Users & roles — board screen community-admin-22.
 *
 * WHO MAY REACH THIS ESTATE, AND WHAT EACH OF THEM MAY REACH. Every column
 * except the mailbox is DERIVED and none of it is stored twice: the initials are
 * cut from the name, the module list is the permission matrix read back, the
 * "All modules" sentinel is what that list says when a role holds every module,
 * and the amber Owner badge belongs to whoever holds the seniormost estate role
 * assigned here. Nothing on this page computes any of it.
 *
 * THE LIST IS THE COMMITTEE AND NOT EVERYBODY WITH A KEY. Gemini's own Head of
 * Security holds an active assignment to this estate, and `usersBoard()` filters
 * them out by console: their access is Gemini's to grant, not this community's.
 *
 * EVERY ACT ON A PERSON ASKS FIRST, IN A DIALOG, AND SAYS EXACTLY WHAT WILL CHANGE (the Settings pass, Hassam's closing
 * order, 12 Oct). Inviting, changing somebody's role or access, taking them off the committee, bringing them back,
 * making them an administrator and withdrawing an invitation were each a panel opened in the table or a form on the
 * page, and "Make administrator", which hands somebody the power to change what every role here may do, went at the
 * first press with no question at all. Now each opens the console's one dialog (M95), its read-back names the person,
 * the role they hold and the role they will hold, and only the dialog's own button acts. A refusal is the server's
 * sentence inside the dialog, which stays open with what was chosen (`submitDialog`).
 *
 * WHAT THIS PAGE DOES NOT DECIDE. Which roles exist, what each may reach and who may hand one out are the matrix's and
 * `RoleEditor`'s; this page shows them and posts a person's role, never a permission. Every refusal is the server's
 * sentence, sent per row before the press (`manage_refusal`, `remove_refusal`, `reinstate_refusal`,
 * `hand_out_refusal`) and printed under its row (M96 level 2). A viewer who may change nobody has no Manage, Remove,
 * Reinstate, Invite or administrator controls at all (level 3), and one line saying what they may do.
 *
 * THE STATUS WORDS ARE THE LIST'S (M99): an account is Active or Inactive; an invitation is open until its date or
 * Expired. A failure to email is amber, never red (Hassam's red rule).
 *
 * A REMOVAL IS NOT FOR EVER. Whoever was taken off is listed under "Taken off the committee" with Reinstate beside
 * them, which gives back the role they held.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The settings column, with this screen marked current. */
    sections: { type: Array, required: true },
    /** One row per active estate assignment, seniormost role first. */
    rows: { type: Array, required: true },
    /** The committee's former members, each with the role they held and why Reinstate is refused, if it is. */
    removed: { type: Array, default: () => [] },
    /** The role the Owner badge landed on, or null on an estate with nobody. */
    owner_role: { type: String, default: null },
    /** This console's roles, each with the modules it reaches. */
    roles: { type: Array, required: true },
    /** Invitations to this estate not yet accepted, newest first. Never the token. */
    invitations: { type: Array, required: true },
    canInvite: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    canManage: { type: Boolean, required: true },
    manageBlockedReason: { type: String, required: true },
    /** Why the seniormost officer's row cannot be managed from here. */
    ownerReason: { type: String, required: true },
    /** Whether this viewer holds the power to change roles, and so may make and unmake administrators. */
    canAdminister: { type: Boolean, default: false },
    /** The seeded administrator's role — what "Make administrator" gives. */
    administratorRole: { type: String, default: 'estate.community_super_admin' },
    administratorNote: { type: String, default: '' },
})

const page = usePage()

/**
 * What the Modules cell says: the server's "All modules" when a role holds every one, up to three named, more
 * counted (the full list pushed the Status column off the table's edge). The names are on the cell's title.
 */
const moduleSummary = (row) => {
    const list = row.module_list ?? []

    if (list.length === 0 || !String(row.modules ?? '').includes(',')) {
        return row.modules
    }

    return list.length <= 3 ? list.join(', ') : `${list.length} modules`
}

// Cut on `/settings/` WITH its slash: an estate whose key begins "settings" would otherwise be cut inside its name.
const settingsRoot = computed(() => page.url.split('/settings/')[0])

/** Whoever may change nothing on this page reads it: no control is drawn for them (M96 level 3). */
const readsOnly = computed(() => !props.canInvite && !props.canManage)

/* ------------------------------------------------------------------ */
/* inviting                                                            */
/* ------------------------------------------------------------------ */

/* Open when arrived at from board 24's "Invite user" (`?invite=1`). */
const inviting = ref(new URLSearchParams(page.url.split('?')[1] ?? '').get('invite') === '1' && props.canInvite)

/*
 * The role an invitation starts on: the first this viewer may hand out that is NOT an administrator's (the Settings
 * pass, 12 Oct). It started on the first in the list, the Community Super Admin, so one press of Send handed a new
 * address the power to change every role. An administrator's role is still there to choose, said as one.
 */
const firstRole = () =>
    (props.roles.find((role) => !role.hand_out_refusal && !role.is_administrator) ??
        props.roles.find((role) => !role.hand_out_refusal) ??
        props.roles[0])?.name ?? ''

const inviteForm = useForm({
    email: '',
    role: firstRole(),
})

/** What the chosen role reaches, shown before the invitation goes out. */
const chosenRole = computed(() => props.roles.find((role) => role.name === inviteForm.role) ?? null)

const openInvite = () => {
    if (!props.canInvite) {
        return
    }

    inviteForm.reset()
    inviteForm.clearErrors()
    inviting.value = true
}

const closeInvite = () => {
    inviting.value = false
    inviteForm.reset()
    inviteForm.clearErrors()
}

const inviteBlockedBy = computed(() => (inviteForm.email.trim() === '' ? 'Enter the address to invite.' : null))

const submitInvite = () => {
    if (!props.canInvite || inviteBlockedBy.value !== null) {
        return
    }

    submitDialog(inviteForm, 'post', `${settingsRoot.value}/settings/users/invitations`, {
        close: closeInvite,
        onSuccess: (visit) => sayOutcome(visit?.props?.flash?.invitation_outcome ?? null),
    })
}

/*
 * WHETHER EACH INVITATION'S EMAIL WENT stays in its own row, from what the server recorded (DESIGN §11 row 1): emailed
 * and when, not sent and why, a standing fact. What the last Invite or Resend did is what just happened, so a toast top
 * right with a cross, in the three kinds the Gemini console says it in (Hassam, 12 October, ruling 4): an email that
 * went is a success; one that did not go, or that has no mail server to go through, says "Not done" and stays until it
 * is closed. A Resend the server refused is said inside its question, which stays open, and a refused invitation inside
 * the invite dialog. An answer that arrives with the page is said once, on arrival.
 */
const outcome = computed(() => page.props.flash?.invitation_outcome ?? null)

const sayOutcome = (said) => {
    if (said !== null) {
        toast(said.message, { tone: said.state === 'sent' ? 'success' : 'error' })
    }
}

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

const resendLabel = (invitation) => {
    if (resendingId.value === invitation.id) {
        return 'Sending…'
    }

    const wait = waitFor(invitation)

    return wait > 0 ? `Resend in ${mmss(wait)}` : 'Resend'
}

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
    const wait = waitFor(invitation)

    return wait > 0 ? waitSentence(invitation, wait) : null
}

/** The invitation whose Resend is being asked about, or null. */
const resendAsk = ref(null)
const resendRefusal = ref(null)

/* Resend emails somebody, so it asks first, naming the address and the role (DR-071); only the dialog's button sends. */
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
    router.post(`${settingsRoot.value}/settings/users/invitations/${invitation.id}/resend`, {}, {
        preserveScroll: true,
        onSuccess: (visit) => {
            resendAsk.value = null
            sayOutcome(visit?.props?.flash?.invitation_outcome ?? null)
        },
        onError: (errors) => {
            resendRefusal.value = errors.invitation ?? Object.values(errors)[0] ?? 'The invitation was not sent again.'
        },
        onFinish: () => {
            resendingId.value = null
        },
    })
}

/** The row's own delivery line: amber until the mail server has taken it. */
const deliveryStyle = (invitation) =>
    invitation.delivery_state === 'sent' ? 'color:var(--slate-600);' : 'color:var(--amber-700);'

/*
 * Withdrawing asks first, naming the address: the link they hold stops working. Its band says only its own refusal:
 * the page's `errors.invitation` is also a Resend's, which belongs in that question.
 */
const withdrawAsk = ref(null)
const withdrawForm = useForm({})

const openWithdraw = (invitation) => {
    withdrawForm.clearErrors()
    withdrawAsk.value = invitation
}

const postWithdraw = () => {
    const invitation = withdrawAsk.value

    if (invitation === null) {
        return
    }

    submitDialog(withdrawForm, 'post', `${settingsRoot.value}/settings/users/invitations/${invitation.id}/revoke`, {
        close: () => {
            withdrawAsk.value = null
        },
    })
}

/* A refusal about an invitation with no question open to say it in: what just happened, so the toast, amber. */
watch(
    () => page.props.errors?.invitation,
    (text) => {
        if (text && resendAsk.value === null && withdrawAsk.value === null) {
            toast(text, { tone: 'warning' })
        }
    },
    { immediate: true },
)

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

// Somebody given or taken off the console in another session moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['setting'] })

const retry = () => router.reload()

/* ------------------------------------------------------------------ */
/* what a row may have done to it                                      */
/* ------------------------------------------------------------------ */

/**
 * WHY A ROW CANNOT BE MANAGED, or null when it can — the server's sentence for this row (the last Community Super
 * Admin, the seniormost officer, an administrator managed by somebody who is not one, yourself). `ownerReason` is only
 * the fallback for a payload that predates those sentences. Asked only of a viewer who may manage people.
 */
const manageBlockedBy = (row) => {
    if (row.manage_refusal !== undefined) {
        return row.manage_refusal
    }

    return row.manageable ? null : props.ownerReason
}

/** Why a row cannot be removed, or null — the same shape, with "not yourself" first. */
const removeBlockedBy = (row) => row.remove_refusal ?? null

/** The refusals a row prints under itself, once each: visible text, not only a tooltip (M96 level 2). */
const rowRefusals = (row) => {
    if (!props.canManage) {
        return []
    }

    return [manageBlockedBy(row), removeBlockedBy(row)].filter((reason, index, all) => reason && all.indexOf(reason) === index)
}

/** A role this viewer may not hand out, and why — the server's sentence. */
const handOutRefusal = (roleName) => props.roles.find((role) => role.name === roleName)?.hand_out_refusal ?? null

/** How a role reads in a choice: its name, and what kind of role it is. */
const roleOptionLabel = (role) =>
    role.label + (role.is_administrator ? ' · administrator' : '') + (role.is_own ? ' (this community’s own)' : '')

const roleLabelOf = (name) => props.roles.find((option) => option.name === name)?.label ?? name

/* ------------------------------------------------------------------ */
/* changing somebody's role or access                                  */
/* ------------------------------------------------------------------ */

const managing = ref(null)
const manageForm = useForm({ role: '', status: 'active' })

const openManage = (row) => {
    if (!props.canManage || manageBlockedBy(row) !== null) {
        return
    }

    manageForm.clearErrors()
    manageForm.role = row.role
    manageForm.status = row.status === 'suspended' ? 'suspended' : 'active'
    managing.value = row
}

const closeManage = () => {
    managing.value = null
    manageForm.clearErrors()
}

/** Whether the dialog would change anything at all. */
const manageChanges = computed(() => {
    const row = managing.value

    if (row === null) {
        return false
    }

    return manageForm.role !== row.role || manageForm.status !== (row.status === 'suspended' ? 'suspended' : 'active')
})

/**
 * EXACTLY WHAT THE PRESS CHANGES, said before it (the closing order: a permission made easy to change by accident is
 * confirmed, naming what will change). The person, the role they hold and the one they will hold, their access, and
 * in so many words whether they start or stop being able to change what every role may do.
 */
const manageReadBack = computed(() => {
    const row = managing.value

    if (row === null) {
        return ''
    }

    const role = props.roles.find((option) => option.name === manageForm.role)
    const now = row.role_label
    const next = role?.label ?? manageForm.role
    const wasActive = row.status !== 'suspended'
    const willBeActive = manageForm.status !== 'suspended'

    const roleLine = manageForm.role === row.role ? `${row.name} stays ${now}` : `${row.name} goes from ${now} to ${next}`
    const accessLine =
        wasActive === willBeActive
            ? willBeActive ? 'and can still sign in' : 'and still cannot sign in'
            : willBeActive ? 'and can sign in again' : 'and can no longer sign in; the record of what they held stays'

    const standing =
        row.is_administrator && role && !role.is_administrator
            ? ' They stop being an administrator.'
            : !row.is_administrator && role?.is_administrator
              ? ' They become an administrator: they can change what every role here may do.'
              : ''

    return `${roleLine}, ${accessLine}.${standing}`
})

const submitManage = () => {
    const row = managing.value

    if (row === null || !manageChanges.value) {
        return
    }

    submitDialog(manageForm, 'post', `${settingsRoot.value}/settings/users/${row.assignment_id}`, { close: closeManage })
}

/* ------------------------------------------------------------------ */
/* taking somebody off the committee, and bringing them back           */
/* ------------------------------------------------------------------ */

const removing = ref(null)
const removeForm = useForm({})

const openRemove = (row) => {
    if (!props.canManage || removeBlockedBy(row) !== null) {
        return
    }

    removeForm.clearErrors()
    removing.value = row
}

const submitRemove = () => {
    const row = removing.value

    if (row === null) {
        return
    }

    submitDialog(removeForm, 'post', `${settingsRoot.value}/settings/users/${row.assignment_id}/remove`, {
        close: () => {
            removing.value = null
        },
    })
}

const reinstating = ref(null)
const reinstateForm = useForm({})

/** Why this former member cannot be reinstated by this viewer — the server's sentence — or null. */
const reinstateBlockedBy = (person) => person.reinstate_refusal ?? null

const openReinstate = (person) => {
    if (!props.canManage || reinstateBlockedBy(person) !== null) {
        return
    }

    reinstateForm.clearErrors()
    reinstating.value = person
}

const submitReinstate = () => {
    const person = reinstating.value

    if (person === null) {
        return
    }

    submitDialog(reinstateForm, 'post', `${settingsRoot.value}/settings/users/${person.assignment_id}/reinstate`, {
        close: () => {
            reinstating.value = null
        },
    })
}

/* ------------------------------------------------------------------ */
/* administrators                                                      */
/* ------------------------------------------------------------------ */

const administrators = computed(() => props.rows.filter((row) => row.is_administrator))

/** Who could be made an administrator by this viewer: not one already, and manageable by them. */
const adminCandidates = computed(() =>
    props.rows.filter((row) => !row.is_administrator && props.canManage && manageBlockedBy(row) === null),
)

const adminForm = useForm({ role: '', status: 'active' })
const adminPick = ref('')
const makingAdmin = ref(false)

const adminRoleLabel = computed(() => roleLabelOf(props.administratorRole) || 'Community Super Admin')

/** Why this viewer cannot make anybody an administrator, in the server's words, or because nobody is left to make one. */
const adminBlockedBy = computed(() => {
    const refusal = handOutRefusal(props.administratorRole)

    if (refusal) {
        return refusal
    }

    return adminCandidates.value.length === 0 ? 'Everybody you could make an administrator already is one, or is not yours to change.' : null
})

const adminChosen = computed(() => props.rows.find((candidate) => String(candidate.id) === String(adminPick.value)) ?? null)

const openMakeAdmin = () => {
    if (!props.canManage || adminBlockedBy.value !== null) {
        return
    }

    adminPick.value = ''
    adminForm.clearErrors()
    makingAdmin.value = true
}

const closeMakeAdmin = () => {
    makingAdmin.value = false
    adminPick.value = ''
    adminForm.clearErrors()
}

/** What "Make administrator" changes, named before the press. */
const adminReadBack = computed(() =>
    adminChosen.value === null
        ? null
        : `${adminChosen.value.name} goes from ${adminChosen.value.role_label} to ${adminRoleLabel.value}. They will be able to change what every role here may do, and to make or stop other administrators.`,
)

const makeAdministrator = () => {
    const row = adminChosen.value

    if (row === null || adminBlockedBy.value !== null) {
        return
    }

    adminForm.role = props.administratorRole
    adminForm.status = row.status === 'suspended' ? 'suspended' : 'active'

    submitDialog(adminForm, 'post', `${settingsRoot.value}/settings/users/${row.assignment_id}`, { close: closeMakeAdmin })
}

/** "Stop being an administrator" is giving them another role: the change dialog, opened on them. */
const unmakeAdministrator = (row) => openManage(row)

/*
 * An account's status in the list's words (M99): the server sends Active or Inactive; the board's sheet colours only
 * `.status-badge.active`, so anything else takes the neutral navy the boards use for a fact that is simply so.
 */
const NEUTRAL_BADGE = 'background:var(--navy-100);color:var(--slate-600);'
const EXPIRED_BADGE = 'background:var(--amber-100);color:var(--amber-700);'

/* ------------------------------------------------------------------ */
/* the five states                                                     */
/* ------------------------------------------------------------------ */

/*
 * Sources are functions, not values: useScreenState runs once during setup. The filtered empty is forceable only:
 * nothing here filters the list a reader can see, but the list IS filtered to this community's own roles.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => false,
})

const showsLists = computed(() => !state.isLoading.value && !state.isDenied.value && !state.isError.value)
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <template #actions>
            <!-- M96 level 3: a viewer who can never invite has no Invite; one who may change nothing reads a badge. -->
            <span v-if="readsOnly" class="view-badge">View only</span>
            <button
                v-else-if="canInvite"
                type="button"
                class="btn-primary-sm"
                title="Invite somebody to hold a role on this estate."
                @click="openInvite"
            >
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Invite user</span>
            </button>
        </template>

        <div class="settings-layout">
            <!-- The settings column. The item the reader is on is text: there is nowhere for it to lead. -->
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">
                        {{ section.label }}
                    </div>
                    <Link v-else :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                </template>
            </div>

            <div>
                <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="5" />

                <EmptyState
                    v-else-if="state.isDenied.value"
                    variant="denied"
                    title="Settings is not part of your role’s access"
                    body="Who may reach this estate is kept in Settings, and your role does not include Settings."
                />

                <EmptyState
                    v-else-if="state.isError.value"
                    variant="error"
                    title="The user list could not be read"
                    body="The estate database did not answer. Nobody’s access has changed. Trying again is safe."
                    action-label="Try again"
                    @action="retry"
                />

                <!-- Assignments exist and none is this community's: forceable only, and true when it is forced. -->
                <EmptyState
                    v-else-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="Everybody assigned here works for Gemini Security"
                    body="This list holds the committee, and no committee role has been given out yet. Gemini’s own staff are not listed: their access is Gemini’s to give."
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="Nobody can reach this estate yet"
                    body="No committee role has been given out here, so nobody can read the ledger, run a meeting or answer a ticket. An estate’s first user is set up with the estate."
                />

                <!-- The scroller: a table wider than its container scrolls rather than hiding its right-hand columns. -->
                <div v-else class="u-scroller">
                    <!-- Said once, in words, for a viewer who may read this list and change nobody on it. -->
                    <p v-if="!canManage" class="u-note">{{ manageBlockedReason }}</p>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Modules</th>
                                <th>Status</th>
                                <!-- The board's fifth header is empty: it sits over the row's actions. -->
                                <th v-if="canManage"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="row in rows" :key="row.id">
                                <tr>
                                    <td>
                                        <div class="res-cell">
                                            <div class="res-avatar">{{ row.initials }}</div>
                                            <div>
                                                <div class="res-name">{{ row.name }}</div>
                                                <div class="res-sub">{{ row.email }}</div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- "President · Owner" arrives as one string: who gets the second half is the matrix's rule. -->
                                    <td>
                                        <div class="role-badge" :class="{ owner: row.is_owner }">{{ row.role_badge }}</div>
                                        <span v-if="row.is_administrator" class="approver-tag u-admin-tag">Administrator</span>
                                    </td>

                                    <td class="u-modules" :title="row.module_list.join(', ')">{{ moduleSummary(row) }}</td>

                                    <td>
                                        <!--
                                          A status leads to where it is changed (Hassam, 12 Oct, batch 1 item 10): for somebody this
                                          viewer may manage, Active or Inactive opens Change role or access; otherwise it is a fact.
                                        -->
                                        <button
                                            v-if="canManage && manageBlockedBy(row) === null"
                                            type="button"
                                            class="status-badge status-act"
                                            :class="row.status"
                                            :style="row.status === 'active' ? undefined : NEUTRAL_BADGE"
                                            :title="`Change ${row.name}’s role or access.`"
                                            @click="openManage(row)"
                                        >
                                            {{ row.status_label }}
                                        </button>
                                        <div
                                            v-else
                                            class="status-badge"
                                            :class="row.status"
                                            :style="row.status === 'active' ? undefined : NEUTRAL_BADGE"
                                        >
                                            {{ row.status_label }}
                                        </div>
                                    </td>

                                    <!-- M96: level 3 for a viewer who may manage nobody (no column); level 2 with the reason under the row. -->
                                    <td v-if="canManage">
                                        <div class="inv-row-actions">
                                            <button
                                                type="button"
                                                class="text-link-sm"
                                                :disabled="manageBlockedBy(row) !== null"
                                                :title="`Change ${row.name}’s role or access.`"
                                                :aria-label="`Manage ${row.name}`"
                                                @click="openManage(row)"
                                            >
                                                Manage
                                            </button>
                                            <button
                                                type="button"
                                                class="text-link-sm"
                                                :disabled="removeBlockedBy(row) !== null"
                                                :title="`Take ${row.name} off the committee.`"
                                                :aria-label="`Remove ${row.name}`"
                                                @click="openRemove(row)"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- WHY THIS ROW'S CONTROLS ARE OFF, IN WORDS: the server's sentence, before anybody presses anything. -->
                                <tr v-if="rowRefusals(row).length > 0" class="u-why-row">
                                    <td :colspan="5">
                                        <p v-for="reason in rowRefusals(row)" :key="reason" class="u-why">{{ reason }}</p>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!--
                  TAKEN OFF THE COMMITTEE (M10 §4). Everybody removed, with the one act that brings them back in the role
                  they held. Absent when nobody has been removed. Reinstate is drawn for a viewer who may manage people,
                  greyed with the server's sentence under it where it is refused.
                -->
                <section v-if="showsLists && removed.length > 0" class="adm-panel" aria-labelledby="rm-head">
                    <div id="rm-head" class="inv-section-head">Taken off the committee</div>
                    <p class="adm-note">
                        Removed, never deleted: each account is inactive and holds no role, so it reaches nothing here.
                        Reinstating somebody gives them back the role they held, and it goes on the estate’s audit log.
                    </p>

                    <ul class="adm-list">
                        <li v-for="person in removed" :key="person.id" class="adm-item">
                            <div class="res-cell">
                                <div class="res-avatar">{{ person.initials }}</div>
                                <div>
                                    <div class="res-name">{{ person.name }}</div>
                                    <div class="res-sub">{{ person.email }} · held {{ person.role_label }}</div>
                                </div>
                            </div>

                            <div v-if="canManage" class="adm-act">
                                <button
                                    type="button"
                                    class="text-link-sm"
                                    :disabled="reinstateBlockedBy(person) !== null"
                                    :title="`Bring ${person.name} back as ${person.role_label}.`"
                                    :aria-label="`Reinstate ${person.name}`"
                                    @click="openReinstate(person)"
                                >
                                    Reinstate…
                                </button>
                                <p v-if="reinstateBlockedBy(person)" class="adm-why">{{ reinstateBlockedBy(person) }}</p>
                            </div>
                        </li>
                    </ul>
                </section>

                <!--
                  ADMINISTRATORS (M10 §4). Who can change what every role here may do. Making one asks first, naming the
                  person and the role they would go from and to; stopping is giving them another role, through Manage.
                -->
                <section v-if="showsLists && rows.length > 0" class="adm-panel" aria-labelledby="adm-head">
                    <div id="adm-head" class="inv-section-head">Administrators</div>
                    <p class="adm-note">{{ administratorNote }}</p>

                    <ul class="adm-list">
                        <li v-for="row in administrators" :key="row.id" class="adm-item">
                            <div class="res-cell">
                                <div class="res-avatar">{{ row.initials }}</div>
                                <div>
                                    <div class="res-name">{{ row.name }}</div>
                                    <div class="res-sub">{{ row.role_label }}</div>
                                </div>
                            </div>

                            <div v-if="canManage" class="adm-act">
                                <button
                                    type="button"
                                    class="text-link-sm"
                                    :disabled="manageBlockedBy(row) !== null"
                                    :title="`Give ${row.name} another role, which stops them being an administrator.`"
                                    @click="unmakeAdministrator(row)"
                                >
                                    Stop being administrator…
                                </button>
                                <p v-if="manageBlockedBy(row)" class="adm-why">{{ manageBlockedBy(row) }}</p>
                            </div>
                        </li>
                        <li v-if="administrators.length === 0" class="adm-item adm-none">
                            Nobody on this committee is an administrator.
                        </li>
                    </ul>

                    <div v-if="canManage" class="adm-add">
                        <button
                            type="button"
                            class="btn-outline-sm"
                            :disabled="adminBlockedBy !== null"
                            :title="`Give somebody the ${adminRoleLabel} role.`"
                            @click="openMakeAdmin"
                        >
                            Make somebody an administrator…
                        </button>
                        <p v-if="adminBlockedBy" class="adm-why">{{ adminBlockedBy }}</p>
                    </div>
                </section>

                <!--
                  The invitations still open, under the committee they will join. Absent when there are none. A lapsed one
                  stays until it is withdrawn or resent: one that quietly vanished would read as one that was accepted.
                -->
                <template v-if="showsLists && invitations.length > 0">
                    <div class="inv-section-head">Invitations open</div>

                    <!-- In the scroller too (checked at 390 px, 12 Oct): on a phone this table ran the page to 706 px. -->
                    <div class="u-scroller">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Invited</th>
                                    <th>Role</th>
                                    <th>Invited by</th>
                                    <th>Open until</th>
                                    <th v-if="canInvite"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="invitation in invitations" :key="invitation.id">
                                    <tr>
                                        <td>
                                            {{ invitation.email }}
                                            <div class="inv-delivery" :style="deliveryStyle(invitation)">{{ invitation.delivery }}</div>
                                        </td>
                                        <td><div class="role-badge">{{ invitation.role_label }}</div></td>
                                        <td>{{ invitation.invited_by }} · invited {{ invitation.invited_on }}</td>
                                        <td>
                                            <!-- Expired leads to its Resend question, for whoever may resend it now (item 10). -->
                                            <button
                                                v-if="invitation.expired && canInvite && resendBlockedBy(invitation) === null && resendingId === null"
                                                type="button"
                                                class="status-badge status-act"
                                                :style="EXPIRED_BADGE"
                                                title="Send the invitation again with a fresh link."
                                                @click="resend(invitation)"
                                            >
                                                Expired · {{ invitation.expires_on }}
                                            </button>
                                            <div v-else class="status-badge" :style="invitation.expired ? EXPIRED_BADGE : NEUTRAL_BADGE">
                                                {{ invitation.expired ? `Expired · ${invitation.expires_on}` : invitation.expires_on }}
                                            </div>
                                        </td>
                                        <!-- M96 level 3: a viewer who may not invite has neither Resend nor Withdraw. -->
                                        <td v-if="canInvite">
                                            <div class="inv-row-actions">
                                                <button
                                                    type="button"
                                                    class="text-link-sm"
                                                    :disabled="resendBlockedBy(invitation) !== null || resendingId !== null"
                                                    :aria-busy="resendingId === invitation.id"
                                                    title="Email the invitation again with a fresh link."
                                                    @click="resend(invitation)"
                                                >
                                                    {{ resendLabel(invitation) }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="text-link-sm"
                                                    title="Withdraw the invitation."
                                                    @click="openWithdraw(invitation)"
                                                >
                                                    Withdraw
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <!-- Why Resend is waiting, said under the invitation (level 2). -->
                                    <tr v-if="canInvite && resendBlockedBy(invitation)" class="u-why-row">
                                        <td colspan="5">
                                            <p class="u-why">{{ resendBlockedBy(invitation) }}</p>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>

                <!-- INVITE: the console's one dialog (M95), the role's reach shown before the invitation goes out. -->
                <ConsoleDialog
                    :open="inviting"
                    :title="`Invite somebody to ${estate.name}`"
                    size="form"
                    confirm-label="Send invitation"
                    busy-label="Sending…"
                    :busy="inviteForm.processing"
                    :confirm-blocked-reason="inviteBlockedBy"
                    :error="inviteForm.errors.role ?? null"
                    :dirty="inviteForm.email.trim() !== ''"
                    discard-title="Discard this invitation?"
                    @confirm="submitInvite"
                    @cancel="closeInvite"
                >
                    <!-- What the mark means, once, above the first field (batch 1 item 3). -->
                    <RequiredMark legend />
                    <div class="dlg-fields">
                        <div class="dlg-field">
                            <label for="inv-email">Email address <RequiredMark /></label>
                            <input id="inv-email" v-model="inviteForm.email" type="email" required maxlength="190" autocomplete="off" placeholder="e.g. name@example.com" />
                            <span class="dlg-hint">The invitation goes here, and it becomes their sign-in.</span>
                            <span v-if="inviteForm.errors.email" class="dlg-error" role="alert">{{ inviteForm.errors.email }}</span>
                        </div>

                        <div class="dlg-field">
                            <label for="inv-role">Role <RequiredMark /></label>
                            <select id="inv-role" v-model="inviteForm.role" required>
                                <option
                                    v-for="role in roles"
                                    :key="role.name"
                                    :value="role.name"
                                    :disabled="Boolean(role.hand_out_refusal)"
                                >
                                    {{ roleOptionLabel(role) }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <p v-if="chosenRole" class="dlg-read">
                        {{ chosenRole.label }} reaches: {{ chosenRole.modules || 'nothing yet' }}.
                        <template v-if="chosenRole.is_administrator">
                            As an administrator they could also change what every role here may do.
                        </template>
                        The invitation names you, the estate and the role, and is open for fourteen days.
                    </p>

                    <!-- Why the administrator's role is greyed in the list, in the server's words. -->
                    <p v-if="handOutRefusal(administratorRole)" class="dlg-note">{{ handOutRefusal(administratorRole) }}</p>
                </ConsoleDialog>

                <!-- MANAGE: a person's role and access, the read-back naming exactly what changes. -->
                <ConsoleDialog
                    :open="managing !== null"
                    :title="managing ? `Change ${managing.name}’s role or access` : ''"
                    size="form"
                    confirm-label="Save the change"
                    :busy="manageForm.processing"
                    :confirm-blocked-reason="manageChanges ? null : 'Nothing has changed yet.'"
                    :error="manageForm.errors.role ?? null"
                    :dirty="manageChanges"
                    discard-title="Discard this change?"
                    @confirm="submitManage"
                    @cancel="closeManage"
                >
                    <template v-if="managing">
                        <p class="dlg-note">{{ managing.email }} · now {{ managing.role_label }}</p>

                        <div class="dlg-fields">
                            <div class="dlg-field">
                                <label for="mg-role">Role</label>
                                <select id="mg-role" v-model="manageForm.role" required>
                                    <option
                                        v-for="option in roles"
                                        :key="option.name"
                                        :value="option.name"
                                        :disabled="Boolean(option.hand_out_refusal) && option.name !== managing.role"
                                    >
                                        {{ roleOptionLabel(option) }}
                                    </option>
                                </select>
                            </div>
                            <div class="dlg-field">
                                <label for="mg-status">Account</label>
                                <select id="mg-status" v-model="manageForm.status" required>
                                    <option value="active">Active · can sign in</option>
                                    <option value="suspended">Inactive · cannot sign in</option>
                                </select>
                            </div>
                        </div>

                        <p class="dlg-read">{{ manageReadBack }}</p>

                        <!-- Why an administrator's role is greyed in the list above. -->
                        <p v-if="handOutRefusal(administratorRole)" class="dlg-note">{{ handOutRefusal(administratorRole) }}</p>
                    </template>
                </ConsoleDialog>

                <!-- REMOVE: destructive, so a red outline (never solid red); nothing is deleted. -->
                <ConsoleDialog
                    :open="removing !== null"
                    :title="removing ? `Take ${removing.name} off the committee?` : ''"
                    tone="danger"
                    confirm-label="Remove from the committee"
                    busy-label="Removing…"
                    :busy="removeForm.processing"
                    :error="removeForm.errors.remove ?? null"
                    @confirm="submitRemove"
                    @cancel="removing = null"
                >
                    <p v-if="removing" class="dlg-plain">
                        {{ removing.name }} stops being {{ removing.role_label }}: their account becomes inactive and holds no
                        role, so it reaches nothing here. Nothing is deleted. Who held the role and until when stays on the
                        audit log, and they can be reinstated from this page.
                    </p>
                </ConsoleDialog>

                <!-- REINSTATE: gives back the role they held, named. -->
                <ConsoleDialog
                    :open="reinstating !== null"
                    :title="reinstating ? `Bring ${reinstating.name} back as ${reinstating.role_label}?` : ''"
                    confirm-label="Reinstate"
                    busy-label="Reinstating…"
                    :busy="reinstateForm.processing"
                    :error="reinstateForm.errors.reinstate ?? null"
                    @confirm="submitReinstate"
                    @cancel="reinstating = null"
                >
                    <p v-if="reinstating" class="dlg-plain">
                        {{ reinstating.name }} gets back {{ reinstating.role_label }}, their account becomes active and they
                        can sign in again.
                        <template v-if="reinstating.is_administrator">
                            They become an administrator again: they can change what every role here may do.
                        </template>
                    </p>
                </ConsoleDialog>

                <!-- MAKE ADMINISTRATOR: it went at the first press; now it names who goes from which role to which. -->
                <ConsoleDialog
                    :open="makingAdmin"
                    title="Make somebody an administrator"
                    size="form"
                    confirm-label="Make administrator"
                    :busy="adminForm.processing"
                    :confirm-blocked-reason="adminChosen ? null : 'Choose who first.'"
                    :error="adminForm.errors.role ?? null"
                    @confirm="makeAdministrator"
                    @cancel="closeMakeAdmin"
                >
                    <RequiredMark legend />
                    <div class="dlg-fields">
                        <div class="dlg-field">
                            <label for="adm-pick">Committee member <RequiredMark /></label>
                            <select id="adm-pick" v-model="adminPick" required>
                                <option value="">Choose a committee member</option>
                                <option v-for="row in adminCandidates" :key="row.id" :value="String(row.id)">
                                    {{ row.name }} · now {{ row.role_label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <p v-if="adminReadBack" class="dlg-read">{{ adminReadBack }}</p>
                    <p v-else class="dlg-note">It gives them the {{ adminRoleLabel }} role.</p>
                </ConsoleDialog>

                <!-- RESEND: it emails somebody, so it asks first (DR-071). -->
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
                        An email to {{ resendAsk.email }}, with a fresh link to join the console as {{ resendAsk.role_label }}. Once it goes, the earlier link stops working. Nobody else is told.
                    </p>
                </ConsoleDialog>

                <!-- WITHDRAW: the link they hold stops working. -->
                <ConsoleDialog
                    :open="withdrawAsk !== null"
                    :title="withdrawAsk ? `Withdraw the invitation to ${withdrawAsk.email}?` : ''"
                    tone="danger"
                    confirm-label="Withdraw invitation"
                    busy-label="Withdrawing…"
                    :busy="withdrawForm.processing"
                    :error="withdrawForm.errors.invitation ?? null"
                    @confirm="postWithdraw"
                    @cancel="withdrawAsk = null"
                >
                    <p v-if="withdrawAsk" class="dlg-plain">
                        The link sent to {{ withdrawAsk.email }} stops working, and no account is made for it. Nobody is
                        emailed about this. You can invite the address again later.
                    </p>
                </ConsoleDialog>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its topbar action and its row links as <div>s; here they are buttons. A <button> arrives wearing a
 * border, a face and the browser's own font. `font-family` and never `font` (D-045).
 */
button {
    font-family: inherit;
}

button.btn-primary-sm {
    border: 0;
}

button.text-link-sm {
    border: 0;
    background: none;
    text-align: left;
}

/* A status that leads somewhere (batch 1 item 10): the badge as drawn, a pointer and an underline on hover. */
button.status-badge.status-act {
    border: 0;
    font-family: inherit;
    cursor: pointer;
}

button.status-badge.status-act:hover,
button.status-badge.status-act:focus-visible {
    text-decoration: underline;
}

button.btn-primary-sm,
button.text-link-sm,
button.btn-outline-sm {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board is a still image of a committee nobody has invited anybody to, so it draws no
 * dialog, no answer and no open invitations. Kept to the tokens and the shapes the estate boards use.
 */
.inv-section-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--slate-500);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin: 18px 0 10px;
}

.inv-row-actions {
    display: flex;
    gap: 12px;
}

/* Whether the invitation's email went — the server's words, under the address. */
.inv-delivery {
    font-size: 11px;
    font-weight: 600;
    line-height: 1.45;
    margin-top: 3px;
    max-width: 44ch;
    overflow-wrap: anywhere;
}

/*
 * THE COLUMN THAT WAS CLIPPED OFF THE RIGHT-HAND EDGE: the modules cell is capped, the address wraps, and the table
 * scrolls rather than silently hiding what is to the right of it.
 */
.u-modules {
    max-width: 24ch;
    overflow-wrap: anywhere;
}

.res-sub {
    overflow-wrap: anywhere;
}

.u-scroller {
    overflow-x: auto;
}

button[disabled] {
    opacity: 0.55;
}

.u-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0 0 10px;
}

.u-admin-tag {
    margin-top: 4px;
}

.u-why-row td {
    background: var(--app-bg);
    padding: 7px 16px 9px;
}

.u-why,
.adm-why {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0;
    max-width: 760px;
}

.u-why + .u-why {
    margin-top: 4px;
}

.adm-panel {
    margin-top: 6px;
}

.adm-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0 0 10px;
    max-width: 760px;
}

.adm-list {
    list-style: none;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    overflow: hidden;
}

.adm-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--navy-100);
}

.adm-item:last-child {
    border-bottom: none;
}

.adm-none {
    font-size: 12px;
    color: var(--slate-500);
}

.adm-act {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    max-width: 420px;
    text-align: right;
}

.adm-add {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    margin: 12px 0 8px;
}

/* An outlined secondary action, in the boards' navy. */
button.btn-outline-sm {
    height: 32px;
    padding: 0 14px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    color: var(--navy-800);
    font-size: 12px;
    font-weight: 700;
}

/* Level 3's badge, where the actions would be. */
.view-badge {
    display: inline-flex;
    align-items: center;
    height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 700;
}

/* Inside the dialogs: the fields, what the press will change, and the server's notes. */
.dlg-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}

.dlg-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.dlg-field label {
    font-size: 11px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.dlg-field input,
.dlg-field select {
    height: 36px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.dlg-hint {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.45;
}

.dlg-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.45;
}

.dlg-read {
    margin: 12px 0 0;
    padding: 10px 13px;
    border-radius: 10px;
    background: var(--navy-100);
    color: var(--navy-900);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.55;
}

.dlg-note {
    margin: 8px 0 0;
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
}

.dlg-note:first-child {
    margin: 0 0 10px;
}

.dlg-plain {
    margin: 0;
    font-size: 12.5px;
    color: var(--navy-900);
    line-height: 1.55;
}

/*
 * ON A PHONE (checked at 390 px with the new shell, 12 Oct). The board keeps the section list beside the page, 200 px of
 * a 390 px screen, and every Settings page ran past the window. Below 760 px the list sits above the page as one row
 * that scrolls sideways, and the page takes the full width. Doubled classes outrank the board's own rules.
 */
@media (max-width: 760px) {
    .settings-layout.settings-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 14px;
    }

    .settings-nav.settings-nav {
        flex-direction: row;
        overflow-x: auto;
    }

    .settings-nav-item.settings-nav-item {
        flex: 0 0 auto;
        width: auto;
        white-space: nowrap;
    }

    /* Both tables scroll sideways at a width their columns read at: squeezed, an email broke every few letters. */
    .u-scroller > .data-table {
        min-width: 720px;
    }

    /* A refusal under a row stays in view while the table scrolls, instead of running off under its far columns. */
    .u-why.u-why {
        position: sticky;
        left: 16px;
        max-width: calc(100vw - 64px);
    }

    /* A person, then what may be done about them: side by side, the name and role went a word a line. */
    .adm-item.adm-item {
        flex-direction: column;
        gap: 8px;
    }

    .adm-act.adm-act {
        align-items: flex-start;
        text-align: left;
        max-width: none;
    }
}
</style>
