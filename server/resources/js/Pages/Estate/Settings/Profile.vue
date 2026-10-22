<script setup>
import { computed, reactive, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'

/**
 * Estate profile — board screen community-admin-21.
 *
 * SEVEN INPUTS, AND ONLY THREE OF THEM ARE THIS ESTATE'S TO CHANGE (D-049).
 * The board draws one form and the payload draws a line through the middle of
 * it: the estate's name, its address, its unit total and its phase count are the
 * SECURITY COMPANY'S CLIENT RECORD and this estate's own units, and each arrives
 * `editable: false` carrying the reason on the field. The general enquiries
 * mailbox, the phone number and the security provider belong to the community
 * and are written here.
 *
 * WHAT CANNOT BE CHANGED LOOKS IT, AND SAYS WHY ON THE SCREEN (the Settings pass, 12 Oct; M53 law 1). The four
 * client-record fields were drawn as the same boxes as the three that can be typed into, with the reason only on
 * hover. Now a locked field is drawn locked, and each group says under its fields, once, why the locked ones are
 * locked, in the server's own sentence. A reader who may change nothing (the President and the Vice President hold
 * Settings as View) has no Save at all (M96 level 3): a "View only" badge where it was, and one line at the top of the
 * form saying what they may do. An editor with nothing changed sees Save greyed with "Nothing has changed yet." beside
 * it (level 2), never only in a tooltip.
 *
 * A REFUSAL IS DRAWN UNDER THE FIELD IT IS ABOUT, IN AMBER. They were three red banners over the form. A save that
 * worked is the shell's toast, from the server's own flash.
 *
 * NOTHING HERE IS CALCULATED. The unit total is `SELECT COUNT(*) FROM units`
 * resolved at the boundary and arrives as a string; the address already has ", Jamaica"
 * appended; the labels, the section headings and the field order are the
 * server's. A page that counted, joined or reformatted any of it would be a
 * second answer to a question the estate already answers elsewhere, and the two
 * would disagree the first time a unit was added.
 *
 * THE LOGO IS UPLOADED HERE (12 §1), because it is printed on every statement,
 * receipt, remittance advice and certificate the estate issues. The size and
 * format rule is said under the button, before the press; the file is stored as
 * uploaded and never re-encoded; and the old one stays, the swap audited with
 * both paths. THE ESTATE'S OWN MARK IS DRAWN once one is set (`logo.url`, the
 * served route with the stored file's fingerprint on it); the board's stand-in
 * only until then. It drew the stand-in for ever, so nobody could see what their
 * paperwork carries.
 *
 * WHAT THE BOARD DRAWS THAT THIS PAGE DOES NOT REPRODUCE, recorded rather than
 * copied: the estate name's input is drawn with the `.focused` amber ring, which
 * is a picture of somebody's cursor sitting in it; the platform's one focus
 * treatment is `app.css`'s (D-134).
 */
const props = defineProps({
    /** The client record: this estate's name and its single-line address. */
    estate: { type: Object, required: true },
    /** The settings column, with this screen marked current. */
    sections: { type: Array, required: true },
    /** `path` (null until one is set), `url` (where the page draws it from), `caption`, `action`. */
    logo: { type: Object, required: true },
    /** Three headed groups, each carrying its fields in the board's order. */
    groups: { type: Array, required: true },
    canEdit: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    /** The size and format rule, stated before the press. */
    logoRules: { type: String, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all. All four settings
 * screens live on this one.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

// A settings form: re-reading it under somebody's changes would lose them (M18H §7.2).
useLiveKind('form')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/settings/profile. The URL that served this page already carries
 * whichever shape this environment uses, so cutting it at /settings is correct
 * in both — and unlike a tenant key read off a prop it cannot address an estate
 * other than the one already open.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/settings')))

/* ------------------------------------------------------------------ */
/* the estate's mark (12 §1 — it is printed on every document) */
/* ------------------------------------------------------------------ */

const logoInput = ref(null)

const logoForm = useForm({ logo: null })

/*
 * THE OLD ONE IS KEPT. A logo is printed on paperwork households already hold,
 * and the swap is audited with both paths — so a receipt issued last March can
 * be explained by what the estate's mark was in March. Nothing deletes a file a
 * document may have been rendered from.
 */
const uploadLogo = (event) => {
    const file = event.target.files?.[0]

    if (!file) {
        return
    }

    logoForm.logo = file
    logoForm.post(`${root.value}/settings/profile/logo`, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            logoForm.logo = null

            if (logoInput.value) {
                logoInput.value.value = ''
            }
        },
    })
}

/* ------------------------------------------------------------------ */
/* the form */
/* ------------------------------------------------------------------ */

const fields = computed(() => props.groups.flatMap((group) => group.fields))

/**
 * What has been typed, seeded from what is stored.
 *
 * ONLY THE EDITABLE FIELDS ARE IN HERE, and that is the second of the three
 * places the read-only four are refused. The route refuses them (`saveProfile`
 * validates three keys and no more), the model refuses them (`$fillable` names
 * three columns), and this object never holds them — so a request that carried
 * the estate's name could only be one somebody built by hand, and it would be
 * ignored when it arrived.
 */
const draft = reactive({})

for (const field of props.groups.flatMap((group) => group.fields)) {
    if (field.editable) {
        draft[field.key] = field.value
    }
}

/** Whether anything on the form differs from what is stored. */
const dirty = computed(() => fields.value.some((f) => f.editable && draft[f.key] !== f.value))

/** A field nobody on this screen may type into: the client record's four, and every field for a reader. */
const locked = (field) => !field.editable || !props.canEdit

/*
 * WHAT MUST BE FILLED IN SAYS SO (Hassam, 12 Oct, batch 1 item 3), as `saveProfile` asks: the security provider is
 * required; the enquiries email and phone may be left empty. A locked field is not asked of anybody, so it is unmarked.
 */
const REQUIRED_FIELDS = ['security_provider']
const OPTIONAL_FIELDS = ['enquiries_email', 'enquiries_phone']

const mustFill = (field) => REQUIRED_FIELDS.includes(field.key) && !locked(field)
const mayLeave = (field) => OPTIONAL_FIELDS.includes(field.key) && !locked(field)

/**
 * Why Save is greyed, said beside it, or null when it can be pressed (M96 level 2).
 *
 * Only ever asked of somebody who may save: a reader has no Save at all (level 3). The empty provider is refused
 * here before the press, in the same rule the service refuses it by (`Settings::updateProfile`).
 */
const saveBlockedBy = computed(() => {
    if (!dirty.value) {
        return 'Nothing has changed yet.'
    }

    if (String(draft.security_provider ?? '').trim() === '') {
        return 'Name the security provider before saving.'
    }

    return null
})

/** What Save does, in one line (M53 law 2). */
const SAVE_TITLE = 'Save the enquiries email, the phone number and the security provider.'

const saving = ref(false)

const save = () => {
    if (saveBlockedBy.value !== null || saving.value) {
        return
    }

    saving.value = true
    router.post(`${root.value}/settings/profile`, { ...draft }, {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false
        },
    })
}

/** The server's refusal of one field, drawn under it. */
const errorFor = (field) => page.props.errors?.[field.key] ?? null

/* ------------------------------------------------------------------ */
/* the board's shape */
/* ------------------------------------------------------------------ */

/**
 * The board's two-column pairs, worked out from the `span` the server sends,
 * and each group's reasons for its locked fields, said once under them.
 *
 * Board 21 puts "Total units" beside "Number of phases" and the enquiries
 * mailbox beside the phone, in a `.m-two-col` flex row; the other three fields
 * are full width. The payload says which is which, so the pairing is derived
 * from `span` rather than from a position in the array — a field inserted
 * between two halves would otherwise silently pair the wrong two.
 */
const layout = computed(() =>
    props.groups.map((group) => {
        const rows = []
        const reasons = []

        for (const field of group.fields) {
            const open = rows[rows.length - 1]

            if (field.span === 'half' && open?.span === 'half' && open.fields.length === 1) {
                open.fields.push(field)
            } else {
                rows.push({ span: field.span, fields: [field] })
            }

            if (!field.editable && field.reason && !reasons.includes(field.reason)) {
                reasons.push(field.reason)
            }
        }

        return { title: group.title, rows, reasons }
    })
)

/**
 * The value a field is showing: what has been typed where it can be typed, and
 * what is stored where it cannot.
 */
const shown = (field) => (field.editable ? draft[field.key] : field.value)

/**
 * A keystroke, kept off the fields that are shown and not changed.
 *
 * `readonly` already refuses them at the browser, and this refuses them again
 * for the same reason the service keeps its allowlist away from the request: an
 * attribute is one line, and one line is exactly the kind of thing that gets
 * dropped during a rework.
 */
const typed = (field, event) => {
    if (field.editable && props.canEdit) {
        draft[field.key] = event.target.value
    }
}

/* ------------------------------------------------------------------ */
/* the five states */
/* ------------------------------------------------------------------ */

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render.
 *
 * `rows` counts FIELDS and not groups. A payload with no groups at all is an
 * estate with no profile on record; a payload carrying the headed groups and no
 * field inside any of them is a profile that arrived without its fields, and the
 * two need different words.
 */
const state = useScreenState({
    rows: () => fields.value.length,
    filtered: () => props.groups.length > 0,
})

const retry = () => router.reload()
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <template #actions>
            <!-- M96 level 3: a reader who can never save has no Save; the badge says what this screen is to them. -->
            <span v-if="!canEdit" class="view-badge">View only</span>

            <template v-else>
                <!-- Level 2: greyed, and why in one line beside it, never only on hover. -->
                <span v-if="saveBlockedBy !== null" class="save-why">{{ saveBlockedBy }}</span>
                <button
                    type="button"
                    class="btn-primary-sm"
                    :disabled="saveBlockedBy !== null || saving"
                    :title="SAVE_TITLE"
                    @click="save"
                >
                    <span>{{ saving ? 'Saving…' : 'Save changes' }}</span>
                </button>
            </template>
        </template>

        <div class="settings-layout">
            <!--
              The settings column, on every settings screen. The item the reader
              is on is text rather than a control: there is nowhere for it to lead.
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

            <SkeletonRows v-if="state.isLoading.value" :rows="7" :columns="1" />

            <EmptyState
                v-else-if="state.isDenied.value"
                variant="denied"
                title="Settings is not part of your role’s access"
                body="The estate profile is kept in Settings, and your role does not include Settings."
            />

            <EmptyState
                v-else-if="state.isError.value"
                variant="error"
                title="The estate profile could not be read"
                body="The estate database did not answer. Nothing was changed, and the contact details on record still stand. Trying again is safe."
                action-label="Try again"
                @action="retry"
            />

            <!-- Headings with no fields under them: true when it happens, and nothing on this screen filters. -->
            <EmptyState
                v-else-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="The profile arrived without its fields"
                body="Its sections came back with nothing in them, so nothing is shown rather than an empty form. Trying again is safe."
                action-label="Try again"
                @action="retry"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="This estate has no profile yet"
                body="An estate’s profile is its name and address on Gemini Security’s client record, the lots and phases it holds, and the email and phone residents use. None of it has been set up for this estate yet."
            />

            <!--
              The board's own inline max-width, kept verbatim. A settings form
              that ran the full 924px of the right column would put a 900px box
              around a phone number.
            -->
            <div v-else class="form-panel" style="max-width:640px;">
                <p v-if="!canEdit" class="view-note">{{ blockedReason }}</p>

                <div class="logo-upload">
                    <div class="logo-box" :class="{ 'has-logo': logo.url }">
                        <img v-if="logo.url" :src="logo.url" alt="The estate’s logo" class="logo-img" />
                        <!-- The board's own mark, until the estate has set one. -->
                        <svg v-else viewBox="0 0 40 40" aria-hidden="true">
                            <circle cx="15" cy="20" r="9" fill="#FFFFFF" opacity="0.92" />
                            <circle
                                cx="25"
                                cy="20"
                                r="9"
                                fill="#FFB627"
                                opacity="0.92"
                                style="mix-blend-mode: multiply"
                            />
                        </svg>
                    </div>
                    <div>
                        <div class="lu-txt">{{ logo.caption }}</div>

                        <!--
                          M96 level 3: a reader has no upload at all. For an editor
                          the file input is hidden and the board's own button opens
                          it; the rule is said under it, before the press, and the
                          picker offers exactly what it names. Never SVG: the server
                          refuses it, because a mark served inline that can carry
                          script is not an image (EstateBranding).
                        -->
                        <template v-if="canEdit">
                            <input
                                ref="logoInput"
                                type="file"
                                accept="image/png,image/jpeg"
                                style="display: none"
                                @change="uploadLogo"
                            />
                            <button
                                type="button"
                                class="lu-btn"
                                :disabled="logoForm.processing"
                                title="Choose a picture to replace the estate’s logo."
                                @click="logoInput?.click()"
                            >
                                {{ logoForm.processing ? 'Uploading…' : logo.action }}
                            </button>
                            <div class="lu-rules">{{ logoRules }}</div>
                        </template>

                        <div v-if="logoForm.errors.logo" class="lu-error" role="alert">{{ logoForm.errors.logo }}</div>
                    </div>
                </div>

                <!-- What the mark means, once, above the first field, for whoever may fill the form in (batch 1 item 3). -->
                <template v-if="canEdit">
                    <RequiredMark legend />
                </template>

                <template v-for="group in layout" :key="group.title">
                    <div class="form-sec-head">{{ group.title }}</div>

                    <!--
                      A pair of halves is the board's .m-two-col flex row; a
                      full-width field is a bare .m-field. Both draw the same
                      field, so the pairing is the only thing this branch
                      decides.
                    -->
                    <template v-for="(row, i) in group.rows" :key="`${group.title}-${i}`">
                        <div v-if="row.span === 'half'" class="m-two-col">
                            <div v-for="field in row.fields" :key="field.key" class="m-field">
                                <label :for="`f-${field.key}`">
                                    {{ field.label }}<template v-if="mayLeave(field)"> (optional)</template>
                                    <RequiredMark v-if="mustFill(field)" />
                                </label>

                                <div class="m-input" :class="{ 'is-locked': locked(field), 'has-error': errorFor(field) }">
                                    <!--
                                      THE HIDDEN COPY IS WHAT SIZES THE FIELD.
                                      Board 21's half-width fields are as wide as
                                      what is in them, because the board draws the
                                      value in a <span>. An <input> is as wide as
                                      its `size`, so the span is still here,
                                      invisible, holding the same string: it sizes
                                      the cell and the input lies on top of it.
                                    -->
                                    <span class="m-sizer">
                                        <span class="m-ghost" aria-hidden="true">{{ shown(field) }}</span>
                                        <input
                                            :id="`f-${field.key}`"
                                            :type="field.type"
                                            :value="shown(field)"
                                            :readonly="locked(field)"
                                            :required="mustFill(field)"
                                            :aria-readonly="locked(field)"
                                            :aria-invalid="errorFor(field) ? 'true' : undefined"
                                            @input="typed(field, $event)"
                                        />
                                    </span>
                                </div>

                                <p v-if="errorFor(field)" class="field-error" role="alert">{{ errorFor(field) }}</p>
                            </div>
                        </div>

                        <!--
                          A full-width row carries exactly one field, because
                          `span` is what puts a field in a pair and a full field
                          is by definition not in one.
                        -->
                        <div v-else class="m-field">
                            <label :for="`f-${row.fields[0].key}`">
                                {{ row.fields[0].label }}<template v-if="mayLeave(row.fields[0])"> (optional)</template>
                                <RequiredMark v-if="mustFill(row.fields[0])" />
                            </label>
                            <div class="m-input" :class="{ 'is-locked': locked(row.fields[0]), 'has-error': errorFor(row.fields[0]) }">
                                <input
                                    :id="`f-${row.fields[0].key}`"
                                    class="m-fill"
                                    :type="row.fields[0].type"
                                    :value="shown(row.fields[0])"
                                    :readonly="locked(row.fields[0])"
                                    :required="mustFill(row.fields[0])"
                                    :aria-readonly="locked(row.fields[0])"
                                    :aria-invalid="errorFor(row.fields[0]) ? 'true' : undefined"
                                    @input="typed(row.fields[0], $event)"
                                />
                            </div>

                            <p v-if="errorFor(row.fields[0])" class="field-error" role="alert">{{ errorFor(row.fields[0]) }}</p>
                        </div>
                    </template>

                    <!-- Why the locked fields above are locked, once each, in the server's own words. -->
                    <p v-for="why in group.reasons" :key="why" class="field-why">{{ why }}</p>
                </template>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only above the authored line, and each removal names the
 * element that needs it.
 *
 * The board draws its topbar action and its logo action as <div>s and its seven
 * field values as <span>s; here they are two buttons, several links and seven
 * inputs. A <button> and an <input> arrive wearing a border, a face and the
 * browser's own font — and unlike every other element on the page they do NOT
 * inherit the board's font-family, because form controls never do.
 *
 * `font-family` and never `font`. Vue's scoped attribute lifts a bare `button`
 * selector to the same weight as a single class, so `font: inherit` here would
 * out-specify `.settings-nav-item`'s own 12.5px and `.lu-btn`'s own 700 and
 * render both at the body's size and weight. See D-045.
 */
button,
input {
    font-family: inherit;
}

/*
 * NONE OF THESE IS GIVEN A BORDER OR A BACKGROUND BY THE BOARD.
 * .btn-primary-sm declares a background and a shadow; .lu-btn declares neither.
 */
button.btn-primary-sm {
    border: 0;
}

button.lu-btn {
    border: 0;
    background: none;
    text-align: left;
}

/* The board draws the logo action as a <div>, which is block; a <button> is
 * inline-block, and the difference is a baseline gap underneath it. */
button.lu-btn {
    display: block;
}

button:not([disabled]) {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * The value, on the element that now carries it: the board's `.m-input span`
 * (13px, weight 500, navy-900) restated on the input that took the job over.
 * `min-width: 0` because an input is a flex item and would otherwise push the
 * field wider than the box the board drew.
 */
.m-input input {
    border: 0;
    background: none;
    outline: 0;
    padding: 0;
    font-size: 13px;
    font-weight: 500;
    color: var(--navy-900);
    min-width: 0;
}

/* The full-width fields fill the box. */
.m-input input.m-fill {
    width: 100%;
}

/*
 * A read-only field is still a field: its text is selectable and copyable.
 * `readonly`, never `disabled`, so the client record can be quoted in a message
 * to Gemini Security.
 */
.m-input input[readonly] {
    cursor: default;
}

/* =====================================================================
 * AUTHORED BELOW THIS LINE.
 * ===================================================================== */

/*
 * The hidden copy that gives an input the width a span would have had: one grid
 * cell, the invisible ghost setting the width and the input filling it.
 */
.m-sizer {
    display: inline-grid;
    min-width: 4ch;
}

.m-sizer > * {
    grid-area: 1 / 1;
}

.m-ghost {
    visibility: hidden;
    white-space: pre;
}

/*
 * A LOCKED FIELD LOOKS LOCKED (M53 law 1; the Settings pass). The board's box is a filled field that invites typing;
 * a field nobody here may change is drawn as an outline with no fill, its words a shade lighter, so it reads as shown
 * rather than offered. Its reason is the line under its group.
 */
.m-input.is-locked {
    background: transparent;
    box-shadow: inset 0 0 0 1px var(--navy-100);
}

.m-input.is-locked input {
    color: var(--slate-600);
}

/* A field the server refused: amber, never red (Hassam's red rule, 11 Oct). */
.m-input.has-error {
    box-shadow: inset 0 0 0 1px var(--amber-600);
}

.field-error {
    margin: 6px 0 0;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    color: var(--amber-700);
}

/* Why the locked fields of a group are locked: one quiet line each, under the group. */
.field-why {
    margin: -4px 0 14px;
    font-size: 11.5px;
    line-height: 1.5;
    color: var(--slate-500);
}

/* What a reader may do here, at the top of the form: a statement, not a warning. */
.view-note {
    margin: 0 0 16px;
    padding: 9px 13px;
    border-radius: 10px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.5;
}

/* Level 3's badge, where Save would be. */
.view-badge {
    display: inline-flex;
    align-items: center;
    height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 700;
}

/* Level 2's reason, beside the greyed Save. */
.save-why {
    margin-right: 10px;
    font-size: 12px;
    font-weight: 600;
    color: var(--slate-500);
}

button.btn-primary-sm[disabled] {
    opacity: 0.55;
}

/* The estate's own mark, inside the board's box, never stretched. */
.logo-box.has-logo {
    background: #FFFFFF;
    box-shadow: inset 0 0 0 1px var(--navy-100);
}

.logo-img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

/* The upload's rule, said before the press. */
.lu-rules {
    margin-top: 4px;
    max-width: 420px;
    font-size: 11px;
    line-height: 1.5;
    color: var(--slate-500);
}

/* A logo the estate's own rules refused: amber. */
.lu-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--amber-700);
    line-height: 1.5;
    margin-top: 6px;
    max-width: 420px;
}

/*
 * ON A PHONE (checked at 390 px with the new shell, 12 Oct). The board keeps the section list beside the page, 200 px of
 * a 390 px screen, and every Settings page ran past the window. Below 760 px the list sits above the page as one row
 * that scrolls sideways, and the page takes the full width. Doubled classes outrank the board's own rules.
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
}
</style>
