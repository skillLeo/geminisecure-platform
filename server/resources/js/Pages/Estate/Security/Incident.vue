<script setup>
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * One incident at this estate, end to end (Batch 1 item 10, 12 Oct 2026: the list's row had nowhere to open to, the
 * same report E4 made about the alerts list on 26 Sep).
 *
 * STILL A READ. An incident is logged and resolved on the Guard App and the Gemini console; this is the estate's
 * sight of what was recorded about its own gate, on the estate's clock. Never the officer's employee number, licence
 * or record: only their name, the same as the Security page's own list already shows.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    incident: { type: Object, required: true },
})

useWireframe('community-admin-08-reports-unit-claims-and-notices')

// The Security page, rooted where this console is (the estate's own hostname, or its key in the path locally).
const page = usePage()
const backHref = computed(() => {
    const cut = page.url.indexOf('/security')

    return `${cut === -1 ? '' : page.url.slice(0, cut)}/security`
})
</script>

<template>
    <Head :title="`${incident.kind_label} · ${estate.name}`" />

    <!-- The way back, in words, to the Security page this incident is opened from: the shell's back control. -->
    <EstateConsole title="Security" :estate-name="estate.name" active="security" :back="{ href: backHref, label: 'Back to security' }">

        <div class="panel ia-head">
            <div class="ia-kind">{{ incident.kind_label }}</div>
            <div class="ia-status" :class="{ warn: !incident.closed }">{{ incident.status_label }}</div>
            <p class="sa-note">
                An incident is logged and closed by the guards' own company. This page shows what was recorded. Nothing here
                changes it.
            </p>
        </div>

        <div class="panel">
            <div class="panel-head">What happened</div>
            <dl class="ia-facts">
                <dt>When</dt>
                <dd>{{ incident.occurred_at }}</dd>
                <dt>Severity</dt>
                <dd>{{ incident.severity_label }}</dd>
                <dt>On post</dt>
                <dd>{{ incident.guard }}</dd>
                <dt>Reported by</dt>
                <dd>{{ incident.logged_by }}</dd>
            </dl>
            <p v-if="incident.detail" class="ia-detail">{{ incident.detail }}</p>
        </div>

        <div class="panel">
            <div class="panel-head">Resolution</div>
            <template v-if="incident.closed">
                <p class="ia-detail">{{ incident.resolution }}</p>
                <p class="sa-note">Closed {{ incident.closed_at }}.</p>
            </template>
            <p v-else class="sa-note">Action required: nobody has closed this incident yet.</p>
        </div>
    </EstateConsole>
</template>

<style scoped>
/* The Security page's own panels (Estate/Security/Alert.vue carries the same rules; shared only by repetition). */
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

.ia-head {
    padding-top: 16px;
    display: grid;
    gap: 4px;
}

.ia-kind {
    font-family: 'Poppins', sans-serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--navy-900);
}

.ia-status {
    font-size: 12px;
    font-weight: 700;
    color: var(--slate-500);
}

/* Waiting on somebody: a warning, in amber. Red is the open panic's alone. */
.ia-status.warn {
    color: var(--amber-700);
}

.sa-note {
    margin: 6px 0 0;
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.55;
}

.ia-facts {
    display: grid;
    grid-template-columns: 120px minmax(0, 1fr);
    gap: 6px 12px;
    margin: 8px 0 0;
    font-size: 13px;
}

.ia-facts dt {
    font-weight: 700;
    color: var(--slate-600);
}

.ia-facts dd {
    margin: 0;
    color: var(--navy-900);
    overflow-wrap: anywhere;
}

.ia-detail {
    margin: 8px 0 0;
    font-size: 13px;
    color: var(--navy-900);
    line-height: 1.5;
    overflow-wrap: anywhere;
}

@media (max-width: 480px) {
    .ia-facts {
        grid-template-columns: minmax(0, 1fr);
        gap: 2px;
    }

    .ia-facts dd {
        margin-bottom: 6px;
    }
}
</style>
