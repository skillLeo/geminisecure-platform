<script setup>
/**
 * The loading state: a skeleton, never a spinner.
 *
 * A spinner says "something is happening". A skeleton says "a table of about
 * this shape is arriving", which is the thing the reader actually wants to
 * know, and it stops the page jumping when the rows land.
 *
 * Sized from the caller so it matches the table it stands in for — a skeleton
 * of the wrong height reintroduces exactly the layout shift it exists to
 * prevent.
 */
defineProps({
    rows: { type: Number, default: 6 },
    columns: { type: Number, default: 5 },
})
</script>

<template>
    <div class="skeleton" aria-busy="true" aria-live="polite">
        <span class="sr-only">Loading…</span>

        <div v-for="row in rows" :key="row" class="skeleton-row">
            <div
                v-for="column in columns"
                :key="column"
                class="skeleton-cell"
                :style="{ width: column === 1 ? '28%' : `${Math.round(60 / (columns - 1))}%` }"
            ></div>
        </div>
    </div>
</template>

<style scoped>
/*
 * Authored, because the boards draw no loading state at all — they are still
 * images and never load. Kept to the tokens the boards do define, so a
 * skeleton looks like it belongs to this design rather than to a component
 * library.
 */
.skeleton {
    padding: 4px 0;
}

.skeleton-row {
    display: flex;
    align-items: center;
    gap: 16px;
    height: 44px;
    border-bottom: 1px solid var(--navy-100);
}

.skeleton-cell {
    height: 10px;
    border-radius: 6px;
    background: linear-gradient(90deg, var(--navy-100), var(--navy-200), var(--navy-100));
    background-size: 200% 100%;
    animation: skeleton-sweep 1.4s ease-in-out infinite;
}

@keyframes skeleton-sweep {
    0% {
        background-position: 200% 0;
    }

    100% {
        background-position: -200% 0;
    }
}

/*
 * Someone who has asked their system to stop animating has usually asked
 * because motion makes them ill. A sweeping gradient across every row of a
 * table is exactly the kind of thing they meant.
 */
@media (prefers-reduced-motion: reduce) {
    .skeleton-cell {
        animation: none;
        background: var(--navy-100);
    }
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
