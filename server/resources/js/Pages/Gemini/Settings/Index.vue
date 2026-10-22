<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import SettingsTabs from './SettingsTabs.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// A settings form (M18H §7.2).
useLiveKind('form')

/**
 * Platform settings — board screen super-admin-42, the module's landing screen.
 *
 * Two panels: what the platform charges, and who holds an account on it.
 *
 * The rate card is two sources rendered as one list, and that is the board's
 * doing rather than a shortcut. Three rows are tier prices and come from
 * `plans`; the fourth is a per-guard charge that applies on top of ANY tier and
 * therefore cannot live on a plan. The board draws them as one card because
 * they answer one question — what does this platform charge — and the second
 * line on each row says which kind it is.
 *
 * THE RATE BOXES ARE THE WAY IN (M10 §3). The client's rule is that the
 * Director edits "the per-unit price of each tier, the per-guard add-on, and
 * any client line items" here, so a tier's box or the add-on's box opens the
 * change panel on that row — for a role holding Platform settings configure.
 * For anyone else the box stays the board's <div> holding a span, with the
 * reason on it, and "Save changes" renders disabled and says why. This said
 * "nothing here is editable" until M10 §3; the tier price stopped being read
 * only in Wave 4, and the add-on now joins it.
 *
 * A CHANGE IS SAID BEFORE IT IS SAVED. What it will affect and from when —
 * which billing period it is first invoiced in, which clients by name, and
 * which of them already hold an invoice that stays as it was — is asked of the
 * server as the tier and date change (`impact`), never worked out here, so the
 * panel cannot say one thing while the invoice run does another.
 *
 * The card's wrapper carries the board's own inline grid style, copied
 * verbatim, because the board's stylesheet defines no class for it.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    rates: { type: Array, required: true },
    admins: { type: Array, required: true },
    /** The tiers a price change can be made against, with what they cost now. */
    plans: { type: Array, required: true },
    /** The platform rates a change can be made against — the per-guard add-on — in the same shape. */
    editableRates: { type: Array, required: true },
    /** Changes recorded and not yet in force, tiers and rates together. */
    pending: { type: Array, required: true },
    /** The first day of the next billing period: when a change applies unless told otherwise. */
    nextPeriodStart: { type: String, required: true },
    /** The server's statement of what the change in the panel would affect, and from when. */
    impact: { type: Object, default: null },
    /** Whether anything is priced but retired — see the note below. */
    hasRetired: { type: Boolean, required: true },
    canWrite: { type: Boolean, required: true },
    saveDisabledReason: { type: String, required: true },
})

/* ------------------------------------------------------------------ */
/* changing a tier price or the per-guard add-on (12 §2 Wave 4; M10 §3) */
/* ------------------------------------------------------------------ */

/*
 * A DATE AND A REASON, AND NEITHER IS OPTIONAL. This re-prices every client on
 * the tier, or every client with guards deployed: the date is what stops it
 * being retroactive — an invoice already raised was raised at the price in
 * force — and the reason is what an auditor reads a year later instead of
 * asking somebody to remember.
 */
const editing = ref(false)

/** Every tier and every billed rate, as one list the panel picks from. `key` matches a rate-card row's `target`. */
const targets = computed(() => [
    ...props.plans.map((plan) => ({ key: `tier-${plan.id}`, kind: 'tier', id: plan.id, name: plan.name, price: plan.price, amount: plan.amount, per: 'unit' })),
    ...props.editableRates.map((rate) => ({ key: `rate-${rate.id}`, kind: 'rate', id: rate.id, name: rate.name, price: rate.price, amount: rate.amount, per: rate.basis })),
])

const targetOf = (key) => targets.value.find((target) => target.key === key) ?? null

const impactKey = () => (props.impact ? `${props.impact.kind}-${props.impact.id}` : null)

const initialKey = impactKey() ?? targets.value[0]?.key ?? null

const priceForm = useForm({
    target: initialKey,
    amount: targetOf(initialKey)?.amount ?? '',
    effective_from: props.impact?.effective_from ?? props.nextPeriodStart,
    reason: '',
})

const chosen = computed(() => targetOf(priceForm.target))

/*
 * WHETHER THE SERVER'S SENTENCE IS ABOUT WHAT IS IN THE FORM. While the tier or
 * the date has changed and the answer has not come back, the panel says it is
 * checking and the save waits — a read-back about a different change is worse
 * than none.
 */
const impactFresh = computed(
    () =>
        props.impact !== null &&
        impactKey() === priceForm.target &&
        props.impact.effective_from === priceForm.effective_from
)

let impactTimer = null

const askImpact = () => {
    clearTimeout(impactTimer)

    if (!priceForm.target || !priceForm.effective_from) {
        return
    }

    impactTimer = setTimeout(() => {
        router.get(
            '/settings',
            { change: priceForm.target, from: priceForm.effective_from },
            { only: ['impact'], preserveState: true, preserveScroll: true, replace: true }
        )
    }, 250)
}

onBeforeUnmount(() => clearTimeout(impactTimer))

// A different tier or rate starts from its own current amount, not the last one's.
watch(
    () => priceForm.target,
    (key) => {
        priceForm.amount = targetOf(key)?.amount ?? ''
        askImpact()
    }
)

watch(
    () => priceForm.effective_from,
    () => askImpact()
)

/** The move itself, echoed from the form. What it affects and from when is the server's — see `impact`. */
const priceMove = computed(() => {
    if (chosen.value === null) {
        return ''
    }

    return `${chosen.value.name}: ${chosen.value.price} per ${chosen.value.per} now, J$${priceForm.amount || '…'} per ${chosen.value.per} after the change.`
})

const refusal = computed(() => (impactFresh.value ? props.impact.refused : null))

const saveBlockedReason = computed(() => {
    if (priceForm.reason.trim() === '') {
        return 'Say why. An unexplained price change is the entry an auditor stops at.'
    }

    if (!priceForm.effective_from) {
        return 'Choose the date it takes effect from.'
    }

    if (!impactFresh.value) {
        return 'Still working out what this change affects. It can be saved once that is shown.'
    }

    return refusal.value
})

/*
 * THE PANEL IS BROUGHT TO THE READER (the client, 26 Sep: "why still director cannot change the pricing"). It opens
 * above the rate card, and a Director who pressed a price box further down the page saw nothing happen: the panel
 * had opened out of sight. Now it is scrolled into view and the new price is ready to type.
 */
const panelEl = ref(null)
const amountEl = ref(null)
const bringPanel = () =>
    nextTick(() => {
        panelEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
        amountEl.value?.focus({ preventScroll: true })
        amountEl.value?.select?.()
    })

const openEdit = () => {
    if (!props.canWrite) {
        return
    }

    priceForm.clearErrors()
    editing.value = !editing.value

    if (editing.value) {
        bringPanel()
    }
}

/** A rate-card box opens the panel on its own row. */
const openFor = (key) => {
    if (!props.canWrite || targetOf(key) === null) {
        return
    }

    priceForm.clearErrors()
    priceForm.target = key
    editing.value = true
    bringPanel()
}

const submitPrice = () => {
    if (chosen.value === null || saveBlockedReason.value) {
        return
    }

    const { kind, id } = chosen.value

    priceForm
        .transform((data) => ({
            [kind === 'tier' ? 'plan_id' : 'rate_id']: id,
            amount: data.amount,
            effective_from: data.effective_from,
            reason: data.reason,
        }))
        .post(kind === 'tier' ? '/settings/prices' : '/settings/rates', {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = false
                priceForm.reason = ''

                // The redirect lands on the default question; the form follows
                // it, so the next opening is not left waiting on an answer about
                // the change just saved.
                priceForm.target = impactKey() ?? targets.value[0]?.key ?? null
                priceForm.effective_from = props.impact?.effective_from ?? props.nextPeriodStart
                priceForm.amount = targetOf(priceForm.target)?.amount ?? ''
            },
        })
}

const formErrors = computed(() => Object.values(priceForm.errors).filter(Boolean))

/*
 * "Filtered" on this screen is `is_active`, which is a real column and a real
 * filter: a retired plan keeps its rows and its history and simply stops being
 * quoted. Empty-because-everything-is-retired is a different screen from
 * empty-because-nothing-was-ever-priced — one says the rate card was emptied,
 * the other that it was never filled — so they are told apart here rather than
 * sharing one blank panel.
 */
const state = useScreenState({
    rows: () => props.rates.length,
    filtered: () => props.hasRetired,
})
</script>

<template>
    <Head title="Platform settings" />

    <GeminiConsole title="Platform settings">
        <template #actions>
            <!--
              The board draws this as a <div>. It opens the change panel rather
              than saving a form the board does not have: a price is changed one
              at a time, from a date, with a reason — see the panel.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canWrite || targets.length === 0"
                :title="canWrite ? 'Change a tier price or the per-guard add-on, from a date, with the reason. The panel says which clients it reaches and from which billing period; nothing already invoiced changes.' : saveDisabledReason"
                @click="openEdit"
            >
                <!-- "Change a price", not the board's "Save changes": it opens the panel, it saves nothing. -->
                <span>Change a price</span>
            </button>
        </template>

        <SettingsTabs :tabs="tabs" />


        <!--
          AUTHORED. The board draws a rate card nobody is changing, so it has no
          panel and no pending list.
        -->
        <form v-if="editing" ref="panelEl" class="ps-panel" @submit.prevent="submitPrice">
            <div class="ps-head">
                A tier price re-prices every client on it, and the per-guard add-on every client with guards deployed.
                The date is what stops it being retroactive — an invoice already raised was raised at the price in
                force — and the reason is what an auditor reads instead of asking somebody to remember.
            </div>

            <div class="ps-fields">
                <div class="ps-field">
                    <label for="ps-plan">Tier or rate</label>
                    <select id="ps-plan" v-model="priceForm.target" required>
                        <option v-for="target in targets" :key="target.key" :value="target.key">
                            {{ target.name }} — {{ target.price }}/{{ target.per }}
                        </option>
                    </select>
                </div>
                <div class="ps-field">
                    <label for="ps-amount">New price per {{ chosen?.per ?? 'unit' }}, J$</label>
                    <input id="ps-amount" ref="amountEl" v-model="priceForm.amount" type="text" inputmode="decimal" required />
                </div>
                <div class="ps-field">
                    <label for="ps-from">In force from</label>
                    <input id="ps-from" v-model="priceForm.effective_from" type="date" required />
                </div>
                <div class="ps-field ps-field--wide">
                    <label for="ps-reason">Why</label>
                    <input id="ps-reason" v-model="priceForm.reason" type="text" required maxlength="255" />
                </div>
            </div>

            <!--
              WHAT IT WILL AFFECT AND FROM WHEN (M10 §3). The first line echoes
              the form; every line after it is the server's.
            -->
            <div class="ps-read" aria-live="polite">
                <p>{{ priceMove }}</p>
                <template v-if="impactFresh">
                    <p v-for="(line, i) in impact.lines" :key="i">{{ line }}</p>
                </template>
                <p v-else-if="!priceForm.effective_from">Choose the date it takes effect from, and this will say which invoices it reaches.</p>
                <p v-else>Working out which clients and which billing period this reaches…</p>
            </div>

            <div v-for="(message, i) in formErrors" :key="'err-' + i" class="ps-error">{{ message }}</div>

            <div class="ps-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="priceForm.processing || Boolean(saveBlockedReason)"
                    :title="saveBlockedReason || 'Record the change, with the reason, as the panel above describes it.'"
                >
                    <span>{{ priceForm.processing ? 'Saving…' : 'Change the price' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="editing = false">Cancel</button>
            </div>
        </form>

        <!--
          Keyed on `key`, never `id`: tier changes and rate changes come from
          two tables that both count from 1, so their ids collide.
        -->
        <div v-if="pending.length" class="ps-pending">
            <div class="ps-head">Recorded and not yet in force</div>
            <div v-for="change in pending" :key="`${change.kind}-${change.id}`" class="ps-pending-row">
                <span>
                    <b>{{ change.plan }}</b> {{ change.from }} → {{ change.to }} per {{ change.per }} from
                    {{ change.effective_from }} · first invoiced for {{ change.first_billed }}
                </span>
                <span class="ps-pending-why">{{ change.reason }} · {{ change.by }}</span>
            </div>
        </div>

        <EmptyState
            v-if="state.isDenied.value"
            variant="denied"
            title="Platform settings is not part of your role's access"
            body="This module holds what every client is charged and who can reach what across the platform, so it opens only to roles that hold it outright. Yours does not."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The rate card could not be loaded"
            body="The platform database did not answer. No price has been changed and nothing is lost — the rates are still on file to be read."
        />

        <div v-else style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
            <div class="panel">
                <div class="panel-head">
                    <h3>Subscription tier pricing</h3>
                </div>

                <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="2" />

                <EmptyState
                    v-else-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="Every plan and rate is retired"
                    body="Nothing is being quoted. Retired plans keep their subscriptions, their invoices and their history — they are simply no longer offered, and marking one active again brings it back to this card."
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="Nothing is priced yet"
                    body="A tier appears here once it has a per-unit price, and a charge that applies on top of any tier appears beneath them. Until then this platform quotes nothing."
                />

                <template v-else>
                    <div v-for="rate in rates" :key="rate.target" class="rate-row">
                        <div>
                            <div class="rn">{{ rate.name }}</div>
                            <div class="rd">{{ rate.detail }}</div>
                        </div>
                        <!--
                          The board's box, as a button for a role that may change
                          it (M10 §3): it opens the panel on this row. Otherwise
                          it stays the board's <div>, saying why.
                        -->
                        <button
                            v-if="canWrite && rate.editable"
                            type="button"
                            class="rate-input"
                            :class="{ 'rate-input--chosen': editing && priceForm.target === rate.target }"
                            :title="`Change ${rate.name} from a date, with the reason. The panel says which clients it reaches and from which billing period.`"
                            @click="openFor(rate.target)"
                        >
                            <span>{{ rate.amount }}</span>
                            <!-- Says it can be pressed: a box that looks like a field and opens a panel was not found. -->
                            <span class="rate-change" aria-hidden="true">Change</span>
                        </button>
                        <div
                            v-else
                            class="rate-input"
                            :title="canWrite ? 'Nothing invoices this rate yet — only the per-guard add-on is billed — so changing it would change no bill.' : saveDisabledReason"
                        >
                            <span>{{ rate.amount }}</span>
                        </div>
                    </div>
                </template>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h3>Platform administrators</h3>
                </div>

                <SkeletonRows v-if="state.isLoading.value" :rows="2" :columns="2" />

                <EmptyState
                    v-else-if="admins.length === 0"
                    variant="first-use"
                    title="Nobody holds a platform account"
                    body="Anyone carrying a Gemini Console role appears here, with what that role can reach. Accounts are issued by invitation and never self-created."
                />

                <template v-else>
                    <div v-for="admin in admins" :key="admin.name" class="admin-row">
                        <div class="res-avatar">{{ admin.initials }}</div>
                        <div>
                            <div class="an">{{ admin.name }}</div>
                            <div class="ar">{{ admin.detail }}</div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every line removes a browser
 * default rather than adding a style.
 *
 * The board draws "Save changes" as a <div>. It is a real <button> here, and a
 * button arrives with a border, a background and Arial. The board's own
 * .btn-primary-sm rule already sets the height, padding, radius, background and
 * shadow, so only what the UA adds is taken off.
 */
button.btn-primary-sm {
    appearance: none;
    border: 0;
    font-family: inherit;
}

/* Inert, and it says why on hover. No opacity change: the board draws this
 * button at one weight, and dimming it would be a pixel the design does not
 * have. */
button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.btn-primary-sm {
    cursor: pointer;
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
 * The board's .rate-input box as a <button> (M10 §3). The board's own rule
 * supplies the size, radius, ground and a transparent border; only what the UA
 * adds to a button is taken off, and the border the board leaves transparent
 * is what shows the box can be pressed, and which one the panel is on.
 */
button.rate-input {
    font: inherit;
    text-align: left;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.rate-change {
    font-size: 11px;
    font-weight: 700;
    color: var(--navy-600);
}

button.rate-input:hover,
button.rate-input:focus-visible,
button.rate-input.rate-input--chosen {
    border-color: var(--navy-200);
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a rate card nobody is changing, so
 * it has no panel, no flash and no pending list. Kept to the tokens the Gemini
 * boards define. What a change did is the shell's toast; its refusal is said inside the change's own panel.
 */
.ps-panel,
.ps-pending {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 17px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.ps-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 820px;
}

.ps-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 11px;
}

.ps-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.ps-field--wide {
    grid-column: span 3;
}

.ps-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.ps-field input,
.ps-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.ps-read {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.55;
    margin: 0;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.ps-read p {
    margin: 0;
}

.ps-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.ps-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.ps-pending-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 14px;
    font-size: 11.5px;
    color: var(--navy-900);
    line-height: 1.6;
    border-top: 1px solid var(--navy-100);
    padding-top: 7px;
}

.ps-pending-why {
    font-size: 10.5px;
    color: var(--slate-500);
}
</style>
