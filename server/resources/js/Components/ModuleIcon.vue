<script setup>
/**
 * Navigation icons, one per module key.
 *
 * Every path is copied verbatim from the approved wireframes. Stroke weights
 * are CONTEXTUAL and not interchangeable: sidebar navigation is 1.8, and the
 * settings cog is the sole 1.5 in this set. Do not normalise them.
 *
 * Rendering an unknown key returns nothing rather than a fallback glyph. A
 * missing icon is a visible bug worth fixing; a generic placeholder silently
 * ships a module nobody drew.
 */
defineProps({
    module: { type: String, required: true },
})
</script>

<template>
    <!-- Dashboard -->
    <svg v-if="module === 'dashboard'" viewBox="0 0 24 24" fill="none">
        <path d="M3 11l9-8 9 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        <path d="M5 10v10h14V10" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    </svg>

    <!-- Clients -->
    <svg v-else-if="module === 'clients'" viewBox="0 0 24 24" fill="none">
        <path d="M3 21V8l9-5 9 5v13M9 21v-6h6v6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    </svg>

    <!-- Dispatch -->
    <svg v-else-if="module === 'dispatch'" viewBox="0 0 24 24" fill="none">
        <path
            d="M12 2C8 2 5 5 5 9c0 5 7 13 7 13s7-8 7-13c0-4-3-7-7-7z"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linejoin="round"
        />
        <circle cx="12" cy="9" r="2.5" stroke="currentColor" stroke-width="1.8" />
    </svg>

    <!-- Guard workforce -->
    <svg v-else-if="module === 'guard_workforce'" viewBox="0 0 24 24" fill="none">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.8" />
        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.8" />
        <path
            d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
        />
    </svg>

    <!-- Payroll and Accounting -->
    <svg v-else-if="module === 'payroll_accounting'" viewBox="0 0 24 24" fill="none">
        <path
            d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H7"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
        />
    </svg>

    <!-- Billing and subscriptions -->
    <svg v-else-if="module === 'billing_subscriptions'" viewBox="0 0 24 24" fill="none">
        <rect x="2" y="6" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.8" />
        <path d="M2 10h20" stroke="currentColor" stroke-width="1.8" />
    </svg>

    <!-- Cross-tenant reports -->
    <svg v-else-if="module === 'cross_tenant_reports'" viewBox="0 0 24 24" fill="none">
        <path d="M3 3v18h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
        <path d="M7 15l4-5 4 3 5-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    </svg>

    <!-- Access and audit log -->
    <svg v-else-if="module === 'access_audit_log'" viewBox="0 0 24 24" fill="none">
        <path
            d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linejoin="round"
        />
    </svg>

    <!-- Platform settings. The cog is the ONLY 1.5 stroke in the set. -->
    <svg v-else-if="module === 'platform_settings'" viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5" />
        <path
            d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"
            stroke="currentColor"
            stroke-width="1.5"
        />
    </svg>
</template>
