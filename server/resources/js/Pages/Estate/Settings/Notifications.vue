<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'
import { toast } from '../../../lib/toasts.js'

/**
 * Notification defaults — board screen community-admin-30.
 *
 * ONE SWITCH PER MESSAGE AND CHANNEL, ONE SUBMIT, and the board is right to draw it that way. Feature toggles armed and
 * confirmed per row because each turned a module on or off for every household; nothing here does that. These decide
 * who is emailed or pushed about something that has already happened, a contact preference in the same register as the
 * estate's enquiries mailbox on board 21, so it is one "Save changes" and one audit entry, gated on `edit_profile`.
 *
 * THE POSTED ARRAY IS NOT THE ALLOWLIST. `Settings::saveNotifications()` walks its own event catalogue and reads each
 * key out of what arrives, so an extra key in the request body reaches nothing.
 *
 * NO SMS COLUMN WHILE NOTHING CAN SEND A TEXT (Hassam, 12 Oct 2026): "A control that can never be used is level 3,
 * which means no control at all, not a greyed switch with UNAVAILABLE written under it." While `sms_available` is false
 * no row draws an SMS switch, and one line says texts are not available. WHAT WAS SAVED FOR SMS IS KEPT, untouched: this
 * page never posts a channel it does not draw, and the server leaves a channel the request does not carry exactly as
 * stored, so the day a provider is connected the column comes back with those values and nobody sets them again.
 *
 * NOTHING READS THESE YET, AND THE PAGE SAYS SO FIRST (Hassam, 12 Oct 2026): no code that emails or pushes a household
 * consults `notification_defaults`, so the switches record a choice that changes nothing today. They stay editable,
 * because the data they save is right and will be used the day the senders read it ("wire the senders to read
 * notification preferences", the real fix, in docs/handoff/SETTINGS.md); a page that pretended to configure something
 * would be worse than one that admits it does not yet.
 *
 * M96 LEVELS. A reader (the President and the Vice President hold Settings as View) has no Save at all: a "View only"
 * badge where it was, and one line at the top saying what they may do; the switches show the estate's defaults, inert.
 * An editor with nothing changed sees Save greyed with the reason beside it. A refused save is a toast, top right, in the
 * server's words (a refusal on a page with no dialog open, D-235).
 */
const props = defineProps({
    estate: { type: Object, required: true },
    sections: { type: Array, required: true },
    groups: { type: Array, required: true },
    canEdit: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    sms_available: { type: Boolean, default: true },
    /** The platform's own sentence about SMS; the page says it in one line of its own now. */
    sms_note: { type: String, default: null },
})

const page = usePage()

/*
 * SHEET 08, NOT THE SETTINGS SHEET ITS SIBLINGS WEAR. `.notif-row`, `.notif-panel`, `.channel-sw` and
 * `.channel-switches` are defined only in "Reports Unit Claims and Notices", where board 30 was drawn; sheet 08 carries
 * `.settings-layout` and `.settings-nav-item` too.
 */
useWireframe('community-admin-08-reports-unit-claims-and-notices')

// A settings form: re-reading it under somebody's changes would lose them (M18H §7.2).
useLiveKind('form')

const state = useScreenState({
    rows: () => props.groups.length,
})

const retry = () => router.reload()

/** The channels a row draws: one that can never send is not drawn at all (M96 level 3). */
const shownChannels = (row) => row.channels.filter((channel) => channel.available !== false)

const keyOf = (row, channel) => `${row.key}.${channel.key}`

/* The working copy: the server's own values to start, and only the channels drawn, so only those are ever posted. */
const draft = reactive(
    Object.fromEntries(props.groups.flatMap((group) => group.rows.flatMap((row) => shownChannels(row).map((channel) => [keyOf(row, channel), channel.enabled])))),
)

/** Whether anything drawn differs from what is stored: after a save, the stored values are the draft's. */
const dirty = computed(() =>
    props.groups.some((group) => group.rows.some((row) => shownChannels(row).some((channel) => draft[keyOf(row, channel)] !== channel.enabled))),
)

const toggle = (row, channel) => {
    if (!props.canEdit) {
        return
    }

    draft[keyOf(row, channel)] = !draft[keyOf(row, channel)]
}

/** Why Save is greyed, beside it (M96 level 2). Only ever asked of somebody who may save. */
const saveBlockedBy = computed(() => (dirty.value ? null : 'Nothing has changed yet.'))

const saving = ref(false)

const save = () => {
    if (!props.canEdit || saveBlockedBy.value !== null || saving.value) {
        return
    }

    const defaults = {}

    for (const [path, enabled] of Object.entries(draft)) {
        const [event, channel] = path.split('.')

        defaults[event] = { ...(defaults[event] ?? {}), [channel]: enabled }
    }

    saving.value = true
    router.post(window.location.pathname, { defaults }, {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false
        },
    })
}

/* A refused save is a toast, in the server's words (D-235); the switches keep what was flipped. */
watch(
    () => page.props.errors?.defaults,
    (text) => {
        if (text) {
            toast(text, { tone: 'warning' })
        }
    },
    { immediate: true },
)
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
                    title="Save these notification defaults."
                    @click="save"
                >
                    <span>{{ saving ? 'Saving…' : 'Save changes' }}</span>
                </button>
            </template>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Settings is not part of your role’s access"
            body="Notification defaults are kept in Settings, and your role does not include Settings."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The notification defaults could not be read"
            body="The estate database did not answer. Nothing was changed, and the defaults on record still stand. Trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <div v-else class="settings-layout">
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
                <!--
                  THE TRUTH ABOUT THESE SWITCHES, FIRST (Hassam, 12 Oct): no sender reads them yet, so the page says so
                  and keeps capturing the choices, which are right and will be used the day the senders read them.
                -->
                <p class="notif-unused" role="note">These preferences are saved but are not yet used. Every notification currently goes to everyone entitled to receive it.</p>

                <!-- What a reader may do here: a statement, not a warning. -->
                <p v-if="!canEdit" class="view-note">{{ blockedReason }}</p>

                <!-- One line, now that no switch needs explaining (Hassam, 12 Oct). -->
                <p v-if="!sms_available" class="notif-sms-note" role="note">Text messages are not available on this estate, so nothing is sent by text.</p>

                <div v-for="group in groups" :key="group.heading" class="notif-group">
                    <div class="notif-group-head">{{ group.heading }}</div>
                    <div class="notif-panel">
                        <div v-for="row in group.rows" :key="row.key" class="notif-row">
                            <div class="notif-txt">
                                <div class="nt1">{{ row.label }}</div>
                                <div class="nt2">{{ row.description }}</div>
                            </div>
                            <div class="channel-switches">
                                <div v-for="channel in shownChannels(row)" :key="channel.key" class="channel-sw">
                                    <button
                                        type="button"
                                        class="switch"
                                        :class="{ on: draft[keyOf(row, channel)] }"
                                        role="switch"
                                        :aria-checked="draft[keyOf(row, channel)]"
                                        :aria-label="`${row.label}: ${channel.label}`"
                                        :disabled="!canEdit"
                                        :title="canEdit ? undefined : blockedReason"
                                        @click="toggle(row, channel)"
                                    >
                                        <span class="knob"></span>
                                    </button>
                                    <span>{{ channel.label }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default removal for the real controls the board draws as <div>s: its own .btn-primary-sm, .settings-nav-item and
 * .switch supply everything visible (D-045).
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

a.settings-nav-item {
    text-decoration: none;
    display: block;
}

button.switch {
    border: 0;
    padding: 0;
    cursor: pointer;
}

button.btn-primary-sm[disabled],
button.switch[disabled] {
    cursor: not-allowed;
}

button.btn-primary-sm[disabled] {
    opacity: 0.55;
}

/* AUTHORED BELOW THIS LINE: the board draws neither line. Tokens the console already carries. */

/* That nothing reads these yet: the first thing on the page, a fact to weigh, in the amber of one. */
.notif-unused {
    margin: 0 0 14px;
    padding: 10px 14px;
    border-radius: 10px;
    background: var(--amber-100);
    border: 1px solid var(--amber-500);
    color: var(--navy-900);
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.55;
}

/* What a reader may do here, at the top: a statement, not a warning. */
.view-note {
    margin: 0 0 14px;
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

/* Texts are not available: one line, in the amber of a fact to weigh. */
.notif-sms-note {
    margin: 0 0 16px;
    padding: 10px 14px;
    border-radius: 10px;
    background: var(--amber-100);
    border: 1px solid var(--amber-500);
    color: var(--navy-900);
    font-size: 12.5px;
    line-height: 1.55;
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
