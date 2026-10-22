<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import { toast } from '../../../lib/toasts.js'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload'

// The client's row, its invoices, its officers and what is happening on site: this page follows all of them (M18H §7.2).
useLiveReload({ entities: ['client', 'invoice', 'officer', 'shift', 'incident'] })

/**
 * Client Detail — board screens super-admin-05 AND super-admin-09.
 *
 * ONE COMPONENT, TWO STATES, and that is the whole point of it.
 *
 * Board 05 draws Phoenix Park, a live client: what it is billed, who is posted
 * at its gates, what it has paid. Board 09 draws Ocean View, an estate still
 * being onboarded, and its panels are different — a checklist of what is left
 * to do, the plan as prepared rather than as billed, and the single contact who
 * has been named so far. Those are not two designs of the same screen. They are
 * the same screen answering a client that cannot answer the first set of
 * questions, and a client mid-onboarding rendered through the live panels is a
 * column of em dashes: no MRR, no invoices, no guards, and nothing saying why.
 *
 * So the lifecycle arrives from the server and this branches on it. Forking the
 * component would have been faster and would have meant that every later change
 * to the hero, the action stack or the panel chrome had to be made twice, with
 * one of the two silently drifting.
 *
 * DOM and class names are the board's, including the two inline `style`
 * attributes the board itself carries: the pill and the two-column grid.
 * Neither has a class in the board's stylesheet, and inventing one would be
 * authoring CSS the design does not have.
 *
 * Two things the LIVE board draws are not reproduced literally, and both for
 * the same reason — the figure does not exist in the central database and this
 * screen may not open an estate one:
 *
 *   "Units by phase"   phases are estate structure, held in the estate's own
 *                      database. The panel shows this client's platform
 *                      invoices instead, which is the same shape and is the
 *                      thing the primary action leads to.
 *   "Security add-on"  there is no per-guard add-on rate in the central
 *                      schema. The row shows the contracted unit count.
 *
 * The onboarding board adds a third of the same kind: it draws "Contract term —
 * 24 months", and nothing central records a term. That row shows the contract's
 * renewal date, which is stored and is the fact an operator acts on.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    canViewBilling: { type: Boolean, required: true },
    /** Whether this role may change this client rather than only read it. */
    canManage: { type: Boolean, required: true },
    /*
     * Why the roster and message links lead nowhere for this viewer, or null when
     * they lead somewhere. Asked by the server of the same permission their routes
     * check, so a role that may only read the client sees the reason on the control
     * rather than following a live-looking link onto a 403.
     */
    guardsBlockedReason: { type: String, default: null },
    messageBlockedReason: { type: String, default: null },
    /*
     * The contact's invitation (DESIGN §11 row 5): whether its email went, in the server's words, and Send or
     * Resend. Null once nobody is waiting for one. Onboarding used to make the contact an account and send
     * them nothing, while the checklist said "Estate admin invited".
     */
    contactInvitation: { type: Object, default: null },
    invitationBlockedReason: { type: String, default: null },
    /** The estate's clock (M19A §9): `{ zone, name, zones, canChange, blockedReason }`. */
    timezone: { type: Object, default: null },
})

/* The estate's time zone — shown to all, changed by the Director (M19A §9). */
const zoneForm = useForm({ timezone: props.timezone?.zone ?? '' })
const zoneQuery = ref('')
const zoneMatches = computed(() => {
    const all = props.timezone?.zones ?? []
    const q = zoneQuery.value.trim().toLowerCase().replace(/\s+/g, '_')
    const list = (q === '' ? all : all.filter((z) => z.toLowerCase().includes(q))).slice(0, 50)
    return list.includes(zoneForm.timezone) ? list : [zoneForm.timezone, ...list]
})
const zoneNowLine = (zone) => {
    try {
        return `It is ${new Intl.DateTimeFormat('en-US', { timeZone: zone, weekday: 'short', hour: 'numeric', minute: '2-digit' }).format(clock.value)} there now.`
    } catch {
        return ''
    }
}
const saveZone = () => zoneForm.post(`/clients/${props.estate.id}/timezone`, { preserveScroll: true })

const isOnboarding = computed(() => props.estate.lifecycle === 'onboarding')

/*
 * WHY THE BILLING PANELS ARE NOT THERE, or null when they are (M10 §5).
 *
 * For a role without Billing & subscriptions the server sends no `subscription`,
 * no `invoices`, no `tierLabel` and none of the billing stats — the keys are
 * absent, not empty. This is the switch the page turns on, and it matters for
 * more than the two panels: an absent list read as an empty one would count the
 * client as having nothing recorded against it, and the Subscription panel would
 * tell the reader a paying client is "Not on a plan yet".
 */
const withheld = computed(() => props.estate.withheld_reason ?? null)

const state = useScreenState({
    /*
     * A detail screen holds one record, so "how many rows" is really "is there
     * anything recorded against this client". An onboarding one always has its
     * checklist; a live one that has never been given a plan, a contact, a
     * guard or an invoice is a bare provisioned estate and says so.
     *
     * Withheld billing counts as something recorded: the panels are there,
     * saying why they are closed, and the client is not the bare estate the
     * empty state describes.
     */
    rows: () =>
        isOnboarding.value
            ? 1
            : (withheld.value ? 1 : (props.estate.subscription ?? []).length + (props.estate.invoices ?? []).length) +
              props.estate.contacts.length +
              props.estate.guards.length,

    /*
     * Never true of its own accord. This screen draws no search and no filter —
     * the directory is where clients are narrowed — so empty-because-filtered
     * cannot happen here and is only ever reached by forcing it locally with
     * `?_state=empty-filtered`. It still renders, because the reviewer checking
     * all six states on every screen should see a sentence rather than a blank
     * page, and what it says is exactly that: the filter lives on the directory.
     */
    filtered: () => false,

    /*
     * The service hands back an empty payload when the estate row disappeared
     * between the controller resolving it and the panels being read. Rare, and
     * a blank hero with four em dashes would read as a client with no data
     * rather than as a page that failed.
     */
    failed: () => !props.estate.id,
})

/*
 * Marking onboarding complete is a real transition: it puts the client live and
 * starts its billing. useForm rather than a bare router.post so the button can
 * show that it is mid-flight and cannot be pressed twice, which on this action
 * would mean two attempts to activate the same subscription.
 */
const completion = useForm({})

const markComplete = () => {
    completion.post(`/clients/${props.estate.id}/onboarding/complete`, { preserveScroll: true })
}

/*
 * THE CONTACT'S INVITATION — Send or Resend, and what happened, in the contact's own panel (DESIGN §11 row 5).
 * It emails somebody, so it asks first, naming the person and the address, and only the question's own button
 * sends (DR-114, the register's Part 7); it waits out the server's minute, counting down, and cannot be pressed
 * twice.
 */
const page = usePage()
const outcome = computed(() => page.props.flash?.invitation_outcome ?? null)

/*
 * WHAT BECAME OF THE INVITATION (Hassam, 12 October: three kinds). It went, or its email did not go: what just happened,
 * a toast top right with a cross. The server refusing to send it at all is a refusal of the open question: said in the
 * question's amber band, and the question stays open; it is never toasted. Whether the email went stays in the panel,
 * from the record: a standing fact.
 */
const invitationRefusal = ref(null)

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

    // Arriving with an answer (the client was just created and its contact invited): no question is open, so a toast.
    sayOutcome(outcome.value)
})
onBeforeUnmount(() => clearInterval(ticking))

const invitationWait = computed(() => {
    const after = props.contactInvitation?.resend_after ? Date.parse(props.contactInvitation.resend_after) : Number.NaN

    return Number.isFinite(after) ? Math.max(0, Math.ceil((after - clock.value) / 1000)) : 0
})

const mmss = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`

const invitationForm = useForm({})

const invitationBlockedBy = computed(() => {
    if (props.invitationBlockedReason !== null) {
        return props.invitationBlockedReason
    }

    if (invitationWait.value === 0) {
        return null
    }

    // In words that match the panel: "just sent" only when the last try went (the server's `last_try`).
    const wait = mmss(invitationWait.value)

    if (props.contactInvitation?.last_try === 'sent') {
        return `It was just sent. It can go again in ${wait}.`
    }

    if (props.contactInvitation?.last_try === 'not_sent') {
        return `It was tried moments ago and did not go. It can be tried again in ${wait}.`
    }

    return `It was tried moments ago. It can be tried again in ${wait}.`
})

const invitationLabel = computed(() => {
    if (invitationForm.processing) {
        return 'Sending…'
    }

    if (invitationWait.value > 0) {
        return `Resend in ${mmss(invitationWait.value)}`
    }

    return props.contactInvitation?.action ?? 'Send invitation'
})

/** Whether the question about the contact's invitation is open. */
const invitationAsk = ref(false)
const isResendInvite = computed(() => props.contactInvitation?.action === 'Resend invitation')

const sendInvitation = () => {
    if (invitationBlockedBy.value !== null || invitationForm.processing) {
        return
    }

    invitationRefusal.value = null
    invitationAsk.value = true
}

const keepInvitation = () => {
    invitationAsk.value = false
    invitationRefusal.value = null
}

const postInvitation = () => {
    if (invitationBlockedBy.value !== null || invitationForm.processing) {
        return
    }

    invitationRefusal.value = null
    invitationForm.post(`/clients/${props.estate.id}/contact-invitation/resend`, {
        preserveScroll: true,
        onSuccess: (visit) => {
            const said = visit?.props?.flash?.invitation_outcome ?? null

            if (said?.state === 'refused') {
                invitationRefusal.value = said.message

                return
            }

            invitationAsk.value = false
            sayOutcome(said)
        },
    })
}

/** Why the go-live button cannot be pressed, or null when it can. */
const completionBlockedBy = computed(() => {
    if (!props.canManage) {
        return 'Changing a client is not part of your role’s access'
    }

    return props.estate.onboarding?.blockedReason ?? null
})
</script>

<template>
    <Head :title="estate.name" />

    <GeminiConsole :title="estate.name" board="super-admin-01-login-dashboard-and-activity" :back="{ href: '/clients', label: 'Back to clients' }">

        <SkeletonRows v-if="state.isLoading.value" :rows="9" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This client is not yours to open"
            body="Your role is scoped to the sites you are assigned to, and this estate is not one of them. An Operations Manager or the Director can widen that scope."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This client's record could not be read"
            body="The estate exists but its panels could not be loaded from the platform database. Nothing has been changed. Try again, and if it persists the platform database is the place to look."
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="This screen has no filter"
            :body="
                withheld
                    ? 'A client record shows one estate and everything recorded against it. Narrowing by name or status happens on the client directory.'
                    : 'A client record shows one estate and everything recorded against it. Narrowing by name, tier or status happens on the client directory.'
            "
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nothing recorded against this client yet"
            body="The estate has been provisioned but has no plan, no committee accounts, no guards posted and nothing invoiced. Put it on a plan to start."
        />

        <template v-else>
            <div class="detail-head">
                <div class="hero-card">
                    <div class="hero-top">
                        <div>
                            <div class="hero-name">{{ estate.name }}</div>
                            <div class="hero-sub">{{ estate.subtitle }}</div>
                        </div>
                        <div
                            style="
                                font-size: 10.5px;
                                font-weight: 700;
                                color: var(--amber-700);
                                background: var(--amber-100);
                                padding: 5px 11px;
                                border-radius: 20px;
                            "
                        >{{ estate.tierLabel ?? estate.statusLabel }}</div>
                    </div>
                    <div class="hero-stats">
                        <div v-for="stat in estate.stats" :key="stat.label" class="hero-stat">
                            <div class="hs-v">{{ stat.value }}</div>
                            <div class="hs-l">{{ stat.label }}</div>
                        </div>
                    </div>
                </div>

                <!--
                  The action stack is the lifecycle's, not the screen's. An
                  onboarding client has nothing to bill and nobody posted, so
                  neither "View billing history" nor "Manage guard assignment"
                  has anything to open; what it has instead is the one act that
                  moves it forward.
                -->
                <div v-if="isOnboarding" class="action-stack">
                    <button
                        type="button"
                        class="stack-btn primary"
                        :disabled="completionBlockedBy !== null || completion.processing"
                        :title="completionBlockedBy ?? 'Put this client live and start its billing'"
                        @click="markComplete"
                    >
                        <BoardIcon name="check" :stroke="3" />
                        <span>Mark onboarding complete</span>
                    </button>

                    <Link
                        v-if="messageBlockedReason === null"
                        :href="`/clients/${estate.id}/message`"
                        class="stack-btn outline"
                    >
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Message primary contact</span>
                    </Link>
                    <button v-else type="button" class="stack-btn outline" disabled :title="messageBlockedReason">
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Message primary contact</span>
                    </button>
                </div>

                <div v-else class="action-stack">
                    <Link v-if="canViewBilling" href="/billing" class="stack-btn primary">
                        <BoardIcon name="billing" :stroke="1.7" />
                        <span>View billing history</span>
                    </Link>
                    <button
                        v-else
                        type="button"
                        class="stack-btn primary"
                        disabled
                        title="Billing &amp; subscriptions is not part of your role's access"
                    >
                        <BoardIcon name="billing" :stroke="1.7" />
                        <span>View billing history</span>
                    </button>

                    <Link
                        v-if="guardsBlockedReason === null"
                        :href="`/clients/${estate.id}/guards`"
                        class="stack-btn outline"
                    >
                        <BoardIcon name="guards" :stroke="1.7" />
                        <span>Manage guard assignment</span>
                    </Link>
                    <button v-else type="button" class="stack-btn outline" disabled :title="guardsBlockedReason">
                        <BoardIcon name="guards" :stroke="1.7" />
                        <span>Manage guard assignment</span>
                    </button>

                    <Link
                        v-if="messageBlockedReason === null"
                        :href="`/clients/${estate.id}/message`"
                        class="stack-btn outline"
                    >
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Message estate admin</span>
                    </Link>
                    <button v-else type="button" class="stack-btn outline" disabled :title="messageBlockedReason">
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Message estate admin</span>
                    </button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
                <div>
                    <div v-if="isOnboarding" class="info-panel">
                        <div class="info-panel-head">Onboarding checklist</div>

                        <!--
                          Every step is derived from what is actually recorded,
                          never from a stored tick. A step this system cannot
                          answer carries its reason on the row rather than
                          quietly reading Pending forever.
                        -->
                        <div
                            v-for="step in estate.onboarding.checklist"
                            :key="step.label"
                            class="info-row2"
                            :title="step.note ?? undefined"
                        >
                            <span>{{ step.label }}</span>
                            <span>{{ step.value }}</span>
                        </div>
                    </div>

                    <div class="info-panel">
                        <div class="info-panel-head">{{ estate.subscriptionHead }}</div>

                        <!--
                          The one panel that says why, and it covers the invoice
                          list as well: both are Billing & subscriptions' records,
                          and one refusal in place of two reads as a rule rather
                          than as two missing things.
                        -->
                        <EmptyState
                            v-if="withheld"
                            variant="denied"
                            title="Not part of your role's access"
                            :body="withheld"
                        />

                        <EmptyState
                            v-else-if="!estate.subscription.length"
                            variant="first-use"
                            title="Not on a plan yet"
                            body="This estate is provisioned but has no subscription, so it has no units, no tier and no recurring revenue."
                        />

                        <div v-for="row in estate.subscription ?? []" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span>
                            <span>{{ row.value }}</span>
                        </div>
                    </div>

                    <div v-if="!isOnboarding && !withheld" class="info-panel">
                        <div class="info-panel-head">Platform invoices</div>

                        <EmptyState
                            v-if="!estate.invoices.length"
                            variant="first-use"
                            title="Nothing invoiced yet"
                            body="Invoices appear here from the first billing period after the subscription starts."
                        />

                        <div v-for="row in estate.invoices" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span>
                            <span>{{ row.value }}</span>
                        </div>
                    </div>
                </div>

                <div>
                    <!--
                      One contact while onboarding, the whole committee once
                      live. Listing six accounts against an estate that has
                      named one person makes the record look further along than
                      it is; naming one against a live client hides the
                      treasurer the accounts team actually needs.
                    -->
                    <div v-if="isOnboarding" class="info-panel">
                        <div class="info-panel-head">Primary contact</div>

                        <div v-for="row in estate.onboarding.primaryContact" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span>
                            <span>{{ row.value }}</span>
                        </div>
                    </div>

                    <template v-else>
                        <div class="info-panel">
                            <div class="info-panel-head">Estate contacts</div>

                            <EmptyState
                                v-if="!estate.contacts.length"
                                variant="first-use"
                                title="No committee accounts yet"
                                body="A contact appears here once an estate account is issued and assigned a committee role."
                            />

                            <div v-for="contact in estate.contacts" :key="contact.name" class="contact-row">
                                <div class="contact-avatar">{{ contact.initials }}</div>
                                <div>
                                    <div class="cn">{{ contact.name }}</div>
                                    <div class="cr">{{ contact.detail }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="info-panel">
                            <div class="info-panel-head">Guards deployed</div>

                            <EmptyState
                                v-if="!estate.guards.length"
                                variant="first-use"
                                title="No guards posted here"
                                body="Either this estate runs its own security and buys the software alone, or it has not been staffed yet."
                            />

                            <div v-for="guard in estate.guards" :key="guard.name" class="contact-row">
                                <div class="contact-avatar">{{ guard.initials }}</div>
                                <div>
                                    <div class="cn">{{ guard.name }}</div>
                                    <div class="cr">{{ guard.detail }}</div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!--
                      THE CONTACT'S INVITATION (DESIGN §11 row 5): whether its email went, in the server's words,
                      and Send or Resend. Drawn while somebody is still waiting for one, onboarding or live. The answer
                      to the last press is a toast, or a refusal inside the question (Hassam, 12 October).
                    -->
                    <div v-if="contactInvitation" class="info-panel">
                        <div class="info-panel-head">Estate admin invitation</div>

                        <template v-if="contactInvitation">
                            <div class="info-row2">
                                <span>{{ contactInvitation.name }}</span>
                                <span>{{ contactInvitation.email }}</span>
                            </div>
                            <p class="ci-delivery" :class="{ 'ci-not-sent': contactInvitation.delivery_state !== 'sent' }">{{ contactInvitation.delivery }}</p>
                            <div class="ci-actions">
                                <button
                                    type="button"
                                    class="stack-btn outline"
                                    :disabled="invitationBlockedBy !== null || invitationForm.processing"
                                    :aria-busy="invitationForm.processing"
                                    :title="invitationBlockedBy ?? `Email ${contactInvitation.email} an invitation to their estate's console. It names you, the estate and the role, and stands fourteen days.`"
                                    @click="sendInvitation"
                                >
                                    <BoardIcon name="broadcast" :stroke="1.7" />
                                    <span>{{ invitationLabel }}</span>
                                </button>
                            </div>
                        </template>
                    </div>

                    <ConsoleDialog
                        :open="invitationAsk && contactInvitation !== null"
                        :title="contactInvitation ? `${isResendInvite ? 'Resend' : 'Send'} the invitation to ${contactInvitation.name}?` : ''"
                        :confirm-label="contactInvitation?.action ?? 'Send invitation'"
                        busy-label="Sending…"
                        :busy="invitationForm.processing"
                        :error="invitationRefusal"
                        @confirm="postInvitation"
                        @cancel="keepInvitation"
                    >
                        <p v-if="contactInvitation && isResendInvite" style="margin: 0">
                            An email to {{ contactInvitation.email }}, with a fresh link to join {{ estate.name }}'s console. Once it goes, the earlier link stops working. Nobody else is told.
                        </p>
                        <p v-else-if="contactInvitation" style="margin: 0">
                            An email to {{ contactInvitation.email }}, with a link to join {{ estate.name }}'s console. It names you and stands fourteen days. Nobody else is told.
                        </p>
                    </ConsoleDialog>

                    <!-- M19A §9: the estate's clock. Every reader sees it; the Director alone changes it, warned first. -->
                    <div v-if="timezone" class="info-panel">
                        <div class="info-panel-head">Time zone</div>
                        <div class="info-row2">
                            <span>{{ timezone.name }}</span>
                            <span>{{ timezone.zone }}</span>
                        </div>
                        <p class="ci-delivery">{{ zoneNowLine(timezone.zone) }}</p>
                        <template v-if="timezone.canChange">
                            <label class="tz-label" for="tz-select">Change it</label>
                            <input v-model="zoneQuery" type="search" class="tz-search" placeholder="Search — e.g. New York, Karachi" aria-label="Search time zones" />
                            <select id="tz-select" v-model="zoneForm.timezone" class="tz-select">
                                <option v-for="z in zoneMatches" :key="z" :value="z">{{ z.replace(/_/g, ' ') }}</option>
                            </select>
                            <p v-if="zoneForm.timezone !== timezone.zone" class="tz-warn" role="alert">
                                Every time {{ estate.name }} shows — passes, shifts, meetings, bookings, dues dates, pushes and emails —
                                will read on {{ zoneForm.timezone.replace(/_/g, ' ') }} time from now on, and a time typed there will mean that clock.
                                Nothing stored changes. {{ zoneNowLine(zoneForm.timezone) }}
                            </p>
                            <p v-if="zoneForm.errors.timezone" class="ci-outcome ci-refused">{{ zoneForm.errors.timezone }}</p>
                            <div class="ci-actions">
                                <button type="button" class="stack-btn outline" :disabled="zoneForm.processing || zoneForm.timezone === timezone.zone" @click="saveZone">
                                    <span>{{ zoneForm.processing ? 'Saving…' : 'Change the time zone' }}</span>
                                </button>
                            </div>
                        </template>
                        <p v-else class="tz-note">{{ timezone.blockedReason }}</p>
                    </div>
                </div>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/* M19A §9 — the time-zone panel. */
.tz-label {
    display: block;
    margin-top: 10px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
}
.tz-search,
.tz-select {
    width: 100%;
    margin-top: 6px;
    height: 36px;
    padding: 0 10px;
    border: 1.5px solid var(--navy-200);
    border-radius: 10px;
    font: inherit;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--white);
}
.tz-warn {
    margin: 10px 0 0;
    padding: 9px 12px;
    border-radius: 10px;
    background: var(--amber-100);
    color: var(--navy-900);
    font-size: 12.5px;
    line-height: 1.5;
}
.tz-note {
    margin: 8px 0 0;
    font-size: 12px;
    color: var(--text-body);
}
</style>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule removes a browser
 * default or reproduces an inline style the board itself carries. Nothing here
 * introduces a colour, a size or a spacing the design does not already declare.
 *
 * The board draws the action stack as three <div>s. Here one is a real link and
 * the rest are real buttons, so the anchor's underline and the button's border
 * would show through and change the pixels.
 *
 * Note what is deliberately absent. There is no padding, background or colour
 * reset: .stack-btn and .stack-btn.outline already set all three, and a rule
 * here would out-specify the board and undo it. The border reset is written
 * against the bare element for the same reason — .stack-btn.outline's own
 * border is the more specific rule and still wins, which is exactly what should
 * happen, while the primary button, which the board gives no border, loses the
 * browser's.
 */
button {
    border: 0;
    font: inherit;
}

.action-stack a {
    text-decoration: none;
}

.action-stack button {
    cursor: pointer;
}

.action-stack button[disabled] {
    cursor: not-allowed;
}


/*
 * AUTHORED: the contact's invitation panel (DESIGN §11 row 5). No board draws it — board 09 draws the
 * checklist's "Estate admin invited" and nothing about whether the email went. The board's own tokens only.
 */
.ci-delivery,
.ci-outcome {
    font-size: 11.5px;
    line-height: 1.5;
    margin: 8px 0 0;
    overflow-wrap: anywhere;
}

.ci-delivery {
    color: var(--slate-600);
    font-weight: 600;
}

.ci-delivery.ci-not-sent {
    color: var(--amber-700);
}

.ci-actions {
    margin-top: 10px;
}

.ci-actions button {
    cursor: pointer;
}

.ci-actions button[disabled] {
    cursor: not-allowed;
}

.ci-outcome {
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
}

/* A refusal of the one field it sits under: amber, as every ordinary refusal is (red is an open panic's alone). */
.ci-refused {
    background: var(--amber-100);
    color: var(--amber-700);
}
</style>
