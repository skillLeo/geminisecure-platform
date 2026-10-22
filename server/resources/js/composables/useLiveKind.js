import { onBeforeUnmount } from 'vue'
import { state as liveState } from '../live/store.js'

/**
 * A PAGE THAT IS NOT A LIVE LIST SAYS WHICH KIND IT IS (M18H §7.2; design §6.4).
 *
 * "This screen does not update by itself" is the truth about a list nobody has wired, and the order is that every such
 * list is wired. Two kinds of page are not lists, and the honesty line says the truth about each instead:
 *
 *   useLiveKind('form')       an edit page — settings, a create form, a composer. It shows no record to go stale, and
 *                             re-reading it under somebody's typing would lose their work. No line is drawn.
 *   useLiveKind('snapshot')   a report: a figure worked out at a moment. Drawn "As of 09:38:12 · Refresh".
 *
 * A page calls one of these OR `useLiveReload`; `LiveStatusCoverageTest` holds every console page to that.
 *
 * @param {'form'|'snapshot'} kind
 */
export function useLiveKind(kind) {
    liveState.pageKind = kind

    onBeforeUnmount(() => {
        if (liveState.pageKind === kind) {
            liveState.pageKind = null
        }
    })
}
