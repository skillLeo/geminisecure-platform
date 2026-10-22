/**
 * The four authorised brand-mark colourways.
 *
 * Counted across all 98 instances in the approved wireframe set. The fills are
 * hard-coded hex rather than var() because SVG presentation attributes as
 * written in the wireframes do not resolve CSS custom properties — which is
 * also why #1974D2 and #3B2166 each appear ~45 times as literals there.
 *
 * Kept in a plain module rather than inside the component because a Vue
 * `defineProps()` validator cannot reference a locally declared variable: it
 * is hoisted outside setup(). An import is fine.
 */
export const BRAND_COLOURWAYS = {
    // Gemini console sidebar - 46 instances
    console: { left: '#3B2166', right: '#1974D2', opacity: 0.92 },
    // Estate console sidebar, resident splash - 45 instances
    estate: { left: '#FFFFFF', right: '#FFB627', opacity: 0.92 },
    // Resident auth badge - 5 instances, full opacity
    auth: { left: '#1974D2', right: '#FFB627', opacity: 1 },
    // Console login card - 2 instances, full opacity
    login: { left: '#3B2166', right: '#1974D2', opacity: 1 },
}
