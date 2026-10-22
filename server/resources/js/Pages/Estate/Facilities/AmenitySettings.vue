<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'

/**
 * Manage amenities — board screen community-admin-20.
 *
 * THE RATE CARD. It posts nothing and it SOURCES everything: every figure on the
 * booking diary and every amenity charge on a unit's ledger derives from the two
 * amounts on these cards, which is why they are transcribed exactly and why they
 * cannot be one column. A BOOKING FEE is revenue — earned, non-refundable, Cr
 * 4100 when the booking is charged. A DEPOSIT is a refundable liability the
 * estate is holding and never recognises as income. Conflating them credits the
 * income account with money the estate has to give back.
 *
 * EDITING A CARD MUST NOT MOVE A DEPOSIT ALREADY HELD, and that is the whole
 * reason a booking snapshots these figures instead of joining to them. Board
 * 19's accounting note states the consequence: "bookings copy
 * amenity.deposit_amount verbatim, so a change to this rate card must not
 * retro-alter deposits already held or the deposit control account will no
 * longer agree to the sum of open bookings." A rate card edited on Tuesday would
 * otherwise restate every deposit taken since March, and the estate would owe
 * residents an amount its own books no longer showed. It is the rule the edit
 * screen has to state on itself, and it is why `reasons.edit` says the control
 * needs a screen rather than a dialog.
 *
 * NULL IS NOT ZERO ON EITHER AMOUNT. The Pool Deck draws "Free for residents"
 * and "None" — statements about the amenity rather than amounts — and the board's
 * own note requires that a nil amenity "produce a booking with zero journal lines
 * rather than a zero-amount entry", because a zero-value entry is a row in the
 * ledger claiming something happened. Both labels arrive from the server beside
 * the minor units, so this page never has to invent what a null looks like.
 *
 * MONEY IS FORMATTED AT THE MODEL BOUNDARY AND NOT HERE. `Amenity::money()`
 * writes the bare "$3,000" these two boards use — no decimals, no J$, unlike the
 * ledger screens' "J$12,400.00" — from minor units, which is how every amount in
 * this system is stored. Nothing on this page divides by a hundred; a template
 * that did is how a rounding artefact reaches a rate card that other screens
 * copy from.
 *
 * TWO FIELDS THE BUILD SPEC NAMES AND THIS BOARD DRAWS NOWHERE. Every amenity
 * carries a cancellation rule and a booking window; board 20 draws four meta
 * items per card and neither is among them. They arrive in the payload, they are
 * unset on this estate, and they stay that way — recorded rather than invented,
 * and set by an estate on the edit screen. The columns exist because a booking
 * snapshots the cancellation rule it was made under.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** One card per active amenity, in the estate's own sort order. */
    rows: { type: Array, required: true },
    /** The four glyphs the boards draw, which an amenity picks from. */
    icons: { type: Array, required: true },
    canUpdate: { type: Boolean, required: true },
    canCreate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    createReason: { type: String, required: true },
})

/*
 * ADD AND EDIT ARE BUILT (12 §2, Wave 2). One form, two uses: blank for a new
 * amenity, filled from the card for an edit. Capacity, hours, fee and deposit
 * are captured together; a blank fee or deposit is "Free for residents" and
 * "None", never zero. An edit changes what the NEXT booking copies and never a
 * confirmed one: every booking carries its own copy of the terms it was made
 * under, and the form says so at its head.
 *
 * IN THE CONSOLE'S MODAL SINCE M95 §1 (M97 pass): the add form opened above the cards and the edit form under its own
 * card, which made the page longer and announced nothing. Each error sits under its own field, and what was typed is
 * never thrown away without asking.
 *
 * AND IT SAYS WHAT THE EDIT REACHES, BEFORE THE PRESS (M10 §3: "both screens show
 * clearly what a change will affect and from when"). Each card arrives with
 * `terms_effect` — "Applies to bookings made from now on. The 2 upcoming bookings
 * already made keep the fee, deposit and cancellation rule they were made under."
 * — counted by the server from the diary, so the page never guesses how many
 * bookings a change leaves alone. Every edit is written to the audit log, before
 * and after.
 */
const BLANK_TERMS = {
    name: '',
    icon_key: 'pavilion',
    capacity: 20,
    fee: '',
    deposit: '',
    opens_at: '08:00',
    closes_at: '22:00',
    booking_window_days: 30,
    cancellation_hours: 24,
    is_bookable: true,
    // M53 §2: on unless switched off. A booking waits for the office, and its fee is charged when approved.
    requires_approval: true,
    retire: false,
}

/** Which amenity's form is open: null for none, 'new' for a new one, or a card id. */
const editing = ref(null)

const form = useForm({ ...BLANK_TERMS })

/** The card being edited, or null while adding. */
const editingRow = computed(() => (editing.value === null || editing.value === 'new' ? null : props.rows.find((row) => row.id === editing.value) ?? null))

const openAdd = () => {
    if (!props.canCreate) {
        return
    }

    form.clearErrors()
    Object.assign(form, BLANK_TERMS)
    // What is in the form now is where it starts, so closing it untouched asks nothing.
    form.defaults?.()
    editing.value = 'new'
}

const openEdit = (row) => {
    if (!props.canUpdate) {
        return
    }

    form.clearErrors()
    Object.assign(form, { ...BLANK_TERMS, ...row.terms, retire: false })
    form.defaults?.()
    editing.value = row.id
}

const closePanel = () => {
    editing.value = null
    form.clearErrors()
}

/**
 * A typed amount as the server reads it (M95 §4: "fee $3,000 and deposit $5,000 read back as free for residents, no
 * deposit"). The fields showed 3000.00 and 5000.00 only as grey placeholders, so they were blank and the read-back was
 * true; the placeholders now say what a blank means. And "3,000", "$3,000" and "J$3,000.00" are all 3000: read back,
 * and posted, as the amount they are.
 */
const plainAmount = (typed) =>
    String(typed ?? '')
        .trim()
        .replace(/^J?\$/i, '')
        .replace(/[\s,]/g, '')

const AMOUNT = /^\d+(\.\d{1,2})?$/

const amountWords = (typed, noun, none) => {
    const plain = plainAmount(typed)

    if (plain === '' || (AMOUNT.test(plain) && Number(plain) === 0)) {
        return none
    }

    if (!AMOUNT.test(plain)) {
        return `${noun} not an amount yet`
    }

    // As the cards write it: "$3,000", and cents only when there are some ("$4,500.50").
    const value = Number(plain)
    const cents = Number.isInteger(value) ? 0 : 2

    return `${noun} $${value.toLocaleString('en-US', { minimumFractionDigits: cents, maximumFractionDigits: 2 })}`
}

/** Why the form's button cannot be pressed yet, said under it (M96 level 2); null when it can. */
const saveBlocked = computed(() => {
    if (form.name.trim() === '') {
        return 'Name the amenity first.'
    }

    for (const [typed, noun] of [[form.fee, 'booking fee'], [form.deposit, 'deposit']]) {
        const plain = plainAmount(typed)

        if (plain !== '' && !AMOUNT.test(plain)) {
            return `Write the ${noun} as an amount, like 3000 or 3,000.50.`
        }
    }

    return null
})

const submit = () => {
    if (saveBlocked.value !== null) {
        return
    }

    const target = editing.value === 'new' ? facilities('/amenities') : facilities(`/amenities/${editing.value}`)

    form.transform((data) => ({ ...data, fee: plainAmount(data.fee), deposit: plainAmount(data.deposit) })).post(target, {
        preserveScroll: true,
        onSuccess: () => closePanel(),
    })
}

/*
 * A BLANK IS SAID IN WORDS, NEVER PRINTED (Hassam, batch 1 item 2: the read-back said "cancel nullh ahead, book up to
 * null" for an amenity with no cancellation rule and no booking horizon). A number typed in a field, or null for a blank.
 */
const typedNumber = (value) => (value === '' || value === null || value === undefined || Number.isNaN(Number(value)) ? null : Number(value))

const cancelWords = (hours) => {
    const h = typedNumber(hours)

    return h === null || h === 0 ? 'cancel any time' : `cancel at least ${h} hour${h === 1 ? '' : 's'} ahead`
}

const windowWords = (days) => {
    const d = typedNumber(days)

    return d === null ? 'no limit on how far ahead' : `book up to ${d} day${d === 1 ? '' : 's'} ahead`
}

const capacityWords = (guests) => {
    const n = typedNumber(guests)

    return n === null ? 'capacity not set' : `${n} guest${n === 1 ? '' : 's'}`
}

const hoursWords = (opens, closes) => (String(opens ?? '').trim() && String(closes ?? '').trim() ? `${opens}–${closes}` : 'hours not set')

/** The terms read back before the press, in the words the cards use. */
const readBack = computed(() => {
    const fee = amountWords(form.fee, 'fee', 'free for residents')
    const deposit = amountWords(form.deposit, 'deposit', 'no deposit')

    return `${form.name.trim() || 'This amenity'}: ${capacityWords(form.capacity)}, ${hoursWords(form.opens_at, form.closes_at)}, ${fee}, ${deposit}, ${cancelWords(form.cancellation_hours)}, ${windowWords(form.booking_window_days)}${form.requires_approval ? ', bookings wait for the office' : ', bookings confirmed when paid in the app'}${form.is_bookable ? '' : ', closed to new bookings'}${form.retire ? ', retired' : ''}.`
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

// An editor for each amenity's terms: re-reading it under somebody's typing would lose their work (M18H §7.2).
useLiveKind('form')

const page = usePage()

/**
 * All six, and the sixth is reachable only by forcing.
 *
 * `empty` is an estate that has no amenities at all — nothing to book and
 * nothing to charge for. `empty-filtered` is the same list emptied by the view
 * rather than by the estate: this screen is the ACTIVE rate card, and an amenity
 * is retired rather than deleted so that what was booked while it was open still
 * reads correctly. An estate whose amenities are all retired has a register that
 * is not empty and a card list that is. No control on this page applies that
 * filter, so nothing here can produce the state from data — it is forceable with
 * `?_state=empty-filtered` in local, and it says something true when it is.
 */
const state = useScreenState({
    rows: () => props.rows.length,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/facilities/amenities/settings. The URL that served this page
 * already carries whichever shape this environment uses, so cutting it at
 * /facilities is correct in both — and unlike a tenant key read off a prop it
 * cannot address an estate other than the one already open.
 */
const facilities = (suffix) => `${page.url.slice(0, page.url.indexOf('/facilities'))}/facilities${suffix}`

const backHref = computed(() => facilities('/amenities/bookings'))

/**
 * What an amenity that is listed here and missing from the diary's chips means.
 *
 * Not drawn on the board — all four of its amenities are open for booking — and
 * absent from this screen whenever that is true. It is here because it is the
 * one thing about a card a reader cannot work out from what is drawn: an amenity
 * is retired rather than deleted, and one closed to new bookings still has a
 * rate card, still holds the deposits taken under it, and still appears in the
 * history of every booking it ever took.
 */
const CLOSED_LABEL = 'Closed to new bookings'

// Said on a card only when its approval is switched off (M53 §2); waiting for the office is every amenity's default.
const NO_APPROVAL_LABEL = 'Confirmed when paid in the app'
</script>

<template>
    <Head title="Manage amenities" />

    <!-- A nested page: the way back to the booking diary, in words, top left (M95 §3). -->
    <EstateConsole
        title="Manage amenities"
        :estate-name="estate.name"
        active="facilities"
        :back="{ href: backHref, label: 'Back to bookings' }"
    >
        <template #actions>
            <!--
              The primary blue here where the diary's own topbar carries an outline button: on this screen adding an
              amenity IS the next thing to do. M96 level 3: a role that can never add one has no Add amenity at all.
            -->
            <button
                v-if="canCreate"
                type="button"
                class="btn-primary-sm"
                title="Add an amenity with its capacity, hours, fee and deposit."
                @click="openAdd"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add amenity</span>
            </button>
        </template>

        <!--
          Add an amenity, or edit one, in the console's modal (M95 §1). Each error sits under its own field; the terms
          are read back before the press; an edit says what it reaches, counted by the server.
        -->
        <ConsoleDialog
            :open="editing !== null"
            :title="editingRow ? `Edit the ${editingRow.name}` : 'Add an amenity'"
            size="form"
            :tone="form.retire ? 'danger' : 'primary'"
            :confirm-label="editingRow ? (form.retire ? 'Retire amenity' : 'Save terms') : 'Add amenity'"
            :busy-label="editingRow ? 'Saving…' : 'Adding…'"
            :busy="form.processing"
            :dirty="form.isDirty"
            :discard-title="editingRow ? 'Discard these changes?' : 'Discard this amenity?'"
            :confirm-blocked-reason="saveBlocked"
            @confirm="submit"
            @cancel="closePanel"
        >
            <p v-if="editingRow" class="am-head">
                It changes what the next booking copies. Every booking already confirmed keeps the fee, deposit and
                cancellation rule it was made under.
            </p>
            <RequiredMark legend />
            <div class="am-fields">
                <div class="am-field am-field--wide">
                    <label for="am-name">Name <RequiredMark /></label>
                    <input id="am-name" v-model="form.name" type="text" required maxlength="80" />
                    <div v-if="form.errors.name" class="am-error">{{ form.errors.name }}</div>
                </div>
                <div class="am-field">
                    <label for="am-icon">Glyph</label>
                    <select id="am-icon" v-model="form.icon_key">
                        <option v-for="icon in icons" :key="icon" :value="icon">{{ icon }}</option>
                    </select>
                </div>
                <div class="am-field">
                    <label for="am-capacity">Capacity, guests <RequiredMark /></label>
                    <input id="am-capacity" v-model.number="form.capacity" type="number" min="1" max="5000" required />
                    <div v-if="form.errors.capacity" class="am-error">{{ form.errors.capacity }}</div>
                </div>
                <!-- A blank fee or deposit is a statement, so the placeholder says it in words, never as an amount. -->
                <div class="am-field">
                    <label for="am-fee">Booking fee, J$ (optional, blank for free)</label>
                    <input id="am-fee" v-model="form.fee" type="text" inputmode="decimal" placeholder="Free for residents" />
                    <div v-if="form.errors.fee" class="am-error">{{ form.errors.fee }}</div>
                </div>
                <div class="am-field">
                    <label for="am-deposit">Deposit, J$ (optional, blank for none)</label>
                    <input id="am-deposit" v-model="form.deposit" type="text" inputmode="decimal" placeholder="No deposit" />
                    <div v-if="form.errors.deposit" class="am-error">{{ form.errors.deposit }}</div>
                </div>
                <div class="am-field">
                    <label for="am-opens">Opens <RequiredMark /></label>
                    <input id="am-opens" v-model="form.opens_at" type="text" required pattern="^(?:[01]\d|2[0-4]):[0-5]\d$" />
                    <div v-if="form.errors.opens_at" class="am-error">{{ form.errors.opens_at }}</div>
                </div>
                <div class="am-field">
                    <label for="am-closes">Closes (24:00 for midnight) <RequiredMark /></label>
                    <input id="am-closes" v-model="form.closes_at" type="text" required pattern="^(?:[01]\d|2[0-4]):[0-5]\d$" />
                    <div v-if="form.errors.closes_at" class="am-error">{{ form.errors.closes_at }}</div>
                </div>
                <div class="am-field">
                    <label for="am-window">Book up to, days ahead (optional, blank for no limit)</label>
                    <input id="am-window" v-model.number="form.booking_window_days" type="number" min="1" max="365" />
                    <div v-if="form.errors.booking_window_days" class="am-error">{{ form.errors.booking_window_days }}</div>
                </div>
                <div class="am-field">
                    <label for="am-cancel">Cancel at least, hours ahead (optional, blank for any time)</label>
                    <input id="am-cancel" v-model.number="form.cancellation_hours" type="number" min="0" max="720" />
                    <div v-if="form.errors.cancellation_hours" class="am-error">{{ form.errors.cancellation_hours }}</div>
                </div>
            </div>
            <label v-if="editingRow" class="am-check">
                <input v-model="form.is_bookable" type="checkbox" />
                <span>Open for booking. Untick to close it to new bookings while keeping the ones it has.</span>
            </label>
            <label class="am-check">
                <input v-model="form.requires_approval" type="checkbox" />
                <span>Bookings wait for the office's approval, and the fee is charged when approved. Untick to confirm a booking when it is paid in the app.</span>
            </label>
            <label v-if="editingRow" class="am-check">
                <input v-model="form.retire" type="checkbox" />
                <span>Retire it. It leaves this card, keeps its history and the deposits taken under it, and is never deleted.</span>
            </label>
            <p class="am-read">{{ readBack }}</p>
            <!-- What the edit reaches and from when: the server's count, before the press. -->
            <p v-if="editingRow" class="am-effect">{{ editingRow.terms_effect }}</p>
        </ConsoleDialog>

        <!--
          The whole screen is one payload, so nothing on it arrives before the
          rest. Four skeleton rows for the four cards, four bars for the four
          facts each of them carries.
        -->
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Facilities is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The rate card could not be read"
            body="Nothing has changed. Please try again."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="Every amenity here has been retired"
            body="A retired amenity keeps its rate card and its past bookings."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This estate has no amenities yet"
            body="Add an amenity to open it for booking."
        />

        <!--
          Four sibling cards and nothing else — no subnav, no filter row, no KPI
          row and no table. The board is a list of rate cards, and each one is
          the icon, the four figures and the way in to change them.
        -->
        <template v-else>
            <template v-for="row in rows" :key="row.id">
            <div class="amenity-card">
                <!--
                  The glyph is the amenity's OWN stored key, not a match on its
                  name: the board draws four distinct SVGs and the same key has
                  to resolve to the smaller 16px glyph in the booking diary, so
                  an estate adding a tennis court picks an icon rather than
                  inheriting whichever one a string match landed on. Drawn at
                  this board's 1.7 — the same geometry the diary draws at 1.6,
                  and the difference is the boards' own.
                -->
                <div class="ac-icon">
                    <svg v-if="row.icon === 'gazebo'" viewBox="0 0 24 24" fill="none">
                        <path
                            d="M12 3v18M4 9c0-3.3 3.6-6 8-6s8 2.7 8 6"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                        />
                        <path d="M4 9h16" stroke="currentColor" stroke-width="1.7" />
                    </svg>
                    <svg v-else-if="row.icon === 'clubhouse'" viewBox="0 0 24 24" fill="none">
                        <path
                            d="M4 21V9l8-6 8 6v12"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linejoin="round"
                        />
                        <path d="M9 21v-7h6v7" stroke="currentColor" stroke-width="1.7" />
                    </svg>
                    <svg v-else-if="row.icon === 'pavilion'" viewBox="0 0 24 24" fill="none">
                        <path d="M3 10l9-6 9 6" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                        <path
                            d="M5 10v9M11 10v9M13 10v9M19 10v9M3 19h18"
                            stroke="currentColor"
                            stroke-width="1.7"
                        />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                        <path
                            d="M12 3v2M12 19v2M3 12h2M19 12h2"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>

                <div class="ac-info">
                    <div class="ac-name">{{ row.name }}</div>

                    <!--
                      Four facts, in the board's order, and the two amounts are
                      side by side because they are the pair a committee
                      confuses: the fee is earned and the deposit is owed back.
                      Both labels come from the server — "Free for residents"
                      and "None" are statements about the amenity rather than
                      numbers, and a page formatting a null would have to invent
                      them.

                      The hours are an en-dash WITH spaces here, where a
                      booking's time range on the diary uses one with none. Both
                      forms are the boards' own and they are deliberately
                      different; `hoursLabel()` keeps them apart, and it is also
                      what prints "Midnight" where the twenty-fourth hour would
                      otherwise read as 12:00 AM and make an amenity closed all
                      day.
                    -->
                    <div class="ac-meta">
                        <div class="ac-meta-item">Capacity <b>{{ row.capacity_label }}</b></div>
                        <div class="ac-meta-item">Booking fee <b>{{ row.fee_label }}</b></div>
                        <div class="ac-meta-item">Deposit <b>{{ row.deposit_label }}</b></div>
                        <div class="ac-meta-item">Hours <b>{{ row.hours_label }}</b></div>

                        <!-- Absent while every amenity is open, which is what the
                             board draws. See CLOSED_LABEL. -->
                        <div v-if="!row.is_bookable" class="ac-meta-item">
                            Bookings <b>{{ CLOSED_LABEL }}</b>
                        </div>
                        <div v-else-if="!row.requires_approval" class="ac-meta-item">
                            Bookings <b>{{ NO_APPROVAL_LABEL }}</b>
                        </div>
                    </div>
                </div>

                <!-- M96 level 3: a role that can never edit the rate card has no edit control on it. -->
                <button
                    v-if="canUpdate"
                    type="button"
                    class="icon-btn"
                    :title="`Edit the ${row.name}'s capacity, hours, fee and deposit.`"
                    :aria-label="`Edit ${row.name}`"
                    @click="openEdit(row)"
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
                </button>
            </div>
            </template>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its back affordance, its topbar action and its four edit
 * chips as <div>s; here they are a link and five buttons, and a <button> arrives
 * wearing the browser's own font, a border and buttonface grey. .btn-primary-sm
 * and .icon-btn supply everything visible, and app.css has already taken the
 * UA's underline and blue off every anchor on a board page.
 *
 * NEITHER CLASS BELOW IS GIVEN A BORDER BY THE BOARD — .btn-primary-sm declares
 * a background and a shadow, .icon-btn a background and a radius. That is what
 * makes `border: 0` a removal rather than an override: a scoped element selector
 * outranks a single class, and the same line against .btn-outline-sm would rub
 * out the 1.5px navy edge the board draws on it. See D-045.
 */
button {
    font-family: inherit;
}

button.btn-primary-sm,
button.icon-btn {
    border: 0;
}

button.btn-primary-sm,
button.icon-btn {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a rate card nobody is editing, so it has no form; the form is the console's
 * modal's body now (M95 §1). Kept to the tokens the boards define and the shapes they already use.
 */
.am-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
    margin: 0 0 12px;
}

/*
 * TWO COLUMNS THAT FIT THE DIALOG, ONE ON A PHONE (Hassam, batch 1 item 2: the name and the booking fee were cut off,
 * with a sideways scrollbar inside the dialog). Four `1fr` columns could not shrink below an input's own width; each
 * column may now shrink to nothing and each input fills its column.
 */
.am-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 11px;
    margin-bottom: 11px;
}

/* The modal is the whole screen on a phone: one field a row there. */
@media (max-width: 640px) {
    .am-fields {
        grid-template-columns: minmax(0, 1fr);
    }
}

.am-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.am-field--wide {
    grid-column: 1 / -1;
}

.am-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.am-field input,
.am-field select {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.am-check {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    cursor: pointer;
    margin-bottom: 9px;
}

.am-check input {
    margin: 3px 0 0;
    flex: 0 0 auto;
}

.am-read {
    font-size: 11.5px;
    color: var(--navy-900);
    font-weight: 600;
    line-height: 1.5;
    margin: 0;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 14px;
}

.am-effect {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.55;
    margin: 9px 0 0;
}

/* A refusal under its field is amber (Hassam's three weights, 11 Oct): red is an open panic's, and a destructive act's outline. */
.am-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
}
</style>
