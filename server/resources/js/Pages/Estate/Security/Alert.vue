<script setup>
import { computed, onMounted, watch } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import SiteMap from '../../../Components/SiteMap.vue'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'
import { useLifeSafetyPoll } from '../../../composables/useLifeSafetyPoll.js'
import { ESTATE_POLL_MS } from '../../../composables/useEstatePanic.js'

/**
 * One alert at this estate, end to end — the client's report E4, 26 Sep ("I am unable to click any of the rows").
 *
 * STILL A READ. Acknowledging, sending an officer and closing an alert happen in Gemini's control room; this is the
 * estate's sight of each step and when it happened, on the estate's clock. Who was told is said in counts, and a text
 * is counted only when the provider ACCEPTED it — never "sent" for one nobody accepted.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    alert: { type: Object, required: true },
})

useWireframe('community-admin-08-reports-unit-claims-and-notices')

// A panic moves by the second — acknowledged, a guard on the way, resolved — and this page follows it (M18H §7.2).
useLiveReload({ entities: ['alert'] })

// The Security page, rooted where this console is (the estate's own hostname, or its key in the path locally).
const page = usePage()
const backHref = computed(() => {
    const cut = page.url.indexOf('/security')

    return `${cut === -1 ? '' : page.url.slice(0, cut)}/security`
})

const pins = computed(() =>
    props.alert.lat === null || props.alert.lng === null
        ? []
        : [{ id: `alert-${props.alert.id}`, lat: props.alert.lat, lng: props.alert.lng, kind: 'alert', title: `${props.alert.kind_label} · ${props.alert.unit}` }],
)

/*
 * EACH STEP LANDS AS THE CONTROL ROOM RECORDS IT. An open alert is re-read on the Security page's own clock (the
 * estate's panic poll, ESTATE_POLL_MS); a closed one has nothing left to change and is not asked about again.
 */
/** People to call (M18C §4.2): who pressed, their household, their emergency contacts — each group said, even empty. */
const peopleGroups = computed(() => {
    const p = props.alert.people
    if (!p) return []
    return [
        { title: 'Who raised it', people: p.resident ? [p.resident] : [], none: 'Not on the register. The lot is above.' },
        { title: 'Household', people: p.members ?? [], none: 'Nobody else on the register.' },
        { title: 'Emergency contacts', people: p.contacts ?? [], none: 'None on file.' },
    ]
})

const poll = useLifeSafetyPoll(['alert'], ESTATE_POLL_MS, { active: () => props.alert.open })
onMounted(() => props.alert.open && poll.start())
watch(() => props.alert.open, (open) => (open ? poll.start() : poll.stop()))
</script>

<template>
    <Head :title="`${alert.kind_label} · ${alert.unit}`" />

    <!-- The way back, in words, to the Security page this alert is opened from (M95 §3): the shell's back control. -->
    <EstateConsole title="Security" :estate-name="estate.name" active="security" :back="{ href: backHref, label: 'Back to security' }">

        <div class="panel sa-head" :class="{ open: alert.open }">
            <div class="sa-lot">{{ alert.unit }}</div>
            <div class="sa-title"><template v-if="alert.is_drill">DRILL · </template>{{ alert.kind_label }} · {{ alert.who }}</div>
            <div class="sa-status" :class="{ open: alert.open }">{{ alert.status }}</div>
            <p class="sa-note">
                The response is run by Gemini's control room: this page shows each step as they record it. Nothing here changes the
                alert.
            </p>
        </div>

        <div class="sa-grid">
            <div class="panel">
                <div class="panel-head">What happened, and when</div>
                <ol class="sa-steps">
                    <li v-for="step in alert.steps" :key="step.key" class="sa-step">
                        <span class="sa-dot" aria-hidden="true"></span>
                        <div>
                            <div class="sa-step-label">{{ step.label }}</div>
                            <div class="sa-step-at">{{ step.at }}</div>
                            <div v-if="step.detail" class="sa-step-detail">{{ step.detail }}</div>
                        </div>
                    </li>
                </ol>
                <p v-if="alert.open" class="sa-note">Steps not shown have not happened yet.</p>
            </div>

            <div>
                <div class="panel">
                    <div class="panel-head">Where</div>
                    <SiteMap v-if="pins.length" :pins="pins" :height="260" :label="`Where the ${alert.kind_label.toLowerCase()} was raised`" />
                    <!-- The lot alone, so it reads whether one was recorded or not ("Lot 47." or "Lot not recorded."). -->
                    <p v-else class="sa-note">No position came with this alert: the phone did not share one. {{ alert.unit }}.</p>
                </div>

                <div class="panel">
                    <div class="panel-head">Who was told</div>
                    <ul v-if="alert.told.length" class="sa-told">
                        <li v-for="line in alert.told" :key="line">{{ line }}</li>
                    </ul>
                    <p v-else class="sa-note">Still being worked out, or nobody was on record to tell.</p>
                </div>

                <!-- Every number, ready to dial (M18C §4.2): the resident, the household, the emergency contacts. -->
                <div v-if="alert.people" class="panel">
                    <div class="panel-head">People to call</div>
                    <template v-for="group in peopleGroups" :key="group.title">
                        <div class="sa-pc-group">{{ group.title }}</div>
                        <p v-if="group.people.length === 0" class="sa-note">{{ group.none }}</p>
                        <div v-for="(p, i) in group.people" :key="group.title + i" class="sa-pc-row">
                            <div>
                                <div class="sa-pc-name">{{ p.name }}</div>
                                <div class="sa-pc-rel">{{ p.relationship }}{{ p.alerts_to_primary ? ' · no email, so alerts go to the primary resident' : '' }}</div>
                            </div>
                            <a v-if="p.tel" class="sa-pc-call" :href="`tel:${p.tel}`" :aria-label="`Call ${p.name} on ${p.phone}`">{{ p.phone }}</a>
                            <span v-else class="sa-pc-rel">{{ p.phone }}</span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Authored: no board draws this screen. Its panels are the Security page's own (seen on screen, 11 Oct: the sheet this
 * page borrows draws no panel, so every block sat bare on the page with no card and no spacing).
 */
.panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 6px 18px 14px;
    margin-bottom: 18px;
}

.panel-head {
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--navy-900);
    padding: 14px 0 10px;
}

.sa-head {
    padding-top: 16px;
    display: grid;
    gap: 4px;
}

.sa-lot {
    font-family: 'Poppins', sans-serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--navy-900);
}

.sa-title {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--navy-900);
}

.sa-status {
    font-size: 12px;
    font-weight: 700;
    color: var(--slate-500);
}

.sa-status.open {
    color: var(--red-700);
}

.sa-note {
    margin: 6px 0 0;
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.55;
}

.sa-grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    /*
     * EACH COLUMN SIZES TO ITS OWN CONTENT (Hassam, Batch 1 item 9, 12 Oct: "a large empty white panel"). Grid's
     * default stretch made the left panel grow to match the right column's stacked height — map, Who was told, People
     * to call — whenever an alert has few steps (unacknowledged, on Hassam's own test alerts: Raised alone). The panel
     * was never empty of content; it was stretched past it.
     */
    align-items: start;
    gap: 16px;
}

@media (max-width: 900px) {
    .sa-grid {
        grid-template-columns: 1fr;
    }
}

.sa-steps {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 12px;
}

.sa-step {
    display: grid;
    grid-template-columns: 12px 1fr;
    gap: 10px;
    align-items: start;
}

.sa-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--navy-600);
    margin-top: 4px;
}

.sa-step-label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.sa-step-at {
    font-size: 11.5px;
    color: var(--slate-500);
    font-variant-numeric: tabular-nums;
}

.sa-step-detail {
    font-size: 12px;
    color: var(--slate-600);
    margin-top: 2px;
}

.sa-told {
    margin: 0;
    padding-left: 18px;
    display: grid;
    gap: 4px;
    font-size: 12px;
    color: var(--navy-900);
}

.sa-pc-group {
    margin-top: 10px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
}

.sa-pc-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-top: 1px solid var(--navy-100);
}

.sa-pc-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
}

.sa-pc-rel {
    font-size: 12px;
    color: var(--text-body);
}

.sa-pc-call {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 10px;
    background: var(--navy-600);
    color: var(--text-on-dark);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}
</style>
