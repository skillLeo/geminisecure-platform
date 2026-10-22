<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'
// Where the Roster tab opens (M35 §1), so "Open the Roster" lands where the tab does.
import { ROSTER_HREF } from '../../../lib/workforceTabs.js'
import { toast } from '../../../lib/toasts.js'

/**
 * Guard profile — board screens super-admin-19 AND super-admin-23.
 *
 * ONE component, two boards. 19 draws Marcus Whyte, who is compliant; 23 draws
 * Devon Palmer, whose PSRA licence has lapsed. The difference between those two
 * boards is not styling and is not a second component — it is two FEATURE
 * STATES the record itself decides:
 *
 *   UN-ROSTERABLE          the Build Spec: "Expired licence sets un-rosterable
 *                          — a hard block, not a warning." The hero takes the
 *                          board's own .suspended treatment, the licence stat
 *                          stops promising a future expiry, and the reassign
 *                          control is GONE rather than offered and refused.
 *                          Offering to post an unlicensed officer somewhere
 *                          else is the one thing this screen must not do.
 *
 *   OPEN COMPLIANCE CASE   the timeline becomes the case file. Compliance
 *                          events lead it, because someone opening this record
 *                          has to know the officer cannot legally be on post
 *                          before they read which post that is, and the panel
 *                          heading admits they are there.
 *
 * D-034: an expired licence is exactly what a client should be able to see
 * about the guard standing at their gate, so none of this is softened.
 *
 * DOM and class names are the board's, including the inline grid style on the
 * two-column row, which is the board's own and is kept inline rather than
 * promoted to a class the design does not define.
 *
 * Two of the board's values have no column behind them and are not invented
 * here: the hero's standard hourly rate is replaced by the gross on the guard's
 * most recent payslip, and the employment panel's bank account and NIS number
 * by the employee number and email that the record actually holds.
 *
 * That gross is a payslip amount, so it reaches only a role holding Payroll &
 * Accounting (M10 §5). For anyone else the server leaves the stat out of
 * `guard.stats` altogether and the hero draws the three that are left — there
 * is nothing here to hide, because nothing arrived.
 *
 * The status pill uses the board's own .status-badge classes rather than the
 * hard-coded colour inline style the board carries. That style states one
 * status whatever the guard's really is, which on the wrong officer would be a
 * lie told in the most prominent place on the screen.
 */
const props = defineProps({
    guard: { type: Object, required: true },
    history: { type: Array, required: true },
    /** The clients and posts this officer can be moved to (12 §2, Wave 5). */
    clientOptions: { type: Array, default: () => [] },
    postOptions: { type: Array, default: () => [] },
    canAct: { type: Boolean, default: false },
    actBlockedReason: { type: String, default: '' },
    /** A handset waiting to replace this officer's bound one (13 D1). */
    rebindRequests: { type: Array, default: () => [] },
    /**
     * Whether this officer's phone can ring like a call (the guard ring §9.2): the
     * server's reading of the phone's own last report, each check ON or OFF, when it
     * was reported, and whether a test ring proved it. `Services\Ring\RingReport`.
     */
    callStyle: { type: Object, default: null },
    /** The officer's shifts, the one running now and the next 14 days (26 Sep, C5). */
    shifts: { type: Array, default: () => [] },
    /** Positions on the shifts of the last week: recorded throughout, or when they were not (M22 §1.1). */
    positions: { type: Array, default: () => [] },
    /** Whether the viewer holds the live map's permission, which the positions held about an officer inherit (12 Oct). */
    canSeeTrack: { type: Boolean, default: false },
})

/*
 * A REBIND IS A SUPERVISOR'S DECISION (13 D1). A guard who already has a bound
 * handset and enrols another is not rebound until somebody here says so — a lost
 * phone reported by the guard and a code used by somebody else look identical
 * from the enrolment endpoint. The decision is recorded against the officer's
 * current or next shift.
 */
const decideRebind = (request, approve) => {
    if (approve) {
        router.post(`/guards/${props.guard.id}/device-rebinds/${request.id}/approve`, { note: '' }, { preserveScroll: true })

        return
    }

    refuseForm.clearErrors()
    refuseForm.note = ''
    refusing.value = request
}

/*
 * REFUSING A HANDSET NEEDS A REASON, and the officer is shown it. This was the
 * browser's own window.prompt; it is the console's dialog now (24 September
 * 2026), with the server's limit and its refusal shown in place.
 */
const REFUSAL_NOTE_MAX = 190
const refusing = ref(null)
const refuseForm = useForm({ note: '' })

const refuseBlocked = computed(() => (refuseForm.note.trim() === ''
    ? 'Say why the handset is refused. The officer is shown it.'
    : null))

const confirmRefuse = () => {
    if (!refusing.value || refuseBlocked.value || refuseForm.processing) {
        return
    }

    refuseForm.post(`/guards/${props.guard.id}/device-rebinds/${refusing.value.id}/deny`, {
        preserveScroll: true,
        onSuccess: () => {
            refusing.value = null
            refuseForm.reset()
        },
    })
}

/*
 * ISSUING THE OFFICER'S ENROLMENT CODE.
 *
 * The code comes back in the flash and is shown once, in the panel below. It is not held in a
 * prop and not re-fetched: the server keeps only its hash, so there is nothing to show a second
 * time and a lost code is reissued rather than looked up. `preserveScroll` so the panel appears
 * where the operator is already looking.
 */
const issuing = ref(false)

/*
 * SENDING IT FROM HERE (the user, 25 September 2026). The code is on screen once; the operator copies it, or sends
 * it straight to the officer's own phone number by WhatsApp or a text, with the words the officer needs to use it.
 * A number of ten digits is a Jamaican (North American) one and gets its country code for WhatsApp.
 */
const copied = ref(null)

const guardPhone = computed(() => {
    const row = (props.guard.employment ?? []).find((r) => r.label === 'Phone')
    const digits = String(row?.value ?? '').replace(/\D/g, '')

    return digits.length >= 7 ? digits : null
})

const enrolmentMessage = computed(() => {
    const issued = page.props.flash?.enrolment_code

    return issued
        ? `Your GeminiSecure Guard enrolment code is ${issued.code}. Open the Guard App, choose "Enrol this phone" and type it in. It works once and lapses after ${issued.hours} hours.`
        : ''
})

const whatsAppHref = computed(() => {
    const phone = guardPhone.value
    const to = phone === null ? '' : phone.length === 10 ? `1${phone}` : phone

    return `https://wa.me/${to}?text=${encodeURIComponent(enrolmentMessage.value)}`
})

const smsHref = computed(() => (guardPhone.value === null ? null : `sms:${guardPhone.value}?&body=${encodeURIComponent(enrolmentMessage.value)}`))

const copyCode = async (whole) => {
    try {
        await navigator.clipboard.writeText(whole ? enrolmentMessage.value : String(page.props.flash?.enrolment_code?.code ?? ''))
        copied.value = whole ? 'message' : 'code'
        setTimeout(() => { copied.value = null }, 2500)
    } catch {
        toast('This browser would not copy. Select the code above and copy it by hand.', { tone: 'error' })
    }
}

const issueEnrolmentCode = () => {
    issuing.value = true

    // Pressed with no dialog open, so a refusal is what just happened: a toast that stays until closed (Hassam, 12 Oct).
    router.post(`/guards/${props.guard.id}/enrolment-code`, {}, {
        preserveScroll: true,
        onError: (errors) => {
            toast(errors.enrolment ?? Object.values(errors)[0] ?? 'The enrolment code was not issued.', { tone: 'error' })
        },
        onFinish: () => { issuing.value = false },
    })
}

/*
 * Five of the six. A profile has no filter, so `empty-filtered` cannot happen
 * here — there is no query to have excluded anything, and rendering a "clear
 * the filter" panel on a screen with no filter would invent a control to
 * explain a state that does not exist. Forcing it falls through to the
 * populated screen, which is the honest answer.
 */
const page = usePage()

const state = useScreenState({
    rows: () => props.history.length,
})

/**
 * Why "Mark licence renewed" cannot be pressed.
 *
 * A renewal is a NEW EXPIRY DATE read off the renewed certificate, not a flag.
 * Recording one without that date would write a compliance fact nobody has
 * seen, so the control says what it needs and where the case lives instead.
 */
/* ------------------------------------------------------------------ */
/* the two writes (12 §2, Wave 5) */
/* ------------------------------------------------------------------ */

/*
 * A RENEWAL IS A NEW EXPIRY DATE READ OFF THE CERTIFICATE, not a flag — which
 * is exactly what this control's old reason said it needed. The panel asks for
 * the date and the number, and the server refuses a date that does not extend
 * the licence on file: one dated on or before it is a typo that would leave the
 * officer non-compliant while the screen said otherwise.
 */
const renewing = ref(false)

const renewalPrompt = computed(
    () =>
        `Record ${props.guard.name}'s renewed PSRA licence — the new expiry date read off the certificate, and the number printed on it.`
)

const renewForm = useForm({ psra_expires_on: '', psra_number: props.guard.psra_number ?? '' })

const submitRenewal = () => {
    if (renewForm.psra_expires_on === '') {
        return
    }

    renewForm.post(`/guards/${props.guard.id}/licence`, {
        preserveScroll: true,
        onSuccess: () => {
            renewing.value = false
        },
    })
}

/*
 * THE POST GOES WITH THE CLIENT. An officer who keeps a post at an estate they
 * no longer work would appear on that client's coverage board as standing a
 * gate — the same failure D-034 records for suspension, in the other direction.
 */
const moving = ref(false)

/*
 * IT OPENS WHERE THE OFFICER IS (bug hunt B009). This read `guard.tenant_id`, which
 * the profile never carried, so the form always opened on "No client — off posting"
 * with an empty post list, and pressing Reassign as it stood took the officer off
 * their client and opened every future shift they held. The profile now sends the
 * client and the post, and the form starts on both — so a press that changes
 * nothing is a press that is refused, not one that unposts somebody.
 */
const moveForm = useForm({ tenant_id: props.guard.tenant_id ?? '', post_id: props.guard.post_id ?? '' })

const postsForClient = computed(() =>
    props.postOptions.filter((option) => String(option.tenant_id) === String(moveForm.tenant_id))
)

/* Changing client invalidates a post that is not that client's. */
const onClientChange = () => {
    if (!postsForClient.value.some((option) => String(option.id) === String(moveForm.post_id))) {
        moveForm.post_id = ''
    }
}

/* Where the officer already is: nothing to move, so nothing is offered. */
const moveUnchanged = computed(
    () =>
        String(moveForm.tenant_id ?? '') === String(props.guard.tenant_id ?? '') &&
        String(moveForm.post_id ?? '') === String(props.guard.post_id ?? '')
)

const submitMove = () => {
    moveForm.post(`/guards/${props.guard.id}/reassign`, {
        preserveScroll: true,
        onSuccess: () => {
            moving.value = false
        },
    })
}

const retry = () => router.reload()

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['shift','request','incident','payroll'] })
</script>

<template>
    <Head :title="guard.name" />

    <GeminiConsole :title="guard.name" :back="{ href: '/guards', label: 'Back to guard workforce' }">

        <div v-if="state.isLoading.value" class="info-panel">
            <SkeletonRows :rows="8" :columns="2" />
        </div>

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This guard's record is not part of your role's access"
            body="An employment record carries a licence, a posting and a pay figure, so it opens only to roles that hold the Guard workforce module. Yours does not."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This guard's record could not be loaded"
            body="The platform database did not answer. Nothing has been changed, and nothing about this guard has been lost — the record is still there to be read."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="detail-head">
                <div class="hero-card" :class="guard.hero_class">
                    <div class="hero-top">
                        <div>
                            <div class="hero-name">{{ guard.name }}</div>
                            <div class="hero-sub">{{ guard.subtitle }}</div>
                        </div>
                        <div class="status-badge" :class="guard.status_badge">{{ guard.status_label }}</div>
                    </div>
                    <div class="hero-stats">
                        <div v-for="stat in guard.stats" :key="stat.label" class="hero-stat">
                            <div class="hs-v">{{ stat.value }}</div>
                            <div class="hs-l">{{ stat.label }}</div>
                        </div>
                    </div>
                </div>
                <div class="action-stack">
                    <!--
                      UN-ROSTERABLE: the reassign control is not disabled here,
                      it is absent, and the compliance action stands in its
                      place. A hard block means there is no posting to move this
                      officer to, so a greyed "Reassign client" would be
                      offering a thing that is not merely unbuilt but forbidden.
                    -->
                    <button
                        v-if="!guard.rosterable"
                        type="button"
                        class="stack-btn outline"
                        :disabled="!canAct"
                        :title="canAct ? renewalPrompt : actBlockedReason"
                        @click="renewing = !renewing"
                    >
                        <BoardIcon name="check-circle" />
                        <span>Mark licence renewed</span>
                    </button>

                    <!--
                      A compliant officer can be moved. A blocked one cannot,
                      and the control is ABSENT rather than greyed there: there
                      is no posting to move them to, so offering it would be
                      offering a thing that is forbidden rather than unbuilt.
                    -->
                    <button
                        v-else
                        type="button"
                        class="stack-btn primary"
                        :disabled="!canAct"
                        :title="canAct ? 'Move this officer to another client, or off one. Their post goes with the client, and any future shifts at the previous one are opened on the rota.' : actBlockedReason"
                        @click="moving = !moving"
                    >
                        <BoardIcon name="guards" :stroke="1.7" />
                        <span>Reassign client</span>
                    </button>

                    <!--
                      THE CODE THAT PUTS THIS OFFICER'S PHONE ON THE GUARD APP.
                      Until this button existed, `DeviceEnrolment::issueCode()` could only be
                      reached from a shell on the server, so a security company could not put its
                      own guards on its own app from its own console. The code is shown once, in
                      the panel below; only its hash is kept, so a lost one is reissued, never
                      looked up.
                    -->
                    <button
                        type="button"
                        class="stack-btn outline"
                        :disabled="!canAct || issuing"
                        :title="canAct ? 'Issue a one-time code for this officer to type into the Guard App. It is shown once and lasts 24 hours.' : actBlockedReason"
                        @click="issueEnrolmentCode"
                    >
                        <BoardIcon name="guards" :stroke="1.7" />
                        <span>{{ issuing ? 'Issuing…' : 'Issue enrolment code' }}</span>
                    </button>

                    <a v-if="guard.contact_href" :href="guard.contact_href" class="stack-btn outline">
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Contact guard</span>
                    </a>
                    <button
                        v-else
                        type="button"
                        class="stack-btn outline"
                        disabled
                        title="No phone number or email address is on this guard's record"
                    >
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Contact guard</span>
                    </button>
                </div>
            </div>

            <!--
              THE ENROLMENT CODE, SHOWN ONCE.
              The server keeps only its hash, so this is the only time it can be read. It is
              grouped in fours because it is delivered by reading it aloud down a telephone.
            -->
            <div v-if="page.props.flash?.enrolment_code" class="gd-panel gd-enrol">
                <div class="gd-head">
                    Enrolment code for {{ page.props.flash.enrolment_code.guard }}
                </div>
                <div class="gd-code">{{ page.props.flash.enrolment_code.code }}</div>
                <p class="gd-note">
                    Send it to the officer, or read it to them. They type it into the Guard App once, on the phone they
                    will carry. It works one time and lapses after
                    {{ page.props.flash.enrolment_code.hours }} hours. It is not stored and cannot be
                    shown again — if it is lost, issue another.
                </p>
                <!-- Sent from here while it is on screen; nothing keeps a copy (the server has only its hash). -->
                <div class="gd-enrol-actions">
                    <button type="button" class="btn-outline-sm" @click="copyCode(false)">{{ copied === 'code' ? 'Copied' : 'Copy code' }}</button>
                    <button type="button" class="btn-outline-sm" @click="copyCode(true)">{{ copied === 'message' ? 'Copied' : 'Copy message' }}</button>
                    <a class="btn-outline-sm" :href="whatsAppHref" target="_blank" rel="noopener">Send by WhatsApp</a>
                    <a v-if="smsHref" class="btn-outline-sm" :href="smsHref">Send by text message</a>
                </div>
                <p v-if="!guardPhone" class="gd-note">No phone number is on this officer's record, so WhatsApp opens for you to choose who to send it to.</p>
            </div>

            <!-- AUTHORED (13 D1): only when a handset is waiting to be rebound. -->
            <div v-for="request in rebindRequests" :key="request.id" class="gd-panel">
                <div class="gd-head">
                    New handset waiting — {{ request.label ?? request.platform }} · requested {{ request.requested_at }}.
                    Approving replaces the handset bound now; its token stops working when the new one collects its own.
                </div>
                <div class="gd-rebind-actions">
                    <button type="button" class="btn-outline-sm" :disabled="!canAct" :title="canAct ? 'Refuse this handset, with a note the officer sees.' : actBlockedReason" @click="decideRebind(request, false)">
                        <span>Refuse</span>
                    </button>
                    <button type="button" class="btn-primary-sm" :disabled="!canAct" :title="canAct ? 'Bind this handset to the officer, recorded against their shift.' : actBlockedReason" @click="decideRebind(request, true)">
                        <span>Approve handset</span>
                    </button>
                </div>
            </div>

            <!--
              AUTHORED. The board draws an officer nobody is acting on, so it
              has neither panel.
            -->
            <form v-if="renewing" class="gd-panel" @submit.prevent="submitRenewal">
                <div class="gd-head">
                    A renewal is the new expiry date read off the certificate, not a flag. A date that does not extend
                    the licence on file is refused — it would leave this officer non-compliant while the screen said
                    otherwise.
                </div>

                <div class="gd-fields">
                    <div class="gd-field">
                        <label for="gd-expires">New expiry date</label>
                        <input id="gd-expires" v-model="renewForm.psra_expires_on" type="date" required />
                    </div>
                    <div class="gd-field">
                        <label for="gd-number">PSRA number on the certificate</label>
                        <input id="gd-number" v-model="renewForm.psra_number" type="text" required maxlength="40" />
                    </div>
                </div>

                <div v-if="renewForm.errors.psra_expires_on" class="gd-error">
                    {{ renewForm.errors.psra_expires_on }}
                </div>

                <div class="gd-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="renewForm.processing || renewForm.psra_expires_on === ''"
                        title="Record the renewal. A suspension, if there is one, stays — that was a decision somebody took."
                    >
                        <span>{{ renewForm.processing ? 'Recording…' : 'Record renewal' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="renewing = false">Cancel</button>
                </div>
            </form>

            <form v-if="moving" class="gd-panel" @submit.prevent="submitMove">
                <div class="gd-head">
                    The post goes with the client. An officer who kept a post at an estate they no longer work would
                    show on that client's coverage board as standing a gate — so moving them to another client, or off
                    one, opens their future shifts on the rota for the estate to fill. Moving them to another post at
                    the same client leaves their shifts as they are.
                </div>

                <div class="gd-fields">
                    <div class="gd-field">
                        <label for="gd-client">Client</label>
                        <select id="gd-client" v-model="moveForm.tenant_id" @change="onClientChange">
                            <option value="">No client — off posting</option>
                            <option v-for="client in clientOptions" :key="client.id" :value="client.id">
                                {{ client.name }}
                            </option>
                        </select>
                    </div>
                    <div class="gd-field">
                        <label for="gd-post">Post</label>
                        <select id="gd-post" v-model="moveForm.post_id">
                            <option value="">No post yet</option>
                            <option v-for="option in postsForClient" :key="option.id" :value="option.id">
                                {{ option.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <div v-if="moveForm.errors.tenant_id" class="gd-error">{{ moveForm.errors.tenant_id }}</div>

                <div class="gd-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="moveForm.processing || moveUnchanged"
                        :title="moveUnchanged ? 'This is where the officer is posted now. Choose another client or post to move them.' : 'Move this officer.'"
                    >
                        <span>{{ moveForm.processing ? 'Moving…' : 'Reassign' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="moving = false">Cancel</button>
                </div>
            </form>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
                <div>
                    <div class="info-panel">
                        <div class="info-panel-head">Employment</div>
                        <div v-for="row in guard.employment" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span>
                            <span>{{ row.value }}</span>
                        </div>
                    </div>
                    <div class="info-panel">
                        <div class="info-panel-head">Current assignment</div>
                        <div v-for="row in guard.assignment" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span>
                            <span>{{ row.value }}</span>
                        </div>
                    </div>
                    <!--
                      AUTHORED (26 Sep, C5): the officer's shifts, the one running now and the next fourteen days, on
                      each estate's clock. A shift is posted and filled on the Roster; this is where the office reads it.
                    -->
                    <div class="info-panel">
                        <div class="info-panel-head">Shifts · now and the next 14 days</div>
                        <p v-if="shifts.length === 0" class="info-note">
                            Nothing rostered. Post a shift on the Roster and assign {{ guard.name ?? 'this officer' }} to it; the
                            Guard App can clock in from 60 minutes before it starts.
                        </p>
                        <div v-for="shift in shifts" :key="shift.id" class="info-row2">
                            <span>{{ shift.day }} · {{ shift.window }}</span>
                            <span :class="{ 'shift-on': shift.on_duty }">{{ shift.post }} · {{ shift.state }}</span>
                        </div>
                        <a :href="ROSTER_HREF" class="text-link-sm info-link">Open the Roster</a>
                    </div>
                    <!--
                      AUTHORED (M22 §1.1): the shift record for positions. Whether the phone recorded where the
                      officer was through each shift of the last week, and every gap when it did not — so a Head of
                      Security reading this after a night knows when positions were missing, and never where anyone was.
                    -->
                    <div v-if="positions.length > 0" class="info-panel">
                        <div class="info-panel-head">Positions on recent shifts</div>
                        <div v-for="shift in positions" :key="shift.id" class="pos-shift">
                            <div class="info-row2">
                                <span>{{ shift.day }} · {{ shift.window }}</span>
                                <span>{{ shift.post }}</span>
                            </div>
                            <p class="pos-summary" :class="{ 'pos-gap': shift.gap }">{{ shift.summary }}</p>
                            <p v-for="line in shift.gaps" :key="line" class="pos-line pos-gap">{{ line }}</p>
                        </div>
                    </div>
                    <!--
                      AUTHORED (Hassam, 12 Oct): what this officer agreed to, and the positions kept about them, each a screen
                      of its own. Positions held only for a role that holds the live map's permission (level 3, no link).
                    -->
                    <div class="info-panel">
                        <div class="info-panel-head">Location sharing</div>
                        <p class="info-note">What {{ guard.name }} agreed to on the Guard App, and the positions kept from their shifts.</p>
                        <Link :href="`/guards/${guard.id}/location-sharing`" class="text-link-sm info-link">What they agreed to, and when</Link>
                        <Link v-if="canSeeTrack" :href="`/guards/${guard.id}/track`" class="text-link-sm info-link ls-track-link">Positions held</Link>
                    </div>
                    <!--
                      AUTHORED (the guard ring §9.2): whether a panic can ring this officer's
                      phone like a call. The phone's own report, each check ON or OFF, and when
                      it said so — never a claim the phone has not made.
                    -->
                    <div v-if="callStyle" class="info-panel">
                        <div class="info-panel-head">Call-style alerts</div>
                        <div class="info-row2">
                            <span>Phone</span>
                            <span>{{ callStyle.label }}</span>
                        </div>
                        <div v-if="callStyle.detail" class="info-row2">
                            <span>Reported</span>
                            <span>{{ callStyle.detail }}</span>
                        </div>
                        <div v-for="row in callStyle.rows" :key="row.key" class="info-row2">
                            <span>{{ row.label }}</span>
                            <span>{{ row.on === true ? 'ON' : row.on === false ? 'OFF' : 'Not known' }}</span>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="tl2">
                        <div class="info-panel-head">{{ guard.history_head }}</div>

                        <EmptyState
                            v-if="state.isEmpty.value"
                            variant="first-use"
                            title="Nothing on file yet"
                            body="A posting, a licence check and a compliance action each write a line here. This record has none of them yet."
                        />

                        <div v-for="(event, i) in state.isEmpty.value ? [] : history" :key="i" class="tl2-row">
                            <div class="tl2-dot" :class="{ danger: event.danger }"></div>
                            <div class="tl2-txt">
                                <div class="tt1">{{ event.title }}</div>
                                <div class="tt2">{{ event.meta }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <ConsoleDialog
            :open="refusing !== null"
            :title="`Refuse this handset for ${guard.name ?? 'this officer'}?`"
            tone="danger"
            confirm-label="Refuse handset"
            busy-label="Refusing…"
            :busy="refuseForm.processing"
            :confirm-blocked-reason="refuseBlocked"
            @confirm="confirmRefuse"
            @cancel="refusing = null"
        >
            <p class="rf-lead">
                The handset they bound stays bound, and the new one is not. The officer is shown your reason,
                so write it for them.
            </p>
            <label class="rf-label" for="refuse-note">Reason</label>
            <textarea
                id="refuse-note"
                v-model="refuseForm.note"
                class="rf-note"
                rows="3"
                :maxlength="REFUSAL_NOTE_MAX"
                placeholder="For example: this phone was not issued by Gemini — see your supervisor."
            ></textarea>
            <div class="rf-foot">
                <span v-if="refuseForm.errors.note" class="rf-error" role="alert">{{ refuseForm.errors.note }}</span>
                <span class="rf-count">{{ refuseForm.note.length }} / {{ REFUSAL_NOTE_MAX }}</span>
            </div>
        </ConsoleDialog>
    </GeminiConsole>
</template>

<style scoped>
/* The refusal dialog's field (ConsoleDialog draws the frame). Authored; tokens only. */
.rf-lead {
    margin: 0;
}

.rf-label {
    display: block;
    margin-top: 14px;
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
}

.rf-note {
    display: block;
    width: 100%;
    box-sizing: border-box;
    margin-top: 6px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1.5px solid var(--navy-200);
    background: var(--white);
    font-family: inherit;
    font-size: 13.5px;
    line-height: 1.45;
    color: var(--navy-900);
    resize: vertical;
}

.rf-note:focus {
    outline: 2px solid var(--amber-500);
    outline-offset: 1px;
}

.rf-foot {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 4px;
    font-size: 12px;
}

.rf-error {
    color: var(--red-700);
}

.rf-count {
    margin-left: auto;
    color: var(--slate-500);
    font-variant-numeric: tabular-nums;
}

/*
 * The board draws both action-stack buttons as <div>s. One is a real link that
 * dials or mails the guard, the other a disabled button until reassignment
 * exists. These rules take back off only what the browser adds — the link's
 * underline, the button's font family, and the border on the one variant whose
 * board rule declares none. Everything visible still comes from .stack-btn.
 *
 * The border reset names .primary rather than .stack-btn because a scoped
 * element selector outranks a single class: `button.stack-btn { border: 0 }`
 * would beat .stack-btn.outline and strip the outline variant of the 1.5px
 * edge that is the whole difference between the two.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

button.stack-btn.primary {
    border: 0;
}


button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws an officer nobody is acting on, so
 * it has no panel and no flash. Kept to the tokens the Gemini boards define.
 */
.gd-rebind-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 10px;
}

.gd-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.gd-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 800px;
}

.gd-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.gd-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.gd-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.gd-field input,
.gd-field select {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.gd-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

/*
 * The enrolment code panel. Amber rather than the usual white, because this is the one thing on
 * the screen that will be gone when the page is left.
 */
.gd-enrol {
    border: 1px solid var(--amber-500);
    background: var(--amber-100);
}

.gd-enrol-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.gd-enrol-actions a {
    text-decoration: none;
}

.gd-code {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 30px;
    font-weight: 700;
    letter-spacing: 4px;
    color: var(--navy-900);
    margin: 10px 0 8px;
    user-select: all;
}

.gd-note {
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.65;
    max-width: 62ch;
}

.gd-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

/* The officer's shifts (C5). */
.info-note {
    margin: 4px 0 8px;
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.5;
}

.shift-on {
    color: var(--success-700);
    font-weight: 700;
}

.info-link {
    display: inline-block;
    margin-top: 8px;
}

/* Positions on recent shifts (M22 §1.1): a gap needs attention, so amber; red is an open panic's (Hassam's three weights, 11 Oct). */
.pos-shift + .pos-shift {
    margin-top: 8px;
}

.ls-track-link {
    margin-left: 14px;
}

.pos-summary,
.pos-line {
    margin: 2px 0 0;
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
}

.pos-gap {
    color: var(--amber-700);
    font-weight: 600;
}
</style>
