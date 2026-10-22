<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'
import { submitDialog } from '../../../lib/dialogSubmit.js'

/**
 * One booking and the deposit behind it — the booking detail.
 *
 * NOT ON ANY APPROVED BOARD, AND ADDED DELIBERATELY (D-086). Board 19 draws the
 * deposit STATE in its diary and no control that changes it, and the service
 * that moves the money had no route at all. The client's ruling: "A service
 * that moves money with no route is a worse gap than the caution it replaced.
 * Put the control on the amenity booking detail." So this screen exists to carry
 * that control, and it borrows board 18's shapes — the summary card, the action
 * stack, the timeline rail — from the same stylesheet rather than inventing a
 * fourth visual language for one screen nobody drew.
 *
 * THREE ACTS, TWO GATES, AND THE HIGHER ONE KEEPS MONEY. Recording the deposit
 * received and refunding it need Facilities update: each is the estate's books
 * catching up with cash that has already moved. Forfeiting keeps a resident's
 * money as the estate's income, so it needs Facilities APPROVE — "the higher
 * verb" — and a reason, and the reason field is put in front of the press rather
 * than the press being sent to be bounced. `canUpdate` and `canApprove` answer
 * the two gates; the deposit's own state answers the rest.
 *
 * THE ENTRY IS NAMED BEFORE IT IS MADE. Each panel says what it will post, and
 * none of the three touches 1200 Dues Receivable: a deposit is the estate
 * holding somebody's money, not somebody owing the estate money.
 *
 * NOT ONE FIGURE HERE IS A HOUSEHOLD'S FINANCIAL POSITION. The Property Manager
 * holds Facilities and nothing on Dues & ledger (D-010). The amounts on this
 * screen are the terms snapshotted onto the booking — its fee and its deposit —
 * which are facts about the booking rather than about the household's account.
 */
const props = defineProps({
    /** Category B (13 C3): whether this screen's records came from a real handset or the simulator. */
    sourceBadge: { type: Object, required: true },
    estate: { type: Object, required: true },
    booking: { type: Object, required: true },
    /** The deposit as the booking carries it: amount, state, and its three steps. */
    deposit: { type: Object, required: true },
    canUpdate: { type: Boolean, required: true },
    canApprove: { type: Boolean, required: true },
    updateReason: { type: String, required: true },
    approveReason: { type: String, required: true },
})

/*
 * Boards 17 to 20 share the batch-5 sheet, and so does this screen: it is drawn
 * from board 18's classes, and naming any other sheet would render it with no
 * summary card, no action stack and no rail.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

// The resident cancelling in the app, a deposit paid or refunded: this booking re-reads itself (M18H §7.2).
useLiveReload({ entities: ['booking', 'dues'] })

const page = usePage()

/*
 * Populated where the booking carries a deposit; empty where it never took one —
 * a Pool Deck booking has nothing to hold, and the rail would be three steps of
 * nothing. There is no filter here, so empty-filtered is reachable only when
 * forced, and it says so.
 */
const state = useScreenState({
    rows: () => (props.deposit.amount === null ? 0 : 1),
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL — production gives
 * each estate its own hostname and no prefix, local serves every estate from one
 * host under /estate/{key}. Cutting the URL that served this page is correct in
 * both and cannot address an estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/facilities')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

/** The diary, which is where the reader pressed "View". */
const diaryHref = computed(() => `${root.value}/facilities/amenities/bookings`)

const bookingPath = computed(() => `${diaryHref.value}/${props.booking.id}`)

/*
 * The diary's own badge rule: the sheet draws Confirmed, Pending and Completed; Rejected, the one vocabulary's word for
 * a declined booking, takes a red outline, drawn inside the badge so it is no larger than the others (M96 §2; Hassam,
 * 11 October: "Rejected becomes a red outline, not solid red, not amber"); Cancelled and Expired the neutral navy for
 * a fact that is over.
 */
const NEUTRAL_BADGE = 'background:var(--navy-100);color:var(--slate-600);'

const REJECTED_BADGE = 'background:transparent;box-shadow:inset 0 0 0 1px var(--red-700);color:var(--red-700);'

const badgeStyle = computed(() => {
    if (['confirmed', 'pending', 'completed'].includes(props.booking.status)) {
        return undefined
    }

    return props.booking.status === 'declined' ? REJECTED_BADGE : NEUTRAL_BADGE
})

/** The status's icon, beside its word: never the colour alone (M96). The diary draws the same. */
const STATUS_ICON = {
    pending: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
    confirmed: 'M20 6 9 17l-5-5',
    completed: 'M20 6 9 17l-5-5',
    declined: 'M6 6l12 12M18 6L6 18',
    cancelled: 'M8 12h8M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
    expired: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
}

/* ------------------------------------------------------------------ */
/* the three acts, and why each one may be refused */
/* ------------------------------------------------------------------ */

/**
 * Which act's form is open: 'hold', 'refund', 'forfeit', or none. One at a time, in the console's modal since M95 §1
 * (M97 pass): each opened as a panel under the card, which made the page longer and announced nothing.
 */
const panel = ref(null)

/** Why Forfeit deposit cannot be pressed yet in its form, said under it (M96 level 2). */
const forfeitFormBlocked = computed(() => (forfeitForm.reason.trim() === '' ? 'Say why the estate is keeping it.' : null))

const holdForm = useForm({ on: '' })
const refundForm = useForm({ on: '' })
/*
 * `amount` is what the estate KEEPS, in dollars; blank keeps the whole deposit.
 * Two broken chairs do not cost a household all of it, and the rest goes back in
 * the same entry so nothing is left held (M4 §4, defect 2).
 */
const forfeitForm = useForm({ reason: '', amount: '' })

/*
 * A blank date travels as null, which the service reads as today. An empty
 * string would be refused by the date rule and tell the reader their date was
 * wrong when they had not given one.
 */
const blankToNull = (value) => (String(value).trim() === '' ? null : value)

holdForm.transform((data) => ({ on: blankToNull(data.on) }))
refundForm.transform((data) => ({ on: blankToNull(data.on) }))
forfeitForm.transform((data) => ({ reason: data.reason, amount: blankToNull(data.amount) }))

/**
 * Why the deposit cannot be recorded as received, or null when it can.
 *
 * The service's own refusals, said here first. A deposit is taken once, from
 * awaiting, and only for a booking that may still happen.
 */
const holdBlockedBy = computed(() => {
    if (!props.canUpdate) {
        return props.updateReason
    }

    if (props.deposit.state === 'held') {
        return 'The estate is already holding this deposit. Recording it again would put the same money in the bank twice.'
    }

    if (props.deposit.state !== 'awaiting') {
        return `This deposit has been ${props.deposit.state}. A deposit is taken once, and this one has already gone back or been kept.`
    }

    if (['declined', 'cancelled', 'expired'].includes(props.booking.status)) {
        return `This booking was ${props.booking.status}, so there is no event for a deposit to secure. Recording one held would leave the estate owing money on a booking that will never happen.`
    }

    return null
})

const refundBlockedBy = computed(() => {
    if (!props.canUpdate) {
        return props.updateReason
    }

    if (props.deposit.state !== 'held') {
        return 'Only a deposit the estate is holding can be refunded.'
    }

    return null
})

/**
 * Why the deposit cannot be kept, or null when it can.
 *
 * THE HIGHER VERB COMES SECOND, after the lower one, because a viewer without
 * update holds no approve either — and the sentence they need is the one about
 * the access they actually lack.
 */
const forfeitBlockedBy = computed(() => {
    if (!props.canUpdate) {
        return props.updateReason
    }

    if (!props.canApprove) {
        return props.approveReason
    }

    if (props.deposit.state !== 'held') {
        return 'Only a deposit the estate is holding can be kept.'
    }

    return null
})

/*
 * THREE LEVELS, NO DEAD BUTTON THAT LOOKS ALIVE (M94 §1.2, M96 §1). The deposit's state is a badge at the top, with
 * when. Done, or never this role's (level 3): a settled deposit, a role that cannot record deposits, a forfeit a role
 * can never approve, a receipt already recorded: no button at all. Blocked for now (level 2): refund and forfeit while
 * the deposit is still awaited, greyed, the reason on the card, because a tablet has no hover. Available (level 1): a
 * normal button.
 */
const BADGE_ICON = { completed: 'check', closed: 'dot', in_progress: 'clock', pending: 'clock' }

const showActions = computed(() => props.canUpdate && !props.deposit.settled)

const awaiting = computed(() => props.deposit.state === 'awaiting')

const WAIT_REASON = 'Refund or forfeit the deposit once it is recorded as received.'

const openPanel = (which) => {
    panel.value = which

    holdForm.clearErrors()
    refundForm.clearErrors()
    forfeitForm.clearErrors()
}

const closePanel = () => {
    panel.value = null
    // What was typed and then discarded does not come back the next time a form opens.
    holdForm.reset()
    refundForm.reset()
    forfeitForm.reset()
}

/*
 * A form submits on Enter from any field and a disabled button does not stop
 * it. Every route is gated and the service refuses every one of these states,
 * so the guards below are only about not sending a press that will bounce.
 */
const submitHold = () => {
    if (holdBlockedBy.value !== null) {
        return
    }

    // Each from its dialog through the one save (lib/dialogSubmit.js): a refusal keeps it open with what was typed.
    submitDialog(holdForm, 'post', `${bookingPath.value}/deposit/hold`, { close: closePanel })
}

const submitRefund = () => {
    if (refundBlockedBy.value !== null) {
        return
    }

    submitDialog(refundForm, 'post', `${bookingPath.value}/deposit/refund`, { close: closePanel })
}

const submitForfeit = () => {
    if (forfeitBlockedBy.value !== null || forfeitForm.reason.trim() === '') {
        return
    }

    submitDialog(forfeitForm, 'post', `${bookingPath.value}/deposit/forfeit`, {
        close: closePanel,
        onSuccess: () => forfeitForm.reset(),
    })
}
</script>

<template>
    <Head :title="`Booking ${booking.reference}`" />

    <!-- A nested page: the way back to the diary, in words, top left (M95 §3). -->
    <EstateConsole
        :title="`Booking ${booking.reference}`"
        :estate-name="estate.name"
        active="facilities"
        :back="{ href: diaryHref, label: 'Back to bookings' }"
    >
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This booking is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This booking could not be loaded"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!--
              The service's refusals come back under `deposit`, said inside the act's own dialog; a field's refusal
              under its field. Nothing is a banner on the page (M95 §2).
            -->
            <div class="ticket-head">
                <div class="ticket-summary">
                    <div class="ts-top">
                        <div>
                            <div class="ts-title">{{ booking.amenity }} · {{ booking.resident }}, {{ booking.unit }}</div>
                            <div class="ts-meta">
                                {{ booking.reference }} · {{ booking.when
                                }}<template v-if="booking.guests"> · {{ booking.guests }} guests</template>
                            </div>
                        </div>

                        <div class="status-badge bk-status" :class="booking.status" :style="badgeStyle">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path :d="STATUS_ICON[booking.status] ?? STATUS_ICON.pending" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            {{ booking.status_label }}
                        </div>
                    </div>

                    <!--
                      The terms AS THE BOOKING CARRIES THEM — copied off the
                      amenity when it was made, and never read back, so the rate
                      card can change tomorrow without restating these.
                    -->
                    <dl class="bk-terms">
                        <div>
                            <dt>Booking fee</dt>
                            <dd>{{ booking.fee }}<template v-if="booking.fee_charged"> · charged to the unit</template></dd>
                        </div>
                        <div>
                            <dt>Deposit</dt>
                            <dd>{{ deposit.amount ?? 'None' }}</dd>
                        </div>
                        <div v-if="booking.cancellation">
                            <dt>Cancellation notice</dt>
                            <dd>{{ booking.cancellation }}</dd>
                        </div>
                        <div v-if="booking.decision">
                            <dt>Decision</dt>
                            <dd>{{ booking.decision }}</dd>
                        </div>
                    </dl>

                    <div v-if="booking.notes" class="ts-desc">"{{ booking.notes }}"</div>
                </div>

                <!--
                  The deposit door. The primary is whichever act the deposit can
                  take next — recording it received while it is awaited, refunding
                  it while it is held — and forfeiting is never the primary: keeping
                  a resident's money is the exception, not the next step.
                -->
                <div v-if="deposit.amount !== null" class="action-stack">
                    <!-- The deposit's state, with when, before any action (M96 §3, §5): colour, an icon and the word. -->
                    <div v-if="deposit.badge" class="bk-badge" :class="`bk-${deposit.badge.tone}`">
                        <svg v-if="BADGE_ICON[deposit.badge.tone] === 'check'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <polyline points="20 6 9 17 4 12" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <svg v-else-if="BADGE_ICON[deposit.badge.tone] === 'clock'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="2" />
                            <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="4" fill="currentColor" />
                        </svg>
                        <span>{{ deposit.badge.label }}</span>
                    </div>

                    <template v-if="showActions">
                    <!-- Level 3 when it can never be pressed (a booking that will not happen): no button. -->
                    <button
                        v-if="awaiting && holdBlockedBy === null"
                        type="button"
                        class="stack-btn primary"
                        :title="`Record the ${deposit.amount} deposit as received.`"
                        @click="openPanel('hold')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="20 6 9 17 4 12"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>Record deposit received</span>
                    </button>

                    <button
                        type="button"
                        class="stack-btn"
                        :class="deposit.state === 'held' ? 'primary' : 'outline'"
                        :disabled="refundBlockedBy !== null"
                        :title="
                            refundBlockedBy ??
                            `Return the ${deposit.amount} to the household.`
                        "
                        @click="openPanel('refund')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                            <path
                                d="M3 3.5V8h4.5"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>Refund deposit</span>
                    </button>

                    <!-- Never offered to a role that can never forfeit (level 3, M96 §1.1). -->
                    <button
                        v-if="canApprove"
                        type="button"
                        class="stack-btn outline"
                        :disabled="forfeitBlockedBy !== null"
                        :title="
                            forfeitBlockedBy ??
                            `Keep the ${deposit.amount} as the estate's income.`
                        "
                        @click="openPanel('forfeit')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M8 11V7a4 4 0 0 1 8 0v4" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>Forfeit deposit</span>
                    </button>

                    <!-- Why a step cannot be taken yet, on the card: no hover on a tablet (level 2). -->
                    <p v-if="awaiting" class="bk-why">{{ WAIT_REASON }}</p>
                    </template>
                </div>
            </div>

            <!--
              The three acts, each in the console's modal (M95 §1), all closed on a fresh GET. Each names the entry it
              will post before the press, because on a screen that moves money the reader should know which way it is
              about to move; the service's refusal is said inside the dialog, a field's under its field.
            -->
            <ConsoleDialog
                :open="panel === 'hold'"
                :title="`Record the ${deposit.amount} deposit as received?`"
                size="form"
                confirm-label="Record as held"
                busy-label="Recording…"
                :busy="holdForm.processing"
                :dirty="String(holdForm.on).trim() !== ''"
                discard-title="Discard this date?"
                :error="holdForm.errors.deposit ?? null"
                @confirm="submitHold"
                @cancel="closePanel"
            >
                <p class="act-note">
                    {{ booking.reference }}. The estate is holding the household’s money and owes it back after the
                    event; nothing is charged to the unit.
                </p>
                <div class="act-field act-date">
                    <label for="act-hold-on">Received on (optional, blank for today)</label>
                    <input id="act-hold-on" v-model="holdForm.on" type="date" />
                    <div v-if="holdForm.errors.on" class="act-error">{{ holdForm.errors.on }}</div>
                </div>
            </ConsoleDialog>

            <ConsoleDialog
                :open="panel === 'refund'"
                :title="`Refund the ${deposit.amount} deposit?`"
                size="form"
                confirm-label="Refund deposit"
                busy-label="Refunding…"
                :busy="refundForm.processing"
                :dirty="String(refundForm.on).trim() !== ''"
                discard-title="Discard this date?"
                :error="refundForm.errors.deposit ?? null"
                @confirm="submitRefund"
                @cancel="closePanel"
            >
                <p class="act-note">
                    {{ booking.reference }}. The deposit goes back to the household, the reverse of taking it. A deposit
                    returned was never the estate’s to earn, so it never reaches income.
                </p>
                <div class="act-field act-date">
                    <label for="act-refund-on">Refunded on (optional, blank for today)</label>
                    <input id="act-refund-on" v-model="refundForm.on" type="date" />
                    <div v-if="refundForm.errors.on" class="act-error">{{ refundForm.errors.on }}</div>
                </div>
            </ConsoleDialog>

            <ConsoleDialog
                :open="panel === 'forfeit'"
                :title="`Keep the ${deposit.amount} deposit?`"
                size="form"
                tone="danger"
                confirm-label="Forfeit deposit"
                busy-label="Forfeiting…"
                :busy="forfeitForm.processing"
                :dirty="forfeitForm.reason.trim() !== '' || String(forfeitForm.amount).trim() !== ''"
                discard-title="Discard this reason?"
                :confirm-blocked-reason="forfeitFormBlocked"
                :error="forfeitForm.errors.deposit ?? null"
                @confirm="submitForfeit"
                @cancel="closePanel"
            >
                <p class="act-note">
                    {{ booking.reference }}. The deposit becomes the estate’s income, the only way a deposit ever does.
                    Keeping all of it moves no cash; it has been in the bank since it was taken. Keeping part of it
                    returns the rest to the household at the same time, so nothing is left held.
                </p>
                <RequiredMark legend />
                <div class="act-field">
                    <label for="act-forfeit-reason">
                        Why the estate is keeping it (the household will ask, and this is the committee's answer) <RequiredMark />
                    </label>
                    <textarea
                        id="act-forfeit-reason"
                        v-model="forfeitForm.reason"
                        rows="3"
                        required
                        maxlength="255"
                        placeholder="e.g. Gazebo returned with two chairs broken and the lighting rig down."
                    ></textarea>
                    <div v-if="forfeitForm.errors.reason" class="act-error">{{ forfeitForm.errors.reason }}</div>
                </div>
                <div class="act-field act-date">
                    <label for="act-forfeit-amount">Amount to keep, in dollars (optional, blank keeps all {{ deposit.amount }})</label>
                    <input
                        id="act-forfeit-amount"
                        v-model="forfeitForm.amount"
                        type="number"
                        inputmode="decimal"
                        min="0.01"
                        step="0.01"
                    />
                    <div v-if="forfeitForm.errors.amount" class="act-error">{{ forfeitForm.errors.amount }}</div>
                </div>
            </ConsoleDialog>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="Nothing on this booking matches a filter"
                body="The booking and its deposit are unchanged."
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No deposit is taken on this booking"
                body="Nothing is held, so there is nothing to refund or keep."
            />

            <!--
              The deposit's story, read off the entries that posted it. Three steps
              always, in order, and a step nobody has reached still occupies its
              place, greyed — so the reader can see what is left.
            -->
            <div v-else class="tl2">
                <div v-for="step in deposit.timeline" :key="step.key" class="tl2-row">
                    <div class="tl2-dot" :class="step.state">
                        <svg v-if="step.state === 'done'" viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="20 6 9 17 4 12"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <svg v-else-if="step.state === 'active'" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="5" fill="currentColor" />
                        </svg>
                    </div>

                    <div class="tl2-txt" :class="{ muted: step.state === 'pending' }">
                        <div class="tt1">{{ step.label }}</div>
                        <div v-if="step.line" class="tt2">{{ step.line }}</div>
                    </div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, the same removals board 18's page makes and for the same
 * reasons: the sheet draws its action stack as <div>s, and a <button> arrives
 * wearing the browser's font, border and buttonface grey. The border reset names
 * only the variants that have no border of their own — `.stack-btn.outline`'s
 * 1.5px navy edge is the whole difference between the two variants (D-045).
 */
button {
    font: inherit;
}

button.stack-btn.primary,
button.btn-primary-sm {
    border: 0;
}

button.stack-btn {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. No board draws this screen, so everything it adds
 * to board 18's shapes is kept to the tokens the boards define and the type
 * sizes .ts-meta and .tt2 already set. The three acts' forms are the console's
 * modal's body (M95 §1).
 */

/* The status badge's icon, beside its word (M96), as the diary draws it. */
.bk-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.bk-status svg {
    width: 11px;
    height: 11px;
    flex: 0 0 auto;
    display: block;
}

/*
 * The deposit's badge (M96 §3): the product's own badge treatments, colour, an icon and the word, and why a step waits
 * (level 2), on the card.
 */
.bk-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    align-self: flex-start;
    padding: 5px 11px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    line-height: 1.4;
}

.bk-badge svg {
    width: 13px;
    height: 13px;
    flex: 0 0 auto;
}

.bk-completed {
    background: var(--green-100);
    color: var(--green-700);
}

.bk-in_progress {
    background: var(--amber-100);
    color: var(--amber-700);
}

.bk-pending {
    background: var(--blue-100, var(--navy-100));
    color: var(--blue-700, var(--navy-700));
}

.bk-closed {
    background: transparent;
    border: 1px solid var(--navy-200);
    color: var(--slate-600);
}

.bk-why {
    margin: 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-500);
}

.bk-terms {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px 18px;
    margin: 14px 0 0;
}

.bk-terms dt {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    margin: 0 0 3px;
}

.bk-terms dd {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.5;
    margin: 0;
}

.act-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 12px;
}

.act-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-bottom: 11px;
}

.act-date {
    max-width: 240px;
}

.act-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.act-field input,
.act-field textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.act-field input {
    height: 34px;
    padding: 0 10px;
}

.act-field textarea {
    padding: 9px 10px;
    line-height: 1.55;
    resize: vertical;
}

.act-field textarea::placeholder {
    color: var(--text-placeholder);
    opacity: 1;
}

/* A field's refusal is amber, as the console's modal says its own (Hassam's three weights, 11 October). */
.act-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}
</style>
