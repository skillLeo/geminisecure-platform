<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'

/**
 * Feature toggles — board screen community-admin-23.
 *
 * READ ONLY, WITH THE PLAN (Hassam's closing order, 12 Oct 2026: "feature toggles shown read only with the plan").
 * Every row says its state in the list's words, Active or Inactive, and one line saying where that comes from: always
 * on, the plan, or a decision recorded for this estate. THE SWITCHES THAT WERE HERE CHANGED NOTHING: an estate's
 * choice was stored and nothing outside this page read it, so the page offered controls that lied. A module the plan
 * leaves out is now absent from the sidebar and its addresses say so (`PlanGate`); the plan itself is Gemini
 * Security's, against the subscription. The one rule an estate does set here, whether the main resident's invites are
 * trusted, keeps its switch, which asks why in the console's dialog.
 *
 * WHAT THIS ESTATE'S PLAN OFFERS IT AND WHAT THIS ESTATE DECIDED, and the two
 * are not the same question. Every row's effective state is resolved at the
 * boundary in a fixed order — core is on, else this community's own override,
 * else whether the plan includes it — so nothing here reads a stored "enabled"
 * flag, because `estate_features` holds overrides ONLY. A row per feature per
 * estate would look decided when nothing had been decided, and the day the plan
 * changed, eleven stale copies of its old answer would outvote it.
 *
 * A CHANGE HERE NEEDS A STATED REASON, AND THE REASON IS TYPED. Board 23's own
 * audit note is the requirement: "every toggle change writes an audit record
 * with actor, timestamp, and reason", and the service refuses a blank one. So a
 * switch does not post when it is pressed — it ARMS, and a field opens beneath
 * the row asking why. A dialog would have been the other way to do it; this is
 * the one that leaves the board untouched until somebody presses something,
 * which is the state the board is a picture of.
 *
 * TEN ROWS, ALL OF THEM FEATURES (D-050, amended by M10 §9). There was an
 * eleventh — "Security guard payroll routing" — which was not in the catalogue
 * at all: guards are Gemini Security Limited's employees, paid by Gemini, filed
 * under Gemini's TRN. It was emitted from `estate_settings` and drawn locked
 * with a paragraph saying why it could not be moved.
 *
 * The client removed it. A locked control is still a question: it puts the
 * choice in front of a committee, invites them to ask why they cannot make it,
 * and has to be answered on every estate's screen for ever. An estate has no say
 * in how somebody else's employees are paid, so the row is gone rather than
 * disabled, and the column behind it with it. The server still refuses the key,
 * because a control nobody draws is still one somebody can post to.
 *
 * A FOURTH GROUP THE BOARD DOES NOT DRAW: "Held back in this release". The
 * biometric consent flag (D-022), card payments for dues (D-023) and geofenced
 * clock-in (D-033) are each a ruling rather than a feature, each is a named flag
 * on `estate_settings` in its safest position, and each is drawn locked with the
 * ruling on it. None of the three was added to the central catalogue, because
 * that table is the package builder's and putting a legally-blocked feature into
 * it would offer it for sale. Stating a decision is the difference between a
 * decision and an oversight.
 *
 * THE AUDIT NOTE IS DRAWN ABOVE THE HELD-BACK GROUP AND NOT BELOW IT, which is
 * one place away from the order the payload lists the groups in. The note is
 * about what happens WHEN a toggle is changed, and not one row in the group
 * below it can be changed by anybody — so it reads as a footer to the ten live
 * rows and a preamble to the three that are shut. It also keeps the board's own
 * closing sentence on screen: `.content` clips at the fold, and three more rows
 * above the note would push the note past it.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The seven-item settings column, with this screen marked current. */
    sections: { type: Array, required: true },
    /** The plan banner: the estate's plan, its sentence and its badge. */
    plan: { type: Object, required: true },
    /** Tiers in ascending order, then the held-back group. */
    groups: { type: Array, required: true },
    audit_note: { type: String, required: true },
    canToggle: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    /**
     * "Trust the main resident's invites" — an estate rule about its own register, not a catalogue
     * feature, so it is drawn in a section of its own below the plan's rows (household invites, stage 2).
     */
    residentInvites: { type: Object, default: null },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

// A settings form: re-reading it under somebody's changes would lose them (M18H §7.2).
useLiveKind('form')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL. Production gives
 * each estate its own hostname and no prefix; local serves every estate from one
 * host with the estate key in the path. The URL that served this page already
 * carries whichever shape this environment uses.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/settings')))

/**
 * The three tier badge variants the board defines, keyed by the tier the server
 * sends.
 *
 * `essential` is the plan key and `core` is what the board calls the badge, and
 * they are deliberately not renamed into each other: the plan is a commercial
 * record and the badge is a word on a pill.
 */
const TIER_CLASS = { essential: 'core', standard: 'standard', premium: 'premium' }

/**
 * The badge on a row with no tier at all.
 *
 * The three held-back rows belong to no plan — they are rulings, not products —
 * so the board defines no variant for them and one is not invented. The neutral
 * pair is the board's own, lifted off `.tier-req.core`, because "Held" is a
 * statement of fact rather than a fault to flag. Declared inline for the same
 * reason board 26 declares its amber inline: borrowing a variant named for
 * something else puts the wrong word's colour on the right fact.
 */
const HELD_BADGE = 'background:var(--navy-100);color:var(--slate-600);'

/**
 * What the branding dots are, in one line: a readout of how much the plan includes, never a control (D-044).
 */
const SCALE_TITLE = 'How much custom branding the plan includes.'

/* ------------------------------------------------------------------ */
/* trusting the main resident's invites */
/* ------------------------------------------------------------------ */

/*
 * THE SAME RULE AS EVERY SWITCH HERE — a reason is typed before anything changes — asked in the
 * console's dialog, because switching it on hands a claim's approval to the household. The dialog
 * shows the server's refusal inside itself and closes only when the change is recorded.
 */
const trustOpen = ref(false)
const trustReason = ref('')
const trustBusy = ref(false)
const trustError = ref(null)

const trustTarget = computed(() => !(props.residentInvites?.enabled ?? false))

const openTrust = () => {
    if (!props.residentInvites?.canChange) {
        return
    }

    trustReason.value = ''
    trustError.value = null
    trustOpen.value = true
}

const cancelTrust = () => {
    trustOpen.value = false
    trustError.value = null
}

const trustBlocked = computed(() =>
    trustReason.value.trim().length < 4
        ? 'Say why this is changing. It is recorded with your name, the time and your reason.'
        : null
)

const saveTrust = () => {
    if (trustBlocked.value !== null || trustBusy.value) {
        return
    }

    trustBusy.value = true
    trustError.value = null

    router.post(
        `${root.value}/settings/household-invites/trust`,
        { enabled: trustTarget.value, reason: trustReason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                trustOpen.value = false
            },
            onError: (errors) => {
                trustError.value = errors.invite_trust ?? errors.reason ?? errors.enabled ?? 'The change could not be saved. Nothing was changed.'
            },
            onFinish: () => {
                trustBusy.value = false
            },
        }
    )
}

/* ------------------------------------------------------------------ */
/* the six states */
/* ------------------------------------------------------------------ */

/**
 * How many rows the catalogue actually produced, held back ones excluded.
 *
 * The held-back three are this release's own and exist whatever the catalogue
 * holds, so counting them would make an estate with no features at all look
 * populated — three rulings and nothing to rule on.
 */
const catalogueRows = computed(() =>
    props.groups
        .filter((group) => group.key !== 'held_back')
        .reduce((n, group) => n + group.rows.length, 0)
)

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render.
 *
 * THE SIXTH IS REAL AND IS REACHED FROM DATA. `featuresBoard()` drops any
 * catalogue feature that no plan includes — a row somebody is still drafting,
 * and drawing it would offer a community something nobody sells — so a
 * catalogue that is full of drafts produces a screen with no groups and no rows,
 * which is emptiness caused by the filter rather than by an empty catalogue.
 * That is a different sentence from "nothing is on offer at all", and both are
 * below.
 */
const state = useScreenState({
    rows: () => catalogueRows.value,
    filtered: () => props.plan.key !== null,
})

const retry = () => router.reload()
</script>

<template>
    <Head title="Settings" />

    <!--
      No topbar action at all. Board 23 draws an h1 and no .top-right block:
      nothing on this screen is created, so there is nothing for a primary
      button to do.
    -->
    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <div class="settings-layout">
            <!--
              The settings column, on every one of the four screens. The item
              the reader is on is text rather than a control — there is nowhere
              for it to lead — the three built siblings are real links, and the
              three unbuilt ones are visibly inert and each says what it is
              waiting on rather than linking into a 404.
            -->
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">
                        {{ section.label }}
                    </div>
                    <Link v-else :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                </template>
            </div>

            <div>
                <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="3" />

                <EmptyState
                    v-else-if="state.isDenied.value"
                    variant="denied"
                    title="Settings is not part of your role’s access"
                    body="What this estate’s plan includes is kept in Settings, and your role does not include Settings."
                />

                <EmptyState
                    v-else-if="state.isError.value"
                    variant="error"
                    title="The feature panel could not be read"
                    body="The plan and its features could not be read. Nothing has been switched, and every module is running exactly as it was. Trying again is safe."
                    action-label="Try again"
                    @action="retry"
                />

                <!--
                  A catalogue with rows in it and no plan including any of them.
                  See the note on `state`: this is emptiness caused by the
                  filter, and it is the estate's subscription that decides it.
                -->
                <EmptyState
                    v-else-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="Nothing in the catalogue belongs to a plan yet"
                    body="The platform holds features and none of them has been put into a plan, so there is nothing to show this estate. A feature no plan includes is still a draft."
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="This estate has no subscription on record"
                    body="What an estate can use is what its plan includes, and this one has no plan yet. Gemini Security sets the plan against the subscription."
                />

                <template v-else>
                    <!--
                      The plan banner. The sentence is assembled at the boundary
                      from the estate's name and the plan's, so this page never
                      joins the two — and the badge is the plan's own name
                      rather than a word chosen to match it.
                    -->
                    <div class="plan-banner">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <div>
                            <div class="pb1">{{ plan.banner }}</div>
                            <div class="pb2">{{ plan.note }}</div>
                        </div>
                        <div v-if="plan.name" class="pb-badge">{{ plan.name }}</div>
                    </div>

                    <template v-for="group in groups" :key="group.key">
                        <!--
                          The audit note sits between the last tier group and
                          the held-back group. See the file's own note: it is a
                          footer to the rows that can change and a preamble to
                          the three that cannot.
                        -->
                        <div v-if="group.key === 'held_back'" class="audit-note">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <span>{{ audit_note }}</span>
                        </div>

                        <div class="feat-group">
                            <div class="feat-group-head">{{ group.heading }}</div>

                            <div class="feat-panel">
                                <template v-for="row in group.rows" :key="row.key">
                                    <div class="feat-row">
                                        <!--
                                          THE GLYPH IS THE FEATURE'S OWN KEY and
                                          not a match on its name: the board
                                          draws eleven distinct marks, and an
                                          estate on a plan that adds a twelfth
                                          feature should get a shape somebody
                                          chose rather than whichever one a
                                          string match landed on.
                                        -->
                                        <div class="feat-icon" :class="{ locked: row.locked }">
                                            <svg v-if="row.key === 'resident_core'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M12 9v4M12 17h.01"
                                                    stroke="currentColor"
                                                    stroke-width="1.9"
                                                    stroke-linecap="round"
                                                />
                                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'visitor_passes'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <rect
                                                    x="3"
                                                    y="6"
                                                    width="18"
                                                    height="14"
                                                    rx="2"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                />
                                                <path
                                                    d="M3 8l9 6 9-6"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'amenity_booking'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M3 10l9-6 9 6"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linejoin="round"
                                                />
                                                <path
                                                    d="M5 10v9M11 10v9M13 10v9M19 10v9M3 19h18"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                />
                                            </svg>
                                            <svg v-else-if="row.key === 'guard_app'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                />
                                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                                            </svg>
                                            <svg v-else-if="row.key === 'evoting'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M9 12l2 2 4-4"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                />
                                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'gated_meetings'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                />
                                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                                                <path
                                                    d="M23 21v-2a4 4 0 0 0-3-3.9"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'accounting_core'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M3 3v18h18"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                />
                                                <path
                                                    d="M7 15l4-5 4 3 5-7"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'estate_payroll'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H7"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'payment_gateway'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <rect
                                                    x="2"
                                                    y="4"
                                                    width="20"
                                                    height="16"
                                                    rx="2"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                />
                                                <path d="M2 9h20M8 4v5" stroke="currentColor" stroke-width="1.7" />
                                            </svg>
                                            <svg v-else-if="row.key === 'ai_drafting'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M12 3v3m0 12v3m9-9h-3M6 12H3m14.5-6.5l-2.1 2.1M8.6 15.4l-2.1 2.1m0-11l2.1 2.1m8.8 8.8l-2.1-2.1"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                />
                                                <circle cx="12" cy="12" r="3.5" stroke="currentColor" stroke-width="1.7" />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'custom_branding'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linejoin="round"
                                                />
                                                <path
                                                    d="M4 22V15"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linecap="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'biometric_consent'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M12 4c-3.3 0-6 2.7-6 6v3M18 10a6 6 0 0 0-3-5.2M9 20c1-1.7 1.5-3.6 1.5-5.5V10a1.5 1.5 0 0 1 3 0v4.5M16.5 19v-4.5"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linecap="round"
                                                />
                                            </svg>
                                            <svg v-else-if="row.key === 'geofencing'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linejoin="round"
                                                />
                                                <circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="1.6" />
                                            </svg>
                                            <svg v-else viewBox="0 0 24 24" fill="none">
                                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                                            </svg>
                                        </div>

                                        <div class="feat-txt">
                                            <div class="ft1">
                                                {{ row.label }}
                                                <span
                                                    class="tier-req"
                                                    :class="TIER_CLASS[row.tier]"
                                                    :style="row.tier ? undefined : HELD_BADGE"
                                                >
                                                    {{ row.tier_label }}
                                                </span>
                                            </div>
                                            <div class="ft2">{{ row.description }}</div>
                                        </div>

                                        <!--
                                          READ ONLY (the closing order): the state in the list's words, and where it
                                          comes from in one line. The branding dots stay as the readout they always
                                          were; nothing on a row is a control (M96 level 3 for everybody).
                                        -->
                                        <div class="feat-state">
                                            <div v-if="row.control === 'scale'" class="branding-scale" :title="SCALE_TITLE">
                                                <div
                                                    v-for="dot in row.scale"
                                                    :key="dot.code"
                                                    class="branding-dot"
                                                    :class="dot.filled ? 'filled' : 'outline'"
                                                    :title="`${dot.label}: ${dot.filled ? 'included in this plan' : 'not included in this plan'}`"
                                                >
                                                    {{ dot.code }}
                                                </div>
                                            </div>
                                            <span class="state-pill" :class="row.state_label === 'Active' ? 'is-active' : 'is-inactive'">
                                                {{ row.state_label }}
                                            </span>
                                            <span class="feat-plan">{{ row.plan_line }}</span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <!--
                      THE RESIDENT APP'S ONE ESTATE RULE, below the plan's rows
                      because it is not a feature of any plan: whether a member the
                      primary resident invites waits for the office's one tap.
                    -->
                    <div v-if="residentInvites" class="feat-group" data-section="resident-app">
                        <div class="feat-group-head">Resident App</div>

                        <div class="feat-panel">
                            <div class="feat-row">
                                <div class="feat-icon">
                                    <svg viewBox="0 0 24 24" fill="none">
                                        <path
                                            d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"
                                            stroke="currentColor"
                                            stroke-width="1.7"
                                        />
                                        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                                        <path
                                            d="M17 11l2 2 4-4"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </svg>
                                </div>

                                <div class="feat-txt">
                                    <div class="ft1">{{ residentInvites.label }}</div>
                                    <div v-for="sentence in residentInvites.sentences" :key="sentence" class="ft2">
                                        {{ sentence }}
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="switch"
                                    :class="{ on: residentInvites.enabled }"
                                    role="switch"
                                    :aria-checked="residentInvites.enabled"
                                    :aria-label="residentInvites.label"
                                    :disabled="!residentInvites.canChange"
                                    :title="
                                        residentInvites.canChange
                                            ? `Switch ${residentInvites.label} ${residentInvites.enabled ? 'off' : 'on'}. You will be asked why before it is recorded.`
                                            : residentInvites.blockedReason
                                    "
                                    @click="openTrust"
                                >
                                    <span class="knob"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- ConsoleDialog: trust primary residents' invites -->
        <ConsoleDialog
            v-if="residentInvites"
            :open="trustOpen"
            :title="trustTarget ? 'Trust the main resident’s invites?' : 'Stop trusting the main resident’s invites?'"
            :confirm-label="trustTarget ? 'Trust their invites' : 'Wait for review'"
            busy-label="Saving…"
            :busy="trustBusy"
            :confirm-blocked-reason="trustBlocked"
            :error="trustError"
            @confirm="saveTrust"
            @cancel="cancelTrust"
        >
            <p v-if="trustTarget" class="trust-lead">
                A member who signs in with the exact address the primary resident invited will be linked to the
                household at once, without anybody in the estate office reviewing it. The estate office’s own
                invites still wait for review, and every link is audited.
            </p>
            <p v-else class="trust-lead">
                From now on every member a primary resident invites waits for one tap in Unit claims. Nobody
                already linked is affected.
            </p>

            <!-- Required, and recorded with the name and the time (batch 1 item 3: marked; the example a placeholder). -->
            <label class="trust-label" for="trust-reason">Why is this changing? <RequiredMark /></label>
            <input
                id="trust-reason"
                v-model="trustReason"
                class="trust-input"
                type="text"
                maxlength="300"
                required
                placeholder="e.g. The committee agreed it at the September meeting"
                @keyup.enter="saveTrust"
            />
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only above the authored line, and each removal names the
 * element that needs it.
 *
 * The board draws its settings items, its eleven switches and its four
 * segmented options as <div>s; here they are links, buttons and one input.
 *
 * `font-family` and never `font`. Vue's scoped attribute lifts a bare `button`
 * selector to the same weight as a single class, so `font: inherit` would
 * out-specify `.settings-nav-item`'s own 12.5px and render it at the body's size.
 * The board declares no font-family on any of them, so that one property is the
 * whole of what is missing. See D-045.
 *
 * Padding and margin need no removing — the board's own `*` reset already takes
 * both off every element on the page.
 */
button,
input {
    font-family: inherit;
}

/*
 * .switch declares its own background and .switch.on and .switch.locked-on
 * override it; NONE of the three declares a border. Only the border comes off
 * here — a `background: none` on this selector would out-specify both state
 * rules and leave every switch on the screen the same grey.
 */
button.switch {
    border: 0;
}

/* .settings-nav-item declares neither a border nor a face, and the amber face
 * on .active is a two-class rule no button here can match — the current section
 * is drawn as text. */
button.settings-nav-item {
    border: 0;
    background: none;
    text-align: left;
}

/* .btn-primary-sm declares a background and a shadow and no border. */
button.btn-primary-sm {
    border: 0;
}

button:not([disabled]) {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/* =====================================================================
 * AUTHORED BELOW THIS LINE.
 *
 * None of it is drawn on a screen that has just opened, which is the state
 * board 23 is a picture of: the flash and the refusal need a press to exist,
 * the reason field needs a control to have been armed, and the provenance line
 * needs an estate to have overridden something. Kept to the boards' own tokens
 * and to shapes they already use.
 * ===================================================================== */

/*
 * A ROW'S STATE, READ ONLY (the closing order): the list's word in a pill, Active green and Inactive the neutral navy
 * the boards use for a fact, and where it comes from on one line under it.
 */
.feat-state {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 5px;
    min-width: 170px;
    text-align: right;
}

.state-pill {
    display: inline-flex;
    align-items: center;
    height: 22px;
    padding: 0 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}

.state-pill.is-active {
    background: var(--green-100);
    color: var(--green-700);
}

.state-pill.is-inactive {
    background: var(--navy-100);
    color: var(--slate-600);
}

.feat-plan {
    font-size: 10.5px;
    color: var(--slate-500);
    line-height: 1.4;
    max-width: 240px;
}

/* The "Resident App" row's three sentences read as one description, a little apart. */
[data-section='resident-app'] .ft2 + .ft2 {
    margin-top: 3px;
}

/* The trust dialog's reason field, on the same tokens as the inline reason field above. */
.trust-lead {
    margin: 0 0 14px;
}

.trust-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
    margin-bottom: 6px;
}

.trust-input {
    width: 100%;
    height: 40px;
    border: 1.5px solid var(--navy-200);
    border-radius: 10px;
    background: var(--white);
    padding: 0 13px;
    font-size: 13px;
    color: var(--navy-900);
}

/*
 * ON A PHONE (checked at 390 px with the new shell, 12 Oct). The board keeps the section list beside the page, 200 px of
 * a 390 px screen, and every Settings page ran past the window. Below 760 px the list sits above the page as one row
 * that scrolls sideways, and the page takes the full width. Doubled classes outrank the board's own rules.
 *
 * Here a feature's state also moves under its name, in line with the words (the icon's 36 px and the row's 14 px gap),
 * and the plan's badge under the banner's sentence: beside them they left the name a word or two a line.
 */
@media (max-width: 760px) {
    .settings-layout.settings-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 14px;
    }

    .settings-nav.settings-nav {
        flex-direction: row;
        overflow-x: auto;
    }

    .settings-nav-item.settings-nav-item {
        flex: 0 0 auto;
        width: auto;
        white-space: nowrap;
    }

    .feat-row.feat-row {
        flex-wrap: wrap;
    }

    .feat-txt.feat-txt {
        flex: 1 1 0;
        min-width: 0;
    }

    .feat-state.feat-state {
        flex: 1 0 100%;
        min-width: 0;
        align-items: flex-start;
        text-align: left;
        padding-left: 50px;
    }

    .plan-banner.plan-banner {
        flex-wrap: wrap;
    }

    .plan-banner.plan-banner > div:not(.pb-badge) {
        flex: 1 1 calc(100% - 33px);
        min-width: 0;
    }

    .plan-banner.plan-banner .pb-badge {
        margin-left: 33px;
    }
}
</style>
