<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'

/**
 * Onboard new client — board screen super-admin-08.
 *
 * THIS DOES NOT PROVISION A DATABASE. `estate:provision` creates a MySQL
 * database and a dedicated user with its own grants; that is an operator act
 * with a command behind it, run deliberately and watched while it runs. A web
 * form doing it as a side effect would build infrastructure on a button press,
 * and a failure halfway would leave a half-built estate nobody knew to check.
 *
 * So this records the CLIENT — the tenant, its site, the subscription agreed
 * and the person who signed — and the success message says the command the
 * operator runs next. The board's own note says the same thing in its words.
 *
 * NOTHING BILLS. The subscription is written in `onboarding`; the act that
 * starts charging is completing onboarding, which has its own checklist on the
 * client record.
 *
 * The projection uses the same arithmetic the invoice does, so what an account
 * manager quotes at signing is what the client is later invoiced.
 */
const props = defineProps({
    plans: { type: Array, required: true },
    guard_rate_minor: { type: Number, required: true },
    currency: { type: String, required: true },
    onboarding_example: { type: String, default: null },
    /** Every IANA zone the server accepts (M19A §9). */
    zones: { type: Array, default: () => [] },
})

// M32 F7: the example a phone box shows is the platform's home's here, never the viewer's browser's.
const page = usePage()

/*
 * THE ESTATE'S CLOCK (M19A §9): a required step, chosen from a searchable list, never assumed. The time NOW in the
 * chosen zone is shown under it, so picking New York for a Kingston estate is obvious before anything is saved.
 */
const zoneQuery = ref('')
const zoneMatches = computed(() => {
    const q = zoneQuery.value.trim().toLowerCase().replace(/\s+/g, '_')
    const list = q === '' ? props.zones : props.zones.filter((z) => z.toLowerCase().includes(q))
    return list.slice(0, 50)
})
const now = ref(Date.now())
let ticker = null
onMounted(() => {
    ticker = setInterval(() => {
        now.value = Date.now()
    }, 15000)
})
onBeforeUnmount(() => clearInterval(ticker))
const zoneNow = computed(() => {
    if (!form.timezone) return ''
    try {
        const time = new Intl.DateTimeFormat('en-US', { timeZone: form.timezone, weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(now.value)
        return `It is ${time} there now.`
    } catch {
        return ''
    }
})

const form = useForm({
    name: '',
    address: '',
    units: '',
    phases: 1,
    plan_id: props.plans[1]?.id ?? props.plans[0]?.id ?? null,
    guards: 0,
    term_months: 24,
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    timezone: '',
})

/*
 * There is no subdomain field, because the board draws none — and that is
 * right rather than an omission. A subdomain becomes a hostname and a database
 * name, and it is chosen when the operator runs `estate:provision`, which is
 * the step that actually creates them. The server derives a suggestion from
 * the name so the client record has an id, and the success message prints the
 * exact command with it in, so the operator sees and can change it before any
 * infrastructure exists.
 */
const selectedPlan = computed(() => props.plans.find((p) => p.id === form.plan_id) ?? null)

const money = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: props.currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100)

const units = computed(() => Number(form.units) || 0)
const guards = computed(() => Number(form.guards) || 0)
const subscriptionMinor = computed(() => units.value * (selectedPlan.value?.price_minor ?? 0))
const addOnMinor = computed(() => guards.value * props.guard_rate_minor)

const submit = () => form.post('/clients/new', { preserveScroll: true })
</script>

<template>
    <Head title="Onboard new client" />

    <GeminiConsole title="Onboard new client" :back="{ href: '/clients', label: 'Back to clients' }">

        <div class="form-layout">
            <form class="form-panel" @submit.prevent="submit">
                <div class="form-sec-head">Estate details</div>

                <RequiredMark legend />

                <div class="m-field">
                    <label for="name">Estate name <RequiredMark /></label>
                    <div class="m-input" :class="{ focused: form.name !== '' }">
                        <input id="name" v-model="form.name" type="text" required placeholder="e.g. Mandeville Heights" />
                    </div>
                    <div v-if="form.errors.name" class="field-error">{{ form.errors.name }}</div>
                </div>

                <!--
                  One field, as the board draws it: "Mandeville, Manchester".
                  The street line and the parish are stored separately because
                  clients are listed and reported by parish, so the server
                  splits on the last comma. Asking for two fields would be a
                  storage decision leaking onto a form.
                -->
                <div class="m-field">
                    <label for="address">Address <RequiredMark /></label>
                    <div class="m-input">
                        <input
                            id="address"
                            v-model="form.address"
                            type="text"
                            required
                            placeholder="e.g. Mandeville, Manchester"
                            aria-describedby="address-hint"
                        />
                    </div>
                    <div id="address-hint" class="zone-now">The street or district, then the parish.</div>
                    <div v-if="form.errors.address" class="field-error">{{ form.errors.address }}</div>
                </div>

                <!-- M19A §9: the estate's clock, required, searchable, with the time there now. -->
                <div class="m-field">
                    <label for="timezone">Time zone <RequiredMark /></label>
                    <div class="m-input">
                        <input id="timezone-search" v-model="zoneQuery" type="search" placeholder="Search, e.g. Jamaica, New York, Karachi" aria-label="Search time zones" />
                    </div>
                    <div class="m-input" style="margin-top: 6px">
                        <select id="timezone" v-model="form.timezone" required>
                            <option value="" disabled title="A prompt, not a zone: every estate needs its own (M19A §9)">Choose the estate's time zone</option>
                            <option v-if="form.timezone && !zoneMatches.includes(form.timezone)" :value="form.timezone">{{ form.timezone }}</option>
                            <option v-for="z in zoneMatches" :key="z" :value="z">{{ z.replace(/_/g, ' ') }}</option>
                        </select>
                    </div>
                    <div class="zone-now">{{ zoneNow || 'Every time the estate, its residents and its guards see is on this clock.' }}</div>
                    <div v-if="form.errors.timezone" class="field-error">{{ form.errors.timezone }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="units">Total units <RequiredMark /></label>
                        <div class="m-input">
                            <input id="units" v-model="form.units" type="number" min="1" required />
                        </div>
                        <div v-if="form.errors.units" class="field-error">{{ form.errors.units }}</div>
                    </div>

                    <div class="m-field">
                        <label for="phases">Number of phases <RequiredMark /></label>
                        <div class="m-input">
                            <input id="phases" v-model="form.phases" type="number" min="1" required />
                        </div>
                        <div v-if="form.errors.phases" class="field-error">{{ form.errors.phases }}</div>
                    </div>
                </div>

                <div class="form-sec-head">Subscription</div>

                <div class="seg" role="radiogroup" aria-label="Subscription tier">
                    <button
                        v-for="plan in plans"
                        :key="plan.id"
                        type="button"
                        role="radio"
                        :aria-checked="form.plan_id === plan.id"
                        class="seg-item"
                        :class="{ active: form.plan_id === plan.id }"
                        @click="form.plan_id = plan.id"
                    >
                        {{ plan.name }}
                    </button>
                </div>
                <div v-if="form.errors.plan_id" class="field-error">{{ form.errors.plan_id }}</div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="guards">Guards to assign <RequiredMark /></label>
                        <div class="m-input">
                            <input id="guards" v-model="form.guards" type="number" min="0" required />
                        </div>
                        <div v-if="form.errors.guards" class="field-error">{{ form.errors.guards }}</div>
                    </div>

                    <div class="m-field">
                        <label for="term_months">Contract term</label>
                        <div class="m-input">
                            <select id="term_months" v-model="form.term_months">
                                <option value="">Monthly rolling</option>
                                <option :value="12">12 months</option>
                                <option :value="24">24 months</option>
                                <option :value="36">36 months</option>
                            </select>
                        </div>
                        <div v-if="form.errors.term_months" class="field-error">{{ form.errors.term_months }}</div>
                    </div>
                </div>

                <div class="form-sec-head">Primary contact</div>

                <div class="m-field">
                    <label for="contact_name">Name &amp; role <RequiredMark /></label>
                    <div class="m-input">
                        <input
                            id="contact_name"
                            v-model="form.contact_name"
                            type="text"
                            placeholder="e.g. Andre Campbell, President"
                            required
                        />
                    </div>
                    <div v-if="form.errors.contact_name" class="field-error">{{ form.errors.contact_name }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="contact_email">Email <RequiredMark /></label>
                        <div class="m-input">
                            <input
                                id="contact_email"
                                v-model="form.contact_email"
                                type="email"
                                placeholder="e.g. contact@example.org"
                                required
                            />
                        </div>
                        <div v-if="form.errors.contact_email" class="field-error">{{ form.errors.contact_email }}</div>
                    </div>

                    <div class="m-field">
                        <label for="contact_phone">Phone <RequiredMark /></label>
                        <div class="m-input">
                            <input
                                id="contact_phone"
                                v-model="form.contact_phone"
                                type="tel"
                                :placeholder="`e.g. ${page.props.phoneExample?.local ?? ''}`"
                                required
                            />
                        </div>
                        <div v-if="form.errors.contact_phone" class="field-error">{{ form.errors.contact_phone }}</div>
                    </div>
                </div>

                <button type="submit" class="stack-btn primary" style="width: 100%" :disabled="form.processing">
                    <BoardIcon name="plus" :stroke="2" />
                    <span>{{ form.processing ? 'Recording…' : 'Start onboarding' }}</span>
                </button>
            </form>

            <div class="preview-panel">
                <div class="preview-head">Projected MRR</div>

                <div style="background: var(--navy-100); border-radius: 12px; padding: 13px">
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 12px;
                            color: var(--navy-900);
                            padding: 5px 0;
                        "
                    >
                        <span>{{ selectedPlan?.name }} — {{ units }} units × {{ money(selectedPlan?.price_minor ?? 0) }}</span>
                        <b>{{ money(subscriptionMinor) }}</b>
                    </div>
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 12px;
                            color: var(--navy-900);
                            padding: 5px 0;
                        "
                    >
                        <span>Security add-on — {{ guards }} × {{ money(guard_rate_minor) }}</span>
                        <b>{{ money(addOnMinor) }}</b>
                    </div>
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 13px;
                            font-weight: 700;
                            color: var(--navy-900);
                            padding: 9px 0 0;
                            border-top: 1px solid var(--navy-600);
                            margin-top: 6px;
                        "
                    >
                        <span>Total once active</span>
                        <b>{{ money(subscriptionMinor + addOnMinor) }}</b>
                    </div>
                </div>

                <div class="preview-note">
                    This creates the client record and generates its own Estate Console. Billing won't start until
                    onboarding is marked complete<template v-if="onboarding_example"> — same as
                    {{ onboarding_example }} is now</template>.
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the tier segments, every field and the
 * submit as <div>s; here they are radio buttons, inputs, a select and a button,
 * each bringing the browser's own chrome. The board's .seg-item, .m-input and
 * .stack-btn rules supply everything visual.
 */
/* The family and line height only: `font: inherit` would reset the board's .seg-item size and weight (13 E1, as board 34). */
button.seg-item {
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

.m-input input,
.m-input select {
    flex: 1;
    min-width: 0;
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

.m-input input::placeholder {
    color: inherit;
    opacity: 1;
}

.m-input input[type='number']::-webkit-outer-spin-button,
.m-input input[type='number']::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

/* Authored: the board draws one filled-in happy path and has no error state to
 * copy. Kept to the tokens the boards do define, and placed under its own
 * field rather than in a summary. */
.field-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    margin-top: 5px;
    line-height: 1.45;
}

/* M19A §9: the time now in the zone chosen. */
.zone-now {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-body);
    margin-top: 6px;
}

.m-input select {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: var(--text-primary);
}

</style>
