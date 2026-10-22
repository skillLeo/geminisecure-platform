<script setup>
import { computed } from 'vue'
import { BRAND_COLOURWAYS } from '../brand.js'

/**
 * The GeminiSecure brand mark.
 *
 * TWO OVERLAPPING CIRCLES. viewBox 0 0 40 40, both r=12.5, cx=15 and cx=25,
 * cy=20, with mix-blend-mode:multiply on the RIGHT circle. The overlap IS the
 * logo and is produced by the blend mode alone — there is no third shape, no
 * mask, no clip-path and no gradient.
 *
 * Copied verbatim from the wireframes. Never redraw it, and never parameterise
 * the radius: an audit of all 98 instances in the approved set found exactly
 * one drawn at r=9, where the circles barely intersect and the overlap that is
 * the mark nearly disappears. That instance is the defect, not a small-size
 * variant.
 */
const props = defineProps({
    colourway: {
        type: String,
        default: 'console',
        validator: (value) => Object.keys(BRAND_COLOURWAYS).includes(value),
    },
})

const colours = computed(() => BRAND_COLOURWAYS[props.colourway])
</script>

<template>
    <svg class="mark" viewBox="0 0 40 40">
        <circle cx="15" cy="20" r="12.5" :fill="colours.left" :opacity="colours.opacity" />
        <circle
            cx="25"
            cy="20"
            r="12.5"
            :fill="colours.right"
            :opacity="colours.opacity"
            style="mix-blend-mode: multiply"
        />
    </svg>
</template>
