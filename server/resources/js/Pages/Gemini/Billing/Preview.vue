<script setup>
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BackLink from '../../../Components/BackLink.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import { useLiveKind } from '../../../composables/useLiveKind'

// A preview is worked out at a moment, before anything is issued: "As of …" (M18H §7.2).
useLiveKind('snapshot')

/**
 * Invoice preview — a "Not yet invoiced" row's "Preview" (12 §2, item 44).
 *
 * NO BOARD DRAWS THIS SCREEN. It borrows the invoice screen's line rows so the
 * two read alike, and it says in words, first, that it is NOT an invoice: no
 * number, no due amount, nothing owed. A preview of a bill that looked like a
 * document would be a figure somebody forwards as one.
 *
 * THE INVOICE RUN'S OWN DRAFT (B076). Every line and the total are the ones
 * raising this period would post: the tier and the add-on priced on the
 * period's first day (M10 §3), and the line items in force during the period.
 * So the confirmation's "Raise … at J$X?" is the figure that is posted. It used
 * to read the line items in force TODAY, which quoted a total the raise did
 * not post whenever a line item started or ended inside the period — and an
 * issued invoice is corrected only by a credit note.
 */
const props = defineProps({
    projection: { type: Object, required: true },
    bill: { type: Object, required: true },
    /** The invoice run (13 B1) — where it posts, and whether it may. */
    raiseHref: { type: String, required: true },
    canRaise: { type: Boolean, required: true },
    /** Why a reader without Raise invoices cannot, naming that permission (M11 B3). */
    raiseDisabledReason: { type: String, required: true },
    raiseBlockedReason: { type: String, default: null },
})

/*
 * CONFIRMED BEFORE IT POSTS. Raising an invoice numbers it, posts it to the
 * client's receivable and can never be edited — a mistake is a credit note. So
 * the first press states what is about to happen, and the second does it.
 */
const confirming = ref(false)
const raising = ref(false)

const raiseTitle = () => {
    if (!props.canRaise) {
        return props.raiseDisabledReason
    }

    return props.raiseBlockedReason ?? `Raise ${props.projection.period} for ${props.projection.estate}. You confirm before it posts.`
}

/*
 * WHAT WAS CONFIRMED GOES WITH THE PRESS (B076). The run posts this period at
 * this total or refuses — a preview left open while a price changed, or while
 * somebody else raised the same month, cannot post a figure nobody confirmed.
 */
const raiseRefusal = ref(null)

const askRaise = () => {
    raiseRefusal.value = null
    confirming.value = true
}

const notNow = () => {
    confirming.value = false
    raiseRefusal.value = null
}

const raise = () => {
    raising.value = true
    raiseRefusal.value = null
    router.post(
        props.raiseHref,
        { period_start: props.projection.period_start, total_minor: props.projection.total_minor },
        {
            // A refusal stays in the question's amber band and the question stays open (Hassam, 12 October).
            onError: (errors) => {
                raiseRefusal.value = errors.invoice ?? Object.values(errors)[0] ?? 'The invoice was not raised.'
            },
            onFinish: () => {
                raising.value = false
            },
        },
    )
}
</script>

<template>
    <Head :title="`Preview · ${projection.estate}`" />

    <GeminiConsole title="Invoice preview">
        <!-- M95 §3: the shared back control, in words, where the bare round arrow was. -->
        <template #lead>
            <BackLink href="/billing" label="Back to billing" />
        </template>

        <!--
          M96 levels. A role that may never raise an invoice has no button, only why in one line (level 3). One that may,
          but not this period yet, has it greyed with the server's reason beside it (level 2).
        -->
        <template #actions>
            <template v-if="canRaise">
                <button
                    type="button"
                    class="btn-primary-sm"
                    :disabled="raiseBlockedReason !== null"
                    :title="raiseTitle()"
                    @click="askRaise"
                >
                    <span>Raise this invoice</span>
                </button>
                <span v-if="raiseBlockedReason" class="pv-why">{{ raiseBlockedReason }}</span>
            </template>
            <span v-else class="pv-why">{{ raiseDisabledReason }}</span>
        </template>

        <!-- Raising asks first, in the shared modal (M95 §1): it was a box that opened above the preview. -->
        <ConsoleDialog
            :open="confirming && canRaise && raiseBlockedReason === null"
            :title="`Raise ${projection.estate}'s invoice for ${projection.period}?`"
            tone="danger"
            confirm-label="Raise and post"
            busy-label="Raising…"
            cancel-label="Not now"
            :busy="raising"
            :error="raiseRefusal"
            @confirm="raise"
            @cancel="notNow"
        >
            <p style="margin: 0">
                At {{ bill.total.replace('/mo', '') }}. It is numbered, posted to the client's account and never
                edited: a mistake is corrected by a credit note. It is not emailed until you send it.
            </p>
        </ConsoleDialog>

        <div class="warn-banner">
            <BoardIcon name="warning" :stroke="1.7" />
            <div>
                <div class="wb1">A projection, not an invoice</div>
                <div class="wb2">
                    {{ projection.estate }} has not been invoiced for {{ projection.period }}. This is what raising that
                    invoice would post: the prices in force on the period's first day and the line items in force during
                    the period. It has no number and nothing is owed on it.
                </div>
            </div>
        </div>

        <div class="pv-card">
            <div class="pv-top">
                <div>
                    <div class="pv-name">{{ projection.estate }}</div>
                    <div class="pv-sub">{{ projection.period }} · expected due {{ projection.due_on }}</div>
                </div>
                <div class="pv-pill">Not yet invoiced</div>
            </div>

            <div class="pv-section">{{ bill.baseHead }}</div>
            <div v-for="(row, i) in bill.base" :key="'b' + i" class="line-item-row">
                <div class="li-desc">
                    <div class="li1">{{ row.name }}</div>
                    <div class="li2">{{ row.detail }}</div>
                </div>
                <div class="li-amt">{{ row.price }}</div>
            </div>

            <template v-if="bill.additions.length">
                <div class="pv-section">Additions for this client</div>
                <div v-for="(row, i) in bill.additions" :key="'a' + i" class="line-item-row">
                    <div class="li-desc">
                        <div class="li1">{{ row.name }}</div>
                        <div class="li2">{{ row.detail }}</div>
                    </div>
                    <div class="li-amt">{{ row.price }}</div>
                </div>
            </template>

            <template v-if="bill.removals.length">
                <div class="pv-section">Removals for this client</div>
                <div v-for="(row, i) in bill.removals" :key="'r' + i" class="line-item-row">
                    <div class="li-desc">
                        <div class="li1">{{ row.name }}</div>
                        <div class="li2">{{ row.detail }}</div>
                    </div>
                    <!-- The price carries its own sign, as on board 44. -->
                    <div class="li-amt">{{ row.price }}</div>
                </div>
            </template>

            <div class="line-total">
                <span>Projected monthly total</span>
                <span>{{ bill.total }}</span>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED around the invoice sheet's own line rows and warning banner. Kept to
 * the tokens the Gemini boards define.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}

button.text-link-sm {
    background: none;
    border: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/* Why Raise is greyed or absent, beside where it would be, readable without hovering (M96). */
.pv-why {
    max-width: 320px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.45;
    color: var(--slate-600);
}

.pv-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px 20px;
    margin-top: 14px;
    max-width: 820px;
}

.pv-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.pv-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
}

.pv-sub {
    font-size: 11.5px;
    color: var(--slate-500);
}

.pv-pill {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--amber-700);
    background: var(--amber-100);
    padding: 5px 11px;
    border-radius: 20px;
}

.pv-section {
    font-size: 11px;
    font-weight: 700;
    color: var(--slate-600);
    margin: 14px 0 4px;
}
</style>
