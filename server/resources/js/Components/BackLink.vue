<script setup>
import { Link } from '@inertiajs/vue3'

/**
 * THE WAY BACK FROM A NESTED PAGE (M95 §3; Hassam, 11 Oct: "A person should never have to leave the application to move
 * around inside it").
 *
 * Top left, before the title, on every page reached by View, Edit, Open or Create, and never on a page the sidebar
 * opens: that one is the top of its own section. It is the meeting page's round arrow, which M95 calls right, with the
 * words it lacked: it says where it goes ("Back to bills"), and it goes to that list, never to whatever the browser's
 * history happens to hold, which after a save can be a form that submits again.
 *
 * A page passes it to the shell as `back`: `<EstateConsole :back="{ href, label }">`.
 */
defineProps({
    href: { type: String, required: true },
    /** Where it goes, in words: "Back to bills". */
    label: { type: String, required: true },
})
</script>

<template>
    <Link :href="href" class="back-link">
        <span class="back-link-arrow" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
                <polyline points="15 18 9 12 15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
        <span class="back-link-label">{{ label }}</span>
    </Link>
</template>

<style scoped>
/* The meeting page's arrow, as it drew it inline (34px, navy-100, a 16px navy-700 chevron), and the words beside it. */
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex: 0 0 auto;
    max-width: 260px;
    text-decoration: none;
    color: var(--navy-700);
    font-size: 12.5px;
    font-weight: 700;
}

.back-link-arrow {
    width: 34px;
    height: 34px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
}

.back-link-arrow svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
    display: block;
}

.back-link-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.back-link:hover .back-link-arrow {
    background: var(--navy-200);
}

.back-link:focus-visible {
    outline: 2px solid var(--amber-500);
    outline-offset: 2px;
    border-radius: 17px;
}
</style>
