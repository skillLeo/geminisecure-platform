<script setup>
/**
 * The Estate Console's sidebar glyphs, lifted from the boards.
 *
 * Separate from ModuleIcon, which draws the GEMINI console's sidebar. The two
 * consoles have different modules and the boards give them different geometry —
 * the estate's "Estate structure" is a house with a ground line where Gemini's
 * "Clients" is a building without one, and Facilities is a colonnade that
 * appears nowhere on the Gemini side. Folding them together behind one
 * component and picking whichever glyph was written first is the drift these
 * boards were re-lifted to remove.
 *
 * Every path here is copied from the board markup rather than redrawn, stroke
 * width included: the estate sidebar draws at 1.8 throughout, except the gear,
 * which the boards draw at 1.5 because it is a denser shape at the same size.
 *
 * An unknown name renders nothing rather than a fallback. A missing icon is a
 * visible bug worth fixing; a generic placeholder silently ships something
 * nobody drew.
 */
defineProps({
    name: { type: String, required: true },
})
</script>

<template>
    <!-- Dashboard: a house -->
    <svg v-if="name === 'dashboard'" viewBox="0 0 24 24" fill="none">
        <path d="M3 11l9-8 9 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        <path d="M5 10v10h14V10" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    </svg>

    <!-- Estate structure: a house on a ground line, with a door -->
    <svg v-else-if="name === 'estate'" viewBox="0 0 24 24" fill="none">
        <path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    </svg>

    <!-- Residents: two figures -->
    <svg v-else-if="name === 'residents'" viewBox="0 0 24 24" fill="none">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.8" />
        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.8" />
        <path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    </svg>

    <!-- Dues & ledger: a card -->
    <svg v-else-if="name === 'dues'" viewBox="0 0 24 24" fill="none">
        <rect x="2" y="6" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.8" />
        <path d="M2 10h20" stroke="currentColor" stroke-width="1.8" />
    </svg>

    <!-- Accounting: a dollar sign -->
    <svg v-else-if="name === 'accounting'" viewBox="0 0 24 24" fill="none">
        <path
            d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H7"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
        />
    </svg>

    <!-- Payroll & HR: a wall planner -->
    <svg v-else-if="name === 'payroll'" viewBox="0 0 24 24" fill="none">
        <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.8" />
        <path d="M2 9h20M8 4v5" stroke="currentColor" stroke-width="1.8" />
    </svg>

    <!-- Facilities: a colonnade -->
    <svg v-else-if="name === 'facilities'" viewBox="0 0 24 24" fill="none">
        <path d="M3 10l9-6 9 6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
        <path d="M5 10v9M11 10v9M13 10v9M19 10v9M3 19h18" stroke="currentColor" stroke-width="1.8" />
    </svg>

    <!-- Governance: a speaker -->
    <svg v-else-if="name === 'governance'" viewBox="0 0 24 24" fill="none">
        <path d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
        <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    </svg>

    <!-- Reports: axes with a trend line -->
    <svg v-else-if="name === 'reports'" viewBox="0 0 24 24" fill="none">
        <path d="M3 3v18h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
        <path d="M7 15l4-5 4 3 5-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    </svg>

    <!--
      Settings: a gear.

      Drawn at 1.5 where every other item in this sidebar is 1.8. That is the
      board's own choice and not a slip — the gear is a far denser outline at
      the same 18px, and at 1.8 it fills in.
    -->
    <svg v-else-if="name === 'settings'" viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5" />
        <path
            d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"
            stroke="currentColor"
            stroke-width="1.5"
        />
    </svg>
</template>
