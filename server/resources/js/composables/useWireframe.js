import { BOARD_BODY_CLASS } from '../wireframe-map'

/**
 * Declares which approved board a page reproduces.
 *
 * Each board's stylesheet is lifted verbatim and scoped to its own body class,
 * so a page gets the board's CSS by wearing that class. One line at the top of
 * a page's <script setup>:
 *
 *     useWireframe('super-admin-02-clients')
 *
 * A page names its own BOARD, never a class. Boards whose stylesheets are
 * byte-identical share one class — all nine Super Admin boards do — and the
 * generated map handles that, so the page stays honest about where its design
 * came from without needing to know how the CSS is packed.
 *
 * Applied synchronously during setup() rather than in onMounted, so the class
 * is on the element before the first paint of the new page. Inertia keeps the
 * outgoing page mounted while the incoming one is created, so this removes any
 * previous wf- class instead of cleaning up on unmount — an unmount hook would
 * fire after the new page had already set its own and strip it again.
 */
export function useWireframe(board) {
    const bodyClass = BOARD_BODY_CLASS[board]

    if (!bodyClass) {
        // Loud on purpose. A silent miss here renders the page with no board
        // CSS at all, which reads as "not built yet" rather than "named a
        // board that does not exist".
        throw new Error(
            `useWireframe: no such board '${board}'. ` +
                `Known boards are listed in resources/js/wireframe-map.js (generated).`
        )
    }

    if (typeof document !== 'undefined') {
        const body = document.body

        for (const existing of [...body.classList]) {
            if (existing.startsWith('wf-') && existing !== bodyClass) {
                body.classList.remove(existing)
            }
        }

        body.classList.add(bodyClass)
    }

    return bodyClass
}
