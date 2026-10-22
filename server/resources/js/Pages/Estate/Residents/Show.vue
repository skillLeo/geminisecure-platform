<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import InfoButton from '../../../Components/InfoButton.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { agoFrom } from '../../../composables/useClock.js'
import { useLiveReload } from '../../../composables/useLiveReload'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * One household in full — board screen community-admin-38.
 *
 * THE HERO'S "BALANCE OWED" TILE IS ONE CELL, NOT TWO. `stats` carries the
 * whole `standing` object's `label` and `tone` for that tile and nothing else
 * of it — `value_minor` arrives on the payload and this page never reads it,
 * because the cell has to print whatever `standingOf()` decided a screen may
 * say, and reaching past `label` for a number would be exactly the second route
 * back to the figure the hard invariant exists to close. `EstateResidentsTest`
 * scans this whole payload for the vocabulary of money and for any digit at
 * all, against Lot 47's real balance.
 *
 * THE LEDGER CROSS-LINK IS ABSENT, NOT BLANKED, WHEN IT MAY NOT BE SHOWN.
 * `Residents::linkedActivity()` only ever appends the arrears row when the
 * standing it was built from actually carries a positive `balance_minor` —
 * which cannot happen for a restricted household or a viewer without
 * `estate.dues_ledger.view`, both of which stop `standingOf()` before a figure
 * exists to compare. So this template never decides whether to draw that row;
 * it draws exactly the rows `linked` contains.
 *
 * "VIEW LEDGER" IS DRAWN ONLY FOR A ROLE THAT HOLDS THE LEDGER — absent rather
 * than disabled, the same rule the sidebar itself follows for a module a role
 * cannot reach (D-055). Board 38's own persona is the Property Manager, whom
 * D-010 locks out of `dues_ledger` entirely; measured as a role that holds it,
 * this page draws the same three actions the board does, and the discrepancy
 * for the Property Manager specifically is D-044's general ruling rather than
 * a new one.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    resident: { type: Object, required: true },
    standing: { type: Object, required: true },
    stats: { type: Array, required: true },
    members: { type: Array, required: true },
    contact: { type: Array, required: true },
    linked: { type: Array, required: true },
    /**
     * Who has lived at this lot (M98, ownership and tenancy history): every household the register has held here, those
     * here now first, each `{ id, name, held_by, recorded_as, moved_in, moved_out, reason, current }`.
     */
    history: { type: Array, default: () => [] },
    canViewLedger: { type: Boolean, required: true },

    /*
     * M32 F1: every household at this lot, when there is more than one ("a new, separate household at this lot"),
     * `{ id, name, holder, sample, shown, param }` — empty for the ordinary lot of one, which needs no switcher.
     */
    households_here: { type: Array, default: () => [] },

    /*
     * Declared and not rendered. `reasons.edit` says editing a resident is not
     * built yet FOR ANYBODY — the same shape as board 3's "Add phase" — so
     * there is no access-denied sentence this flag would ever choose between;
     * an undeclared prop on this fragment's root would fall through as a stray
     * HTML attribute rather than be ignored.
     */
    canEdit: { type: Boolean, required: true },
    editBlockedReason: { type: String, required: true },
    /** The household's people, as the edit panel edits them. */
    people: { type: Array, required: true },
    reasons: { type: Object, required: true },

    /* Q-M44 — ending and giving back a person's Resident App login. */
    canEndAppAccess: { type: Boolean, required: true },
    canRemoveHousehold: { type: Boolean, default: false },
    appAccessBlockedReason: { type: String, required: true },

    /* Sending — and resending — a Pending person's invite to the Resident App. */
    canInvite: { type: Boolean, default: false },
    inviteBlockedReason: { type: String, default: '' },

    /* What each person may do in the Resident App — Member, Dependent, Helper — and who may change it. */
    accessLevels: { type: Array, default: () => [] },
    canChangeLevel: { type: Boolean, default: false },
    levelBlockedReason: { type: String, default: '' },

    /* Whether anybody may be given less than Member yet (`AccessLevels::open`), and the sentence for when not. */
    levelsOpen: { type: Boolean, default: false },
    levelsClosedReason: { type: String, default: '' },

    /** The unit this household is on — what its live events are named by. */
    unitId: { type: Number, default: 0 },
})

/*
 * THIS PAGE MOVES BY ITSELF (M18H §6): somebody added or corrected at this lot, a claim decided, a payment posted, or
 * an app account deleted or restored — read again, keeping the scroll and whatever is being typed.
 */
useLiveReload({ entities: ['resident', 'dues'], ids: () => (props.unitId > 0 ? [props.unitId] : null) })
useLiveReload({ entities: ['account'] })

/* ------------------------------------------------------------------ */
/* access levels in the Resident App — Member, Dependent, Helper */
/* ------------------------------------------------------------------ */

/*
 * THE SERVER ENFORCES THESE ON EVERY REQUEST (`AppMatrix::LEVELS`); this panel is where the office sees them and
 * changes them. Each level arrives with what it may do (`may`), so what a change takes away is worked out from the
 * same table the server refuses by — never a sentence typed here that could drift from it.
 */
const levelOf = (value) => props.accessLevels.find((level) => level.value === value) ?? null

/** A level's name for a sentence: in words even for a level this page does not know, never "undefined" (batch 1 sweep). */
const levelLabel = (key) => levelOf(key)?.label ?? 'another level'

/** What each `may` key is called in a sentence. */
const MAY_WORDS = {
    // No pets (M57 §6): they are out of the Resident App's interface, so no sentence here promises them.
    household: 'the household’s members, vehicles and contacts',
    household_edit: 'changing vehicles and emergency contacts',
    visitor_passes: 'visitor passes',
    approvals: 'answering the gate',
    dues: 'dues',
    payments: 'payments',
    tickets: 'maintenance requests',
    notices: 'estate notices',
    meetings: 'meetings',
    elections: 'voting',
    bookings: 'bookings',
}

const listed = (items) => (items.length <= 1 ? (items[0] ?? '') : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`)

const levelDiff = (fromValue, toValue) => {
    const from = levelOf(fromValue)?.may ?? {}
    const to = levelOf(toValue)?.may ?? {}
    const keys = Object.keys(MAY_WORDS)

    return {
        lost: keys.filter((key) => from[key] && !to[key]).map((key) => MAY_WORDS[key]),
        gained: keys.filter((key) => !from[key] && to[key]).map((key) => MAY_WORDS[key]),
    }
}

/*
 * What the select offers a person. While levels are closed the server gives nobody less than Member, so only Member
 * is offered — beside a level the person already holds from before, which can always be taken back to Member.
 */
const levelChoices = (person) =>
    props.levelsOpen ? props.accessLevels : props.accessLevels.filter((level) => level.value === 'member' || level.value === person.access_level)

const levelTitle = (person) => {
    if (person.is_primary) {
        return 'The primary resident always has Member access.'
    }

    if (!props.canChangeLevel) {
        return props.levelBlockedReason
    }

    return levelChoices(person).length < 2 ? props.levelsClosedReason : `What ${person.full_name} may do in the Resident App.`
}

const levelForm = useForm({ access_level: '' })

// ConsoleDialog: Change access level.
const levelChange = ref(null)

const chooseLevel = (person, event) => {
    const to = event.target.value

    // The select keeps showing the level as it stands until the change is confirmed.
    event.target.value = person.access_level

    if (!props.canChangeLevel || person.is_primary || to === person.access_level) {
        return
    }

    levelForm.clearErrors()
    levelChange.value = { person, from: person.access_level, to }
}

const levelNarrows = computed(() => levelChange.value !== null && levelDiff(levelChange.value.from, levelChange.value.to).lost.length > 0)

const levelSentence = computed(() => {
    if (levelChange.value === null) {
        return ''
    }

    const { lost, gained } = levelDiff(levelChange.value.from, levelChange.value.to)
    const after = 'It takes effect the next time their app asks the server; nobody is signed out.'

    return lost.length > 0
        ? `In the app they lose ${listed(lost)} at once. The panic button and their own e-pass stay. ${after}`
        : `In the app they gain ${listed(gained)}. ${levelOf(levelChange.value.to)?.sentence ?? ''} ${after}`
})

const confirmLevel = () => {
    const change = levelChange.value

    if (change === null) {
        return
    }

    /*
     * SAID ONCE (M95 §2, M98 pass): the server's sentence arrives as the flash and the console says it as a toast. It was
     * also copied into the person's row, so the same words stood twice; the row's select now shows the new level.
     */
    levelForm.access_level = change.to
    levelForm.post(`${root.value}/residents/${props.resident.unit_slug}/people/${change.person.id}/access-level`, {
        preserveScroll: true,
        onSuccess: () => {
            levelChange.value = null
        },
    })
}

/* ------------------------------------------------------------------ */
/* invites to the Resident App — sent, resent, cancelled */
/* ------------------------------------------------------------------ */

/*
 * "WHEREVER THERE IS THE INVITE OPTION THERE MUST BE RESEND INVITE" (the user, 24 September). Every Pending person
 * is listed with what became of their invite, in the server's words — emailed, not sent and why, lapsed, cancelled —
 * and a Resend that waits out the server's own timer (1, then 2, then 5 minutes), counting down on the button so
 * nobody presses it into a refusal. Cancelling asks first, in a dialog in view.
 */
const pendingPeople = computed(() => props.people.filter((person) => person.is_pending))

const clock = ref(Date.now())
let ticking = null
onMounted(() => {
    ticking = setInterval(() => {
        clock.value = Date.now()
    }, 1000)
})
onBeforeUnmount(() => clearInterval(ticking))

/** Seconds until Resend may be pressed again, from the server's `resend_after`; 0 means now. */
const waitFor = (person) => {
    const after = person.invite?.resend_after ? Date.parse(person.invite.resend_after) : Number.NaN

    return Number.isFinite(after) ? Math.max(0, Math.ceil((after - clock.value) / 1000)) : 0
}

const mmss = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`

/*
 * HOW LONG AGO THE INVITE WENT (M19A §4.1) — "Emailed to … · 2 minutes ago". It printed the reader's own clock with no
 * label: the very sentence that looked broken to a reader in another zone.
 */
const when = (iso) => agoFrom(iso, clock.value) ?? ''

/** What became of the invite, in one sentence — never "sent" unless the mail server took it. */
const inviteLine = (person) => {
    const invite = person.invite ?? { status: 'none' }

    switch (invite.status) {
        case 'sent':
            return `Emailed to ${invite.email} · ${when(invite.sent_at)}`
        case 'not_sent':
            // The office reads the real reason for a refusal; the app is only ever told the generic one.
            return `Not sent: ${invite.office_reason ?? invite.reason ?? 'no reason was recorded.'}`
        case 'expired':
            return 'Lapsed without being used. Send it again.'
        case 'cancelled':
            return 'Cancelled.'
        case 'claimed':
            return 'Accepted: they signed in with this address, and their claim is in Unit claims.'
        default:
            return person.email ? 'No invite sent yet.' : 'No email address on file, so no invite can be sent. Add one with Edit details.'
    }
}

const inviteForm = useForm({})
// Its own form, so a refused send is never shown again, stale, in the Cancel invite dialog's band (Hassam, 12 Oct).
const cancelInviteForm = useForm({})
const sendingId = ref(null)

const inviteLabel = (person) => {
    const wait = waitFor(person)

    if (sendingId.value === person.id && inviteForm.processing) {
        return 'Sending…'
    }

    if (wait > 0) {
        return `Resend in ${mmss(wait)}`
    }

    return ['sent', 'not_sent'].includes(person.invite?.status) ? 'Resend invite' : 'Send invite'
}

const canSendNow = (person) => props.canInvite && !!person.email && waitFor(person) === 0 && !inviteForm.processing

/*
 * SEND INVITE ASKS FIRST (DR-069; the register's Part 7 rule: a control that starts contact confirms first, naming how
 * many and by what channel). The press opens a question naming the person and the address the email goes to; only the
 * dialog's own button sends.
 */
const inviting = ref(null)

const isResend = (person) => ['sent', 'not_sent'].includes(person?.invite?.status)

/** A person's e-Pass in a word, beside its line (M98): the approved words, never red. */
const EPASS_WORDS = { active: 'Active', paused: 'Paused', cancelled: 'Cancelled', none: 'None' }

/** "Held by Andrea Fletcher · Owner · moved in 2 Sep 2024": one household of the lot's history, in one line. */
const historyLine = (entry) =>
    [
        entry.held_by ? `Held by ${entry.held_by}` : null,
        entry.recorded_as === 'Not recorded' ? 'Owner or tenant not recorded' : entry.recorded_as,
        entry.moved_in ? `moved in ${entry.moved_in}` : null,
    ]
        .filter(Boolean)
        .join(' · ')

const inviteTitle = computed(() => (inviting.value === null ? '' : `${isResend(inviting.value) ? 'Resend' : 'Send'} the invite to ${inviting.value.full_name}?`))

const sendInvite = (person) => {
    if (!canSendNow(person)) {
        return
    }

    inviteForm.clearErrors()
    inviting.value = person
}

/*
 * A REFUSAL OF AN OPEN DIALOG IS SAID INSIDE IT (Hassam, 12 Oct): in its amber band, the dialog staying open until it is
 * fixed or closed. It was closed and toasted, and at 390 px a toast behind a modal is hidden or scrolled away.
 */
const inviteRefusal = computed(() => inviteForm.errors.invite ?? Object.values(inviteForm.errors)[0] ?? null)

const closeInvite = () => {
    inviting.value = null
    inviteForm.clearErrors()
}

const postInvite = () => {
    const person = inviting.value

    if (person === null || !canSendNow(person)) {
        return
    }

    sendingId.value = person.id
    inviteForm.post(`${root.value}/residents/${props.resident.unit_slug}/people/${person.id}/invite`, {
        preserveScroll: true,
        onSuccess: () => {
            inviting.value = null
        },
        onFinish: () => {
            sendingId.value = null
        },
    })
}

// ConsoleDialog: Cancel invite.
const cancelling = ref(null)

const openCancelInvite = (person) => {
    cancelInviteForm.clearErrors()
    cancelling.value = person
}

const confirmCancelInvite = () => {
    if (cancelling.value === null) {
        return
    }

    cancelInviteForm.post(`${root.value}/residents/${props.resident.unit_slug}/people/${cancelling.value.id}/invite/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null
        },
    })
}

/* ------------------------------------------------------------------ */
/* correcting a record (12 §2, Wave 2) */
/* ------------------------------------------------------------------ */

/*
 * WHAT THIS PANEL EDITS. Who somebody is — their name, how to reach them, their
 * relationship to the household, the date they moved in. NOT whether they are
 * authorised: verification is decided where a claim is reviewed, by somebody
 * whose name and date go on the decision, and a status editable from a details
 * form would let a person verify themselves with nothing behind it. Biometric
 * consent is not here either; it is the person's and not the office's (D-022).
 */
const editingId = ref(null)

const editForm = useForm({
    full_name: '',
    email: '',
    phone: '',
    // M18C §3.2: an email, or this ticked — a person with none has their alerts go to the primary resident.
    no_email: false,
    relationship: 'owner',
    moved_in_on: '',
    is_primary: false,
})

/*
 * IN THE CONSOLE'S MODAL SINCE M95 §1 (M98 pass), with the two acts below: each opened as a panel between the hero and
 * the household, which made the page longer and announced nothing. Each error sits under its own field, and a refusal
 * for a field the form does not draw is still said in it (EST-B-03a).
 */
const openEdit = () => {
    if (!props.canEdit || props.people.length === 0) {
        return
    }

    choosePerson(props.people[0])
}

const choosePerson = (person) => {
    editForm.clearErrors()
    Object.assign(editForm, {
        full_name: person.full_name,
        email: person.email ?? '',
        phone: person.phone ?? '',
        no_email: !!person.alerts_to_primary && !person.email,
        relationship: person.relationship,
        moved_in_on: person.moved_in_on,
        is_primary: person.is_primary,
    })
    // What is in the form now is where it starts, so closing it untouched asks nothing.
    editForm.defaults?.()
    editingId.value = person.id
}

const closeEdit = () => {
    editingId.value = null
    editForm.clearErrors()
}

/** The person being corrected, for the modal's title. */
const editingPerson = computed(() => props.people.find((person) => person.id === editingId.value) ?? null)

/** Why Save details cannot be pressed yet, said under it (M96 level 2). */
const editBlocked = computed(() => (editForm.full_name.trim() === '' ? 'A resident has a name.' : null))

/** The fields the form draws an error under; any other refusal is listed at its foot, so none goes unsaid. */
const EDIT_FIELDS = ['full_name', 'relationship', 'moved_in_on', 'email', 'phone', 'no_email', 'is_primary']

const otherEditErrors = computed(() => Object.entries(editForm.errors ?? {}).filter(([field]) => !EDIT_FIELDS.includes(field)))

const submitEdit = () => {
    if (editingId.value === null || editBlocked.value !== null) {
        return
    }

    editForm.post(`${root.value}/residents/${props.resident.unit_slug}/people/${editingId.value}`, {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    })
}

/* ------------------------------------------------------------------ */
/* ending a person's Resident App access — Q-M44 */
/* ------------------------------------------------------------------ */

/*
 * A SEPARATE PANEL FROM THE ONE ABOVE, AND THAT IS THE POINT. Correcting a
 * record and ending a login are different acts with different consequences, and
 * an office that could do the second by saving the first would do it by
 * accident. This panel takes a reason, states plainly what the person loses and
 * what the community keeps, and is undoable from the same place.
 *
 * WHAT THE GATE DOES WITH IT is the half the client asked for and it is not
 * decided here: the server cancels the person's e-pass and tells the guard, in
 * the guard's own words, that they are no longer a resident of this unit and go
 * to the Visitor Entrance. This screen says so, so the officer using it knows
 * what they have just done.
 */
const accessId = ref(null)

const accessForm = useForm({ reason: '' })

/** Only people who actually have a Resident App login — a register entry alone has nothing to end. */
const appPeople = computed(() => props.people.filter((person) => person.app_access.state !== 'none'))

const chosenAccess = computed(() => props.people.find((person) => person.id === accessId.value) ?? null)

/** Whether the modal offers a choice of person: opened from the household's own button, not from one person's row. */
const accessChooser = ref(false)

const openAccess = () => {
    if (!props.canEndAppAccess || appPeople.value.length === 0) {
        return
    }

    accessChooser.value = true
    chooseAccess(appPeople.value[0])
}

/** From the person's own row in the Resident App access panel: the modal opens for them alone. */
const openAccessFor = (person) => {
    if (!props.canEndAppAccess || !(person.app_access.can_end || person.app_access.can_restore)) {
        return
    }

    accessChooser.value = false
    chooseAccess(person)
}

const closeAccess = () => {
    accessId.value = null
    accessForm.clearErrors()
    accessForm.reason = ''
}

/** What the modal does for the person chosen: end their access, give it back, or nothing (they deleted it). */
const accessMode = computed(() => {
    const access = chosenAccess.value?.app_access

    if (!access) {
        return null
    }

    return access.can_end ? 'end' : access.can_restore ? 'restore' : 'gone'
})

const accessTitle = computed(() => {
    const person = chosenAccess.value

    if (person === null) {
        return ''
    }

    return accessMode.value === 'restore' ? `Give ${person.full_name} their Resident App access back?` : `End ${person.full_name}'s Resident App access?`
})

/** Why the modal's own button cannot be pressed yet, said under it (M96 level 2); null when it can. */
const accessBlocked = computed(() => {
    if (accessMode.value === 'gone') {
        return 'There is no access left to end or give back.'
    }

    return accessMode.value === 'end' && accessForm.reason.trim().length < 5 ? 'Say why, in a few words at least.' : null
})

const confirmAccess = () => {
    if (accessBlocked.value !== null) {
        return
    }

    if (accessMode.value === 'end') {
        submitRevoke()
    } else if (accessMode.value === 'restore') {
        submitRestore()
    }
}

const chooseAccess = (person) => {
    accessForm.clearErrors()
    accessForm.reason = ''
    accessId.value = person.id
}

const accessPath = (verb) => `${root.value}/residents/${props.resident.unit_slug}/people/${accessId.value}/app-access/${verb}`

const submitRevoke = () => {
    accessForm.post(accessPath('revoke'), {
        preserveScroll: true,
        onSuccess: () => closeAccess(),
    })
}

const submitRestore = () => {
    accessForm.post(accessPath('restore'), {
        preserveScroll: true,
        onSuccess: () => closeAccess(),
    })
}

/*
 * MESSAGE HOUSEHOLD (Hassam, 11 Oct: "Message household has no working path."). The console has no channel of its own
 * to one household (a notice goes to the estate or a phase; a message kept in the Resident App is M94 §2.3, not built),
 * so the working path is the office's own email: one link addressed to everyone in this household with an address, the
 * estate and the household in the subject. Nothing is sent by the console and nothing is recorded in it.
 */
const householdEmails = computed(() => [...new Set(props.people.map((person) => String(person.email ?? '').trim()).filter((email) => email !== ''))])

const messageHref = computed(() =>
    householdEmails.value.length === 0
        ? null
        : `mailto:${householdEmails.value.join(',')}?subject=${encodeURIComponent(`${props.estate.name}: ${props.resident.household_name ?? props.resident.name}`)}`
)

const NO_EMAIL_HERE = 'Nobody in this household has an email address on file. Add one with Edit details.'

/** Why the "End app access" button is disabled, in the words of whichever reason applies. */
const accessButtonReason = computed(() => {
    if (!props.canEndAppAccess) {
        return props.appAccessBlockedReason
    }

    if (appPeople.value.length === 0) {
        return 'Nobody in this household has a Resident App account, so there is no app access to end.'
    }

    // One line (Law 2): what it leaves alone is said in the modal, before anything is pressed.
    return 'End somebody\'s Resident App access, or give it back.'
})

/*
 * REMOVE THE HOUSEHOLD (M21 §3.1) — AUTHORED, like the two panels above. A family moved out: every member's app
 * account is deleted as the office deletes one, every member leaves the register, and the lot reads as vacant. It
 * can be undone from Residents → Deleted accounts for the estate's office window. The lot is typed to confirm.
 */
const removeOpen = ref(false)

// M32 F1: the household on this page, by id — a lot can hold more than one.
const removeForm = useForm({ confirm_lot: '', reason: '', household_id: props.resident.household_id ?? null })

const openRemove = () => {
    if (!props.canRemoveHousehold || !props.resident.household_name) {
        return
    }

    removeForm.clearErrors()
    removeForm.confirm_lot = ''
    removeForm.reason = ''
    removeForm.household_id = props.resident.household_id ?? null
    removeOpen.value = true
}

const closeRemove = () => {
    removeOpen.value = false
    removeForm.clearErrors()
}

const lotTyped = computed(() => removeForm.confirm_lot.trim().toLowerCase() === String(props.resident.unit_reference ?? '').trim().toLowerCase())

const removeReady = computed(() => lotTyped.value && removeForm.reason.trim().length >= 10)

/** Why Remove household cannot be pressed yet, said under it (M96 level 2); null when it can. */
const removeBlocked = computed(() => {
    if (!lotTyped.value) {
        return `Type the lot exactly: ${props.resident.unit_reference}.`
    }

    return removeForm.reason.trim().length < 10 ? 'Say why in a sentence.' : null
})

const submitRemove = () => {
    if (!removeReady.value) {
        return
    }

    removeForm.post(`${root.value}/residents/${props.resident.unit_slug}/household/remove`, {
        preserveScroll: true,
        onSuccess: () => closeRemove(),
    })
}

const removeButtonReason = computed(() => {
    if (!props.canRemoveHousehold) {
        return 'Removing a household is not part of your role.'
    }

    if (!props.resident.household_name) {
        return 'Nobody is on this lot, so there is no household to remove.'
    }

    // One line (Law 2): what is kept and how long it can be undone is said in the modal.
    return 'Take the whole household off this lot when it moves out.'
})

/*
 * Which board's stylesheet this page wears, and it is NOT the one boards 3 and
 * 4 wear despite all three being residents screens.
 *
 * The ten Community Admin sheets are grouped by the order the boards were
 * drawn in, not by module: board 38 sits in "Payroll Employees Details and
 * Billing" beside boards 37 and 40, because those three are the detail-and-
 * billing set. Every class this page uses — .hero-card, .hero-stats,
 * .stack-btn, .info-panel, .info-row2, .cross-link — is defined there and in no
 * other sheet, so naming the residents sheet left this screen with no board CSS
 * at all: it measured 29.46% as unstyled text with a full-page SVG under it.
 * Confirmed by searching the sheets for the board's own "Household members"
 * heading rather than inferred from the module.
 */
useWireframe('community-admin-10-payroll-employees-details-and-billing')

const page = usePage()

/*
 * WHAT BECAME OF THE INVITE, IN THE ROW IT IS ABOUT (household invites, stage 2, DESIGN §7.1). After Add
 * resident the office lands here, and the new person's invite row says what happened and is scrolled into
 * view and focused — never only a banner above a page the reader is not looking at.
 */
const inviteOutcome = computed(() => page.props.flash?.invite_outcome ?? null)

const outcomeFor = (person) =>
    inviteOutcome.value && String(inviteOutcome.value.resident_id) === String(person.id) ? inviteOutcome.value : null

onMounted(() => {
    const id = inviteOutcome.value?.resident_id

    if (id === undefined || id === null || typeof document === 'undefined') {
        return
    }

    const row = document.getElementById(`invite-row-${id}`)

    row?.scrollIntoView?.({ block: 'center' })
    row?.focus?.({ preventScroll: true })
})

/**
 * Five of the six. `empty` is a unit with no household on it —
 * `Residents::vacantStanding()` is what the server built this payload from —
 * and it is the one state real data can actually put this screen in: a lot the
 * boards address by reference rather than by resident can always be somebody
 * nobody has moved into. There is no filter on a detail screen, so
 * `empty-filtered` cannot occur from real data.
 */
const state = useScreenState({
    rows: () => props.members.length,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/residents/lot-47. Cutting the current URL at /residents is
 * correct in both.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/residents')))
const residentsPath = computed(() => `${root.value}/residents`)
const ledgerPath = computed(() => `${root.value}/finance/units/${props.resident.unit_id}`)

/*
 * ONE VOCABULARY (M96 §2, M98 pass), as the register says it: a verified household or person is Active, waiting on the
 * office is Pending; "Invited" and "Pending review" have no word of their own and read Pending, the closest.
 */
const STATE_WORDS = { active: 'Active', verified: 'Active', invited: 'Pending', pending: 'Pending' }

const stateWord = (state, label) => STATE_WORDS[state] ?? label

/** The hero's verification pill — green when verified, amber while pending. */
const verificationPillStyle = computed(() =>
    props.resident.verification === 'verified'
        ? 'font-size:10.5px;font-weight:700;color:var(--green-700);background:var(--success-100);padding:5px 11px;border-radius:20px;'
        : 'font-size:10.5px;font-weight:700;color:var(--amber-700);background:var(--amber-100);padding:5px 11px;border-radius:20px;'
)

/** Which glyph a Linked activity row draws, by its own `type`. */
const linkIcon = (type) => (type === 'ticket' ? 'ticket' : type === 'booking' ? 'booking' : 'ledger')
</script>

<template>
    <Head :title="resident.name" />

    <!-- A nested page: the way back to the register, in words, top left (M95 §3). -->
    <EstateConsole
        :title="resident.name"
        :estate-name="estate.name"
        active="residents"
        :back="{ href: residentsPath, label: 'Back to residents' }"
    >

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This household is not part of your role’s access"
            body="A resident's detail names who holds a household, what it owes where a role may see that, and what is linked to it, so it opens only to roles that hold Residents. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This household could not be read"
            body="The estate database did not answer. Nothing has been edited and no message has been sent. This is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No resident is on record at this unit"
            body="This lot has nobody on the register. A household is filed here the same way any other unit's is, through Add resident. What the unit itself owes is on its own ledger, addressed to the address rather than to a family that is not there."
            action-label="Back to Residents"
            @action="router.get(residentsPath)"
        />

        <template v-else>
            <!-- M32 F1: a lot holding more than one household shows one at a time; this moves between them. -->
            <div v-if="households_here.length > 1" class="subnav" role="tablist" aria-label="Households at this lot">
                <Link
                    v-for="h in households_here"
                    :key="h.id"
                    :href="`${root}/residents/${resident.unit_slug}${h.param ? `?household=${h.param}` : ''}`"
                    class="subnav-item"
                    :class="{ active: h.shown }"
                    role="tab"
                    :aria-selected="h.shown"
                >
                    {{ h.name }}<template v-if="h.holder"> · {{ h.holder }}</template>
                    <span v-if="h.sample" class="status-badge draft" title="Held by the demonstration seed's placeholder, not a person the estate added">sample</span>
                </Link>
            </div>

            <div class="detail-head res-detail-head">
                <div class="hero-card">
                    <div class="hero-top">
                        <div>
                            <div class="hero-name">
                                {{ resident.name }}
                                <span v-if="resident.sample" class="status-badge draft" title="This household is held by the demonstration seed's placeholder, not a person the estate added">sample</span>
                            </div>
                            <div class="hero-sub">{{ resident.sub }}</div>
                        </div>
                        <div class="res-verification" :style="verificationPillStyle">{{ stateWord(resident.verification, resident.verification_label) }}</div>
                    </div>
                    <div class="hero-stats">
                        <div v-for="stat in stats" :key="stat.key" class="hero-stat">
                            <div class="hs-v" :class="{ 'hs-v-amber': stat.key === 'balance' && stat.tone === 'amber' }">
                                {{ stat.value ?? '—' }}
                            </div>
                            <div class="hs-l">{{ stat.label }}</div>
                        </div>
                    </div>
                </div>

                <div class="action-stack res-action-stack">
                    <Link v-if="canViewLedger" :href="ledgerPath" class="stack-btn primary">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="6" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M2 10h20" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>View ledger</span>
                    </Link>

                    <!--
                      MESSAGE HOUSEHOLD, A WORKING PATH (Hassam, 11 Oct, from the client's clicking: "Message household
                      has no working path."). It opens the office's own email, addressed to everyone in this household
                      with an address; level 2 while nobody here has one, the reason under the stack. A role that can
                      never correct, end access or remove has none of those buttons (M96 level 3).
                    -->
                    <a v-if="messageHref" :href="messageHref" class="stack-btn outline" title="Email everyone in this household from your own email.">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linejoin="round"
                            />
                            <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <span>Message household</span>
                    </a>
                    <button v-else type="button" class="stack-btn outline" disabled title="Nobody in this household has an email address on file.">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linejoin="round"
                            />
                            <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <span>Message household</span>
                    </button>

                    <button
                        v-if="canEdit && people.length > 0"
                        type="button"
                        class="stack-btn outline"
                        title="Correct a person's name, contact details, relationship or move-in date."
                        @click="openEdit"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                            <path
                                d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>Edit details</span>
                    </button>

                    <!--
                      Q-M44. Its own control, never a field on the edit form:
                      this signs a person out on every phone, deletes every
                      address a notification could reach them at, cancels the
                      e-pass a guard admits them on, and makes them a visitor
                      at this estate's gate.
                    -->
                    <!--
                      Level 2 while nobody here has an app account: greyed, the reason on the card under the stack. With
                      one account it is not drawn (the hub's finding, 12 Oct): it opened the very question that person's
                      own End access on their row asks, two buttons for one act. It is for choosing among several.
                    -->
                    <button
                        v-if="canEndAppAccess && appPeople.length !== 1"
                        type="button"
                        class="stack-btn outline stack-danger"
                        :disabled="appPeople.length === 0"
                        :title="accessButtonReason"
                        @click="openAccess"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M8 10V7.5a4 4 0 0 1 7-2.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <span>End app access</span>
                    </button>

                    <button
                        v-if="canRemoveHousehold && resident.household_name"
                        type="button"
                        class="stack-btn outline stack-danger"
                        :title="removeButtonReason"
                        @click="openRemove"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            <path d="M9 15h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <span>Remove household</span>
                    </button>

                    <!-- Why Message household and End app access wait, on the card: a tablet has no hover (M96 level 2). -->
                    <p v-if="!messageHref" class="res-why">{{ NO_EMAIL_HERE }}</p>
                    <p v-if="canEndAppAccess && appPeople.length === 0" class="res-why">{{ accessButtonReason }}</p>
                </div>
            </div>


            <!--
              Remove the household, in the console's modal (M95 §1). The lot is typed to confirm and the reason goes on the
              record; until both are given, the greyed button says which is missing.
            -->
            <ConsoleDialog
                :open="removeOpen"
                :title="`Remove ${resident.household_name} from ${resident.unit_reference}?`"
                size="form"
                tone="danger"
                confirm-label="Remove household"
                busy-label="Removing…"
                :busy="removeForm.processing"
                :dirty="removeForm.confirm_lot.trim() !== '' || removeForm.reason.trim() !== ''"
                discard-title="Discard what you typed?"
                :confirm-blocked-reason="removeBlocked"
                :error="removeForm.errors.remove ?? null"
                @confirm="submitRemove"
                @cancel="closeRemove"
            >
                <p class="res-head">
                    The household has moved out. This is every member at once; to take one person off, correct their
                    record instead.
                </p>

                <ul class="acc-list">
                    <li>Every member's Resident App account is deleted, as the office deletes one: signed out on every phone, e-pass cancelled, no notification can reach them. Each is emailed.</li>
                    <li>Every member leaves the register, and the lot reads as vacant. At the gate, a guard finds nobody living here.</li>
                    <li>Visitor passes they issued that have not been used, and their future bookings, are cancelled; a held deposit is refunded.</li>
                </ul>

                <div class="acc-keep">
                    What the estate keeps: everything the lot owes and has paid, its ledger, its gate history, incidents,
                    ballots and the audit trail. The household can be restored from Residents → Deleted accounts for the
                    estate's restore window; after that, nothing of the people is left, and no row is dropped.
                </div>

                <RequiredMark legend />
                <div class="res-fields">
                    <div class="res-field">
                        <label for="rm-lot">Type the lot to confirm: {{ resident.unit_reference }} <RequiredMark /></label>
                        <input id="rm-lot" v-model="removeForm.confirm_lot" type="text" required maxlength="64" autocomplete="off" />
                        <div v-if="removeForm.errors.confirm_lot" class="res-error">{{ removeForm.errors.confirm_lot }}</div>
                    </div>
                    <div class="res-field res-field--wide">
                        <label for="rm-reason">Why (this goes on the record beside your name) <RequiredMark /></label>
                        <input id="rm-reason" v-model="removeForm.reason" type="text" required minlength="10" maxlength="300" placeholder="e.g. Sold the property; keys handed over on the 14th" />
                        <div v-if="removeForm.errors.reason" class="res-error">{{ removeForm.errors.reason }}</div>
                    </div>
                </div>
            </ConsoleDialog>

            <!--
              AUTHORED. The board draws a household nobody is correcting, so it
              has no panel. Verification is absent from it on purpose, and the
              head says so rather than leaving a reader to wonder.
            -->
            <ConsoleDialog
                :open="editingId !== null"
                :title="editingPerson ? `Correct ${editingPerson.full_name}'s record` : 'Correct a record'"
                size="form"
                confirm-label="Save details"
                busy-label="Saving…"
                :busy="editForm.processing"
                :dirty="editForm.isDirty"
                discard-title="Discard these changes?"
                :confirm-blocked-reason="editBlocked"
                @confirm="submitEdit"
                @cancel="closeEdit"
            >
                <p class="res-head">
                    This changes who somebody is, not whether they are authorised: verification is decided where a claim
                    is reviewed, with a name and a date on the decision.
                </p>

                <div v-if="people.length > 1" class="res-people">
                    <button
                        v-for="person in people"
                        :key="person.id"
                        type="button"
                        class="res-person"
                        :class="{ active: person.id === editingId }"
                        :title="`Correct ${person.full_name}. ${stateWord(String(person.status_label ?? '').toLowerCase(), person.status_label)}.`"
                        @click="choosePerson(person)"
                    >
                        {{ person.panel_name }}<template v-if="person.sample"> · sample</template>
                    </button>
                </div>

                <!-- Each refusal under its own field (M95 §1); EST-B-03a's rule stands: every refusal is said. -->
                <RequiredMark legend />
                <div class="res-fields">
                    <div class="res-field res-field--wide">
                        <label for="rp-name">Name <RequiredMark /></label>
                        <input id="rp-name" v-model="editForm.full_name" type="text" required maxlength="120" />
                        <div v-if="editForm.errors.full_name" class="res-error">{{ editForm.errors.full_name }}</div>
                    </div>
                    <div class="res-field">
                        <label for="rp-rel">Relationship to the household <RequiredMark /></label>
                        <input id="rp-rel" v-model="editForm.relationship" type="text" required maxlength="40" placeholder="e.g. owner" />
                        <div v-if="editForm.errors.relationship" class="res-error">{{ editForm.errors.relationship }}</div>
                    </div>
                    <div class="res-field">
                        <label for="rp-moved">Moved in (optional)</label>
                        <input id="rp-moved" v-model="editForm.moved_in_on" type="date" />
                        <div v-if="editForm.errors.moved_in_on" class="res-error">{{ editForm.errors.moved_in_on }}</div>
                    </div>
                    <div class="res-field">
                        <label for="rp-email">Email <RequiredMark v-if="!editForm.no_email" /></label>
                        <input id="rp-email" v-model="editForm.email" type="email" maxlength="160" placeholder="e.g. marcia.brown@gmail.com" :required="!editForm.no_email" :disabled="editForm.no_email" />
                        <div v-if="editForm.errors.email" class="res-error">{{ editForm.errors.email }}</div>
                    </div>
                    <div class="res-field">
                        <label for="rp-phone">Phone <RequiredMark /></label>
                        <input id="rp-phone" v-model="editForm.phone" type="tel" maxlength="40" :placeholder="`e.g. ${page.props.phoneExample?.local ?? ''}`" required />
                        <div v-if="editForm.errors.phone" class="res-error">{{ editForm.errors.phone }}</div>
                    </div>
                </div>

                <label v-if="!editForm.is_primary" class="res-check">
                    <input v-model="editForm.no_email" type="checkbox" @change="editForm.no_email && (editForm.email = '')" />
                    <span>This person has no email; alerts go to the primary resident instead</span>
                </label>
                <div v-if="editForm.errors.no_email" class="res-error">{{ editForm.errors.no_email }}</div>

                <label class="res-check">
                    <input v-model="editForm.is_primary" type="checkbox" />
                    <span>
                        Primary contact for this household. A household has one, so ticking this moves it off whoever
                        holds it now.
                    </span>
                </label>
                <div v-if="editForm.errors.is_primary" class="res-error">{{ editForm.errors.is_primary }}</div>

                <!-- A refusal for a field this form does not draw, said all the same (EST-B-03a). -->
                <div v-for="[field, message] in otherEditErrors" :key="field" class="res-error">{{ message }}</div>
            </ConsoleDialog>

            <!--
              AUTHORED — Q-M44. The board draws nothing of this; it is built
              from the edit panel's own components so the two read as one
              screen. It says what the person loses, what the community keeps,
              and what the gate will do, before anything is pressed.
            -->
            <ConsoleDialog
                :open="accessId !== null && chosenAccess !== null"
                :title="accessTitle"
                size="form"
                :tone="accessMode === 'end' ? 'danger' : 'primary'"
                :confirm-label="accessMode === 'restore' ? 'Give access back' : 'End app access'"
                :busy-label="accessMode === 'restore' ? 'Restoring…' : 'Ending…'"
                :busy="accessForm.processing"
                :dirty="accessForm.reason.trim() !== ''"
                discard-title="Discard this reason?"
                :confirm-blocked-reason="accessBlocked"
                @confirm="confirmAccess"
                @cancel="closeAccess"
            >
                <p class="res-head">
                    Ending app access is not the same as taking somebody off the register. This ends a LOGIN; whether
                    they still live here is a separate decision, made where it carries a name and a date.
                </p>

                <div v-if="accessChooser && appPeople.length > 1" class="res-people">
                    <button
                        v-for="person in appPeople"
                        :key="person.id"
                        type="button"
                        class="res-person"
                        :class="{ active: person.id === accessId }"
                        :title="`${person.full_name} · ${person.app_access.label}.`"
                        @click="chooseAccess(person)"
                    >
                        {{ person.panel_name }}
                    </button>
                </div>

                <div v-if="chosenAccess" class="acc-state">{{ chosenAccess.full_name }} · {{ chosenAccess.app_access.label }}</div>

                <template v-if="accessMode === 'end'">
                    <ul class="acc-list">
                        <li>Every phone they are signed in on loses access at once.</li>
                        <li>Every address a notification could reach them at is deleted, and any sign-in code already sent stops working.</li>
                        <li>Their e-pass is cancelled, so a guard cannot admit them on it.</li>
                        <li>They can no longer issue a visitor pass to this gate, or answer a guard at the door.</li>
                        <li>
                            At the gate they are a VISITOR. A guard who searches this unit or scans their old pass is told
                            they are no longer a resident of {{ resident.sub }} and to direct them to the Visitor Entrance.
                        </li>
                    </ul>

                    <div class="acc-keep">
                        What the community keeps: their entry on the register, the gate history, this household's charges,
                        payments and receipts, and the audit trail. A revocation is not a deletion, and it can be undone here.
                    </div>

                    <RequiredMark legend />
                    <div class="res-field res-field--wide">
                        <label for="acc-reason">Why (this goes on the record beside your name) <RequiredMark /></label>
                        <input
                            id="acc-reason"
                            v-model="accessForm.reason"
                            type="text"
                            required
                            minlength="5"
                            maxlength="190"
                            placeholder="e.g. Sold the property, handover on the 14th"
                        />
                        <div v-if="accessForm.errors.reason" class="res-error">{{ accessForm.errors.reason }}</div>
                    </div>
                </template>

                <template v-else-if="accessMode === 'restore'">
                    <div class="acc-keep">
                        Ended {{ chosenAccess.app_access.revoked_at }} by {{ chosenAccess.app_access.revoked_by }}:
                        “{{ chosenAccess.app_access.revoked_reason }}”. Giving it back lets them sign in again on their
                        own phone and puts them back where they were; their e-pass is issued fresh the first time they open it.
                    </div>
                    <div v-if="accessForm.errors.reason" class="res-error">{{ accessForm.errors.reason }}</div>
                </template>

                <div v-else-if="chosenAccess" class="acc-keep">
                    {{ chosenAccess.full_name }} deleted this account themselves, so there is no access left to end or to
                    give back. Signing up again makes a new account, and the estate approves that claim like any other.
                </div>
            </ConsoleDialog>

            <div class="res-columns">
                <div>
                    <div class="info-panel">
                        <div class="info-panel-head">Household members <InfoButton topic="householdRoles" /></div>
                        <div v-for="row in members" :key="row.label" class="info-row2">
                            <span>
                                {{ row.label }}
                                <span v-if="row.sample" class="status-badge draft" title="Sample data.">sample</span>
                            </span>
                            <span>{{ row.value }}</span>
                        </div>
                    </div>
                    <div class="info-panel">
                        <div class="info-panel-head">Contact</div>
                        <div v-for="row in contact" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span><span>{{ row.value }}</span>
                        </div>
                    </div>

                    <!--
                      AUTHORED — Q-M44. Drawn only when somebody here has a
                      Resident App login, the same "absent rather than blanked"
                      rule the ledger link follows: a panel of "no account" for
                      every household tells a reader nothing.
                    -->
                    <!--
                      THE ACT BESIDE THE PERSON IT IS ABOUT (Hassam, 11 Oct, from the client's clicking: "Ending a
                      resident's app access has no clear path"). Each person's row says what their access is and carries
                      its own End access, or Give access back once the office has ended it, opening the modal for that
                      person. Somebody who deleted their own account has nothing left to end: no button (M96 level 3),
                      and the row says so. In the invites panel's row layout below.
                    -->
                    <div v-if="appPeople.length > 0" class="info-panel">
                        <div class="info-panel-head">Resident App access</div>
                        <div v-for="person in appPeople" :key="`app-${person.id}`" class="invite-row">
                            <div class="invite-who">
                                <div class="invite-name">{{ person.full_name }}</div>
                                <div class="invite-line" :class="{ 'acc-ended': person.app_access.state === 'revoked' }">{{ person.app_access.label }}</div>
                            </div>
                            <div v-if="canEndAppAccess && (person.app_access.can_end || person.app_access.can_restore)" class="invite-acts">
                                <button
                                    type="button"
                                    class="btn btn-outline invite-btn"
                                    :class="{ 'invite-btn-danger': person.app_access.can_end }"
                                    :title="person.app_access.can_end ? `End ${person.full_name}'s Resident App access.` : `Give ${person.full_name} their Resident App access back.`"
                                    @click="openAccessFor(person)"
                                >
                                    {{ person.app_access.can_end ? 'End access' : 'Give access back' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!--
                      e-Passes (M98, e-Pass management): the pass a guard admits each person on, in one line each. The
                      office manages it through app access above: End access cancels it at every gate at once, Give
                      access back lets it be issued again. A Helper's follows the household's days and hours.
                    -->
                    <div v-if="people.length > 0" class="info-panel">
                        <div class="info-panel-head">e-Passes</div>
                        <div v-for="person in people" :key="`epass-${person.id}`" class="invite-row">
                            <div class="invite-who">
                                <div class="invite-name">{{ person.full_name }}</div>
                                <div class="invite-line">{{ person.epass?.line ?? 'No e-Pass: not on the Resident App' }}</div>
                            </div>
                            <span class="epass-state" :class="`is-${person.epass?.state ?? 'none'}`">{{ EPASS_WORDS[person.epass?.state ?? 'none'] }}</span>
                        </div>
                        <p class="epass-note">Ending a person's app access cancels their e-Pass at every gate at once.</p>
                    </div>

                    <!--
                      AUTHORED — invites to the Resident App, for everybody still Pending. What became of
                      each is the server's own sentence; Resend counts down the server's timer.
                    -->
                    <div v-if="pendingPeople.length > 0" class="info-panel">
                        <div class="info-panel-head">Invites to the Resident App</div>
                        <div
                            v-for="person in pendingPeople"
                            :id="`invite-row-${person.id}`"
                            :key="`invite-${person.id}`"
                            class="invite-row"
                            :class="{ 'invite-row-outcome': outcomeFor(person) }"
                            :tabindex="outcomeFor(person) ? -1 : undefined"
                        >
                            <div class="invite-who">
                                <div class="invite-name">{{ person.full_name }}</div>
                                <div class="invite-line" :class="{ 'invite-bad': person.invite?.status === 'not_sent' }" role="status">{{ inviteLine(person) }}</div>
                                <div v-if="outcomeFor(person)" class="invite-line invite-outcome" :class="{ 'invite-bad': outcomeFor(person).state !== 'sent' }" role="status">
                                    {{ outcomeFor(person).message }}
                                </div>
                            </div>
                            <!--
                              M96 levels (M98 pass): a role that can never send an invite has no button (level 3); one
                              just sent is greyed and its label counts down the wait, on the screen (level 2).
                            -->
                            <div v-if="canInvite && person.email && !['claimed'].includes(person.invite?.status)" class="invite-acts">
                                <button
                                    type="button"
                                    class="btn btn-outline invite-btn"
                                    :disabled="!canSendNow(person)"
                                    :aria-disabled="!canSendNow(person)"
                                    :title="waitFor(person) > 0 ? 'It was just sent. It can go again in a few minutes.' : `Email ${person.email} an invite to the Resident App.`"
                                    @click="sendInvite(person)"
                                >
                                    {{ inviteLabel(person) }}
                                </button>
                                <button
                                    v-if="['sent', 'not_sent'].includes(person.invite?.status)"
                                    type="button"
                                    class="invite-cancel"
                                    @click="openCancelInvite(person)"
                                >
                                    Cancel invite
                                </button>
                            </div>
                        </div>
                    </div>

                    <!--
                      AUTHORED — what each person may do in the Resident App. The server checks it on every
                      request; a change here is confirmed in a dialog that says exactly what it takes away.
                    -->
                    <div v-if="people.length > 0 && accessLevels.length > 0" class="info-panel">
                        <div class="info-panel-head">Resident App access levels</div>
                        <p v-if="!levelsOpen" class="level-closed">{{ levelsClosedReason }}</p>
                        <!-- What each level means, said ONCE above the list (M53 Law 2), never repeated under every person. -->
                        <ul class="level-legend">
                            <li v-for="level in accessLevels" :key="`legend-${level.value}`"><strong>{{ level.label }}:</strong> {{ level.sentence }}</li>
                        </ul>
                        <div v-for="person in people" :key="`level-${person.id}`" class="invite-row">
                            <div class="invite-who">
                                <div class="invite-name">{{ person.full_name }}</div>
                                <div v-if="person.is_primary" class="invite-line">Primary resident</div>
                            </div>
                            <div class="invite-acts">
                                <!--
                                  M96 levels (M98 pass): the primary resident is always Member, and a role that can never
                                  change a level sees the level as a fact, not a greyed select (level 3). While levels are
                                  closed, the select is greyed and why is said above the list (level 2).
                                -->
                                <select
                                    v-if="canChangeLevel && !person.is_primary"
                                    class="level-select"
                                    :value="person.access_level"
                                    :aria-label="`Resident App access for ${person.full_name}`"
                                    :disabled="levelForm.processing || levelChoices(person).length < 2"
                                    :title="levelTitle(person)"
                                    @change="chooseLevel(person, $event)"
                                >
                                    <option v-for="level in levelChoices(person)" :key="level.value" :value="level.value">{{ level.label }}</option>
                                </select>
                                <span v-else class="level-fixed">{{ person.is_primary ? 'Member (primary)' : levelOf(person.access_level)?.label ?? person.access_level }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <div v-if="linked.length > 0" class="info-panel">
                        <div class="info-panel-head">Linked activity</div>
                        <div v-for="link in linked" :key="link.type + link.title" class="cross-link">
                            <svg v-if="linkIcon(link.type) === 'ledger'" viewBox="0 0 24 24" fill="none">
                                <rect x="2" y="6" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.6" />
                                <path d="M2 10h20" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                            <svg v-else-if="linkIcon(link.type) === 'ticket'" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M12 3v18M4 9c0-3.3 3.6-6 8-6s8 2.7 8 6"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linecap="round"
                                />
                                <path d="M4 9h16" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                            <div class="cl-txt">
                                <div class="cl1">{{ link.title }}</div>
                                <div class="cl2">{{ link.sub }}</div>
                            </div>
                        </div>
                    </div>

                    <!--
                      Absent rather than blanked follows through to the panel
                      itself: a household with nothing linked (no arrears, no
                      open ticket, no booking) draws no empty "Linked activity"
                      card either, which is the board's own logic taken one
                      step further rather than a state the board had to draw.
                    -->
                    <div v-else class="info-panel">
                        <div class="info-panel-head">Linked activity</div>
                        <p class="no-linked-activity">Nothing is currently linked to this household.</p>
                    </div>
                </div>
            </div>

            <!--
              Who has lived here (M98, ownership and tenancy history): every household the register has held at this
              lot, those here now first, with how it was recorded, when it moved in, and when and why it moved out. Read
              only: a household moves in with Add resident and out with Remove household.
            -->
            <div class="info-panel res-history">
                <div class="info-panel-head">Who has lived here</div>
                <p v-if="history.length === 0" class="no-linked-activity">No household has been recorded at this lot.</p>
                <ol v-else class="res-history-list">
                    <li v-for="entry in history" :key="entry.id" class="res-history-row">
                        <div class="res-history-main">
                            <div class="res-history-name">{{ entry.name }}</div>
                            <div class="res-history-sub">{{ historyLine(entry) }}</div>
                            <div v-if="entry.reason" class="res-history-sub">{{ entry.reason }}</div>
                        </div>
                        <span class="res-history-state" :class="{ 'is-current': entry.current }">
                            <!-- A past household with no move-out date says so in words, never "Moved out null" (batch 1 sweep). -->
                            {{ entry.current ? 'Living here' : entry.moved_out ? `Moved out ${entry.moved_out}` : 'Moved out' }}
                        </span>
                    </li>
                </ol>
            </div>

            <!-- DR-069: who is told, and how, before it goes. -->
            <ConsoleDialog
                :open="inviting !== null"
                :title="inviteTitle"
                :confirm-label="isResend(inviting) ? 'Resend invite' : 'Send invite'"
                busy-label="Sending…"
                :busy="inviteForm.processing"
                :error="inviteRefusal"
                @confirm="postInvite"
                @cancel="closeInvite"
            >
                <p v-if="inviting" style="margin: 0">An email to {{ inviting.email }}, with the link to join the Resident App. Nobody else is told.</p>
            </ConsoleDialog>

            <ConsoleDialog
                :open="cancelling !== null"
                :title="cancelling ? `Cancel ${cancelling.full_name}'s invite?` : ''"
                tone="danger"
                :confirm-label="cancelling ? `Cancel ${cancelling.full_name}'s invite` : 'Cancel invite'"
                cancel-label="Keep it"
                busy-label="Cancelling…"
                :busy="cancelInviteForm.processing"
                :error="cancelInviteForm.errors.invite ?? Object.values(cancelInviteForm.errors)[0] ?? null"
                @confirm="confirmCancelInvite"
                @cancel="cancelling = null"
            >
                <p style="margin: 0">
                    They stay on the register as Pending. The email they were sent no longer leads anywhere new, and you can
                    send a fresh invite from here at any time.
                </p>
            </ConsoleDialog>

            <ConsoleDialog
                :open="levelChange !== null"
                :title="levelChange ? `Change ${levelChange.person.full_name} to ${levelLabel(levelChange.to)}?` : ''"
                :tone="levelNarrows ? 'danger' : 'primary'"
                :confirm-label="levelChange ? `Change to ${levelLabel(levelChange.to)}` : 'Change'"
                :cancel-label="levelChange ? `Keep ${levelLabel(levelChange.from)}` : 'Cancel'"
                busy-label="Changing…"
                :busy="levelForm.processing"
                :error="levelForm.errors.access_level ?? null"
                @confirm="confirmLevel"
                @cancel="levelChange = null"
            >
                <p style="margin: 0">{{ levelSentence }}</p>
            </ConsoleDialog>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its back affordance and its three action-stack buttons as
 * <div>s; here they are a link and buttons, each arriving with the browser's
 * own font, border and buttonface grey. .stack-btn.primary declares a
 * background and no border; .stack-btn.outline declares both, and its border
 * IS the variant, so only the primary style is reset — see D-045.
 */
a.stack-btn.primary {
    text-decoration: none;
    border: 0;
}

/* Message household is a link to the office's own email, in the outline button's shape. */
a.stack-btn.outline {
    text-decoration: none;
}

/*
 * HASSAM'S RED RULE (11 Oct, final): solid saturated red is an open panic's alone; a destructive control is a red
 * outline, serious and not an emergency; errors and refusals are amber. End app access, Remove household, a person's
 * End access and Cancel invite are the destructive ones on this page.
 */
button.stack-btn.outline.stack-danger {
    border-color: var(--red-700);
}

button.stack-btn.outline.stack-danger span,
button.stack-btn.outline.stack-danger svg {
    color: var(--red-700);
}

.invite-btn.invite-btn-danger {
    border-color: var(--red-700);
    color: var(--red-700);
}

/* M96 level 2: a greyed outline button looks greyed; the board's has no disabled look, so it read as live. */
button.stack-btn.outline[disabled] {
    opacity: 0.55;
}

/* The household's two columns, side by side as the board draws them (they were an inline style). */
.res-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/*
 * ON A PHONE (seen at 390 px on the dev server, 12 Oct): the hero and its actions, and the two columns, one under
 * another. Side by side they made the page wider than the phone.
 */
@media (max-width: 640px) {
    .detail-head.res-detail-head {
        flex-direction: column;
    }

    .action-stack.res-action-stack {
        width: auto;
    }

    .res-columns {
        grid-template-columns: 1fr;
    }

    .res-history-row {
        flex-wrap: wrap;
    }
}

/* e-Passes (M98): each person's state as a pill, Active green, Paused amber, Cancelled and None slate; never red. */
.epass-state {
    flex: 0 0 auto;
    align-self: center;
    font-size: 10.5px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--slate-600);
    white-space: nowrap;
}

.epass-state.is-active {
    background: var(--green-100);
    color: var(--green-700);
}

.epass-state.is-paused {
    background: var(--amber-100);
    color: var(--amber-700);
}

.epass-note {
    margin: 10px 0 0;
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.5;
}

/* Who has lived here (M98): a line a household, those here now first; Living here in green, a move out in slate. */
.info-panel.res-history {
    margin-top: 16px;
}

.res-history-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.res-history-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    border-top: 1px solid var(--navy-100);
}

.res-history-row:first-child {
    border-top: 0;
    padding-top: 2px;
}

.res-history-main {
    min-width: 0;
}

.res-history-name {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.res-history-sub {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.5;
}

.res-history-state {
    flex: 0 0 auto;
    font-size: 10.5px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--slate-600);
    white-space: nowrap;
}

.res-history-state.is-current {
    background: var(--green-100);
    color: var(--green-700);
}

button.stack-btn.outline {
    font: inherit;
    cursor: pointer;
}

button.stack-btn.outline[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no amber balance and no empty
 * "Linked activity" panel — Andrea Fletcher's is neither restricted nor
 * unlinked. Kept to the tokens the board does define: .hs-v is what the amber
 * variant overrides, and the empty note borrows .info-row2's own colour.
 */
.hs-v-amber {
    color: var(--amber-300);
}

.no-linked-activity {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.6;
    padding: 4px 0;
}

button.res-person {
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/* An action that cannot be used yet looks it, and its reason is on the card, under the stack (M96 level 2). */
button.stack-btn.outline[disabled] {
    opacity: 0.55;
}

.res-why {
    margin: 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-500);
}

/*
 * The three forms, which the board draws nothing of, are the console's modal's body now (M95 §1, M98 pass); a refusal
 * with no field is a toast or the modal's own line.
 */
.res-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    margin: 0 0 12px;
}

.res-people {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-bottom: 11px;
}

.res-person {
    font-size: 11px;
    font-weight: 600;
    color: var(--slate-600);
    background: var(--navy-100);
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 5px 10px;
    line-height: 1.5;
}

.res-person.active {
    color: var(--navy-900);
    background: var(--white);
    border-color: var(--navy-200);
}

.res-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 11px;
}

/* The modal is the whole screen on a phone: one field a row there. */
@media (max-width: 640px) {
    .res-fields {
        grid-template-columns: 1fr;
    }

    .res-field--wide {
        grid-column: auto;
    }
}

.res-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.res-field--wide {
    grid-column: span 2;
}

.res-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.res-field input {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.res-check {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.55;
    cursor: pointer;
    margin-bottom: 9px;
}

.res-check input {
    margin: 3px 0 0;
    flex: 0 0 auto;
}

.res-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}

/*
 * The app-access panel — Q-M44. Board tokens only: the same navy and slate the
 * edit panel above is built from, and the amber the rest of this console uses
 * where something needs reading before it is pressed.
 */
.acc-state {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
    margin-bottom: 9px;
}

.acc-list {
    margin: 0 0 11px;
    padding: 0 0 0 16px;
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.7;
}

.acc-keep {
    font-size: 11px;
    line-height: 1.6;
    color: var(--slate-600);
    background: var(--navy-100);
    border-radius: 10px;
    padding: 9px 11px;
    margin-bottom: 11px;
}

.acc-ended {
    color: var(--amber-700);
    font-weight: 700;
}

/* Invites to the Resident App — authored, in the panel's own row rhythm and the console's tokens. */
.invite-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--navy-100);
}

.invite-row:last-of-type {
    border-bottom: 0;
}

.invite-who {
    min-width: 0;
}

.invite-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--navy-900);
}

.invite-line {
    font-size: 12px;
    color: var(--slate-600);
    margin-top: 2px;
}

.invite-bad {
    color: var(--amber-700);
}

/* The row Add resident just acted on: marked, so the eye lands on it after the scroll. */
.invite-row-outcome {
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 12px;
    outline: 0;
}

.invite-outcome {
    font-weight: 600;
}

.invite-acts {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    flex: 0 0 auto;
}

/*
 * The rows' own buttons (Resend invite, End access, Give access back), in the board's outline-button shape. They wore
 * `btn btn-outline`, which board 38's sheet does not define, so they drew as the browser's grey buttons (seen on the
 * dev server, 11 Oct).
 */
.invite-btn {
    min-width: 132px;
    height: 34px;
    padding: 0 14px;
    border: 1.5px solid var(--navy-200);
    border-radius: 10px;
    background: var(--white);
    color: var(--navy-700);
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    font-variant-numeric: tabular-nums;
}

.invite-btn[disabled] {
    cursor: not-allowed;
    opacity: 0.6;
}

.invite-cancel {
    border: 0;
    background: transparent;
    padding: 0;
    font: inherit;
    font-size: 12px;
    /* Destructive, so muted red (Hassam's red rule, final); it was amber for a few hours tonight. */
    color: var(--red-700);
    cursor: pointer;
    text-decoration: underline;
}

/* Access levels — the invite rows' own rhythm, and the edit panel's field look for the select. */
.level-select {
    height: 33px;
    min-width: 132px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 8px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
    cursor: pointer;
}

.level-select[disabled] {
    cursor: not-allowed;
    opacity: 0.7;
}

.level-closed {
    margin: 0 0 8px;
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.5;
}

/* The three levels, once, in the same quiet type as the line above. */
.level-legend {
    margin: 0 0 10px;
    padding: 0;
    list-style: none;
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.5;
}

/* A level that can never be changed here, said as a fact in the select's own type (M96 level 3). */
.level-fixed {
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-900);
}

/*
 * M32 F1: the `sample` chip on a demonstration placeholder — the Gemini console's own `.status-badge.draft`, lifted
 * unchanged, because this board's sheet defines no `.draft`. Here and not in app.css: app.css is one of the sources the
 * classic Roster and Sites pages are pinned to (M30 §13).
 */
.status-badge.draft {
    background: var(--navy-100);
    color: var(--navy-700);
}
</style>
