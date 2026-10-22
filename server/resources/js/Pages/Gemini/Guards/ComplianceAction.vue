<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import { toast } from '../../../lib/toasts.js'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// Recording a compliance action is a form (M18H §7.2).
useLiveKind('form')

/**
 * Compliance action — board screen super-admin-21.
 *
 * The profile (19 and 23) SHOWS an open compliance case. This screen is where
 * it is acted on, and the difference matters: everything here either changes
 * the record or says plainly why it cannot.
 *
 * DOM, classes and label text are the board's, including the two inline grid
 * and flex styles, which are the board's own and stay inline rather than being
 * promoted to classes the design does not define.
 *
 * Four controls, and only one of them is live — which is the honest count
 * rather than a shortfall:
 *
 *   Suspend from active duty   REAL. A POST behind `guard_workforce.update`
 *                              that writes the guard's status and appends to
 *                              the audit log. The single destructive act on
 *                              this board, and the one it exists for.
 *
 *   Contact about renewal      REAL. A tel: or mailto: link built from the
 *                              record, so it dials the number on file rather
 *                              than a number somebody typed onto a board.
 *
 *   Post open shifts to cover  There is no shift roster yet. Disabled, saying
 *                              so, rather than a button that looks live.
 *
 *   Mark licence renewed       A renewal is a NEW EXPIRY DATE read off the
 *                              renewed certificate, not a flag. The same
 *                              refusal, in the same words, as the profile.
 *
 * D-034 governs what suspension does NOT do. The guard keeps their post and
 * their estate, so Phoenix Park Village 1 goes on seeing that the officer at
 * their Service Gate is the one who cannot legally stand it. Deployed means
 * posted at the estate, not compliant, and clearing the posting here would tidy
 * the client's screen while the problem stood at their gate.
 */
const props = defineProps({
    action: { type: Object, required: true },
    can_act: { type: Boolean, required: true },
    /** How many future shifts releasing would actually open (12 §2, Wave 5). */
    future_shifts: { type: Number, default: 0 },
})

/**
 * Five of the six.
 *
 * `empty` is a real state and not a contrivance: this URL is a guard's id, and
 * a guard whose licence is in order has no matter to act on. It is reached by
 * following a stale link, or by opening the case a colleague closed a minute
 * ago, and it must say so rather than draw an action panel over nothing.
 *
 * `empty-filtered` cannot happen. One guard, one matter, no query — there is no
 * filter that could have excluded anything, and drawing a "clear the filter"
 * panel on a screen with no filter would invent a control to explain a state
 * that does not exist. Forcing it falls through to the populated screen.
 */
const state = useScreenState({
    rows: () => (props.action.case === null ? 0 : 1),
})

const form = useForm({})

// A refusal that reached the server anyway (a stale page, a POST made by hand) is what just happened, pressed with no
// dialog open: a toast that stays until closed (Hassam, 12 October). What a press did is the shell's toast.
const suspend = () =>
    form.post(`/guards/${props.action.id}/suspend`, {
        preserveScroll: true,
        onError: (errors) => {
            if (errors.guard) {
                toast(`Nothing was changed. ${errors.guard}`, { tone: 'error' })
            }
        },
    })

/**
 * Why "Suspend from active duty" cannot be pressed, or null when it can.
 *
 * The role's reason comes first: someone who may not act at all does not need
 * to be told the licence is fine, they need to be told the button is not
 * theirs. Everything after that is the service's own sentence, so the control
 * and the rule behind it cannot drift apart.
 */
const suspendBlocked = computed(() => {
    if (!props.can_act) {
        return 'Suspending an officer changes an employment record, so it needs the Guard workforce module at full access. Your role holds it for reading only.'
    }

    return props.action.suspend_blocked_reason
})

/* ------------------------------------------------------------------ */
/* the two writes (12 §2, Wave 5) */
/* ------------------------------------------------------------------ */

/*
 * A RENEWAL IS A NEW EXPIRY DATE READ OFF THE CERTIFICATE, which is what this
 * control's old reason said it needed. The server refuses a date that does not
 * extend the licence on file — one dated on or before it is a typo that would
 * leave the officer non-compliant while the screen said otherwise.
 */
const renewing = ref(false)

const renewalPrompt = computed(
    () =>
        `Record ${props.action.name}'s renewed PSRA licence — the new expiry date printed on it, and the number. ` +
        'A suspension is not lifted by this.'
)

const renewForm = useForm({ psra_expires_on: '', psra_number: props.action.psra_number ?? '' })

const submitRenewal = () => {
    if (renewForm.psra_expires_on === '') {
        return
    }

    renewForm.post(`/guards/${props.action.id}/licence`, {
        preserveScroll: true,
        onSuccess: () => {
            renewing.value = false
        },
    })
}

/*
 * RELEASING IS FUTURE-ONLY. A shift with an actual start is the evidence a post
 * was covered; clearing its officer would erase who covered it. What this does
 * is make tomorrow's gaps visible so somebody can fill them.
 */
const releasing = ref(false)

const releaseForm = useForm({ reason: '' })

const submitRelease = () => {
    if (releaseForm.reason.trim() === '') {
        return
    }

    releaseForm.post(`/guards/${props.action.id}/release-shifts`, {
        preserveScroll: true,
        onSuccess: () => {
            releasing.value = false
            releaseForm.reset()
        },
    })
}

const retry = () => router.reload()
</script>

<template>
    <Head :title="`${action.name} — compliance action`" />

    <GeminiConsole :title="`${action.name} · compliance action`" :back="{ href: `/guards/${action.id}`, label: `Back to ${action.name}` }">

        <div v-if="state.isLoading.value" class="info-panel">
            <SkeletonRows :rows="6" :columns="2" />
        </div>

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This compliance case is not part of your role's access"
            body="A compliance matter names the officer, the estate and the post it affects, so it opens only to roles that hold the Guard workforce module. Yours does not."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This compliance case could not be loaded"
            body="The platform database did not answer. Nothing has been suspended and nothing has been recorded — the case is exactly as it was."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            :title="`There is no open compliance matter against ${action.name}`"
            body="A case opens by itself the moment a PSRA licence lapses or is found to have no expiry on file, and closes the moment a current one is recorded. There is nothing to act on here."
            action-label="Open the guard's record"
            @action="router.get(`/guards/${action.id}`)"
        />

        <template v-else>
            <!-- The case itself: a standing fact about this officer, so a banner that stays. -->
            <div class="crit-banner">
                <BoardIcon name="warning" />
                <div>
                    <div class="cb1">{{ action.case.headline }}</div>
                    <div class="cb2">{{ action.case.detail }}</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
                <div>
                    <div class="info-panel">
                        <div class="info-panel-head">What this affects</div>
                        <div v-for="impact in action.impacts" :key="impact.title" class="impact-row">
                            <div class="impact-icon">
                                <BoardIcon :name="impact.icon" :stroke="1.6" />
                            </div>
                            <div class="impact-txt">
                                <div class="im1">{{ impact.title }}</div>
                                <div class="im2">{{ impact.meta }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="info-panel">
                        <div class="info-panel-head">Take action</div>
                        <div style="display: flex; flex-direction: column; gap: 10px">
                            <button
                                type="button"
                                class="stack-btn danger"
                                :disabled="suspendBlocked !== null || form.processing"
                                :title="suspendBlocked"
                                @click="suspend"
                            >
                                <BoardIcon name="x-circle" :stroke="1.7" />
                                <span>{{ form.processing ? 'Suspending…' : 'Suspend from active duty' }}</span>
                            </button>

                            <a v-if="action.contact_href" :href="action.contact_href" class="stack-btn outline">
                                <BoardIcon name="broadcast" :stroke="1.7" />
                                <span>Contact {{ action.first_name }} about renewal</span>
                            </a>
                            <button
                                v-else
                                type="button"
                                class="stack-btn outline"
                                disabled
                                title="No phone number or email address is on this guard's record"
                            >
                                <BoardIcon name="broadcast" :stroke="1.7" />
                                <span>Contact {{ action.first_name }} about renewal</span>
                            </button>

                            <!--
                              Releasing this officer's FUTURE shifts back to
                              open (12 §2, Wave 5). Never one already worked —
                              a shift with an actual start is the evidence a
                              post was covered, and clearing its officer would
                              erase who covered it.
                            -->
                            <button
                                type="button"
                                class="stack-btn outline"
                                :disabled="!can_act || future_shifts === 0 || releaseForm.processing"
                                :title="
                                    !can_act
                                        ? 'Opening an officer\'s shifts changes the rota, so it needs Guard workforce update access.'
                                        : future_shifts === 0
                                          ? `${action.first_name} holds no future shifts, so there is nothing to open.`
                                          : `Release ${action.first_name}'s ${future_shifts} future shift(s) back to open so somebody can pick them up. Shifts already worked are untouched.`
                                "
                                @click="releasing = !releasing"
                            >
                                <BoardIcon name="plus" :stroke="2" />
                                <span>Post open shifts to cover</span>
                            </button>

                            <button
                                type="button"
                                class="stack-btn outline"
                                :disabled="!can_act"
                                :title="can_act ? renewalPrompt : 'Recording a renewal changes whether this officer may legally stand a post, so it needs Guard workforce update access.'"
                                @click="renewing = !renewing"
                            >
                                <BoardIcon name="check-circle" />
                                <span>Mark licence renewed</span>
                            </button>
                        </div>

                        <!--
                          AUTHORED. The board draws a case nobody is acting on,
                          so it has neither panel.
                        -->
                        <form v-if="releasing" class="ca-panel" @submit.prevent="submitRelease">
                            <div class="ca-head">
                                {{ future_shifts }} future shift(s) go back to open. A dispatcher picking one up reads
                                the reason, so it says whether the estate asked for cover or an officer was stood down.
                            </div>
                            <label for="ca-reason">Why</label>
                            <input
                                id="ca-reason"
                                v-model="releaseForm.reason"
                                type="text"
                                required
                                maxlength="190"
                                placeholder="PSRA licence lapsed — suspended from duty"
                            />
                            <div v-if="releaseForm.errors.reason" class="ca-error">{{ releaseForm.errors.reason }}</div>
                            <div class="ca-actions">
                                <button
                                    type="submit"
                                    class="btn-primary-sm"
                                    :disabled="releaseForm.processing || releaseForm.reason.trim() === ''"
                                    :title="releaseForm.reason.trim() === '' ? 'Say why.' : 'Open the shifts.'"
                                >
                                    <span>{{ releaseForm.processing ? 'Opening…' : 'Open the shifts' }}</span>
                                </button>
                                <button type="button" class="text-link-sm" @click="releasing = false">Cancel</button>
                            </div>
                        </form>

                        <form v-if="renewing" class="ca-panel" @submit.prevent="submitRenewal">
                            <div class="ca-head">
                                A renewal is the new expiry date read off the certificate, not a flag. A suspension is
                                not lifted by it — that was a decision somebody took, and lifting it is its own.
                            </div>
                            <div class="ca-fields">
                                <div class="ca-field">
                                    <label for="ca-expires">New expiry date</label>
                                    <input id="ca-expires" v-model="renewForm.psra_expires_on" type="date" required />
                                </div>
                                <div class="ca-field">
                                    <label for="ca-number">PSRA number on the certificate</label>
                                    <input id="ca-number" v-model="renewForm.psra_number" type="text" required maxlength="40" />
                                </div>
                            </div>
                            <div v-if="renewForm.errors.psra_expires_on" class="ca-error">
                                {{ renewForm.errors.psra_expires_on }}
                            </div>
                            <div class="ca-actions">
                                <button
                                    type="submit"
                                    class="btn-primary-sm"
                                    :disabled="renewForm.processing || renewForm.psra_expires_on === ''"
                                    title="Record the renewal."
                                >
                                    <span>{{ renewForm.processing ? 'Recording…' : 'Record renewal' }}</span>
                                </button>
                                <button type="button" class="text-link-sm" @click="renewing = false">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Reset only. The board draws all four action controls as <div>s and the back
 * chevron as an inline-styled <div>; the real buttons and links that replace
 * them arrive with a browser's own border, font, background and underline.
 * These rules take exactly those defaults back off, and introduce no colour,
 * spacing or size the board does not already declare.
 *
 * `.danger` and `.outline` are named rather than `button.stack-btn`, because a
 * scoped element selector outranks a single class: a blanket border reset would
 * beat .stack-btn.outline and strip the 1.5px edge that is the whole difference
 * between the variants. Each variant's own border is restated from the board's
 * own rule so the reset cannot silently remove it.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

/* The board's own .stack-btn.danger and .stack-btn.outline borders, restated so
 * the <button> default border is replaced rather than merely removed. */
button.stack-btn.danger {
    border: 1.5px solid var(--red-700);
}

button.stack-btn.outline {
    border: 1.5px solid var(--navy-200);
}

/* A disabled <button> is dimmed and greyed by the UA. The board draws these
 * controls at one weight, and the reason they cannot be pressed is on the
 * title rather than in the pixels. */
button.stack-btn[disabled] {
    opacity: 1;
    cursor: not-allowed;
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
 * AUTHORED BELOW THIS LINE. The board draws a case nobody is acting on, so it
 * has neither the release panel nor the renewal panel. Kept to the tokens the
 * Gemini boards define.
 */
.ca-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 14px 15px;
    margin-top: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ca-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
}

.ca-panel > label,
.ca-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.ca-panel > input,
.ca-field input {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.ca-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.ca-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.ca-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.ca-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
