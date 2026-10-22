<script setup>
/**
 * A notice on a door card: sign in, forgot password, a new password, verify contact, the invitation, the second
 * factor (the front door, M97 item 2).
 *
 * IT SIZES ITS OWN ICON, THE SAME ON BOTH DOORS. These notices used board classes (`.mfa-note`, `.crit-banner`) that
 * only the Gemini door's sheet draws, so on the estate door the icon had no size at all and filled the card, pushing
 * the field and the button below the fold.
 *
 * FOUR TONES, AND AN ICON ONLY WHEN THERE IS SOMETHING TO REPORT:
 *   - ok: something happened (a link was sent, a password changed). The one notice a page leads with: its icon is 32px,
 *     beside the words.
 *   - info: a fact about this door. The board's own note: a 15px icon.
 *   - warn: something the reader must do before going on (M96's "Action required": amber, with its icon).
 *   - error: a refusal about a field. Small and inline, under the field it is about, never a banner.
 * The colour is never the only signal: the icon and the words carry it too. A refusal is an alert, anything else a
 * status.
 */
defineProps({
    tone: { type: String, default: 'info', validator: (tone) => ['ok', 'info', 'warn', 'error'].includes(tone) },
})
</script>

<template>
    <div class="door-notice" :class="`door-notice--${tone}`" :role="tone === 'error' ? 'alert' : 'status'">
        <svg v-if="tone === 'ok'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" />
            <polyline points="16.5 9 10.5 15 7.5 12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <svg v-else-if="tone === 'info'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" />
            <path d="M12 11v5M12 8h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
        <svg v-else viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            <path
                d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
                stroke="currentColor"
                stroke-width="1.8"
            />
        </svg>
        <div class="door-notice-words"><slot /></div>
    </div>
</template>

<style scoped>
/* The board's own note (super-admin-01 `.mfa-note`): the box, the gap and the type are its values. */
.door-notice {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    border-radius: 11px;
    padding: 11px 13px;
    margin: 0 0 18px;
    font-family: 'Inter', sans-serif;
    font-size: 11px;
    line-height: 1.5;
    text-align: left;
}

.door-notice svg {
    width: 15px;
    height: 15px;
    flex: 0 0 auto;
    margin-top: 1px;
    display: block;
}

/* A long address wraps inside the card rather than running past its edge. */
.door-notice-words {
    min-width: 0;
    overflow-wrap: anywhere;
}

/* Something happened: 32px beside the words (M97 item 2 asks 32 to 48), so the card still fits a laptop screen. */
.door-notice--ok {
    align-items: center;
    gap: 12px;
    font-size: 12px;
    background: var(--success-100);
    color: var(--success-700);
}

.door-notice--ok svg {
    width: 32px;
    height: 32px;
    margin-top: 0;
}

.door-notice--info {
    background: var(--navy-100);
    color: var(--navy-600);
}

/* Amber text is under 4.5:1 on white, so the words stay near-black and the icon carries the amber. */
.door-notice--warn {
    font-size: 12px;
    background: var(--amber-100);
    color: var(--navy-900);
}

.door-notice--warn svg {
    width: 16px;
    height: 16px;
    color: var(--amber-700);
}

/*
 * A refusal about a field: no box, under the field, in the type the other field errors on these cards use. AMBER, NEVER
 * RED (Hassam, 11 Oct): a wrong password is not an emergency, and red on a door card competes with a panic alert for
 * meaning. Amber text is under 4.5:1 on white, so, as the warn tone draws it, the words are near-black and bold and
 * the warning icon carries the amber.
 */
.door-notice--error {
    gap: 6px;
    padding: 0;
    margin: 6px 0 0;
    border-radius: 0;
    background: none;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
}

.door-notice--error svg {
    width: 14px;
    height: 14px;
    margin-top: 2px;
    color: var(--amber-700);
}
</style>
