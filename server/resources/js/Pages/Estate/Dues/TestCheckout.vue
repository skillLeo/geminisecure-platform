<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'
import {
    BRAND_NAME,
    DECLINE_SENTENCE,
    NOTHING_PAID,
    NOT_A_TEST_CARD,
    TEST_CARDS,
    TEST_MODE_NOTE,
    brandOf,
    cardErrors,
    cvcLengthFor,
    digitsOf,
    formatCardNumber,
    formatExpiry,
    isRealCard,
    lastFour,
    outcomeOf,
    testReference,
} from '../../../lib/testCheckout'
import { toast } from '../../../lib/toasts.js'

/**
 * THE TEST CARD CHECKOUT — how paying a unit's dues by card will look once the estate chooses a card gateway.
 * No board draws it; it is the Stripe checkout's own shape (the number, the expiry, the security code, the
 * name, then Pay) in the arrears board's tokens, opened from a unit's ledger.
 *
 * A PREVIEW, NEVER A PAYMENT (DuesController::testCheckout, lib/testCheckout.js):
 *   - PREVIEW is said at the top, on the card, on the button and on the result (DR-052);
 *   - only Stripe's published test numbers are taken, and a real one is wiped the moment it is recognised.
 *     The fields ask the browser and password managers not to offer a saved card;
 *   - "Pay" makes NO request — there is no route that could take what is typed here — and nothing is kept:
 *     the fields are this page's memory, gone when it closes. Nothing is posted and no receipt is allocated.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    unit: { type: Object, required: true },
    balance_minor: { type: Number, required: true },
    /** This month at the estate, `{ year, month }`, month 1–12. */
    today: { type: Object, required: true },
    timezone: { type: String, required: true },
})

useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

// A checkout being filled in: nothing on it goes stale, and nothing may re-read it under the person's typing (M18H §7.2).
useLiveKind('form')

const page = usePage()

/** The ledger this was opened from: this page's own URL, less its last segment. */
const ledgerHref = computed(() => page.url.replace(/\/test-checkout(\?.*)?$/, ''))

const money = (minor) =>
    new Intl.NumberFormat('en-JM', { style: 'currency', currency: 'JMD', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(minor / 100)

/* The amount to pretend to pay: what the household owes, or a round test figure when it owes nothing. */
const amountText = ref(((props.balance_minor > 0 ? props.balance_minor : 100000) / 100).toFixed(2))
const amountMinor = computed(() => {
    const cleaned = String(amountText.value).replace(/[^\d.]/g, '')
    if (!/^\d+(\.\d{1,2})?$/.test(cleaned)) return null
    const minor = Math.round(Number(cleaned) * 100)
    return minor > 0 && minor <= 99999999999 ? minor : null
})

const EMPTY = { number: '', expiry: '', cvc: '', name: '' }
const form = reactive({ ...EMPTY })
const touched = reactive({})
const tried = ref(false)
const processing = ref(false)
const result = ref(null)
let timer = null
onBeforeUnmount(() => clearTimeout(timer))

const brand = computed(() => brandOf(form.number))
const errors = computed(() => cardErrors(form, props.today))
const show = (field) => (tried.value || touched[field] ? (errors.value[field] ?? null) : null)
const amountError = computed(() => (amountMinor.value === null && (tried.value || touched.amount) ? 'Enter an amount to pay, like 12400.00.' : null))

const onNumber = (event) => {
    const next = formatCardNumber(event.target.value)
    // A real card is not held here for a moment longer than it takes to recognise it; why it went is a toast (M95 §2).
    if (isRealCard(next)) {
        form.number = ''
        event.target.value = ''
        toast(NOT_A_TEST_CARD, { tone: 'warning', key: 'test-card-wiped' })
        return
    }
    form.number = next
    event.target.value = next
}

const onExpiry = (event) => {
    form.expiry = formatExpiry(event.target.value, form.expiry)
    event.target.value = form.expiry
}

const onCvc = (event) => {
    form.cvc = digitsOf(event.target.value).slice(0, cvcLengthFor(brand.value))
    event.target.value = form.cvc
}

const pick = (card) => {
    tried.value = false
    Object.keys(touched).forEach((key) => delete touched[key])
    form.number = formatCardNumber(card.number)
    form.expiry = '12 / 34'
    form.cvc = card.brand === 'amex' ? '1234' : '123'
}

// "Sep 28, 2026, 6:16 PM Jamaica time" — the estate's clock, named (M19A §4.2).
const whenAtEstate = (ms) =>
    `${new Intl.DateTimeFormat('en-US', { timeZone: props.timezone, month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(ms)} ${String(props.timezone).split('/').pop().replace(/_/g, ' ')} time`

const pay = () => {
    if (processing.value) return
    tried.value = true
    if (Object.keys(errors.value).length > 0 || amountMinor.value === null) return
    processing.value = true
    timer = setTimeout(() => {
        processing.value = false
        const outcome = outcomeOf(form.number)
        if (outcome !== 'succeeded') {
            // The preview's refusal is a toast (M95 §2), as every refusal is: it was an alert inside the form.
            toast(`${DECLINE_SENTENCE[outcome]} Nothing was charged. This card number is always refused this way.`, { tone: 'warning', key: 'test-card-declined' })
            return
        }
        const at = Date.now()
        result.value = {
            reference: testReference(at),
            when: whenAtEstate(at),
            card: `${BRAND_NAME[brand.value]} ${lastFour(form.number)}`,
            amount: money(amountMinor.value),
        }
        // The card leaves this page's memory with the result: only its brand and last four are kept, to show.
        Object.assign(form, EMPTY)
        tried.value = false
        Object.keys(touched).forEach((key) => delete touched[key])
    }, 1600)
}

const again = () => {
    result.value = null
}

const cardShown = computed(() => (digitsOf(form.number) ? formatCardNumber(form.number) : '•••• •••• •••• ••••'))
</script>

<template>
    <!--
      A PREVIEW, AND IT SAYS SO (DR-052; M77 §6): the word for a trial run, set beside a household's dues on the Pay button,
      read as if the system were a trial. Nothing here charges, sends or posts, and every place that could be mistaken
      for a payment says preview.
    -->
    <Head title="Card payment preview" />

    <!-- A nested page: the way back to the unit's ledger, in words, top left (M95 §3). -->
    <EstateConsole
        :title="`Card payment preview · ${unit.reference}`"
        :estate-name="estate.name"
        active="dues_ledger"
        :back="{ href: ledgerHref, label: `Back to ${unit.reference}'s ledger` }"
    >

        <div class="tc-banner" role="note">
            <span class="tc-badge">PREVIEW</span>
            <p>{{ TEST_MODE_NOTE }}</p>
        </div>

        <div v-if="result" class="tc-done" role="status">
            <div class="tc-done-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </div>
            <span class="tc-badge">PREVIEW</span>
            <h2>The preview went through</h2>
            <div class="tc-done-amount">{{ result.amount }}</div>
            <dl class="tc-done-rows">
                <div><dt>Unit</dt><dd>{{ unit.title }}</dd></div>
                <div><dt>Card</dt><dd>{{ result.card }}</dd></div>
                <div><dt>Reference</dt><dd>{{ result.reference }}</dd></div>
                <div><dt>When</dt><dd>{{ result.when }}</dd></div>
            </dl>
            <p class="tc-nothing">{{ NOTHING_PAID }}</p>
            <div class="tc-done-actions">
                <Link :href="ledgerHref" class="tc-btn tc-btn--primary">Back to the ledger</Link>
                <button type="button" class="tc-btn tc-btn--outline" @click="again">Try another card number</button>
            </div>
        </div>

        <div v-else class="tc-grid">
            <form class="tc-checkout" novalidate autocomplete="off" data-lpignore="true" data-form-type="other" @submit.prevent="pay">
                <div class="tc-summary">
                    <div>
                        <div class="tc-muted">Paying</div>
                        <div class="tc-strong">{{ unit.title }}</div>
                        <div class="tc-muted">{{ unit.household }} · owes {{ money(balance_minor) }}</div>
                    </div>
                    <label class="tc-amount">
                        <span>Amount (JMD)</span>
                        <input v-model="amountText" inputmode="decimal" autocomplete="off" :disabled="processing" @blur="touched.amount = true" />
                    </label>
                </div>
                <p v-if="amountError" class="tc-error" role="alert">{{ amountError }}</p>

                <label class="tc-label" for="tc-number">Card number</label>
                <div class="tc-card-box" :class="{ 'tc-card-box--error': show('number') || show('expiry') || show('cvc') }">
                    <input
                        id="tc-number"
                        class="tc-input tc-input--number"
                        :value="form.number"
                        inputmode="numeric"
                        maxlength="19"
                        placeholder="4242 4242 4242 4242"
                        autocomplete="off"
                        data-lpignore="true"
                        data-1p-ignore
                        :disabled="processing"
                        @input="onNumber"
                        @blur="touched.number = true"
                    />
                    <div class="tc-card-row">
                        <input
                            class="tc-input"
                            :value="form.expiry"
                            inputmode="numeric"
                            maxlength="7"
                            placeholder="MM / YY"
                            aria-label="Expiry date"
                            autocomplete="off"
                            data-lpignore="true"
                            data-1p-ignore
                            :disabled="processing"
                            @input="onExpiry"
                            @blur="touched.expiry = true"
                        />
                        <input
                            class="tc-input"
                            :value="form.cvc"
                            inputmode="numeric"
                            maxlength="4"
                            :placeholder="brand === 'amex' ? '1234' : 'CVC'"
                            aria-label="Security code"
                            autocomplete="off"
                            data-lpignore="true"
                            data-1p-ignore
                            :disabled="processing"
                            @input="onCvc"
                            @blur="touched.cvc = true"
                        />
                    </div>
                </div>
                <p v-for="field in ['number', 'expiry', 'cvc']" v-show="show(field)" :key="field" class="tc-error" role="alert">{{ show(field) }}</p>

                <label class="tc-label" for="tc-name">Name on card</label>
                <input
                    id="tc-name"
                    v-model="form.name"
                    class="tc-input tc-input--solo"
                    :class="{ 'tc-input--error': show('name') }"
                    maxlength="80"
                    placeholder="As it is printed on the card"
                    autocomplete="off"
                    data-lpignore="true"
                    data-1p-ignore
                    :disabled="processing"
                    @blur="touched.name = true"
                />
                <p v-if="show('name')" class="tc-error" role="alert">{{ show('name') }}</p>

                <button type="submit" class="tc-btn tc-btn--primary tc-pay" :disabled="processing" :aria-busy="processing">
                    <span v-if="processing" class="tc-spinner" aria-hidden="true"></span>
                    {{ processing ? 'Processing the preview…' : amountMinor === null ? 'Pay (preview)' : `Pay ${money(amountMinor)} (preview)` }}
                </button>

                <p class="tc-secure">
                    Nothing typed here leaves this browser: Pay makes no request, and nothing is saved. When card payments are switched on, the card
                    will go straight to the payment provider, never to the estate’s server.
                </p>
            </form>

            <aside class="tc-side">
                <div class="tc-card-picture" aria-hidden="true">
                    <div class="tc-cp-top">
                        <span class="tc-badge tc-badge--dark">PREVIEW</span>
                        <span class="tc-cp-brand">{{ BRAND_NAME[brand] }}</span>
                    </div>
                    <div class="tc-cp-number">{{ cardShown }}</div>
                    <div class="tc-cp-bottom">
                        <span>{{ form.name.trim() ? form.name.trim().toUpperCase() : 'NAME ON CARD' }}</span>
                        <span>{{ form.expiry ? form.expiry.replace(/\s/g, '') : 'MM/YY' }}</span>
                    </div>
                </div>

                <div class="tc-tests">
                    <div class="tc-strong">Card numbers you can use</div>
                    <div class="tc-muted">Click one to fill the form. Any future expiry date and any security code work.</div>
                    <button v-for="card in TEST_CARDS" :key="card.number" type="button" class="tc-test-row" :title="`Fill the form with the ${card.label} card number`" @click="pick(card)">
                        <span class="tc-test-number">{{ formatCardNumber(card.number) }}</span>
                        <span :class="card.outcome === 'succeeded' ? 'tc-ok' : 'tc-bad'">{{ card.label }}</span>
                    </button>
                </div>
            </aside>
        </div>
    </EstateConsole>
</template>

<style scoped>
.tc-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: var(--amber-100);
    border: 1px solid var(--amber-500);
    border-radius: 12px;
    padding: 12px 14px;
    margin: 0 0 18px;
}

.tc-banner p {
    margin: 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--navy-900);
}

.tc-badge {
    flex: none;
    display: inline-block;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.6px;
    color: var(--amber-700);
    background: var(--white);
    border: 1px solid var(--amber-500);
    border-radius: 20px;
    padding: 3px 9px;
}

.tc-badge--dark {
    color: var(--navy-900);
    background: var(--amber-500);
    border-color: transparent;
}

.tc-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}

@media (max-width: 900px) {
    .tc-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

.tc-checkout,
.tc-tests,
.tc-done {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 18px;
}

.tc-summary {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 16px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--navy-100);
    margin-bottom: 14px;
}

.tc-muted {
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--slate-500);
}

.tc-strong {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.tc-amount {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--slate-500);
}

.tc-amount input {
    width: 150px;
    font: inherit;
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
    text-align: right;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    padding: 8px 10px;
}

.tc-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--slate-500);
    margin: 14px 0 6px;
}

.tc-card-box {
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    overflow: hidden;
}

/* A field's refusal is amber (Hassam's three weights: solid red is an open panic alert only). */
.tc-card-box--error {
    border-color: var(--amber-700);
}

.tc-card-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    border-top: 1px solid var(--navy-200);
}

.tc-card-row .tc-input + .tc-input {
    border-left: 1px solid var(--navy-200);
}

.tc-input {
    width: 100%;
    box-sizing: border-box;
    font: inherit;
    font-size: 14px;
    color: var(--navy-900);
    border: 0;
    padding: 11px 12px;
    background: var(--white);
    /* No outline reset (M12 §4.1): the consoles' one focus ring shows here too. */
}

.tc-input:focus {
    background: var(--navy-100);
}

.tc-input--number {
    letter-spacing: 1px;
}

.tc-input--solo {
    border: 1px solid var(--navy-200);
    border-radius: 10px;
}

.tc-input--error {
    border-color: var(--amber-700);
}

.tc-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    margin: 6px 0 0;
}

.tc-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font: inherit;
    font-size: 13.5px;
    font-weight: 700;
    border-radius: 11px;
    padding: 12px 18px;
    cursor: pointer;
    text-decoration: none;
}

.tc-btn--primary {
    color: var(--white);
    background: var(--navy-600);
    border: 0;
}

.tc-btn--primary[disabled] {
    opacity: 0.75;
    cursor: progress;
}

.tc-btn--outline {
    color: var(--navy-700);
    background: var(--white);
    border: 1px solid var(--navy-200);
}

.tc-pay {
    width: 100%;
    margin-top: 18px;
}

.tc-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.45);
    border-top-color: var(--white);
    border-radius: 50%;
    animation: tc-spin 0.8s linear infinite;
}

@keyframes tc-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .tc-spinner {
        animation: none;
    }
}

.tc-secure {
    font-size: 11px;
    line-height: 1.5;
    color: var(--slate-500);
    margin: 12px 0 0;
}

.tc-side {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.tc-card-picture {
    height: 180px;
    border-radius: 16px;
    padding: 18px;
    box-sizing: border-box;
    color: var(--white);
    background: linear-gradient(135deg, var(--navy-600), var(--navy-800));
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.tc-cp-top,
.tc-cp-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.tc-cp-brand {
    font-weight: 800;
    font-style: italic;
}

.tc-cp-number {
    font-size: 19px;
    letter-spacing: 2px;
}

.tc-cp-bottom {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.tc-cp-bottom span:first-child {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tc-test-row {
    display: flex;
    align-items: center;
    gap: 14px;
    width: 100%;
    font: inherit;
    text-align: left;
    background: none;
    border: 0;
    border-top: 1px solid var(--navy-100);
    padding: 9px 0;
    cursor: pointer;
}

.tc-test-row:first-of-type {
    margin-top: 8px;
}

.tc-test-row:hover .tc-test-number {
    color: var(--navy-600);
}

.tc-test-number {
    flex: none;
    width: 170px;
    font-size: 12.5px;
    font-weight: 600;
    letter-spacing: 0.5px;
    color: var(--navy-800);
}

.tc-ok {
    font-size: 11.5px;
    color: var(--success-700);
}

/* A test card that is always refused says so in amber: a warning about the card, not an alarm. */
.tc-bad {
    font-size: 11.5px;
    color: var(--amber-700);
}

.tc-done {
    max-width: 560px;
    margin: 0 auto;
    text-align: center;
}

.tc-done-mark {
    width: 64px;
    height: 64px;
    margin: 4px auto 12px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--success-700);
    background: var(--success-100);
}

.tc-done-mark svg {
    width: 30px;
    height: 30px;
}

.tc-done h2 {
    font-size: 18px;
    color: var(--navy-900);
    margin: 10px 0 4px;
}

.tc-done-amount {
    font-size: 26px;
    font-weight: 800;
    color: var(--navy-900);
}

.tc-done-rows {
    text-align: left;
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    padding: 0 14px;
    margin: 16px 0 0;
}

.tc-done-rows div {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    padding: 10px 0;
    font-size: 12.5px;
}

.tc-done-rows div + div {
    border-top: 1px solid var(--navy-100);
}

.tc-done-rows dt {
    color: var(--slate-500);
}

.tc-done-rows dd {
    margin: 0;
    font-weight: 600;
    color: var(--navy-900);
    text-align: right;
}

.tc-nothing {
    text-align: left;
    font-size: 12px;
    line-height: 1.5;
    color: var(--navy-900);
    background: var(--amber-100);
    border-radius: 10px;
    padding: 10px 12px;
    margin: 16px 0 0;
}

.tc-done-actions {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
}
</style>
